<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * آداپتر پیامک ملی‌پیامک برای فیلتر wsm_send_sms.
 *
 * حالت دستی در گزینهٔ wsm_sms ذخیره می‌شود و رمز افزونه فقط به‌صورت رمزنگاری‌شده (AES-256-GCM) نگه داشته می‌شود.
 * حالت خودکار، گزینه‌های SMS‌دار قالب فعال را می‌خواند و در نبود دادهٔ کامل به تنظیم دستی برمی‌گردد.
 *
 * مستندات رسمی ملی‌پیامک:
 *  - ارسال ساده: POST فرم (form-urlencoded) به https://rest.payamak-panel.com/api/SendSMS/SendSMS
 *  - متن پیامک باید با عبارت «لغو11» تمام شود (الزام سازمان تنظیم مقررات).
 *  - نتیجهٔ ۱ یعنی موفق؛ کدهای ۰ تا ۱۸ و -108 تا -110 خطا هستند؛ عدد بزرگ (شناسهٔ پیامک) هم موفقیت است.
 */
class WSM_SMS {
    const OPTION   = 'wsm_sms';
    const ENDPOINT = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
    const SUFFIX   = 'لغو11';

    private static $theme_discovery_cache = null;

    public static function boot() {
        add_filter('wsm_send_sms', array(__CLASS__, 'send'), 10, 2);
        add_action('woocommerce_checkout_order_processed', array(__CLASS__, 'notify_new_order'), 10, 3);
        add_action('rest_api_init', array(__CLASS__, 'register_app_settings_routes'));
    }

    /** هشدار سفارش جدید به مدیر فروشگاه (فقط پس از ثبت سفارش در checkout). */
    public static function notify_new_order($order_id, $posted_data = array(), $order = null) {
        $settings = WSM_Util::settings();
        if (empty($settings['notify_new_order'])) {
            return;
        }
        if (!$order || !is_a($order, 'WC_Order')) {
            $order = wc_get_order((int) $order_id);
        }
        if (!$order || $order->get_meta('_wsm_invoice')) {
            return;
        }
        $message = 'سفارش جدید #' . $order->get_order_number() . ' ثبت شد؛ مبلغ ' . $order->get_total() . ' ' . get_woocommerce_currency_symbol();
        WSM_Security::notify_sms($message);
    }

    /** Raw, encrypted plugin settings. Theme credentials are resolved only for sending. */
    public static function config() {
        $cfg = get_option(self::OPTION, array());
        return is_array($cfg) ? $cfg : array();
    }

    /** Default to theme auto mode so existing manual credentials remain a fallback. */
    private static function source_mode($config = null) {
        if (!is_array($config)) { $config = self::config(); }
        return isset($config['mode']) && $config['mode'] === 'manual' ? 'manual' : 'auto';
    }

    private static function password_value($config) {
        if (!is_array($config)) { return ''; }
        if (array_key_exists('_password_plain', $config) && is_scalar($config['_password_plain'])) {
            return (string) $config['_password_plain'];
        }
        if (!empty($config['password_enc']) && class_exists('WSM_Crypto')) {
            $password = WSM_Crypto::decrypt((string) $config['password_enc']);
            return is_string($password) ? $password : '';
        }
        return '';
    }

    /** Username, password and sender are required for the MeliPayamak API. */
    private static function credentials_ready($config) {
        if (!is_array($config)) { return false; }
        $username = isset($config['username']) && is_scalar($config['username']) ? trim((string) $config['username']) : '';
        $from = isset($config['from']) && is_scalar($config['from']) ? trim((string) $config['from']) : '';
        return $username !== '' && $from !== '' && self::password_value($config) !== '';
    }

    /** Resolve effective SMS credentials without ever returning them from a public API response. */
    private static function resolve_config($stored = null, $theme = null, $log_fallback = true) {
        if (!is_array($stored)) { $stored = self::config(); }
        $mode = self::source_mode($stored);
        if ($mode === 'manual') {
            $stored['_effective_source'] = 'manual';
            return $stored;
        }
        if (!is_array($theme)) { $theme = self::discover_theme_sms_config(); }
        if (!empty($theme['complete']) && !empty($theme['credentials']) && is_array($theme['credentials'])) {
            $effective = $stored;
            $effective['username'] = (string) $theme['credentials']['username'];
            $effective['from'] = (string) $theme['credentials']['from'];
            $effective['_password_plain'] = (string) $theme['credentials']['password'];
            if (!empty($theme['credentials']['admin_mobile'])) {
                $effective['admin_mobile'] = (string) $theme['credentials']['admin_mobile'];
            }
            $effective['_effective_source'] = 'theme';
            return $effective;
        }
        $manual_ready = self::credentials_ready($stored);
        if ($log_fallback) { self::log_theme_fallback($theme, $manual_ready); }
        $stored['_effective_source'] = $manual_ready ? 'manual_fallback' : 'none';
        return $stored;
    }

    public static function effective_config() {
        return self::resolve_config();
    }

    /** آیا هشدار پیامکی روشن است؟ */
    public static function is_enabled() {
        $c = self::config();
        return !empty($c['enabled']);
    }

    /** Credentials are considered configured even when the optional manager destination is blank. */
    public static function is_configured() {
        return self::credentials_ready(self::effective_config());
    }

    /** آیا هشدار هم روشن است و هم پیکربندی کامل؟ */
    public static function is_active() {
        return self::is_enabled() && self::is_configured();
    }

    /** Register a small, permission-checked settings API for the app SMS tab. */
    public static function register_app_settings_routes() {
        if (!function_exists('register_rest_route')) { return; }
        $namespace = class_exists('WSM_Rest_API') ? WSM_Rest_API::NS : 'wsm/v1';
        register_rest_route($namespace, '/sms/config', array(
            array(
                'methods' => 'GET',
                'callback' => array(__CLASS__, 'app_config_get'),
                'permission_callback' => array(__CLASS__, 'app_config_permission'),
            ),
            array(
                'methods' => 'PUT',
                'callback' => array(__CLASS__, 'app_config_update'),
                'permission_callback' => array(__CLASS__, 'app_config_permission'),
            ),
        ));
    }

    /** Bearer + HMAC authentication and settings permission for the custom SMS route. */
    public static function app_config_permission($request) {
        if (!class_exists('WSM_Util') || !WSM_Util::is_woo_active()) {
            return class_exists('WSM_Util') ? WSM_Util::woo_required_error() : new WP_Error('wsm_unavailable', 'افزونه آماده نیست.', array('status' => 503));
        }
        if (class_exists('WSM_Security') && WSM_Security::blocked_until()) {
            return new WP_Error('wsm_ip_blocked', 'IP شما موقتاً مسدود است. بعد از پایان مدت مسدودی دوباره تلاش کنید.', array('status' => 429));
        }
        $token = WSM_Permissions::extract_token($request);
        if ($token === '') { return new WP_Error('wsm_no_token', 'توکن ارسال نشده است.', array('status' => 401)); }
        $device = WSM_Auth::validate_bearer($token);
        if (is_wp_error($device)) { return $device; }
        $signed = WSM_Security::verify_signature($request, $device);
        if (is_wp_error($signed)) { return $signed; }
        $user = get_userdata((int) $device->user_id);
        if (!$user) { return new WP_Error('wsm_forbidden', 'دسترسی به این بخش ندارید.', array('status' => 403)); }
        if (get_user_meta((int) $device->user_id, 'wsm_app_user_status', true) === 'inactive') {
            return new WP_Error('wsm_app_user_inactive', 'حساب کاربری این اپ غیرفعال شده است.', array('status' => 403));
        }
        $permission_record = WSM_Permissions::user_permissions_record((int) $device->user_id);
        $app_role = (string) get_user_meta((int) $device->user_id, 'wsm_app_role', true);
        $roles = is_array($user->roles) ? $user->roles : array();
        $wp_admin = WSM_Permissions::is_wordpress_admin_user($user);
        $legacy_manager = $wp_admin || in_array('administrator', $roles, true) || in_array('shop_manager', $roles, true) ||
            (!empty($user->allcaps['manage_woocommerce']) || !empty($user->allcaps['manage_options']));
        $app_account = get_user_meta((int) $device->user_id, 'wsm_app_user_status', true) === 'active' || $app_role !== '' || $permission_record !== null;
        if (!$legacy_manager && !$app_account) { return new WP_Error('wsm_forbidden', 'دسترسی به این بخش ندارید.', array('status' => 403)); }
        WSM_Permissions::set_device($device);
        $method = strtoupper((string) $request->get_method());
        $can_view = WSM_Devices::device_can($device, 'settings.view') || WSM_Devices::device_can($device, 'settings.edit');
        $allowed = $method === 'GET' ? $can_view : WSM_Devices::device_can($device, 'settings.edit');
        if (!$wp_admin && !$allowed) {
            $needed = $method === 'GET' ? array('settings.view', 'settings.edit') : 'settings.edit';
            return new WP_Error('wsm_device_permission_denied', 'برای تنظیم سرویس پیامک، مجوز ویرایش تنظیمات عمومی لازم است.', array('status' => 403, 'permission' => $needed));
        }
        return true;
    }

    /** Return safe status and field paths; never return a password value. */
    public static function app_config_get($request) {
        $stored = self::config();
        $mode = self::source_mode($stored);
        $theme = self::discover_theme_sms_config();
        $effective = self::resolve_config($stored, $theme, true);
        $device = WSM_Permissions::current_device();
        $user = $device ? get_userdata((int) $device->user_id) : false;
        $can_edit = $user && (WSM_Permissions::is_wordpress_admin_user($user) || WSM_Devices::device_can($device, 'settings.edit'));
        $manual = array(
            'username' => $can_edit && isset($stored['username']) && is_scalar($stored['username']) ? (string) $stored['username'] : '',
            'from' => $can_edit && isset($stored['from']) && is_scalar($stored['from']) ? (string) $stored['from'] : '',
            'admin_mobile' => $can_edit && isset($stored['admin_mobile']) && is_scalar($stored['admin_mobile']) ? (string) $stored['admin_mobile'] : '',
            'password_set' => self::password_value($stored) !== '',
            'configured' => self::credentials_ready($stored),
        );
        return array(
            'mode' => $mode,
            'enabled' => !empty($stored['enabled']),
            'manual' => $manual,
            'theme' => self::public_theme_status($theme),
            'effective_source' => isset($effective['_effective_source']) ? $effective['_effective_source'] : 'none',
            'fallback_used' => isset($effective['_effective_source']) && $effective['_effective_source'] === 'manual_fallback',
            'configured' => self::credentials_ready($effective),
            'active' => !empty($stored['enabled']) && self::credentials_ready($effective),
        );
    }

    /** Save only allow-listed app settings; password is encrypted before persistence. */
    public static function app_config_update($request) {
        $body = method_exists($request, 'get_json_params') ? $request->get_json_params() : array();
        if (!is_array($body)) { $body = method_exists($request, 'get_body_params') ? $request->get_body_params() : array(); }
        if (!is_array($body)) { return new WP_Error('wsm_sms_bad_config', 'ساختار تنظیمات پیامک نامعتبر است.', array('status' => 400)); }
        $stored = self::config();
        $mode = isset($body['mode']) && is_scalar($body['mode']) ? sanitize_key((string) $body['mode']) : self::source_mode($stored);
        if (!in_array($mode, array('auto', 'manual'), true)) { return new WP_Error('wsm_sms_bad_mode', 'حالت پیامک باید خودکار یا دستی باشد.', array('status' => 400)); }
        $stored['mode'] = $mode;
        if ($mode === 'manual') {
            foreach (array('username', 'from', 'admin_mobile') as $key) {
                if (!array_key_exists($key, $body)) { continue; }
                if (!is_scalar($body[$key])) { return new WP_Error('wsm_sms_bad_field', 'یکی از فیلدهای تنظیمات دستی پیامک نامعتبر است.', array('status' => 400)); }
                $value = sanitize_text_field((string) $body[$key]);
                if ($key === 'username' && strlen($value) > 191) { return new WP_Error('wsm_sms_bad_username', 'نام کاربری پیامک بیش از حد طولانی است.', array('status' => 400)); }
                if ($key === 'from' && strlen($value) > 100) { return new WP_Error('wsm_sms_bad_sender', 'شمارهٔ ارسال‌کننده بیش از حد طولانی است.', array('status' => 400)); }
                if ($key === 'admin_mobile') {
                    $value = preg_replace('/[^0-9+]/', '', $value);
                    if ($value !== '' && (strlen($value) < 7 || strlen($value) > 20)) { return new WP_Error('wsm_sms_bad_mobile', 'شمارهٔ مدیر معتبر نیست.', array('status' => 400)); }
                }
                $stored[$key] = $value;
            }
            if (array_key_exists('password', $body)) {
                if (!is_scalar($body['password'])) { return new WP_Error('wsm_sms_bad_password', 'رمز ملی‌پیامک نامعتبر است.', array('status' => 400)); }
                $password = (string) $body['password'];
                if (strlen($password) > 512) { return new WP_Error('wsm_sms_bad_password', 'رمز ملی‌پیامک بیش از حد طولانی است.', array('status' => 400)); }
                if (trim($password) !== '') {
                    $encrypted = WSM_Crypto::encrypt($password);
                    if (!is_string($encrypted) || $encrypted === '') { return new WP_Error('wsm_sms_crypto_unavailable', 'رمزنگاری امن رمز پیامک در دسترس نیست.', array('status' => 500)); }
                    $stored['password_enc'] = $encrypted;
                }
            }
            if (!self::credentials_ready($stored)) {
                return new WP_Error('wsm_sms_manual_incomplete', 'برای حالت دستی، نام کاربری، رمز و شمارهٔ ارسال‌کننده را وارد کنید. برای حفظ رمز ذخیره‌شده، فیلد رمز را خالی بگذارید.', array('status' => 400));
            }
        }
        update_option(self::OPTION, $stored, false);
        $saved = get_option(self::OPTION, array());
        if (!is_array($saved) || self::source_mode($saved) !== $mode) {
            return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ تنظیمات پیامک انجام نشد.', array('status' => 500));
        }
        if ($mode === 'manual') {
            foreach (array('username', 'from', 'admin_mobile') as $key) {
                if (array_key_exists($key, $stored) && (!array_key_exists($key, $saved) || (string) $saved[$key] !== (string) $stored[$key])) {
                    return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ یکی از فیلدهای دستی پیامک تأیید نشد.', array('status' => 500));
                }
            }
            if (isset($body['password']) && is_scalar($body['password']) && trim((string) $body['password']) !== '' && self::password_value($saved) !== (string) $body['password']) {
                return new WP_Error('wsm_sms_save_failed', 'ذخیرهٔ رمز ملی‌پیامک تأیید نشد.', array('status' => 500));
            }
        }
        return self::app_config_get($request);
    }

    private static function public_theme_status($theme) {
        $keys = isset($theme['keys']) && is_array($theme['keys']) ? $theme['keys'] : array();
        $candidates = array();
        foreach ((array) (isset($theme['candidates']) ? $theme['candidates'] : array()) as $candidate) {
            if (!is_array($candidate)) { continue; }
            $candidates[] = array(
                'option_name' => isset($candidate['option_name']) ? (string) $candidate['option_name'] : '',
                'keys' => isset($candidate['keys']) && is_array($candidate['keys']) ? $candidate['keys'] : array(),
                'complete' => !empty($candidate['complete']),
            );
        }
        $message = !empty($theme['ambiguous']) ? 'چند گزینهٔ قالب به‌طور هم‌زمان حاوی اطلاعات پیامک هستند؛ برای جلوگیری از انتخاب اشتباه، هیچ‌کدام خودکار استفاده نمی‌شود.' : (!empty($theme['complete']) ? 'اطلاعات پیامک در تنظیمات قالب پیدا شد.' : (!empty($theme['option_name']) ? 'گزینهٔ قالب پیدا شد، اما زیرکلیدهای نام کاربری، رمز و فرستنده کامل نیست.' : 'گزینهٔ تنظیمات پیامک قالب پیدا نشد.'));
        return array(
            'found' => !empty($theme['complete']),
            'complete' => !empty($theme['complete']),
            'ambiguous' => !empty($theme['ambiguous']),
            'option_name' => isset($theme['option_name']) ? (string) $theme['option_name'] : '',
            'keys' => $keys,
            'fields' => array_keys($keys),
            'candidates' => $candidates,
            'message' => $message,
        );
    }

    /** Discover theme settings by active-theme option names and SMS-scoped nested keys. */
    private static function discover_theme_sms_config() {
        if (is_array(self::$theme_discovery_cache)) { return self::$theme_discovery_cache; }
        $theme_slugs = array();
        $raw_slugs = array(get_option('stylesheet', ''), get_option('template', ''));
        if (function_exists('wp_get_theme')) {
            $theme_object = wp_get_theme();
            if ($theme_object) {
                $raw_slugs[] = $theme_object->get_stylesheet();
                $raw_slugs[] = $theme_object->get_template();
                $raw_slugs[] = $theme_object->get('Name');
            }
        }
        foreach ($raw_slugs as $slug) {
            if (!is_scalar($slug)) { continue; }
            $slug = sanitize_title((string) $slug);
            if ($slug !== '') { $theme_slugs[$slug] = true; }
            foreach (preg_split('/[-_]+/', $slug) as $part) {
                if (strlen($part) >= 5) { $theme_slugs[$part] = true; }
            }
        }
        $generic_names = array('theme_options', 'my_theme_options', 'theme-options', 'theme_settings', 'theme_options_data', 'theme_option');
        $option_names = array();
        foreach ($generic_names as $name) { $option_names[$name] = true; }
        foreach (array_keys($theme_slugs) as $slug) {
            $base = str_replace('-', '_', $slug);
            foreach (array($base . '_options', $base . '_theme_options', $base . '_settings', $base . 'options') as $name) { $option_names[$name] = true; }
        }
        $all = function_exists('wp_load_alloptions') ? wp_load_alloptions() : array();
        if (is_array($all)) {
            foreach ($all as $name => $unused) {
                if (self::is_theme_option_candidate_name((string) $name, $theme_slugs, $generic_names)) { $option_names[(string) $name] = true; }
            }
        }
        global $wpdb;
        if (isset($wpdb) && isset($wpdb->options) && method_exists($wpdb, 'get_col') && method_exists($wpdb, 'prepare') && method_exists($wpdb, 'esc_like')) {
            $patterns = array('%theme%');
            foreach (array_keys($theme_slugs) as $slug) { if (strlen($slug) >= 5) { $patterns[] = '%' . $wpdb->esc_like($slug) . '%'; } }
            foreach (array_unique($patterns) as $pattern) {
                $sql = $wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC LIMIT 250", $pattern);
                $found_names = $wpdb->get_col($sql);
                foreach ((array) $found_names as $name) {
                    if (self::is_theme_option_candidate_name((string) $name, $theme_slugs, $generic_names)) { $option_names[(string) $name] = true; }
                }
            }
        }
        $option_names = array_slice(array_keys($option_names), 0, 500);
        $candidates = array();
        foreach ($option_names as $option_name) {
            if ($option_name === self::OPTION || strpos($option_name, 'theme_mods_') === 0 || strpos($option_name, '_transient_') === 0) { continue; }
            $value = get_option($option_name, null);
            if (is_string($value)) {
                $trimmed = ltrim($value);
                if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
                    $decoded = json_decode($value, true);
                    if (is_array($decoded)) { $value = $decoded; }
                }
            }
            if (!is_array($value) && !is_object($value)) { continue; }
            $groups = array();
            self::walk_theme_sms_fields($value, $option_name, array(), '', self::is_sms_context_key($option_name), $groups, 0);
            foreach ($groups as $scope => $fields) {
                if (!is_array($fields) || !$fields) { continue; }
                $keys = array();
                $credentials = array();
                foreach ($fields as $field => $entry) {
                    if (!is_array($entry) || !isset($entry['path'])) { continue; }
                    $keys[$field] = (string) $entry['path'];
                    if (isset($entry['value'])) { $credentials[$field] = (string) $entry['value']; }
                }
                $complete = !empty($credentials['username']) && !empty($credentials['password']) && !empty($credentials['from']);
                $score = self::theme_option_name_score($option_name, $theme_slugs) + (count($credentials) * 10) + ($complete ? 60 : 0);
                $scope_key = self::normalized_option_key($scope);
                if (strpos($scope_key, 'melipayamak') !== false || strpos($scope_key, 'payamak') !== false) { $score += 35; }
                elseif (strpos($scope_key, 'sms') !== false) { $score += 20; }
                $candidates[] = array(
                    'option_name' => $option_name,
                    'scope' => $scope,
                    'keys' => $keys,
                    'credentials' => $credentials,
                    'complete' => $complete,
                    'score' => $score,
                );
            }
        }
        if (!$candidates) {
            self::$theme_discovery_cache = array('complete' => false, 'ambiguous' => false, 'option_name' => '', 'keys' => array(), 'credentials' => array(), 'candidates' => array());
            return self::$theme_discovery_cache;
        }
        usort($candidates, array(__CLASS__, 'compare_theme_candidates'));
        $complete_candidates = array_values(array_filter($candidates, function ($candidate) { return !empty($candidate['complete']); }));
        $pool = $complete_candidates ? $complete_candidates : $candidates;
        $top = $pool[0];
        $second = isset($pool[1]) ? $pool[1] : null;
        $ambiguous = $second && (int) $second['score'] === (int) $top['score'];
        if ($ambiguous) {
            $safe_candidates = array();
            foreach (array_slice($pool, 0, 5) as $candidate) {
                if ((int) $candidate['score'] !== (int) $top['score']) { break; }
                $safe_candidates[] = array('option_name' => $candidate['option_name'], 'keys' => $candidate['keys'], 'complete' => !empty($candidate['complete']));
            }
            self::$theme_discovery_cache = array('complete' => false, 'ambiguous' => true, 'option_name' => '', 'keys' => array(), 'credentials' => array(), 'candidates' => $safe_candidates);
            return self::$theme_discovery_cache;
        }
        self::$theme_discovery_cache = array(
            'complete' => !empty($top['complete']),
            'ambiguous' => false,
            'option_name' => (string) $top['option_name'],
            'keys' => isset($top['keys']) ? $top['keys'] : array(),
            'credentials' => isset($top['credentials']) ? $top['credentials'] : array(),
            'candidates' => array(array('option_name' => (string) $top['option_name'], 'keys' => isset($top['keys']) ? $top['keys'] : array(), 'complete' => !empty($top['complete']))),
        );
        return self::$theme_discovery_cache;
    }

    private static function is_theme_option_candidate_name($name, $theme_slugs, $generic_names) {
        $name = strtolower(trim((string) $name));
        if ($name === '' || strpos($name, '_transient_') === 0 || strpos($name, 'theme_mods_') === 0) { return false; }
        if (in_array($name, $generic_names, true)) { return true; }
        if (strpos($name, 'theme') !== false) { return true; }
        $normalized = self::normalized_option_key($name);
        foreach ((array) $theme_slugs as $slug => $enabled) {
            $slug = self::normalized_option_key($slug);
            if (strlen($slug) >= 5 && strpos($normalized, $slug) !== false) { return true; }
        }
        return false;
    }

    private static function theme_option_name_score($name, $theme_slugs) {
        $name = strtolower((string) $name);
        $normalized = self::normalized_option_key($name);
        $score = 0;
        foreach ((array) $theme_slugs as $slug => $enabled) {
            $slug = self::normalized_option_key($slug);
            if (strlen($slug) >= 5 && strpos($normalized, $slug) !== false) { $score = max($score, 120); }
        }
        if (in_array($name, array('theme_options', 'my_theme_options', 'theme-options', 'theme_settings', 'theme_options_data', 'theme_option'), true)) { $score = max($score, 85); }
        elseif (strpos($name, 'theme') !== false) { $score = max($score, 55); }
        if (strpos($normalized, 'sms') !== false) { $score += 5; }
        return $score;
    }

    public static function compare_theme_candidates($a, $b) {
        $score_a = isset($a['score']) ? (int) $a['score'] : 0;
        $score_b = isset($b['score']) ? (int) $b['score'] : 0;
        if ($score_a === $score_b) { return strcmp((string) $a['option_name'] . ':' . (string) $a['scope'], (string) $b['option_name'] . ':' . (string) $b['scope']); }
        return $score_a > $score_b ? -1 : 1;
    }

    private static function normalized_option_key($key) {
        $key = strtolower((string) $key);
        if (function_exists('remove_accents')) { $key = remove_accents($key); }
        $normalized = preg_replace('/[^a-z0-9]+/', '', $key);
        return is_string($normalized) ? $normalized : '';
    }

    private static function is_sms_context_key($key) {
        $key = self::normalized_option_key($key);
        return strpos($key, 'sms') !== false || strpos($key, 'melipayamak') !== false || strpos($key, 'mellipayamak') !== false || strpos($key, 'payamak') !== false || strpos($key, 'meli') === 0 || strpos($key, 'melli') === 0 || (strpos($key, 'national') === 0 && self::theme_field_for_key($key) !== '');
    }

    private static function theme_field_for_key($key) {
        $normalized = self::normalized_option_key($key);
        $stripped = $normalized;
        foreach (array('melipayamak', 'mellipayamak', 'national', 'payamak', 'melli', 'meli', 'sms') as $prefix) {
            if (strpos($stripped, $prefix) === 0) { $stripped = substr($stripped, strlen($prefix)); }
        }
        if (in_array($stripped, array('username', 'user', 'login', 'apiusername', 'apiuser', 'accountusername'), true)) { return 'username'; }
        if (in_array($stripped, array('password', 'pass', 'passwd', 'pwd', 'apipassword', 'apipass'), true)) { return 'password'; }
        if (in_array($stripped, array('from', 'sender', 'sendernumber', 'fromnumber', 'line', 'linenumber', 'smsline', 'origin'), true)) { return 'from'; }
        if (in_array($stripped, array('adminmobile', 'adminphone', 'managerphone', 'managermobile'), true)) { return 'admin_mobile'; }
        return '';
    }

    private static function walk_theme_sms_fields($value, $option_name, $path, $scope, $context, &$groups, $depth) {
        if ($depth > 10) { return; }
        if (is_object($value)) { $value = get_object_vars($value); }
        if (!is_array($value)) { return; }
        foreach ($value as $key => $child) {
            if (!is_string($key) && !is_int($key)) { continue; }
            $key = (string) $key;
            $next_path = $path;
            $next_path[] = $key;
            $key_context = self::is_sms_context_key($key);
            if (is_array($child) || is_object($child)) {
                $next_scope = $key_context ? implode('.', $next_path) : $scope;
                self::walk_theme_sms_fields($child, $option_name, $next_path, $next_scope, $context || $key_context, $groups, $depth + 1);
                continue;
            }
            $field = self::theme_field_for_key($key);
            if ($field === '' || !($context || $key_context) || !is_scalar($child)) { continue; }
            $value_string = (string) $child;
            if (trim($value_string) === '') { continue; }
            $group = $scope !== '' ? $scope : 'root';
            if (!isset($groups[$group])) { $groups[$group] = array(); }
            $priority = strlen(self::normalized_option_key($key));
            if (!isset($groups[$group][$field]) || $priority > (int) $groups[$group][$field]['priority']) {
                $groups[$group][$field] = array('value' => $value_string, 'path' => implode('.', $next_path), 'priority' => $priority);
            }
        }
    }

    private static function log_theme_fallback($theme, $manual_ready) {
        $transient = 'wsm_sms_theme_config_notice_v1';
        if (get_transient($transient)) { return; }
        $option_name = isset($theme['option_name']) ? sanitize_text_field((string) $theme['option_name']) : '';
        if (!empty($theme['ambiguous'])) {
            $action = 'sms_theme_settings_ambiguous';
            $detail = 'چند گزینهٔ قالب برای پیامک هم‌زمان پیدا شد؛ پیکربندی خودکار به‌دلیل ابهام استفاده نشد.' . ($manual_ready ? ' تنظیمات دستی افزونه به‌عنوان جایگزین استفاده شد.' : ' تنظیمات دستی کامل هم موجود نیست.');
        } elseif ($manual_ready) {
            $action = 'sms_theme_settings_fallback';
            $detail = 'اطلاعات کامل پیامک در تنظیمات قالب پیدا نشد؛ از تنظیمات دستی افزونه به‌عنوان جایگزین استفاده شد.';
        } else {
            $action = 'sms_theme_settings_missing';
            $detail = 'اطلاعات کامل پیامک در تنظیمات قالب یا تنظیمات دستی افزونه موجود نیست؛ ارسال انجام نمی‌شود.';
        }
        if ($option_name !== '') { $detail .= ' Option Name=' . $option_name . '.'; }
        $paths = isset($theme['keys']) && is_array($theme['keys']) ? $theme['keys'] : array();
        if ($paths) {
            $safe_paths = array();
            foreach ($paths as $field => $path) { $safe_paths[] = sanitize_key((string) $field) . '=' . sanitize_text_field((string) $path); }
            $detail .= ' Key paths: ' . implode(', ', $safe_paths) . '.';
        }
        if (class_exists('WSM_Security')) { WSM_Security::log($action, 'warning', 0, $detail); }
        set_transient($transient, 1, 600);
    }

    /** آدرس سرویس؛ فقط برای تست با سرور شبیه‌ساز قابل تغییر است. */
    public static function endpoint() {
        return apply_filters('wsm_sms_endpoint', self::ENDPOINT);
    }

    /**
     * @param bool   $sent    اگر یک افزونهٔ دیگر قبلاً ارسال کرده باشد، همان را برمی‌گردانیم.
     * @param string $message متن پیامک
     * @return bool
     */
    public static function send($sent, $message) {
        if ($sent) {
            return true;
        }
        if (!self::is_active()) {
            return false;
        }
        $result = self::dispatch((string) $message);
        if (!$result['ok']) {
            WSM_Security::log('sms_failed', 'warning', 0, $result['detail']);
        }
        return $result['ok'];
    }

    /**
     * پیام آزمایشی از صفحهٔ تنظیمات (حتی وقتی هشدار خاموش است).
     * @return array { ok: bool, detail: string }
     */
    public static function send_test() {
        $config = self::effective_config();
        if (!self::credentials_ready($config)) {
            return array('ok' => false, 'detail' => 'نام کاربری، رمز و شمارهٔ ارسال‌کنندهٔ ملی‌پیامک کامل نیست.');
        }
        $mobile = isset($config['admin_mobile']) ? (string) $config['admin_mobile'] : '';
        if ($mobile === '') {
            return array('ok' => false, 'detail' => 'شمارهٔ مدیر برای دریافت پیامک آزمایشی ثبت نشده است.');
        }
        $name = get_bloginfo('name');
        $result = self::dispatch_to('پیام آزمایشی هشدار امنیتی ' . ($name !== '' ? $name : 'فروشگاه'), $mobile);
        WSM_Security::log($result['ok'] ? 'sms_test_ok' : 'sms_test_failed', $result['ok'] ? 'info' : 'warning', 0, $result['detail']);
        return $result;
    }

    /**
     * ارسال واقعی یک پیام و تفسیر پاسخ. هیچ‌وقت رمز یا شماره در نتیجه برنمی‌گردد.
     * @return array { ok: bool, detail: string }
     */
    public static function dispatch($text) {
        $c = self::effective_config();
        return self::dispatch_to($text, isset($c['admin_mobile']) ? $c['admin_mobile'] : '');
    }

    /** ارسال به شمارهٔ مشخص؛ برای هشدارهای عضویت فقط پس از رضایت ثبت‌شدهٔ عضو فراخوانی شود. */
    public static function dispatch_to($text, $to) {
        $c = self::effective_config();
        $to = preg_replace('/[^0-9+]/', '', is_scalar($to) ? (string) $to : '');
        if ($to === '' || strlen($to) < 7 || strlen($to) > 20) {
            return array('ok' => false, 'detail' => 'شمارهٔ مقصد معتبر نیست.');
        }
        if (!self::credentials_ready($c)) {
            return array('ok' => false, 'detail' => 'نام کاربری، رمز و شمارهٔ ارسال‌کنندهٔ ملی‌پیامک کامل نیست.');
        }
        $password = self::password_value($c);
        if (!is_string($password) || $password === '') {
            return array('ok' => false, 'detail' => 'رمز ملی‌پیامک قابل خواندن نیست؛ دوباره در حالت دستی ذخیره کنید.');
        }

        $text = trim((string) $text);
        if (substr($text, -strlen(self::SUFFIX)) !== self::SUFFIX) {
            $text = rtrim($text) . ' ' . self::SUFFIX;
        }

        $body = http_build_query(array(
            'username' => (string) $c['username'],
            'password' => $password,
            'to'       => $to,
            'from'     => (string) $c['from'],
            'text'     => $text,
            'isflash'  => 'false',
        ), '', '&');

        $res = wp_remote_post(self::endpoint(), array(
            'timeout' => 15,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
            'body'    => $body,
        ));
        if (is_wp_error($res)) {
            return array('ok' => false, 'detail' => 'خطای اتصال به سرویس پیامک.');
        }

        $http   = (int) wp_remote_retrieve_response_code($res);
        $parsed = self::parse_response((string) wp_remote_retrieve_body($res));
        $ok     = $parsed['ok'] && $http === 200;
        $detail = 'پاسخ سرویس پیامک: ' . $parsed['detail'] . ' (HTTP ' . $http . ')';
        return array('ok' => $ok, 'detail' => $detail);
    }

    /**
     * تفسیر پاسخ ملی‌پیامک.
     * @return array { ok: bool, detail: string }
     */
    public static function parse_response($body) {
        $body = trim((string) $body);
        $data = json_decode($body, true);
        if (is_array($data) && isset($data['RetStatus'])) {
            $status = (int) $data['RetStatus'];
            if ($status === 1) {
                return array('ok' => true, 'detail' => 'ارسال شد');
            }
            return array('ok' => false, 'detail' => 'RetStatus=' . $status);
        }
        if (preg_match('/^-?\d+$/', $body)) {
            $n = (int) $body;
            if ($n === 1 || $n > 100) {
                return array('ok' => true, 'detail' => 'ارسال شد (شناسه ' . $n . ')');
            }
            return array('ok' => false, 'detail' => 'کد خطا ' . $n . ': ' . self::error_text($n));
        }
        return array('ok' => false, 'detail' => 'پاسخ نامشخص');
    }

    /** متن فارسی کدهای خطای مستندات رسمی */
    public static function error_text($code) {
        $map = array(
            0   => 'نام کاربری یا رمز اشتباه است',
            2   => 'اعتبار پنل پیامکی کافی نیست',
            3   => 'محدودیت تعداد ارسال روزانه',
            4   => 'محدودیت حجم هر ارسال',
            5   => 'شمارهٔ ارسال‌کننده معتبر نیست',
            6   => 'سامانه در حال به‌روزرسانی است',
            7   => 'متن شامل کلمات فیلترشده است',
            9   => 'ارسال از خط عمومی از طریق وب‌سرویس مجاز نیست',
            10  => 'پنل پیامکی فعال نیست',
            11  => 'درخواست در وضعیت ارسال نشده است',
            12  => 'احراز هویت پنل کامل نیست',
            14  => 'متن شامل لینک است',
            15  => 'عبارت «لغو11» در انتهای متن نیست',
            18  => 'شمارهٔ گیرنده نامعتبر است',
            108 => 'آی‌پی سرور مسدود شده است',
            109 => 'تنظیم آی‌پی مجاز در پنل ملی‌پیامک الزامی است',
            110 => 'استفاده از APIKey به‌جای رمز الزامی است',
        );
        $key = abs((int) $code);
        return isset($map[$key]) ? $map[$key] : 'خطای ناشناخته';
    }
}

