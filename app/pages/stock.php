<?php
/** انبار و کاردکس */

if (is_post()) {
    csrf_verify();
    if (post('action') === 'move') {
        auth_require_permission('write_stock');
        $pid = post_int('product_id');
        $qty = post_int('qty');
        $kind = post('kind', 'ورود');
        if (!$pid || $qty === 0) {
            flash('error', 'کالا و تعداد را درست وارد کنید.');
        } else {
            $signed = in_array($kind, ['خروج','فروش','ضایعات','مرجوع به تأمین‌کننده'], true)
                      ? -abs($qty) : abs($qty);
            $cur = product_stock($pid);
            if ($cur + $signed < 0) {
                flash('warn', 'هشدار: موجودی منفی شد. بررسی کنید که ثبت‌ها درست باشند.');
            }
            add_stock_move($pid, $signed, $kind,
                jalali_str_to_ymd(post('move_date')) ?: date('Y-m-d'),
                post_int('unit_cost'), post('ref'), post('notes'));
            flash('ok', 'حرکت انبار ثبت شد.');
        }
        redirect(url('stock', array_filter(['pid' => get_int('pid', 0) ?: null])));
    }
    if (post('action') === 'delmove') {
        auth_require_permission('delete_stock_move');
        $mv = one("SELECT * FROM stock_moves WHERE id=?", [post_int('id')]);
        if ($mv) {
            q("DELETE FROM stock_moves WHERE id=?", [$mv['id']]);
            q("UPDATE products SET stock_qty=? WHERE id=?",
              [product_stock((int)$mv['product_id']), $mv['product_id']]);
            flash('ok', 'حرکت حذف شد.');
        }
        redirect(url('stock', array_filter(['pid' => get_int('pid', 0) ?: null])));
    }
}

$pid = get_int('pid', 0);
$prod = $pid ? one("SELECT * FROM products WHERE id=?", [$pid]) : null;

$products = all("SELECT id, name, part_number, stock_qty, buy_price
                   FROM products WHERE status <> 'حذف‌شده' ORDER BY name");

$inStock = all("SELECT p.*, c.name AS cat_name
                  FROM products p LEFT JOIN categories c ON c.id=p.category_id
                 WHERE p.stock_qty <> 0 ORDER BY (p.stock_qty * p.buy_price) DESC");

$totalValue = 0; $totalUnits = 0;
foreach ($inStock as $r) {
    $totalValue += (int)$r['stock_qty'] * (int)$r['buy_price'];
    $totalUnits += (int)$r['stock_qty'];
}

// جنس مانده: موجودی دارد ولی در ۹۰ روز فروش نداشته
$d90 = date('Y-m-d', strtotime('-90 days'));
$dead = []; $deadValue = 0;
foreach ($inStock as $r) {
    if ((int)$r['stock_qty'] <= 0) continue;
    $s = product_sales_in_days((int)$r['id'], 90);
    if ($s === 0) { $dead[] = $r; $deadValue += (int)$r['stock_qty'] * (int)$r['buy_price']; }
}

$moves = $pid
    ? all("SELECT sm.*, p.name AS pname FROM stock_moves sm
             JOIN products p ON p.id = sm.product_id
            WHERE sm.product_id=? ORDER BY sm.move_date DESC, sm.id DESC LIMIT 120", [$pid])
    : all("SELECT sm.*, p.name AS pname FROM stock_moves sm
             JOIN products p ON p.id = sm.product_id
            ORDER BY sm.move_date DESC, sm.id DESC LIMIT 60");

[$jy, $jm, $jd] = jtoday();
$todayStr = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

layout_head('انبار');
page_head('انبار و کاردکس',
          $prod ? 'کاردکس: ' . $prod['name'] : 'ثبت ورود و خروج کالا',
          $pid ? '<a class="btn" href="' . url('stock') . '">نمای کلی انبار</a>' : '');
?>

<div class="stats g4">
  <div class="stat"><div class="lb">اقلام دارای موجودی</div>
    <div class="vl"><?= fa_digits(count($inStock)) ?></div></div>
  <div class="stat"><div class="lb">مجموع تعداد</div>
    <div class="vl"><?= fa_digits($totalUnits) ?></div></div>
  <div class="stat acc"><div class="lb">ارزش کل موجودی</div>
    <div class="vl sm"><?= money($totalValue) ?></div><div class="hint">تومان</div></div>
  <div class="stat <?= $deadValue > 0 ? 'neg' : '' ?>">
    <div class="lb">جنس مانده +۹۰ روز</div>
    <div class="vl sm"><?= money($deadValue) ?></div>
    <div class="hint"><?= fa_digits(count($dead)) ?> قلم بدون فروش</div></div>
</div>

<?php if ($deadValue > 0 && $totalValue > 0 && $deadValue / $totalValue > 0.25): ?>
  <div class="note n-red">
    <b>هشدار جنس مانده</b>
    بیش از یک‌چهارم ارزش انبار شما بیش از ۹۰ روز است که فروش نداشته.
    این یعنی خریدها بر اساس حدس انجام شده‌اند. پیش از خرید بعدی، بخش «تصمیم انبار» را ببینید.
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-h"><h2>ثبت حرکت انبار</h2></div>
  <div class="card-b">
    <form method="post" class="frm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="move">
      <div class="row r4">
        <div class="fld">
          <label>کالا *</label>
          <select name="product_id" required>
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($products as $p): ?>
              <option value="<?= $p['id'] ?>" <?= $pid === (int)$p['id'] ? 'selected' : '' ?>>
                <?= e(str_limit($p['name'], 40)) ?>
                <?= (int)$p['stock_qty'] ? ' (موجودی: ' . fa_digits($p['stock_qty']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label>نوع حرکت</label>
          <select name="kind">
            <?php foreach (['ورود','خروج','موجودی اولیه','مرجوع از مشتری',
                            'مرجوع به تأمین‌کننده','ضایعات','اصلاح شمارش'] as $k): ?>
              <option value="<?= e($k) ?>"><?= e($k) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label>تعداد *</label>
          <input type="text" name="qty" inputmode="numeric" required placeholder="0">
          <div class="hint">همیشه عدد مثبت — جهت از «نوع حرکت» تعیین می‌شود</div>
        </div>
        <div class="fld">
          <label>تاریخ</label>
          <input type="text" name="move_date" value="<?= e($todayStr) ?>" class="mono">
        </div>
      </div>
      <div class="row r3">
        <div class="fld">
          <label>بهای واحد <span class="opt">(اختیاری)</span></label>
          <input type="text" name="unit_cost" class="money" placeholder="0">
        </div>
        <div class="fld">
          <label>مرجع <span class="opt">(شمارهٔ فاکتور یا سفارش)</span></label>
          <input type="text" name="ref" placeholder="—">
        </div>
        <div class="fld">
          <label>یادداشت</label>
          <input type="text" name="notes" placeholder="—">
        </div>
      </div>
      <div class="frm-ft"><button class="btn btn-p">ثبت حرکت</button></div>
    </form>
  </div>
</div>

<?php if (!$pid && $inStock): ?>
<div class="card">
  <div class="card-h"><h2>موجودی فعلی</h2></div>
  <div class="card-b tight">
    <div class="tw">
    <table>
      <thead><tr><th>کالا</th><th>دسته</th><th class="num">موجودی</th>
                 <th class="num">بهای واحد</th><th class="num">ارزش</th>
                 <th class="num">فروش ۹۰ روز</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($inStock as $r):
          $s90 = product_sales_in_days((int)$r['id'], 90);
          $val = (int)$r['stock_qty'] * (int)$r['buy_price'];
      ?>
        <tr>
          <td><a href="<?= url(auth_can('write_product') ? 'product_edit' : 'products', auth_can('write_product') ? ['id' => $r['id']] : []) ?>">
              <b><?= e(str_limit($r['name'], 34)) ?></b></a></td>
          <td class="small"><?= e($r['cat_name'] ?: '—') ?></td>
          <td class="num"><b class="<?= (int)$r['stock_qty'] < 0 ? 'neg' : '' ?>">
            <?= fa_digits($r['stock_qty']) ?></b></td>
          <td class="num"><?= money($r['buy_price']) ?></td>
          <td class="num"><?= money($val) ?></td>
          <td class="num">
            <?= $s90 > 0 ? fa_digits($s90) : badge('بدون فروش', 'red') ?>
          </td>
          <td class="act">
            <a class="btn btn-sm" href="<?= url('stock', ['pid' => $r['id']]) ?>">کاردکس</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <div class="card-h">
    <h2><?= $pid ? 'کاردکس این کالا' : 'آخرین حرکت‌های انبار' ?></h2>
    <?php if ($prod): ?>
      <span class="sub">موجودی فعلی: <?= fa_digits(product_stock($pid)) ?></span>
    <?php endif; ?>
  </div>
  <div class="card-b tight">
    <?php if (!$moves): ?>
      <?php empty_box('حرکتی ثبت نشده', 'با فرم بالا اولین ورود انبار را ثبت کنید.'); ?>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead><tr><th>تاریخ</th><?= $pid ? '' : '<th>کالا</th>' ?>
                   <th>نوع</th><th class="num">تعداد</th>
                   <th class="num">بهای واحد</th><th>مرجع</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($moves as $m): ?>
          <tr>
            <td class="small nowrap"><?= jdate($m['move_date']) ?></td>
            <?php if (!$pid): ?>
              <td><a href="<?= url('stock', ['pid' => $m['product_id']]) ?>">
                  <?= e(str_limit($m['pname'], 26)) ?></a></td>
            <?php endif; ?>
            <td class="small"><?= e($m['kind']) ?></td>
            <td class="num">
              <b class="<?= (int)$m['qty'] < 0 ? 'neg' : 'pos' ?>">
                <?= (int)$m['qty'] > 0 ? '+' : '' ?><?= fa_digits($m['qty']) ?></b>
            </td>
            <td class="num"><?= (int)$m['unit_cost'] ? money($m['unit_cost']) : '—' ?></td>
            <td class="small"><?= e($m['ref'] ?: '—') ?></td>
            <td class="act">
              <?php if (auth_can('delete_stock_move')): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delmove">
                  <input type="hidden" name="id" value="<?= $m['id'] ?>">
                  <button class="btn btn-sm btn-d" data-confirm="این حرکت حذف شود؟">×</button>
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
