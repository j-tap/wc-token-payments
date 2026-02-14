<?php
if (!defined('ABSPATH')) exit;

/**
 * Gateway registration, filtering, checkout UI and Blocks support.
 *
 * Adapts automatically to every environment:
 *  - Standard classic WooCommerce checkout (radio buttons)
 *  - Block-based checkout (Blocks API)
 *  - Custom/overridden checkout (standalone "Pay with Tokens" section)
 */
final class WCTK_Gateway_Tokens {

    public static function init(): void {
        // Standard WC gateway registration
        add_filter('woocommerce_payment_gateways', [__CLASS__, 'register_gateway']);
        add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'filter_available_gateways'], 20, 1);
        add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'ensure_gateway_available'], 9999, 1);

        add_action('plugins_loaded', [__CLASS__, 'load_gateway_class'], 20);

        // Block-based checkout support
        add_action('woocommerce_blocks_payment_method_type_registration', [__CLASS__, 'register_blocks_payment_method']);

        // Standalone "Pay with Tokens" section (auto-hides when standard UI is visible)
        add_action('woocommerce_review_order_before_payment', [__CLASS__, 'render_checkout_token_option'], 5);
        add_action('wp_footer', [__CLASS__, 'render_checkout_inline_script']);
    }

    /* ---------------------------------------------------------------
     *  Gateway class loading & registration
     * --------------------------------------------------------------- */

    public static function load_gateway_class(): void {
        if (!class_exists('WC_Payment_Gateway')) return;
        require_once WCTK_PATH . 'includes/class-wc-gateway-tokens.php';
    }

    public static function register_blocks_payment_method($registry): void {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) return;

        require_once WCTK_PATH . 'includes/class-wctk-blocks-support.php';
        $registry->register(new WCTK_Blocks_Support());
    }

    /** @param array<string> $gateways */
    public static function register_gateway(array $gateways): array {
        if (class_exists('WC_Gateway_Tokens')) {
            $gateways[] = 'WC_Gateway_Tokens';
        }
        return $gateways;
    }

    /* ---------------------------------------------------------------
     *  Available gateways filtering
     * --------------------------------------------------------------- */

    /** @return bool True if cart contains the top-up product. */
    private static function cart_has_topup(): bool {
        $topup_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        if ($topup_id <= 0 || !WC()->cart) return false;

        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['product_id']) && (int) $cart_item['product_id'] === $topup_id) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, WC_Payment_Gateway> $gateways
     * @return array<string, WC_Payment_Gateway>
     */
    public static function filter_available_gateways(array $gateways): array {
        if (!is_user_logged_in() || self::cart_has_topup()) {
            unset($gateways['wctk_tokens']);
            return $gateways;
        }

        if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-pay')) {
            $order_id = absint(get_query_var('order-pay'));
            if ($order_id > 0) {
                $order = wc_get_order($order_id);
                if ($order && $order->get_meta(WCTK_ORDER_META_IS_TOPUP) === 'yes') {
                    unset($gateways['wctk_tokens']);
                }
            }
        }

        return $gateways;
    }

    /**
     * Re-inject gateway at very late priority if removed by theme/plugin.
     *
     * @param array<string, WC_Payment_Gateway> $gateways
     * @return array<string, WC_Payment_Gateway>
     */
    public static function ensure_gateway_available(array $gateways): array {
        if (isset($gateways['wctk_tokens'])) return $gateways;
        if (!is_user_logged_in() || !class_exists('WC_Gateway_Tokens')) return $gateways;
        if (self::cart_has_topup()) return $gateways;

        $gateway = new WC_Gateway_Tokens();
        if ($gateway->is_available()) {
            $gateways['wctk_tokens'] = $gateway;
        }

        return $gateways;
    }

    /* ---------------------------------------------------------------
     *  Standalone "Pay with Tokens" checkout section
     *
     *  Renders a self-contained payment block on the checkout page.
     *  Auto-hides via JS if the standard WC payment method UI already
     *  shows our gateway (to avoid duplication on native checkouts).
     * --------------------------------------------------------------- */

    private static bool $token_section_rendered = false;

    public static function render_checkout_token_option(): void {
        if (self::$token_section_rendered) return;
        if (!is_user_logged_in() || self::cart_has_topup()) return;

        self::$token_section_rendered = true;

        $user_id = get_current_user_id();
        $balance = WCTK_Balance::get($user_id);
        $rate    = WCTK_Plugin::get_rate();
        $needed  = 0;
        $enough  = false;

        if (WC()->cart && $rate > 0) {
            $total    = (float) WC()->cart->get_total('edit');
            $currency = get_woocommerce_currency();
            $default  = get_option('woocommerce_currency', 'EUR');

            if ($currency !== $default) {
                $total = WCTK_Shortcode_Buy::convert_to_default_currency($total, $currency);
            }

            $needed = (int) ceil($total / $rate);
            $enough = $balance >= $needed;
        }
        ?>
        <div id="wctk-checkout-pay" class="wctk-checkout-pay <?php echo $enough ? 'wctk-checkout-pay--enough' : 'wctk-checkout-pay--insufficient'; ?>" style="
            border-radius: 8px;
            padding: 16px 20px;
            margin: 16px 0 20px;
        ">
            <h3 class="wctk-checkout-pay__title" style="margin: 0 0 10px; font-size: 1.1em;">
                <?php echo esc_html__('Pay with Tokens', WCTK_TEXT_DOMAIN); ?>
            </h3>

            <p class="wctk-checkout-pay__balance" style="margin: 4px 0;">
                <?php echo esc_html__('Token balance:', WCTK_TEXT_DOMAIN); ?>
                <strong class="wctk-checkout-pay__balance-value"><?php echo esc_html((string) $balance); ?></strong>
            </p>

            <?php if ($needed > 0): ?>
                <p class="wctk-checkout-pay__cost" style="margin: 4px 0;">
                    <?php echo esc_html(sprintf(
                        __('Tokens needed for this order: %d', WCTK_TEXT_DOMAIN),
                        $needed
                    )); ?>
                </p>
            <?php endif; ?>

            <?php if (!$enough && $needed > 0): ?>
                <p class="wctk-checkout-pay__warning" style="margin: 8px 0 4px;">
                    <?php echo esc_html(sprintf(
                        __('Not enough tokens. Need %1$d, you have %2$d.', WCTK_TEXT_DOMAIN),
                        $needed,
                        $balance
                    )); ?>
                </p>
                <?php
                $topup_path = trim((string) get_option(WCTK_OPT_TOPUP_PAGE_PATH, ''));
                if ($topup_path === '') {
                    $topup_path = '/my-account/edit-account/';
                }
                $topup_url = WCTK_Plugin::resolve_redirect_url($topup_path);
                if ($topup_url !== ''):
                ?>
                <a href="<?php echo esc_url($topup_url); ?>" class="wctk-checkout-pay__topup-link button" style="margin-top: 8px; padding: 10px 24px; font-size: 1em; display: inline-block;">
                    <?php echo esc_html__('Top up', WCTK_TEXT_DOMAIN); ?>
                </a>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($enough): ?>
                <button type="button"
                        id="wctk-pay-tokens-btn"
                        class="wctk-checkout-pay__button button alt"
                        style="margin-top: 10px; padding: 10px 24px; font-size: 1em;">
                    <?php echo esc_html__('Pay with Tokens', WCTK_TEXT_DOMAIN); ?>
                </button>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Inline JS that powers the standalone checkout section.
     *
     * Auto-hides the standalone block when the standard WC payment UI
     * already contains our gateway radio button (native checkout).
     */
    public static function render_checkout_inline_script(): void {
        if (!function_exists('is_checkout') || !is_checkout()) return;
        if (!is_user_logged_in()) return;
        ?>
        <script>
        (function() {
            var section = document.getElementById('wctk-checkout-pay');
            if (!section) return;

            /* ---- Auto-hide if standard WC payment UI visibly shows our gateway ---- */
            var nativeRadio = document.querySelector(
                '#payment input[name="payment_method"][value="wctk_tokens"]'
            );
            if (nativeRadio) {
                var paymentList = nativeRadio.closest('.wc_payment_methods, .woocommerce-checkout-payment, #payment');
                if (paymentList && paymentList.offsetHeight > 0 && paymentList.offsetWidth > 0) {
                    section.style.display = 'none';
                    return;
                }
            }

            var btn = document.getElementById('wctk-pay-tokens-btn');
            if (!btn) return;

            var btnLabel   = '<?php echo esc_js(__('Pay with Tokens', WCTK_TEXT_DOMAIN)); ?>';
            var btnLoading = '<?php echo esc_js(__('Processing...', WCTK_TEXT_DOMAIN)); ?>';

            function resetBtn() {
                btn.disabled = false;
                btn.textContent = btnLabel;
            }

            btn.addEventListener('click', function(e) {
                e.preventDefault();

                var form = document.querySelector(
                    'form.checkout, form.woocommerce-checkout, #order_review'
                );
                if (!form) form = btn.closest('form');
                if (!form) {
                    alert('<?php echo esc_js(__('Checkout form not found.', WCTK_TEXT_DOMAIN)); ?>');
                    return;
                }

                // Validate terms checkbox before locking the button
                var terms = form.querySelector('#terms');
                if (terms && !terms.checked) {
                    if (window.jQuery) jQuery(form).trigger('submit');
                    return;
                }

                btn.disabled = true;
                btn.textContent = btnLoading;

                // Override payment method
                form.querySelectorAll('input[name="payment_method"]').forEach(function(el) {
                    el.remove();
                });

                var input = document.createElement('input');
                input.type  = 'hidden';
                input.name  = 'payment_method';
                input.value = 'wctk_tokens';
                form.appendChild(input);

                if (window.jQuery) {
                    jQuery(form).trigger('submit');
                } else {
                    form.submit();
                }
            });

            // Restore button on errors or checkout updates
            if (window.jQuery) {
                jQuery(document.body).on('checkout_error updated_checkout', resetBtn);
            }
        })();
        </script>
        <?php
    }
}
