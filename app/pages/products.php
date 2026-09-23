<?php
/** فهرست کاتالوگ */

// حذف
if (is_post() && post('action') === 'delete') {
    csrf_verify();
    $id = post_int('id');
    q("DELETE FROM product_vehicles WHERE product_id=?", [$id]);
    q("UPDATE order_items SET product_id=NULL WHERE product_id=?", [$id]);
    q("DELETE FROM stock_moves WHERE product_id=?", [$id]);
    q("DELETE FROM products WHERE id=?", [$id]);
    flash('ok', 'کالا حذف شد.');
    redirect(url('products'));
}

$search = get('q');
$fCat   = get_int('cat', 0);
$fSup   = get_int('sup', 0);
$fVeh   = get_int('veh', 0);
$fSt    = get('st');
$fFlag  = get('flag');
$sort   = get('sort', 'new');
$page   = max(1, get_int('pg', 1));
$per    = 25;

$w = []; $prm = [];
if ($search !== '') {
    $w[] = "(p.name LIKE ? OR p.part_number LIKE ? OR p.internal_code LIKE ? OR p.brand LIKE ?)";
    $like = '%' . $search . '%';
    array_push($prm, $like, $like, $like, $like);
}
if ($fCat) { $w[] = "p.category_id = ?"; $prm[] = $fCat; }
if ($fSup) { $w[] = "p.supplier_id = ?"; $prm[] = $fSup; }
if ($fSt !== '') { $w[] = "p.status = ?"; $prm[] = $fSt; }
if ($fVeh) {
    $w[] = "EXISTS (SELECT 1 FROM product_vehicles pv WHERE pv.product_id=p.id AND pv.vehicle_id=?)";
    $prm[] = $fVeh;
}
if ($fFlag === 'nophoto')  $w[] = "p.has_photo = 0";
if ($fFlag === 'noveh')    $w[] = "NOT EXISTS (SELECT 1 FROM product_vehicles pv WHERE pv.product_id=p.id)";
if ($fFlag === 'noprice')  $w[] = "(p.buy_price = 0 OR p.sell_price = 0)";
if ($fFlag === 'loss')     $w[] = "(p.sell_price > 0 AND p.buy_price > 0 AND p.sell_price < p.buy_price)";
if ($fFlag === 'instock')  $w[] = "p.stock_qty > 0";

$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$orderBy = [
    'new'    => 'p.id DESC',
    'name'   => 'p.name ASC',
    'profit' => '(p.sell_price - p.buy_price) DESC',
    'stock'  => 'p.stock_qty DESC',
][$sort] ?? 'p.id DESC';

$total = (int)scalar("SELECT COUNT(*) FROM products p $where", $prm);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$rows = all("SELECT p.*, c.name AS cat_name, s.name AS sup_name
               FROM products p
               LEFT JOIN categories c ON c.id = p.category_id
               LEFT JOIN suppliers  s ON s.id = p.supplier_id
               $where ORDER BY $orderBy LIMIT $per OFFSET $off", $prm);

// خودروهای هر کالا
$vehMap = [];
if ($rows) {
    $ids = implode(',', array_map(fn($r) => (int)$r['id'], $rows));
    foreach (all("SELECT pv.product_id, v.name FROM product_vehicles pv
                    JOIN vehicles v ON v.id = pv.vehicle_id
                   WHERE pv.product_id IN ($ids)") as $r) {
        $vehMap[$r['product_id']][] = $r['name'];
    }
}

$cats = all("SELECT * FROM categories ORDER BY sort_order, name");
$sups = all("SELECT * FROM suppliers WHERE active=1 ORDER BY name");
$vehs = all("SELECT * FROM vehicles ORDER BY sort_order, name");

layout_head('کاتالوگ');
page_head('کاتالوگ کالا', fa_digits($total) . ' کالا',
    '<a class="btn btn-p" href="' . url('product_edit') . '">+ کالای جدید</a>'
  . '<a class="btn" href="' . url('export', ['t' => 'products']) . '">خروجی CSV</a>');
?>

<form class="filters" method="get">
  <input type="hidden" name="p" value="products">
  <div class="fld wide">
    <label>جست‌وجو</label>
    <input type="search" name="q" value="<?= e($search) ?>" data-livesearch
           placeholder="نام، کد فنی، کد داخلی یا برند">
  </div>
  <div class="fld">
    <label>دسته</label>
    <select name="cat" data-autosubmit>
      <option value="0">همه</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= $c['id'] ?>" <?= $fCat == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>خودرو</label>
    <select name="veh" data-autosubmit>
      <option value="0">همه</option>
      <?php foreach ($vehs as $v): ?>
        <option value="<?= $v['id'] ?>" <?= $fVeh == $v['id'] ? 'selected' : '' ?>><?= e($v['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>تأمین‌کننده</label>
    <select name="sup" data-autosubmit>
      <option value="0">همه</option>
      <?php foreach ($sups as $s): ?>
        <option value="<?= $s['id'] ?>" <?= $fSup == $s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>وضعیت</label>
    <select name="st" data-autosubmit>
      <option value="">همه</option>
      <?php foreach (['فعال','موقتاً ناموجود','در حال بررسی','حذف‌شده'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $fSt === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>نیازمند توجه</label>
    <select name="flag" data-autosubmit>
      <option value="">—</option>
      <option value="noveh"   <?= $fFlag==='noveh'?'selected':'' ?>>بدون جدول سازگاری</option>
      <option value="nophoto" <?= $fFlag==='nophoto'?'selected':'' ?>>بدون عکس</option>
      <option value="noprice" <?= $fFlag==='noprice'?'selected':'' ?>>بدون قیمت</option>
      <option value="loss"    <?= $fFlag==='loss'?'selected':'' ?>>فروش کمتر از خرید</option>
      <option value="instock" <?= $fFlag==='instock'?'selected':'' ?>>دارای موجودی</option>
    </select>
  </div>
  <div class="fld">
    <label>ترتیب</label>
    <select name="sort" data-autosubmit>
      <option value="new"    <?= $sort==='new'?'selected':'' ?>>جدیدترین</option>
      <option value="name"   <?= $sort==='name'?'selected':'' ?>>نام</option>
      <option value="profit" <?= $sort==='profit'?'selected':'' ?>>بیشترین سود</option>
      <option value="stock"  <?= $sort==='stock'?'selected':'' ?>>موجودی</option>
    </select>
  </div>
  <?php if ($search||$fCat||$fSup||$fVeh||$fSt!==''||$fFlag): ?>
    <a class="btn btn-sm" href="<?= url('products') ?>">پاک کردن</a>
  <?php endif; ?>
</form>

<div class="card">
  <div class="card-b tight">
    <?php if (!$rows): ?>
      <?php empty_box(
        $total === 0 && $search === '' ? 'هنوز کالایی ثبت نشده' : 'نتیجه‌ای یافت نشد',
        $total === 0 && $search === ''
          ? 'اولین کالای کاتالوگ را اضافه کنید. توصیه: از قطعات پرتکرار مثل فیلتر شروع کنید.'
          : 'فیلترها را تغییر دهید.',
        $total === 0 ? '<a class="btn btn-p" href="' . url('product_edit') . '">+ افزودن کالا</a>' : ''
      ); ?>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead>
          <tr>
            <th>کالا</th><th>دسته</th><th>سازگاری</th>
            <th class="num">خرید</th><th class="num">فروش</th><th class="num">سود</th>
            <th class="num">موجودی</th><th class="num">وضعیت</th><th></th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $g = (int)$r['sell_price'] - (int)$r['buy_price'];
            $mg = (int)$r['buy_price'] > 0 ? $g / (int)$r['buy_price'] : 0;
            $vlist = $vehMap[$r['id']] ?? [];
        ?>
          <tr>
            <td>
              <a href="<?= url('product_edit', ['id' => $r['id']]) ?>"><b><?= e(str_limit($r['name'], 38)) ?></b></a>
              <div class="tiny muted">
                <?php if ($r['internal_code']): ?><span class="mono"><?= e($r['internal_code']) ?></span><?php endif; ?>
                <?php if ($r['part_number']): ?> · <span class="mono"><?= e($r['part_number']) ?></span><?php endif; ?>
                <?php if ($r['brand']): ?> · <?= e($r['brand']) ?><?php endif; ?>
              </div>
              <?php if (!$r['has_photo'] || !$vlist): ?>
                <div class="tiny" style="color:#b07500;margin-top:2px">
                  <?= !$vlist ? 'بدون سازگاری' : '' ?>
                  <?= (!$vlist && !$r['has_photo']) ? ' · ' : '' ?>
                  <?= !$r['has_photo'] ? 'بدون عکس' : '' ?>
                </div>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($r['cat_name'] ?: '—') ?></td>
            <td>
              <?php if ($vlist): ?>
                <div class="chips">
                  <?php foreach (array_slice($vlist, 0, 3) as $v): ?>
                    <span class="chip"><?= e($v) ?></span>
                  <?php endforeach; ?>
                  <?php if (count($vlist) > 3): ?>
                    <span class="chip">+<?= fa_digits(count($vlist) - 3) ?></span>
                  <?php endif; ?>
                </div>
              <?php else: ?><span class="muted tiny">—</span><?php endif; ?>
            </td>
            <td class="num"><?= $r['buy_price'] ? money($r['buy_price']) : '—' ?></td>
            <td class="num"><?= $r['sell_price'] ? money($r['sell_price']) : '—' ?></td>
            <td class="num">
              <?php if ($r['buy_price'] && $r['sell_price']): ?>
                <span class="<?= $g < 0 ? 'neg' : ($g > 0 ? 'pos' : '') ?>"><?= money($g) ?></span>
                <div class="tiny muted"><?= pct($mg) ?></div>
              <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td class="num">
              <?= (int)$r['stock_qty'] > 0
                    ? '<b>' . fa_digits($r['stock_qty']) . '</b>'
                    : '<span class="muted tiny">سفارش‌محور</span>' ?>
            </td>
            <td class="num"><?= badge($r['status'], product_status_kind($r['status'])) ?></td>
            <td class="act">
              <a class="btn btn-sm" href="<?= url('product_edit', ['id' => $r['id']]) ?>">ویرایش</a>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="btn btn-sm btn-d" data-confirm="این کالا حذف شود؟ این کار برگشت‌پذیر نیست.">حذف</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="pager">
          <?php
          $qs = fn($n) => url('products', array_filter([
              'q'=>$search,'cat'=>$fCat?:null,'sup'=>$fSup?:null,'veh'=>$fVeh?:null,
              'st'=>$fSt?:null,'flag'=>$fFlag?:null,'sort'=>$sort,'pg'=>$n
          ], fn($v) => $v !== null && $v !== ''));
          if ($page > 1) echo '<a href="' . $qs($page - 1) . '">قبلی</a>';
          for ($i = 1; $i <= $pages; $i++) {
              if ($i == 1 || $i == $pages || abs($i - $page) <= 2) {
                  echo $i == $page
                      ? '<span class="cur">' . fa_digits($i) . '</span>'
                      : '<a href="' . $qs($i) . '">' . fa_digits($i) . '</a>';
              } elseif (abs($i - $page) == 3) {
                  echo '<span class="dots">…</span>';
              }
          }
          if ($page < $pages) echo '<a href="' . $qs($page + 1) . '">بعدی</a>';
          ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php layout_foot(); ?>
