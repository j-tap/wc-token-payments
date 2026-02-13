<?php
/**
 * Plugin Name: WC Token Payments
 * Plugin URI: https://github.com/j-tap/wc-token-payments
 * Description: Token wallet: top-up with any currency (WooCommerce payment methods), then pay for orders with tokens.
 * Version: 0.1.0
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
define('WCTK_VERSION', '0.1.0');
define('WCTK_TEXT_DOMAIN', 'wc-token-payments');

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
