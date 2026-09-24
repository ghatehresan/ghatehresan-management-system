<?php
/** تنظیمات، پشتیبان‌گیری و دادهٔ نمونه */

auth_require_permission('manage_settings');
$msgExtra = '';

if (is_post()) {
    csrf_verify();
    $act = post('action');

    if ($act === 'api_generate') {
        $apiKey = 'gr_' . bin2hex(random_bytes(12));
        $apiSecret = bin2hex(random_bytes(32));
        set_setting('api_enabled', '1');
        set_setting('api_key', $apiKey);
        set_setting('api_secret', $apiSecret);
        activity_log('settings_updated', 'settings', null, 'api_credentials_generated=1');
        flash('ok', 'کلید اتصال ساخته شد. آن را در پلاگین وردپرس وارد کنید.');
        redirect(url('settings'));
    }

    if ($act === 'api_save') {
        $apiKey = preg_replace('/[^A-Za-z0-9_.-]/', '', post('api_key'));
        $apiSecret = trim((string)post('api_secret'));
        if (post('api_enabled') && ($apiKey === '' || strlen($apiSecret) < 32)) {
            flash('error', 'برای فعال‌سازی اتصال، کلید و رمز API معتبر لازم است.');
        } else {
            set_setting('api_enabled', post('api_enabled') ? '1' : '0');
            set_setting('api_key', $apiKey);
            set_setting('api_secret', $apiSecret);
            activity_log('settings_updated', 'settings', null, 'api_config_updated=1');
            flash('ok', 'تنظیمات اتصال سایت ذخیره شد.');
        }
        redirect(url('settings'));
    }

    if ($act === 'save') {
        $fee = (float)str_replace(',', '.', en_digits(post('gateway_fee_percent')));
        $fee = max(0, min(100, $fee));
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', post('order_prefix')) ?: 'GR';
        set_setting('shop_name', post('shop_name'));
        set_setting('shop_phone', post('shop_phone'));
        set_setting('shop_city', post('shop_city'));
        set_setting('gateway_fee_percent', number_format($fee, 2, '.', ''));
        set_setting('packaging_cost', (string)max(0, post_int('packaging_cost')));
        set_setting('stock_min_sales_90d', (string)max(1, post_int('stock_min_sales_90d')));
        set_setting('order_prefix', substr($prefix, 0, 12));
        activity_log('settings_updated', 'settings');
        flash('ok', 'تنظیمات ذخیره شد.');
        redirect(url('settings'));
    }

    if ($act === 'category_add' && post('name') !== '') {
        q("INSERT INTO categories (name, sort_order) VALUES (?, ?)",
          [post('name'), (int)scalar("SELECT COALESCE(MAX(sort_order),0)+1 FROM categories")]);
        activity_log('settings_updated', 'settings', null, 'category_added=' . post('name'));
        flash('ok', 'دسته افزوده شد.');
        redirect(url('settings'));
    }
    if ($act === 'category_del') {
        $cid = post_int('id');
        q("UPDATE products SET category_id=NULL WHERE category_id=?", [$cid]);
        q("DELETE FROM categories WHERE id=?", [$cid]);
        activity_log('settings_updated', 'settings', $cid, 'category_deleted=1');
        flash('ok', 'دسته حذف شد.');
        redirect(url('settings'));
    }
    if ($act === 'vehicle_add' && post('name') !== '') {
        q("INSERT INTO vehicles (name, maker, sort_order) VALUES (?, ?, ?)",
          [post('name'), post('maker'),
           (int)scalar("SELECT COALESCE(MAX(sort_order),0)+1 FROM vehicles")]);
        activity_log('settings_updated', 'settings', null, 'vehicle_added=' . post('name'));
        flash('ok', 'خودرو افزوده شد.');
        redirect(url('settings'));
    }
    if ($act === 'vehicle_del') {
        $vid = post_int('id');
        q("DELETE FROM product_vehicles WHERE vehicle_id=?", [$vid]);
        q("DELETE FROM vehicles WHERE id=?", [$vid]);
        activity_log('settings_updated', 'settings', $vid, 'vehicle_deleted=1');
        flash('ok', 'خودرو حذف شد.');
        redirect(url('settings'));
    }

    if ($act === 'demo') {
        require_once APP_PATH . '/demo.php';
        $n = seed_demo_data();
        activity_log('settings_updated', 'settings', null, 'demo_created=' . $n);
        flash('ok', 'دادهٔ نمونه ساخته شد: ' . fa_digits($n) . ' رکورد. '
                  . 'برای پاک کردن، از همین صفحه «حذف دادهٔ نمونه» را بزنید.');
        redirect(url('dashboard'));
    }

    if ($act === 'demo_clear') {
        foreach (['order_items','orders','stock_moves','product_vehicles',
                  'products','customers','suppliers','missed'] as $tbl) {
            q("DELETE FROM $tbl");
        }
        activity_log('demo_cleared', 'settings');
        flash('ok', 'همهٔ داده‌های عملیاتی پاک شد. دسته‌ها و خودروها باقی ماندند.');
        redirect(url('settings'));
    }
}

// پشتیبان‌گیری SQLite
if (get('download') === 'backup' && DB_DRIVER === 'sqlite') {
    $f = SQLITE_PATH;
    if (is_file($f)) {
        // دیتابیس با WAL کار می‌کند؛ ابتدا تغییرات WAL را به فایل اصلی checkpoint می‌کنیم.
        try { db()->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) {
            error_log('[Ghatehresan backup] ' . $e->getMessage());
        }
        clearstatcache(true, $f);
        [$jy, $jm, $jd] = jtoday();
        $fn = sprintf('ghatehresan-backup-%04d-%02d-%02d.sqlite', $jy, $jm, $jd);
        activity_log('backup_downloaded', 'backup', null, 'file=' . $fn);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $fn . '"');
        header('Content-Length: ' . filesize($f));
        readfile($f);
        exit;
    }
}

$cats = all("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id=c.id) AS n
               FROM categories c ORDER BY c.sort_order, c.name");
$vehs = all("SELECT v.*, (SELECT COUNT(*) FROM product_vehicles pv WHERE pv.vehicle_id=v.id) AS n
               FROM vehicles v ORDER BY v.sort_order, v.name");

$counts = [];
foreach (['products'=>'کالا','orders'=>'سفارش','customers'=>'مشتری','suppliers'=>'تأمین‌کننده',
          'missed'=>'دفتر نداشتیم','stock_moves'=>'حرکت انبار'] as $tbl => $lb) {
    $counts[$lb] = (int)scalar("SELECT COUNT(*) FROM $tbl");
}

$dbSize = (DB_DRIVER === 'sqlite' && is_file(SQLITE_PATH)) ? filesize(SQLITE_PATH) : 0;
$hasData = array_sum($counts) > 0;

layout_head('تنظیمات');
page_head('تنظیمات', 'پیکربندی، داده‌های پایه و پشتیبان‌گیری');
?>

<form method="post" class="frm">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="save">
  <div class="card">
    <div class="card-h"><h2>اطلاعات کسب‌وکار</h2></div>
    <div class="card-b frm">
      <div class="row r3">
        <div class="fld"><label>نام فروشگاه</label>
          <input type="text" name="shop_name" value="<?= e(setting('shop_name', 'قطعه‌رسان')) ?>"></div>
        <div class="fld"><label>شمارهٔ تماس</label>
          <input type="tel" name="shop_phone" value="<?= e(setting('shop_phone', '')) ?>"
                 class="mono" dir="ltr"></div>
        <div class="fld"><label>شهر</label>
          <input type="text" name="shop_city" value="<?= e(setting('shop_city', '')) ?>"></div>
      </div>
      <div class="row r3">
        <div class="fld">
          <label>کارمزد درگاه پرداخت <span class="opt">(درصد)</span></label>
          <input type="text" name="gateway_fee_percent"
                 value="<?= e(setting('gateway_fee_percent', setting('gateway_fee_pct', '0'))) ?>"
                 inputmode="decimal">
          <div class="hint">هنگام ثبت سفارش خودکار پیشنهاد می‌شود</div>
        </div>
        <div class="fld">
          <label>هزینهٔ پیش‌فرض بسته‌بندی</label>
          <input type="text" name="packaging_cost" value="<?= e(setting('packaging_cost', '0')) ?>"
                 class="money">
        </div>
        <div class="fld">
          <label>پیشوند شمارهٔ سفارش</label>
          <input type="text" name="order_prefix" value="<?= e(setting('order_prefix', 'GR')) ?>"
                 class="mono" dir="ltr" maxlength="6">
        </div>
      </div>
      <div class="fld" style="max-width:330px">
        <label>آستانهٔ فروش ۹۰ روزه برای ورود به انبار</label>
        <input type="text" name="stock_min_sales_90d"
               value="<?= e(setting('stock_min_sales_90d', '3')) ?>" inputmode="numeric">
        <div class="hint">در صفحهٔ «تصمیم انبار» استفاده می‌شود</div>
      </div>
      <div class="frm-ft"><button class="btn btn-p">ذخیرهٔ تنظیمات</button></div>
    </div>
  </div>
</form>

<div class="card">
  <div class="card-h"><h2>اتصال وب‌سایت وردپرسی</h2><span class="sub">API امن برای ووکامرس</span></div>
  <div class="card-b">
    <div class="note n-info" style="margin-top:0">
      <b>مرجع موجودی و قیمت: این سامانه</b>
      پلاگین وردپرس کالا و موجودی را از اینجا می‌خواند و سفارش‌های سایت را به این سامانه ارسال می‌کند.
      آدرس API برای وارد کردن در پلاگین: <code dir="ltr">public/api.php</code>
      <br>اتصال را فقط روی HTTPS فعال کنید و کلیدها را با کسی به اشتراک نگذارید.
    </div>
    <form method="post" class="frm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="api_save">
      <div class="row r3">
        <div class="fld">
          <label>کلید API</label>
          <input type="text" name="api_key" value="<?= e(setting('api_key', '')) ?>" class="mono" dir="ltr" autocomplete="off">
        </div>
        <div class="fld">
          <label>رمز API</label>
          <input type="text" name="api_secret" value="<?= e(setting('api_secret', '')) ?>" class="mono" dir="ltr" autocomplete="off">
        </div>
        <div class="fld" style="display:flex;align-items:flex-end">
          <label class="chk"><input type="checkbox" name="api_enabled" value="1" <?= setting('api_enabled', '0') === '1' ? 'checked' : '' ?>> اتصال فعال است</label>
        </div>
      </div>
      <div class="frm-ft">
        <button class="btn btn-p">ذخیرهٔ اتصال</button>
      </div>
    </form>
    <form method="post" style="margin-top:10px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="api_generate">
      <button class="btn btn-sm btn-n" data-confirm="کلیدهای فعلی از کار می‌افتند. کلید جدید ساخته شود؟">ساخت کلیدهای جدید</button>
    </form>
  </div>
</div>

<div class="stats g2">
  <div class="card mb0">
    <div class="card-h"><h2>دسته‌های کالا</h2><span class="sub"><?= fa_digits(count($cats)) ?> دسته</span></div>
    <div class="card-b tight">
      <div class="tw">
      <table>
        <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td><?= e($c['name']) ?></td>
            <td class="num small muted"><?= fa_digits($c['n']) ?> کالا</td>
            <td class="act">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="category_del">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                <button class="btn btn-sm btn-d"
                  data-confirm="حذف شود؟ کالاهای این دسته بدون دسته می‌مانند.">×</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <form method="post" class="inline-add">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="category_add">
        <input type="text" name="name" placeholder="نام دستهٔ جدید" required>
        <button class="btn btn-sm btn-n">افزودن</button>
      </form>
    </div>
  </div>

  <div class="card mb0">
    <div class="card-h"><h2>خودروها</h2><span class="sub"><?= fa_digits(count($vehs)) ?> خودرو</span></div>
    <div class="card-b tight">
      <div class="tw">
      <table>
        <tbody>
        <?php foreach ($vehs as $vh): ?>
          <tr>
            <td><?= e($vh['name']) ?>
              <?php if ($vh['maker']): ?>
                <span class="tiny muted"><?= e($vh['maker']) ?></span>
              <?php endif; ?>
            </td>
            <td class="num small muted"><?= fa_digits($vh['n']) ?> قطعه</td>
            <td class="act">
              <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="vehicle_del">
                <input type="hidden" name="id" value="<?= $vh['id'] ?>">
                <button class="btn btn-sm btn-d" data-confirm="حذف شود؟">×</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <form method="post" class="inline-add">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="vehicle_add">
        <input type="text" name="name" placeholder="نام خودرو" required>
        <input type="text" name="maker" placeholder="سازنده" style="max-width:110px">
        <button class="btn btn-sm btn-n">افزودن</button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>پشتیبان‌گیری و خروجی</h2></div>
  <div class="card-b">
    <div class="note n-amber" style="margin-top:0">
      <b>هفته‌ای یک بار پشتیبان بگیرید</b>
      این داده جایگزین‌ناپذیر است. فایل پشتیبان را جایی بیرون از این کامپیوتر نگه دارید —
      حافظهٔ ابری یا فلش. یک هارد خراب می‌تواند کل سابقهٔ کسب‌وکار را ببرد.
    </div>

    <dl class="kv">
      <?php foreach ($counts as $lb => $n): ?>
        <dt><?= e($lb) ?></dt><dd><?= fa_digits($n) ?> رکورد</dd>
      <?php endforeach; ?>
      <dt>موتور پایگاه داده</dt>
      <dd><?= DB_DRIVER === 'sqlite' ? 'SQLite (فایل مستقل)' : 'MySQL' ?></dd>
      <?php if ($dbSize): ?>
        <dt>حجم فایل</dt><dd><?= fa_digits(round($dbSize / 1024)) ?> کیلوبایت</dd>
      <?php endif; ?>
    </dl>

    <div class="dv"></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
      <?php if (DB_DRIVER === 'sqlite'): ?>
        <a class="btn btn-p" href="<?= url('settings', ['download' => 'backup']) ?>">
          دانلود فایل پشتیبان کامل</a>
      <?php endif; ?>
      <a class="btn" href="<?= url('export', ['t' => 'products']) ?>">کاتالوگ CSV</a>
      <a class="btn" href="<?= url('export', ['t' => 'orders']) ?>">سفارش‌ها CSV</a>
      <a class="btn" href="<?= url('export', ['t' => 'order_items']) ?>">اقلام سفارش CSV</a>
      <a class="btn" href="<?= url('export', ['t' => 'customers']) ?>">مشتریان CSV</a>
      <a class="btn" href="<?= url('export', ['t' => 'suppliers']) ?>">تأمین‌کنندگان CSV</a>
      <a class="btn" href="<?= url('export', ['t' => 'missed']) ?>">دفتر نداشتیم CSV</a>
      <a class="btn" href="<?= url('export', ['t' => 'stock']) ?>">کاردکس CSV</a>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>دادهٔ نمونه</h2></div>
  <div class="card-b">
    <p class="small muted">
      اگر می‌خواهید پیش از وارد کردن اطلاعات واقعی، کار سامانه را ببینید،
      دادهٔ نمونه بسازید: چند کالا، سفارش، مشتری و تأمین‌کنندهٔ ساختگی.
      همه‌چیز قابل حذف است.
    </p>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="demo">
        <button class="btn btn-n"
          <?= $hasData ? 'data-confirm="داده‌ای در سامانه وجود دارد. دادهٔ نمونه به آن اضافه می‌شود. ادامه؟"' : '' ?>>
          ساخت دادهٔ نمونه</button>
      </form>
      <form method="post" style="display:inline">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="demo_clear">
        <button class="btn btn-d"
          data-confirm="همهٔ کالاها، سفارش‌ها، مشتریان و تأمین‌کنندگان پاک می‌شوند. این کار برگشت‌پذیر نیست. مطمئن هستید؟">
          پاک کردن همهٔ داده‌ها</button>
      </form>
    </div>
  </div>
</div>

<div class="card">
  <div class="card-h"><h2>دربارهٔ این سامانه</h2></div>
  <div class="card-b">
    <dl class="kv">
      <dt>نسخه</dt><dd>۱٫۴</dd>
      <dt>نسخهٔ PHP</dt><dd class="mono" dir="ltr"><?= e(PHP_VERSION) ?></dd>
      <dt>محل داده</dt>
      <dd class="mono tiny" dir="ltr">
        <?= DB_DRIVER === 'sqlite' ? e(SQLITE_PATH) : e(DB_NAME . ' @ ' . DB_HOST) ?>
      </dd>
    </dl>
    <div class="dv"></div>
    <p class="tiny muted mb0">
      این سامانه ابزار مدیریت داخلی است. محاسبات سود آن برای تصمیم‌گیری روزمره طراحی شده و
      جایگزین دفاتر رسمی حسابداری، اظهارنامهٔ مالیاتی یا مشاورهٔ حقوقی نیست.
    </p>
  </div>
</div>

<?php layout_foot(); ?>
