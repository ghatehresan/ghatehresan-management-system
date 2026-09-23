<?php
/**
 * اگر کاربر به‌جای پوشهٔ public به ریشهٔ برنامه آمد،
 * او را به مسیر درست هدایت کن.
 */
$base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
header('Location: ' . $base . '/public/', true, 302);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
<meta charset="UTF-8">
<meta http-equiv="refresh" content="0;url=public/">
<title>قطعه‌رسان</title>
<style>
  body{font-family:Tahoma,sans-serif;background:#F9FAFB;color:#111820;
       display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0}
  .b{background:#fff;border:1px solid #E8EBEF;border-radius:14px;
     padding:30px 34px;text-align:center;max-width:420px}
  a{color:#F26B1D;font-weight:700;text-decoration:none}
</style>
</head>
<body>
  <div class="b">
    <p>در حال انتقال به سامانهٔ قطعه‌رسان…</p>
    <p>اگر منتقل نشدید، <a href="public/">اینجا کلیک کنید</a>.</p>
  </div>
</body>
</html>
