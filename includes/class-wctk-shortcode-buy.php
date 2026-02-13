<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Shortcode_Buy {

    public static function init(): void {
        add_shortcode('wctk_buy_tokens', [__CLASS__, 'shortcode']);
        add_action('init', [__CLASS__, 'handle_form_submit']);
    }

    public static function rate(): float {
        $rate = (float) get_option('wctk_rate', '1');
        if ($rate <= 0) $rate = 1.0;
        return $rate;
    }

    public static function maybe_create_topup_product(): void {
        if (!function_exists('wc_get_product')) return;

        $existing_id = (int) get_option('wctk_topup_product_id', 0);
        if ($existing_id > 0 && wc_get_product($existing_id)) return;

        // Создаем скрытый виртуальный товар
        $product = new WC_Product_Simple();
        $product->set_name('Token top-up');
        $product->set_status('private'); // не виден в каталоге
        $product->set_virtual(true);
        $product->set_downloadable(false);
        $product->set_catalog_visibility('hidden');
        $product->set_sold_individually(false);

        // Цена будет перезаписана при создании заказа (order item total),
        // но ставим дефолт, чтобы продукт был валидным:
        $product->set_regular_price('0');

        $product_id = $product->save();
        update_option('wctk_topup_product_id', (int)$product_id);
    }

    public static function shortcode(): string {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to buy tokens.', WCTK_TEXT_DOMAIN) . '</p>';
        }

        $rate = self::rate();
        $currency = get_woocommerce_currency();
        $balance = WCTK_Balance::get(get_current_user_id());

        ob_start();
        ?>
        <div class="wctk-buy-tokens">
            <p><strong><?php echo esc_html__('Your token balance:', WCTK_TEXT_DOMAIN); ?></strong> <?php echo esc_html((string) $balance); ?></p>

            <form method="post">
                <?php wp_nonce_field('wctk_buy_tokens', 'wctk_buy_nonce'); ?>
                <label>
                    <?php echo esc_html__('How many tokens to buy:', WCTK_TEXT_DOMAIN); ?>
                    <input type="number" name="tokens_qty" min="1" step="1" required>
                </label>

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

                <button type="submit" name="wctk_buy_submit" value="1">
                    <?php echo esc_html__('Create order', WCTK_TEXT_DOMAIN); ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function handle_form_submit(): void {
        if (!isset($_POST['wctk_buy_submit']) || !is_user_logged_in()) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field($_POST['wctk_buy_nonce'] ?? ''), 'wctk_buy_tokens')) {
            wp_die(esc_html__('Security check failed. Please try again.', WCTK_TEXT_DOMAIN), '', ['response' => 403]);
        }

        $tokens_qty = absint($_POST['tokens_qty'] ?? 0);
        if ($tokens_qty <= 0) {
            wp_die(esc_html__('Invalid token quantity.', WCTK_TEXT_DOMAIN), '', ['response' => 400]);
        }

        self::maybe_create_topup_product();
        $product_id = (int) get_option('wctk_topup_product_id', 0);
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_die(esc_html__('Top-up product not found.', WCTK_TEXT_DOMAIN), '', ['response' => 500]);
        }

        $rate = self::rate();
        $total = $tokens_qty * $rate;

        // Создаем заказ
        $order = wc_create_order(['customer_id' => get_current_user_id()]);
        $item_id = $order->add_product($product, 1, [
            'subtotal' => $total,
            'total' => $total,
        ]);

        // Сохраняем кол-во токенов в meta item
        wc_add_order_item_meta($item_id, 'tokens_qty', $tokens_qty, true);

        // Помечаем заказ как top-up (важно для изоляции и начисления)
        $order->update_meta_data('_wctk_is_topup', 'yes');
        $order->update_meta_data('_wctk_tokens_qty', $tokens_qty);
        $order->save();

        // Переходим на оплату стандартными методами Woo (любой платежный шлюз / мультивалюта)
        wp_safe_redirect($order->get_checkout_payment_url());
        exit;
    }

    /**
     * Хук на processing/completed. Идемпотентно: начисляем токены только один раз.
     */
    public static function handle_paid_order(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        if ($order->get_meta('_wctk_is_topup') !== 'yes') return;

        // не начислять повторно
        if ($order->get_meta('_wctk_tokens_credited') === 'yes') return;

        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) return;

        $tokens_qty = (int) $order->get_meta('_wctk_tokens_qty');
        if ($tokens_qty <= 0) return;

        try {
            WCTK_Balance::change($user_id, $tokens_qty, WCTK_Ledger::KIND_TOPUP, (int) $order_id, __('Token top-up via Woo order', WCTK_TEXT_DOMAIN), [
                'order_total' => (float)$order->get_total(),
                'currency' => $order->get_currency(),
            ]);

            $order->update_meta_data('_wctk_tokens_credited', 'yes');
            $order->add_order_note(sprintf('Credited %d tokens to user #%d', $tokens_qty, $user_id));
            $order->save();
        } catch (Throwable $e) {
            // Не падаем; оставим заметку
            $order->add_order_note('Token credit failed: ' . $e->getMessage());
            $order->save();
        }
    }
}
