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
                $tokens = (float) $val;
                if ($tokens <= 0) return '1';
                return (string) (1 / $tokens);
            }
        ]);

        register_setting('wctk_settings', WCTK_OPT_TOPUP_PRODUCT_ID, [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
        ]);

        register_setting('wctk_settings', WCTK_OPT_TOPUP_REDIRECT_URL, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
        ]);
    }

    public static function render_page(): void {
        if (!current_user_can('manage_woocommerce')) return;

        $rate = (float) get_option(WCTK_OPT_RATE, '1');
        $tokens_per_currency = $rate > 0 ? (1 / $rate) : 1;
        $currency = get_woocommerce_currency();
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

        echo '<div class="wrap wctk-admin">';
        echo '<h1 class="wctk-admin__title wp-heading-inline">' . esc_html__('Token Payments', WCTK_TEXT_DOMAIN) . '</h1>';
        echo '<p class="wctk-admin__desc">' . esc_html__('Configure the token rate and manage balances.', WCTK_TEXT_DOMAIN) . '</p>';
        echo '<hr class="wp-header-end">';

        // --- Settings card ---
        echo '<div class="wctk-admin-card wctk-admin-card--settings" style="max-width: 640px; margin-top: 20px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px;">';
        echo '<div class="wctk-admin-card__header" style="padding: 20px 24px 0; border-bottom: 1px solid #c3c4c7;"><h2 style="margin: 0 0 4px; font-size: 1.1em;">' . esc_html__('Settings', WCTK_TEXT_DOMAIN) . '</h2><p style="margin: 0; color: #646970;">' . esc_html__('Token rate and top-up product.', WCTK_TEXT_DOMAIN) . '</p></div>';
        echo '<div class="wctk-admin-card__body" style="padding: 20px 24px 24px;">';
        echo '<form method="post" action="options.php" class="wctk-settings-form">';
        settings_fields('wctk_settings');
        do_settings_sections('wctk_settings');

        echo '<table class="form-table" role="presentation" style="margin-top: 0;">';

        echo '<tr class="wctk-settings-form__row wctk-settings-form__row--rate"><th scope="row" style="padding-top: 16px;">' . esc_html(sprintf(__('Tokens per 1 %s', WCTK_TEXT_DOMAIN), $currency)) . '</th><td style="padding-top: 16px;">';
        echo '<input type="text" id="wctk_tokens_per_currency" name="' . esc_attr(WCTK_OPT_RATE) . '" value="' . esc_attr(rtrim(rtrim(number_format($tokens_per_currency, 4, '.', ''), '0'), '.')) . '" class="wctk-settings-form__input regular-text" />';
        echo '<p class="wctk-settings-form__hint description" id="wctk-rate-preview" style="margin-top: 8px;">1 <span id="wctk-rate-currency">' . esc_html($currency) . '</span> = <span id="wctk-rate-tokens">' . esc_html((string) $tokens_per_currency) . '</span> ' . esc_html__('tokens', WCTK_TEXT_DOMAIN) . '</p>';
        echo '<script>(function(){var i=document.getElementById("wctk_tokens_per_currency"),s=document.getElementById("wctk-rate-tokens");function u(){var v=parseFloat(i.value);s.textContent=isNaN(v)||v<=0?"—":v;}i.addEventListener("input",u);i.addEventListener("change",u);})();</script>';
        echo '</td></tr>';

        echo '<tr class="wctk-settings-form__row wctk-settings-form__row--product"><th scope="row">' . esc_html__('Top-up product ID', WCTK_TEXT_DOMAIN) . '</th><td>';
        echo '<input type="number" name="' . esc_attr(WCTK_OPT_TOPUP_PRODUCT_ID) . '" value="' . esc_attr((string) $product_id) . '" min="0" class="wctk-settings-form__input small-text" />';
        echo '<p class="wctk-settings-form__hint description">' . esc_html__('Auto-created on activation. Keep this stable.', WCTK_TEXT_DOMAIN) . '</p>';
        echo '</td></tr>';

        $topup_redirect_url = get_option(WCTK_OPT_TOPUP_REDIRECT_URL, '');
        $default_checkout_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : '';
        echo '<tr class="wctk-settings-form__row wctk-settings-form__row--redirect"><th scope="row">' . esc_html__('Top-up payment page', WCTK_TEXT_DOMAIN) . '</th><td>';
        echo '<input type="url" name="' . esc_attr(WCTK_OPT_TOPUP_REDIRECT_URL) . '" value="' . esc_attr($topup_redirect_url) . '" class="wctk-settings-form__input regular-text" placeholder="' . esc_attr($default_checkout_url) . '" />';
        echo '<p class="wctk-settings-form__hint description">' . esc_html(sprintf(
            __('Leave empty to use the default WooCommerce checkout (%s). Customer is redirected here to enter billing details, select payment method and pay.', WCTK_TEXT_DOMAIN),
            $default_checkout_url
        )) . '</p>';
        echo '</td></tr>';

        echo '</table>';
        submit_button(__('Save Settings', WCTK_TEXT_DOMAIN));
        echo '</form>';
        echo '</div></div>';

        // --- Manual adjustment card ---
        echo '<div class="wctk-admin-card wctk-admin-card--adjust" style="max-width: 640px; margin-top: 24px; background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px;">';
        echo '<div class="wctk-admin-card__header" style="padding: 20px 24px 0; border-bottom: 1px solid #c3c4c7;"><h2 style="margin: 0 0 4px; font-size: 1.1em;">' . esc_html__('Manual balance adjustment', WCTK_TEXT_DOMAIN) . '</h2><p style="margin: 0; color: #646970;">' . esc_html__('Add or subtract tokens for a user.', WCTK_TEXT_DOMAIN) . '</p></div>';
        echo '<div class="wctk-admin-card__body" style="padding: 20px 24px 24px;">';
        echo '<form method="post" class="wctk-adjust-form">';
        wp_nonce_field('wctk_adjust_balance');
        echo '<table class="form-table" role="presentation" style="margin-top: 0;">';
        echo '<tr class="wctk-adjust-form__row"><th scope="row" style="padding-top: 16px;">' . esc_html__('User ID', WCTK_TEXT_DOMAIN) . '</th><td style="padding-top: 16px;"><input name="user_id" type="number" min="1" required class="wctk-adjust-form__input regular-text"></td></tr>';
        echo '<tr class="wctk-adjust-form__row"><th scope="row">' . esc_html__('Delta tokens (+/-)', WCTK_TEXT_DOMAIN) . '</th><td><input name="delta" type="number" required class="wctk-adjust-form__input small-text" placeholder="e.g. 10 or -5"></td></tr>';
        echo '<tr class="wctk-adjust-form__row"><th scope="row">' . esc_html__('Note', WCTK_TEXT_DOMAIN) . '</th><td><input name="note" type="text" class="wctk-adjust-form__input regular-text" placeholder="' . esc_attr__('Optional note', WCTK_TEXT_DOMAIN) . '"></td></tr>';
        echo '</table>';
        echo '<p style="margin-bottom: 0;"><button class="wctk-adjust-form__submit button button-primary" name="wctk_adjust_submit" value="1">' . esc_html__('Apply', WCTK_TEXT_DOMAIN) . '</button></p>';
        echo '</form>';
        echo '</div></div>';

        echo '</div>';
    }
}
