<?php
/** دفتر نداشتیم */

if (is_post()) {
    csrf_verify();
    $act = post('action');

    if ($act === 'add') {
        auth_require_permission('write_missed');
        $d = jalali_str_to_ymd(post('req_date')) ?: date('Y-m-d');
        if (post('part_name') === '') {
            flash('error', 'نام قطعه الزامی است.');
        } else {
            q("INSERT INTO missed (req_date, part_name, vehicle, year_engine, source,
                   quoted_price, followed_up, resolved, notes)
               VALUES (?,?,?,?,?,?,?,0,?)",
              [$d, post('part_name'), post('vehicle'), post('year_engine'),
               post('source'), post_int('quoted_price'), post('followed_up') ? 1 : 0,
               post('notes')]);
            flash('ok', 'ثبت شد. این داده بعداً می‌گوید انبار را با چه چیزی پر کنید.');
        }
        redirect(url('missed'));
    }
    if ($act === 'resolve') {
        auth_require_permission('write_missed');
        q("UPDATE missed SET resolved = 1 - resolved WHERE id=?", [post_int('id')]);
        redirect(url('missed', array_filter(['f' => get('f')])));
    }
    if ($act === 'delete') {
        auth_require_permission('delete_missed');
        q("DELETE FROM missed WHERE id=?", [post_int('id')]);
        flash('ok', 'حذف شد.');
        redirect(url('missed'));
    }
}

$f      = get('f', 'open');
$search = get('q');

$w = []; $prm = [];
if ($f === 'open')     $w[] = "resolved = 0";
if ($f === 'resolved') $w[] = "resolved = 1";
if ($search !== '') {
    $w[] = "(part_name LIKE ? OR vehicle LIKE ?)";
    $prm[] = "%$search%"; $prm[] = "%$search%";
}
$where = $w ? 'WHERE ' . implode(' AND ', $w) : '';

$rows = all("SELECT * FROM missed $where ORDER BY req_date DESC, id DESC LIMIT 200", $prm);

$nOpen = (int)scalar("SELECT COUNT(*) FROM missed WHERE resolved=0");
$nAll  = (int)scalar("SELECT COUNT(*) FROM missed");

// تحلیل: پرتکرارترین‌ها
$top = all("SELECT part_name, COUNT(*) AS c,
                   COUNT(DISTINCT vehicle) AS nveh, MAX(req_date) AS last_date
              FROM missed WHERE resolved = 0
             GROUP BY part_name HAVING COUNT(*) >= 2
             ORDER BY c DESC, last_date DESC LIMIT 10");

$d30 = date('Y-m-d', strtotime('-30 days'));
$n30 = (int)scalar("SELECT COUNT(*) FROM missed WHERE req_date >= ?", [$d30]);

[$jy, $jm, $jd] = jtoday();
$todayStr = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);

layout_head('دفتر نداشتیم');
page_head('دفتر نداشتیم', 'ارزشمندترین دادهٔ کسب‌وکار شما',
    '<a class="btn" href="' . url('export', ['t' => 'missed']) . '">خروجی CSV</a>');
?>

<div class="note n-info">
  <b>چرا این دفتر مهم است؟</b>
  هر بار مشتری قطعه‌ای می‌خواهد که ندارید، یک فرصت از دست رفته ثبت می‌شود.
  بعد از چند ماه، این فهرست دقیقاً می‌گوید کاتالوگ بعدی و اولین انبار را با چه چیزی پر کنید.
  این داده را نمی‌توان خرید — فقط از مشتری واقعی به دست می‌آید.
</div>

<div class="stats g3">
  <div class="stat acc"><div class="lb">پیگیری‌نشده</div><div class="vl"><?= fa_digits($nOpen) ?></div></div>
  <div class="stat"><div class="lb">۳۰ روز اخیر</div><div class="vl"><?= fa_digits($n30) ?></div></div>
  <div class="stat"><div class="lb">کل ثبت‌شده</div><div class="vl"><?= fa_digits($nAll) ?></div></div>
</div>

<div class="card">
  <div class="card-h"><h2>ثبت درخواست جدید</h2>
    <span class="sub">همین حالا ثبت کنید، نه بعداً از حافظه</span></div>
  <div class="card-b">
    <form method="post" class="frm">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <div class="row r4">
        <div class="fld">
          <label>نام قطعه *</label>
          <input type="text" name="part_name" required placeholder="مثال: لنت ترمز جلو">
        </div>
        <div class="fld">
          <label>خودرو</label>
          <input type="text" name="vehicle" placeholder="مثال: پژو ۴۰۵">
        </div>
        <div class="fld">
          <label>سال / تیپ</label>
          <input type="text" name="year_engine" placeholder="۱۳۹۵، TU5">
        </div>
        <div class="fld">
          <label>تاریخ</label>
          <input type="text" name="req_date" value="<?= e($todayStr) ?>" class="mono">
        </div>
      </div>
      <div class="row r4">
        <div class="fld">
          <label>از کجا پرسید</label>
          <select name="source">
            <option value="">—</option>
            <?php foreach (['سایت','اینستاگرام','تلگرام','واتساپ','تلفن','حضوری','معرفی'] as $s): ?>
              <option value="<?= e($s) ?>"><?= e($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="fld">
          <label>قیمتی که گفت <span class="opt">(اختیاری)</span></label>
          <input type="text" name="quoted_price" class="money" placeholder="0">
        </div>
        <div class="fld" style="display:flex;align-items:flex-end">
          <label class="chk">
            <input type="checkbox" name="followed_up" value="1">
            پیگیری شد
          </label>
        </div>
        <div class="fld">
          <label>یادداشت</label>
          <input type="text" name="notes" placeholder="نکته">
        </div>
      </div>
      <div class="frm-ft"><button class="btn btn-p">ثبت در دفتر</button></div>
    </form>
  </div>
</div>

<?php if ($top): ?>
<div class="card">
  <div class="card-h"><h2>تحلیل: پرتکرارترین درخواست‌ها</h2>
    <span class="sub">نامزدهای اصلی برای افزودن به کاتالوگ</span></div>
  <div class="card-b tight">
    <div class="tw">
    <table>
      <thead><tr><th>قطعه</th><th class="num">دفعات</th><th class="num">تنوع خودرو</th>
                 <th class="num">آخرین درخواست</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($top as $t): ?>
        <tr>
          <td><b><?= e($t['part_name']) ?></b></td>
          <td class="num">
            <?= $t['c'] >= 3 ? badge(fa_digits($t['c']) . ' بار', 'orange')
                             : badge(fa_digits($t['c']) . ' بار', 'gray') ?>
          </td>
          <td class="num"><?= fa_digits($t['nveh']) ?></td>
          <td class="num small"><?= jdate($t['last_date']) ?></td>
          <td class="act">
            <a class="btn btn-sm btn-p" href="<?= url('product_edit') ?>">افزودن به کاتالوگ</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="tabs">
  <a href="<?= url('missed', ['f' => 'open']) ?>"     class="<?= $f==='open'?'on':'' ?>">پیگیری‌نشده</a>
  <a href="<?= url('missed', ['f' => 'resolved']) ?>" class="<?= $f==='resolved'?'on':'' ?>">رسیدگی‌شده</a>
  <a href="<?= url('missed', ['f' => 'all']) ?>"      class="<?= $f==='all'?'on':'' ?>">همه</a>
</div>

<div class="card">
  <div class="card-b tight">
    <?php if (!$rows): ?>
      <?php empty_box('موردی ثبت نشده',
        'از همین امروز شروع کنید — هر «نداشتید؟» یک دادهٔ باارزش است.'); ?>
    <?php else: ?>
      <div class="tw">
      <table>
        <thead><tr><th>تاریخ</th><th>قطعه</th><th>خودرو</th><th>منبع</th>
                   <th class="num">قیمت اعلامی</th><th class="num">وضعیت</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr style="<?= $r['resolved'] ? 'opacity:.55' : '' ?>">
            <td class="small nowrap"><?= jdate($r['req_date']) ?></td>
            <td>
              <b><?= e($r['part_name']) ?></b>
              <?php if ($r['notes']): ?>
                <div class="tiny muted"><?= e(str_limit($r['notes'], 44)) ?></div>
              <?php endif; ?>
            </td>
            <td class="small">
              <?= e($r['vehicle'] ?: '—') ?>
              <?php if ($r['year_engine']): ?>
                <div class="tiny muted"><?= e($r['year_engine']) ?></div>
              <?php endif; ?>
            </td>
            <td class="small"><?= e($r['source'] ?: '—') ?></td>
            <td class="num"><?= (int)$r['quoted_price'] ? money($r['quoted_price']) : '—' ?></td>
            <td class="num">
              <?= $r['resolved'] ? badge('رسیدگی شد', 'green')
                                 : ($r['followed_up'] ? badge('پیگیری شد', 'amber')
                                                      : badge('باز', 'gray')) ?>
            </td>
            <td class="act">
              <?php if (auth_can('write_missed')): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="resolve">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm"><?= $r['resolved'] ? 'بازگشت به باز' : 'رسیدگی شد' ?></button>
                </form>
              <?php endif; ?>
              <?php if (auth_can('delete_missed')): ?>
                <form method="post" style="display:inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= $r['id'] ?>">
                  <button class="btn btn-sm btn-d" data-confirm="حذف شود؟">×</button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php layout_foot(); ?>
