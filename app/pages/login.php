<?php
/** ورود به سامانه */

if (is_authenticated()) redirect(url('dashboard'));
$errors = [];

if (is_post()) {
    csrf_verify();
    $username = strtolower(trim((string)post('username')));
    $password = (string)($_POST['password'] ?? '');
    $user = one("SELECT id, username, name, role, active, password_hash
                   FROM users WHERE username=? AND active=1", [$username]);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        activity_log('login_failed', 'auth', null, 'username=' . $username);
        $errors[] = 'نام کاربری یا رمز عبور نادرست است.';
    } else {
        activity_log('login', 'auth', $user['id'], 'username=' . $user['username'], $user['id']);
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
<title>ورود — قطعه‌رسان</title>
<link rel="stylesheet" href="assets/app.css">
<style>
.auth-page{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:22px;background:#F9FAFB}.auth-box{width:min(420px,100%);background:#fff;border:1px solid #E8EBEF;border-radius:14px;padding:28px;box-shadow:0 4px 18px rgba(14,42,71,.08)}.auth-brand{text-align:center;color:#0E2A47;font-size:22px;font-weight:900;margin-bottom:4px}.auth-sub{text-align:center;color:#55616E;font-size:13px;margin-bottom:22px}.auth-box h1{font-size:19px;color:#0E2A47;margin-bottom:14px}.auth-box .fld{margin-bottom:14px}.auth-box label{display:block;font-size:13px;font-weight:700;color:#0E2A47;margin-bottom:5px}.auth-box input{width:100%;font-family:inherit;font-size:14px;padding:10px 12px;border:1px solid #D5DCE4;border-radius:9px}.auth-box input:focus{outline:0;border-color:#F26B1D;box-shadow:0 0 0 3px rgba(242,107,29,.13)}.auth-box .btn{width:100%;justify-content:center;margin-top:5px}.auth-error{background:rgba(217,48,37,.06);border:1px solid rgba(217,48,37,.3);color:#a3201a;border-radius:9px;padding:10px 12px;font-size:13px;margin-bottom:15px}
</style>
</head>
<body class="auth-page">
<div class="auth-box">
  <div class="auth-brand">قطعه‌رسان</div>
  <div class="auth-sub">سامانهٔ مدیریت قطعه‌رسان</div>
  <h1>ورود به سامانه</h1>
  <?php if ($errors): ?><div class="auth-error"><?= e($errors[0]) ?></div><?php endif; ?>
  <form method="post" autocomplete="on">
    <?= csrf_field() ?>
    <div class="fld"><label>نام کاربری</label><input type="text" name="username" value="<?= e(post('username')) ?>" dir="ltr" autocomplete="username" required autofocus></div>
    <div class="fld"><label>رمز عبور</label><input type="password" name="password" dir="ltr" autocomplete="current-password" required></div>
    <button class="btn btn-p" type="submit">ورود</button>
  </form>
</div>
</body>
</html>
