<?php
/**
 * Plugin Name: قطعه‌رسان — همگام‌سازی ووکامرس
 * Plugin URI: https://example.com/
 * Description: همگام‌سازی کالا، قیمت، موجودی و سفارش بین ووکامرس و سامانهٔ مدیریت قطعه‌رسان.
 * Version: 0.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: قطعه‌رسان
 * Text Domain: ghatehresan-sync
 */

if (!defined('ABSPATH')) exit;

final class Ghatehresan_WC_Sync {
    const VERSION = '0.1.0';
    const CRON_HOOK = 'ghatehresan_sync_cron';
    const PUSH_HOOK = 'ghatehresan_push_order';
    const OPT_API_URL = 'ghatehresan_api_url';
    const OPT_API_KEY = 'ghatehresan_api_key';
    const OPT_API_SECRET = 'ghatehresan_api_secret';
    const OPT_ENABLED = 'ghatehresan_enabled';
    const OPT_PRICE_MULTIPLIER = 'ghatehresan_price_multiplier';
    const OPT_PRODUCT_CURSOR = 'ghatehresan_product_cursor';
    const OPT_ORDER_CURSOR = 'ghatehresan_order_cursor';
    const OPT_LAST_RUN = 'ghatehresan_last_run';
    const OPT_LAST_ERROR = 'ghatehresan_last_error';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_ghatehresan_test_connection', [$this, 'test_connection_action']);
        add_action('admin_post_ghatehresan_sync_now', [$this, 'sync_now_action']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_filter('cron_schedules', [$this, 'cron_schedules']);
        add_action(self::CRON_HOOK, [$this, 'cron_sync']);
        add_action(self::PUSH_HOOK, [$this, 'push_order'], 10, 1);

        // سفارش فقط یک بار در سامانه ثبت می‌شود؛ تغییرات بعدی وضعیت از سامانه به سایت می‌آیند.
        add_action('woocommerce_checkout_order_processed', [$this, 'queue_order'], 20, 1);
        add_action('woocommerce_new_order', [$this, 'queue_order'], 20, 1);
    }

    public static function activate(): void {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 60, 'ghatehresan_15min', self::CRON_HOOK);
        }
    }

    public static function deactivate(): void {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) wp_unschedule_event($timestamp, self::CRON_HOOK);
    }

    public function cron_schedules(array $schedules): array {
        $schedules['ghatehresan_15min'] = [
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display'  => 'هر ۱۵ دقیقه — قطعه‌رسان',
        ];
        return $schedules;
    }

    public function admin_menu(): void {
        add_submenu_page(
            'woocommerce',
            'همگام‌سازی قطعه‌رسان',
            'همگام‌سازی قطعه‌رسان',
            'manage_woocommerce',
            'ghatehresan-sync',
            [$this, 'settings_page']
        );
    }

    public function register_settings(): void {
        register_setting('ghatehresan_sync', self::OPT_API_URL, [
            'sanitize_callback' => 'esc_url_raw',
            'default' => '',
        ]);
        register_setting('ghatehresan_sync', self::OPT_API_KEY, [
            'sanitize_callback' => function ($value) {
                return preg_replace('/[^A-Za-z0-9_.-]/', '', (string)$value);
            },
            'default' => '',
        ]);
        register_setting('ghatehresan_sync', self::OPT_API_SECRET, [
            'sanitize_callback' => function ($value) {
                return trim((string)$value);
            },
            'default' => '',
        ]);
        register_setting('ghatehresan_sync', self::OPT_ENABLED, [
            'sanitize_callback' => function ($value) { return $value ? '1' : '0'; },
            'default' => '0',
        ]);
        register_setting('ghatehresan_sync', self::OPT_PRICE_MULTIPLIER, [
            'sanitize_callback' => function ($value) {
                $n = (float)$value;
                return (string)($n > 0 ? $n : 1);
            },
            'default' => '1',
        ]);
    }

    public function admin_notices(): void {
        if (!current_user_can('manage_woocommerce')) return;
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p><b>قطعه‌رسان:</b> برای استفاده از پلاگین، ووکامرس باید فعال باشد.</p></div>';
        }
        if (get_option(self::OPT_LAST_ERROR, '')) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            if ($screen && $screen->id === 'woocommerce_page_ghatehresan-sync') {
                echo '<div class="notice notice-warning"><p><b>آخرین خطای همگام‌سازی:</b> '
                   . esc_html(get_option(self::OPT_LAST_ERROR)) . '</p></div>';
            }
        }
    }

    private function option(string $key, $default = '') {
        return get_option($key, $default);
    }

    private function configured(): bool {
        return $this->option(self::OPT_ENABLED, '0') === '1'
            && trim((string)$this->option(self::OPT_API_URL, '')) !== ''
            && trim((string)$this->option(self::OPT_API_KEY, '')) !== ''
            && trim((string)$this->option(self::OPT_API_SECRET, '')) !== '';
    }

    public function settings_page(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی غیرمجاز');
        $message = sanitize_key($_GET['gh_message'] ?? '');
        $counts = [
            'products' => (int)get_option('ghatehresan_last_products_count', 0),
            'orders' => (int)get_option('ghatehresan_last_orders_count', 0),
        ];
        ?>
        <div class="wrap" dir="rtl">
          <h1>همگام‌سازی قطعه‌رسان</h1>
          <?php if ($message === 'ok'): ?><div class="notice notice-success"><p>عملیات با موفقیت انجام شد.</p></div><?php endif; ?>
          <?php if ($message === 'error'): ?><div class="notice notice-error"><p>عملیات انجام نشد؛ جزئیات در پیام خطا آمده است.</p></div><?php endif; ?>
          <p>سامانهٔ مدیریت قطعه‌رسان مرجع قیمت و موجودی است. این پلاگین کالاها را از سامانه می‌خواند و سفارش‌های ووکامرس را یک‌بار به سامانه ارسال می‌کند.</p>

          <form method="post" action="options.php">
            <?php settings_fields('ghatehresan_sync'); ?>
            <table class="form-table" role="presentation">
              <tr>
                <th scope="row">اتصال فعال</th>
                <td><label><input type="checkbox" name="<?= esc_attr(self::OPT_ENABLED) ?>" value="1" <?= checked($this->option(self::OPT_ENABLED, '0'), '1', false) ?>> فعال باشد</label></td>
              </tr>
              <tr>
                <th scope="row"><label for="gh_api_url">آدرس API سامانه</label></th>
                <td>
                  <input id="gh_api_url" class="regular-text code" type="url" dir="ltr" name="<?= esc_attr(self::OPT_API_URL) ?>" value="<?= esc_attr($this->option(self::OPT_API_URL)) ?>" placeholder="https://example.com/ghatehresan/public/api.php">
                  <p class="description">آدرس کامل فایل <code>public/api.php</code> را وارد کنید.</p>
                </td>
              </tr>
              <tr>
                <th scope="row"><label for="gh_api_key">کلید API</label></th>
                <td><input id="gh_api_key" class="regular-text code" type="text" dir="ltr" name="<?= esc_attr(self::OPT_API_KEY) ?>" value="<?= esc_attr($this->option(self::OPT_API_KEY)) ?>" autocomplete="off"></td>
              </tr>
              <tr>
                <th scope="row"><label for="gh_api_secret">رمز API</label></th>
                <td><input id="gh_api_secret" class="large-text code" type="password" dir="ltr" name="<?= esc_attr(self::OPT_API_SECRET) ?>" value="<?= esc_attr($this->option(self::OPT_API_SECRET)) ?>" autocomplete="new-password"></td>
              </tr>
              <tr>
                <th scope="row"><label for="gh_price_multiplier">ضریب تبدیل قیمت</label></th>
                <td>
                  <input id="gh_price_multiplier" class="small-text" type="number" min="0.0001" step="0.01" dir="ltr" name="<?= esc_attr(self::OPT_PRICE_MULTIPLIER) ?>" value="<?= esc_attr($this->option(self::OPT_PRICE_MULTIPLIER, '1')) ?>">
                  <p class="description">اگر واحد قیمت سایت ریال و سامانه تومان است، مقدار ۰٫۱ وارد کنید؛ حالت معمول ۱ است.</p>
                </td>
              </tr>
            </table>
            <?php submit_button('ذخیره تنظیمات'); ?>
          </form>

          <hr>
          <h2>عملیات</h2>
          <p>آخرین اجرای محصولات: <code dir="ltr"><?= esc_html($this->option(self::OPT_PRODUCT_CURSOR, '—')) ?></code> — <?= esc_html((string)$counts['products']) ?> مورد</p>
          <p>آخرین اجرای سفارش‌ها: <code dir="ltr"><?= esc_html($this->option(self::OPT_ORDER_CURSOR, '—')) ?></code> — <?= esc_html((string)$counts['orders']) ?> مورد</p>
          <p>
            <a class="button" href="<?= esc_url(wp_nonce_url(admin_url('admin-post.php?action=ghatehresan_test_connection'), 'gh_test_connection')) ?>">تست اتصال</a>
            <a class="button button-primary" href="<?= esc_url(wp_nonce_url(admin_url('admin-post.php?action=ghatehresan_sync_now'), 'gh_sync_now')) ?>">همگام‌سازی همین حالا</a>
          </p>
        </div>
        <?php
    }

    public function test_connection_action(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('gh_test_connection');
        $result = $this->api_request('GET', 'health');
        if (is_wp_error($result)) {
            update_option(self::OPT_LAST_ERROR, $result->get_error_message(), false);
            $this->redirect_admin('error');
        }
        delete_option(self::OPT_LAST_ERROR);
        $this->redirect_admin('ok');
    }

    public function sync_now_action(): void {
        if (!current_user_can('manage_woocommerce')) wp_die('دسترسی غیرمجاز');
        check_admin_referer('gh_sync_now');
        $products = $this->sync_products();
        $orders = $this->sync_orders();
        if (is_wp_error($products) || is_wp_error($orders)) {
            $err = is_wp_error($products) ? $products : $orders;
            update_option(self::OPT_LAST_ERROR, $err->get_error_message(), false);
            $this->redirect_admin('error');
        }
        $this->flush_pending_orders();
        delete_option(self::OPT_LAST_ERROR);
        $this->redirect_admin('ok');
    }

    private function redirect_admin(string $message): void {
        wp_safe_redirect(add_query_arg([
            'page' => 'ghatehresan-sync',
            'gh_message' => $message,
        ], admin_url('admin.php')));
        exit;
    }

    public function cron_sync(): void {
        if (!$this->configured() || !class_exists('WooCommerce')) return;
        $products = $this->sync_products();
        $orders = $this->sync_orders();
        $this->flush_pending_orders();
        if (is_wp_error($products)) update_option(self::OPT_LAST_ERROR, $products->get_error_message(), false);
        elseif (is_wp_error($orders)) update_option(self::OPT_LAST_ERROR, $orders->get_error_message(), false);
        else {
            delete_option(self::OPT_LAST_ERROR);
            update_option(self::OPT_LAST_RUN, current_time('mysql'), false);
        }
    }

    public function queue_order($order_id): void {
        $order_id = absint($order_id);
        if (!$order_id || !$this->configured()) return;
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_gh_management_order_id', true)) return;
        $order->update_meta_data('_gh_sync_pending', '1');
        $order->save();
        if (!wp_next_scheduled(self::PUSH_HOOK, [$order_id])) {
            wp_schedule_single_event(time() + 10, self::PUSH_HOOK, [$order_id]);
        }
    }

    public function push_order($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_meta('_gh_management_order_id', true)) return;
        $payload = $this->order_payload($order);
        $result = $this->api_request('POST', 'orders', [], $payload);
        if (is_wp_error($result)) {
            $order->update_meta_data('_gh_sync_last_error', $result->get_error_message());
            $order->update_meta_data('_gh_sync_pending', '1');
            $order->save();
            update_option(self::OPT_LAST_ERROR, $result->get_error_message(), false);
            return;
        }
        $remote = $result['data'] ?? [];
        $order->update_meta_data('_gh_management_order_id', (int)($remote['id'] ?? 0));
        $order->update_meta_data('_gh_management_order_no', sanitize_text_field($remote['order_no'] ?? ''));
        $order->update_meta_data('_gh_management_status', sanitize_text_field($remote['status'] ?? ''));
        $order->update_meta_data('_gh_sync_pending', '0');
        $order->delete_meta_data('_gh_sync_last_error');
        $order->save();
    }

    private function order_payload(WC_Order $order): array {
        $items = [];
        foreach ($order->get_items('line_item') as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $management_id = get_post_meta($product->get_id(), '_gh_management_product_id', true);
            $qty = max(1, (int)$item->get_quantity());
            $line_total = (float)$item->get_total();
            if ($line_total <= 0) $line_total = (float)$product->get_price() * $qty;
            $items[] = [
                'management_product_id' => $management_id ? (int)$management_id : 0,
                'sku' => (string)$product->get_sku(),
                'name' => (string)$item->get_name(),
                'qty' => $qty,
                'sell_price' => $this->money_to_management($line_total / $qty),
            ];
        }
        $created = $order->get_date_created();
        $customerName = trim($order->get_formatted_billing_full_name());
        if ($customerName === '') $customerName = 'مشتری وب‌سایت';
        $fees = 0;
        foreach ($order->get_fees() as $fee) $fees += (float)$fee->get_total();
        return [
            'source' => 'woocommerce',
            'external_id' => (string)$order->get_id(),
            'order_no' => (string)$order->get_order_number(),
            'order_date' => $created ? $created->date('Y-m-d') : current_time('Y-m-d'),
            'status' => $this->wc_to_management_status($order->get_status()),
            'gateway_fee' => $this->money_to_management($fees),
            'shipping_cost' => $this->money_to_management((float)$order->get_shipping_total()),
            'packaging_cost' => 0,
            'customer' => [
                'name' => $customerName,
                'phone' => (string)$order->get_billing_phone(),
                'city' => (string)$order->get_billing_city(),
                'address' => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
            ],
            'notes' => 'شناسه ووکامرس: ' . $order->get_id(),
            'items' => $items,
        ];
    }

    private function money_to_management($value): int {
        $multiplier = (float)$this->option(self::OPT_PRICE_MULTIPLIER, '1');
        return (int)round((float)$value * ($multiplier > 0 ? $multiplier : 1));
    }

    private function wc_to_management_status(string $status): string {
        $map = [
            'processing' => 'خرید شد',
            'completed'  => 'تحویل شد',
            'cancelled'  => 'لغو شد',
            'refunded'   => 'لغو شد',
            'failed'     => 'لغو شد',
        ];
        return $map[$status] ?? 'ثبت شد';
    }

    private function management_to_wc_status(string $status): string {
        $map = [
            'ثبت شد'   => 'on-hold',
            'خرید شد'  => 'processing',
            'تحویل شد' => 'completed',
            'لغو شد'   => 'cancelled',
        ];
        return $map[$status] ?? '';
    }

    public function sync_products() {
        if (!$this->configured()) return new WP_Error('not_configured', 'تنظیمات اتصال کامل نیست.');
        $since = (string)$this->option(self::OPT_PRODUCT_CURSOR, '');
        $page = 1;
        $count = 0;
        $maxUpdated = $since;
        do {
            $result = $this->api_request('GET', 'products', [
                'page' => $page,
                'per_page' => 100,
                'since' => $since,
            ]);
            if (is_wp_error($result)) return $result;
            foreach (($result['data'] ?? []) as $item) {
                if ($this->sync_product($item)) $count++;
                $updated = (string)($item['updated_at'] ?? '');
                if ($updated !== '' && ($maxUpdated === '' || $updated > $maxUpdated)) $maxUpdated = $updated;
            }
            $page = (int)($result['meta']['next_page'] ?? 0);
        } while ($page > 0 && $page <= 50);

        if ($maxUpdated !== '') update_option(self::OPT_PRODUCT_CURSOR, $maxUpdated, false);
        update_option('ghatehresan_last_products_count', $count, false);
        return $count;
    }

    private function sync_product(array $item): bool {
        if (empty($item['id']) || empty($item['name'])) return false;
        $managementId = (int)$item['id'];
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => '_gh_management_product_id',
            'meta_value' => (string)$managementId,
            'suppress_filters' => true,
        ]);
        $productId = !empty($ids) ? (int)$ids[0] : 0;
        if (!$productId && !empty($item['sku'])) $productId = (int)wc_get_product_id_by_sku((string)$item['sku']);

        try {
            $product = $productId ? wc_get_product($productId) : new WC_Product_Simple();
            if (!$product) $product = new WC_Product_Simple();
            $product->set_name(sanitize_text_field($item['name']));
            if (!$product->get_sku() || $product->get_sku() === (string)($item['sku'] ?? '')) {
                try { $product->set_sku(sanitize_text_field($item['sku'] ?? '')); } catch (Throwable $e) {}
            }
            $product->set_regular_price((string)$this->money_to_management_reverse($item['sell_price'] ?? 0));
            $product->set_manage_stock(true);
            $stock = (int)($item['stock_qty'] ?? 0);
            $product->set_stock_quantity(max(0, $stock));
            $status = (string)($item['status'] ?? 'فعال');
            $product->set_stock_status($status === 'حذف‌شده' || $status === 'موقتاً ناموجود' || $stock <= 0 ? 'outofstock' : 'instock');
            $product->set_status(in_array($status, ['فعال', 'موقتاً ناموجود'], true) ? 'publish' : 'draft');
            $savedId = $product->save();
            update_post_meta($savedId, '_gh_management_product_id', $managementId);
            update_post_meta($savedId, '_gh_management_updated_at', sanitize_text_field($item['updated_at'] ?? ''));
            if (!empty($item['category'])) {
                wp_set_object_terms($savedId, [sanitize_text_field($item['category'])], 'product_cat', false);
            }
            return true;
        } catch (Throwable $e) {
            update_option(self::OPT_LAST_ERROR, 'کالا ' . $managementId . ': ' . $e->getMessage(), false);
            return false;
        }
    }

    private function money_to_management_reverse($value): float {
        $multiplier = (float)$this->option(self::OPT_PRICE_MULTIPLIER, '1');
        return (float)$value / ($multiplier > 0 ? $multiplier : 1);
    }

    public function sync_orders() {
        if (!$this->configured()) return new WP_Error('not_configured', 'تنظیمات اتصال کامل نیست.');
        $since = (string)$this->option(self::OPT_ORDER_CURSOR, '');
        $page = 1;
        $count = 0;
        $maxUpdated = $since;
        do {
            $result = $this->api_request('GET', 'orders', [
                'page' => $page,
                'per_page' => 100,
                'since' => $since,
                'source' => 'woocommerce',
            ]);
            if (is_wp_error($result)) return $result;
            foreach (($result['data'] ?? []) as $item) {
                if ($this->sync_order_status($item)) $count++;
                $updated = (string)($item['updated_at'] ?? '');
                if ($updated !== '' && ($maxUpdated === '' || $updated > $maxUpdated)) $maxUpdated = $updated;
            }
            $page = (int)($result['meta']['next_page'] ?? 0);
        } while ($page > 0 && $page <= 50);

        if ($maxUpdated !== '') update_option(self::OPT_ORDER_CURSOR, $maxUpdated, false);
        update_option('ghatehresan_last_orders_count', $count, false);
        return $count;
    }

    private function sync_order_status(array $item): bool {
        $managementId = (int)($item['id'] ?? 0);
        if (!$managementId) return false;
        $orders = wc_get_orders([
            'limit' => 1,
            'return' => 'objects',
            'meta_key' => '_gh_management_order_id',
            'meta_value' => (string)$managementId,
        ]);
        if (empty($orders)) return false;
        $order = $orders[0];
        $status = sanitize_text_field($item['status'] ?? '');
        $target = $this->management_to_wc_status($status);
        if ($target !== '' && $order->get_status() !== $target) {
            $order->update_status($target, 'وضعیت از سامانهٔ قطعه‌رسان به‌روزرسانی شد.', false);
        }
        if ($status === 'ارسال شد') {
            $order->add_order_note('سفارش در سامانهٔ قطعه‌رسان ارسال‌شده ثبت شده است.');
        }
        $order->update_meta_data('_gh_management_status', $status);
        $order->update_meta_data('_gh_management_updated_at', sanitize_text_field($item['updated_at'] ?? ''));
        $order->save();
        return true;
    }

    private function flush_pending_orders(): void {
        $orders = wc_get_orders([
            'limit' => 20,
            'return' => 'ids',
            'meta_key' => '_gh_sync_pending',
            'meta_value' => '1',
            'orderby' => 'date',
            'order' => 'ASC',
        ]);
        foreach ((array)$orders as $orderId) $this->push_order((int)$orderId);
    }

    private function api_request(string $method, string $resource, array $query = [], $body = null) {
        $base = trim((string)$this->option(self::OPT_API_URL, ''));
        $key = trim((string)$this->option(self::OPT_API_KEY, ''));
        $secret = trim((string)$this->option(self::OPT_API_SECRET, ''));
        if ($base === '' || $key === '' || $secret === '') {
            return new WP_Error('not_configured', 'آدرس یا کلیدهای API تنظیم نشده‌اند.');
        }
        $query = array_merge(['resource' => $resource], $query);
        $url = add_query_arg($query, $base);
        $bodyString = $body === null ? '' : wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body !== null && $bodyString === false) return new WP_Error('json_encode', 'تبدیل سفارش به JSON انجام نشد.');
        $parts = wp_parse_url($url);
        $path = $parts['path'] ?? '/api.php';
        $queryString = $parts['query'] ?? '';
        $timestamp = (string)time();
        $canonical = $timestamp . "\n" . strtoupper($method) . "\n" . $path
                   . ($queryString !== '' ? '?' . $queryString : '') . "\n" . $bodyString;
        $signature = hash_hmac('sha256', $canonical, $secret);
        $args = [
            'method' => strtoupper($method),
            'timeout' => 25,
            'redirection' => 2,
            'sslverify' => true,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json; charset=utf-8',
                'X-Ghatehresan-Key' => $key,
                'X-Ghatehresan-Timestamp' => $timestamp,
                'X-Ghatehresan-Signature' => $signature,
            ],
        ];
        if ($body !== null) $args['body'] = $bodyString;
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) return $response;
        $code = (int)wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return new WP_Error('invalid_response', 'پاسخ API قابل خواندن نیست. HTTP ' . $code);
        if ($code < 200 || $code >= 300 || empty($decoded['ok'])) {
            $message = $decoded['error']['message'] ?? ('خطای API با کد ' . $code);
            return new WP_Error('api_error', (string)$message, ['status' => $code, 'body' => $decoded]);
        }
        return $decoded;
    }
}

register_activation_hook(__FILE__, ['Ghatehresan_WC_Sync', 'activate']);
register_deactivation_hook(__FILE__, ['Ghatehresan_WC_Sync', 'deactivate']);

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        $GLOBALS['ghatehresan_wc_sync'] = new Ghatehresan_WC_Sync();
    } elseif (is_admin()) {
        add_action('admin_notices', function () {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p><b>قطعه‌رسان:</b> افزونهٔ ووکامرس فعال نیست.</p></div>';
            }
        });
    }
});
