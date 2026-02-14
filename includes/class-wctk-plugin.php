<?php
if (!defined('ABSPATH')) exit;

require_once WCTK_PATH . 'includes/class-wctk-ledger.php';
require_once WCTK_PATH . 'includes/class-wctk-balance.php';
require_once WCTK_PATH . 'includes/class-wctk-shortcode-balance.php';
require_once WCTK_PATH . 'includes/class-wctk-shortcode-buy.php';
require_once WCTK_PATH . 'includes/class-wctk-account-token-balance.php';
require_once WCTK_PATH . 'includes/class-wctk-gateway-tokens.php';
require_once WCTK_PATH . 'includes/class-wctk-admin.php';

final class WCTK_Plugin {
    private static $instance = null;

    public static function instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    private function __construct() {}

    public static function load_textdomain(): void {
        load_plugin_textdomain(
            WCTK_TEXT_DOMAIN,
            false,
            dirname(plugin_basename(WCTK_FILE)) . '/languages'
        );
    }

    public static function activate(): void {
        WCTK_Ledger::create_table();
        WCTK_Shortcode_Buy::maybe_create_topup_product();
        add_rewrite_endpoint(WCTK_Account_Token_Balance::ENDPOINT, EP_ROOT | EP_PAGES);
        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        // Ничего не удаляем — чтобы исключить риск потери данных.
    }

    private function init(): void {
        add_action('init', function (): void {
            if (get_option(WCTK_OPT_RATE) === false) {
                add_option(WCTK_OPT_RATE, '1');
            }
        });

        add_action('init', [__CLASS__, 'load_textdomain'], 0);

        WCTK_Shortcode_Balance::init();
        WCTK_Shortcode_Buy::init();
        WCTK_Account_Token_Balance::init();
        WCTK_Gateway_Tokens::init();
        WCTK_Admin::init();

        // Начисление токенов после оплаты top-up заказа
        add_action('woocommerce_order_status_completed', ['WCTK_Shortcode_Buy', 'handle_paid_order'], 10, 1);
        add_action('woocommerce_order_status_processing', ['WCTK_Shortcode_Buy', 'handle_paid_order'], 10, 1);
    }

    public static function get_rate(): float {
        $rate = (float) get_option(WCTK_OPT_RATE, '1');
        return $rate > 0 ? $rate : 1.0;
    }
}
