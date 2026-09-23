<?php
/** راه‌اندازی اولیه و ساخت مدیر سامانه */

if (!auth_bootstrap_required()) {
    redirect(is_authenticated() ? url('dashboard') : url('login'));
}

$errors = [];
if (is_post()) {
    csrf_verify();
    $username = trim((string)post('username'));
    $name     = trim((string)post('name'));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['password_confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $username)) {
        $errors[] = 'نام کاربری باید ۳ تا ۶۴ نویسه و فقط شامل حروف لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.';
    }
    if ($name === '') $errors[] = 'نام مدیر الزامی است.';
    if (strlen($password) < 8) $errors[] = 'رمز عبور باید حداقل ۸ نویسه باشد.';
    if ($password !== $confirm) $errors[] = 'تکرار رمز عبور یکسان نیست.';
    if (one("SELECT id FROM users WHERE username=?", [$username])) {
        $errors[] = 'این نام کاربری قبلاً ثبت شده است.';
    }

    if (!$errors) {
        q("INSERT INTO users (username, password_hash, name, role, active)
           VALUES (?,?,?,?,1)",
          [$username, password_hash($password, PASSWORD_DEFAULT), $name, 'admin']);
        $user = one("SELECT id, username, name, role, active FROM users WHERE username=?", [$username]);
        auth_login($user);
        redirect(url('dashboard'));
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>راه‌اندازی قطعه‌رسان</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.auth-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:22px;background:#F9FAFB}
.auth-box{width:min(460px,100%);background:#fff;border:1px solid #E8EBEF;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(14,42,71,.08)}
.auth-brand{text-align:center;color:#0E2A47;font-size:22px;font-weight:900;margin-bottom:4px}.auth-sub{text-align:center;color:#55616E;font-size:13px;margin-bottom:22px}
.auth-box h1{font-size:19px;color:#0E2A47;margin-bottom:14px}.auth-box .fld{margin-bottom:14px}.auth-box label{display:block;font-size:13px;font-weight:700;color:#0E2A47;margin-bottom:5px}.auth-box input{width:100%;font-family:inherit;font-size:14px;padding:10px 12px;border:1px solid #D5DCE4;border-radius:9px}.auth-box input:focus{outline:0;border-color:#F26B1D;box-shadow:0 0 0 3px rgba(242,107,29,.13)}.auth-box .btn{width:100%;justify-content:center;margin-top:5px}.auth-error{background:rgba(217,48,37,.06);border:1px solid rgba(217,48,37,.3);color:#a3201a;border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:15px}.auth-hint{font-size:12px;color:#55616E;line-height:1.9;margin-top:15px}
</style>
</head>
<body class="auth-page">
<div class="auth-box">
  <div class="auth-brand">قطعه‌رسان</div>
  <div class="auth-sub">سامانهٔ مدیریت قطعه‌رسان</div>
  <h1>راه‌اندازی اولیه</h1>
  <p class="auth-hint" style="margin-top:0;margin-bottom:15px">برای شروع، حساب مدیر سامانه را بسازید. رمز عبور را در جای امن نگه دارید.</p>
  <?php if ($errors): ?>
    <div class="auth-error"><?php foreach ($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?></div>
  <?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <div class="fld"><label>نام مدیر</label><input type="text" name="name" value="<?= e(post('name')) ?>" required autofocus></div>
    <div class="fld"><label>نام کاربری لاتین</label><input type="text" name="username" value="<?= e(post('username')) ?>" dir="ltr" autocomplete="username" required></div>
    <div class="fld"><label>رمز عبور</label><input type="password" name="password" dir="ltr" autocomplete="new-password" required></div>
    <div class="fld"><label>تکرار رمز عبور</label><input type="password" name="password_confirm" dir="ltr" autocomplete="new-password" required></div>
    <button class="btn btn-p" type="submit">ساخت حساب مدیر</button>
  </form>
</div>
</body>
</html>
