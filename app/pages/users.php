<?php
/** مدیریت کاربران و نقش‌های سامانه — فقط مدیر کل */

auth_require_permission('manage_users');
$roles = user_roles();
$id = get_int('id', 0);
$row = $id ? one("SELECT id, username, name, role, active FROM users WHERE id=?", [$id]) : null;
if ($id && !$row) {
    flash('error', 'کاربر یافت نشد.');
    redirect(url('users'));
}

$errors = [];
if (is_post()) {
    csrf_verify();
    $act = post('action');

    if ($act === 'save') {
        $uid      = post_int('id');
        $existing = $uid ? one("SELECT * FROM users WHERE id=?", [$uid]) : null;
        $username = strtolower(trim((string)post('username')));
        $name     = trim((string)post('name'));
        $role     = post('role', 'sales');
        $active   = post('active') ? 1 : 0;
        $password = (string)($_POST['password'] ?? '');

        if ($uid && !$existing) $errors[] = 'کاربر یافت نشد.';
        if (!preg_match('/^[a-z0-9_.-]{3,64}$/', $username)) {
            $errors[] = 'نام کاربری باید ۳ تا ۶۴ نویسه و فقط شامل حروف لاتین، عدد، نقطه، خط تیره یا زیرخط باشد.';
        }
        if ($name === '') $errors[] = 'نام نمایشی الزامی است.';
        if (!isset($roles[$role])) $errors[] = 'نقش انتخاب‌شده معتبر نیست.';
        if (!$uid && strlen($password) < 8) $errors[] = 'برای کاربر جدید، رمز عبور حداقل ۸ نویسه باشد.';
        if ($uid && $password !== '' && strlen($password) < 8) {
            $errors[] = 'رمز عبور جدید باید حداقل ۸ نویسه باشد.';
        }
        $duplicate = one("SELECT id FROM users WHERE username=? AND id<>?", [$username, $uid]);
        if ($duplicate) $errors[] = 'این نام کاربری قبلاً ثبت شده است.';

        // مدیر فعلی نباید خودش را قفل یا از مدیر کل خارج کند.
        $current = auth_user();
        if ($uid && $current && (int)$current['id'] === $uid && (!$active || $role !== 'admin')) {
            $errors[] = 'برای جلوگیری از قفل شدن سامانه، نمی‌توانید حساب خودتان را غیرفعال یا از مدیر کل خارج کنید.';
        }
        if ($uid && $existing && $existing['role'] === 'admin' && $existing['active'] &&
            ($role !== 'admin' || !$active) && active_admin_count() <= 1) {
            $errors[] = 'حداقل یک مدیر کل فعال باید باقی بماند.';
        }

        if (!$errors) {
            if ($uid) {
                if ($password !== '') {
                    q("UPDATE users SET username=?, name=?, role=?, active=?, password_hash=? WHERE id=?",
                      [$username, $name, $role, $active,
                       password_hash($password, PASSWORD_DEFAULT), $uid]);
                } else {
                    q("UPDATE users SET username=?, name=?, role=?, active=? WHERE id=?",
                      [$username, $name, $role, $active, $uid]);
                }
                flash('ok', 'اطلاعات کاربر به‌روزرسانی شد.');
            } else {
                q("INSERT INTO users (username, password_hash, name, role, active)
                   VALUES (?,?,?,?,?)",
                  [$username, password_hash($password, PASSWORD_DEFAULT), $name, $role, $active]);
                flash('ok', 'کاربر جدید ساخته شد.');
            }
            redirect(url('users'));
        }
        $row = ['id'=>$uid, 'username'=>$username, 'name'=>$name, 'role'=>$role, 'active'=>$active];
        $id = $uid;
    }

    if ($act === 'toggle') {
        $uid = post_int('id');
        $target = one("SELECT id, username, name, role, active FROM users WHERE id=?", [$uid]);
        $current = auth_user();
        if (!$target) {
            flash('error', 'کاربر یافت نشد.');
        } elseif ($current && (int)$current['id'] === $uid) {
            flash('error', 'نمی‌توانید حساب خودتان را غیرفعال کنید.');
        } elseif ($target['role'] === 'admin' && $target['active'] && active_admin_count() <= 1) {
            flash('error', 'حداقل یک مدیر کل فعال باید باقی بماند.');
        } else {
            q("UPDATE users SET active=? WHERE id=?", [$target['active'] ? 0 : 1, $uid]);
            flash('ok', $target['active'] ? 'کاربر غیرفعال شد.' : 'کاربر فعال شد.');
        }
        redirect(url('users'));
    }
}

$v = fn($key, $default = '') => e($row[$key] ?? $default);
$editing = $id > 0;
$allUsers = all("SELECT id, username, name, role, active, created_at FROM users ORDER BY active DESC, name");

layout_head('کاربران');
page_head('مدیریت کاربران', 'کنترل دسترسی کارکنان سامانه',
    $editing ? '<a class="btn" href="' . url('users') . '">کاربر جدید</a>' : '');
foreach ($errors as $er) echo '<div class="flash f-red">' . e($er) . '</div>';
?>

<div class="note n-info">
  <b>نکتهٔ امنیتی</b>
  برای هر نفر حساب جدا بسازید و رمز عبور را با دیگران به اشتراک نگذارید.
  حساب‌های غیرفعال دیگر نمی‌توانند وارد سامانه شوند، اما سابقهٔ آن‌ها حفظ می‌شود.
</div>

<div class="card">
  <div class="card-h">
    <h2><?= $editing ? 'ویرایش کاربر' : 'ساخت کاربر جدید' ?></h2>
    <?php if ($editing): ?><span class="sub"><?= e($row['username']) ?></span><?php endif; ?>
  </div>
  <div class="card-b">
    <form method="post" class="frm" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= $editing ? (int)$row['id'] : 0 ?>">
      <div class="row r4">
        <div class="fld">
          <label>نام نمایشی *</label>
          <input type="text" name="name" value="<?= $v('name') ?>" required autofocus>
        </div>
        <div class="fld">
          <label>نام کاربری *</label>
          <input type="text" name="username" value="<?= $v('username') ?>" dir="ltr" required>
          <div class="hint">فقط حروف لاتین، عدد، نقطه، خط تیره و زیرخط</div>
        </div>
        <div class="fld">
          <label>نقش *</label>
          <select name="role">
            <?php foreach ($roles as $rk => $rl): ?>
              <option value="<?= e($rk) ?>" <?= ($row['role'] ?? 'sales') === $rk ? 'selected' : '' ?>><?= e($rl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld" style="display:flex;align-items:flex-end">
          <label class="chk">
            <input type="checkbox" name="active" value="1" <?= (!$editing || !empty($row['active'])) ? 'checked' : '' ?>>
            حساب فعال است
          </label>
        </div>
      </div>
      <div class="row r2">
        <div class="fld">
          <label><?= $editing ? 'رمز عبور جدید (اختیاری)' : 'رمز عبور *' ?></label>
          <input type="password" name="password" dir="ltr" autocomplete="new-password" <?= $editing ? '' : 'required' ?>>
          <div class="hint">حداقل ۸ نویسه<?= $editing ? '؛ برای حفظ رمز فعلی خالی بگذارید' : '' ?></div>
        </div>
      </div>
      <div class="frm-ft">
        <button class="btn btn-p" type="submit"><?= $editing ? 'ذخیرهٔ تغییرات' : 'ساخت کاربر' ?></button>
        <?php if ($editing): ?><a class="btn" href="<?= url('users') ?>">انصراف</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>فهرست کاربران</h2><span class="sub"><?= fa_digits(count($allUsers)) ?> حساب</span></div>
  <div class="card-b tight">
    <div class="tw">
    <table>
      <thead><tr><th>نام</th><th>نام کاربری</th><th>نقش</th><th class="num">وضعیت</th><th>ثبت‌شده</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($allUsers as $u): ?>
        <tr style="<?= $u['active'] ? '' : 'opacity:.55' ?>">
          <td><b><?= e($u['name']) ?></b><?= (int)$u['id'] === (int)(auth_user()['id'] ?? 0) ? ' <span class="tiny muted">(شما)</span>' : '' ?></td>
          <td class="mono" dir="ltr"><?= e($u['username']) ?></td>
          <td><?= badge(role_label($u['role']), $u['role'] === 'admin' ? 'orange' : ($u['role'] === 'warehouse' ? 'info' : 'gray')) ?></td>
          <td class="num"><?= $u['active'] ? badge('فعال', 'green') : badge('غیرفعال', 'red') ?></td>
          <td class="small"><?= jdate($u['created_at']) ?></td>
          <td class="act">
            <a class="btn btn-sm" href="<?= url('users', ['id' => $u['id']]) ?>">ویرایش</a>
            <?php if ((int)$u['id'] !== (int)(auth_user()['id'] ?? 0)): ?>
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                <button class="btn btn-sm <?= $u['active'] ? 'btn-d' : '' ?>" data-confirm="وضعیت این کاربر تغییر کند؟">
                  <?= $u['active'] ? 'غیرفعال کردن' : 'فعال کردن' ?>
                </button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>سطح دسترسی نقش‌ها</h2></div>
  <div class="card-b">
    <dl class="kv">
      <dt><?= e(role_label('admin')) ?></dt><dd>همهٔ بخش‌ها، تنظیمات، پشتیبان‌گیری، حذف و مدیریت کاربران</dd>
      <dt><?= e(role_label('manager')) ?></dt><dd>مدیریت کامل عملیات، بدون مدیریت کاربران و تنظیمات امنیتی</dd>
      <dt><?= e(role_label('sales')) ?></dt><dd>کالا، سفارش، مشتری، تأمین‌کننده، دفتر نداشتیم و گزارش فروش</dd>
      <dt><?= e(role_label('warehouse')) ?></dt><dd>مشاهدهٔ کالا، انبار، کاردکس، تصمیم انبار و تأمین‌کنندگان</dd>
    </dl>
  </div>
</div>

<?php layout_foot(); ?>
