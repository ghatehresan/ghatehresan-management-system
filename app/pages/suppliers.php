<?php
/** فهرست تأمین‌کنندگان */

if (is_post() && post('action') === 'delete') {
    csrf_verify();
    $id = post_int('id');
    q("UPDATE products SET supplier_id=NULL WHERE supplier_id=?", [$id]);
    q("DELETE FROM suppliers WHERE id=?", [$id]);
    flash('ok', 'تأمین‌کننده حذف شد.');
    redirect(url('suppliers'));
}

$rows = all("SELECT s.*,
               (SELECT COUNT(*) FROM products p WHERE p.supplier_id = s.id) AS n_products
             FROM suppliers s ORDER BY s.active DESC, s.name");

layout_head('تأمین‌کننده');
page_head('تأمین‌کنندگان', fa_digits(count($rows)) . ' تأمین‌کننده',
    '<a class="btn btn-p" href="' . url('supplier_edit') . '">+ تأمین‌کنندهٔ جدید</a>');
?>

<?php if (count($rows) < 3): ?>
  <div class="note n-amber">
    <b>توزیع‌کنندهٔ سوم را پیدا کنید</b>
    دو منبع کم است — نه برای چانه‌زنی قیمت، بلکه برای بقا. اگر یکی تعطیل کند یا رابطه خراب شود،
    کل کسب‌وکار متوقف می‌شود. حتی اگر فعلاً از سومی خرید نمی‌کنید، داشتن شماره و لیست قیمتش خودش بیمه است.
  </div>
<?php endif; ?>

<?php if (!$rows): ?>
  <div class="card"><div class="card-b tight">
    <?php empty_box('هنوز تأمین‌کننده‌ای ثبت نشده',
      'اطلاعات دو توزیع‌کننده‌ای که با آن‌ها در ارتباط هستید را وارد کنید. ده سؤال کلیدی در فرم آمده است.',
      '<a class="btn btn-p" href="' . url('supplier_edit') . '">+ افزودن تأمین‌کننده</a>'); ?>
  </div></div>
<?php else: ?>
  <div class="stats g2">
  <?php foreach ($rows as $s):
      $avg = ((int)$s['rate_reliability'] + (int)$s['rate_quality'] + (int)$s['rate_response']);
      $cnt = ((int)$s['rate_reliability'] ? 1 : 0) + ((int)$s['rate_quality'] ? 1 : 0) + ((int)$s['rate_response'] ? 1 : 0);
      $avg = $cnt ? round($avg / $cnt, 1) : 0;
      $roleKind = ['تأمین‌کنندهٔ اصلی'=>'green','تأمین‌کنندهٔ پشتیبان'=>'info',
                   'فقط اقلام خاص'=>'amber','فعلاً نه'=>'gray','در حال بررسی'=>'gray'][$s['role']] ?? 'gray';
  ?>
    <div class="card mb0" style="<?= $s['active'] ? '' : 'opacity:.6' ?>">
      <div class="card-h">
        <h2><?= e($s['name']) ?></h2>
        <?= badge($s['role'] ?: 'در حال بررسی', $roleKind) ?>
      </div>
      <div class="card-b">
        <dl class="kv">
          <?php if ($s['contact_person']): ?><dt>رابط</dt><dd><?= e($s['contact_person']) ?></dd><?php endif; ?>
          <?php if ($s['phone']): ?><dt>تماس</dt><dd class="mono"><?= e($s['phone']) ?></dd><?php endif; ?>
          <?php if ($s['specialty']): ?><dt>تخصص</dt><dd><?= e(str_limit($s['specialty'], 55)) ?></dd><?php endif; ?>
          <dt>کالاهای مرتبط</dt><dd><?= fa_digits($s['n_products']) ?> قلم</dd>
          <?php if ($s['min_order']): ?><dt>حداقل سفارش</dt><dd><?= e($s['min_order']) ?></dd><?php endif; ?>
          <?php if ($s['lead_time']): ?><dt>زمان آماده‌سازی</dt><dd><?= e($s['lead_time']) ?></dd><?php endif; ?>
          <?php if ($s['credit_terms']): ?><dt>پرداخت مدت‌دار</dt><dd><?= e($s['credit_terms']) ?></dd><?php endif; ?>
          <?php if ($avg): ?>
            <dt>میانگین امتیاز</dt>
            <dd><b><?= fa_digits($avg) ?></b> <span class="muted small">از ۵</span></dd>
          <?php endif; ?>
        </dl>
        <div class="dv"></div>
        <div style="display:flex;gap:7px;flex-wrap:wrap">
          <a class="btn btn-sm" href="<?= url('supplier_edit', ['id' => $s['id']]) ?>">ویرایش</a>
          <a class="btn btn-sm" href="<?= url('products', ['sup' => $s['id']]) ?>">کالاهایش</a>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= $s['id'] ?>">
            <button class="btn btn-sm btn-d" data-confirm="حذف شود؟ کالاهایش بدون تأمین‌کننده می‌مانند.">حذف</button>
          </form>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php layout_foot(); ?>
