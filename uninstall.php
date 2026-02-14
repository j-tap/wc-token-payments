<?php
/**
 * Fired when the plugin is deleted (Plugins → Delete), not on Deactivate.
 *
 * По умолчанию ничего не удаляем: балансы и история остаются в БД.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Раскомментировать для полного удаления данных при удалении плагина.
// Ключи опций должны совпадать с WCTK_OPT_* в wc-token-payments.php (при удалении плагин не загружается).
/*
global $wpdb;
delete_option('wctk_rate');
delete_option('wctk_topup_product_id');
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wctk_ledger");
$wpdb->query("DELETE FROM {$wpdb->usermeta} WHERE meta_key = 'wctk_token_balance'");
*/
