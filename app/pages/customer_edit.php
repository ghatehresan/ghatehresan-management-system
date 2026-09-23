<?php
/** افزودن / ویرایش مشتری */

$id  = get_int('id', 0);
$row = $id ? one("SELECT * FROM customers WHERE id=?", [$id]) : null;
if ($id && !$row) { flash('error', 'مشتری یافت نشد.'); redirect(url('customers')); }

$errors = [];

if (is_post()) {
    csrf_verify();
    auth_require_permission('write_customer');
    $d = [
        'name'    => post('name'),
        'phone'   => post('phone'),
        'city'    => post('city'),
        'address' => post('address'),
        'ctype'   => post('ctype', 'مصرف‌کننده'),
        'vehicle' => post('vehicle'),
        'source'  => post('source'),
        'notes'   => post('notes'),
    ];
    if ($d['name'] === '') $errors[] = 'نام مشتری الزامی است.';

    if (!$errors) {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($d)));
            q("UPDATE customers SET $set WHERE id=?", [...array_values($d), $id]);
            flash('ok', 'به‌روزرسانی شد.');
        } else {
            $cols = implode(', ', array_keys($d));
            $ph   = implode(',', array_fill(0, count($d), '?'));
            q("INSERT INTO customers ($cols) VALUES ($ph)", array_values($d));
            flash('ok', 'مشتری ثبت شد.');
        }
        redirect(url('customers'));
    }
    $row = array_merge($row ?: [], $d, ['id' => $id]);
}

$v = fn($k, $d = '') => e($row[$k] ?? $d);

$orders = $id
    ? all("SELECT * FROM orders WHERE customer_id=? ORDER BY order_date DESC LIMIT 40", [$id])
    : [];
$rev = $net = 0;
foreach ($orders as $o) {
    if ($o['status'] === 'لغو شد' || $o['is_returned']) continue;
    $p = order_profit($o); $rev += $p['revenue']; $net += $p['net'];
}

layout_head($id ? 'ویرایش مشتری' : 'مشتری جدید');
page_head($id ? e($row['name']) : 'افزودن مشتری',
          $id ? 'پروندهٔ مشتری' : 'ثبت مشتری جدید',
          '<a class="btn" href="' . url('customers') . '">بازگشت</a>');

foreach ($errors as $er) echo '<div class="flash f-red">' . e($er) . '</div>';
?>

<?php if ($id && $orders): ?>
<div class="stats g4">
  <div class="stat"><div class="lb">تعداد سفارش</div><div class="vl"><?= fa_digits(count($orders)) ?></div></div>
  <div class="stat"><div class="lb">مجموع خرید</div><div class="vl sm"><?= money($rev) ?></div></div>
  <div class="stat <?= $net < 0 ? 'neg' : 'pos' ?>"><div class="lb">سود خالص از این مشتری</div>
    <div class="vl sm"><?= money($net) ?></div></div>
  <div class="stat"><div class="lb">میانگین هر سفارش</div>
    <div class="vl sm"><?= money(count($orders) ? (int)round($rev / count($orders)) : 0) ?></div></div>
</div>
<?php endif; ?>

<form method="post" class="frm">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-h"><h2>اطلاعات مشتری</h2></div>
    <div class="card-b frm">
      <div class="row r3">
        <div class="fld"><label>نام *</label>
          <input type="text" name="name" value="<?= $v('name') ?>" required autofocus></div>
        <div class="fld"><label>شمارهٔ تماس</label>
          <input type="tel" name="phone" value="<?= $v('phone') ?>" class="mono" dir="ltr"></div>
        <div class="fld">
          <label>نوع مشتری</label>
          <select name="ctype">
            <?php foreach (['مصرف‌کننده','تعمیرکار','عمده‌فروش','نمایشگاه','سایر'] as $t): ?>
              <option value="<?= e($t) ?>" <?= ($row['ctype'] ?? 'مصرف‌کننده') === $t ? 'selected' : '' ?>>
                <?= e($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="row r3">
        <div class="fld"><label>شهر</label>
          <input type="text" name="city" value="<?= $v('city') ?>"></div>
        <div class="fld"><label>خودرو</label>
          <input type="text" name="vehicle" value="<?= $v('vehicle') ?>" placeholder="پژو ۲۰۶ تیپ ۲"></div>
        <div class="fld">
          <label>از کجا آشنا شد</label>
          <select name="source">
            <option value="">—</option>
            <?php foreach (['سایت','اینستاگرام','تلگرام','واتساپ','معرفی دوستان',
                            'ترب','دیوار','حضوری','سایر'] as $s): ?>
              <option value="<?= e($s) ?>" <?= ($row['source'] ?? '') === $s ? 'selected' : '' ?>>
                <?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fld"><label>آدرس</label>
        <input type="text" name="address" value="<?= $v('address') ?>"></div>
      <div class="fld"><label>یادداشت</label>
        <textarea name="notes" placeholder="ترجیحات، سابقه، نکات"><?= $v('notes') ?></textarea></div>
    </div>
  </div>

  <div class="frm-ft">
    <button class="btn btn-p"><?= $id ? 'ذخیرهٔ تغییرات' : 'ثبت مشتری' ?></button>
    <a class="btn" href="<?= url('customers') ?>">انصراف</a>
  </div>
</form>

<?php if ($id && $orders): ?>
<div class="card">
  <div class="card-h"><h2>سابقهٔ سفارش‌ها</h2></div>
  <div class="card-b tight">
    <div class="tw">
    <table>
      <thead><tr><th>شماره</th><th>تاریخ</th><th class="num">مبلغ</th>
                 <th class="num">سود</th><th class="num">وضعیت</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($orders as $o): $p = order_profit($o); ?>
        <tr>
          <td class="mono small"><?= e($o['order_no']) ?></td>
          <td class="small"><?= jdate($o['order_date']) ?></td>
          <td class="num"><?= money($p['revenue']) ?></td>
          <td class="num <?= $p['net'] < 0 ? 'neg' : 'pos' ?>"><?= money($p['net']) ?></td>
          <td class="num"><?= badge($o['status'], order_status_kind($o['status'])) ?></td>
          <td class="act"><a class="btn btn-sm" href="<?= url('order_view', ['id' => $o['id']]) ?>">مشاهده</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php layout_foot(); ?>
