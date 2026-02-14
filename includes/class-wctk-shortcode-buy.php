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

    public static function get_default_currency(): string {
        return get_option('woocommerce_currency', 'EUR');
    }

    public static function get_current_currency(): string {
        return (string) apply_filters('wctk_current_currency', get_woocommerce_currency());
    }

    /**
     * Convert amount from given currency to default store currency.
     * Uses get_conversion_rate_to_default() so multi-currency plugins only need to hook 'wctk_convert_rate_to_default'.
     */
    public static function convert_to_default_currency(float $amount, string $from_currency): float {
        $rate = self::get_conversion_rate_to_default($from_currency);
        return $amount * $rate;
    }

    /**
     * Conversion rate multiplier: amount_in_default = amount_in_current * rate.
     * Plugins (e.g. WooCommerce Multi Currency) can hook into 'wctk_convert_rate_to_default'.
     */
    public static function get_conversion_rate_to_default(string $from_currency): float {
        $default = self::get_default_currency();
        if ($from_currency === $default) {
            return 1.0;
        }
        $rate = (float) apply_filters('wctk_convert_rate_to_default', 1.0, $from_currency, $default);
        return $rate > 0 ? $rate : 1.0;
    }

    /**
     * Universal: how many tokens for an amount in default currency. Rounds down.
     */
    public static function tokens_from_default_currency_amount(float $amount_default): int {
        $rate = self::rate();
        if ($rate <= 0) {
            return 0;
        }
        return (int) floor($amount_default / $rate);
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
        $current_currency = self::get_current_currency();
        $conversion_rate = self::get_conversion_rate_to_default($current_currency);

        ob_start();
        ?>
        <form method="post" class="wctk-topup-form">
            <?php wp_nonce_field(WCTK_NONCE_ACTION_BUY_TOKENS, 'wctk_buy_nonce'); ?>
            <input type="hidden" name="wctk_topup_currency" value="<?php echo esc_attr($current_currency); ?>">
            <div class="form-group">
                <label for="wctk_topup_amount"><?php echo esc_html(sprintf(__('Amount in %s:', WCTK_TEXT_DOMAIN), $current_currency)); ?></label>
                <input id="wctk_topup_amount" type="number" name="topup_amount" min="0.01" step="0.01" required class="woocommerce-Input woocommerce-Input--text input-text form-control" placeholder="0.00">
            </div>
            <p class="description wctk-tokens-preview-wrap">
                <?php echo esc_html__('You will receive:', WCTK_TEXT_DOMAIN); ?> <strong><span id="wctk-tokens-preview">0</span></strong> <?php echo esc_html__('tokens', WCTK_TEXT_DOMAIN); ?>
            </p>
            <script>
            (function(){
                var rate = <?php echo (float) $rate; ?>, conv = <?php echo (float) $conversion_rate; ?>;
                var inp = document.getElementById("wctk_topup_amount"), out = document.getElementById("wctk-tokens-preview");
                function upd(){ var a = parseFloat(inp.value) || 0; out.textContent = rate > 0 && a > 0 ? Math.floor((a * conv) / rate) : "0"; }
                inp.addEventListener("input", upd); inp.addEventListener("change", upd);
            })();
            </script>
            <div class="form-group">
                <button type="submit" name="wctk_buy_submit" value="1" class="woocommerce-Button axil-btn button woocommerce-Button--info"><?php echo esc_html__('Create order', WCTK_TEXT_DOMAIN); ?></button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    /**
     * Create a top-up order. If $order_total and $order_currency are set, the order
     * is created for that amount in that currency; otherwise total = tokens_qty * rate (default currency).
     *
     * @param int    $user_id        User ID.
     * @param int    $tokens_qty    Tokens to credit after payment.
     * @param float  $order_total   Optional. Order total in $order_currency (for amount-based flow).
     * @param string $order_currency Optional. Currency code (e.g. EUR, USD).
     * @return WC_Order|WP_Error
     */
    public static function create_topup_order(int $user_id, int $tokens_qty, float $order_total = 0.0, string $order_currency = '') {
        if ($user_id <= 0 || $tokens_qty <= 0) {
            return new WP_Error('wctk_invalid_args', __('Invalid user or token quantity.', WCTK_TEXT_DOMAIN));
        }

        self::maybe_create_topup_product();
        $product_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_Error('wctk_no_product', __('Top-up product not found.', WCTK_TEXT_DOMAIN));
        }

        $total = $order_total > 0 && $order_currency !== ''
            ? $order_total
            : $tokens_qty * self::rate();

        $order = wc_create_order(['customer_id' => $user_id]);
        if (is_wp_error($order)) {
            return $order;
        }

        if ($order_currency !== '') {
            $order->set_currency($order_currency);
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

        $amount = isset($_POST['topup_amount']) ? (float) $_POST['topup_amount'] : 0.0;
        $currency = sanitize_text_field($_POST['wctk_topup_currency'] ?? '');
        if ($currency === '') {
            $currency = self::get_current_currency();
        }

        if ($amount <= 0) {
            wp_die(esc_html__('Invalid amount.', WCTK_TEXT_DOMAIN), '', ['response' => 400]);
        }

        $amount_default = self::convert_to_default_currency($amount, $currency);
        $tokens_qty = self::tokens_from_default_currency_amount($amount_default);

        if ($tokens_qty < 1) {
            wp_die(esc_html__('Amount is too low to receive at least 1 token.', WCTK_TEXT_DOMAIN), '', ['response' => 400]);
        }

        $order = self::create_topup_order(get_current_user_id(), $tokens_qty, $amount, $currency);
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
