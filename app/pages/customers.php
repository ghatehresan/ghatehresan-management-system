<?php
/** فهرست مشتریان */

if (is_post() && post('action') === 'delete') {
    csrf_verify();
    auth_require_permission('delete_customer');
    $id = post_int('id');
    q("UPDATE orders SET customer_id=NULL WHERE customer_id=?", [$id]);
    q("DELETE FROM customers WHERE id=?", [$id]);
    flash('ok', 'مشتری حذف شد.');
    redirect(url('customers'));
}

$search = get('q');
$type   = get('type');

$w = []; $prm = [];
if ($search !== '') {
    $w[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.city LIKE ?)";
    $prm[] = "%$search%"; $prm[] = "%$search%"; $prm[] = "%$search%";
}
if ($type !== '') { $w[] = "c.ctype = ?"; $prm[] = $type; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$rows = all("
  SELECT c.*,
    (SELECT COUNT(*) FROM orders o WHERE o.customer_id=c.id AND o.status<>'لغو شد' AND o.is_returned=0) AS n_orders,
    (SELECT MAX(o.order_date) FROM orders o WHERE o.customer_id=c.id AND o.status<>'لغو شد' AND o.is_returned=0) AS last_order
  FROM customers c $where
  ORDER BY n_orders DESC, c.name LIMIT 300", $prm);

// محاسبهٔ ارزش هر مشتری
foreach ($rows as &$r) {
    $ords = all("SELECT * FROM orders WHERE customer_id=? AND status<>'لغو شد' AND is_returned=0", [$r['id']]);
    $rev = $net = 0;
    foreach ($ords as $o) { $p = order_profit($o); $rev += $p['revenue']; $net += $p['net']; }
    $r['revenue'] = $rev; $r['net'] = $net;
}
unset($r);

$total   = (int)scalar("SELECT COUNT(*) FROM customers");
$repeat  = 0; $b2b = 0;
foreach ($rows as $r) {
    if ((int)$r['n_orders'] >= 2) $repeat++;
    if (in_array($r['ctype'], ['تعمیرکار', 'عمده‌فروش', 'نمایشگاه'], true)) $b2b++;
}
$repeatRate = count($rows) ? $repeat / count($rows) : 0;

layout_head('مشتریان');
page_head('مشتریان', fa_digits($total) . ' مشتری ثبت‌شده',
    '<a class="btn" href="' . url('export', ['t' => 'customers']) . '">خروجی CSV</a>'
  . '<a class="btn btn-p" href="' . url('customer_edit') . '">+ مشتری جدید</a>');
?>

<div class="stats g4">
  <div class="stat"><div class="lb">کل مشتریان</div><div class="vl"><?= fa_digits($total) ?></div></div>
  <div class="stat pos"><div class="lb">مشتری تکرارشونده</div>
    <div class="vl"><?= fa_digits($repeat) ?></div>
    <div class="hint"><?= pct($repeatRate) ?> از فهرست</div></div>
  <div class="stat"><div class="lb">مشتری کسب‌وکاری</div>
    <div class="vl"><?= fa_digits($b2b) ?></div>
    <div class="hint">تعمیرکار و عمده</div></div>
  <div class="stat acc"><div class="lb">نرخ بازگشت هدف</div>
    <div class="vl">۳۰٪</div>
    <div class="hint">شاخص سلامت اعتماد</div></div>
</div>

<?php if (count($rows) >= 10 && $repeatRate < 0.15): ?>
  <div class="note n-amber">
    <b>نرخ خرید دوباره پایین است</b>
    کمتر از ۱۵٪ مشتریان بار دوم خرید کرده‌اند. در فروش قطعه، مشتری راضی معمولاً برمی‌گردد.
    اگر برنمی‌گردد، یا قطعه مشکل داشته یا تجربهٔ تحویل خوب نبوده. چند نفرشان را تماس بگیرید و بپرسید.
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-b">
    <form method="get" class="fbar">
      <input type="hidden" name="p" value="customers">
      <input type="search" name="q" value="<?= e($search) ?>" placeholder="نام، تلفن یا شهر…">
      <select name="type" data-autosubmit>
        <option value="">همهٔ انواع</option>
        <?php foreach (['مصرف‌کننده','تعمیرکار','عمده‌فروش','نمایشگاه','سایر'] as $t): ?>
          <option value="<?= e($t) ?>" <?= $type === $t ? 'selected' : '' ?>><?= e($t) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-n">جست‌وجو</button>
      <?php if ($search !== '' || $type !== ''): ?>
        <a class="btn" href="<?= url('customers') ?>">پاک کردن</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-b tight">
    <?php if (!$rows): ?>
      <?php empty_box('مشتری‌ای یافت نشد',
        'مشتری‌ها معمولاً هنگام ثبت سفارش خودکار اضافه می‌شوند.',
        '<a class="btn btn-p" href="' . url('customer_edit') . '">+ افزودن دستی</a>'); ?>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead><tr><th>نام</th><th>نوع</th><th>تماس</th><th>شهر</th>
                   <th class="num">سفارش</th><th class="num">مجموع خرید</th>
                   <th class="num">سود خالص</th><th class="num">آخرین خرید</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td>
              <a href="<?= url('customer_edit', ['id' => $r['id']]) ?>"><b><?= e($r['name']) ?></b></a>
              <?php if ((int)$r['n_orders'] >= 3): ?>
                <span class="tiny"><?= badge('وفادار', 'green') ?></span>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($r['ctype'] ?: '—') ?></td>
            <td class="mono small" dir="ltr"><?= e($r['phone'] ?: '—') ?></td>
            <td class="small"><?= e($r['city'] ?: '—') ?></td>
            <td class="num"><b><?= fa_digits($r['n_orders']) ?></b></td>
            <td class="num"><?= money($r['revenue']) ?></td>
            <td class="num <?= $r['net'] < 0 ? 'neg' : 'pos' ?>"><?= money($r['net']) ?></td>
            <td class="num small"><?= $r['last_order'] ? jdate($r['last_order']) : '—' ?></td>
            <td class="act">
              <a class="btn btn-sm" href="<?= url('customer_edit', ['id' => $r['id']]) ?>">ویرایش</a>
              <?php if (auth_can('delete_customer')): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-d" data-confirm="حذف شود؟">×</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php layout_foot(); ?>
