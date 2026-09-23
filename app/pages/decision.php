<?php
/** ماشین‌حساب تصمیم انبار — پیاده‌سازی قاعدهٔ فاز ۲ سند استراتژی */

if (is_post()) {
    csrf_verify();
    if (post('action') === 'threshold') {
        auth_require_permission('change_decision');
        $min = max(1, post_int('min_sales'));
        set_setting('stock_min_sales_90d', (string)$min);
        activity_log('decision_changed', 'decision', null, 'min_sales_90d=' . $min);
        flash('ok', 'آستانه به‌روزرسانی شد.');
        redirect(url('decision'));
    }
    if (post('action') === 'flag') {
        auth_require_permission('change_decision');
        // ثبت وضعیت ثبات قیمت / منسوخ بودن در یادداشت کالا
        $pid = post_int('id');
        q("UPDATE products SET notes = ? WHERE id = ?", [post('notes'), $pid]);
        activity_log('decision_changed', 'product', $pid, 'flag_notes_updated=1');
        flash('ok', 'ذخیره شد.');
        redirect(url('decision'));
    }
}

$minSales = (int)setting('stock_min_sales_90d', 3);

// همهٔ کالاهای فعال با آمار فروش ۹۰ روز
$d90 = date('Y-m-d', strtotime('-90 days'));

$rows = all("
  SELECT p.id, p.name, p.part_number, p.buy_price, p.sell_price, p.stock_qty,
         p.status, c.name AS cat_name,
         COALESCE((SELECT SUM(oi.qty) FROM order_items oi
                     JOIN orders o ON o.id = oi.order_id
                    WHERE oi.product_id = p.id
                      AND o.status <> 'لغو شد' AND o.is_returned = 0
                      AND o.order_date >= ?), 0) AS sold90,
         COALESCE((SELECT COUNT(*) FROM order_items oi
                     JOIN orders o ON o.id = oi.order_id
                    WHERE oi.product_id = p.id
                      AND o.status <> 'لغو شد' AND o.is_returned = 0
                      AND o.order_date >= ?), 0) AS times90
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
   WHERE p.status = 'فعال'
   ORDER BY sold90 DESC, p.name", [$d90, $d90]);

// تقسیم‌بندی
$ready = $notyet = $instock = [];
foreach ($rows as $r) {
    $r['cond1'] = (int)$r['sold90'] >= $minSales;          // تکرار فروش
    $r['cond2'] = (int)$r['buy_price'] > 0;                // قیمت مشخص
    $r['cond3'] = (int)$r['times90'] >= 2;                 // در چند سفارش مجزا
    $r['all']   = $r['cond1'] && $r['cond2'] && $r['cond3'];

    if ((int)$r['stock_qty'] > 0)      $instock[] = $r;
    elseif ($r['all'])                 $ready[]   = $r;
    elseif ((int)$r['sold90'] > 0)     $notyet[]  = $r;
}

// سرمایهٔ لازم برای نامزدها (۲ عدد از هر کدام به عنوان مثال محافظه‌کارانه)
$needCapital = 0;
foreach ($ready as $r) $needCapital += (int)$r['buy_price'] * 2;

$invValue = (int)scalar("SELECT COALESCE(SUM(stock_qty*buy_price),0) FROM products WHERE stock_qty>0");

layout_head('تصمیم انبار');
page_head('ماشین‌حساب تصمیم انبار',
          'قاعدهٔ فاز ۲: قطعه فقط با داشتن هر سه شرط وارد انبار می‌شود');
?>

<div class="note n-amber">
  <b>قاعدهٔ طلایی</b>
  هرگز بر اساس حدس خرید نکنید. فقط قطعه‌ای وارد انبار شود که دادهٔ فروش خودتان ثابت کرده پرتکرار است.
  و بیش از یک‌سوم نقدینگی در دسترس را در موجودی قفل نکنید.
</div>

<div class="stats g4">
  <div class="stat pos"><div class="lb">آمادهٔ انبار</div>
    <div class="vl"><?= fa_digits(count($ready)) ?></div>
    <div class="hint">هر سه شرط را دارند</div></div>
  <div class="stat"><div class="lb">هنوز نه</div>
    <div class="vl"><?= fa_digits(count($notyet)) ?></div>
    <div class="hint">سفارش‌محور بمانند</div></div>
  <div class="stat"><div class="lb">در انبار</div>
    <div class="vl"><?= fa_digits(count($instock)) ?></div>
    <div class="hint">ارزش: <?= money($invValue) ?></div></div>
  <div class="stat acc"><div class="lb">سرمایهٔ تقریبی لازم</div>
    <div class="vl sm"><?= money($needCapital) ?></div>
    <div class="hint">برای ۲ عدد از هر نامزد</div></div>
</div>

<div class="card">
  <div class="card-h"><h2>آستانهٔ تصمیم</h2></div>
  <div class="card-b">
    <form method="post" style="display:flex;gap:11px;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="threshold">
      <div class="fld" style="max-width:250px">
        <label>حداقل فروش در ۹۰ روز برای ورود به انبار</label>
        <input type="text" name="min_sales" value="<?= (int)$minSales ?>" inputmode="numeric">
        <div class="hint">این عدد را از دادهٔ واقعی خودتان دربیاورید، نه از حدس</div>
      </div>
      <button class="btn btn-n">به‌روزرسانی</button>
    </form>
  </div>
</div>

<?php
function dec_table($rows, $title, $sub, $kind) {
    if (!$rows) return;
    ?>
    <div class="card">
      <div class="card-h">
        <h2><?= e($title) ?></h2>
        <span class="sub"><?= e($sub) ?></span>
      </div>
      <div class="card-b tight">
        <div class="tw">
        <table>
          <thead>
            <tr><th>کالا</th><th class="num">فروش ۹۰ روز</th><th class="num">تعداد سفارش</th>
                <th class="num">شرط ۱</th><th class="num">شرط ۲</th><th class="num">شرط ۳</th>
                <th class="num">قیمت خرید</th><th class="num">تصمیم</th></tr>
          </thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <a href="<?= url(auth_can('write_product') ? 'product_edit' : 'products', auth_can('write_product') ? ['id' => $r['id']] : []) ?>">
                  <b><?= e(str_limit($r['name'], 34)) ?></b></a>
                <div class="tiny muted"><?= e($r['cat_name'] ?: '—') ?></div>
              </td>
              <td class="num"><b><?= fa_digits($r['sold90']) ?></b></td>
              <td class="num"><?= fa_digits($r['times90']) ?></td>
              <td class="num"><?= $r['cond1'] ? '<span class="pos">✓</span>' : '<span class="neg">✗</span>' ?></td>
              <td class="num"><?= $r['cond2'] ? '<span class="pos">✓</span>' : '<span class="neg">✗</span>' ?></td>
              <td class="num"><?= $r['cond3'] ? '<span class="pos">✓</span>' : '<span class="neg">✗</span>' ?></td>
              <td class="num"><?= (int)$r['buy_price'] ? money($r['buy_price']) : '—' ?></td>
              <td class="num">
                <?php if ((int)$r['stock_qty'] > 0): ?>
                  <?= badge('موجودی: ' . fa_digits($r['stock_qty']), 'green') ?>
                <?php elseif ($r['all']): ?>
                  <?= badge('آمادهٔ انبار', 'orange') ?>
                <?php else: ?>
                  <?= badge('سفارش‌محور بماند', 'gray') ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        </div>
      </div>
    </div>
    <?php
}

if (!$rows) {
    empty_box('هنوز کالای فعالی وجود ندارد',
        'ابتدا کاتالوگ را پر کنید و چند سفارش ثبت کنید تا داده جمع شود.');
} else {
    dec_table($ready,  'آمادهٔ ورود به انبار', 'هر سه شرط را دارند', 'green');
    dec_table($notyet, 'هنوز نه — سفارش‌محور بمانند', 'فروش دارند اما به آستانه نرسیده‌اند', 'gray');
    dec_table($instock,'در حال حاضر در انبار', 'گردش این‌ها را زیر نظر داشته باشید', 'navy');
}
?>

<div class="card">
  <div class="card-h"><h2>معنی شرط‌ها</h2></div>
  <div class="card-b">
    <dl class="kv">
      <dt><b>شرط ۱</b></dt>
      <dd>فروش در ۹۰ روز گذشته حداقل <?= fa_digits($minSales) ?> عدد بوده است</dd>
      <dt><b>شرط ۲</b></dt>
      <dd>قیمت خرید مشخص و ثبت شده است — بدون آن نمی‌توان سرمایهٔ لازم را حساب کرد</dd>
      <dt><b>شرط ۳</b></dt>
      <dd>در حداقل ۲ سفارش مجزا فروخته شده — یعنی تقاضا واقعی است، نه یک خرید بزرگ اتفاقی</dd>
    </dl>
    <div class="dv"></div>
    <p class="small muted mb0">
      این ابزار تصمیم را ساده می‌کند اما جای قضاوت شما را نمی‌گیرد.
      قبل از خرید، دو نکتهٔ دیگر را هم در نظر بگیرید که سامانه نمی‌داند:
      آیا قیمت این قطعه باثبات است؟ آیا منسوخ‌شدنی است؟
    </p>
  </div>
</div>

<?php layout_foot(); ?>
