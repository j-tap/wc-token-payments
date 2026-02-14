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

        echo '<div class="wctk-account">';
        echo WCTK_Shortcode_Balance::render_balance($user_id, 'h3');

        $rows = WCTK_Ledger::get_by_user($user_id, 50, 0);
        if (!$rows) {
            echo '<p class="wctk-account__empty">' . esc_html__('No operations yet.', WCTK_TEXT_DOMAIN) . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="wctk-ledger shop_table"><thead><tr>';
        echo '<th class="wctk-ledger__col wctk-ledger__col--date">' . esc_html__('Date', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th class="wctk-ledger__col wctk-ledger__col--kind">' . esc_html__('Kind', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th class="wctk-ledger__col wctk-ledger__col--tokens">' . esc_html__('Tokens', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th class="wctk-ledger__col wctk-ledger__col--order">' . esc_html__('Order', WCTK_TEXT_DOMAIN) . '</th>';
        echo '<th class="wctk-ledger__col wctk-ledger__col--note">' . esc_html__('Note', WCTK_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($rows as $r) {
            $delta_class = ((int) $r['tokens']) >= 0 ? 'wctk-ledger__row--positive' : 'wctk-ledger__row--negative';
            echo '<tr class="wctk-ledger__row ' . $delta_class . '">';
            echo '<td class="wctk-ledger__cell wctk-ledger__cell--date">' . esc_html($r['created_at']) . '</td>';
            echo '<td class="wctk-ledger__cell wctk-ledger__cell--kind">' . esc_html($r['kind']) . '</td>';
            echo '<td class="wctk-ledger__cell wctk-ledger__cell--tokens">' . esc_html((string) $r['tokens']) . '</td>';
            echo '<td class="wctk-ledger__cell wctk-ledger__cell--order">' . esc_html($r['order_id'] ? '#' . $r['order_id'] : '-') . '</td>';
            echo '<td class="wctk-ledger__cell wctk-ledger__cell--note">' . esc_html($r['note'] ?? '') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        echo '</div>';
    }
}
