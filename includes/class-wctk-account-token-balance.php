<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Account_Token_Balance {

    const ENDPOINT = 'token-balance';

    public static function init(): void {
        add_action('init', [__CLASS__, 'add_endpoint']);
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'menu_items']);
        add_action('woocommerce_account_' . self::ENDPOINT . '_endpoint', [__CLASS__, 'render']);
    }

    public static function add_endpoint(): void {
        add_rewrite_endpoint(self::ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public static function menu_items(array $items): array {
        $items[self::ENDPOINT] = __('Token Balance', WCTK_TEXT_DOMAIN);
        return $items;
    }

    public static function render(): void {
        $user_id = get_current_user_id();
        echo WCTK_Shortcode_Balance::render_balance($user_id, 'h3');

        $rows = WCTK_Ledger::get_by_user($user_id, 50, 0);
        if (!$rows) {
            echo '<p>' . esc_html__('No operations yet.', WCTK_TEXT_DOMAIN) . '</p>';
            return;
        }

        echo '<table class="shop_table"><thead><tr>';
        echo '<th>' . esc_html__('Date', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Kind', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Tokens', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Order', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Note', WCTK_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            echo '<tr>';
            echo '<td>' . esc_html($r['created_at']) . '</td>';
            echo '<td>' . esc_html($r['kind']) . '</td>';
            echo '<td>' . esc_html((string) $r['tokens']) . '</td>';
            echo '<td>' . esc_html($r['order_id'] ? '#' . $r['order_id'] : '-') . '</td>';
            echo '<td>' . esc_html($r['note'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
