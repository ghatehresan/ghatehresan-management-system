<?php
/**
 * لایهٔ دیتابیس — پشتیبانی همزمان از SQLite و MySQL
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $cfg = require __DIR__ . '/config.php';
    $d   = $cfg['db'];

    try {
        if ($d['driver'] === 'mysql') {
            $m   = $d['mysql'];
            $dsn = "mysql:host={$m['host']};port={$m['port']};dbname={$m['database']};charset={$m['charset']}";
            $pdo = new PDO($dsn, $m['username'], $m['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } else {
            $path = $d['sqlite']['path'];
            $dir  = dirname($path);
            if (!is_dir($dir)) @mkdir($dir, 0775, true);
            $pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    } catch (PDOException $ex) {
        $drv = $d['driver'];
        $hint = $drv === 'mysql'
            ? 'مطمئن شوید MySQL در ایکس‌امپ روشن است و دیتابیس «' . e($d['mysql']['database']) . '» ساخته شده.'
            : 'مطمئن شوید پوشهٔ storage قابل نوشتن است.';
        error_log('[Ghatehresan DB] ' . $ex->getMessage());
        $detail = !empty($cfg['app']['debug'])
            ? '<pre style="background:#f5f5f5;padding:14px;border-radius:8px;direction:ltr;'
              . 'text-align:left;overflow:auto;font-size:12px">' . e($ex->getMessage()) . '</pre>'
            : '<p>جزئیات فنی در گزارش خطای سرور ثبت شده است.</p>';
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="fa" dir="rtl"><meta charset="utf-8">'
           . '<body style="font-family:Tahoma;padding:44px;max-width:640px;margin:auto;line-height:2">'
           . '<h2 style="color:#D93025">خطا در اتصال به دیتابیس</h2>'
           . '<p>' . $hint . '</p>' . $detail
           . '<p style="color:#777;font-size:13px">تنظیمات در فایل <code>app/config.php</code></p>'
           . '</body></html>';
        exit;
    }

    return $pdo;
}

function is_mysql(): bool {
    static $r = null;
    if ($r === null) {
        $cfg = require __DIR__ . '/config.php';
        $r = $cfg['db']['driver'] === 'mysql';
    }
    return $r;
}

/** اجرای کوئری با پارامتر */
function q(string $sql, array $params = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function one(string $sql, array $params = []) {
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function all(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}
function scalar(string $sql, array $params = [], $default = 0) {
    $v = q($sql, $params)->fetchColumn();
    return $v === false || $v === null ? $default : $v;
}

/** ساخت جداول */
function migrate(): void {
    $pdo = db();
    $my  = is_mysql();

    $ID   = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $TXT  = $my ? 'VARCHAR(255)' : 'TEXT';
    $LONG = 'TEXT';
    $INT  = $my ? 'BIGINT' : 'INTEGER';
    $NOW  = $my ? 'CURRENT_TIMESTAMP' : "(datetime('now','localtime'))";
    $SUF  = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $tables = [];

    // ── تأمین‌کنندگان ──────────────────────────────────────────────
    $tables['suppliers'] = "
        id              $ID,
        name            $TXT NOT NULL,
        contact_person  $TXT NULL,
        phone           $TXT NULL,
        address         $LONG NULL,
        specialty       $LONG NULL,
        has_price_list  $TXT NULL,
        price_validity  $TXT NULL,
        min_order       $TXT NULL,
        lead_time       $TXT NULL,
        stock_report    $TXT NULL,
        return_policy   $LONG NULL,
        official_invoice $TXT NULL,
        authenticity    $LONG NULL,
        credit_terms    $TXT NULL,
        exclusivity     $TXT NULL,
        rate_reliability INTEGER DEFAULT 0,
        rate_quality     INTEGER DEFAULT 0,
        rate_response    INTEGER DEFAULT 0,
        role            $TXT DEFAULT 'در حال بررسی',
        notes           $LONG NULL,
        last_price_update $TXT NULL,
        active          INTEGER DEFAULT 1,
        created_at      $TXT DEFAULT $NOW
    ";

    // ── دسته‌بندی ──────────────────────────────────────────────────
    $tables['categories'] = "
        id         $ID,
        name       $TXT NOT NULL,
        sort_order INTEGER DEFAULT 0
    ";

    // ── خودروها ────────────────────────────────────────────────────
    $tables['vehicles'] = "
        id         $ID,
        name       $TXT NOT NULL,
        maker      $TXT NULL,
        sort_order INTEGER DEFAULT 0
    ";

    // ── کالاها ─────────────────────────────────────────────────────
    $tables['products'] = "
        id             $ID,
        internal_code  $TXT NULL,
        part_number    $TXT NULL,
        name           $TXT NOT NULL,
        category_id    INTEGER NULL,
        brand          $TXT NULL,
        quality        $TXT NULL,
        supplier_id    INTEGER NULL,
        buy_price      $INT DEFAULT 0,
        sell_price     $INT DEFAULT 0,
        status         $TXT DEFAULT 'فعال',
        lead_days      INTEGER DEFAULT 0,
        stock_qty      INTEGER DEFAULT 0,
        has_photo      INTEGER DEFAULT 0,
        year_engine    $TXT NULL,
        notes          $LONG NULL,
        created_at     $TXT DEFAULT $NOW,
        updated_at     $TXT NULL
    ";

    // ── سازگاری کالا با خودرو (رابطهٔ چندبه‌چند) ──────────────────
    $tables['product_vehicles'] = "
        id         $ID,
        product_id INTEGER NOT NULL,
        vehicle_id INTEGER NOT NULL
    ";

    // ── مشتریان ────────────────────────────────────────────────────
    $tables['customers'] = "
        id         $ID,
        name       $TXT NOT NULL,
        phone      $TXT NULL,
        city       $TXT NULL,
        address    $LONG NULL,
        ctype      $TXT DEFAULT 'مصرف‌کننده',
        vehicle    $TXT NULL,
        source     $TXT NULL,
        notes      $LONG NULL,
        created_at $TXT DEFAULT $NOW
    ";

    // ── سفارش‌ها ───────────────────────────────────────────────────
    $tables['orders'] = "
        id             $ID,
        order_no       $TXT NULL,
        order_date     $TXT NULL,
        customer_id    INTEGER NULL,
        customer_name  $TXT NULL,
        city           $TXT NULL,
        status         $TXT DEFAULT 'ثبت شد',
        gateway_fee    $INT DEFAULT 0,
        shipping_cost  $INT DEFAULT 0,
        packaging_cost $INT DEFAULT 0,
        is_returned    INTEGER DEFAULT 0,
        return_reason  $LONG NULL,
        notes          $LONG NULL,
        created_at     $TXT DEFAULT $NOW
    ";

    // ── اقلام سفارش ────────────────────────────────────────────────
    $tables['order_items'] = "
        id           $ID,
        order_id     INTEGER NOT NULL,
        product_id   INTEGER NULL,
        product_name $TXT NULL,
        qty          INTEGER DEFAULT 1,
        buy_price    $INT DEFAULT 0,
        sell_price   $INT DEFAULT 0
    ";

    // ── دفتر نداشتیم ───────────────────────────────────────────────
    $tables['missed'] = "
        id           $ID,
        req_date     $TXT NULL,
        part_name    $TXT NOT NULL,
        vehicle      $TXT NULL,
        year_engine  $TXT NULL,
        source       $TXT NULL,
        quoted_price $INT DEFAULT 0,
        followed_up  INTEGER DEFAULT 0,
        resolved     INTEGER DEFAULT 0,
        notes        $LONG NULL,
        created_at   $TXT DEFAULT $NOW
    ";

    // ── حرکت موجودی (کاردکس) ───────────────────────────────────────
    $tables['stock_moves'] = "
        id          $ID,
        product_id  INTEGER NOT NULL,
        order_id    INTEGER NULL,
        move_date   $TXT NULL,
        qty         INTEGER NOT NULL,
        kind        $TXT NULL,
        unit_cost   $INT DEFAULT 0,
        ref         $TXT NULL,
        notes       $LONG NULL,
        created_at  $TXT DEFAULT $NOW
    ";

    // ── تنظیمات ────────────────────────────────────────────────────
    $tables['settings'] = "
        k $TXT PRIMARY KEY,
        v $LONG NULL
    ";

    // ── کاربران سامانه ──────────────────────────────────────────────
    $tables['users'] = "
        id            $ID,
        username      $TXT NOT NULL UNIQUE,
        password_hash $TXT NOT NULL,
        name          $TXT NOT NULL,
        role          $TXT DEFAULT 'admin',
        active        INTEGER DEFAULT 1,
        created_at    $TXT DEFAULT $NOW
    ";

    // ── گزارش فعالیت کاربران ───────────────────────────────────────
    $tables['activity_log'] = "
        id           $ID,
        user_id      INTEGER NULL,
        action       $TXT NOT NULL,
        entity_type  $TXT NULL,
        entity_id    INTEGER NULL,
        details      $LONG NULL,
        ip_address   $TXT NULL,
        user_agent   $LONG NULL,
        created_at   $TXT DEFAULT $NOW
    ";

    foreach ($tables as $name => $cols) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$name` ($cols)$SUF");
    }

    // ── ایندکس‌ها ──────────────────────────────────────────────────
    $idx = [
        'idx_prod_name'     => 'products(name)',
        'idx_prod_pn'       => 'products(part_number)',
        'idx_prod_cat'      => 'products(category_id)',
        'idx_prod_sup'      => 'products(supplier_id)',
        'idx_prod_status'   => 'products(status)',
        'idx_pv_prod'       => 'product_vehicles(product_id)',
        'idx_pv_veh'        => 'product_vehicles(vehicle_id)',
        'idx_ord_date'      => 'orders(order_date)',
        'idx_ord_status'    => 'orders(status)',
        'idx_ord_cust'      => 'orders(customer_id)',
        'idx_oi_order'      => 'order_items(order_id)',
        'idx_oi_prod'       => 'order_items(product_id)',
        'idx_missed_date'   => 'missed(req_date)',
        'idx_sm_prod'       => 'stock_moves(product_id)',
        'idx_sm_order'      => 'stock_moves(order_id)',
        'idx_activity_user' => 'activity_log(user_id)',
        'idx_activity_time' => 'activity_log(created_at)',
    ];
    foreach ($idx as $n => $def) {
        try { $pdo->exec("CREATE INDEX IF NOT EXISTS `$n` ON $def"); } catch (Throwable $e) {}
    }

    // ── ارتقای پایگاه دادهٔ قدیمی: افزودن ستون‌های جدید ─────────────
    // این کار داده‌های موجود را دست‌نخورده نگه می‌دارد.
    $patch = [
        'customers' => [
            'address' => "$LONG NULL",
            'ctype'   => "$TXT NULL",
            'vehicle' => "$TXT NULL",
            'source'  => "$TXT NULL",
        ],
        'vehicles'  => ['maker'   => "$TXT NULL"],
        'products'    => ['quality' => "$TXT NULL"],
        'stock_moves' => ['order_id' => "INTEGER NULL"],
    ];
    foreach ($patch as $tbl => $cols) {
        $have = table_columns($tbl);
        if (!$have) continue;
        foreach ($cols as $col => $def) {
            if (in_array($col, $have, true)) continue;
            try { $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN `$col` $def"); } catch (Throwable $e) {}
        }
    }
    // انتقال دادهٔ ستون قدیمی type به ctype (اگر وجود داشته باشد)
    $cc = table_columns('customers');
    if (in_array('type', $cc, true) && in_array('ctype', $cc, true)) {
        try {
            $pdo->exec("UPDATE customers SET ctype = type
                         WHERE (ctype IS NULL OR ctype = '') AND type IS NOT NULL");
        } catch (Throwable $e) {}
    }

    seed_defaults();
}

/** فهرست ستون‌های یک جدول (برای ارتقای امن) */
function table_columns(string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $pdo = db();
    $out = [];
    try {
        if (is_mysql()) {
            foreach ($pdo->query("SHOW COLUMNS FROM `$table`") as $r) $out[] = $r['Field'];
        } else {
            foreach ($pdo->query("PRAGMA table_info(`$table`)") as $r) $out[] = $r['name'];
        }
    } catch (Throwable $e) { $out = []; }
    return $cache[$table] = $out;
}

/** دادهٔ اولیه */
function seed_defaults(): void {
    $pdo = db();

    if ((int)scalar("SELECT COUNT(*) FROM categories") === 0) {
        $cats = ['فیلتر','مصرفی موتور','روغن و مایعات','ترمز',
                 'برقی سبک','کابین و جانبی','جلوبندی','بدنه','سایر'];
        $st = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (?,?)");
        foreach ($cats as $i => $c) $st->execute([$c, $i]);
    }

    if ((int)scalar("SELECT COUNT(*) FROM vehicles") === 0) {
        $vs = ['پژو ۴۰۵','پژو پارس','پژو ۲۰۶','سمند','دنا','رانا',
               'پراید','تیبا','کوییک','شاهین','ال۹۰','سایر'];
        $st = $pdo->prepare("INSERT INTO vehicles (name, sort_order) VALUES (?,?)");
        foreach ($vs as $i => $v) $st->execute([$v, $i]);
    }

    // خودروهای تکمیلی مورد استفاده در دادهٔ نمونه و کاتالوگ قطعات.
    foreach ([['ساینا', 'سایپا'], ['ساندرو', 'رنو']] as [$vn, $maker]) {
        if (one("SELECT id FROM vehicles WHERE name=?", [$vn]) === null) {
            q("INSERT INTO vehicles (name, maker, sort_order) VALUES (?, ?, ?)",
              [$vn, $maker, (int)scalar("SELECT COALESCE(MAX(sort_order),0)+1 FROM vehicles")]);
        }
    }

    $cfg = require __DIR__ . '/config.php';
    $defs = [
        'gateway_fee_percent' => (string)$cfg['defaults']['gateway_fee_percent'],
        'packaging_cost'      => (string)$cfg['defaults']['packaging_cost'],
        'stock_min_sales_90d' => (string)$cfg['defaults']['stock_min_sales_90d'],
        'business_name'       => 'قطعه‌رسان',
        'schema_version'      => '2',
    ];
    foreach ($defs as $k => $v) {
        if (one("SELECT k FROM settings WHERE k=?", [$k]) === null) {
            q("INSERT INTO settings (k,v) VALUES (?,?)", [$k, $v]);
        }
    }
}

// ── تنظیمات ────────────────────────────────────────────────────────
function setting($k, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (all("SELECT k,v FROM settings") as $r) $cache[$r['k']] = $r['v'];
    }
    return $cache[$k] ?? $default;
}
function set_setting($k, $v) {
    if (one("SELECT k FROM settings WHERE k=?", [$k]) === null) {
        q("INSERT INTO settings (k,v) VALUES (?,?)", [$k, $v]);
    } else {
        q("UPDATE settings SET v=? WHERE k=?", [$v, $k]);
    }
}

// ── محاسبات مالی مرکزی ─────────────────────────────────────────────

/** مجموع فروش و خرید اقلام یک سفارش */
function order_item_totals(int $orderId): array {
    $r = one("SELECT
                COALESCE(SUM(sell_price * qty),0) AS revenue,
                COALESCE(SUM(buy_price  * qty),0) AS cost,
                COALESCE(SUM(qty),0)              AS units
              FROM order_items WHERE order_id=?", [$orderId]);
    return [
        'revenue' => (int)($r['revenue'] ?? 0),
        'cost'    => (int)($r['cost'] ?? 0),
        'units'   => (int)($r['units'] ?? 0),
    ];
}

/** سود خالص یک سفارش با تمام هزینه‌ها */
function order_profit(array $order): array {
    $t   = order_item_totals((int)$order['id']);
    $rev = $t['revenue'];
    $exp = $t['cost']
         + (int)$order['gateway_fee']
         + (int)$order['shipping_cost']
         + (int)$order['packaging_cost'];
    $net = $rev - $exp;
    return [
        'revenue' => $rev,
        'cost'    => $t['cost'],
        'units'   => $t['units'],
        'gross'   => $rev - $t['cost'],
        'net'     => $net,
        'margin'  => $rev > 0 ? $net / $rev : 0.0,
    ];
}

/** موجودی واقعی یک کالا از روی کاردکس */
function product_stock(int $productId): int {
    return (int)scalar("SELECT COALESCE(SUM(qty),0) FROM stock_moves WHERE product_id=?", [$productId]);
}

/** ثبت حرکت موجودی */
function add_stock_move(int $productId, int $qty, string $kind, $date = null,
                        int $unitCost = 0, $ref = null, $notes = null,
                        ?int $orderId = null): void {
    $pdo = db();
    $local = !$pdo->inTransaction();
    if ($local) $pdo->beginTransaction();
    try {
        q("INSERT INTO stock_moves
              (product_id, order_id, move_date, qty, kind, unit_cost, ref, notes)
           VALUES (?,?,?,?,?,?,?,?)",
          [$productId, $orderId, $date ?: date('Y-m-d'), $qty, $kind,
           $unitCost, $ref, $notes]);
        q("UPDATE products SET stock_qty = ? WHERE id = ?",
          [product_stock($productId), $productId]);
        if ($local) $pdo->commit();
    } catch (Throwable $e) {
        if ($local && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** حذف حرکت‌های فروش وابسته به یک سفارش و بازسازی موجودی کالاها */
function remove_order_stock_moves(int $orderId, ?string $orderNo = null): void {
    $where = 'order_id=?';
    $params = [$orderId];
    if ($orderNo !== null && $orderNo !== '') {
        $where .= " OR (order_id IS NULL AND kind=? AND ref=?)";
        $params[] = 'فروش';
        $params[] = $orderNo;
    }
    $moves = all("SELECT DISTINCT product_id FROM stock_moves WHERE $where", $params);
    q("DELETE FROM stock_moves WHERE $where", $params);
    foreach ($moves as $move) {
        $pid = (int)$move['product_id'];
        q("UPDATE products SET stock_qty=? WHERE id=?", [product_stock($pid), $pid]);
    }
}

/** همگام‌سازی موجودی با وضعیت فعلی سفارش */
function sync_order_stock(int $orderId, ?string $previousOrderNo = null): void {
    $pdo = db();
    $order = one("SELECT * FROM orders WHERE id=?", [$orderId]);
    if (!$order) return;

    $local = !$pdo->inTransaction();
    if ($local) $pdo->beginTransaction();
    try {
        remove_order_stock_moves($orderId, $previousOrderNo ?: ($order['order_no'] ?? null));

        // سفارش لغوشده یا مرجوعی، فروشِ در جریان برای کسر موجودی ندارد.
        if ($order['status'] !== 'لغو شد' && empty($order['is_returned'])) {
            $items = all("SELECT product_id, qty, buy_price
                            FROM order_items WHERE order_id=?", [$orderId]);
            $byProduct = [];
            foreach ($items as $item) {
                $pid = (int)$item['product_id'];
                $qty = (int)$item['qty'];
                if ($pid <= 0 || $qty <= 0) continue;
                if (!isset($byProduct[$pid])) {
                    $byProduct[$pid] = ['qty' => 0, 'buy' => (int)$item['buy_price']];
                }
                $byProduct[$pid]['qty'] += $qty;
            }
            foreach ($byProduct as $pid => $item) {
                // کالای سفارش‌محور موجودی ندارد و از کاردکس کسر نمی‌شود.
                if (product_stock((int)$pid) <= 0) continue;
                add_stock_move((int)$pid, -$item['qty'], 'فروش',
                    $order['order_date'], $item['buy'], $order['order_no'],
                    'کسر خودکار هنگام ثبت یا ویرایش سفارش', $orderId);
            }
        }
        if ($local) $pdo->commit();
    } catch (Throwable $e) {
        if ($local && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** تعداد فروش یک کالا در N روز گذشته */
function product_sales_in_days(int $productId, int $days = 90): int {
    $since = date('Y-m-d', strtotime("-$days days"));
    return (int)scalar(
        "SELECT COALESCE(SUM(oi.qty),0)
           FROM order_items oi
           JOIN orders o ON o.id = oi.order_id
          WHERE oi.product_id = ?
            AND o.status <> 'لغو شد'
            AND o.is_returned = 0
            AND o.order_date >= ?", [$productId, $since]);
}
