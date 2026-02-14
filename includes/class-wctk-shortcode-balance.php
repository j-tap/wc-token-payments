<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Shortcode_Balance {

    public static function init(): void {
        add_shortcode('wctk_balance', [__CLASS__, 'shortcode']);
    }

    /**
     * Вывод баланса токенов (для шорткода или вызова из кода).
     *
     * @param int|null $user_id     ID пользователя; null = текущий.
     * @param string   $wrapper_tag Тег обёртки: 'p', 'h3' и т.д.
     * @return string HTML.
     */
    public static function render_balance(?int $user_id = null, string $wrapper_tag = 'p'): string {
        $user_id = $user_id ?? get_current_user_id();
        if ($user_id <= 0) {
            return '<p class="wctk-notice wctk-notice--login">' . esc_html__('You must be logged in to view token balance.', WCTK_TEXT_DOMAIN) . '</p>';
        }

        $balance = WCTK_Balance::get($user_id);
        $label = __('Token balance:', WCTK_TEXT_DOMAIN);
        $tag = in_array($wrapper_tag, ['p', 'h2', 'h3', 'div'], true) ? $wrapper_tag : 'p';

        return '<' . $tag . ' class="wctk-balance">'
            . '<strong class="wctk-balance__label">' . esc_html($label) . '</strong> '
            . '<span class="wctk-balance__value">' . esc_html((string) $balance) . '</span>'
            . '</' . $tag . '>';
    }

    public static function shortcode(): string {
        return self::render_balance(null);
    }
}
