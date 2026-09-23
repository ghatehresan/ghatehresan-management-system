<?php
/**
 * قطعه‌رسان — سامانهٔ مدیریت
 * نقطهٔ ورود برنامه
 */

$cfg = require __DIR__ . '/../app/config.php';

if ($cfg['app']['debug']) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
}

date_default_timezone_set($cfg['app']['timezone']);
mb_internal_encoding('UTF-8');

// ثابت‌های عمومی برای استفاده در صفحات
define('APP_PATH',    dirname(__DIR__) . '/app');
define('DB_DRIVER',   $cfg['db']['driver']);
define('SQLITE_PATH', $cfg['db']['sqlite']['path']);
define('DB_NAME',     $cfg['db']['mysql']['database']);
define('DB_HOST',     $cfg['db']['mysql']['host']);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/db.php';
require __DIR__ . '/../app/layout.php';

// ساخت جداول در اولین اجرا
migrate();

// بررسی نسخهٔ PHP
if (PHP_VERSION_ID < 70400) {
    exit('<div style="font-family:Tahoma;direction:rtl;padding:40px;text-align:center">'
       . 'این برنامه به PHP نسخهٔ ۷٫۴ یا بالاتر نیاز دارد. نسخهٔ فعلی: ' . PHP_VERSION
       . '</div>');
}

// مسیریابی
$pages = ['dashboard','products','product_edit','orders','order_edit','order_view',
          'missed','stock','decision','suppliers','supplier_edit',
          'customers','customer_edit','reports','settings','export',
          'login','setup','logout'];

$p = $_GET['p'] ?? 'dashboard';
if (!in_array($p, $pages, true)) $p = 'dashboard';

// تا وقتی مدیر اولیه ساخته نشده، فقط راه‌اندازی مجاز است.
if (auth_bootstrap_required() && $p !== 'setup') {
    redirect(url('setup'));
}
if (!auth_bootstrap_required() && !is_authenticated() && !in_array($p, ['login'], true)) {
    redirect(url('login'));
}
if (is_authenticated() && in_array($p, ['login', 'setup'], true)) {
    redirect(url('dashboard'));
}

$file = __DIR__ . '/../app/pages/' . $p . '.php';
if (!is_file($file)) {
    http_response_code(404);
    $file = __DIR__ . '/../app/pages/dashboard.php';
}

require $file;
