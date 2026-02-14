<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Shortcode_Buy {

    private const SAVED_CART_TTL = HOUR_IN_SECONDS;

    public static function init(): void {
        add_shortcode('wctk_buy_tokens', [__CLASS__, 'shortcode']);
        add_action('init', [__CLASS__, 'handle_form_submit'], 20);

        // Allow the top-up product to be purchased (it has price 0 and status private)
        add_filter('woocommerce_is_purchasable', [__CLASS__, 'make_topup_product_purchasable'], 10, 2);

        // Multi-currency auto-detection (VillaTheme WooCommerce Multi Currency)
        add_filter('wctk_convert_rate_to_default', [__CLASS__, 'auto_detect_conversion_rate'], 5, 3);

        // Cart-based checkout hooks (priority 99 — run AFTER multi-currency plugins)
        add_action('woocommerce_before_calculate_totals', [__CLASS__, 'set_topup_cart_item_price'], 99);
        add_filter('woocommerce_get_item_data', [__CLASS__, 'display_topup_cart_item_data'], 10, 2);
        add_filter('woocommerce_cart_item_name', [__CLASS__, 'topup_cart_item_name'], 10, 3);
        add_action('woocommerce_checkout_create_order_line_item', [__CLASS__, 'transfer_topup_meta_to_order_item'], 10, 4);
        add_action('woocommerce_checkout_order_processed', [__CLASS__, 'mark_checkout_topup_order'], 10, 3);
        add_action('woocommerce_thankyou', [__CLASS__, 'restore_cart_after_topup'], 5);
        add_action('template_redirect', [__CLASS__, 'maybe_restore_saved_cart']);
    }

    // ──────────────────────────────────────────────
    //  Rate / currency helpers (public API)
    // ──────────────────────────────────────────────

    public static function rate(): float {
        return WCTK_Plugin::get_rate();
    }

    public static function get_default_currency(): string {
        return get_option('woocommerce_currency', 'EUR');
    }

    public static function get_current_currency(): string {
        return (string) apply_filters('wctk_current_currency', get_woocommerce_currency());
    }

    public static function convert_to_default_currency(float $amount, string $from_currency): float {
        return $amount * self::get_conversion_rate_to_default($from_currency);
    }

    public static function get_conversion_rate_to_default(string $from_currency): float {
        $default = self::get_default_currency();
        if ($from_currency === $default) {
            return 1.0;
        }
        $rate = (float) apply_filters('wctk_convert_rate_to_default', 1.0, $from_currency, $default);
        return $rate > 0 ? $rate : 1.0;
    }

    public static function tokens_from_default_currency_amount(float $amount_default): int {
        $rate = self::rate();
        return $rate > 0 ? (int) floor($amount_default / $rate) : 0;
    }

    // ──────────────────────────────────────────────
    //  Top-up product
    // ──────────────────────────────────────────────

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

    // ──────────────────────────────────────────────
    //  Checkout URL (not hardcoded)
    // ──────────────────────────────────────────────

    /**
     * Checkout URL for top-up: custom from settings or WooCommerce default.
     */
    public static function get_topup_checkout_url(): string {
        $custom = trim((string) get_option(WCTK_OPT_TOPUP_REDIRECT_URL, ''));
        return $custom !== '' ? $custom : wc_get_checkout_url();
    }

    // ──────────────────────────────────────────────
    //  Shortcode / form
    // ──────────────────────────────────────────────

    public static function shortcode(): string {
        return self::render_topup_form();
    }

    /**
     * Render top-up form (amount + submit).
     * Payment method selection happens on the WooCommerce checkout page.
     */
    public static function render_topup_form(): string {
        if (!is_user_logged_in()) {
            return '<p class="wctk-notice wctk-notice--login">' . esc_html__('You must be logged in to buy tokens.', WCTK_TEXT_DOMAIN) . '</p>';
        }

        $rate             = self::rate();
        $current_currency = self::get_current_currency();
        $conversion_rate  = self::get_conversion_rate_to_default($current_currency);

        ob_start();
        wc_print_notices();
        ?>
        <form method="post" class="wctk-topup-form">
            <?php wp_nonce_field(WCTK_NONCE_ACTION_BUY_TOKENS, 'wctk_buy_nonce'); ?>
            <input type="hidden" name="wctk_topup_currency" value="<?php echo esc_attr($current_currency); ?>">
            <input type="hidden" name="wctk_form_url" value="<?php echo esc_url(get_permalink()); ?>">

            <div class="wctk-topup-form__group wctk-topup-form__group--amount form-group">
                <label class="wctk-topup-form__label" for="wctk_topup_amount"><?php echo esc_html(sprintf(__('Amount in %s:', WCTK_TEXT_DOMAIN), $current_currency)); ?></label>
                <input id="wctk_topup_amount" type="number" name="topup_amount" min="0.01" step="0.01" required
                       class="wctk-topup-form__input woocommerce-Input woocommerce-Input--text input-text form-control" placeholder="0.00">
            </div>

            <p class="wctk-topup-form__preview description">
                <span class="wctk-topup-form__preview-label"><?php echo esc_html__('You will receive:', WCTK_TEXT_DOMAIN); ?></span>
                <strong class="wctk-topup-form__preview-value"><span id="wctk-tokens-preview">0</span></strong>
                <span class="wctk-topup-form__preview-unit"><?php echo esc_html__('tokens', WCTK_TEXT_DOMAIN); ?></span>
            </p>

            <script>
            (function(){
                var rate = <?php echo (float) $rate; ?>,
                    conv = <?php echo (float) $conversion_rate; ?>;
                var inp = document.getElementById("wctk_topup_amount"),
                    out = document.getElementById("wctk-tokens-preview");
                function upd(){
                    var a = parseFloat(inp.value) || 0;
                    out.textContent = rate > 0 && a > 0 ? Math.floor((a * conv) / rate) : "0";
                }
                inp.addEventListener("input", upd);
                inp.addEventListener("change", upd);
            })();
            </script>

            <div class="wctk-topup-form__group wctk-topup-form__group--submit form-group">
                <button type="submit" name="wctk_buy_submit" value="1"
                        class="wctk-topup-form__submit woocommerce-Button axil-btn button woocommerce-Button--info">
                    <?php echo esc_html__('Proceed to payment', WCTK_TEXT_DOMAIN); ?>
                </button>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    // ──────────────────────────────────────────────
    //  Form handler → add to cart → redirect to checkout
    // ──────────────────────────────────────────────

    public static function handle_form_submit(): void {
        if (!isset($_POST['wctk_buy_submit']) || !is_user_logged_in()) {
            return;
        }

        if (!wp_verify_nonce(sanitize_text_field($_POST['wctk_buy_nonce'] ?? ''), WCTK_NONCE_ACTION_BUY_TOKENS)) {
            wp_die(esc_html__('Security check failed. Please try again.', WCTK_TEXT_DOMAIN), '', ['response' => 403]);
        }

        $amount   = isset($_POST['topup_amount']) ? (float) $_POST['topup_amount'] : 0.0;
        $currency = sanitize_text_field($_POST['wctk_topup_currency'] ?? '');
        if ($currency === '') {
            $currency = self::get_current_currency();
        }

        $form_url = esc_url_raw($_POST['wctk_form_url'] ?? '');
        $referer  = $form_url !== '' ? $form_url : (wp_get_referer() ?: home_url());

        if ($amount <= 0) {
            wc_add_notice(__('Invalid amount.', WCTK_TEXT_DOMAIN), 'error');
            wp_safe_redirect($referer);
            exit;
        }

        $amount_default = self::convert_to_default_currency($amount, $currency);
        $tokens_qty     = self::tokens_from_default_currency_amount($amount_default);

        if ($tokens_qty < 1) {
            wc_add_notice(__('Amount is too low to receive at least 1 token.', WCTK_TEXT_DOMAIN), 'error');
            wp_safe_redirect($referer);
            exit;
        }

        self::maybe_create_topup_product();
        $product_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        if (!$product_id || !wc_get_product($product_id)) {
            wc_add_notice(__('Top-up product not found.', WCTK_TEXT_DOMAIN), 'error');
            wp_safe_redirect($referer);
            exit;
        }

        // Save current cart so we can restore it after payment
        self::save_cart();
        WC()->session->set('wctk_topup_pending', true);

        // Replace cart with the top-up product (custom price via woocommerce_before_calculate_totals)
        WC()->cart->empty_cart();
        $added = WC()->cart->add_to_cart($product_id, 1, 0, [], [
            'wctk_topup'          => true,
            'wctk_tokens_qty'     => $tokens_qty,
            'wctk_topup_amount'   => $amount,
            'wctk_topup_currency' => $currency,
        ]);

        if (!$added) {
            self::restore_saved_cart_now();
            wc_add_notice(__('Could not add top-up to cart.', WCTK_TEXT_DOMAIN), 'error');
            wp_safe_redirect($referer);
            exit;
        }

        $checkout_url = self::get_topup_checkout_url();
        wp_redirect($checkout_url);
        exit;
    }

    // ──────────────────────────────────────────────
    //  Cart save / restore
    // ──────────────────────────────────────────────

    private static function saved_cart_key(): string {
        return 'wctk_saved_cart_' . get_current_user_id();
    }

    private static function save_cart(): void {
        $cart = WC()->cart->get_cart_for_session();
        if (!empty($cart)) {
            set_transient(self::saved_cart_key(), $cart, self::SAVED_CART_TTL);
        }
    }

    private static function restore_saved_cart_now(): void {
        $key = self::saved_cart_key();
        $saved = get_transient($key);
        if ($saved === false) return;

        WC()->cart->empty_cart();
        WC()->session->set('cart', $saved);
        delete_transient($key);
    }

    /**
     * On thank-you page: restore original cart after successful top-up payment.
     */
    public static function restore_cart_after_topup(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(WCTK_ORDER_META_IS_TOPUP) !== 'yes') return;

        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0 || $user_id !== get_current_user_id()) return;

        self::finish_topup_and_restore_cart();
    }

    /**
     * Clear the topup-pending flag and restore the original cart from transient.
     */
    private static function finish_topup_and_restore_cart(): void {
        if (WC()->session) {
            WC()->session->set('wctk_topup_pending', false);
        }

        $key   = self::saved_cart_key();
        $saved = get_transient($key);
        if ($saved !== false && !empty($saved)) {
            WC()->session->set('cart', $saved);
        }
        delete_transient($key);
    }

    /**
     * If user navigates away from checkout, restore their original cart.
     * Skips restoration while the top-up payment is still in progress.
     */
    public static function maybe_restore_saved_cart(): void {
        if (!is_user_logged_in()) return;

        // Never restore on checkout / order-pay / order-received pages
        if (function_exists('is_checkout') && is_checkout()) return;

        // Never restore during gateway callbacks (e.g. ?wc-api=...)
        if (!empty($_GET['wc-api'])) return;

        // Don't restore while payment is in progress
        if (WC()->session && WC()->session->get('wctk_topup_pending')) return;

        $key   = self::saved_cart_key();
        $saved = get_transient($key);
        if ($saved === false) return;

        WC()->cart->empty_cart();
        WC()->session->set('cart', $saved);
        delete_transient($key);
    }

    // ──────────────────────────────────────────────
    //  Multi-currency integration
    // ──────────────────────────────────────────────

    /**
     * Auto-detect conversion rate from popular multi-currency plugins.
     * Hooked into 'wctk_convert_rate_to_default' at priority 5.
     *
     * Supported: VillaTheme WooCommerce Multi Currency (free & premium).
     */
    public static function auto_detect_conversion_rate(float $rate, string $from_currency, string $default_currency): float {
        // VillaTheme WooCommerce Multi Currency
        foreach (['WOOMULTI_CURRENCY_Data', 'WOOMULTI_CURRENCY_F_Data'] as $class) {
            if (!class_exists($class)) continue;

            $data       = new $class();
            $currencies = $data->get_list_currencies();

            if (isset($currencies[$from_currency]['rate'])) {
                $mc_rate = (float) $currencies[$from_currency]['rate'];
                if ($mc_rate > 0) {
                    // mc_rate: 1 default = mc_rate foreign → to convert foreign→default: / mc_rate
                    return 1.0 / $mc_rate;
                }
            }
            break;
        }

        return $rate;
    }

    // ──────────────────────────────────────────────
    //  Cart ↔ checkout hooks
    // ──────────────────────────────────────────────

    /**
     * The top-up product is private with price 0 — WooCommerce blocks it by default.
     */
    public static function make_topup_product_purchasable(bool $purchasable, $product): bool {
        $topup_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        if ($topup_id > 0 && $product->get_id() === $topup_id) {
            return true;
        }
        return $purchasable;
    }

    /**
     * Override the cart item price with the top-up amount.
     * Priority 99 — runs AFTER multi-currency plugins so they don't re-convert our price.
     * The stored amount is in the user's selected currency (what they entered).
     */
    public static function set_topup_cart_item_price($cart): void {
        if (is_admin() && !defined('DOING_AJAX')) return;

        foreach ($cart->get_cart() as $item) {
            if (!empty($item['wctk_topup']) && isset($item['wctk_topup_amount'])) {
                $item['data']->set_price((float) $item['wctk_topup_amount']);
            }
        }
    }

    /**
     * Display "Token top-up" as the item name in cart/checkout instead of the DB product name.
     */
    public static function topup_cart_item_name($name, $cart_item, $cart_item_key): string {
        if (!empty($cart_item['wctk_topup'])) {
            return esc_html__('Token top-up', WCTK_TEXT_DOMAIN);
        }
        return $name;
    }

    /**
     * Show "You will receive: X tokens" in the cart/checkout item details.
     */
    public static function display_topup_cart_item_data(array $item_data, array $cart_item): array {
        if (!empty($cart_item['wctk_topup']) && !empty($cart_item['wctk_tokens_qty'])) {
            $item_data[] = [
                'key'   => __('Tokens', WCTK_TEXT_DOMAIN),
                'value' => (string) $cart_item['wctk_tokens_qty'],
            ];
        }
        return $item_data;
    }

    /**
     * Transfer top-up meta from cart item to order item and set proper name.
     */
    public static function transfer_topup_meta_to_order_item($item, $cart_item_key, $values, $order): void {
        if (!empty($values['wctk_topup'])) {
            $item->add_meta_data('tokens_qty', (int) ($values['wctk_tokens_qty'] ?? 0), true);
            $item->set_name(__('Token top-up', WCTK_TEXT_DOMAIN));
        }
    }

    /**
     * After WooCommerce creates the order from checkout, mark it as top-up.
     *
     * @param int      $order_id
     * @param array    $posted_data
     * @param WC_Order $order
     */
    public static function mark_checkout_topup_order($order_id, $posted_data, $order): void {
        if (!WC()->cart) return;

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (empty($cart_item['wctk_topup'])) continue;

            $tokens_qty = (int) ($cart_item['wctk_tokens_qty'] ?? 0);
            if ($tokens_qty <= 0) continue;

            $order->update_meta_data(WCTK_ORDER_META_IS_TOPUP, 'yes');
            $order->update_meta_data(WCTK_ORDER_META_TOKENS_QTY, $tokens_qty);
            $order->save();
            break;
        }
    }

    // ──────────────────────────────────────────────
    //  Programmatic order creation (public API)
    // ──────────────────────────────────────────────

    /**
     * Create a top-up order programmatically (e.g. from theme code).
     * Billing details are copied from the customer profile.
     *
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

        $customer = new WC_Customer($user_id);
        $order->set_address([
            'first_name' => $customer->get_billing_first_name() ?: $customer->get_first_name(),
            'last_name'  => $customer->get_billing_last_name() ?: $customer->get_last_name(),
            'email'      => $customer->get_billing_email() ?: $customer->get_email(),
            'phone'      => $customer->get_billing_phone(),
            'company'    => $customer->get_billing_company(),
            'address_1'  => $customer->get_billing_address_1(),
            'address_2'  => $customer->get_billing_address_2(),
            'city'       => $customer->get_billing_city(),
            'state'      => $customer->get_billing_state(),
            'postcode'   => $customer->get_billing_postcode(),
            'country'    => $customer->get_billing_country(),
        ], 'billing');

        $item_id = $order->add_product($product, 1, [
            'subtotal' => $total,
            'total'    => $total,
        ]);
        wc_add_order_item_meta($item_id, 'tokens_qty', $tokens_qty, true);

        $order->update_meta_data(WCTK_ORDER_META_IS_TOPUP, 'yes');
        $order->update_meta_data(WCTK_ORDER_META_TOKENS_QTY, $tokens_qty);
        $order->calculate_totals();
        $order->save();

        return $order;
    }

    // ──────────────────────────────────────────────
    //  Token crediting on order completion
    // ──────────────────────────────────────────────

    /**
     * Idempotent: credit tokens only once per order.
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
                'order_total' => (float) $order->get_total(),
                'currency'    => $order->get_currency(),
            ]);

            $order->update_meta_data(WCTK_ORDER_META_TOKENS_CREDITED, 'yes');
            $order->add_order_note(sprintf('Credited %d tokens to user #%d', $tokens_qty, $user_id));
            $order->save();
        } catch (Throwable $e) {
            $order->add_order_note('Token credit failed: ' . $e->getMessage());
            $order->save();
        }
    }

    /**
     * When a top-up order is cancelled or fails, clear the pending flag
     * so the user's original cart can be restored on next page load.
     */
    public static function handle_topup_order_cancelled(int $order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta(WCTK_ORDER_META_IS_TOPUP) !== 'yes') return;

        $user_id = (int) $order->get_customer_id();
        if ($user_id <= 0) return;

        if (WC()->session && (int) WC()->session->get_customer_id() === $user_id) {
            WC()->session->set('wctk_topup_pending', false);
        }
    }
}
