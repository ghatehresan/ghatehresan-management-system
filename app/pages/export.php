<?php
/** خروجی CSV — سازگار با اکسل فارسی (BOM + UTF-8) */

$t = get('t', 'products');

$allowed = ['products', 'orders', 'order_items', 'customers', 'suppliers', 'missed', 'stock', 'activity'];
if (!in_array($t, $allowed, true)) $t = 'products';
if (!auth_can_export($t)) {
    flash('error', 'اجازهٔ دریافت این نوع خروجی را ندارید.');
    redirect(url('dashboard'));
}
activity_log('export_downloaded', 'export', null, 'type=' . $t);

$rows = [];
$head = [];
$name = $t;

switch ($t) {
    case 'products':
        $name = 'کاتالوگ-قطعه‌رسان';
        $head = ['کد داخلی','نام قطعه','شمارهٔ فنی','دسته','برند','کیفیت','تأمین‌کننده',
                 'قیمت خرید','قیمت فروش','سود','حاشیه','موجودی','وضعیت','خودروهای سازگار','یادداشت'];
        $data = all("SELECT p.*, c.name AS cat, s.name AS sup
                       FROM products p
                       LEFT JOIN categories c ON c.id=p.category_id
                       LEFT JOIN suppliers s ON s.id=p.supplier_id
                      ORDER BY p.internal_code, p.name");
        foreach ($data as $r) {
            $veh = all("SELECT v.name FROM product_vehicles pv
                          JOIN vehicles v ON v.id=pv.vehicle_id
                         WHERE pv.product_id=?", [$r['id']]);
            $profit = (int)$r['sell_price'] - (int)$r['buy_price'];
            $rows[] = [
                $r['internal_code'], $r['name'], $r['part_number'], $r['cat'], $r['brand'],
                $r['quality'], $r['sup'], (int)$r['buy_price'], (int)$r['sell_price'], $profit,
                (int)$r['sell_price'] > 0 ? round($profit / (int)$r['sell_price'] * 100, 1) . '%' : '',
                (int)$r['stock_qty'], $r['status'],
                implode(' / ', array_column($veh, 'name')), $r['notes'],
            ];
        }
        break;

    case 'orders':
        $name = 'سفارش‌ها-قطعه‌رسان';
        $head = ['شمارهٔ سفارش','تاریخ','مشتری','شهر','وضعیت','تعداد قلم','مبلغ فروش',
                 'بهای کالا','کارمزد درگاه','ارسال','بسته‌بندی','سود خالص','حاشیه','مرجوعی','علت مرجوعی'];
        $data = all("SELECT o.*, c.name AS cname FROM orders o
                       LEFT JOIN customers c ON c.id=o.customer_id
                      ORDER BY o.order_date DESC, o.id DESC");
        foreach ($data as $o) {
            $p = order_profit($o);
            $rows[] = [
                $o['order_no'], jdate($o['order_date']),
                $o['customer_name'] ?: $o['cname'], $o['city'], $o['status'],
                $p['units'], $p['revenue'], $p['cost'],
                (int)$o['gateway_fee'], (int)$o['shipping_cost'], (int)$o['packaging_cost'],
                $p['net'], $p['revenue'] > 0 ? round($p['margin'] * 100, 1) . '%' : '',
                $o['is_returned'] ? 'بله' : 'خیر', $o['return_reason'],
            ];
        }
        break;

    case 'order_items':
        $name = 'اقلام-سفارش';
        $head = ['شمارهٔ سفارش','تاریخ','کالا','تعداد','قیمت خرید','قیمت فروش','جمع فروش','سود'];
        $data = all("SELECT oi.*, o.order_no, o.order_date FROM order_items oi
                       JOIN orders o ON o.id=oi.order_id
                      ORDER BY o.order_date DESC, oi.id");
        foreach ($data as $r) {
            $rows[] = [
                $r['order_no'], jdate($r['order_date']), $r['product_name'], (int)$r['qty'],
                (int)$r['buy_price'], (int)$r['sell_price'],
                (int)$r['sell_price'] * (int)$r['qty'],
                ((int)$r['sell_price'] - (int)$r['buy_price']) * (int)$r['qty'],
            ];
        }
        break;

    case 'customers':
        $name = 'مشتریان-قطعه‌رسان';
        $head = ['نام','تلفن','نوع','شهر','خودرو','کانال آشنایی','تعداد سفارش',
                 'مجموع خرید','سود خالص','آخرین خرید','آدرس','یادداشت'];
        $data = all("SELECT * FROM customers ORDER BY name");
        foreach ($data as $c) {
            $ords = all("SELECT * FROM orders WHERE customer_id=? AND status<>'لغو شد' AND is_returned=0", [$c['id']]);
            $rev = $net = 0; $last = '';
            foreach ($ords as $o) {
                $p = order_profit($o); $rev += $p['revenue']; $net += $p['net'];
                if ($o['order_date'] > $last) $last = $o['order_date'];
            }
            $rows[] = [
                $c['name'], $c['phone'], $c['ctype'], $c['city'], $c['vehicle'], $c['source'],
                count($ords), $rev, $net, $last ? jdate($last) : '', $c['address'], $c['notes'],
            ];
        }
        break;

    case 'suppliers':
        $name = 'تأمین‌کنندگان';
        $head = ['نام','رابط','تلفن','تخصص','نقش','لیست قیمت','اعتبار قیمت','حداقل سفارش',
                 'زمان آماده‌سازی','گزارش موجودی','مرجوعی','فاکتور رسمی','اصالت',
                 'پرداخت مدت‌دار','انحصار','خوش‌قولی','کیفیت','پاسخ‌گویی','یادداشت'];
        foreach (all("SELECT * FROM suppliers ORDER BY name") as $s) {
            $rows[] = [
                $s['name'], $s['contact_person'], $s['phone'], $s['specialty'], $s['role'],
                $s['has_price_list'], $s['price_validity'], $s['min_order'], $s['lead_time'],
                $s['stock_report'], $s['return_policy'], $s['official_invoice'], $s['authenticity'],
                $s['credit_terms'], $s['exclusivity'],
                (int)$s['rate_reliability'] ?: '', (int)$s['rate_quality'] ?: '',
                (int)$s['rate_response'] ?: '', $s['notes'],
            ];
        }
        break;

    case 'missed':
        $name = 'دفتر-نداشتیم';
        $head = ['تاریخ','قطعه','خودرو','سال/تیپ','منبع','قیمت اعلامی','پیگیری شد','رسیدگی شد','یادداشت'];
        foreach (all("SELECT * FROM missed ORDER BY req_date DESC, id DESC") as $m) {
            $rows[] = [
                jdate($m['req_date']), $m['part_name'], $m['vehicle'], $m['year_engine'],
                $m['source'], (int)$m['quoted_price'] ?: '',
                $m['followed_up'] ? 'بله' : 'خیر', $m['resolved'] ? 'بله' : 'خیر', $m['notes'],
            ];
        }
        break;

    case 'stock':
        $name = 'کاردکس-انبار';
        $head = ['تاریخ','کالا','نوع حرکت','تعداد','بهای واحد','مرجع','یادداشت'];
        foreach (all("SELECT sm.*, p.name AS pname FROM stock_moves sm
                        JOIN products p ON p.id=sm.product_id
                       ORDER BY sm.move_date DESC, sm.id DESC") as $m) {
            $rows[] = [
                jdate($m['move_date']), $m['pname'], $m['kind'], (int)$m['qty'],
                (int)$m['unit_cost'] ?: '', $m['ref'], $m['notes'],
            ];
        }
        break;

    case 'activity':
        $name = 'گزارش-فعالیت';
        $head = ['زمان','کاربر','نام کاربری','رویداد','بخش','شناسه','جزئیات','IP'];
        foreach (all("SELECT a.*, u.name AS user_name, u.username
                        FROM activity_log a
                        LEFT JOIN users u ON u.id=a.user_id
                       ORDER BY a.created_at DESC, a.id DESC
                       LIMIT 5000") as $a) {
            $rows[] = [
                jdate($a['created_at'], 'full'), $a['user_name'] ?: 'سیستم / ناشناس',
                $a['username'], activity_action_label($a['action']),
                activity_entity_label($a['entity_type']), $a['entity_id'] ?: '',
                $a['details'], $a['ip_address'],
            ];
        }
        break;
}

[$jy, $jm, $jd] = jtoday();
$stamp = sprintf('%04d-%02d-%02d', $jy, $jm, $jd);
$filename = $name . '-' . $stamp . '.csv';

while (ob_get_level() > 0) ob_end_clean();

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"; '
     . "filename*=UTF-8''" . rawurlencode($filename));
header('Cache-Control: no-store');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // BOM تا اکسل فارسی درست باز کند
fputcsv($out, $head);
foreach ($rows as $r) fputcsv($out, $r);
fclose($out);
exit;
