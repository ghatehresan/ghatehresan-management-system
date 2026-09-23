<?php
/** افزودن / ویرایش کالا */

$id  = get_int('id', 0);
$row = $id ? one("SELECT * FROM products WHERE id=?", [$id]) : null;
if ($id && !$row) { flash('error', 'کالا یافت نشد.'); redirect(url('products')); }

$selVeh = [];
if ($id) {
    foreach (all("SELECT vehicle_id FROM product_vehicles WHERE product_id=?", [$id]) as $r) {
        $selVeh[] = (int)$r['vehicle_id'];
    }
}

$errors = [];

if (is_post()) {
    csrf_verify();

    $data = [
        'internal_code' => post('internal_code'),
        'part_number'   => post('part_number'),
        'name'          => post('name'),
        'category_id'   => post_int('category_id') ?: null,
        'brand'         => post('brand'),
        'supplier_id'   => post_int('supplier_id') ?: null,
        'buy_price'     => post_int('buy_price'),
        'sell_price'    => post_int('sell_price'),
        'status'        => post('status', 'فعال'),
        'lead_days'     => post_int('lead_days'),
        'has_photo'     => post('has_photo') ? 1 : 0,
        'year_engine'   => post('year_engine'),
        'notes'         => post('notes'),
    ];
    $vehIds  = array_map('intval', (array)($_POST['vehicles'] ?? []));
    $initQty = post_int('init_stock');

    if ($data['name'] === '') $errors[] = 'نام قطعه الزامی است.';
    if ($data['sell_price'] > 0 && $data['buy_price'] > 0
        && $data['sell_price'] < $data['buy_price']) {
        flash('warn', 'توجه: قیمت فروش از قیمت خرید کمتر است — این کالا ضررده خواهد بود.');
    }

    if (!$errors) {
        if ($id) {
            q("UPDATE products SET internal_code=?, part_number=?, name=?, category_id=?,
                  brand=?, supplier_id=?, buy_price=?, sell_price=?, status=?, lead_days=?,
                  has_photo=?, year_engine=?, notes=?, updated_at=?
               WHERE id=?",
              [...array_values($data), date('Y-m-d H:i:s'), $id]);
            $pid = $id;
            flash('ok', 'کالا به‌روزرسانی شد.');
        } else {
            if ($data['internal_code'] === '') {
                $data['internal_code'] = next_internal_code(db());
            }
            q("INSERT INTO products
                 (internal_code, part_number, name, category_id, brand, supplier_id,
                  buy_price, sell_price, status, lead_days, has_photo, year_engine, notes)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)", array_values($data));
            $pid = (int)db()->lastInsertId();
            if ($initQty > 0) {
                add_stock_move($pid, $initQty, 'موجودی اولیه', date('Y-m-d'),
                               $data['buy_price'], null, 'ثبت هنگام ایجاد کالا');
            }
            flash('ok', 'کالای جدید ثبت شد.');
        }

        q("DELETE FROM product_vehicles WHERE product_id=?", [$pid]);
        if ($vehIds) {
            $st = db()->prepare("INSERT INTO product_vehicles (product_id, vehicle_id) VALUES (?,?)");
            foreach (array_unique($vehIds) as $v) $st->execute([$pid, $v]);
        }

        redirect(post('stay') ? url('product_edit', ['id' => $pid]) : url('products'));
    }

    $row    = array_merge($row ?: [], $data, ['id' => $id]);
    $selVeh = $vehIds;
}

$v = fn($k, $d = '') => e($row[$k] ?? $d);

$cats = all("SELECT * FROM categories ORDER BY sort_order, name");
$sups = all("SELECT * FROM suppliers WHERE active=1 ORDER BY name");
$vehs = all("SELECT * FROM vehicles ORDER BY sort_order, name");

// آمار کالا
$stats = null;
if ($id) {
    $stats = [
        'sold90'  => product_sales_in_days($id, 90),
        'soldAll' => (int)scalar("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
                                   JOIN orders o ON o.id=oi.order_id
                                  WHERE oi.product_id=? AND o.status<>'لغو شد' AND o.is_returned=0", [$id]),
        'stock'   => product_stock($id),
        'gross'   => (int)scalar("SELECT COALESCE(SUM(oi.qty*(oi.sell_price-oi.buy_price)),0)
                                    FROM order_items oi JOIN orders o ON o.id=oi.order_id
                                   WHERE oi.product_id=? AND o.status<>'لغو شد' AND o.is_returned=0", [$id]),
    ];
}

layout_head($id ? 'ویرایش کالا' : 'کالای جدید');
page_head($id ? 'ویرایش کالا' : 'افزودن کالای جدید',
          $id ? $row['name'] : 'اطلاعات کامل، مرجوعی را کم می‌کند',
          '<a class="btn" href="' . url('products') . '">بازگشت</a>');

foreach ($errors as $er) echo '<div class="flash f-red">' . e($er) . '</div>';

if ($stats): ?>
  <div class="stats g4">
    <div class="stat"><div class="lb">فروش ۹۰ روز</div><div class="vl"><?= fa_digits($stats['sold90']) ?></div></div>
    <div class="stat"><div class="lb">فروش کل</div><div class="vl"><?= fa_digits($stats['soldAll']) ?></div></div>
    <div class="stat"><div class="lb">موجودی فعلی</div><div class="vl"><?= fa_digits($stats['stock']) ?></div>
      <div class="hint"><a href="<?= url('stock', ['pid' => $id]) ?>">کاردکس</a></div></div>
    <div class="stat <?= $stats['gross'] < 0 ? 'neg' : 'pos' ?>">
      <div class="lb">سود ناخالص کل</div><div class="vl sm"><?= money($stats['gross']) ?></div></div>
  </div>
<?php endif; ?>

<form method="post" class="frm">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card-h"><h2>مشخصات اصلی</h2></div>
    <div class="card-b frm">
      <div class="row r2">
        <div class="fld">
          <label>نام قطعه *</label>
          <input type="text" name="name" value="<?= $v('name') ?>" required
                 placeholder="مثال: فیلتر روغن" autofocus>
          <div class="hint">دقیق بنویسید. «لنت» کافی نیست، «لنت ترمز جلو» بنویسید.</div>
        </div>
        <div class="fld">
          <label>برند <span class="opt">(اختیاری)</span></label>
          <input type="text" name="brand" value="<?= $v('brand') ?>" placeholder="نام برند سازنده">
        </div>
      </div>

      <div class="row r3">
        <div class="fld">
          <label>کد فنی <span class="opt">(لاتین)</span></label>
          <input type="text" name="part_number" value="<?= $v('part_number') ?>"
                 class="mono" dir="ltr" placeholder="4252.46">
          <div class="hint">کد سازنده — کلید اصلی شناسایی قطعه</div>
        </div>
        <div class="fld">
          <label>کد داخلی</label>
          <input type="text" name="internal_code" value="<?= $v('internal_code') ?>"
                 class="mono" dir="ltr" placeholder="خودکار">
          <div class="hint">خالی بگذارید تا خودکار ساخته شود</div>
        </div>
        <div class="fld">
          <label>دسته</label>
          <select name="category_id">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($cats as $c): ?>
              <option value="<?= $c['id'] ?>" <?= (int)($row['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                <?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h">
      <h2>جدول سازگاری</h2>
      <span class="sub">مهم‌ترین عامل کاهش مرجوعی</span>
    </div>
    <div class="card-b frm">
      <div class="fld">
        <label>این قطعه به کدام خودروها می‌خورد؟</label>
        <div class="chks">
          <?php foreach ($vehs as $vv): ?>
            <label class="chk">
              <input type="checkbox" name="vehicles[]" value="<?= $vv['id'] ?>"
                     <?= in_array((int)$vv['id'], $selVeh, true) ? 'checked' : '' ?>>
              <?= e($vv['name']) ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="fld">
        <label>سال ساخت و تیپ موتور <span class="opt">(اختیاری ولی مؤثر)</span></label>
        <input type="text" name="year_engine" value="<?= $v('year_engine') ?>"
               placeholder="مثال: ۱۳۹۰ به بعد، موتور TU5">
        <div class="hint">هرچه دقیق‌تر، احتمال مرجوعی کمتر. همین متن روی سایت به مشتری نشان داده می‌شود.</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>قیمت و تأمین</h2></div>
    <div class="card-b frm">
      <div class="row r3">
        <div class="fld">
          <label>قیمت خرید <span class="opt">(تومان)</span></label>
          <input type="text" name="buy_price" class="money" data-calc="buy"
                 value="<?= (int)($row['buy_price'] ?? 0) ?: '' ?>" placeholder="0">
        </div>
        <div class="fld">
          <label>قیمت فروش <span class="opt">(تومان)</span></label>
          <input type="text" name="sell_price" class="money" data-calc="sell"
                 value="<?= (int)($row['sell_price'] ?? 0) ?: '' ?>" placeholder="0">
        </div>
        <div class="fld">
          <label>سود</label>
          <div style="padding:9px 0" data-calc="out"><span class="muted small">—</span></div>
        </div>
      </div>

      <div class="row r3">
        <div class="fld">
          <label>تأمین‌کننده</label>
          <select name="supplier_id">
            <option value="">— انتخاب کنید —</option>
            <?php foreach ($sups as $s): ?>
              <option value="<?= $s['id'] ?>" <?= (int)($row['supplier_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>>
                <?= e($s['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$sups): ?>
            <div class="hint"><a href="<?= url('supplier_edit') ?>">ابتدا یک تأمین‌کننده ثبت کنید</a></div>
          <?php endif; ?>
        </div>
        <div class="fld">
          <label>زمان تحویل <span class="opt">(روز کاری)</span></label>
          <input type="text" name="lead_days" value="<?= (int)($row['lead_days'] ?? 0) ?: '' ?>"
                 inputmode="numeric" placeholder="3">
          <div class="hint">عددی که به مشتری اعلام می‌کنید — واقعی باشد</div>
        </div>
        <div class="fld">
          <label>وضعیت</label>
          <select name="status">
            <?php foreach (['فعال','موقتاً ناموجود','در حال بررسی','حذف‌شده'] as $s): ?>
              <option value="<?= e($s) ?>" <?= ($row['status'] ?? 'فعال') === $s ? 'selected' : '' ?>>
                <?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php if (!$id): ?>
        <div class="fld" style="max-width:290px">
          <label>موجودی اولیه <span class="opt">(اختیاری)</span></label>
          <input type="text" name="init_stock" inputmode="numeric" placeholder="0">
          <div class="hint">اگر این کالا را در انبار دارید، تعدادش را وارد کنید. خالی = سفارش‌محور</div>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>موارد تکمیلی</h2></div>
    <div class="card-b frm">
      <div class="fld">
        <label class="chk" style="display:inline-flex">
          <input type="checkbox" name="has_photo" value="1" <?= !empty($row['has_photo']) ? 'checked' : '' ?>>
          عکس واقعی این قطعه گرفته شده است
        </label>
      </div>
      <div class="fld">
        <label>یادداشت داخلی</label>
        <textarea name="notes" placeholder="نکاتی که فقط خودتان می‌بینید"><?= $v('notes') ?></textarea>
      </div>
    </div>
  </div>

  <div class="frm-ft">
    <button class="btn btn-p" type="submit"><?= $id ? 'ذخیرهٔ تغییرات' : 'ثبت کالا' ?></button>
    <button class="btn" type="submit" name="stay" value="1">ذخیره و ادامهٔ ویرایش</button>
    <a class="btn" href="<?= url('products') ?>">انصراف</a>
  </div>
</form>

<?php layout_foot(); ?>
