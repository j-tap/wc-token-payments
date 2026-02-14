<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Shortcode_Buy {

    public static function init(): void {
        add_shortcode('wctk_buy_tokens', [__CLASS__, 'shortcode']);
        add_action('init', [__CLASS__, 'handle_form_submit']);
    }

    public static function rate(): float {
        return WCTK_Plugin::get_rate();
    }

    public static function maybe_create_topup_product(): void {
        if (!function_exists('wc_get_product')) return;

        $existing_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        if ($existing_id > 0 && wc_get_product($existing_id)) return;

        $product = new WC_Product_Simple();
        $product->set_name('Token top-up');
        $product->set_status('private');
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_catalog_visibility('hidden');
        $product->set_sold_individually(false);
        $product->set_regular_price('0');

        $product_id = $product->save();
        update_option(WCTK_OPT_TOPUP_PRODUCT_ID, (int) $product_id);
    }

    /**
     * Вывод формы пополнения токенов (шорткод или вызов из кода).
     * Без собственной стилизации, нейтральная разметка.
     *
     * @return string HTML формы.
     */
    public static function render_topup_form(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to buy tokens.', WCTK_TEXT_DOMAIN) . '</p>';
        }

        $rate = self::rate();
        $currency = get_woocommerce_currency();

        ob_start();
        ?>
        <form method="post" class="wctk-topup-form">
            <?php wp_nonce_field(WCTK_NONCE_ACTION_BUY_TOKENS, 'wctk_buy_nonce'); ?>
            <p>
                <label for="wctk_tokens_qty"><?php echo esc_html__('How many tokens to buy:', WCTK_TEXT_DOMAIN); ?></label>
                <input id="wctk_tokens_qty" type="number" name="tokens_qty" min="1" step="1" required>
            </p>
            <p class="description">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: rate number, 2: currency code */
                        __('Rate: 1 token = %1$s %2$s', WCTK_TEXT_DOMAIN),
                        rtrim(rtrim(number_format($rate, 2, '.', ''), '0'), '.'),
                        $currency
                    )
                );
                ?>
            </p>
            <p>
                <button type="submit" name="wctk_buy_submit" value="1"><?php echo esc_html__('Create order', WCTK_TEXT_DOMAIN); ?></button>
            </p>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Программное создание заказа на пополнение токенов.
     *
     * @param int $user_id   ID пользователя.
     * @param int $tokens_qty Количество токенов.
     * @return WC_Order|WP_Error Заказ или ошибка.
     */
    public static function create_topup_order(int $user_id, int $tokens_qty) {
        if ($user_id <= 0 || $tokens_qty <= 0) {
            return new WP_Error('wctk_invalid_args', __('Invalid user or token quantity.', WCTK_TEXT_DOMAIN));
        }

        self::maybe_create_topup_product();
        $product_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('wctk_no_product', __('Top-up product not found.', WCTK_TEXT_DOMAIN));
        }

        $rate = self::rate();
        $total = $tokens_qty * $rate;

        $order = wc_create_order(['customer_id' => $user_id]);
        if (is_wp_error($order)) {
            return $order;
        }

        $item_id = $order->add_product($product, 1, [
            'subtotal' => $total,
            'total' => $total,
        ]);
        wc_add_order_item_meta($item_id, 'tokens_qty', $tokens_qty, true);

        $order->update_meta_data(WCTK_ORDER_META_IS_TOPUP, 'yes');
        $order->update_meta_data(WCTK_ORDER_META_TOKENS_QTY, $tokens_qty);
        $order->save();

        return $order;
    }

    public static function shortcode(): string {
        return self::render_topup_form();
    }

    public static function handle_form_submit(): void {
        if (!isset($_POST['wctk_buy_submit']) || !is_user_logged_in()) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field($_POST['wctk_buy_nonce'] ?? ''), WCTK_NONCE_ACTION_BUY_TOKENS)) {
            wp_die(esc_html__('Security check failed. Please try again.', WCTK_TEXT_DOMAIN), '', ['response' => 403]);
        }

        $tokens_qty = absint($_POST['tokens_qty'] ?? 0);
        if ($tokens_qty <= 0) {
            wp_die(esc_html__('Invalid token quantity.', WCTK_TEXT_DOMAIN), '', ['response' => 400]);
        }

        $order = self::create_topup_order(get_current_user_id(), $tokens_qty);
        if (is_wp_error($order)) {
            wp_die(esc_html($order->get_error_message()), '', ['response' => 500]);
        }

        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /**
     * Хук на processing/completed. Идемпотентно: начисляем токены только один раз.
     */
    public static function handle_paid_order(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        if ($order->get_meta(WCTK_ORDER_META_IS_TOPUP) !== 'yes') return;

        if ($order->get_meta(WCTK_ORDER_META_TOKENS_CREDITED) === 'yes') return;

        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) return;

        $tokens_qty = (int) $order->get_meta(WCTK_ORDER_META_TOKENS_QTY);
        if ($tokens_qty <= 0) return;

        try {
            WCTK_Balance::change($user_id, $tokens_qty, WCTK_Ledger::KIND_TOPUP, (int) $order_id, __('Token top-up via Woo order', WCTK_TEXT_DOMAIN), [
                'order_total' => (float)$order->get_total(),
                'currency' => $order->get_currency(),
            ]);

            $order->update_meta_data(WCTK_ORDER_META_TOKENS_CREDITED, 'yes');
            $order->add_order_note(sprintf('Credited %d tokens to user #%d', $tokens_qty, $user_id));
            $order->save();
        } catch (Throwable $e) {
            // Не падаем; оставим заметку
            $order->add_order_note('Token credit failed: ' . $e->getMessage());
            $order->save();
        }
    }
}
