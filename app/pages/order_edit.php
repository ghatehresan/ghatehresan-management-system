<?php
/** افزودن / ویرایش سفارش */

$id  = get_int('id', 0);
$row = $id ? one("SELECT * FROM orders WHERE id=?", [$id]) : null;
if ($id && !$row) { flash('error', 'سفارش یافت نشد.'); redirect(url('orders')); }

$items = $id ? all("SELECT * FROM order_items WHERE order_id=? ORDER BY id", [$id]) : [];
$errors = [];
$previousOrderNo = $row['order_no'] ?? null;

if (is_post()) {
    csrf_verify();
    auth_require_permission('write_order');

    $od = jalali_str_to_ymd(post('order_date')) ?: date('Y-m-d');
    $data = [
        'order_no'       => post('order_no'),
        'order_date'     => $od,
        'customer_id'    => post_int('customer_id') ?: null,
        'customer_name'  => post('customer_name'),
        'city'           => post('city'),
        'status'         => post('status', 'ثبت شد'),
        'gateway_fee'    => post_int('gateway_fee'),
        'shipping_cost'  => post_int('shipping_cost'),
        'packaging_cost' => post_int('packaging_cost'),
        'is_returned'    => post('is_returned') ? 1 : 0,
        'return_reason'  => post('return_reason'),
        'notes'          => post('notes'),
    ];

    // اقلام
    $pIds  = (array)($_POST['item_product'] ?? []);
    $pNms  = (array)($_POST['item_name'] ?? []);
    $qtys  = (array)($_POST['item_qty'] ?? []);
    $buys  = (array)($_POST['item_buy'] ?? []);
    $sells = (array)($_POST['item_sell'] ?? []);

    $clean = [];
    foreach ($qtys as $i => $qq) {
        $pid = (int)($pIds[$i] ?? 0);
        $nm  = trim((string)($pNms[$i] ?? ''));
        $qty = to_int($qq);
        if ($qty <= 0) continue;
        if (!$pid && $nm === '') continue;
        if ($pid && $nm === '') {
            $pp = one("SELECT name FROM products WHERE id=?", [$pid]);
            $nm = $pp['name'] ?? '';
        }
        $clean[] = [
            'product_id'   => $pid ?: null,
            'product_name' => $nm,
            'qty'          => $qty,
            'buy_price'    => to_int($buys[$i] ?? 0),
            'sell_price'   => to_int($sells[$i] ?? 0),
        ];
    }

    if (trim((string)post('gateway_fee')) === '') {
        $rate = (float)setting('gateway_fee_percent', setting('gateway_fee_pct', 0));
        $gross = 0;
        foreach ($clean as $it) $gross += $it['sell_price'] * $it['qty'];
        $data['gateway_fee'] = (int)round($gross * max(0, $rate) / 100);
    }

    if (!in_array($data['status'], ['ثبت شد','خرید شد','ارسال شد','تحویل شد','لغو شد'], true)) {
        $errors[] = 'وضعیت سفارش معتبر نیست.';
    }
    if (!$clean) $errors[] = 'حداقل یک قلم کالا با تعداد معتبر وارد کنید.';
    if ($data['customer_name'] === '' && !$data['customer_id']) {
        $errors[] = 'نام مشتری یا انتخاب از فهرست مشتریان الزامی است.';
    }

    if (!$errors) {
        $pdo = db();
        try {
            $pdo->beginTransaction();

            if ($id) {
                q("UPDATE orders SET order_no=?, order_date=?, customer_id=?, customer_name=?,
                      city=?, status=?, gateway_fee=?, shipping_cost=?, packaging_cost=?,
                      is_returned=?, return_reason=?, notes=? WHERE id=?",
                  [...array_values($data), $id]);
                $oid = $id;
                q("DELETE FROM order_items WHERE order_id=?", [$oid]);
            } else {
                if ($data['order_no'] === '') {
                    $n = (int)scalar("SELECT COUNT(*) FROM orders") + 1;
                    $prefix = preg_replace('/[^A-Za-z0-9_-]/', '',
                        (string)setting('order_prefix', 'GR')) ?: 'GR';
                    $data['order_no'] = sprintf('%s-%s-%03d', $prefix, date('ym'), $n);
                }
                q("INSERT INTO orders (order_no, order_date, customer_id, customer_name, city,
                       status, gateway_fee, shipping_cost, packaging_cost, is_returned,
                       return_reason, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
                  array_values($data));
                $oid = (int)db()->lastInsertId();
            }

            $st = db()->prepare("INSERT INTO order_items
                  (order_id, product_id, product_name, qty, buy_price, sell_price)
                  VALUES (?,?,?,?,?,?)");
            foreach ($clean as $it) {
                $st->execute([$oid, $it['product_id'], $it['product_name'],
                              $it['qty'], $it['buy_price'], $it['sell_price']]);
            }

            // هر بار وضعیت واقعی سفارش با کاردکس همگام می‌شود.
            sync_order_stock($oid, $id ? $previousOrderNo : null);
            $pdo->commit();
            activity_log($id ? 'order_updated' : 'order_created', 'order', $oid,
                'order_no=' . $data['order_no'] . ';status=' . $data['status']);
            flash('ok', $id ? 'سفارش به‌روزرسانی شد.' : 'سفارش ثبت شد.');
            redirect(url('order_view', ['id' => $oid]));
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = 'ذخیرهٔ سفارش انجام نشد. دوباره تلاش کنید.';
        }
    }

    $row = array_merge($row ?: [], $data, ['id' => $id]);
    $items = [];
    foreach ($clean as $c) $items[] = $c;
}

$v = fn($k, $d = '') => e($row[$k] ?? $d);

$products = all("SELECT id, name, part_number, buy_price, sell_price, stock_qty
                   FROM products WHERE status <> 'حذف‌شده' ORDER BY name");
$customers = all("SELECT * FROM customers ORDER BY name");

$defDate = (isset($row['order_date']) && $row['order_date']) ? $row['order_date'] : date('Y-m-d');
[$djy, $djm, $djd] = (isset($row['order_date']) && $row['order_date'])
    ? gregorian_to_jalali((int)date('Y', strtotime($defDate)),
                          (int)date('n', strtotime($defDate)),
                          (int)date('j', strtotime($defDate)))
    : jtoday();
$defDateStr = sprintf('%04d/%02d/%02d', $djy, $djm, $djd);

layout_head($id ? 'ویرایش سفارش' : 'سفارش جدید');
page_head($id ? 'ویرایش سفارش' : 'ثبت سفارش جدید',
          $id ? ($row['order_no'] ?: '#' . $id) : 'سود خالص همزمان محاسبه می‌شود',
          '<a class="btn" href="' . url('orders') . '">بازگشت</a>');

foreach ($errors as $er) echo '<div class="flash f-red">' . e($er) . '</div>';
?>

<form method="post" class="frm" id="orderForm">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card-h"><h2>اطلاعات سفارش</h2></div>
    <div class="card-b frm">
      <div class="row r4">
        <div class="fld">
          <label>شمارهٔ سفارش</label>
          <input type="text" name="order_no" value="<?= $v('order_no') ?>" class="mono" placeholder="خودکار">
        </div>
        <div class="fld">
          <label>تاریخ <span class="opt">(شمسی)</span></label>
          <input type="text" name="order_date" value="<?= e($defDateStr) ?>" class="mono" placeholder="۱۴۰۵/۰۶/۳۰">
        </div>
        <div class="fld">
          <label>شهر</label>
          <input type="text" name="city" value="<?= $v('city') ?>" placeholder="تهران">
        </div>
        <div class="fld">
          <label>وضعیت</label>
          <select name="status">
            <?php foreach (['ثبت شد','خرید شد','ارسال شد','تحویل شد','لغو شد'] as $s): ?>
              <option value="<?= e($s) ?>" <?= ($row['status'] ?? 'ثبت شد') === $s ? 'selected' : '' ?>>
                <?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row r2">
        <div class="fld">
          <label>نام مشتری *</label>
          <input type="text" name="customer_name" value="<?= $v('customer_name') ?>" placeholder="نام و نام خانوادگی">
        </div>
        <div class="fld">
          <label>یا انتخاب از مشتریان ثبت‌شده</label>
          <select name="customer_id">
            <option value="">—</option>
            <?php foreach ($customers as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)($row['customer_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?><?= $c['phone'] ? ' — ' . e($c['phone']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h">
      <h2>اقلام سفارش</h2>
      <button type="button" class="btn btn-sm btn-n" id="addItem">+ افزودن قلم</button>
    </div>
    <div class="card-b tight">
      <div class="tw">
      <table>
        <thead>
          <tr><th style="min-width:210px">کالا</th><th style="min-width:145px">یا نام دستی</th>
              <th class="num" style="width:82px">تعداد</th>
              <th class="num" style="width:125px">قیمت خرید</th>
              <th class="num" style="width:125px">قیمت فروش</th><th style="width:52px"></th></tr>
        </thead>
        <tbody id="itemsBody">
        <?php
        $rowsToRender = $items ?: [['product_id'=>null,'product_name'=>'','qty'=>1,'buy_price'=>0,'sell_price'=>0]];
        foreach ($rowsToRender as $i => $it): ?>
          <tr data-item>
            <td>
              <select name="item_product[]" data-f="product">
                <option value="">— انتخاب کالا —</option>
                <?php foreach ($products as $pp): ?>
                  <option value="<?= $pp['id'] ?>"
                          data-buy="<?= (int)$pp['buy_price'] ?>"
                          data-sell="<?= (int)$pp['sell_price'] ?>"
                          <?= (int)($it['product_id'] ?? 0) === (int)$pp['id'] ? 'selected' : '' ?>>
                    <?= e(str_limit($pp['name'], 40)) ?>
                    <?= $pp['part_number'] ? ' · ' . e($pp['part_number']) : '' ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </td>
            <td><input type="text" name="item_name[]" value="<?= e($it['product_name'] ?? '') ?>"
                       placeholder="کالای خارج از کاتالوگ"></td>
            <td><input type="text" name="item_qty[]" value="<?= (int)($it['qty'] ?? 1) ?>"
                       data-f="qty" inputmode="numeric" style="text-align:center"></td>
            <td><input type="text" name="item_buy[]" class="money" data-f="buy"
                       value="<?= (int)($it['buy_price'] ?? 0) ?: '' ?>" placeholder="0"></td>
            <td><input type="text" name="item_sell[]" class="money" data-f="sell"
                       value="<?= (int)($it['sell_price'] ?? 0) ?: '' ?>" placeholder="0"></td>
            <td><button type="button" class="btn btn-sm btn-d" data-f="del">×</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>

  <template id="itemTpl">
    <td>
      <select name="item_product[]" data-f="product">
        <option value="">— انتخاب کالا —</option>
        <?php foreach ($products as $pp): ?>
          <option value="<?= $pp['id'] ?>" data-buy="<?= (int)$pp['buy_price'] ?>"
                  data-sell="<?= (int)$pp['sell_price'] ?>">
            <?= e(str_limit($pp['name'], 40)) ?><?= $pp['part_number'] ? ' · ' . e($pp['part_number']) : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input type="text" name="item_name[]" placeholder="کالای خارج از کاتالوگ"></td>
    <td><input type="text" name="item_qty[]" value="1" data-f="qty" inputmode="numeric" style="text-align:center"></td>
    <td><input type="text" name="item_buy[]" class="money" data-f="buy" placeholder="0"></td>
    <td><input type="text" name="item_sell[]" class="money" data-f="sell" placeholder="0"></td>
    <td><button type="button" class="btn btn-sm btn-d" data-f="del">×</button></td>
  </template>

  <div class="card">
    <div class="card-h"><h2>هزینه‌های سفارش</h2>
      <span class="sub">همان مواردی که در فرمول سود خالص سند استراتژی آمده</span></div>
    <div class="card-b frm">
      <div class="row r3">
        <div class="fld">
          <label>کارمزد درگاه پرداخت</label>
          <input type="text" id="gateway_fee" name="gateway_fee" class="money"
                 value="<?= (int)($row['gateway_fee'] ?? 0) ?: '' ?>" placeholder="0">
        </div>
        <div class="fld">
          <label>هزینهٔ ارسال <span class="opt">(سهم شما)</span></label>
          <input type="text" id="shipping_cost" name="shipping_cost" class="money"
                 value="<?= (int)($row['shipping_cost'] ?? 0) ?: '' ?>" placeholder="0">
        </div>
        <div class="fld">
          <label>هزینهٔ بسته‌بندی</label>
          <input type="text" id="packaging_cost" name="packaging_cost" class="money"
                 value="<?= (int)($row['packaging_cost'] ?? (int)setting('packaging_cost', 0)) ?: '' ?>"
                 placeholder="0">
        </div>
      </div>
      <div id="orderCalc"></div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>مرجوعی و یادداشت</h2></div>
    <div class="card-b frm">
      <div class="fld">
        <label class="chk" style="display:inline-flex">
          <input type="checkbox" name="is_returned" value="1" <?= !empty($row['is_returned']) ? 'checked' : '' ?>>
          این سفارش مرجوع شده است
        </label>
      </div>
      <div class="fld">
        <label>علت مرجوعی</label>
        <input type="text" name="return_reason" value="<?= $v('return_reason') ?>"
               placeholder="مثال: به خودرو نخورد — سازگاری اشتباه بود">
        <div class="hint">ثبت علت، ریشه‌یابی را ممکن می‌کند. اغلب مشکل از جدول سازگاری است.</div>
      </div>
      <div class="fld">
        <label>یادداشت</label>
        <textarea name="notes"><?= $v('notes') ?></textarea>
      </div>
    </div>
  </div>

  <div class="frm-ft">
    <button class="btn btn-p" type="submit"><?= $id ? 'ذخیرهٔ تغییرات' : 'ثبت سفارش' ?></button>
    <a class="btn" href="<?= url('orders') ?>">انصراف</a>
  </div>
</form>

<?php layout_foot(); ?>
