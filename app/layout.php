<?php
/** قالب اصلی — هدر و فوتر */

function layout_head($pageTitle = '') {
    $cfg = require __DIR__ . '/config.php';
    $appName = setting('shop_name', $cfg['app']['name']) ?: $cfg['app']['name'];
    $t = $pageTitle ? $pageTitle . ' — ' . $appName : ($appName . ' — سامانهٔ مدیریت');
    $p = $_GET['p'] ?? 'dashboard';

    $nav = [
        'dashboard' => ['داشبورد',        'M3 12l9-9 9 9M5 10v10h14V10'],
        'products'  => ['کاتالوگ',        'M4 6h16M4 12h16M4 18h10'],
        'orders'    => ['سفارش‌ها',       'M6 2l1 4h14l-2 9H8L6 2zM9 21a1 1 0 100-2 1 1 0 000 2zm9 0a1 1 0 100-2 1 1 0 000 2z'],
        'missed'    => ['دفتر نداشتیم',   'M12 9v4m0 4h.01M10.3 3.9L2 18a2 2 0 001.7 3h16.6a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z'],
        'stock'     => ['انبار',          'M3 9l9-6 9 6v10a2 2 0 01-2 2H5a2 2 0 01-2-2z'],
        'decision'  => ['تصمیم انبار',    'M9 11l3 3L22 4M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11'],
        'suppliers' => ['تأمین‌کننده',    'M17 20h5v-2a3 3 0 00-5.4-1.8M9 20H4v-2a3 3 0 015.4-1.8M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        'customers' => ['مشتریان',        'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        'reports'   => ['گزارش‌ها',       'M18 20V10M12 20V4M6 20v-6'],
        'users'     => ['کاربران',         'M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2M9 11a4 4 0 100-8 4 4 0 000 8zm7-1a3 3 0 100-6'],
        'settings'  => ['تنظیمات',        'M12 15a3 3 0 100-6 3 3 0 000 6zM19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09A1.65 1.65 0 008 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H2a2 2 0 110-4h.09A1.65 1.65 0 004.6 8a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06A1.65 1.65 0 008 3.68V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06A1.65 1.65 0 0019.4 8v0c.14.31.4.56.72.7H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z'],
    ];
    ?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($t) ?></title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>

<aside class="side">
  <a class="brand" href="<?= url('dashboard') ?>">
    <svg viewBox="0 0 250 220" width="34" height="30" aria-hidden="true">
      <g transform="translate(5,5)">
        <path d="M 37.7 62.5 L 120 15 L 202.3 62.5 L 202.3 157.5 L 120 205 L 37.7 157.5"
              fill="none" stroke="#fff" stroke-width="21" stroke-linecap="round" stroke-linejoin="round"/>
        <polygon points="150,98 78,98 78,76 20,110 78,144 78,122 150,122" fill="#F26B1D"/>
      </g>
    </svg>
    <span>
      <b><?= e($appName) ?></b>
      <em>سامانهٔ مدیریت</em>
    </span>
  </a>

  <nav>
    <?php foreach ($nav as $key => [$label, $icon]): ?>
      <?php if (!auth_can_page($key)) continue; ?>
      <a href="<?= url($key) ?>" class="<?= $p === $key ? 'on' : '' ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="<?= $icon ?>"/></svg>
        <span><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="side-ft">
    <?php
      $miss = (int)scalar("SELECT COUNT(*) FROM missed WHERE resolved=0");
      if ($miss > 0 && auth_can_page('missed')):
    ?>
      <a class="alert-pill" href="<?= url('missed') ?>">
        <?= fa_digits($miss) ?> مورد در «نداشتیم»
      </a>
    <?php endif; ?>
    <?php $u = auth_user(); if ($u): ?>
      <div class="ver" style="margin-bottom:2px;color:#c6d3e1"><?= e($u['name'] ?: $u['username']) ?></div>
      <div class="ver" style="margin-bottom:7px;color:#8aa0b8"><?= e(role_label($u['role'])) ?></div>
      <form method="post" action="<?= url('logout') ?>" style="text-align:center;margin-bottom:8px">
        <?= csrf_field() ?>
        <button class="btn btn-sm" type="submit" style="background:transparent;color:#c6d3e1;border-color:rgba(255,255,255,.2);width:100%;justify-content:center">خروج از سامانه</button>
      </form>
    <?php endif; ?>
    <div class="ver">نسخهٔ ۱٫۲</div>
  </div>
</aside>

<div class="mobile-bar">
  <button id="menuBtn" aria-label="منو">☰</button>
  <span><?= e($pageTitle ?: 'قطعه‌رسان') ?></span>
</div>

<main class="main">
<?php
    foreach (flash() as $f) {
        $k = $f['type'] === 'error' ? 'red' : ($f['type'] === 'warn' ? 'amber' : 'green');
        echo '<div class="flash f-' . $k . '">' . e($f['msg']) . '</div>';
    }
}

function layout_foot() {
    ?>
</main>
<script src="assets/app.js"></script>
</body>
</html>
<?php
}

/** سربرگ صفحه */
function page_head($title, $sub = '', $actions = '') {
    echo '<div class="phead"><div><h1>' . e($title) . '</h1>';
    if ($sub) echo '<p>' . e($sub) . '</p>';
    echo '</div>';
    if ($actions) echo '<div class="pactions">' . $actions . '</div>';
    echo '</div>';
}

/** جعبهٔ خالی */
function empty_box($title, $sub = '', $btn = '') {
    echo '<div class="empty"><div class="ei">📋</div><h3>' . e($title) . '</h3>';
    if ($sub) echo '<p>' . e($sub) . '</p>';
    if ($btn) echo '<div style="margin-top:14px">' . $btn . '</div>';
    echo '</div>';
}
