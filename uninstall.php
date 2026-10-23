<?php
/**
 * حذف افزونه — فقط در صورتی داده‌ها پاک می‌شوند که کاربر در تنظیمات
 * گزینهٔ «حذف داده‌ها هنگام حذف افزونه» را فعال کرده باشد (پیش‌فرض: خاموش — داده‌ها امن می‌مانند).
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$settings = get_option('wsm_settings', array());
$settings = is_array($settings) ? $settings : array();

if (empty($settings['delete_data_on_uninstall'])) {
    return;
}

global $wpdb;

$tables = array(
    $wpdb->prefix . 'wsm_devices',
    $wpdb->prefix . 'wsm_activity_log',
    $wpdb->prefix . 'wsm_access_keys',
    $wpdb->prefix . 'wsm_migration_log',
    $wpdb->prefix . 'wsm_sync_logs',
    $wpdb->prefix . 'wsm_sync_member_map',
    $wpdb->prefix . 'wsm_auth_attempts',
    $wpdb->prefix . 'wsm_blocked_ips',
    $wpdb->prefix . 'wsm_used_nonces',
    $wpdb->prefix . 'wsm_security_log',
    $wpdb->prefix . 'wsm_customer_ledger',
    $wpdb->prefix . 'wsm_store_customers',
    $wpdb->prefix . 'wsm_club_members',
    $wpdb->prefix . 'wsm_club_plans',
    $wpdb->prefix . 'wsm_club_memberships',
    $wpdb->prefix . 'wsm_club_payments',
    $wpdb->prefix . 'wsm_club_attendance',
    $wpdb->prefix . 'wsm_club_staff',
    $wpdb->prefix . 'wsm_club_payroll',
);

foreach ($tables as $table) {
    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$table}`");
}

$options = array(
    'wsm_settings',
    'wsm_sms',
    'wsm_last_mail_error',
    'wsm_db_version',
    'wsm_access_key_hash',
    'wsm_access_key_prefix',
    'wsm_access_key_expires',
    'wsm_access_key_algo',
    'wsm_access_key_user',
    'wsm_last_rotation',
    'wsm_version',
    'wsm_upgrade_failed_to',
    'wsm_last_migration',
    'wsm_expiry_alerts_last_run',
);

foreach ($options as $option) {
    delete_option($option);
    if (is_multisite()) {
        delete_site_option($option);
    }
}

// اطلاعات TOTP اختصاصی افزونه نیز فقط با فعال‌بودن گزینهٔ حذف داده‌ها پاک می‌شود.
$wpdb->delete($wpdb->usermeta, array('meta_key' => 'wsm_totp_secret'), array('%s'));
$wpdb->delete($wpdb->usermeta, array('meta_key' => 'wsm_totp_counter'), array('%s'));

wp_clear_scheduled_hook('wsm_daily_cleanup');
wp_clear_scheduled_hook('wsm_club_daily_tasks');

// فاکتورها و سفارش‌ها در ووکامرس باقی می‌مانند؛ متای _wsm_invoice را حذف نکنید،
// چون حذف نشانگر باعث می‌شود فاکتورهای قدیمی به‌اشتباه سفارش عادی تلقی شوند.
// فقط متای تاریخ انقضای اختصاصی محصول پاک می‌شود.
$wpdb->delete($wpdb->postmeta, array('meta_key' => '_wsm_expiry_date'), array('%s'));
$wpdb->delete($wpdb->postmeta, array('meta_key' => '_wsm_expiry_external_disabled'), array('%s'));
$wpdb->delete($wpdb->postmeta, array('meta_key' => '_wsm_sales_channel'), array('%s'));
delete_transient('wsm_expiry_meta_key_detected');

// پاک‌سازی transientهای افزونه (از جمله نشست تأیید TOTP و کلیدهای موقت) فقط با رضایت صریح.
$transient_prefixes = array('_transient_wsm_%', '_transient_timeout_wsm_%');
$transient_where = array();
$transient_args = array();
foreach ($transient_prefixes as $prefix) {
    $transient_where[] = 'option_name LIKE %s';
    $transient_args[] = $wpdb->esc_like(substr($prefix, 0, -1)) . '%';
}
$wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->options . ' WHERE ' . implode(' OR ', $transient_where), $transient_args));


