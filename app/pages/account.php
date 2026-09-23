<?php
/** حساب کاربری و تغییر رمز عبور */

if (!is_authenticated()) redirect(url('login'));

$u = auth_user();
$errors = [];

if (is_post()) {
    csrf_verify();
    $current = (string)($_POST['current_password'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    // auth_user عمداً هش رمز را برنمی‌گرداند؛ آن را فقط هنگام تغییر رمز می‌خوانیم.
    $fresh = one("SELECT password_hash FROM users WHERE id=? AND active=1", [$u['id']]);
    if (!$fresh || !password_verify($current, $fresh['password_hash'])) {
        $errors[] = 'رمز عبور فعلی درست نیست.';
    }
    if (strlen($password) < 8) $errors[] = 'رمز عبور جدید باید حداقل ۸ نویسه باشد.';
    if ($password !== $confirm) $errors[] = 'تکرار رمز عبور یکسان نیست.';
    if ($current !== '' && $password === $current) $errors[] = 'رمز عبور جدید باید با رمز فعلی متفاوت باشد.';

    if (!$errors) {
        q("UPDATE users SET password_hash=? WHERE id=?",
          [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
        activity_log('password_changed', 'user', $u['id']);
        flash('ok', 'رمز عبور با موفقیت تغییر کرد.');
        redirect(url('account'));
    }
}

layout_head('حساب من');
page_head('حساب من', 'اطلاعات حساب و امنیت ورود');
foreach ($errors as $er) echo '<div class="flash f-red">' . e($er) . '</div>';
?>

<div class="card">
  <div class="card-h"><h2>اطلاعات حساب</h2></div>
  <div class="card-b">
    <dl class="kv">
      <dt>نام نمایشی</dt><dd><?= e($u['name']) ?></dd>
      <dt>نام کاربری</dt><dd class="mono" dir="ltr"><?= e($u['username']) ?></dd>
      <dt>نقش</dt><dd><?= badge(role_label($u['role']), $u['role'] === 'admin' ? 'orange' : 'gray') ?></dd>
    </dl>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>تغییر رمز عبور</h2><span class="sub">حداقل ۸ نویسه</span></div>
  <div class="card-b">
    <form method="post" class="frm" autocomplete="off">
      <?= csrf_field() ?>
      <div class="row r3">
        <div class="fld">
          <label>رمز عبور فعلی *</label>
          <input type="password" name="current_password" dir="ltr" autocomplete="current-password" required autofocus>
        </div>
        <div class="fld">
          <label>رمز عبور جدید *</label>
          <input type="password" name="password" dir="ltr" autocomplete="new-password" required>
        </div>
        <div class="fld">
          <label>تکرار رمز جدید *</label>
          <input type="password" name="password_confirm" dir="ltr" autocomplete="new-password" required>
        </div>
      </div>
      <div class="frm-ft"><button class="btn btn-p" type="submit">تغییر رمز عبور</button></div>
    </form>
  </div>
</div>

<div class="note n-info">
  <b>نکتهٔ امنیتی</b>
  رمز عبور را با همکاران به اشتراک نگذارید. اگر احتمال می‌دهید رمزتان دیده شده، همین حالا آن را تغییر دهید.
</div>

<?php layout_foot(); ?>
