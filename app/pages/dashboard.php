<?php
/** داشبورد */

$today   = date('Y-m-d');
$d30     = date('Y-m-d', strtotime('-30 days'));
$d90     = date('Y-m-d', strtotime('-90 days'));

// آمار کلی
$nProducts  = (int)scalar("SELECT COUNT(*) FROM products WHERE status <> 'حذف‌شده'");
$nActive    = (int)scalar("SELECT COUNT(*) FROM products WHERE status = 'فعال'");
$nNoPhoto   = (int)scalar("SELECT COUNT(*) FROM products WHERE status='فعال' AND has_photo=0");
$nNoVeh     = (int)scalar("SELECT COUNT(*) FROM products p WHERE p.status='فعال'
                           AND NOT EXISTS (SELECT 1 FROM product_vehicles pv WHERE pv.product_id=p.id)");
$nMissedOpen= (int)scalar("SELECT COUNT(*) FROM missed WHERE resolved=0");
$nSuppliers = (int)scalar("SELECT COUNT(*) FROM suppliers WHERE active=1");

// سفارش‌های ۳۰ روز
$orders30 = all("SELECT * FROM orders WHERE order_date >= ? AND status <> 'لغو شد' AND is_returned=0", [$d30]);
$rev30 = $cost30 = $net30 = 0;
foreach ($orders30 as $o) {
    $pr = order_profit($o);
    $rev30  += $pr['revenue'];
    $cost30 += $pr['cost'];
    $net30  += $pr['net'];
}
$nOrders30 = count($orders30);
$aov30 = $nOrders30 > 0 ? (int)round($rev30 / $nOrders30) : 0;

// نرخ مرجوعی ۳۰ روز
$allOrd30 = (int)scalar("SELECT COUNT(*) FROM orders WHERE order_date >= ? AND status <> 'لغو شد'", [$d30]);
$ret30    = (int)scalar("SELECT COUNT(*) FROM orders WHERE order_date >= ? AND status <> 'لغو شد' AND is_returned=1", [$d30]);
$retRate  = $allOrd30 > 0 ? $ret30 / $allOrd30 : 0;

// سفارش‌های امروز
$todayCnt = (int)scalar("SELECT COUNT(*) FROM orders WHERE order_date = ?", [$today]);

// ارزش موجودی
$invValue = (int)scalar("SELECT COALESCE(SUM(stock_qty * buy_price),0) FROM products WHERE stock_qty > 0");
$invItems = (int)scalar("SELECT COUNT(*) FROM products WHERE stock_qty > 0");

// سفارش‌های باز
$openOrders = all("SELECT * FROM orders WHERE status IN ('ثبت شد','خرید شد')
                   ORDER BY order_date ASC, id ASC LIMIT 8");

// پرفروش‌ها ۹۰ روز
$topSellers = all("
    SELECT p.id, p.name, p.part_number, p.stock_qty,
           SUM(oi.qty) AS sold,
           SUM(oi.qty * (oi.sell_price - oi.buy_price)) AS gross
      FROM order_items oi
      JOIN orders o   ON o.id = oi.order_id
      JOIN products p ON p.id = oi.product_id
     WHERE o.order_date >= ? AND o.status <> 'لغو شد' AND o.is_returned = 0
     GROUP BY p.id, p.name, p.part_number, p.stock_qty
     ORDER BY sold DESC LIMIT 6", [$d90]);

// پرتکرارترین «نداشتیم»
$topMissed = all("SELECT part_name, COUNT(*) AS c, MAX(req_date) AS last_date
                    FROM missed WHERE resolved = 0
                   GROUP BY part_name ORDER BY c DESC, last_date DESC LIMIT 6");

// روند ۶ ماه اخیر
$trend = [];
[$jy, $jm] = jtoday();
for ($i = 5; $i >= 0; $i--) {
    $y = $jy; $m = $jm - $i;
    while ($m < 1) { $m += 12; $y--; }
    [$s, $e2] = jmonth_range($y, $m);
    // انتهای jmonth_range شامل آخرین روز ماه است؛ برای شرط <، روز بعد را استفاده می‌کنیم.
    $eExclusive = date('Y-m-d', strtotime($e2 . ' +1 day'));
    $rows = all("SELECT * FROM orders WHERE order_date >= ? AND order_date < ?
                  AND status <> 'لغو شد' AND is_returned=0", [$s, $eExclusive]);
    $r = $n = 0;
    foreach ($rows as $o) { $pp = order_profit($o); $r += $pp['revenue']; $n += $pp['net']; }
    $trend[] = ['label' => JMONTHS[$m], 'orders' => count($rows), 'rev' => $r, 'net' => $n];
}
$maxRev = max(1, max(array_column($trend, 'rev')));

layout_head('داشبورد');
page_head('داشبورد', 'نمای کلی کسب‌وکار — ' . jdate($today, 'long'),
    (auth_can('write_order') ? '<a class="btn btn-p" href="' . url('order_edit') . '">+ سفارش جدید</a>' : '')
  . (auth_can('write_product') ? '<a class="btn" href="' . url('product_edit') . '">+ کالای جدید</a>' : ''));

// ── هشدارهای اقدام‌پذیر ──
$alerts = [];
if ($nProducts === 0) {
    $alerts[] = ['info', 'شروع کار', 'هنوز کالایی ثبت نشده. از منوی «کاتالوگ» اولین کالا را اضافه کنید یا در «تنظیمات» دادهٔ نمونه بسازید.'];
}
if ($nSuppliers === 0 && $nProducts > 0) {
    $alerts[] = ['amber', 'تأمین‌کننده ثبت نشده', 'اطلاعات توزیع‌کننده‌ها را در بخش «تأمین‌کننده» وارد کنید تا بتوانید منبع هر کالا را مشخص کنید.'];
}
if ($nNoVeh > 0) {
    $alerts[] = ['amber', fa_digits($nNoVeh) . ' کالا بدون جدول سازگاری',
        'جدول سازگاری مهم‌ترین عامل کاهش مرجوعی است. برای هر کالا مشخص کنید به چه خودروهایی می‌خورد.'];
}
if ($nNoPhoto > 0) {
    $alerts[] = ['info', fa_digits($nNoPhoto) . ' کالا بدون عکس',
        'عکس واقعی قطعه، مهم‌ترین سرمایه‌گذاری اعتمادساز است.'];
}
if ($retRate > 0.05 && $allOrd30 >= 10) {
    $alerts[] = ['red', 'نرخ مرجوعی بالا: ' . pct($retRate),
        'ریشهٔ مرجوعی‌ها را در بخش سفارش‌ها بررسی کنید. معمولاً مشکل از جدول سازگاری است.'];
}
if ($net30 < 0 && $nOrders30 > 0) {
    $alerts[] = ['red', 'سود خالص ۳۰ روز منفی است',
        'مجموع هزینه‌ها از فروش بیشتر شده. بخش گزارش‌ها را برای ریشه‌یابی ببینید.'];
}
foreach ($alerts as [$k, $t, $m]) {
    echo '<div class="note n-' . $k . '"><b>' . e($t) . '</b>' . e($m) . '</div>';
}
?>

<div class="stats g4">
  <div class="stat acc">
    <div class="lb">سفارش ۳۰ روز اخیر</div>
    <div class="vl"><?= fa_digits($nOrders30) ?></div>
    <div class="hint">امروز: <?= fa_digits($todayCnt) ?> سفارش</div>
  </div>
  <div class="stat">
    <div class="lb">فروش ۳۰ روز</div>
    <div class="vl sm"><?= money($rev30) ?></div>
    <div class="hint">تومان</div>
  </div>
  <div class="stat <?= $net30 < 0 ? 'neg' : ($net30 > 0 ? 'pos' : '') ?>">
    <div class="lb">سود خالص ۳۰ روز</div>
    <div class="vl sm"><?= money($net30) ?></div>
    <div class="hint">
      <?= $rev30 > 0 ? 'حاشیه ' . pct($net30 / $rev30) : 'تومان' ?>
    </div>
  </div>
  <div class="stat">
    <div class="lb">میانگین ارزش سفارش</div>
    <div class="vl sm"><?= money($aov30) ?></div>
    <div class="hint">تومان</div>
  </div>
</div>

<div class="stats g4">
  <div class="stat">
    <div class="lb">کالای فعال</div>
    <div class="vl"><?= fa_digits($nActive) ?></div>
    <div class="hint">از <?= fa_digits($nProducts) ?> کل</div>
  </div>
  <div class="stat <?= $retRate > 0.05 ? 'neg' : '' ?>">
    <div class="lb">نرخ مرجوعی ۳۰ روز</div>
    <div class="vl"><?= $allOrd30 ? pct($retRate) : '—' ?></div>
    <div class="hint"><?= fa_digits($ret30) ?> از <?= fa_digits($allOrd30) ?> سفارش</div>
  </div>
  <div class="stat">
    <div class="lb">ارزش موجودی انبار</div>
    <div class="vl sm"><?= money($invValue) ?></div>
    <div class="hint"><?= fa_digits($invItems) ?> قلم دارای موجودی</div>
  </div>
  <div class="stat <?= $nMissedOpen > 0 ? 'acc' : '' ?>">
    <div class="lb">دفتر نداشتیم</div>
    <div class="vl"><?= fa_digits($nMissedOpen) ?></div>
    <div class="hint">مورد پیگیری‌نشده</div>
  </div>
</div>

<!-- روند ۶ ماه -->
<div class="card">
  <div class="card-h">
    <h2>روند ۶ ماه اخیر</h2>
    <span class="sub">فروش و سود خالص به تفکیک ماه</span>
  </div>
  <div class="card-b">
    <?php if (array_sum(array_column($trend, 'orders')) === 0): ?>
      <p class="muted small mb0">هنوز سفارشی ثبت نشده است.</p>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead>
          <tr><th>ماه</th><th class="num">سفارش</th><th class="num">فروش</th>
              <th class="num">سود خالص</th><th style="width:34%">نسبت فروش</th></tr>
        </thead>
        <tbody>
        <?php foreach ($trend as $t): ?>
          <tr>
            <td><b><?= e($t['label']) ?></b></td>
            <td class="num"><?= fa_digits($t['orders']) ?></td>
            <td class="num"><?= money($t['rev']) ?></td>
            <td class="num <?= $t['net'] < 0 ? 'neg' : ($t['net'] > 0 ? 'pos' : '') ?>">
              <?= money($t['net']) ?></td>
            <td>
              <div class="bar"><i style="width:<?= max(1, round($t['rev'] / $maxRev * 100)) ?>%"></i></div>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="stats g2">
  <!-- سفارش‌های باز -->
  <div class="card mb0">
    <div class="card-h">
      <h2>سفارش‌های باز</h2>
      <?php if (auth_can_page('orders')): ?><a class="btn btn-sm" href="<?= url('orders') ?>">همه</a><?php endif; ?>
    </div>
    <div class="card-b tight">
      <?php if (!$openOrders): ?>
        <div style="padding:26px 19px" class="muted small">سفارش بازی وجود ندارد.</div>
      <?php else: ?>
        <div class="tw">
        <table>
          <thead><tr><th>شماره</th><th>مشتری</th><th class="num">تاریخ</th><th class="num">وضعیت</th></tr></thead>
          <tbody>
          <?php foreach ($openOrders as $o):
              $nm = $o['customer_name'];
              if (!$nm && $o['customer_id']) {
                  $c = one("SELECT name FROM customers WHERE id=?", [$o['customer_id']]);
                  $nm = $c['name'] ?? '';
              }
          ?>
            <tr>
              <td>
                <?php if (auth_can_page('order_view')): ?><a href="<?= url('order_view', ['id' => $o['id']]) ?>"><?php endif; ?>
                  <b class="mono"><?= e($o['order_no'] ?: '#' . $o['id']) ?></b>
                <?php if (auth_can_page('order_view')): ?></a><?php endif; ?>
              </td>
              <td><?= e(str_limit($nm ?: '—', 22)) ?></td>
              <td class="num small"><?= jdate($o['order_date']) ?></td>
              <td class="num"><?= badge($o['status'], order_status_kind($o['status'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- پرتکرارترین نداشتیم -->
  <div class="card mb0">
    <div class="card-h">
      <h2>بیشترین درخواست‌های ناموجود</h2>
      <?php if (auth_can_page('missed')): ?><a class="btn btn-sm" href="<?= url('missed') ?>">همه</a><?php endif; ?>
    </div>
    <div class="card-b tight">
      <?php if (!$topMissed): ?>
        <div style="padding:26px 19px" class="muted small">
          هنوز موردی ثبت نشده. هر بار مشتری قطعه‌ای خواست که ندارید، در «دفتر نداشتیم» ثبت کنید.
        </div>
      <?php else: ?>
        <div class="tw">
        <table>
          <thead><tr><th>قطعه</th><th class="num">دفعات</th><th class="num">آخرین بار</th></tr></thead>
          <tbody>
          <?php foreach ($topMissed as $m): ?>
            <tr>
              <td><b><?= e(str_limit($m['part_name'], 30)) ?></b></td>
              <td class="num">
                <?= $m['c'] >= 3 ? badge(fa_digits($m['c']) . ' بار', 'orange') : fa_digits($m['c']) ?>
              </td>
              <td class="num small"><?= jdate($m['last_date']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- پرفروش‌ها -->
<div class="card mt16">
  <div class="card-h">
    <h2>پرفروش‌های ۹۰ روز اخیر</h2>
    <?php if (auth_can_page('decision')): ?><a class="btn btn-sm" href="<?= url('decision') ?>">بررسی تصمیم انبار</a><?php endif; ?>
  </div>
  <div class="card-b tight">
    <?php if (!$topSellers): ?>
      <div style="padding:26px 19px" class="muted small">هنوز فروشی ثبت نشده است.</div>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead>
          <tr><th>کالا</th><th class="num">تعداد فروش</th><th class="num">سود ناخالص</th>
              <th class="num">موجودی</th><th class="num">وضعیت انبار</th></tr>
        </thead>
        <tbody>
        <?php
          $minSales = (int)setting('stock_min_sales_90d', 3);
          foreach ($topSellers as $s):
            $ready = (int)$s['sold'] >= $minSales;
        ?>
          <tr>
            <td>
              <a href="<?= url(auth_can('write_product') ? 'product_edit' : 'products', auth_can('write_product') ? ['id' => $s['id']] : []) ?>"><b><?= e(str_limit($s['name'], 34)) ?></b></a>
              <?php if ($s['part_number']): ?>
                <div class="tiny muted mono"><?= e($s['part_number']) ?></div>
              <?php endif; ?>
            </td>
            <td class="num"><b><?= fa_digits($s['sold']) ?></b></td>
            <td class="num"><?= money($s['gross']) ?></td>
            <td class="num"><?= fa_digits($s['stock_qty']) ?></td>
            <td class="num">
              <?php if ((int)$s['stock_qty'] > 0): ?>
                <?= badge('در انبار', 'green') ?>
              <?php elseif ($ready): ?>
                <?= badge('نامزد انبار', 'orange') ?>
              <?php else: ?>
                <?= badge('سفارش‌محور', 'gray') ?>
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
