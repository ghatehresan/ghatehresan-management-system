<?php
/** گزارش‌ها و شاخص‌های ماهانه */

[$cy, $cm, $cd] = jtoday();
$jy = get_int('jy', $cy);
$jm = get_int('jm', $cm);
if ($jm < 1 || $jm > 12) $jm = $cm;

[$mStart, $mEnd] = jmonth_range($jy, $jm);

function period_stats($from, $to) {
    $orders = all("SELECT * FROM orders WHERE order_date BETWEEN ? AND ?", [$from, $to]);
    $s = ['n'=>0,'total'=>0,'revenue'=>0,'cost'=>0,'net'=>0,'returned'=>0,'cancelled'=>0,
          'units'=>0,'fees'=>0,'ship'=>0,'pack'=>0];
    foreach ($orders as $o) {
        if ($o['status'] === 'لغو شد') { $s['cancelled']++; continue; }
        $s['total']++;
        if ($o['is_returned']) { $s['returned']++; continue; }
        $p = order_profit($o);
        $s['n']++;
        $s['revenue'] += $p['revenue'];
        $s['cost']    += $p['cost'];
        $s['net']     += $p['net'];
        $s['units']   += $p['units'];
        $s['fees']    += (int)$o['gateway_fee'];
        $s['ship']    += (int)$o['shipping_cost'];
        $s['pack']    += (int)$o['packaging_cost'];
    }
    $s['aov']    = $s['n'] ? (int)round($s['revenue'] / $s['n']) : 0;
    $s['margin'] = $s['revenue'] > 0 ? $s['net'] / $s['revenue'] : 0;
    $s['retrate']= $s['total'] ? $s['returned'] / $s['total'] : 0;
    return $s;
}

$cur = period_stats($mStart, $mEnd);

// ماه قبل
$pm = $jm - 1; $py = $jy;
if ($pm < 1) { $pm = 12; $py--; }
[$pStart, $pEnd] = jmonth_range($py, $pm);
$prev = period_stats($pStart, $pEnd);

function delta($now, $before) {
    if ($before == 0) return $now > 0 ? ['+', '—'] : ['', '—'];
    $d = ($now - $before) / abs($before);
    return [$d >= 0 ? 'pos' : 'neg', ($d >= 0 ? '+' : '') . pct($d)];
}

// ۱۲ ماه اخیر
$series = [];
$ty = $jy; $tm = $jm;
for ($i = 0; $i < 12; $i++) {
    [$a, $b] = jmonth_range($ty, $tm);
    $st = period_stats($a, $b);
    $series[] = ['y'=>$ty, 'm'=>$tm, 'label'=>JMONTHS[$tm], 's'=>$st];
    $tm--; if ($tm < 1) { $tm = 12; $ty--; }
}
$series = array_reverse($series);
$maxRev = 1;
foreach ($series as $x) $maxRev = max($maxRev, $x['s']['revenue']);

// پرفروش‌های ماه
$topProducts = all("
  SELECT oi.product_name, SUM(oi.qty) AS units,
         SUM(oi.qty * oi.sell_price) AS rev,
         SUM(oi.qty * (oi.sell_price - oi.buy_price)) AS gross
    FROM order_items oi JOIN orders o ON o.id = oi.order_id
   WHERE o.order_date BETWEEN ? AND ? AND o.status <> 'لغو شد' AND o.is_returned = 0
   GROUP BY oi.product_name ORDER BY units DESC, rev DESC LIMIT 12", [$mStart, $mEnd]);

// دسته‌بندی ماه
$byCat = all("
  SELECT COALESCE(c.name, 'بدون دسته') AS cat, SUM(oi.qty) AS units,
         SUM(oi.qty * oi.sell_price) AS rev
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON p.id = oi.product_id
    LEFT JOIN categories c ON c.id = p.category_id
   WHERE o.order_date BETWEEN ? AND ? AND o.status <> 'لغو شد' AND o.is_returned=0
   GROUP BY cat ORDER BY rev DESC", [$mStart, $mEnd]);
$catMax = 1;
foreach ($byCat as $c) $catMax = max($catMax, (int)$c['rev']);

// کانال جذب
$bySource = all("
  SELECT COALESCE(NULLIF(c.source,''),'نامشخص') AS src, COUNT(DISTINCT o.id) AS n
    FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
   WHERE o.order_date BETWEEN ? AND ? AND o.status <> 'لغو شد' AND o.is_returned=0
   GROUP BY src ORDER BY n DESC", [$mStart, $mEnd]);

layout_head('گزارش‌ها');
page_head('گزارش‌ها', 'شاخص‌های ' . JMONTHS[$jm] . ' ' . fa_digits($jy),
    '<a class="btn" href="' . url('export', ['t' => 'orders']) . '">خروجی سفارش‌ها</a>');
?>

<div class="card">
  <div class="card-b">
    <form method="get" class="fbar">
      <input type="hidden" name="p" value="reports">
      <select name="jm" data-autosubmit>
        <?php foreach (array_slice(JMONTHS, 1, 12, true) as $i => $mn): ?>
          <option value="<?= $i ?>" <?= $jm === $i ? 'selected' : '' ?>><?= e($mn) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="jy" data-autosubmit>
        <?php for ($y = $cy; $y >= $cy - 4; $y--): ?>
          <option value="<?= $y ?>" <?= $jy === $y ? 'selected' : '' ?>><?= fa_digits($y) ?></option>
        <?php endfor; ?>
      </select>
      <button class="btn btn-n">نمایش</button>
      <span class="muted small" style="align-self:center">
        <?= jdate($mStart) ?> تا <?= jdate($mEnd) ?>
      </span>
    </form>
  </div>
</div>

<?php
$kpis = [
  ['تعداد سفارش', fa_digits($cur['n']), delta($cur['n'], $prev['n']), ''],
  ['مبلغ فروش', money($cur['revenue']), delta($cur['revenue'], $prev['revenue']), 'تومان'],
  ['سود خالص', money($cur['net']), delta($cur['net'], $prev['net']), 'تومان'],
  ['حاشیهٔ سود', pct($cur['margin']), delta($cur['margin'], $prev['margin']), ''],
  ['میانگین سبد', money($cur['aov']), delta($cur['aov'], $prev['aov']), 'تومان'],
  ['تعداد قطعه', fa_digits($cur['units']), delta($cur['units'], $prev['units']), ''],
  ['نرخ مرجوعی', pct($cur['retrate']), delta($prev['retrate'], $cur['retrate']), 'کمتر بهتر'],
  ['سفارش لغوشده', fa_digits($cur['cancelled']), ['',''], ''],
];
?>
<div class="stats g4">
  <?php foreach ($kpis as [$lb, $val, $dl, $hint]): ?>
    <div class="stat">
      <div class="lb"><?= e($lb) ?></div>
      <div class="vl sm"><?= $val ?></div>
      <div class="hint">
        <?php if (!empty($dl[1]) && $dl[1] !== '—'): ?>
          <span class="<?= $dl[0] ?>"><?= $dl[1] ?></span> نسبت به ماه قبل
        <?php else: ?><?= e($hint) ?><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($cur['retrate'] > 0.05 && $cur['n'] >= 5): ?>
  <div class="note n-red">
    <b>نرخ مرجوعی از ۵٪ بالاتر است</b>
    در فروش قطعه، مرجوعی بالا معمولاً یک ریشه دارد: سازگاری اشتباه اعلام شده یا کیفیت جنس پایین بوده.
    علت مرجوعی‌های این ماه را در صفحهٔ سفارش‌ها بخوانید — اگر همه از یک تأمین‌کننده یا یک دسته‌اند، مسئله پیدا شد.
  </div>
<?php endif; ?>

<?php if ($cur['net'] < 0 && $prev['net'] < 0): ?>
  <div class="note n-red">
    <b>علامت توقف: دو ماه پیاپی سود خالص منفی</b>
    این یکی از سه علامتی است که در سند استراتژی آمده. پیش از ادامه، ساختار قیمت‌گذاری و هزینه‌های
    ارسال و بسته‌بندی را بازبینی کنید.
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><h2>روند ۱۲ ماهه</h2><span class="sub">مبلغ فروش و سود خالص</span></div>
  <div class="card-b">
    <?php if ($maxRev <= 1): ?>
      <?php empty_box('هنوز داده‌ای نیست', 'پس از ثبت چند سفارش، روند اینجا نمایش داده می‌شود.'); ?>
    <?php else: ?>
      <div class="bars">
      <?php foreach ($series as $x):
          $h  = (int)round(($x['s']['revenue'] / $maxRev) * 100);
          $hn = $x['s']['revenue'] > 0
                ? (int)round((max(0, $x['s']['net']) / $maxRev) * 100) : 0;
      ?>
        <div class="bar-col" title="<?= e($x['label']) ?>: <?= money($x['s']['revenue']) ?>">
          <div class="bar-wrap">
            <div class="bar" style="height:<?= max(2, $h) ?>%"></div>
            <div class="bar bar-net" style="height:<?= $hn ?>%"></div>
          </div>
          <div class="bar-lb"><?= e(mb_substr($x['label'], 0, 4)) ?></div>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="legend">
        <span><i class="sw sw-navy"></i> مبلغ فروش</span>
        <span><i class="sw sw-orange"></i> سود خالص</span>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="stats g2">
  <div class="card mb0">
    <div class="card-h"><h2>پرفروش‌های این ماه</h2></div>
    <div class="card-b tight">
      <?php if (!$topProducts): ?>
        <?php empty_box('فروشی در این ماه ثبت نشده', ''); ?>
      <?php else: ?>
        <div class="tw">
        <table>
          <thead><tr><th>کالا</th><th class="num">تعداد</th>
                     <th class="num">فروش</th><th class="num">سود ناخالص</th></tr></thead>
          <tbody>
          <?php foreach ($topProducts as $t): ?>
            <tr>
              <td><?= e(str_limit($t['product_name'], 30)) ?></td>
              <td class="num"><b><?= fa_digits($t['units']) ?></b></td>
              <td class="num"><?= money($t['rev']) ?></td>
              <td class="num <?= (int)$t['gross'] < 0 ? 'neg' : 'pos' ?>"><?= money($t['gross']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card mb0">
    <div class="card-h"><h2>سهم دسته‌ها</h2></div>
    <div class="card-b">
      <?php if (!$byCat): ?>
        <?php empty_box('داده‌ای نیست', ''); ?>
      <?php else: ?>
        <?php foreach ($byCat as $c): ?>
          <div class="pbar">
            <div class="pbar-t">
              <span><?= e($c['cat']) ?></span>
              <b><?= money($c['rev']) ?></b>
            </div>
            <div class="pbar-r">
              <div class="pbar-f" style="width:<?= max(2, (int)round(((int)$c['rev'] / $catMax) * 100)) ?>%"></div>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="stats g2">
  <div class="card mb0">
    <div class="card-h"><h2>تفکیک هزینه‌های ماه</h2></div>
    <div class="card-b tight">
      <div class="tw">
      <table>
        <tbody>
          <tr><td>مبلغ فروش</td><td class="num tl"><b class="pos"><?= money($cur['revenue']) ?></b></td></tr>
          <tr><td>− بهای خرید کالا</td><td class="num tl"><?= money($cur['cost']) ?></td></tr>
          <tr><td>− کارمزد درگاه</td><td class="num tl"><?= money($cur['fees']) ?></td></tr>
          <tr><td>− هزینهٔ ارسال</td><td class="num tl"><?= money($cur['ship']) ?></td></tr>
          <tr><td>− بسته‌بندی</td><td class="num tl"><?= money($cur['pack']) ?></td></tr>
          <tr style="background:var(--paper)">
            <td><b>سود خالص</b></td>
            <td class="num tl"><b class="<?= $cur['net'] < 0 ? 'neg' : 'pos' ?>" style="font-size:16px">
              <?= money($cur['net']) ?></b></td>
          </tr>
        </tbody>
      </table>
      </div>
      <p class="tiny muted" style="padding:9px 13px 2px;margin:0">
        این محاسبه هزینه‌های ثابت (اینترنت، تلفن، اجاره، وقت شما) را در بر نمی‌گیرد.
        برای سود واقعی کسب‌وکار، آن‌ها را جداگانه کسر کنید.
      </p>
    </div>
  </div>

  <div class="card mb0">
    <div class="card-h"><h2>کانال جذب مشتری</h2></div>
    <div class="card-b tight">
      <?php if (!$bySource): ?>
        <?php empty_box('داده‌ای نیست', 'در پروندهٔ مشتری، «از کجا آشنا شد» را پر کنید.'); ?>
      <?php else: ?>
        <div class="tw">
        <table>
          <thead><tr><th>کانال</th><th class="num">تعداد سفارش</th><th class="num">سهم</th></tr></thead>
          <tbody>
          <?php $tot = array_sum(array_column($bySource, 'n')); ?>
          <?php foreach ($bySource as $s): ?>
            <tr>
              <td><?= e($s['src']) ?></td>
              <td class="num"><b><?= fa_digits($s['n']) ?></b></td>
              <td class="num"><?= pct($tot ? (int)$s['n'] / $tot : 0) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="note n-info">
  <b>یادآوری</b>
  این اعداد فقط ابزار تصمیم‌گیری داخلی هستند و جایگزین دفاتر رسمی حسابداری یا اظهارنامهٔ مالیاتی نمی‌شوند.
  برای امور مالیاتی با حسابدار مشورت کنید.
</div>

<?php layout_foot(); ?>
