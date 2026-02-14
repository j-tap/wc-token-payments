<?php
if (!defined('ABSPATH')) exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WCTK_Blocks_Support extends AbstractPaymentMethodType {

    protected $name = 'wctk_tokens';

    public function initialize(): void {
        $this->settings = get_option('woocommerce_wctk_tokens_settings', []);
    }

    public function is_active(): bool {
        return !empty($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles(): array {
        wp_register_script(
            'wctk-tokens-blocks',
            WCTK_URL . 'assets/js/wctk-tokens-blocks.js',
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
            WCTK_VERSION,
            true
        );
        return ['wctk-tokens-blocks'];
    }

    public function get_payment_method_data(): array {
        $balance    = 0;
        $is_topup   = false;
        $logged_in  = is_user_logged_in();

        if ($logged_in) {
            $balance = WCTK_Balance::get(get_current_user_id());
        }

        $topup_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);
        if ($topup_id > 0 && WC()->cart) {
            foreach (WC()->cart->get_cart() as $item) {
                if (!empty($item['product_id']) && (int) $item['product_id'] === $topup_id) {
                    $is_topup = true;
                    break;
                }
            }
        }

        return [
            'title'        => $this->get_setting('title') ?: __('Pay with Tokens', WCTK_TEXT_DOMAIN),
            'supports'     => ['products'],
            'loggedIn'     => $logged_in,
            'isTopup'      => $is_topup,
            'balance'      => $balance,
            'rate'         => WCTK_Plugin::get_rate(),
            'balanceLabel' => __('Token balance:', WCTK_TEXT_DOMAIN),
        ];
    }
}
