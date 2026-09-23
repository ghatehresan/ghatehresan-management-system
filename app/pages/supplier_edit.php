<?php
/** افزودن / ویرایش تأمین‌کننده — ده سؤال مذاکره */

$id  = get_int('id', 0);
$row = $id ? one("SELECT * FROM suppliers WHERE id=?", [$id]) : null;
if ($id && !$row) { flash('error', 'تأمین‌کننده یافت نشد.'); redirect(url('suppliers')); }

$errors = [];

if (is_post()) {
    csrf_verify();
    auth_require_permission('write_supplier');
    $d = [
        'name'             => post('name'),
        'contact_person'   => post('contact_person'),
        'phone'            => post('phone'),
        'address'          => post('address'),
        'specialty'        => post('specialty'),
        'has_price_list'   => post('has_price_list'),
        'price_validity'   => post('price_validity'),
        'min_order'        => post('min_order'),
        'lead_time'        => post('lead_time'),
        'stock_report'     => post('stock_report'),
        'return_policy'    => post('return_policy'),
        'official_invoice' => post('official_invoice'),
        'authenticity'     => post('authenticity'),
        'credit_terms'     => post('credit_terms'),
        'exclusivity'      => post('exclusivity'),
        'rate_reliability' => min(5, max(0, post_int('rate_reliability'))),
        'rate_quality'     => min(5, max(0, post_int('rate_quality'))),
        'rate_response'    => min(5, max(0, post_int('rate_response'))),
        'role'             => post('role', 'در حال بررسی'),
        'notes'            => post('notes'),
        'last_price_update'=> jalali_str_to_ymd(post('last_price_update')),
        'active'           => post('active') ? 1 : 0,
    ];
    if ($d['name'] === '') $errors[] = 'نام تأمین‌کننده الزامی است.';

    if (!$errors) {
        if ($id) {
            $set = implode(', ', array_map(fn($k) => "$k=?", array_keys($d)));
            q("UPDATE suppliers SET $set WHERE id=?", [...array_values($d), $id]);
            flash('ok', 'اطلاعات به‌روزرسانی شد.');
        } else {
            $cols = implode(', ', array_keys($d));
            $ph   = implode(',', array_fill(0, count($d), '?'));
            q("INSERT INTO suppliers ($cols) VALUES ($ph)", array_values($d));
            flash('ok', 'تأمین‌کننده ثبت شد.');
        }
        redirect(url('suppliers'));
    }
    $row = array_merge($row ?: [], $d, ['id' => $id]);
}

$v = fn($k, $d = '') => e($row[$k] ?? $d);
$ynSelect = function ($name, $cur) {
    $out = '<select name="' . e($name) . '">';
    foreach (['' => '—', 'بله' => 'بله', 'خیر' => 'خیر', 'تا حدی' => 'تا حدی'] as $k => $lb) {
        $sel = ((string)$cur === (string)$k) ? ' selected' : '';
        $out .= '<option value="' . e($k) . '"' . $sel . '>' . e($lb) . '</option>';
    }
    return $out . '</select>';
};
$rateSelect = function ($name, $cur) {
    $out = '<select name="' . e($name) . '">';
    $out .= '<option value="0">—</option>';
    for ($i = 1; $i <= 5; $i++) {
        $sel = ((int)$cur === $i) ? ' selected' : '';
        $out .= '<option value="' . $i . '"' . $sel . '>' . fa_digits($i) . '</option>';
    }
    return $out . '</select>';
};

$lpu = '';
if (!empty($row['last_price_update'])) {
    $ts = strtotime($row['last_price_update']);
    [$y,$m,$dd] = gregorian_to_jalali((int)date('Y',$ts),(int)date('n',$ts),(int)date('j',$ts));
    $lpu = sprintf('%04d/%02d/%02d', $y, $m, $dd);
}

layout_head($id ? 'ویرایش تأمین‌کننده' : 'تأمین‌کنندهٔ جدید');
page_head($id ? 'ویرایش تأمین‌کننده' : 'افزودن تأمین‌کننده',
          'ده سؤالی که پیش از اولین خرید باید شفاف شود',
          '<a class="btn" href="' . url('suppliers') . '">بازگشت</a>');

foreach ($errors as $er) echo '<div class="flash f-red">' . e($er) . '</div>';
?>

<form method="post" class="frm">
  <?= csrf_field() ?>

  <div class="card">
    <div class="card-h"><h2>اطلاعات پایه</h2></div>
    <div class="card-b frm">
      <div class="row r3">
        <div class="fld"><label>نام / شرکت *</label>
          <input type="text" name="name" value="<?= $v('name') ?>" required autofocus></div>
        <div class="fld"><label>شخص رابط</label>
          <input type="text" name="contact_person" value="<?= $v('contact_person') ?>"></div>
        <div class="fld"><label>شمارهٔ تماس</label>
          <input type="tel" name="phone" value="<?= $v('phone') ?>" class="mono" dir="ltr"></div>
      </div>
      <div class="row r2">
        <div class="fld"><label>آدرس</label>
          <input type="text" name="address" value="<?= $v('address') ?>"></div>
        <div class="fld"><label>تخصص <span class="opt">(چه قطعاتی)</span></label>
          <input type="text" name="specialty" value="<?= $v('specialty') ?>"
                 placeholder="مثال: فیلتر و مصرفی موتور"></div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>بخش الف — قیمت و سفارش</h2></div>
    <div class="card-b frm">
      <div class="row r2">
        <div class="fld">
          <label>۱. لیست قیمت رسمی دارد؟</label>
          <?= $ynSelect('has_price_list', $row['has_price_list'] ?? '') ?>
          <div class="hint">بدون فایل قیمت، هر بار باید تلفنی بپرسید</div>
        </div>
        <div class="fld">
          <label>۲. قیمت چند وقت معتبر است؟</label>
          <input type="text" name="price_validity" value="<?= $v('price_validity') ?>"
                 placeholder="مثال: هفتگی به‌روز می‌شود">
        </div>
      </div>
      <div class="row r2">
        <div class="fld">
          <label>۳. حداقل سفارش <span class="opt">(حیاتی)</span></label>
          <input type="text" name="min_order" value="<?= $v('min_order') ?>"
                 placeholder="مثال: تکی هم می‌دهد">
          <div class="hint">اگر تک‌فروشی نکند، مدل سفارش‌محور از کار می‌افتد</div>
        </div>
        <div class="fld">
          <label>۴. زمان آماده‌سازی</label>
          <input type="text" name="lead_time" value="<?= $v('lead_time') ?>"
                 placeholder="مثال: ۱ تا ۲ روز کاری">
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>بخش ب — موجودی و کیفیت</h2></div>
    <div class="card-b frm">
      <div class="row r2">
        <div class="fld">
          <label>۵. گزارش موجودی می‌دهد؟ <span class="opt">(حیاتی)</span></label>
          <?= $ynSelect('stock_report', $row['stock_report'] ?? '') ?>
          <div class="hint">بزرگ‌ترین ریسک: بفروشید و بعد معلوم شود موجود نیست</div>
        </div>
        <div class="fld">
          <label>۷. فاکتور رسمی می‌دهد؟</label>
          <?= $ynSelect('official_invoice', $row['official_invoice'] ?? '') ?>
        </div>
      </div>
      <div class="fld">
        <label>۶. شرایط مرجوعی</label>
        <input type="text" name="return_policy" value="<?= $v('return_policy') ?>"
               placeholder="مثال: تا ۷ روز، بدون کسر">
        <div class="hint">تعیین می‌کند شما چه ضمانتی می‌توانید به مشتری بدهید</div>
      </div>
      <div class="fld">
        <label>۸. اصالت و برند <span class="opt">(حیاتی)</span></label>
        <input type="text" name="authenticity" value="<?= $v('authenticity') ?>"
               placeholder="مثال: اصلی با بسته‌بندی سازنده">
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>بخش ج — شرایط همکاری</h2></div>
    <div class="card-b frm">
      <div class="row r2">
        <div class="fld">
          <label>۹. پرداخت مدت‌دار</label>
          <input type="text" name="credit_terms" value="<?= $v('credit_terms') ?>"
                 placeholder="مثال: فعلاً نقدی، بعداً بررسی می‌کند">
          <div class="hint">جلسهٔ اول مطالبه نکنید — اول خوش‌حسابی نشان دهید</div>
        </div>
        <div class="fld">
          <label>۱۰. محدودیت انحصار دارد؟</label>
          <?= $ynSelect('exclusivity', $row['exclusivity'] ?? '') ?>
          <div class="hint">وابستگی به یک منبع، ریسک بقاست</div>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-h"><h2>ارزیابی و تصمیم</h2></div>
    <div class="card-b frm">
      <div class="row r4">
        <div class="fld"><label>خوش‌قولی <span class="opt">(۱ تا ۵)</span></label>
          <?= $rateSelect('rate_reliability', $row['rate_reliability'] ?? 0) ?></div>
        <div class="fld"><label>کیفیت جنس</label>
          <?= $rateSelect('rate_quality', $row['rate_quality'] ?? 0) ?></div>
        <div class="fld"><label>سرعت پاسخ‌گویی</label>
          <?= $rateSelect('rate_response', $row['rate_response'] ?? 0) ?></div>
        <div class="fld">
          <label>آخرین به‌روزرسانی قیمت</label>
          <input type="text" name="last_price_update" value="<?= e($lpu) ?>"
                 class="mono" placeholder="۱۴۰۵/۰۶/۳۰">
        </div>
      </div>
      <div class="row r2">
        <div class="fld">
          <label>نقش در کسب‌وکار</label>
          <select name="role">
            <?php foreach (['در حال بررسی','تأمین‌کنندهٔ اصلی','تأمین‌کنندهٔ پشتیبان',
                            'فقط اقلام خاص','فعلاً نه'] as $r): ?>
              <option value="<?= e($r) ?>" <?= ($row['role'] ?? '') === $r ? 'selected' : '' ?>>
                <?= e($r) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld" style="display:flex;align-items:flex-end">
          <label class="chk">
            <input type="checkbox" name="active" value="1" <?= (!$id || !empty($row['active'])) ? 'checked' : '' ?>>
            فعال است
          </label>
        </div>
      </div>
      <div class="fld">
        <label>یادداشت</label>
        <textarea name="notes" placeholder="نکات جلسه، توافق‌ها، هشدارها"><?= $v('notes') ?></textarea>
      </div>
    </div>
  </div>

  <div class="note n-red">
    <b>پیش از امضای هر قرارداد یا صدور چک</b>
    هر توافق پرداخت مدت‌دار، چک یا قرارداد نمایندگی، پیامد حقوقی و مالیاتی دارد.
    حتماً پیش از امضا با مشاور حقوقی و حسابدار مشورت کنید.
  </div>

  <div class="frm-ft">
    <button class="btn btn-p"><?= $id ? 'ذخیرهٔ تغییرات' : 'ثبت تأمین‌کننده' ?></button>
    <a class="btn" href="<?= url('suppliers') ?>">انصراف</a>
  </div>
</form>

<?php layout_foot(); ?>
