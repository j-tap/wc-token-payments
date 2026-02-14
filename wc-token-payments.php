<?php
/**
 * Plugin Name: WC Token Payments
 * Plugin URI: https://github.com/j-tap/wc-token-payments
 * Description: Token wallet: top-up with any currency (WooCommerce payment methods), then pay for orders with tokens.
 * Version: 0.1.24
 * Author: j-tap
 * Author URI: https://github.com/j-tap
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-token-payments
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 9.x
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WCTK_FILE', __FILE__);
define('WCTK_PATH', plugin_dir_path(__FILE__));
define('WCTK_URL', plugin_dir_url(__FILE__));
define('WCTK_VERSION', '0.1.24');
define('WCTK_TEXT_DOMAIN', 'wc-token-payments');
define('WCTK_GITHUB_REPO', 'j-tap/wc-token-payments');
define('WCTK_OPT_RATE', 'wctk_rate');
define('WCTK_OPT_TOPUP_PRODUCT_ID', 'wctk_topup_product_id');
define('WCTK_ORDER_META_IS_TOPUP', '_wctk_is_topup');
define('WCTK_ORDER_META_TOKENS_QTY', '_wctk_tokens_qty');
define('WCTK_ORDER_META_TOKENS_CREDITED', '_wctk_tokens_credited');
define('WCTK_ORDER_META_TOKENS_SPENT', '_wctk_tokens_spent');
define('WCTK_ORDER_META_TOKENS_SPENT_QTY', '_wctk_tokens_spent_qty');
define('WCTK_NONCE_ACTION_BUY_TOKENS', 'wctk_buy_tokens');
define('WCTK_OPT_TOPUP_REDIRECT_URL', 'wctk_topup_redirect_url');

if (file_exists(WCTK_PATH . 'vendor/autoload.php')) {
    require_once WCTK_PATH . 'vendor/autoload.php';
    $wctk_updater = \YahnisElsts\PluginUpdateChecker\v5p6\PucFactory::buildUpdateChecker(
        'https://github.com/' . WCTK_GITHUB_REPO,
        __FILE__,
        'wc-token-payments'
    );
    $wctk_updater->getVcsApi()->enableReleaseAssets();
}

add_action('before_woocommerce_init', function (): void {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

require_once WCTK_PATH . 'includes/class-wctk-plugin.php';

register_activation_hook(__FILE__, ['WCTK_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WCTK_Plugin', 'deactivate']);

add_action('plugins_loaded', function (): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function (): void {
            $screen = get_current_screen();
            if ($screen && $screen->id === 'plugins') {
                echo '<div class="notice notice-warning"><p>';
                echo esc_html__('WC Token Payments requires WooCommerce to be installed and active.', WCTK_TEXT_DOMAIN);
                echo '</p></div>';
            }
        });
        return;
    }
    WCTK_Plugin::instance();
}, 11);
