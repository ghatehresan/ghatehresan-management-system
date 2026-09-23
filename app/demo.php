<?php
/**
 * سازندهٔ دادهٔ نمونه — فقط برای آشنایی با سامانه.
 * همهٔ نام‌ها و اعداد ساختگی‌اند.
 */

function seed_demo_data(): int
{
    $n = 0;

    // تأمین‌کنندگان
    $sups = [
        ['نمونه — پخش قطعات مرکزی', 'آقای نمونه', '021-00000000', 'فیلتر و مصرفی موتور',
         'تأمین‌کنندهٔ اصلی', 'بله', 'هفتگی', 'تکی هم می‌دهد', '۱ تا ۲ روز کاری',
         'بله', 'تا ۷ روز', 'بله', 'اصلی با بسته‌بندی سازنده', 'فعلاً نقدی', 'خیر', 4, 4, 5],
        ['نمونه — بازار قطعه', 'خانم نمونه', '021-11111111', 'ترمز و جلوبندی',
         'تأمین‌کنندهٔ پشتیبان', 'خیر', 'روزانه تلفنی', 'حداقل ۳ عدد', '۲ تا ۴ روز',
         'تا حدی', 'فقط کالای سالم', 'خیر', 'متفرقه و اصلی', 'ندارد', 'خیر', 3, 3, 3],
    ];
    $supIds = [];
    foreach ($sups as $s) {
        q("INSERT INTO suppliers (name, contact_person, phone, specialty, role,
               has_price_list, price_validity, min_order, lead_time, stock_report,
               return_policy, official_invoice, authenticity, credit_terms, exclusivity,
               rate_reliability, rate_quality, rate_response, active)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)", $s);
        $supIds[] = (int)db()->lastInsertId();
        $n++;
    }

    $cats = all("SELECT id, name FROM categories ORDER BY sort_order");
    $vehs = all("SELECT id, name FROM vehicles ORDER BY sort_order");
    if (!$cats || !$vehs) return $n;

    $catId = fn($nm) => (function ($nm, $cats) {
        foreach ($cats as $c) if (mb_strpos($c['name'], $nm) !== false) return (int)$c['id'];
        return (int)$cats[0]['id'];
    })($nm, $cats);

    $vehId = fn($nm) => (function ($nm, $vehs) {
        foreach ($vehs as $v) if (mb_strpos($v['name'], $nm) !== false) return (int)$v['id'];
        return (int)$vehs[0]['id'];
    })($nm, $vehs);

    // کالاها: [نام, شمارهٔ فنی, دستهٔ تقریبی, برند, خرید, فروش, موجودی, خودروها]
    $prods = [
        ['فیلتر روغن نمونه', 'DEMO-OF-405', 'فیلتر', 'نمونه', 45000, 68000, 6, ['۴۰۵','پارس','سمند']],
        ['فیلتر هوا نمونه', 'DEMO-AF-206', 'فیلتر', 'نمونه', 62000, 95000, 4, ['۲۰۶']],
        ['فیلتر کابین نمونه', 'DEMO-CF-DENA', 'فیلتر', 'نمونه', 78000, 125000, 0, ['دنا','سمند']],
        ['لنت ترمز جلو نمونه', 'DEMO-BP-405', 'ترمز', 'نمونه', 320000, 465000, 2, ['۴۰۵','پارس']],
        ['لنت ترمز جلو نمونه ۲', 'DEMO-BP-PRIDE', 'ترمز', 'نمونه', 210000, 310000, 0, ['پراید','تیبا']],
        ['دیسک ترمز نمونه', 'DEMO-BD-206', 'ترمز', 'نمونه', 540000, 760000, 0, ['۲۰۶']],
        ['شمع موتور نمونه', 'DEMO-SP-TU5', 'مصرفی', 'نمونه', 95000, 148000, 12, ['۲۰۶','۴۰۵','رانا']],
        ['تسمه تایم نمونه', 'DEMO-TB-XU7', 'مصرفی', 'نمونه', 380000, 550000, 0, ['۴۰۵','پارس','سمند']],
        ['روغن موتور نمونه ۴ لیتری', 'DEMO-OIL-4L', 'روغن', 'نمونه', 480000, 650000, 8, ['۴۰۵','۲۰۶','پراید']],
        ['واتر پمپ نمونه', 'DEMO-WP-SAM', 'موتور', 'نمونه', 720000, 980000, 0, ['سمند','دنا']],
        ['دینام نمونه', 'DEMO-ALT-PRIDE', 'برقی', 'نمونه', 1850000, 2400000, 0, ['پراید','تیبا']],
        ['چراغ جلو نمونه', 'DEMO-HL-QUICK', 'بدنه', 'نمونه', 1250000, 1690000, 1, ['کوییک','ساینا']],
        ['آینه بغل نمونه', 'DEMO-MIR-SHAHIN', 'بدنه', 'نمونه', 890000, 1180000, 0, ['شاهین']],
        ['کمک فنر جلو نمونه', 'DEMO-SH-L90', 'جلوبندی', 'نمونه', 1450000, 1950000, 0, ['ال۹۰','ساندرو']],
        ['سیبک طبق نمونه', 'DEMO-BJ-405', 'جلوبندی', 'نمونه', 165000, 248000, 5, ['۴۰۵','پارس','سمند']],
    ];

    $pids = [];
    $i = 1;
    foreach ($prods as [$nm, $pn, $cat, $br, $buy, $sell, $qty, $vlist]) {
        q("INSERT INTO products (internal_code, name, part_number, category_id, supplier_id,
              brand, quality, buy_price, sell_price, stock_qty, status, notes)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)",
          [sprintf('GR-%04d', $i++), $nm, $pn, $catId($cat),
           $supIds[array_rand($supIds)], $br, 'اصلی', $buy, $sell, 0, 'فعال', 'دادهٔ نمونه']);
        $pid = (int)db()->lastInsertId();
        $pids[] = ['id' => $pid, 'buy' => $buy, 'sell' => $sell, 'name' => $nm];
        $n++;

        foreach ($vlist as $vn) {
            $vid = $vehId($vn);
            q("INSERT INTO product_vehicles (product_id, vehicle_id) VALUES (?,?)", [$pid, $vid]);
        }
        if ($qty > 0) {
            add_stock_move($pid, $qty, 'موجودی اولیه',
                date('Y-m-d', strtotime('-100 days')), $buy, 'نمونه', 'دادهٔ نمونه');
        }
    }

    // مشتریان
    $custs = [
        ['مشتری نمونه ۱', '0912-0000001', 'تهران', 'مصرف‌کننده', 'پژو ۴۰۵', 'سایت'],
        ['مشتری نمونه ۲', '0912-0000002', 'کرج', 'تعمیرکار', 'متفرقه', 'معرفی دوستان'],
        ['مشتری نمونه ۳', '0913-0000003', 'اصفهان', 'مصرف‌کننده', 'پژو ۲۰۶', 'اینستاگرام'],
        ['مشتری نمونه ۴', '0915-0000004', 'مشهد', 'عمده‌فروش', '—', 'ترب'],
        ['مشتری نمونه ۵', '0917-0000005', 'شیراز', 'مصرف‌کننده', 'پراید', 'سایت'],
        ['مشتری نمونه ۶', '0914-0000006', 'تبریز', 'تعمیرکار', 'متفرقه', 'واتساپ'],
    ];
    $cids = [];
    foreach ($custs as $c) {
        q("INSERT INTO customers (name, phone, city, ctype, vehicle, source, notes)
           VALUES (?,?,?,?,?,?,'دادهٔ نمونه')", $c);
        $cids[] = (int)db()->lastInsertId();
        $n++;
    }

    // سفارش‌ها در ۵ ماه گذشته
    $statuses = ['تحویل شد','تحویل شد','تحویل شد','تحویل شد','ارسال شد','خرید شد'];
    $cities   = ['تهران','کرج','اصفهان','مشهد','شیراز','تبریز'];
    $seq = 1;

    for ($d = 150; $d >= 1; $d -= 1) {
        // تقریباً هر ۳ روز یک سفارش، با تراکم بیشتر در ماه‌های اخیر
        $chance = $d > 90 ? 5 : ($d > 45 ? 4 : 3);
        if ($d % $chance !== 0) continue;

        $date = date('Y-m-d', strtotime("-$d days"));
        $ts   = strtotime($date);
        [$oy, $om] = gregorian_to_jalali((int)date('Y',$ts), (int)date('n',$ts), (int)date('j',$ts));
        $ono = sprintf('GR-%02d%02d-%03d', $oy % 100, $om, $seq++);

        $cidx = array_rand($cids);
        $nItems = (mt_rand(1, 10) > 7) ? 2 : 1;
        $picked = (array)array_rand($pids, min($nItems, count($pids)));

        $revenue = 0; $cost = 0;
        $rows = [];
        foreach ($picked as $pi) {
            $p = $pids[$pi];
            $qy = mt_rand(1, 10) > 8 ? 2 : 1;
            $rows[] = [$p, $qy];
            $revenue += $p['sell'] * $qy;
            $cost    += $p['buy'] * $qy;
        }

        $ship = [0, 45000, 60000, 85000][array_rand([0,1,2,3])];
        $fee  = (int)round($revenue * 0.01);
        $pack = 12000;
        $isRet = (mt_rand(1, 100) <= 4) ? 1 : 0;
        $st = $statuses[array_rand($statuses)];

        q("INSERT INTO orders (order_no, order_date, customer_id, customer_name, city, status,
               gateway_fee, shipping_cost, packaging_cost, is_returned, return_reason, notes)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,'دادهٔ نمونه')",
          [$ono, $date, $cids[$cidx], null, $cities[array_rand($cities)], $st,
           $fee, $ship, $pack, $isRet,
           $isRet ? 'نمونه: قطعه با خودرو سازگار نبود' : null]);
        $oid = (int)db()->lastInsertId();
        $n++;

        foreach ($rows as [$p, $qy]) {
            q("INSERT INTO order_items (order_id, product_id, product_name, qty, buy_price, sell_price)
               VALUES (?,?,?,?,?,?)", [$oid, $p['id'], $p['name'], $qy, $p['buy'], $p['sell']]);
        }
        // دادهٔ نمونه نیز باید با کاردکس واقعی هماهنگ باشد.
        sync_order_stock($oid);
    }

    // دفتر نداشتیم
    $missedParts = [
        ['دیسک و صفحه کلاچ نمونه', 'پژو ۴۰۵', 5],
        ['واشر سرسیلندر نمونه', 'سمند', 4],
        ['پمپ بنزین نمونه', 'پراید', 3],
        ['سنسور اکسیژن نمونه', 'پژو ۲۰۶', 3],
        ['کمک فنر عقب نمونه', 'تیبا', 2],
        ['رادیاتور آب نمونه', 'دنا', 2],
        ['جلوپنجره نمونه', 'شاهین', 1],
        ['کویل نمونه', 'رانا', 1],
    ];
    $srcs = ['سایت','اینستاگرام','واتساپ','تلفن','تلگرام'];
    foreach ($missedParts as [$pn2, $vn2, $times]) {
        for ($k = 0; $k < $times; $k++) {
            q("INSERT INTO missed (req_date, part_name, vehicle, source, followed_up, resolved, notes)
               VALUES (?,?,?,?,?,0,'دادهٔ نمونه')",
              [date('Y-m-d', strtotime('-' . mt_rand(1, 120) . ' days')),
               $pn2, $vn2, $srcs[array_rand($srcs)], mt_rand(0, 1)]);
            $n++;
        }
    }

    // همگام‌سازی موجودی نهایی
    foreach ($pids as $p) {
        q("UPDATE products SET stock_qty=? WHERE id=?", [product_stock($p['id']), $p['id']]);
    }

    return $n;
}
