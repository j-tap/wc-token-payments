<?php
if (!defined('ABSPATH')) exit;

final class WCTK_Ledger {

    public const KIND_TOPUP = 'topup';
    public const KIND_SPEND = 'spend';
    public const KIND_ADMIN_ADJUST = 'admin_adjust';

    private const ALLOWED_KINDS = [
        self::KIND_TOPUP,
        self::KIND_SPEND,
        self::KIND_ADMIN_ADJUST,
    ];

    public static function table_name(): string {
        global $wpdb;
        return $wpdb->prefix . 'wctk_ledger';
    }

    public static function create_table(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(32) NOT NULL,            -- topup, spend, admin_adjust
            tokens BIGINT NOT NULL,               -- +100 или -30
            order_id BIGINT UNSIGNED NULL,        -- привязка к заказу (если есть)
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            meta LONGTEXT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY order_id (order_id),
            KEY kind (kind)
        ) {$charset};";

        dbDelta($sql);
    }

    public static function add(int $user_id, string $kind, int $tokens, ?int $order_id = null, string $note = '', array $meta = []): int {
        if (!in_array($kind, self::ALLOWED_KINDS, true)) {
            throw new InvalidArgumentException('Invalid ledger kind');
        }

        global $wpdb;
        $table = self::table_name();

        $wpdb->insert(
            $table,
            [
                'user_id' => $user_id,
                'kind' => $kind,
                'tokens' => $tokens,
                'order_id' => $order_id,
                'note' => $note,
                'meta' => !empty($meta) ? wp_json_encode($meta) : null,
                'created_at' => current_time('mysql'),
            ],
            ['%d','%s','%d','%d','%s','%s','%s']
        );

        return (int) $wpdb->insert_id;
    }

    public static function get_by_user(int $user_id, int $limit = 50, int $offset = 0): array {
        global $wpdb;
        $table = self::table_name();

        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d",
            $user_id,
            $limit,
            $offset
        );
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }
}
