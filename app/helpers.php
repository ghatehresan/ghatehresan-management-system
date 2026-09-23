<?php
/**
 * توابع کمکی عمومی
 */

// ── پلی‌فیل برای PHP 7.4 ───────────────────────────────────────────
if (!function_exists('str_contains')) {
    function str_contains($h, $n) { return $n === '' || strpos($h, $n) !== false; }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with($h, $n) { return strncmp($h, $n, strlen($n)) === 0; }
}

// ── خروجی امن ──────────────────────────────────────────────────────
function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── ارقام فارسی ────────────────────────────────────────────────────
function fa_digits($s) {
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    return str_replace($en, $fa, (string)$s);
}
function en_digits($s) {
    $fa = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
    $ar = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
    $en = ['0','1','2','3','4','5','6','7','8','9'];
    return str_replace(array_merge($fa, $ar), array_merge($en, $en), (string)$s);
}

/** مبلغ را با جداکنندهٔ هزارگان و ارقام فارسی برمی‌گرداند */
function money($n, $fa = true) {
    $n = (int)$n;
    $s = number_format(abs($n), 0, '.', '٬');
    if ($n < 0) $s = '−' . $s;
    return $fa ? fa_digits($s) : $s;
}
/** فقط عدد با جداکننده، بدون تبدیل به فارسی (برای input) */
function num_plain($n) { return (string)(int)$n; }

/** رشتهٔ ورودی کاربر را به عدد صحیح تبدیل می‌کند */
function to_int($v) {
    $v = en_digits(trim((string)$v));
    $v = preg_replace('/[^\d\-]/u', '', $v);
    return $v === '' || $v === '-' ? 0 : (int)$v;
}

// ── تاریخ شمسی ─────────────────────────────────────────────────────
function gregorian_to_jalali($gy, $gm, $gd) {
    $g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4))
          - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400))
          + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * ((int)($days / 12053)));
    $days %= 12053;
    $jy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $jy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + (int)($days / 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function jalali_to_gregorian($jy, $jm, $jd) {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (((int)($jy / 33)) * 8)
          + ((int)((($jy % 33) + 3) / 4)) + $jd
          + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * ((int)($days / 146097));
    $days %= 146097;
    if ($days > 36524) {
        $gy += 100 * ((int)(--$days / 36524));
        $days %= 36524;
        if ($days >= 365) $days++;
    }
    $gy += 4 * ((int)($days / 1461));
    $days %= 1461;
    if ($days > 365) {
        $gy += (int)(($days - 1) / 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $sal_a = [0,31,(($gy%4===0 && $gy%100!==0)||($gy%400===0))?29:28,
              31,30,31,30,31,31,30,31,30,31];
    for ($gm = 0; $gm < 13 && $gd > $sal_a[$gm]; $gm++) $gd -= $sal_a[$gm];
    return [$gy, $gm, $gd];
}

const JMONTHS = ['','فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور',
                 'مهر','آبان','آذر','دی','بهمن','اسفند'];

/** تاریخ میلادی Y-m-d را به شمسی تبدیل می‌کند */
function jdate($ymd, $format = 'short') {
    if (!$ymd) return '—';
    $ts = is_numeric($ymd) ? (int)$ymd : strtotime($ymd);
    if (!$ts) return '—';
    [$jy, $jm, $jd] = gregorian_to_jalali((int)date('Y',$ts), (int)date('n',$ts), (int)date('j',$ts));
    if ($format === 'long')  return fa_digits($jd) . ' ' . JMONTHS[$jm] . ' ' . fa_digits($jy);
    if ($format === 'month') return JMONTHS[$jm] . ' ' . fa_digits($jy);
    if ($format === 'full')  return fa_digits(sprintf('%04d/%02d/%02d', $jy,$jm,$jd)) . ' — ' . fa_digits(date('H:i',$ts));
    return fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
}

/** امروز به شمسی، آرایهٔ [سال, ماه, روز] */
function jtoday() {
    return gregorian_to_jalali((int)date('Y'), (int)date('n'), (int)date('j'));
}

/** رشتهٔ شمسی ۱۴۰۵/۰۶/۳۰ را به میلادی Y-m-d تبدیل می‌کند */
function jalali_str_to_ymd($s) {
    $s = en_digits(trim((string)$s));
    if ($s === '') return null;
    if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})$/', $s, $m)) {
        $y = (int)$m[1];
        if ($y > 1700) return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[3]);
        [$gy,$gm,$gd] = jalali_to_gregorian($y, (int)$m[2], (int)$m[3]);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }
    return null;
}

/** بازهٔ میلادی یک ماه شمسی: [start, end] */
/**
 * بازهٔ میلادی یک ماه شمسی.
 * هر دو سر بازه *شامل* هستند، چون همهٔ کوئری‌ها از BETWEEN استفاده می‌کنند.
 * پس روز پایان = یک روز پیش از اول ماه بعد.
 */
function jmonth_range($jy, $jm) {
    [$gy1,$gm1,$gd1] = jalali_to_gregorian($jy, $jm, 1);
    $nY = $jm == 12 ? $jy + 1 : $jy;
    $nM = $jm == 12 ? 1 : $jm + 1;
    [$gy2,$gm2,$gd2] = jalali_to_gregorian($nY, $nM, 1);
    $start = sprintf('%04d-%02d-%02d', $gy1, $gm1, $gd1);
    $end   = date('Y-m-d', strtotime(sprintf('%04d-%02d-%02d', $gy2, $gm2, $gd2) . ' -1 day'));
    return [$start, $end];
}

// ── نشست، امنیت، پیام ──────────────────────────────────────────────
/** آیا سامانه هنوز کاربر اولیه ندارد؟ */
function auth_bootstrap_required(): bool {
    return (int)scalar("SELECT COUNT(*) FROM users") === 0;
}

/** کاربر واردشدهٔ فعلی */
function auth_user() {
    static $loaded = false;
    static $user = null;
    if ($loaded) return $user;
    $loaded = true;
    $id = (int)($_SESSION['_user_id'] ?? 0);
    if ($id > 0) {
        $user = one("SELECT id, username, name, role, active FROM users WHERE id=? AND active=1", [$id]);
        if (!$user) unset($_SESSION['_user_id']);
    }
    return $user;
}

function is_authenticated(): bool {
    return auth_user() !== null;
}

/** ورود موفق و بازسازی نشست */
function auth_login(array $user): void {
    session_regenerate_id(true);
    unset($_SESSION['_csrf']);
    $_SESSION['_user_id'] = (int)$user['id'];
}

function auth_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params['path'], $params['domain'],
            (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

/** نقش‌های قابل انتخاب در سامانه */
function user_roles(): array {
    return [
        'admin'     => 'مدیر کل',
        'manager'   => 'مدیر عملیاتی',
        'sales'     => 'فروشنده',
        'warehouse' => 'انباردار',
    ];
}

function role_label($role): string {
    $roles = user_roles();
    return $roles[$role] ?? 'نامشخص';
}

function auth_role(): string {
    $u = auth_user();
    return (string)($u['role'] ?? '');
}

function auth_is_admin(): bool {
    return auth_role() === 'admin';
}

/** کنترل دسترسی صفحه، مستقل از مخفی یا نمایش دادن لینک‌ها */
function auth_can_page(string $page): bool {
    if (!is_authenticated()) return in_array($page, ['login', 'setup'], true);
    if (auth_is_admin()) return true;

    $roles = [
        'dashboard'    => ['manager','sales','warehouse'],
        'products'     => ['manager','sales','warehouse'],
        'product_edit' => ['manager','sales'],
        'orders'       => ['manager','sales'],
        'order_edit'   => ['manager','sales'],
        'order_view'   => ['manager','sales'],
        'missed'       => ['manager','sales'],
        'stock'        => ['manager','warehouse'],
        'decision'     => ['manager','warehouse'],
        'suppliers'    => ['manager','warehouse'],
        'supplier_edit'=> ['manager','warehouse'],
        'customers'    => ['manager','sales'],
        'customer_edit'=> ['manager','sales'],
        'reports'      => ['manager','sales'],
        'export'       => ['manager','sales','warehouse'],
        'account'      => ['manager','sales','warehouse'],
        'activity'     => [],
        'settings'     => [],
        'users'        => [],
        'logout'       => ['manager','sales','warehouse'],
    ];
    return in_array(auth_role(), $roles[$page] ?? [], true);
}

/** کنترل دسترسی عملیات حساس در سمت سرور */
function auth_can(string $permission): bool {
    if (auth_is_admin()) return true;
    $role = auth_role();
    $permissions = [
        'write_product'      => ['manager','sales'],
        'delete_product'     => ['manager'],
        'write_order'        => ['manager','sales'],
        'change_order_status'=> ['manager','sales'],
        'delete_order'       => ['manager'],
        'write_customer'     => ['manager','sales'],
        'delete_customer'    => ['manager'],
        'write_supplier'     => ['manager','warehouse'],
        'delete_supplier'    => ['manager'],
        'write_missed'       => ['manager','sales'],
        'delete_missed'      => ['manager'],
        'write_stock'        => ['manager','warehouse'],
        'delete_stock_move'  => ['manager','warehouse'],
        'change_decision'    => ['manager','warehouse'],
        'manage_users'       => [],
        'manage_settings'    => [],
    ];
    return in_array($role, $permissions[$permission] ?? [], true);
}

function auth_require_permission(string $permission): void {
    if (auth_can($permission)) return;
    http_response_code(403);
    exit('<div style="font-family:Tahoma;direction:rtl;padding:40px;text-align:center">'
       . 'شما اجازهٔ انجام این عملیات را ندارید.</div>');
}

function active_admin_count(): int {
    return (int)scalar("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1");
}

function auth_can_export(string $type): bool {
    if (auth_is_admin()) return true;
    if (in_array($type, ['products','stock'], true)) {
        return in_array(auth_role(), ['manager','sales','warehouse'], true);
    }
    if (in_array($type, ['orders','order_items','customers','missed'], true)) {
        return in_array(auth_role(), ['manager','sales'], true);
    }
    if ($type === 'suppliers') {
        return in_array(auth_role(), ['manager','warehouse'], true);
    }
    return false;
}

/** ثبت رویداد امنیتی/عملیاتی؛ خراب شدن لاگ نباید عملیات اصلی را متوقف کند. */
function activity_log(string $action, $entityType = null, $entityId = null,
                     $details = null, $userId = null): void {
    if ($userId === null) {
        $u = auth_user();
        $userId = $u ? (int)$u['id'] : null;
    }
    try {
        q("INSERT INTO activity_log
             (user_id, action, entity_type, entity_id, details, ip_address, user_agent)
           VALUES (?,?,?,?,?,?,?)", [
            $userId ?: null,
            $action,
            $entityType ?: null,
            $entityId ? (int)$entityId : null,
            $details !== null ? (string)$details : null,
            substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 255) ?: null,
            substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000) ?: null,
        ]);
    } catch (Throwable $ex) {
        error_log('[Ghatehresan activity] ' . $ex->getMessage());
    }
}

function activity_action_label(string $action): string {
    return [
        'login'              => 'ورود موفق',
        'login_failed'       => 'ورود ناموفق',
        'logout'             => 'خروج',
        'password_changed'   => 'تغییر رمز عبور',
        'user_created'       => 'ساخت کاربر',
        'user_updated'       => 'ویرایش کاربر',
        'user_activated'     => 'فعال‌سازی کاربر',
        'user_deactivated'   => 'غیرفعال‌سازی کاربر',
        'product_created'    => 'ثبت کالا',
        'product_updated'    => 'ویرایش کالا',
        'product_deleted'    => 'حذف کالا',
        'order_created'      => 'ثبت سفارش',
        'order_updated'      => 'ویرایش سفارش',
        'order_status_changed'=> 'تغییر وضعیت سفارش',
        'order_deleted'      => 'حذف سفارش',
        'customer_created'   => 'ثبت مشتری',
        'customer_updated'   => 'ویرایش مشتری',
        'customer_deleted'   => 'حذف مشتری',
        'supplier_created'   => 'ثبت تأمین‌کننده',
        'supplier_updated'   => 'ویرایش تأمین‌کننده',
        'supplier_deleted'   => 'حذف تأمین‌کننده',
        'missed_created'     => 'ثبت دفتر نداشتیم',
        'missed_resolved'    => 'رسیدگی به دفتر نداشتیم',
        'missed_deleted'     => 'حذف دفتر نداشتیم',
        'stock_added'        => 'ثبت حرکت انبار',
        'stock_deleted'      => 'حذف حرکت انبار',
        'decision_changed'   => 'تغییر تصمیم انبار',
        'export_downloaded'  => 'دریافت خروجی',
        'settings_updated'   => 'تغییر تنظیمات',
        'backup_downloaded'  => 'دریافت پشتیبان',
        'demo_cleared'       => 'حذف دادهٔ نمونه',
    ][$action] ?? $action;
}

function activity_entity_label($type): string {
    return [
        'auth'       => 'احراز هویت',
        'user'       => 'کاربر',
        'product'    => 'کالا',
        'order'      => 'سفارش',
        'customer'   => 'مشتری',
        'supplier'   => 'تأمین‌کننده',
        'missed'     => 'دفتر نداشتیم',
        'stock'      => 'انبار',
        'decision'   => 'تصمیم انبار',
        'export'     => 'خروجی',
        'settings'   => 'تنظیمات',
        'backup'     => 'پشتیبان',
    ][$type] ?? ($type ?: '—');
}

function csrf_token() {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}
function csrf_field() {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_verify() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $t = $_POST['_csrf'] ?? '';
    if (!$t || !hash_equals($_SESSION['_csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('<div style="font-family:Tahoma;direction:rtl;padding:40px;text-align:center">'
           . 'نشست منقضی شده است. لطفاً صفحه را تازه کنید و دوباره تلاش کنید.</div>');
    }
    return true;
}

function flash($type = null, $msg = null) {
    if ($msg === null) {
        $m = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $m;
    }
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

function url($page, $params = []) {
    $q = array_merge(['p' => $page], $params);
    return '?' . http_build_query($q);
}

function is_post() { return $_SERVER['REQUEST_METHOD'] === 'POST'; }

function post($k, $d = '')  { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function get($k, $d = '')   { return isset($_GET[$k])  ? trim((string)$_GET[$k])  : $d; }
function post_int($k)       { return to_int($_POST[$k] ?? 0); }
function get_int($k, $d=0)  { $v = $_GET[$k] ?? null; return $v === null || $v === '' ? $d : (int)en_digits($v); }

// ── نمایش ──────────────────────────────────────────────────────────
function pct($n, $digits = 1) {
    return fa_digits(number_format((float)$n * 100, $digits)) . '٪';
}

function badge($text, $kind = 'gray') {
    return '<span class="badge b-' . e($kind) . '">' . e($text) . '</span>';
}

/** رنگ وضعیت سفارش */
function order_status_kind($s) {
    $map = [
        'ثبت شد'   => 'info',
        'خرید شد'  => 'amber',
        'ارسال شد' => 'navy',
        'تحویل شد' => 'green',
        'لغو شد'   => 'red',
    ];
    return $map[$s] ?? 'gray';
}

function product_status_kind($s) {
    $map = [
        'فعال'            => 'green',
        'موقتاً ناموجود'  => 'amber',
        'حذف‌شده'         => 'red',
        'در حال بررسی'    => 'gray',
    ];
    return $map[$s] ?? 'gray';
}

/** برش امن رشته */
function str_limit($s, $n = 60) {
    $s = (string)$s;
    if (mb_strlen($s, 'UTF-8') <= $n) return $s;
    return mb_substr($s, 0, $n - 1, 'UTF-8') . '…';
}

/** تولید کد داخلی بعدی مثل GR-FLT-003 */
function next_internal_code(PDO $db, $prefix = 'GR') {
    $row = $db->query("SELECT internal_code FROM products
                       WHERE internal_code LIKE '{$prefix}-%'
                       ORDER BY id DESC LIMIT 1")->fetch();
    $n = 1;
    if ($row && preg_match('/(\d+)$/', $row['internal_code'], $m)) {
        $n = (int)$m[1] + 1;
    } else {
        $n = (int)$db->query("SELECT COUNT(*) FROM products")->fetchColumn() + 1;
    }
    return sprintf('%s-%04d', $prefix, $n);
}
