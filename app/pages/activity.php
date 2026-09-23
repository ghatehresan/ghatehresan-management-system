<?php
/** گزارش فعالیت کاربران — فقط مدیر کل */

auth_require_permission('manage_users');

$uid = get_int('user_id', 0);
$action = get('action');
$from = jalali_str_to_ymd(get('from'));
$to = jalali_str_to_ymd(get('to'));

$actions = [
    'login'               => 'ورود موفق',
    'login_failed'        => 'ورود ناموفق',
    'logout'              => 'خروج',
    'password_changed'    => 'تغییر رمز عبور',
    'user_created'        => 'ساخت کاربر',
    'user_updated'        => 'ویرایش کاربر',
    'user_activated'      => 'فعال‌سازی کاربر',
    'user_deactivated'    => 'غیرفعال‌سازی کاربر',
    'product_created'     => 'ثبت کالا',
    'product_updated'     => 'ویرایش کالا',
    'product_deleted'     => 'حذف کالا',
    'order_created'       => 'ثبت سفارش',
    'order_updated'       => 'ویرایش سفارش',
    'order_status_changed'=> 'تغییر وضعیت سفارش',
    'order_deleted'       => 'حذف سفارش',
    'customer_created'    => 'ثبت مشتری',
    'customer_updated'    => 'ویرایش مشتری',
    'customer_deleted'    => 'حذف مشتری',
    'supplier_created'    => 'ثبت تأمین‌کننده',
    'supplier_updated'    => 'ویرایش تأمین‌کننده',
    'supplier_deleted'    => 'حذف تأمین‌کننده',
    'missed_created'      => 'ثبت دفتر نداشتیم',
    'missed_resolved'     => 'رسیدگی به دفتر نداشتیم',
    'missed_deleted'      => 'حذف دفتر نداشتیم',
    'stock_added'         => 'ثبت حرکت انبار',
    'stock_deleted'       => 'حذف حرکت انبار',
    'decision_changed'    => 'تغییر تصمیم انبار',
    'export_downloaded'   => 'دریافت خروجی',
    'settings_updated'    => 'تغییر تنظیمات',
    'backup_downloaded'   => 'دریافت پشتیبان',
    'demo_cleared'        => 'حذف دادهٔ نمونه',
];
if ($action !== '' && !isset($actions[$action])) $action = '';

$w = [];
$params = [];
if ($uid) { $w[] = 'a.user_id = ?'; $params[] = $uid; }
if ($action !== '') { $w[] = 'a.action = ?'; $params[] = $action; }
if ($from) { $w[] = 'a.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to) { $w[] = 'a.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$rows = all("SELECT a.*, u.name AS user_name, u.username
               FROM activity_log a
               LEFT JOIN users u ON u.id = a.user_id
               $where
              ORDER BY a.created_at DESC, a.id DESC
              LIMIT 300", $params);
$users = all("SELECT id, name, username FROM users ORDER BY name");

layout_head('گزارش فعالیت');
page_head('گزارش فعالیت کاربران', 'ثبت رویدادهای ورود و عملیات حساس',
    '<a class="btn" href="' . url('export', ['t' => 'activity']) . '">خروجی CSV</a>');
?>

<div class="note n-info">
  <b>این گزارش برای پاسخ‌گویی است</b>
  ورودها، تغییرات کاربران و عملیات حساس در اینجا ثبت می‌شوند. رمز عبور هیچ‌وقت در گزارش ذخیره نمی‌شود.
</div>

<div class="card">
  <div class="card-b">
    <form method="get" class="fbar">
      <input type="hidden" name="p" value="activity">
      <select name="user_id">
        <option value="0">همهٔ کاربران</option>
        <?php foreach ($users as $user): ?>
          <option value="<?= (int)$user['id'] ?>" <?= $uid === (int)$user['id'] ? 'selected' : '' ?>>
            <?= e($user['name'] . ' · ' . $user['username']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="action">
        <option value="">همهٔ رویدادها</option>
        <?php foreach ($actions as $key => $label): ?>
          <option value="<?= e($key) ?>" <?= $action === $key ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="from" value="<?= e(get('from')) ?>" class="mono" placeholder="از تاریخ ۱۴۰۵/۰۱/۰۱">
      <input type="text" name="to" value="<?= e(get('to')) ?>" class="mono" placeholder="تا تاریخ ۱۴۰۵/۰۱/۳۰">
      <button class="btn btn-n">فیلتر</button>
      <?php if ($uid || $action !== '' || $from || $to): ?>
        <a class="btn" href="<?= url('activity') ?>">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>رویدادها</h2><span class="sub"><?= fa_digits(count($rows)) ?> مورد اخیر</span></div>
  <div class="card-b tight">
    <?php if (!$rows): ?>
      <?php empty_box('هنوز فعالیتی ثبت نشده', 'با ورود کاربران و انجام عملیات، گزارش اینجا ساخته می‌شود.'); ?>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead><tr><th>زمان</th><th>کاربر</th><th>رویداد</th><th>بخش</th><th>جزئیات</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td class="small nowrap"><?= jdate($r['created_at'], 'full') ?></td>
            <td>
              <b><?= e($r['user_name'] ?: 'سیستم / ناشناس') ?></b>
              <?php if ($r['username']): ?><div class="tiny muted mono" dir="ltr"><?= e($r['username']) ?></div><?php endif; ?>
            </td>
            <td><?= badge(activity_action_label($r['action']), str_contains($r['action'], 'failed') || str_contains($r['action'], 'deleted') ? 'red' : 'info') ?></td>
            <td class="small"><?= e(activity_entity_label($r['entity_type'])) ?><?= $r['entity_id'] ? ' #' . fa_digits($r['entity_id']) : '' ?></td>
            <td class="small"><?= e(str_limit($r['details'] ?: '—', 90)) ?></td>
            <td class="mono tiny" dir="ltr"><?= e($r['ip_address'] ?: '—') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php layout_foot(); ?>
