<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Balance {
    const META_KEY = 'wctk_token_balance';

    public static function get(int $user_id): int {
        $v = get_user_meta($user_id, self::META_KEY, true);
        if ($v === '' || $v === null) return 0;
        return (int) $v;
    }

    public static function set(int $user_id, int $balance): void {
        if ($balance < 0) $balance = 0; // защита от отрицательных значений
        update_user_meta($user_id, self::META_KEY, (string)$balance);
    }

    /**
     * Атомарность в WP условная. На MVP — делаем "best effort":
     * - всегда валидируем вход
     * - записываем в ledger
     * - обновляем баланс
     */
    public static function change(int $user_id, int $delta, string $kind, ?int $order_id = null, string $note = '', array $meta = []): int {
        $current = self::get($user_id);
        $new = $current + $delta;
        if ($new < 0) {
            throw new RuntimeException('Insufficient token balance');
        }

        // Пишем историю (до изменения — можно также сохранять old/new в meta)
        $ledger_id = WCTK_Ledger::add($user_id, $kind, $delta, $order_id, $note, array_merge($meta, [
            'old_balance' => $current,
            'new_balance' => $new,
        ]));

        self::set($user_id, $new);
        return $ledger_id;
    }
}
