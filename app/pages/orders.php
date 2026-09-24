<?php
/** فهرست سفارش‌ها */

if (is_post() && post('action') === 'delete') {
    csrf_verify();
    auth_require_permission('delete_order');
    $id = post_int('id');
    $order = one("SELECT order_no FROM orders WHERE id=?", [$id]);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        remove_order_stock_moves($id, $order['order_no'] ?? null);
        q("DELETE FROM order_items WHERE order_id=?", [$id]);
        q("DELETE FROM orders WHERE id=?", [$id]);
        $pdo->commit();
        activity_log('order_deleted', 'order', $id);
        flash('ok', 'سفارش حذف شد و موجودی اصلاح شد.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'حذف سفارش انجام نشد.');
    }
    redirect(url('orders'));
}
if (is_post() && post('action') === 'status') {
    csrf_verify();
    auth_require_permission('change_order_status');
    $id = post_int('id');
    $status = post('status');
    if (!in_array($status, ['ثبت شد','خرید شد','ارسال شد','تحویل شد','لغو شد'], true)) {
        flash('error', 'وضعیت سفارش معتبر نیست.');
    } else {
        $pdo = db();
        try {
            $pdo->beginTransaction();
            q("UPDATE orders SET status=?, updated_at=? WHERE id=?",
              [$status, date('Y-m-d H:i:s'), $id]);
            sync_order_stock($id);
            $pdo->commit();
            activity_log('order_status_changed', 'order', $id, 'status=' . $status);
            flash('ok', 'وضعیت سفارش تغییر کرد و موجودی همگام شد.');
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('error', 'تغییر وضعیت سفارش انجام نشد.');
        }
    }
    redirect(url('orders', array_filter(['st' => get('st'), 'pg' => get_int('pg', 1) ?: null])));
}

$search = get('q');
$fSt    = get('st');
$fRet   = get('ret');
$from   = jalali_str_to_ymd(get('from'));
$to     = jalali_str_to_ymd(get('to'));
$page   = max(1, get_int('pg', 1));
$per    = 25;

$w = []; $prm = [];
if ($search !== '') {
    $w[] = "(o.order_no LIKE ? OR o.customer_name LIKE ? OR o.city LIKE ?)";
    $like = '%' . $search . '%';
    array_push($prm, $like, $like, $like);
}
if ($fSt !== '')  { $w[] = "o.status = ?"; $prm[] = $fSt; }
if ($fRet === '1') $w[] = "o.is_returned = 1";
if ($from) { $w[] = "o.order_date >= ?"; $prm[] = $from; }
if ($to)   { $w[] = "o.order_date <= ?"; $prm[] = $to; }
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$total = (int)scalar("SELECT COUNT(*) FROM orders o $where", $prm);
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page - 1) * $per;

$rows = all("SELECT o.*, c.name AS cust_name
               FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
               $where ORDER BY o.order_date DESC, o.id DESC
               LIMIT $per OFFSET $off", $prm);

// جمع کل با فیلتر فعلی
$allF = all("SELECT o.* FROM orders o $where", $prm);
$sumRev = $sumNet = 0; $cntRet = 0;
foreach ($allF as $o) {
    if ($o['status'] === 'لغو شد') continue;
    if ($o['is_returned']) { $cntRet++; continue; }
    $pr = order_profit($o);
    $sumRev += $pr['revenue']; $sumNet += $pr['net'];
}

layout_head('سفارش‌ها');
page_head('سفارش‌ها', fa_digits($total) . ' سفارش',
    '<a class="btn btn-p" href="' . url('order_edit') . '">+ سفارش جدید</a>'
  . '<a class="btn" href="' . url('export', ['t' => 'orders']) . '">خروجی CSV</a>');
?>

<div class="stats g4">
  <div class="stat"><div class="lb">تعداد</div><div class="vl"><?= fa_digits($total) ?></div></div>
  <div class="stat"><div class="lb">مجموع فروش</div><div class="vl sm"><?= money($sumRev) ?></div></div>
  <div class="stat <?= $sumNet < 0 ? 'neg' : 'pos' ?>">
    <div class="lb">مجموع سود خالص</div><div class="vl sm"><?= money($sumNet) ?></div>
    <div class="hint"><?= $sumRev > 0 ? 'حاشیه ' . pct($sumNet / $sumRev) : '' ?></div></div>
  <div class="stat <?= $cntRet > 0 ? 'neg' : '' ?>">
    <div class="lb">مرجوعی</div><div class="vl"><?= fa_digits($cntRet) ?></div>
    <div class="hint"><?= $total ? pct($cntRet / max(1,$total)) : '—' ?></div></div>
</div>

<form class="filters" method="get">
  <input type="hidden" name="p" value="orders">
  <div class="fld wide">
    <label>جست‌وجو</label>
    <input type="search" name="q" value="<?= e($search) ?>" data-livesearch
           placeholder="شمارهٔ سفارش، نام مشتری یا شهر">
  </div>
  <div class="fld">
    <label>وضعیت</label>
    <select name="st" data-autosubmit>
      <option value="">همه</option>
      <?php foreach (['ثبت شد','خرید شد','ارسال شد','تحویل شد','لغو شد'] as $s): ?>
        <option value="<?= e($s) ?>" <?= $fSt === $s ? 'selected' : '' ?>><?= e($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="fld">
    <label>از تاریخ</label>
    <input type="text" name="from" value="<?= e(get('from')) ?>" placeholder="۱۴۰۵/۰۶/۰۱" class="mono">
  </div>
  <div class="fld">
    <label>تا تاریخ</label>
    <input type="text" name="to" value="<?= e(get('to')) ?>" placeholder="۱۴۰۵/۰۶/۳۱" class="mono">
  </div>
  <div class="fld">
    <label>مرجوعی</label>
    <select name="ret" data-autosubmit>
      <option value="">همه</option>
      <option value="1" <?= $fRet === '1' ? 'selected' : '' ?>>فقط مرجوعی‌ها</option>
    </select>
  </div>
  <button class="btn btn-sm btn-n" type="submit">اعمال</button>
  <?php if ($search||$fSt!==''||$fRet||get('from')||get('to')): ?>
    <a class="btn btn-sm" href="<?= url('orders') ?>">پاک کردن</a>
  <?php endif; ?>
</form>

<div class="card">
  <div class="card-b tight">
    <?php if (!$rows): ?>
      <?php empty_box(
        $total === 0 ? 'هنوز سفارشی ثبت نشده' : 'نتیجه‌ای یافت نشد',
        $total === 0 ? 'اولین سفارش را ثبت کنید تا محاسبهٔ سود خودکار شروع شود.' : 'فیلترها را تغییر دهید.',
        $total === 0 ? '<a class="btn btn-p" href="' . url('order_edit') . '">+ ثبت سفارش</a>' : ''
      ); ?>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead>
          <tr><th>شماره</th><th>تاریخ</th><th>مشتری</th>
              <th class="num">اقلام</th><th class="num">فروش</th>
              <th class="num">سود خالص</th><th class="num">حاشیه</th>
              <th class="num">وضعیت</th><th></th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $o):
            $pr = order_profit($o);
            $nm = $o['customer_name'] ?: ($o['cust_name'] ?: '—');
        ?>
          <tr>
            <td>
              <a href="<?= url('order_view', ['id' => $o['id']]) ?>">
                <b class="mono"><?= e($o['order_no'] ?: '#' . $o['id']) ?></b></a>
              <?php if ($o['is_returned']): ?>
                <div><?= badge('مرجوعی', 'red') ?></div>
              <?php endif; ?>
            </td>
            <td class="small nowrap"><?= jdate($o['order_date']) ?></td>
            <td>
              <?= e(str_limit($nm, 20)) ?>
              <?php if ($o['city']): ?><div class="tiny muted"><?= e($o['city']) ?></div><?php endif; ?>
            </td>
            <td class="num"><?= fa_digits($pr['units']) ?></td>
            <td class="num"><?= money($pr['revenue']) ?></td>
            <td class="num <?= $pr['net'] < 0 ? 'neg' : ($pr['net'] > 0 ? 'pos' : '') ?>">
              <?= money($pr['net']) ?></td>
            <td class="num small"><?= $pr['revenue'] > 0 ? pct($pr['margin']) : '—' ?></td>
            <td class="num">
              <?php if (auth_can('change_order_status')): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="status">
                  <input type="hidden" name="id" value="<?= $o['id'] ?>">
                  <select name="status" data-autosubmit
                          style="font-size:12px;padding:3px 7px;border-radius:7px;width:auto">
                    <?php foreach (['ثبت شد','خرید شد','ارسال شد','تحویل شد','لغو شد'] as $s): ?>
                      <option value="<?= e($s) ?>" <?= $o['status'] === $s ? 'selected' : '' ?>>
                        <?= e($s) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php else: ?>
                <?= badge($o['status'], order_status_kind($o['status'])) ?>
              <?php endif; ?>
            </td>
            <td class="act">
              <a class="btn btn-sm" href="<?= url('order_view', ['id' => $o['id']]) ?>">مشاهده</a>
              <?php if (auth_can('write_order')): ?>
                <a class="btn btn-sm" href="<?= url('order_edit', ['id' => $o['id']]) ?>">ویرایش</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>

      <?php if ($pages > 1): ?>
        <div class="pager">
          <?php
          $qs = fn($n) => url('orders', array_filter([
              'q'=>$search,'st'=>$fSt?:null,'ret'=>$fRet?:null,
              'from'=>get('from')?:null,'to'=>get('to')?:null,'pg'=>$n
          ], fn($x) => $x !== null && $x !== ''));
          if ($page > 1) echo '<a href="' . $qs($page-1) . '">قبلی</a>';
          for ($i = 1; $i <= $pages; $i++) {
              if ($i == 1 || $i == $pages || abs($i - $page) <= 2) {
                  echo $i == $page ? '<span class="cur">' . fa_digits($i) . '</span>'
                                   : '<a href="' . $qs($i) . '">' . fa_digits($i) . '</a>';
              } elseif (abs($i - $page) == 3) echo '<span class="dots">…</span>';
          }
          if ($page < $pages) echo '<a href="' . $qs($page+1) . '">بعدی</a>';
          ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php layout_foot(); ?>
