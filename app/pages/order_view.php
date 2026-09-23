<?php
/** مشاهدهٔ سفارش و فاکتور */

$id = get_int('id', 0);
$o  = $id ? one("SELECT * FROM orders WHERE id=?", [$id]) : null;
if (!$o) { flash('error', 'سفارش یافت نشد.'); redirect(url('orders')); }

if (is_post() && post('action') === 'delete') {
    csrf_verify();
    $pdo = db();
    try {
        $pdo->beginTransaction();
        remove_order_stock_moves($id, $o['order_no'] ?? null);
        q("DELETE FROM order_items WHERE order_id=?", [$id]);
        q("DELETE FROM orders WHERE id=?", [$id]);
        $pdo->commit();
        flash('ok', 'سفارش حذف شد و موجودی اصلاح شد.');
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', 'حذف سفارش انجام نشد.');
    }
    redirect(url('orders'));
}

$items = all("SELECT * FROM order_items WHERE order_id=? ORDER BY id", [$id]);
$pr    = order_profit($o);
$cust  = $o['customer_id'] ? one("SELECT * FROM customers WHERE id=?", [$o['customer_id']]) : null;
$name  = $o['customer_name'] ?: ($cust['name'] ?? '—');

layout_head('سفارش ' . ($o['order_no'] ?: '#' . $id));
page_head('سفارش ' . ($o['order_no'] ?: '#' . $id), jdate($o['order_date'], 'long'),
    '<button class="btn" onclick="window.print()">چاپ</button>'
  . '<a class="btn" href="' . url('order_edit', ['id' => $id]) . '">ویرایش</a>'
  . '<a class="btn" href="' . url('orders') . '">بازگشت</a>');
?>

<?php if ($o['is_returned']): ?>
  <div class="note n-red">
    <b>این سفارش مرجوع شده است</b>
    <?= $o['return_reason'] ? e($o['return_reason']) : 'علت ثبت نشده است.' ?>
  </div>
<?php endif; ?>

<?php if ($pr['net'] < 0): ?>
  <div class="note n-red">
    <b>این سفارش ضررده است</b>
    مجموع بهای کالا و هزینه‌ها از مبلغ فروش بیشتر است.
  </div>
<?php endif; ?>

<div class="stats g4">
  <div class="stat"><div class="lb">مبلغ فروش</div><div class="vl sm"><?= money($pr['revenue']) ?></div></div>
  <div class="stat"><div class="lb">بهای کالا</div><div class="vl sm"><?= money($pr['cost']) ?></div></div>
  <div class="stat"><div class="lb">سود ناخالص</div><div class="vl sm"><?= money($pr['gross']) ?></div></div>
  <div class="stat <?= $pr['net'] < 0 ? 'neg' : 'pos' ?>">
    <div class="lb">سود خالص</div><div class="vl sm"><?= money($pr['net']) ?></div>
    <div class="hint"><?= $pr['revenue'] > 0 ? 'حاشیه ' . pct($pr['margin']) : '' ?></div>
  </div>
</div>

<div class="card">
  <div class="card-h">
    <h2>اطلاعات سفارش</h2>
    <?= badge($o['status'], order_status_kind($o['status'])) ?>
  </div>
  <div class="card-b">
    <dl class="kv">
      <dt>مشتری</dt><dd><?= e($name) ?></dd>
      <?php if ($cust && $cust['phone']): ?>
        <dt>تماس</dt><dd class="mono"><?= e($cust['phone']) ?></dd>
      <?php endif; ?>
      <?php if ($o['city']): ?><dt>شهر</dt><dd><?= e($o['city']) ?></dd><?php endif; ?>
      <dt>تاریخ</dt><dd><?= jdate($o['order_date'], 'long') ?></dd>
      <dt>ثبت در سامانه</dt><dd class="small muted"><?= jdate($o['created_at'], 'full') ?></dd>
      <?php if ($o['notes']): ?><dt>یادداشت</dt><dd><?= nl2br(e($o['notes'])) ?></dd><?php endif; ?>
    </dl>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>اقلام</h2></div>
  <div class="card-b tight">
    <div class="tw">
    <table>
      <thead>
        <tr><th>کالا</th><th class="num">تعداد</th><th class="num">خرید واحد</th>
            <th class="num">فروش واحد</th><th class="num">جمع فروش</th><th class="num">سود</th></tr>
      </thead>
      <tbody>
      <?php foreach ($items as $it):
          $line = (int)$it['sell_price'] * (int)$it['qty'];
          $lg   = ((int)$it['sell_price'] - (int)$it['buy_price']) * (int)$it['qty'];
      ?>
        <tr>
          <td>
            <?php if ($it['product_id']): ?>
              <a href="<?= url('product_edit', ['id' => $it['product_id']]) ?>">
                <b><?= e($it['product_name']) ?></b></a>
            <?php else: ?>
              <b><?= e($it['product_name']) ?></b>
              <span class="tiny muted">(خارج از کاتالوگ)</span>
            <?php endif; ?>
          </td>
          <td class="num"><?= fa_digits($it['qty']) ?></td>
          <td class="num"><?= money($it['buy_price']) ?></td>
          <td class="num"><?= money($it['sell_price']) ?></td>
          <td class="num"><b><?= money($line) ?></b></td>
          <td class="num <?= $lg < 0 ? 'neg' : 'pos' ?>"><?= money($lg) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>تفکیک سود خالص</h2></div>
  <div class="card-b">
    <div class="tw">
    <table>
      <tbody>
        <tr><td>مبلغ فروش</td><td class="num tl"><b class="pos"><?= money($pr['revenue']) ?></b></td></tr>
        <tr><td>− بهای خرید کالا</td><td class="num tl"><?= money($pr['cost']) ?></td></tr>
        <tr><td>− کارمزد درگاه پرداخت</td><td class="num tl"><?= money($o['gateway_fee']) ?></td></tr>
        <tr><td>− هزینهٔ ارسال</td><td class="num tl"><?= money($o['shipping_cost']) ?></td></tr>
        <tr><td>− هزینهٔ بسته‌بندی</td><td class="num tl"><?= money($o['packaging_cost']) ?></td></tr>
        <tr style="background:var(--paper)">
          <td><b>سود خالص</b></td>
          <td class="num tl"><b class="<?= $pr['net'] < 0 ? 'neg' : 'pos' ?>" style="font-size:16px">
            <?= money($pr['net']) ?> تومان</b></td>
        </tr>
      </tbody>
    </table>
    </div>
  </div>
</div>

<div class="no-print" style="display:flex;gap:9px;flex-wrap:wrap">
  <a class="btn btn-p" href="<?= url('order_edit', ['id' => $id]) ?>">ویرایش سفارش</a>
  <form method="post" style="display:inline">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="delete">
    <button class="btn btn-d" data-confirm="این سفارش حذف شود؟ برگشت‌پذیر نیست.">حذف سفارش</button>
  </form>
</div>

<?php layout_foot(); ?>
