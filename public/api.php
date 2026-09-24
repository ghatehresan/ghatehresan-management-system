<?php
/** API همگام‌سازی وب‌سایت وردپرسی و سامانهٔ مدیریت */

$cfg = require __DIR__ . '/../app/config.php';
date_default_timezone_set($cfg['app']['timezone']);

if (!defined('APP_PATH')) define('APP_PATH', dirname(__DIR__) . '/app');
if (!defined('DB_DRIVER')) define('DB_DRIVER', $cfg['db']['driver']);
if (!defined('SQLITE_PATH')) define('SQLITE_PATH', $cfg['db']['sqlite']['path']);
if (!defined('DB_NAME')) define('DB_NAME', $cfg['db']['mysql']['database']);
if (!defined('DB_HOST')) define('DB_HOST', $cfg['db']['mysql']['host']);

require __DIR__ . '/../app/helpers.php';
require __DIR__ . '/../app/db.php';
migrate();

function api_json($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $code, string $message, int $status = 400): void {
    api_json(['ok' => false, 'error' => ['code' => $code, 'message' => $message]], $status);
}

function api_header(string $name): string {
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$key] ?? ''));
}

function api_authenticate(string $body): void {
    if (setting('api_enabled', '0') !== '1') {
        api_error('api_disabled', 'اتصال API فعال نیست.', 503);
    }

    $key = (string)setting('api_key', '');
    $secret = (string)setting('api_secret', '');
    $givenKey = api_header('X-Ghatehresan-Key');
    $timestamp = api_header('X-Ghatehresan-Timestamp');
    $signature = api_header('X-Ghatehresan-Signature');

    if ($key === '' || $secret === '' || $givenKey === '' || $timestamp === '' || $signature === '') {
        api_error('unauthorized', 'اطلاعات احراز هویت API کامل نیست.', 401);
    }
    if (!hash_equals($key, $givenKey)) {
        api_error('unauthorized', 'کلید API معتبر نیست.', 401);
    }
    if (!ctype_digit($timestamp) || abs(time() - (int)$timestamp) > 300) {
        api_error('stale_request', 'زمان درخواست معتبر نیست.', 401);
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/api.php', PHP_URL_PATH) ?: '/api.php';
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    $canonical = $timestamp . "\n" . strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') . "\n"
               . $path . ($query !== '' ? '?' . $query : '') . "\n" . $body;
    $expected = hash_hmac('sha256', $canonical, $secret);
    if (!hash_equals($expected, $signature)) {
        api_error('bad_signature', 'امضای درخواست معتبر نیست.', 401);
    }
}

function api_json_body(string $body): array {
    $data = json_decode($body, true);
    if (!is_array($data)) api_error('invalid_json', 'بدنهٔ JSON معتبر نیست.', 422);
    return $data;
}

function api_product_sku(array $row): string {
    $sku = trim((string)($row['internal_code'] ?? ''));
    if ($sku === '') $sku = trim((string)($row['part_number'] ?? ''));
    return $sku !== '' ? $sku : 'GR-' . str_pad((string)$row['id'], 6, '0', STR_PAD_LEFT);
}

function api_product_payload(array $row): array {
    return [
        'id'          => (int)$row['id'],
        'sku'         => api_product_sku($row),
        'internal_code'=> $row['internal_code'],
        'part_number' => $row['part_number'],
        'name'        => $row['name'],
        'brand'       => $row['brand'],
        'quality'     => $row['quality'],
        'category'    => $row['category_name'],
        'buy_price'   => (int)$row['buy_price'],
        'sell_price'  => (int)$row['sell_price'],
        'stock_qty'   => (int)$row['stock_qty'],
        'status'      => $row['status'],
        'notes'       => $row['notes'],
        'updated_at'  => $row['updated_at'] ?: $row['created_at'],
    ];
}

function api_products(): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per_page'] ?? 100)));
    $since = trim((string)($_GET['since'] ?? ''));
    $where = [];
    $params = [];
    if ($since !== '') {
        $where[] = 'COALESCE(p.updated_at, p.created_at) >= ?';
        $params[] = $since;
    }
    $condition = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)scalar("SELECT COUNT(*) FROM products p $condition", $params);
    $offset = ($page - 1) * $per;
    $rows = all("SELECT p.*, c.name AS category_name,
                        COALESCE(p.updated_at, p.created_at) AS sync_updated_at
                   FROM products p
                   LEFT JOIN categories c ON c.id=p.category_id
                   $condition
                  ORDER BY sync_updated_at ASC, p.id ASC
                  LIMIT $per OFFSET $offset", $params);
    foreach ($rows as &$row) $row['updated_at'] = $row['sync_updated_at'];
    unset($row);

    $items = array_map('api_product_payload', $rows);
    api_json(['ok' => true, 'data' => $items, 'meta' => [
        'page' => $page,
        'per_page' => $per,
        'total' => $total,
        'next_page' => ($offset + count($items)) < $total ? $page + 1 : null,
    ]]);
}

function api_order_payload(array $row): array {
    return [
        'id'                 => (int)$row['id'],
        'order_no'           => $row['order_no'],
        'external_source'    => $row['external_source'],
        'external_order_id'  => $row['external_order_id'],
        'status'             => $row['status'],
        'is_returned'        => (int)$row['is_returned'],
        'return_reason'      => $row['return_reason'],
        'updated_at'         => $row['updated_at'] ?: $row['created_at'],
    ];
}

function api_orders(): void {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $per = min(100, max(1, (int)($_GET['per_page'] ?? 100)));
    $since = trim((string)($_GET['since'] ?? ''));
    $source = trim((string)($_GET['source'] ?? ''));
    $where = [];
    $params = [];
    if ($source !== '') { $where[] = 'o.external_source=?'; $params[] = $source; }
    if ($since !== '') {
        $where[] = 'COALESCE(o.updated_at, o.created_at) >= ?';
        $params[] = $since;
    }
    $condition = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $total = (int)scalar("SELECT COUNT(*) FROM orders o $condition", $params);
    $offset = ($page - 1) * $per;
    $rows = all("SELECT o.*, COALESCE(o.updated_at, o.created_at) AS sync_updated_at
                   FROM orders o $condition
                  ORDER BY sync_updated_at ASC, o.id ASC
                  LIMIT $per OFFSET $offset", $params);
    foreach ($rows as &$row) $row['updated_at'] = $row['sync_updated_at'];
    unset($row);

    api_json(['ok' => true, 'data' => array_map('api_order_payload', $rows), 'meta' => [
        'page' => $page,
        'per_page' => $per,
        'total' => $total,
        'next_page' => ($offset + count($rows)) < $total ? $page + 1 : null,
    ]]);
}

function api_find_product(array $item) {
    $id = to_int($item['management_product_id'] ?? $item['product_id'] ?? 0);
    if ($id) {
        $row = one("SELECT * FROM products WHERE id=?", [$id]);
        if ($row) return $row;
    }
    $code = trim((string)($item['sku'] ?? $item['internal_code'] ?? ''));
    if ($code !== '') {
        $row = one("SELECT * FROM products WHERE internal_code=? OR part_number=? LIMIT 1", [$code, $code]);
        if ($row) return $row;
    }
    return null;
}

function api_create_order(array $payload): void {
    $source = preg_replace('/[^A-Za-z0-9_.-]/', '', (string)($payload['source'] ?? ''));
    $externalId = trim((string)($payload['external_id'] ?? ''));
    if ($source === '' || $externalId === '') {
        api_error('missing_external_id', 'منبع و شناسهٔ بیرونی سفارش الزامی است.', 422);
    }

    $existing = one("SELECT * FROM orders WHERE external_source=? AND external_order_id=?",
                    [$source, $externalId]);
    if ($existing) {
        api_json(['ok' => true, 'created' => false, 'data' => api_order_payload($existing)]);
    }

    $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
    $clean = [];
    $missing = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $qty = to_int($item['qty'] ?? 0);
        if ($qty <= 0) continue;
        $product = api_find_product($item);
        if (!$product) {
            $missing[] = (string)($item['sku'] ?? $item['name'] ?? 'نامشخص');
            continue;
        }
        $sell = to_int($item['sell_price'] ?? $product['sell_price']);
        $clean[] = [
            'product_id' => (int)$product['id'],
            'product_name' => $product['name'],
            'qty' => $qty,
            'buy_price' => (int)$product['buy_price'],
            'sell_price' => max(0, $sell),
        ];
    }
    if ($missing) {
        api_json(['ok' => false, 'error' => [
            'code' => 'product_not_found',
            'message' => 'یک یا چند کالا در سامانه پیدا نشد.',
            'items' => $missing,
        ]], 422);
    }
    if (!$clean) api_error('empty_order', 'سفارش حداقل باید یک قلم معتبر داشته باشد.', 422);

    $status = trim((string)($payload['status'] ?? 'ثبت شد'));
    $allowedStatus = ['ثبت شد','خرید شد','ارسال شد','تحویل شد','لغو شد'];
    if (!in_array($status, $allowedStatus, true)) $status = 'ثبت شد';
    $date = trim((string)($payload['order_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
    $customerName = trim((string)($customer['name'] ?? $payload['customer_name'] ?? 'مشتری وب‌سایت'));
    $phone = trim((string)($customer['phone'] ?? ''));
    $city = trim((string)($customer['city'] ?? ''));
    $address = trim((string)($customer['address'] ?? ''));
    $customerId = null;

    $pdo = db();
    try {
        $pdo->beginTransaction();
        if ($phone !== '') {
            $found = one("SELECT id FROM customers WHERE phone=? ORDER BY id LIMIT 1", [$phone]);
            if ($found) {
                $customerId = (int)$found['id'];
            } else {
                q("INSERT INTO customers (name, phone, city, address, source, notes)
                   VALUES (?,?,?,?,?,?)",
                  [$customerName, $phone, $city, $address, 'وب‌سایت', 'ثبت خودکار از ووکامرس']);
                $customerId = (int)db()->lastInsertId();
            }
        }

        $orderNo = trim((string)($payload['order_no'] ?? '')) ?: 'WEB-' . $externalId;
        $now = date('Y-m-d H:i:s');
        q("INSERT INTO orders
             (order_no, order_date, customer_id, customer_name, city, status,
              gateway_fee, shipping_cost, packaging_cost, is_returned, return_reason, notes,
              external_source, external_order_id, updated_at)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)", [
            $orderNo, $date, $customerId, $customerName, $city, $status,
            to_int($payload['gateway_fee'] ?? 0), to_int($payload['shipping_cost'] ?? 0),
            to_int($payload['packaging_cost'] ?? 0), 0, null,
            (string)($payload['notes'] ?? 'ثبت خودکار از وب‌سایت'),
            $source, $externalId, $now,
        ]);
        $orderId = (int)db()->lastInsertId();

        $st = db()->prepare("INSERT INTO order_items
              (order_id, product_id, product_name, qty, buy_price, sell_price)
              VALUES (?,?,?,?,?,?)");
        foreach ($clean as $item) {
            $st->execute([$orderId, $item['product_id'], $item['product_name'],
                          $item['qty'], $item['buy_price'], $item['sell_price']]);
        }
        sync_order_stock($orderId);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[Ghatehresan API order] ' . $ex->getMessage());
        api_error('order_save_failed', 'ثبت سفارش در سامانه انجام نشد.', 500);
    }

    activity_log('order_created', 'order', $orderId,
        'source=' . $source . ';external_id=' . $externalId);
    $saved = one("SELECT * FROM orders WHERE id=?", [$orderId]);
    api_json(['ok' => true, 'created' => true, 'data' => api_order_payload($saved)], 201);
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$body = file_get_contents('php://input') ?: '';
api_authenticate($body);
$resource = trim((string)($_GET['resource'] ?? 'health'));

if ($resource === 'health' && $method === 'GET') {
    api_json(['ok' => true, 'data' => [
        'app' => 'قطعه‌رسان',
        'api_version' => '1',
        'time' => date('c'),
    ]]);
}
if ($resource === 'products' && $method === 'GET') api_products();
if ($resource === 'orders' && $method === 'GET') api_orders();
if ($resource === 'orders' && $method === 'POST') api_create_order(api_json_body($body));

api_error('not_found', 'منبع یا روش درخواست معتبر نیست.', 404);
