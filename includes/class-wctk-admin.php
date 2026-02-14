<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Admin {
    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    public static function menu(): void {
        add_submenu_page(
            'woocommerce',
            __('Token Payments', WCTK_TEXT_DOMAIN),
            __('Token Payments', WCTK_TEXT_DOMAIN),
            'manage_woocommerce',
            'wctk-token-payments',
            [__CLASS__, 'render_page']
        );
    }

    public static function register_settings(): void {
        register_setting('wctk_settings', WCTK_OPT_RATE, [
            'type' => 'string',
            'sanitize_callback' => function ($val) {
                $val = trim((string) $val);
                if ($val === '') return '1';
                if (!preg_match('/^\d+(\.\d+)?$/', $val)) return '1';
                if ((float) $val <= 0) return '1';
                return $val;
            }
        ]);

        register_setting('wctk_settings', WCTK_OPT_TOPUP_PRODUCT_ID, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_woocommerce')) return;

        $rate = get_option(WCTK_OPT_RATE, '1');
        $product_id = (int) get_option(WCTK_OPT_TOPUP_PRODUCT_ID, 0);

        // Ручная корректировка
        if (isset($_POST['wctk_adjust_submit'])) {
            check_admin_referer('wctk_adjust_balance');

            $user_id = (int)($_POST['user_id'] ?? 0);
            $delta = (int)($_POST['delta'] ?? 0);
            $note = sanitize_text_field($_POST['note'] ?? '');

            if ($user_id > 0 && $delta !== 0) {
                try {
                    WCTK_Balance::change($user_id, $delta, WCTK_Ledger::KIND_ADMIN_ADJUST, null, $note, [
                        'admin_id' => get_current_user_id(),
                    ]);
                    echo '<div class="notice notice-success"><p>' . esc_html__('Balance updated.', WCTK_TEXT_DOMAIN) . '</p></div>';
                } catch (Throwable $e) {
                    echo '<div class="notice notice-error"><p>' . esc_html__('Error:', WCTK_TEXT_DOMAIN) . ' ' . esc_html($e->getMessage()) . '</p></div>';
                }
            }
        }

        echo '<div class="wrap"><h1>' . esc_html__('Token Payments (MVP)', WCTK_TEXT_DOMAIN) . '</h1>';

        echo '<form method="post" action="options.php">';
        settings_fields('wctk_settings');
        do_settings_sections('wctk_settings');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Rate (base currency per 1 token)', WCTK_TEXT_DOMAIN) . '</th><td>';
        echo '<input type="text" name="' . esc_attr(WCTK_OPT_RATE) . '" value="' . esc_attr($rate) . '" />';
        echo '<p class="description">' . esc_html(sprintf(__('Example: 1 means 1 token = 1 %s', WCTK_TEXT_DOMAIN), get_woocommerce_currency())) . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Top-up product ID', WCTK_TEXT_DOMAIN) . '</th><td>';
        echo '<input type="number" name="' . esc_attr(WCTK_OPT_TOPUP_PRODUCT_ID) . '" value="' . esc_attr((string) $product_id) . '" min="0" />';
        echo '<p class="description">' . esc_html__('Auto-created on activation. Keep this stable.', WCTK_TEXT_DOMAIN) . '</p>';
        echo '</td></tr>';

        echo '</table>';
        submit_button(__('Save Settings', WCTK_TEXT_DOMAIN));
        echo '</form>';

        echo '<hr/><h2>' . esc_html__('Manual balance adjustment', WCTK_TEXT_DOMAIN) . '</h2>';
        echo '<form method="post">';
        wp_nonce_field('wctk_adjust_balance');
        echo '<table class="form-table"><tr><th>' . esc_html__('User ID', WCTK_TEXT_DOMAIN) . '</th><td><input name="user_id" type="number" min="1" required></td></tr>';
        echo '<tr><th>' . esc_html__('Delta tokens (+/-)', WCTK_TEXT_DOMAIN) . '</th><td><input name="delta" type="number" required></td></tr>';
        echo '<tr><th>' . esc_html__('Note', WCTK_TEXT_DOMAIN) . '</th><td><input name="note" type="text" style="width: 400px;"></td></tr></table>';
        echo '<p><button class="button button-primary" name="wctk_adjust_submit" value="1">' . esc_html__('Apply', WCTK_TEXT_DOMAIN) . '</button></p>';
        echo '</form>';

        echo '</div>';
    }
}
