<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Gateway_Tokens {

    public static function init(): void {
        add_filter('woocommerce_payment_gateways', [__CLASS__, 'register_gateway']);
        add_filter('woocommerce_available_payment_gateways', [__CLASS__, 'filter_available_gateways'], 20, 1);
        add_action('plugins_loaded', [__CLASS__, 'load_gateway_class'], 20);
    }

    public static function load_gateway_class(): void {
        if (!class_exists('WC_Payment_Gateway')) return;
        require_once WCTK_PATH . 'includes/class-wc-gateway-tokens.php';
    }

    /** @param array<string, WC_Payment_Gateway> $gateways */
    public static function register_gateway(array $gateways): array {
        if (class_exists('WC_Gateway_Tokens')) {
            $gateways[] = 'WC_Gateway_Tokens';
        }
        return $gateways;
    }

    /**
     * Скрываем метод, если пользователь не залогинен или в корзине top-up.
     *
     * @param array<string, WC_Payment_Gateway> $gateways
     * @return array<string, WC_Payment_Gateway>
     */
    public static function filter_available_gateways(array $gateways): array {
        if (!is_user_logged_in()) {
            unset($gateways['wctk_tokens']);
            return $gateways;
        }

        $topup_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        if ($topup_id > 0 && WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (!empty($cart_item['product_id']) && (int)$cart_item['product_id'] === $topup_id) {
                    unset($gateways['wctk_tokens']);
                    break;
                }
            }
        }
        return $gateways;
    }
}
