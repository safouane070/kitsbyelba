<?php
// Secure session cookie: HttpOnly, SameSite=Lax, Secure via kits_request_is_https().
require_once __DIR__ . '/includes/session.php';
kits_session_start('Lax');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

// ── CONFIG — edit config.php to change any values ──────
$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/emailjs_send.php';
require_once __DIR__ . '/includes/kits_admin_guard.php';
require_once __DIR__ . '/includes/asset.php';
if (!kits_admin_ip_allowed($cfg)) {
    kits_destroy_session();
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Toegang geweigerd';
    exit;
}

define('EMAILJS_PK',      trim((string)$cfg['emailjs_pk']));
define('EMAILJS_SVC_ORDER', $cfg['emailjs_service_order']);
define('EMAILJS_SVC_RESTOCK', $cfg['emailjs_service_restock']);
define('EMAILJS_TPL',     $cfg['emailjs_template_paid']);
define('EMAILJS_RESTOCK', $cfg['emailjs_template_restock'] ?? '');
define('ADMIN_NOTIFY_EMAIL', trim((string)($cfg['notify_bcc_email'] ?? 'KitsByElbaa@outlook.com')));

// ── LOGOUT ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_a'] ?? '') === 'logout') {
    if (hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        kits_destroy_session();
    }
    header('Location: admin.php'); exit;
}
// ── BRUTE-FORCE PROTECTION ─────────────────────────────
const LOGIN_MAX_ATTEMPTS  = 5;
const LOGIN_LOCKOUT_SECS  = 900; // 15 minutes
if (!isset($_SESSION['login_attempts']))   $_SESSION['login_attempts'] = 0;
if (!isset($_SESSION['login_locked_until'])) $_SESSION['login_locked_until'] = 0;

$locked = time() < $_SESSION['login_locked_until'];

// ── LOGIN ──────────────────────────────────────────────
$loginErr = false;
$adminHashConfigured = trim((string)($cfg['admin_password_hash'] ?? '')) !== '';
if (!$adminHashConfigured && empty($_SESSION['admin'])) {
    // Production without KITS_ADMIN_PASSWORD_HASH — do not expose a login form.
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><meta charset="utf-8"><title>Beheer — niet geconfigureerd</title><div style="font-family:system-ui,sans-serif;max-width:520px;margin:48px auto;padding:24px;border:1px solid #e5e7eb;border-radius:12px">';
    echo '<h2 style="margin:0 0 12px">Beheer niet geconfigureerd</h2>';
    echo '<p style="color:#4b5563;line-height:1.6">Zet <code>KITS_ADMIN_PASSWORD_HASH</code> op de server (zie <code>tools/set_admin_password.php</code>) en gebruik een echte <code>KITS_PUBLIC_SITE_URL</code> (HTTPS).</p>';
    echo '</div>';
    exit;
}
if (!$locked && ($_POST['_a'] ?? '') === 'login') {
    $loginCsrf = (string)($_POST['csrf_token'] ?? '');
    $csrfOk    = $loginCsrf && hash_equals($_SESSION['csrf_token'] ?? '', $loginCsrf);
    $adminHash = trim((string)($cfg['admin_password_hash'] ?? ''));
    if ($csrfOk && $adminHash !== '' && password_verify((string)($_POST['pw'] ?? ''), $adminHash)) {
        $_SESSION['admin'] = true;
        $_SESSION['login_attempts'] = 0;
        session_regenerate_id(true);
        header('Location: admin.php'); exit;
    }
    $_SESSION['login_attempts']++;
    if ($_SESSION['login_attempts'] >= LOGIN_MAX_ATTEMPTS) {
        $_SESSION['login_locked_until'] = time() + LOGIN_LOCKOUT_SECS;
    }
    $loginErr = true;
}

$auth = !empty($_SESSION['admin']);
$pdo  = null;
if ($auth) {
    try {
        $pdo = kits_pdo($cfg);
        require_once __DIR__ . '/api/schema_products.php';
        require_once __DIR__ . '/api/schema_admin_features.php';
        ensure_products_kits_path_column($pdo);
        ensure_admin_features_schema($pdo);
    } catch (PDOException $e) {
        die('<div style="font-family:sans-serif;padding:48px"><h2 style="color:#c0392b">Geen databaseverbinding</h2><p>Controleer of MySQL draait en of <code>database.sql</code> is geïmporteerd.</p></div>');
    }
}

// ── AJAX API (called with X-Action header; body fallback for strict proxies) ─────────────
$__rawAdminBody = file_get_contents('php://input');
$__adminData = json_decode($__rawAdminBody ?: '[]', true);
if (!is_array($__adminData)) {
    $__adminData = [];
}
$__adminAction = (string)($_SERVER['HTTP_X_ACTION'] ?? ($__adminData['action'] ?? ''));
if ($auth && $__adminAction !== '') {
    ini_set('display_errors', '0'); // prevent PHP warnings from corrupting JSON
    header('Content-Type: application/json');
    $d   = $__adminData;
    $act = $__adminAction;
    $csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($d['csrf'] ?? ''));
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)$csrfHeader)) {
        http_response_code(403);
        echo json_encode(['error' => 'Ongeldig CSRF-token']);
        exit;
    }

    switch ($act) {

        case 'stats':
            echo json_encode([
                'orders'  => (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn(),
                'revenue' => (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status NOT IN ('cancelled')")->fetchColumn(),
                'pending' => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn(),
                'paid'    => (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='paid'")->fetchColumn(),
            ]);
            break;

        case 'orders':
            $status = $d['status'] ?? 'all';
            $sql    = "SELECT * FROM orders";
            $args   = [];
            if ($status !== 'all') { $sql .= " WHERE status=?"; $args[] = $status; }
            $sql .= " ORDER BY created_at DESC";
            $stmt = $pdo->prepare($sql); $stmt->execute($args);
            echo json_encode($stmt->fetchAll());
            break;

        case 'order_detail':
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?");
            $stmt->execute([(int)($d['id'] ?? 0)]);
            $o = $stmt->fetch();
            if (!$o) { echo 'null'; break; }
            $stmt2 = $pdo->prepare(
                'SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.size, oi.quantity, oi.price,
                        oi.printing_option, oi.print_name, oi.print_number, oi.print_badges,
                        p.image AS product_image, p.image2 AS product_image2, p.league AS product_league
                 FROM order_items oi
                 LEFT JOIN products p ON p.id = oi.product_id
                 WHERE oi.order_id = ?'
            );
            $stmt2->execute([$o['id']]);
            $o['items'] = $stmt2->fetchAll();
            echo json_encode($o);
            break;

        case 'update_status':
            $allowed = ['pending','confirmed','paid','shipped','delivered','cancelled'];
            $s  = $d['status'] ?? '';
            $id = (int)($d['id'] ?? 0);
            if (!in_array($s, $allowed) || !$id) { echo json_encode(['ok'=>false]); break; }

            // When cancelling: restore stock for all items in this order
            if ($s === 'cancelled') {
                $prev = $pdo->prepare("SELECT status FROM orders WHERE id=?");
                $prev->execute([$id]);
                $prevStatus = $prev->fetchColumn();
                if ($prevStatus && $prevStatus !== 'cancelled') {
                    $items = $pdo->prepare("SELECT product_id, size, quantity FROM order_items WHERE order_id=?");
                    $items->execute([$id]);
                    foreach ($items->fetchAll(PDO::FETCH_ASSOC) as $item) {
                        $pid  = (int)$item['product_id'];
                        $qty  = (int)$item['quantity'];
                        $size = strtoupper(trim((string)($item['size'] ?? '')));
                        // Restore per-size stock if available
                        $prod = $pdo->prepare("SELECT stock_sizes FROM products WHERE id=?");
                        $prod->execute([$pid]);
                        $row = $prod->fetch(PDO::FETCH_ASSOC);
                        $ss  = ($row && $row['stock_sizes']) ? json_decode($row['stock_sizes'], true) : null;
                        if ($ss && $size && array_key_exists($size, $ss)) {
                            $ss[$size] = (int)$ss[$size] + $qty;
                            $pdo->prepare("UPDATE products SET stock_sizes=?, stock=stock+? WHERE id=?")
                                ->execute([json_encode($ss), $qty, $pid]);
                        } else {
                            $pdo->prepare("UPDATE products SET stock=stock+? WHERE id=?")
                                ->execute([$qty, $pid]);
                        }
                    }
                }
            }

            $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$s, $id]);
            echo json_encode(['ok' => true]);
            break;

        case 'low_stock':
            $all = $pdo->query("SELECT id, name, league, stock, stock_sizes FROM products WHERE active=1 ORDER BY stock ASC, name ASC")->fetchAll(PDO::FETCH_ASSOC);
            $rows = [];
            foreach ($all as $r) {
                $total = (int)$r['stock'];
                $ss = !empty($r['stock_sizes']) ? json_decode((string)$r['stock_sizes'], true) : null;
                $worst = $total;
                $hint = '';
                if (is_array($ss)) {
                    foreach ($ss as $sz => $q) {
                        $q = (int)$q;
                        if ($q < $worst) {
                            $worst = $q;
                        }
                        if ($q <= 5 && $q >= 0) {
                            $hint = ($hint !== '' ? $hint . ' · ' : '') . $sz . ':' . $q;
                        }
                    }
                }
                $low = ($total <= 5) || (is_array($ss) && $hint !== '');
                if (!$low) {
                    continue;
                }
                $rows[] = [
                    'id'         => (int)$r['id'],
                    'name'       => $r['name'],
                    'league'     => $r['league'],
                    'stock'      => $total,
                    'worst'      => $worst,
                    'size_hint'  => $hint !== '' ? $hint : null,
                    'stock_sizes'=> $ss ?: (object)[],
                ];
            }
            usort($rows, static function ($a, $b) {
                return [$a['worst'], $a['stock'], $a['name']] <=> [$b['worst'], $b['stock'], $b['name']];
            });
            echo json_encode($rows);
            break;

        case 'products':
            $rows = $pdo->query("SELECT * FROM products ORDER BY sort_order, id")->fetchAll();
            foreach ($rows as &$r) { $r['id']=(int)$r['id']; $r['price']=(float)$r['price']; }
            echo json_encode($rows);
            break;

        case 'save_product':
            // Zelfde maten als api/stock_notify.php + productpagina (anders geen nabestel-match)
            $validSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', '3XL', '4XL'];
            $sizesInput = $d['stock_sizes'] ?? null;
            $stockSizesJson = null;
            $totalStock = max(0, (int)($d['stock'] ?? 0));
            $restockedSizes = [];
            $oldSizes = [];
            if (!empty($d['id']) && is_array($sizesInput)) {
                $oldStmt = $pdo->prepare('SELECT stock_sizes FROM products WHERE id=?');
                $oldStmt->execute([(int)$d['id']]);
                $oldRow = $oldStmt->fetch(PDO::FETCH_ASSOC);
                $oldSizes = !empty($oldRow['stock_sizes']) ? json_decode((string)$oldRow['stock_sizes'], true) : [];
                if (!is_array($oldSizes)) {
                    $oldSizes = [];
                }
            }
            if (is_array($sizesInput)) {
                $verIn = in_array($d['version'] ?? '', ['fan', 'player'], true) ? $d['version'] : '';
                $cleaned = [];
                $sum = 0;
                foreach ($validSizes as $sz) {
                    $qty = max(0, (int)($sizesInput[$sz] ?? 0));
                    if ($verIn === 'player' && in_array($sz, ['2XL', '3XL', '4XL'], true)) {
                        $qty = 0;
                    }
                    $cleaned[$sz] = $qty;
                    $sum += $qty;
                    $oldQ = (int)($oldSizes[$sz] ?? 0);
                    if ($qty > 0 && $oldQ === 0) {
                        $restockedSizes[] = $sz;
                    }
                }
                $stockSizesJson = json_encode($cleaned);
                $totalStock = $sum; // total derived from sizes
            }
            // Player-versie: aparte prijs (nullable) + per-maat voorraad (alleen XS..XXL)
            $playerPrice = null;
            if (isset($d['player_price']) && trim((string)$d['player_price']) !== '') {
                $playerPrice = max(0.0, (float)$d['player_price']);
            }
            $playerStockJson = null;
            if (isset($d['player_stock_sizes']) && is_array($d['player_stock_sizes'])) {
                $pClean = [];
                foreach (['XS', 'S', 'M', 'L', 'XL', 'XXL'] as $sz) {
                    $pClean[$sz] = max(0, (int)($d['player_stock_sizes'][$sz] ?? 0));
                }
                $playerStockJson = json_encode($pClean);
            }
            $f = [
                'name'       => trim($d['name'] ?? ''),
                'version'    => in_array($d['version'] ?? '', ['fan', 'player']) ? $d['version'] : '',
                'league'     => trim($d['league'] ?? ''),
                'cat'        => trim($d['cat'] ?? ''),
                'emoji'      => trim($d['emoji'] ?? '👕'),
                'description'=> trim($d['description'] ?? '') ?: null,
                'fit_info'   => trim($d['fit_info'] ?? '') ?: null,
                'size_advice'=> trim($d['size_advice'] ?? '') ?: null,
                'material_info' => trim($d['material_info'] ?? '') ?: null,
                'shipping_info' => trim($d['shipping_info'] ?? '') ?: null,
                'returns_info' => trim($d['returns_info'] ?? '') ?: null,
                'personalization_policy' => trim($d['personalization_policy'] ?? '') ?: null,
                'care_instructions' => trim($d['care_instructions'] ?? '') ?: null,
                'image_order' => preg_match('/^[1-3](,[1-3]){2}$/', trim($d['image_order'] ?? '1,2,3')) ? trim($d['image_order']) : '1,2,3',
                'price'      => (float)($d['price'] ?? 0),
                'badge'      => in_array($d['badge']??'', ['new','hot']) ? $d['badge'] : '',
                'cat_cover'  => (int)(!empty($d['cat_cover'])),
                'active'      => (int)($d['active'] ?? 1),
                'sort_order'  => (int)($d['sort'] ?? 0),
                'stock'       => $totalStock,
                'stock_sizes' => $stockSizesJson,
                'player_price' => $playerPrice,
                'player_stock_sizes' => $playerStockJson,
                'in_voorraad' => (int)(!empty($d['in_voorraad'])),
            ];
            // Images: set only if the key is present in request.
            // This prevents overwriting on partial edits, while still allowing explicit removal.
            if (array_key_exists('image', $d)) {
                $f['image'] = $d['image'] ?: null;
            }
            if (array_key_exists('image2', $d)) {
                $f['image2'] = $d['image2'] ?: null;
            }
            if (array_key_exists('image3', $d)) {
                $f['image3'] = $d['image3'] ?: null;
            }
            if (!empty($d['id'])) {
                $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($f)));
                $stmt = $pdo->prepare("UPDATE products SET $sets WHERE id=?");
                $stmt->execute([...array_values($f), (int)$d['id']]);
                $out = ['ok' => true, 'id' => (int)$d['id']];
                if ($restockedSizes !== []) {
                    $out['restocked_sizes'] = $restockedSizes;
                    $re = kits_emailjs_try_restock($pdo, $cfg, (int)$d['id'], $restockedSizes);
                    // Geen JS-dubbeling als PHP klaar is zonder mislukte sends; bij failures mag admin-EmailJS de rest proberen.
                    $out['restock_email_done'] = $re['attempted'] && $re['failed'] === 0;
                }
                echo json_encode($out);
            } else {
                $cols = implode(',', array_keys($f));
                $phs  = implode(',', array_fill(0, count($f), '?'));
                $stmt = $pdo->prepare("INSERT INTO products ($cols) VALUES ($phs)");
                $stmt->execute(array_values($f));
                echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()]);
            }
            break;

        case 'duplicate_product':
            $id = (int)($d['id'] ?? 0);
            if (!$id) {
                echo json_encode(['ok' => false]);
                break;
            }
            $src = $pdo->prepare('SELECT * FROM products WHERE id=?');
            $src->execute([$id]);
            $p = $src->fetch(PDO::FETCH_ASSOC);
            if (!$p) {
                echo json_encode(['ok' => false]);
                break;
            }
            $newName = trim((string)$p['name']) . ' (kopie)';
            if (mb_strlen($newName) > 160) {
                $newName = mb_substr($newName, 0, 157) . '…';
            }
            $sort = (int)$p['sort_order'] + 1;
            $ins = $pdo->prepare(
                'INSERT INTO products (name, version, league, cat, emoji, description, fit_info, size_advice, material_info, shipping_info, returns_info, personalization_policy, care_instructions, image_order, stock, price, badge, image, image2, image3, active, sort_order, stock_sizes, player_price, player_stock_sizes, kits_path)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL)'
            );
            $ins->execute([
                $newName,
                $p['version'],
                $p['league'],
                $p['cat'],
                $p['emoji'],
                $p['description'],
                $p['fit_info'],
                $p['size_advice'],
                $p['material_info'],
                $p['shipping_info'],
                $p['returns_info'],
                $p['personalization_policy'],
                $p['care_instructions'],
                $p['image_order'],
                $p['stock'],
                $p['price'],
                $p['badge'],
                $p['image'],
                $p['image2'],
                $p['image3'],
                $p['active'],
                $sort,
                $p['stock_sizes'],
                $p['player_price'],
                $p['player_stock_sizes'],
            ]);
            echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
            break;

        case 'set_cat_cover':
            $pid      = (int)($d['product_id'] ?? 0);
            $clearIds = array_map('intval', (array)($d['clear_ids'] ?? []));
            // Clear cover flag for all products of this type (sent from frontend)
            if ($clearIds) {
                $ph = implode(',', array_fill(0, count($clearIds), '?'));
                $pdo->prepare("UPDATE products SET cat_cover=0 WHERE id IN ($ph)")->execute($clearIds);
            }
            if ($pid > 0) {
                $pdo->prepare("UPDATE products SET cat_cover=1 WHERE id=?")->execute([$pid]);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'save_tracking':
            $id  = (int)($d['id'] ?? 0);
            $trk = trim($d['tracking_number'] ?? '');
            if (!$id) { echo json_encode(['ok'=>false]); break; }
            if (mb_strlen($trk) > 200) $trk = mb_substr($trk, 0, 200);
            $pdo->prepare('UPDATE orders SET tracking_number=? WHERE id=?')
                ->execute([$trk === '' ? null : $trk, $id]);
            echo json_encode(['ok' => true]);
            break;

        case 'quick_restock':
            $id    = (int)($d['id'] ?? 0);
            $sizes = $d['stock_sizes'] ?? null;
            if (!$id || !is_array($sizes)) { echo json_encode(['ok'=>false]); break; }
            // Get old stock to detect which sizes went from 0 → stocked
            $old = $pdo->prepare('SELECT stock_sizes, version FROM products WHERE id=?');
            $old->execute([$id]);
            $oldRow = $old->fetch(PDO::FETCH_ASSOC);
            $oldSizes = !empty($oldRow['stock_sizes']) ? json_decode($oldRow['stock_sizes'], true) : [];
            $isPlayerQr = (($oldRow['version'] ?? '') === 'player');
            $validSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL', '2XL', '3XL', '4XL'];
            $cleaned = []; $total = 0; $restocked = [];
            foreach ($validSizes as $sz) {
                $qty = max(0,(int)($sizes[$sz] ?? 0));
                if ($isPlayerQr && in_array($sz, ['2XL', '3XL', '4XL'], true)) {
                    $qty = 0;
                }
                $cleaned[$sz] = $qty; $total += $qty;
                if ($qty > 0 && (int)($oldSizes[$sz] ?? 0) === 0) {
                    $restocked[] = $sz; // this size just came back in stock
                }
            }
            $pdo->prepare('UPDATE products SET stock_sizes=?, stock=? WHERE id=?')
                ->execute([json_encode($cleaned), $total, $id]);
            $qr = ['ok' => true, 'stock' => $total, 'restocked_sizes' => $restocked];
            if ($restocked !== []) {
                $re = kits_emailjs_try_restock($pdo, $cfg, $id, $restocked);
                $qr['restock_email_done'] = $re['attempted'] && $re['failed'] === 0;
            }
            echo json_encode($qr);
            break;

        case 'toggle_voorraad':
            $id  = (int)($d['id'] ?? 0);
            $val = (int)(!empty($d['in_voorraad']));
            if (!$id) { echo json_encode(['ok'=>false]); break; }
            $pdo->prepare('UPDATE products SET in_voorraad=? WHERE id=?')->execute([$val, $id]);
            echo json_encode(['ok'=>true,'in_voorraad'=>$val]);
            break;

        case 'get_stock_notifications':
            $id = (int)($d['id'] ?? 0);
            if (!$id) { echo json_encode([]); break; }
            $sizes = array_filter(array_map('trim', (array)($d['sizes'] ?? [])));
            if (empty($sizes)) { echo json_encode([]); break; }
            $ph = implode(',', array_fill(0, count($sizes), '?'));
            $stmt = $pdo->prepare(
                "SELECT email, size FROM stock_notifications WHERE product_id=? AND size IN ($ph)"
            );
            $stmt->execute(array_merge([$id], array_values($sizes)));
            echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'clear_stock_notifications':
            $id = (int)($d['id'] ?? 0);
            $sizes = array_filter(array_map('trim', (array)($d['sizes'] ?? [])));
            if (!$id || empty($sizes)) { echo json_encode(['ok'=>true]); break; }
            $ph = implode(',', array_fill(0, count($sizes), '?'));
            $pdo->prepare(
                "DELETE FROM stock_notifications WHERE product_id=? AND size IN ($ph)"
            )->execute(array_merge([$id], array_values($sizes)));
            echo json_encode(['ok'=>true]);
            break;

        case 'clear_stock_notification_row':
            $pid = (int)($d['id'] ?? 0);
            $email = strtolower(trim((string)($d['email'] ?? '')));
            $size = strtoupper(trim((string)($d['size'] ?? '')));
            if ($pid <= 0 || $email === '' || $size === '') {
                echo json_encode(['ok' => false]);
                break;
            }
            $pdo->prepare('DELETE FROM stock_notifications WHERE product_id = ? AND email = ? AND size = ?')
                ->execute([$pid, $email, $size]);
            echo json_encode(['ok' => true]);
            break;

        case 'save_order_note':
            $id = (int)($d['id'] ?? 0);
            if (!$id) {
                echo json_encode(['ok' => false]);
                break;
            }
            $note = isset($d['admin_note']) ? (string)$d['admin_note'] : '';
            if (mb_strlen($note) > 20000) {
                $note = mb_substr($note, 0, 20000);
            }
            $pdo->prepare('UPDATE orders SET admin_note=? WHERE id=?')->execute([$note === '' ? null : $note, $id]);
            echo json_encode(['ok' => true]);
            break;

        case 'delete_product':
            $id = (int)($d['id'] ?? 0);
            // Delete image file if exists
            $row = $pdo->prepare("SELECT image, image2, image3 FROM products WHERE id=?");
            $row->execute([$id]);
            $imgs = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (['image','image2','image3'] as $k) {
                $img = $imgs[$k] ?? '';
                if (!$img) {
                    continue;
                }
                $img = str_replace('\\', '/', trim($img));
                if ($img === '' || str_contains($img, '..')) {
                    continue;
                }
                $imgPath = str_contains($img, '/')
                    ? (__DIR__ . '/' . ltrim($img, '/'))
                    : (__DIR__ . '/uploads/products/' . basename($img));
                if (file_exists($imgPath)) {
                    @unlink($imgPath);
                }
            }
            $pdo->prepare("DELETE FROM products WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        case 'coupons':
            // Ensure table exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                code       VARCHAR(50)  NOT NULL UNIQUE,
                type       ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
                value      DECIMAL(10,2) NOT NULL DEFAULT 10.00,
                active     TINYINT(1)   NOT NULL DEFAULT 1,
                uses_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $rows = $pdo->query("SELECT * FROM coupons ORDER BY created_at DESC")->fetchAll();
            echo json_encode($rows);
            break;

        case 'save_coupon':
            $code   = strtoupper(trim($d['code'] ?? ''));
            $type   = in_array($d['type'] ?? '', ['percent','fixed']) ? $d['type'] : 'percent';
            $value  = max(0.01, min(100, (float)($d['value'] ?? 10)));
            $active = (int)(!empty($d['active']));
            if (mb_strlen($code) < 3 || mb_strlen($code) > 50) {
                echo json_encode(['ok' => false, 'error' => 'Code: 3–50 tekens']);
                break;
            }
            if (!preg_match('/^[A-Z0-9_-]+$/', $code)) {
                echo json_encode(['ok' => false, 'error' => 'Code: alleen letters, cijfers, _ en -']);
                break;
            }
            if (!empty($d['id'])) {
                $pdo->prepare("UPDATE coupons SET code=?,type=?,value=?,active=? WHERE id=?")
                    ->execute([$code, $type, $value, $active, (int)$d['id']]);
                echo json_encode(['ok' => true]);
            } else {
                try {
                    $pdo->prepare("INSERT INTO coupons (code,type,value,active) VALUES (?,?,?,?)")
                        ->execute([$code, $type, $value, $active]);
                    echo json_encode(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
                } catch (PDOException $ex) {
                    echo json_encode(['ok' => false, 'error' => 'Code bestaat al']);
                }
            }
            break;

        case 'delete_coupon':
            $id = (int)($d['id'] ?? 0);
            if ($id) $pdo->prepare("DELETE FROM coupons WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        case 'site_settings':
            require_once __DIR__ . '/includes/site_settings.php';
            echo json_encode(kits_load_site_settings($pdo));
            break;

        case 'save_site_settings':
            require_once __DIR__ . '/includes/site_settings.php';
            $defaults = kits_site_settings_defaults();
            $up = $pdo->prepare('INSERT INTO site_settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
            $set = static function (string $key, string $val) use ($up, $defaults): void {
                $v = trim($val);
                if ($v === '') {
                    $v = $defaults[$key] ?? '';
                }
                $up->execute([$key, $v]);
            };
            $set('promo_banner', kits_sanitize_promo_html(trim((string)($d['promo_banner'] ?? ''))));
            $set('promo_popup_title', strip_tags(trim((string)($d['promo_popup_title'] ?? ''))));
            $set('promo_popup_sub', strip_tags(trim((string)($d['promo_popup_sub'] ?? ''))));
            $set('promo_popup_code', strip_tags(trim((string)($d['promo_popup_code'] ?? ''))));
            $set('promo_faq_answer', kits_sanitize_promo_html(trim((string)($d['promo_faq_answer'] ?? ''))));
            echo json_encode(['ok' => true]);
            break;

        default:
            echo json_encode(['error' => 'Onbekende actie']);
    }
    exit;
}

// Fetch all products directly for page embed (no AJAX needed)
$_allProducts = [];
if ($auth) {
    try {
        $stmt = $pdo->query("SELECT * FROM products ORDER BY sort_order, id");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $r['id']          = (int)$r['id'];
            $r['price']       = (float)$r['price'];
            $r['stock']       = (int)$r['stock'];
            $r['active']      = (int)$r['active'];
            $r['in_voorraad'] = (int)($r['in_voorraad'] ?? 0);
            $_allProducts[]   = $r;
        }
    } catch (Throwable $e) { $_allProducts = []; }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>KitsByElbaa — Beheer</title>
<script defer src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4.4.1/dist/email.min.js" integrity="sha384-SALc35EccAf6RzGw4iNsyj7kTPr33K7RoGzYu+7heZhT8s0GZouafRiCg1qy44AS" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#f0f2f5;--white:#fff;--sidebar:#1a1d23;--sidebar2:#22262e;
  --accent:#2d5a27;--accent2:#3a7232;
  --blue:#3b82f6;--orange:#f59e0b;--green:#10b981;--purple:#8b5cf6;--red:#ef4444;--teal:#06b6d4;
  --ink:#1c1a17;--ink2:#374151;--ink3:#6b7280;--line:#e5e7eb;
  --shadow:0 1px 8px rgba(0,0,0,.08);--shadow-md:0 4px 20px rgba(0,0,0,.12);
}
body{font-family:'Segoe UI',system-ui,sans-serif;background:var(--bg);color:var(--ink2);min-height:100vh;-webkit-text-size-adjust:100%}
body.admin-sidebar-open{overflow:hidden;touch-action:none}
a{color:inherit;text-decoration:none}
button{cursor:pointer;font-family:inherit}

/* ── LOGIN ── */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;background:var(--sidebar)}
.login-card{background:var(--white);border-radius:12px;padding:48px 40px;width:360px;box-shadow:var(--shadow-md);text-align:center}
.login-logo{font-size:22px;font-weight:700;letter-spacing:.04em;color:var(--ink);margin-bottom:8px}
.login-sub{font-size:13px;color:var(--ink3);margin-bottom:32px}
.login-input{width:100%;padding:12px 16px;border:1.5px solid var(--line);border-radius:8px;font-size:14px;outline:none;transition:border .2s;margin-bottom:14px}
.login-input:focus{border-color:var(--accent)}
.login-btn{width:100%;padding:13px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;letter-spacing:.04em;transition:background .2s}
.login-btn:hover{background:var(--accent2)}
.login-err{color:var(--red);font-size:13px;margin-top:10px}

/* ── LAYOUT ── */
.layout{display:flex;min-height:100vh}

/* ── SIDEBAR ── */
.sidebar{width:230px;background:var(--sidebar);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:50}
.s-logo{padding:24px 20px 18px;font-size:17px;font-weight:700;color:#fff;letter-spacing:.04em;border-bottom:1px solid rgba(255,255,255,.08)}
.s-logo span{color:var(--green);opacity:.8;font-weight:400;font-size:11px;display:block;letter-spacing:.12em;text-transform:uppercase;margin-top:2px}
.s-nav{flex:1;padding:16px 12px}
.sn{width:100%;display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;border:none;background:transparent;color:rgba(255,255,255,.55);font-size:13.5px;font-weight:500;transition:all .18s;text-align:left}
.sn:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.85)}
.sn.on{background:var(--accent);color:#fff}
.sn svg{width:17px;height:17px;flex-shrink:0}
.s-bottom{padding:16px 12px;border-top:1px solid rgba(255,255,255,.08)}
.s-logout{display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:8px;color:rgba(255,255,255,.45);font-size:13px;transition:all .18s}
.s-logout:hover{background:rgba(239,68,68,.15);color:#ef4444}
.s-store{display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:8px;color:rgba(255,255,255,.45);font-size:13px;transition:all .18s;margin-bottom:6px}
.s-store:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.7)}

/* ── MAIN ── */
.main{margin-left:230px;flex:1;padding:32px;min-height:100vh}
.view{display:none}
.view.on{display:block}
.page-title{font-size:22px;font-weight:700;color:var(--ink);margin-bottom:6px}
.page-sub{font-size:13px;color:var(--ink3);margin-bottom:28px}

/* ── STATS ── */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:28px}
.stat-card{background:var(--white);border-radius:12px;padding:22px 24px;box-shadow:var(--shadow);display:flex;align-items:center;gap:16px}
.stat-icon{width:46px;height:46px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0}
.stat-val{font-size:24px;font-weight:700;color:var(--ink);line-height:1}
.stat-lbl{font-size:12px;color:var(--ink3);margin-top:4px}

/* ── TOOLBAR ── */
.toolbar{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;gap:12px;flex-wrap:wrap}
.filter-bar{display:flex;gap:6px;flex-wrap:wrap}
.fpill{padding:7px 16px;border-radius:100px;border:1.5px solid var(--line);background:var(--white);font-size:12px;font-weight:600;color:var(--ink3);transition:all .18s;letter-spacing:.04em}
.fpill:hover{border-color:var(--ink2);color:var(--ink2)}
.fpill.on{background:var(--ink);border-color:var(--ink);color:#fff}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border-radius:8px;border:none;font-size:13px;font-weight:600;transition:all .18s}
.btn-primary{background:var(--accent);color:#fff}
.btn-primary:hover{background:var(--accent2)}
.btn-sm{padding:6px 12px;font-size:12px;border-radius:6px}

/* ── TABLE ── */
.tbl-wrap{background:var(--white);border-radius:12px;box-shadow:var(--shadow);overflow:hidden}
table{width:100%;border-collapse:collapse}
th{padding:12px 16px;text-align:left;font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);border-bottom:1.5px solid var(--line);background:var(--bg)}
td{padding:13px 16px;font-size:13.5px;border-bottom:1px solid var(--line);vertical-align:middle}
tr:last-child td{border-bottom:none}
tbody tr{transition:background .15s}
tbody tr:hover{background:#f9fafb;cursor:pointer}

/* ── BADGES ── */
.badge{display:inline-flex;align-items:center;padding:3px 10px;border-radius:100px;font-size:11px;font-weight:700;letter-spacing:.04em}
.b-pending  {background:#fef3c7;color:#92400e}
.b-confirmed{background:#dbeafe;color:#1d4ed8}
.b-paid     {background:#d1fae5;color:#065f46}
.b-shipped  {background:#ede9fe;color:#5b21b6}
.b-delivered{background:#d1fae5;color:#065f46}
.b-cancelled{background:#fee2e2;color:#991b1b}
.b-new      {background:#d1fae5;color:#065f46}
.b-hot      {background:#fef3c7;color:#92400e}

/* ── MODAL ── */
.mbg{
  position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;display:none;
  align-items:center;justify-content:center;
  padding:max(16px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(16px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left));
  box-sizing:border-box;overflow:auto;-webkit-overflow-scrolling:touch
}
.mbg.on{display:flex}
.modal{
  background:var(--white);border-radius:14px;box-shadow:var(--shadow-md);
  width:100%;
  max-width:min(720px, 100%);
  min-width:0;
  max-height:min(90vh, 900px);
  overflow:hidden;
  display:flex;
  flex-direction:column;
  box-sizing:border-box;
  margin:auto;
  flex:0 1 auto;
  align-self:center
}
.modal-head{
  padding:20px 24px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;
  flex-shrink:0;gap:12px;min-width:0
}
.modal-title{font-size:16px;font-weight:700;color:var(--ink);min-width:0;overflow:hidden;text-overflow:ellipsis}
.xbtn{width:32px;height:32px;border-radius:50%;border:1.5px solid var(--line);background:transparent;font-size:16px;color:var(--ink3);display:flex;align-items:center;justify-content:center;transition:all .18s;flex-shrink:0}
.xbtn:hover{background:var(--bg)}
.modal-body{
  padding:24px;
  overflow-y:auto;
  overflow-x:hidden;
  flex:1 1 auto;
  min-height:0;
  min-width:0;
  -webkit-overflow-scrolling:touch
}

/* ── ORDER DETAIL ── */
.od-section{margin-bottom:20px}
.od-label{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);margin-bottom:8px}
.od-info{background:var(--bg);border-radius:8px;padding:14px 16px;font-size:13.5px;line-height:1.8}
.od-items{border:1px solid var(--line);border-radius:8px;overflow:hidden}
.od-item{display:flex;justify-content:space-between;padding:11px 16px;border-bottom:1px solid var(--line);font-size:13.5px}
.od-item:last-child{border-bottom:none}
.od-product-link{font-size:11px;font-weight:600;padding:4px 10px;margin:0 8px 0 0;border-radius:6px;border:1px solid var(--line);background:var(--cream2);cursor:pointer;color:var(--ink2);vertical-align:baseline}
.od-product-link:hover{background:var(--accent);color:#fff;border-color:var(--accent)}
.od-total{display:flex;justify-content:space-between;padding:12px 16px;background:var(--bg);font-weight:700}
.status-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.status-btn{padding:8px 14px;border-radius:7px;border:1.5px solid var(--line);background:var(--white);font-size:12px;font-weight:600;transition:all .18s}
.status-btn:hover{border-color:var(--ink);background:var(--bg)}
.status-btn.active{background:var(--ink);border-color:var(--ink);color:#fff}
.pay-btn{padding:10px 20px;border-radius:8px;background:var(--green);color:#fff;border:none;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;transition:background .2s;width:100%;justify-content:center;margin-top:16px;cursor:pointer}
.pay-btn:hover{background:#059669}
.pay-btn:disabled{opacity:.5;pointer-events:none}
.pay-btn-sec{margin-top:12px;background:var(--white)!important;color:var(--ink)!important;border:2px solid var(--line)!important}
.pay-btn-sec:hover{background:var(--bg)!important;border-color:var(--ink)!important}
.od-email-hint{font-size:11px;color:var(--ink3);margin:8px 0 0;line-height:1.55}
.od-paid-note{text-align:center;padding:11px 14px;background:var(--bg);border-radius:8px;font-size:12px;margin-top:12px;color:var(--ink3)}

/* ── FORM ── */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;min-width:0;width:100%}
.fg{display:flex;flex-direction:column;gap:5px;min-width:0}
.fg.full{grid-column:1/-1}
.flabel{font-size:11px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3)}
.finput,.fselect{padding:10px 12px;border:1.5px solid var(--line);border-radius:7px;font-size:13.5px;font-family:inherit;outline:none;transition:border .2s;background:var(--white);max-width:100%;box-sizing:border-box}
textarea.finput{resize:vertical;min-height:72px}
.finput:focus,.fselect:focus{border-color:var(--accent)}
.save-btn{width:100%;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;margin-top:20px;transition:background .2s}
.save-btn:hover{background:var(--accent2)}
.pm-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:16px}
.pm-actions .save-btn{margin-top:0;flex:1 1 200px;min-width:0}
.pm-dup{background:var(--bg)!important;border:1px solid var(--line)!important;color:var(--ink2)!important;padding:12px 20px!important;border-radius:8px!important;font-size:14px!important;font-weight:600!important}
/* ── PER-SIZE STOCK GRID ── */
.size-stock-grid{display:grid;grid-template-columns:repeat(4, minmax(0, 1fr));gap:8px;margin-top:14px;width:100%;min-width:0}
.ss-item{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:0}
.ss-lbl{font-size:10px;font-weight:700;letter-spacing:.08em;color:var(--ink3);text-transform:uppercase}
.ss-input{text-align:center;padding:8px 2px;font-size:13px;font-weight:600;min-width:0;width:100%;max-width:100%;box-sizing:border-box}
.ss-total{margin-top:12px;font-size:12px;color:var(--ink3);padding-top:10px;border-top:1px dashed var(--line)}
.ss-total strong{color:var(--ink)}
.ss-stock-card{
  margin-top:8px;padding:14px 16px 16px;border-radius:10px;border:1px solid var(--line);
  background:linear-gradient(165deg, rgba(45,90,39,.07) 0%, var(--white) 56px);
  box-shadow:0 1px 3px rgba(28,26,23,.05);
}
.ss-stock-badges{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;align-items:center}
.ss-badge{
  display:inline-flex;align-items:center;padding:5px 11px;border-radius:999px;
  font-size:10px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
}
.ss-badge--fast{background:rgba(45,90,39,.16);color:#143214}
.ss-badge--slow{background:var(--cream2);color:var(--ink3);border:1px solid var(--line)}
.ss-lead{margin:0 0 14px;font-size:12.5px;line-height:1.55;color:var(--ink2)}
.ss-qs{display:flex;flex-wrap:wrap;align-items:center;gap:8px;column-gap:8px;row-gap:10px}
.ss-qs-lbl{
  font-size:10px;font-weight:700;letter-spacing:.11em;text-transform:uppercase;color:var(--ink4);
  width:100%;margin:0;
}
@media(min-width:520px){
  .ss-qs-lbl{width:auto;margin-right:6px}
}
.ss-qs-btn{
  padding:8px 14px;font-size:12px;font-weight:600;border-radius:8px;border:1px solid var(--line);
  background:var(--white);color:var(--ink2);cursor:pointer;font-family:inherit;
  transition:border-color .15s,color .15s,background .15s;box-shadow:0 1px 2px rgba(28,26,23,.04);
}
.ss-qs-btn:hover{border-color:var(--ink);color:var(--ink);background:var(--cream2)}
.ss-qs-btn.danger{border-color:#fecaca;color:#b91c1c;background:#fffafa}
.ss-qs-btn.danger:hover{border-color:#b91c1c;background:#fef2f2}
.admin-copy-card{max-width:640px;padding:22px 26px;border-radius:10px;border:1px solid var(--line);background:linear-gradient(165deg,rgba(45,90,39,.06) 0%,var(--white) 52px);box-shadow:0 1px 3px rgba(28,26,23,.06);line-height:1.75}
.admin-copy-card p:first-child{margin-top:0}

/* ── EMPTY ── */
.empty{text-align:center;padding:64px 32px;color:var(--ink3)}
.empty-ico{font-size:48px;margin-bottom:12px}

/* ── TAB TRANSITION ── */
@keyframes viewIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.view.on{animation:viewIn .22s ease both}

/* ── LOGIN ERROR SHAKE ── */
.login-card.shake{animation:loginShake .4s ease}
@keyframes loginShake{0%,100%{transform:translateX(0)}20%{transform:translateX(-7px)}40%{transform:translateX(7px)}60%{transform:translateX(-4px)}80%{transform:translateX(4px)}}

/* ── TOAST ── */
.toast{position:fixed;bottom:28px;right:28px;background:var(--ink);color:#fff;padding:12px 18px;border-radius:8px;font-size:13px;font-weight:500;opacity:0;transform:translateY(8px);transition:all .25s;pointer-events:none;z-index:200;display:inline-flex;align-items:center;gap:9px;max-width:min(92vw,460px)}
.toast .toast-ico{display:inline-flex;flex-shrink:0}
.toast .toast-ico svg{width:16px;height:16px;display:block}
.toast .toast-msg{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.toast.toast--success{background:#2d5a27;color:#fff}
.toast.toast--error{background:#c0392b;color:#fff}
.toast.on{opacity:1;transform:translateY(0)}

/* ── IMAGE UPLOAD ── */
.img-upload-area{border:2px dashed var(--line);border-radius:8px;padding:20px;text-align:center;cursor:pointer;transition:border-color .18s, background .18s;min-height:90px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:var(--ink3);font-size:13px;max-width:100%;box-sizing:border-box;min-width:0}
.img-upload-area:hover{border-color:var(--accent);background:var(--bg)}
.img-upload-area img{max-height:240px;max-width:100%;object-fit:contain;border-radius:6px}
.img-upload-hint{font-size:11px;color:var(--ink3)}
.img-remove-btn{background:#fee2e2;color:#991b1b;border:none;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;margin-top:6px}
.admin-pthumb-wrap{width:108px;height:135px;border-radius:10px;border:1px solid var(--line);background:linear-gradient(145deg,#f3f4f6,#e5e7eb);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center}
.admin-pthumb{width:100%;height:100%;object-fit:cover;display:block}
.admin-pthumb-miss{font-size:28px;opacity:.35}
.admin-pmini{display:flex;gap:3px;margin-top:6px;flex-wrap:wrap;max-width:108px}
.admin-pmini img{width:34px;height:42px;object-fit:cover;border-radius:4px;border:1px solid var(--line);background:var(--bg)}
.pname-cell strong{display:block;font-size:13.5px;color:var(--ink)}
.pname-meta{font-size:11px;color:var(--ink3);margin-top:6px;line-height:1.45;word-break:break-all}
.products-filters{width:100%;background:var(--white);border-radius:12px;border:1px solid var(--line);padding:14px 16px;margin-bottom:14px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;box-shadow:var(--shadow)}
.products-filters .pf-search{flex:1;min-width:200px;max-width:360px;padding:9px 12px;border:1.5px solid var(--line);border-radius:8px;font-size:13px}
.products-filters .fselect{padding:9px 10px;font-size:12px;min-width:130px}
.products-count{font-size:12px;color:var(--ink3);margin-left:auto}

/* ── RECENT ORDERS (dashboard) ── */
.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:24px}
.dash-card{background:var(--white);border-radius:12px;padding:20px 24px;box-shadow:var(--shadow)}
.dash-card h3{font-size:14px;font-weight:700;margin-bottom:14px;color:var(--ink)}
.mini-order{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--line);font-size:13px}
.mini-order:last-child{border-bottom:none}
.mo-id{font-weight:600;color:var(--ink)}
.mo-name{color:var(--ink3);font-size:12px}
/* ── LOW STOCK WIDGET ── */
.low-stock-card{display:flex;flex-direction:column;gap:10px;padding:12px 0;border-bottom:1px solid var(--line);font-size:13px;min-width:0}
.low-stock-card:last-child{border-bottom:none}
.low-stock-card--click{cursor:pointer;border-radius:10px;margin:0 -8px;padding:12px 8px;transition:background .15s}
.low-stock-card--click:hover{background:var(--bg)}
.low-stock-card-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;min-width:0}
.low-stock-card-titles{flex:1;min-width:0}
.low-stock-title{font-weight:600;color:var(--ink);font-size:13px;line-height:1.35;word-break:break-word}
.low-stock-meta{display:block;font-size:10px;color:var(--ink3);font-weight:400;margin-top:4px;line-height:1.35}
.low-stock-summary-badge{flex-shrink:0;font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;white-space:nowrap}
.low-stock-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;min-width:0}
.low-stock-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:7px;font-size:11px;font-weight:600;border:1px solid transparent;line-height:1.2}
.low-stock-chip--ok{background:#f0fdf4;color:#166534;border-color:#bbf7d0}
.low-stock-chip--warn{background:#fef3c7;color:#92400e;border-color:#fde68a}
.low-stock-chip--out{background:#fee2e2;color:#991b1b;border-color:#fecaca}
.low-stock-chip-sz{opacity:.9;font-weight:600}
.low-stock-chip-q{font-weight:700}
.low-stock-card-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.low-stock-link{font-size:11px;font-weight:600;color:var(--accent);text-decoration:none;padding:6px 10px;border-radius:6px;border:1px solid rgba(45,90,39,.35)}
.low-stock-link:hover{background:rgba(45,90,39,.08)}
.low-stock-admin{font-size:11px;font-weight:600;color:var(--ink2);text-decoration:none;padding:6px 10px;border-radius:6px;border:1px solid var(--line);background:var(--white);cursor:pointer;font-family:inherit}
.low-stock-admin:hover{background:var(--bg)}
.low-stock-qty{font-size:11px;font-weight:700;padding:2px 8px;border-radius:100px}
.low-stock-qty.out{background:#fee2e2;color:#991b1b}
.low-stock-qty.low{background:#fef3c7;color:#92400e}
.vr-toggle{width:44px;height:26px;border-radius:100px;background:#d1d5db;position:relative;cursor:pointer;transition:background .2s;flex-shrink:0;display:inline-block}
.vr-toggle--on{background:#16a34a}
.vr-toggle-knob{position:absolute;top:3px;left:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.25);transition:transform .2s}
.vr-toggle--on .vr-toggle-knob{transform:translateX(18px)}
.vr-thumb-link{display:inline-block;line-height:0;border-radius:7px;overflow:hidden;vertical-align:middle;box-shadow:0 0 0 1px rgba(0,0,0,.06)}
.vr-thumb-link:hover{box-shadow:0 0 0 2px var(--accent)}
.restock-modal{max-width:430px}
.restock-body{padding:20px 24px}
.restock-name{font-size:13px;color:var(--ink3);margin-bottom:14px}
.restock-grid{grid-template-columns:repeat(4, minmax(0, 1fr));gap:10px;margin:0 0 16px}
.restock-total{font-size:13px;color:var(--ink3);margin-bottom:16px}
.restock-total strong{color:var(--ink)}
.restock-save{width:100%;background:var(--accent);color:#fff;border:none;padding:12px;border-radius:8px;font-weight:700;font-size:14px}
.restock-save:hover{background:var(--accent2)}
/* ── ORDER SEARCH ── */
.order-search{flex:1;max-width:280px;padding:9px 12px;border:1.5px solid var(--line);border-radius:8px;font-size:13px;font-family:inherit;outline:none;transition:border .2s}
.order-search:focus{border-color:var(--accent)}
/* ── QUICK ACTION BUTTONS (order modal) ── */
.od-quick-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.od-qa-btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:7px;border:1.5px solid var(--line);background:var(--white);font-size:12px;font-weight:600;cursor:pointer;transition:all .18s;color:var(--ink2)}
.od-qa-btn:hover{border-color:var(--ink2);background:var(--bg)}
.od-qa-btn.wa{border-color:#25D366;color:#25D366}
.od-qa-btn.wa:hover{background:#f0fdf4}
/* ── BACK TO TOP (shared) ── */
.back-to-top{position:fixed;bottom:90px;right:20px;width:40px;height:40px;border-radius:50%;background:var(--ink);color:#fff;border:none;cursor:pointer;font-size:18px;display:flex;align-items:center;justify-content:center;opacity:0;transform:translateY(10px);transition:all .25s;z-index:180;box-shadow:0 4px 12px rgba(0,0,0,.2)}
.back-to-top.on{opacity:1;transform:translateY(0)}

/* ── MOBILE TOP BAR (nav drawer) ── */
.admin-mobile-header{display:none;align-items:center;gap:12px;padding:10px 14px;padding-left:max(12px, env(safe-area-inset-left));padding-right:max(12px, env(safe-area-inset-right));padding-top:max(10px, env(safe-area-inset-top));background:var(--white);border-bottom:1px solid var(--line);position:fixed;top:0;left:0;right:0;z-index:100;min-height:52px;box-shadow:var(--shadow)}
.admin-menu-toggle{width:44px;height:44px;border:none;background:var(--bg);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--ink);flex-shrink:0;transition:background .15s}
.admin-menu-toggle:hover{background:#e5e7eb}
.admin-menu-toggle svg{width:22px;height:22px;stroke-width:2}
.admin-mobile-title{font-size:15px;font-weight:700;color:var(--ink);letter-spacing:.03em;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.sidebar-backdrop{display:none;position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:104;opacity:0;transition:opacity .22s ease;backdrop-filter:blur(2px)}
.sidebar-backdrop.on{display:block;opacity:1}

/* ── DASHBOARD: responsive grid (override inline 3-col) ── */
#view-dash .dash-grid{grid-template-columns:repeat(3, minmax(0, 1fr))}

/* ── RESPONSIVE ── */
@media (max-width:1280px){
  .stats{grid-template-columns:repeat(2, minmax(0, 1fr))}
  #view-dash .dash-grid{grid-template-columns:1fr}
}
@media (max-width:1024px){
  .admin-mobile-header{display:flex}
  .sidebar{width:min(290px, 86vw);z-index:106;padding-top:env(safe-area-inset-top);padding-bottom:env(safe-area-inset-bottom);transition:transform .28s cubic-bezier(.4,0,.2,1);transform:translate3d(-100%,0,0);box-shadow:none}
  .sidebar.open{transform:translate3d(0,0,0);box-shadow:12px 0 40px rgba(0,0,0,.2)}
  .s-bottom{padding-bottom:max(16px, env(safe-area-inset-bottom))}
  .sidebar-backdrop{z-index:104}
  .sidebar-backdrop.on{pointer-events:auto}
  .mbg{z-index:120}
  .sn{min-height:46px}
  .main{margin-left:0;padding:16px;padding-top:calc(56px + 12px);padding-left:max(14px, env(safe-area-inset-left));padding-right:max(14px, env(safe-area-inset-right));padding-bottom:max(28px, env(safe-area-inset-bottom));max-width:100%;overflow-x:hidden;box-sizing:border-box}
  .layout{overflow-x:hidden;max-width:100vw}
  body{overflow-x:hidden}
  .page-title{font-size:20px}
  .products-filters .pf-search{max-width:none;width:100%;min-width:0}
  .products-count{margin-left:0;width:100%;text-align:left;padding-top:4px}
  .order-search{max-width:none;width:100%;min-width:0;flex:1 1 100%}
  .toolbar .filter-bar{width:100%}
  .form-grid{grid-template-columns:1fr;gap:12px}
  .size-stock-grid{grid-template-columns:repeat(3, minmax(0, 1fr));gap:8px}
  .modal-head{padding:16px 18px;flex-wrap:wrap;gap:8px}
  .modal-body{padding:18px}
  .stat-card{padding:18px 16px}
  .dash-card{padding:16px 18px}
  .fpill{padding:8px 12px;font-size:11px;min-height:40px;align-items:center;display:inline-flex}
  .od-item{flex-wrap:wrap;gap:8px}
  .od-item strong{margin-left:auto}
  .status-row .status-btn{flex:1 1 auto;min-width:calc(50% - 4px);justify-content:center}
  .toast{left:max(14px, env(safe-area-inset-left));right:max(14px, env(safe-area-inset-right));bottom:max(22px, env(safe-area-inset-bottom));text-align:center}
  .products-filters{flex-direction:column;align-items:stretch}
  .products-filters .fselect{width:100%;min-width:0}
  .products-toolbar-split{flex-direction:column;align-items:stretch!important;gap:12px!important}
  .promo-dual-grid,.coupon-dual-grid{grid-template-columns:1fr!important}
  .cat-cover-grid{grid-template-columns:repeat(auto-fit,minmax(140px,1fr))!important;gap:10px!important}
  #vr-search{max-width:100%!important;width:100%}
  #coupon-form-wrap{max-width:100%!important;box-sizing:border-box}
  #view-products #products-body td:nth-child(2) .admin-pthumb-wrap{margin-left:auto;margin-right:auto}
  #view-products #products-body .pname-cell{word-break:break-word;overflow-wrap:anywhere}
  /* Producten / Voorraad / Kortingscodes — kaarten i.p.v. brede tabellen */
  #view-products .tbl-wrap,
  #view-voorraad .tbl-wrap,
  #view-coupons .tbl-wrap{background:transparent;box-shadow:none;border-radius:0;overflow:visible}
  #view-products .tbl-wrap table,
  #view-voorraad .tbl-wrap table,
  #view-coupons .tbl-wrap table{min-width:unset!important;width:100%;border-collapse:separate;border-spacing:0}
  #view-products thead,
  #view-voorraad thead,
  #view-coupons thead{display:none}
  #view-products #products-body tr,
  #view-voorraad #vr-tbody tr,
  #view-coupons #coupons-body tr{
    display:block;
    border:1px solid var(--line);
    border-radius:12px;
    margin-bottom:12px;
    padding:14px;
    background:var(--white);
    box-shadow:var(--shadow);
  }
  #view-products #products-body tr:hover,
  #view-voorraad #vr-tbody tr:hover,
  #view-coupons #coupons-body tr:hover{background:var(--white)}
  #view-products #products-body td,
  #view-voorraad #vr-tbody td,
  #view-coupons #coupons-body td{
    display:block;
    border:none;
    padding:8px 0;
    border-bottom:1px dashed rgba(229,231,235,.9);
    font-size:13px;
  }
  #view-products #products-body tr td:last-child,
  #view-voorraad #vr-tbody tr td:last-child,
  #view-coupons #coupons-body tr td:last-child{border-bottom:none;padding-top:10px}
  #view-products #products-body td::before,
  #view-voorraad #vr-tbody td::before,
  #view-coupons #coupons-body td::before{
    display:block;
    font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--ink3);
    margin-bottom:4px;
  }
  #view-products #products-body td:nth-child(1)::before{content:'Selecteren'}
  #view-products #products-body td:nth-child(2)::before{content:'Foto'}
  #view-products #products-body td:nth-child(3)::before{content:'Kit'}
  #view-products #products-body td:nth-child(4)::before{content:'Competitie'}
  #view-products #products-body td:nth-child(5)::before{content:'Categorie'}
  #view-products #products-body td:nth-child(6)::before{content:'Prijs'}
  #view-products #products-body td:nth-child(7)::before{content:'Voorraad'}
  #view-products #products-body td:nth-child(8)::before{content:'Badge'}
  #view-products #products-body td:nth-child(9)::before{content:'Status'}
  #view-products #products-body td:nth-child(10)::before{content:'Snel leveren'}
  #view-products #products-body td:nth-child(11)::before{content:'Acties'}
  #view-voorraad #vr-tbody td:nth-child(1)::before{content:'Foto'}
  #view-voorraad #vr-tbody td:nth-child(2)::before{content:'Product'}
  #view-voorraad #vr-tbody td:nth-child(3)::before{content:'Competitie'}
  #view-voorraad #vr-tbody td:nth-child(4)::before{content:'Op voorraad'}
  #view-coupons #coupons-body td:nth-child(1)::before{content:'Code'}
  #view-coupons #coupons-body td:nth-child(2)::before{content:'Type'}
  #view-coupons #coupons-body td:nth-child(3)::before{content:'Waarde'}
  #view-coupons #coupons-body td:nth-child(4)::before{content:'Gebruikt'}
  #view-coupons #coupons-body td:nth-child(5)::before{content:'Actief'}
  #view-coupons #coupons-body td:nth-child(6)::before{content:'Aangemaakt'}
  #view-coupons #coupons-body td:nth-child(7)::before{content:'Acties'}
  #view-products #products-body td:nth-child(11) .btn,
  #view-coupons #coupons-body td:nth-child(7) .btn{min-height:42px;padding:10px 14px}
  #view-products #products-body td[colspan],
  #view-voorraad #vr-tbody td[colspan],
  #view-coupons #coupons-body td[colspan]{
    border-bottom:none;text-align:center;padding:36px 16px;
  }
  #view-products #products-body td[colspan]::before,
  #view-voorraad #vr-tbody td[colspan]::before,
  #view-coupons #coupons-body td[colspan]::before{content:none!important;display:none!important}
  /* Bestellingen — kaarten + horizontaal scrollende statusfilters */
  #view-orders .toolbar{flex-direction:column;align-items:stretch;gap:10px}
  #view-orders .filter-bar{
    flex-wrap:nowrap;
    overflow-x:auto;
    -webkit-overflow-scrolling:touch;
    gap:8px;
    padding:4px 2px 10px;
    margin:0 -2px;
    scrollbar-width:none;
    overscroll-behavior-x:contain;
  }
  #view-orders .filter-bar::-webkit-scrollbar{display:none}
  #orders-body tr{display:flex;flex-wrap:wrap;border:1px solid var(--line);border-radius:8px;margin-bottom:10px;padding:10px 12px;background:var(--white);cursor:pointer}
  #orders-body tr td{border:none;padding:2px 4px;font-size:12.5px}
  #orders-body tr td:nth-child(1){font-weight:700;font-size:13px;flex:1 0 60%}
  #orders-body tr td:nth-child(2){flex:1 0 100%;color:var(--ink2);word-break:break-word;overflow-wrap:anywhere}
  #orders-body tr td:nth-child(3){color:var(--ink3);flex:1 0 40%}
  #orders-body tr td:nth-child(4){font-weight:700;flex:1 0 40%;text-align:right}
  #orders-body tr td:nth-child(5){flex:1 0 100%}
  #orders-body tr td:nth-child(6){color:var(--ink3);font-size:11px;flex:1 0 50%}
  #orders-body tr td:nth-child(7){
    flex:1 0 100%;
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:8px;
    align-items:stretch;
  }
  #orders-body tr td:nth-child(7) .btn-sm{margin-left:0!important;width:100%;justify-content:center;min-height:42px;box-sizing:border-box}
  #view-orders table thead{display:none}
  #view-orders .tbl-wrap{background:transparent;box-shadow:none;border-radius:0;overflow:visible}
  #view-orders table{min-width:unset!important;border-collapse:separate;border-spacing:0}
  #orders-body tr td[colspan]{flex:1 0 100%!important;text-align:center;padding:32px 12px!important}
  .promo-settings-box{padding:20px 18px!important}
  .cat-cover-section{padding:16px 14px!important;margin-bottom:18px!important}
  #cover-picker{padding:16px 14px!important}
  #cover-picker-grid{max-height:min(52dvh,380px)!important}
}
@media (max-width:640px){
  .stats{grid-template-columns:1fr}
  .size-stock-grid{grid-template-columns:repeat(2, minmax(0, 1fr))}
  .mbg{align-items:center;justify-content:center;padding:10px;padding-left:max(10px, env(safe-area-inset-left));padding-right:max(10px, env(safe-area-inset-right));padding-top:max(10px, env(safe-area-inset-top));padding-bottom:max(10px, env(safe-area-inset-bottom))}
  .modal{width:100%;max-width:100%;max-height:min(88dvh, 88vh);border-radius:14px;margin:0}
  .modal-head{border-radius:14px 14px 0 0;position:sticky;top:0;background:var(--white);z-index:2}
  .modal-body{padding-bottom:max(18px, env(safe-area-inset-bottom))}
  .save-btn{margin-top:14px;padding:14px;font-size:15px}
  .pm-actions{flex-direction:column;align-items:stretch}
  .pm-actions .save-btn{flex:1 1 auto;width:100%;margin-top:0}
  .pm-dup{width:100%;justify-content:center}
  .img-upload-area{min-height:100px;padding:16px}
  .admin-pthumb-wrap{width:88px;height:110px}
  .low-stock-card-head{flex-wrap:wrap}
  .low-stock-summary-badge{margin-left:auto}
  .low-stock-card-actions{justify-content:flex-start}
  .restock-body{padding:16px}
  .restock-grid{grid-template-columns:repeat(2, minmax(0, 1fr));gap:8px}
}
@media (max-width:400px){
  .login-card{width:100%;max-width:calc(100vw - 32px);padding:36px 22px;margin:0 auto}
  .fpill{padding:7px 10px}
  #orders-body tr td:nth-child(7){grid-template-columns:1fr}
}
@supports (padding: max(0px)){
  .login-wrap{padding-left:max(16px, env(safe-area-inset-left));padding-right:max(16px, env(safe-area-inset-right));padding-bottom:max(24px, env(safe-area-inset-bottom));padding-top:max(24px, env(safe-area-inset-top))}
}
</style>
<link rel="stylesheet" href="css/responsive-global.css?v=15">
</head>
<body>

<?php if (!$auth): ?>
<div class="login-wrap">
  <div class="login-card">
    <div class="login-logo">KitsByElbaa</div>
    <div class="login-sub">Beheer</div>
    <form method="post">
      <input type="hidden" name="_a" value="login">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
      <input class="login-input" type="password" name="pw" placeholder="Wachtwoord" autofocus>
      <button class="login-btn" type="submit">Inloggen</button>
      <?php if ($loginErr): ?>
        <p class="login-err">Onjuist wachtwoord<?= $locked ? ' — te veel pogingen. Wacht 15 minuten.' : '.' ?></p>
        <script>
          (function(){
            var card = document.querySelector('.login-card');
            card.classList.add('shake');
            card.addEventListener('animationend', function(){ card.classList.remove('shake'); }, {once:true});
          })();
        </script>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<script>window.__PUBLIC_SITE__=<?= json_encode(rtrim((string)($cfg['public_site_url'] ?? ''), '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<div class="layout">
  <header class="admin-mobile-header">
    <button type="button" class="admin-menu-toggle" id="adminMenuToggle" aria-label="Navigatie openen" aria-expanded="false" aria-controls="adminSidebar" onclick="toggleAdminSidebar()">
      <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
    </button>
    <span class="admin-mobile-title" id="adminMobileTitle">Overzicht</span>
  </header>
  <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="closeAdminSidebar()" aria-hidden="true"></div>

  <aside class="sidebar" id="adminSidebar">
    <div class="s-logo">KitsByElbaa <span>Beheer</span></div>
    <nav class="s-nav">
      <button class="sn on" id="tab-dash" onclick="tab('dash')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        Overzicht
      </button>
      <button class="sn" id="tab-orders" onclick="tab('orders')" style="position:relative">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        <span id="orders-badge" style="display:none;position:absolute;top:6px;right:10px;background:#ef4444;color:#fff;font-size:10px;font-weight:700;border-radius:100px;padding:1px 6px;line-height:16px"></span>
        Bestellingen
      </button>
      <button class="sn" id="tab-products" onclick="tab('products')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
        Producten
      </button>
      <button class="sn" id="tab-voorraad" onclick="tab('voorraad')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        Voorraad
      </button>
      <button class="sn" id="tab-coupons" onclick="tab('coupons')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M7 7h.01M17 17h.01M9 9l6 6M3 12l9-9 9 9-9 9-9-9z"/></svg>
        Kortingscodes
      </button>
      <button class="sn" id="tab-promo" onclick="tab('promo')">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.26c.477 0 .935.164 1.29.454l3.336 2.667M18 13V9a2 2 0 00-2-2h-1.343M11 5.882V5a2 2 0 012-2h2.343"/></svg>
        Promotie &amp; banner
      </button>
    </nav>
    <div class="s-bottom">
      <a class="s-store" href="index.html" target="_blank">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
        Winkel bekijken
      </a>
      <form method="post" style="margin:0">
        <input type="hidden" name="_a" value="logout">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">
        <button type="submit" class="s-logout" style="width:100%;border:none;background:none;cursor:pointer;font-family:inherit">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          Uitloggen
        </button>
      </form>
    </div>
  </aside>

  <main class="main">

    <div class="view on" id="view-dash">
      <div class="page-title">Overzicht</div>
      <div class="page-sub">Welkom terug — dit is de stand van zaken.</div>
      <div class="stats">
        <div class="stat-card">
          <div class="stat-icon" style="background:#dbeafe"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1d4ed8" stroke-width="1.7" stroke-linejoin="round"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"></path><path d="M3 8l9 5 9-5"></path><path d="M12 13v8"></path></svg></div>
          <div><div class="stat-val" id="s-orders">—</div><div class="stat-lbl">Bestellingen totaal</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#d1fae5">💶</div>
          <div><div class="stat-val" id="s-revenue">—</div><div class="stat-lbl">Omzet totaal</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#fef3c7"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#b45309" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 7.5V12l3 2"></path></svg></div>
          <div><div class="stat-val" id="s-pending">—</div><div class="stat-lbl">In afwachting</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon" style="background:#d1fae5">✅</div>
          <div><div class="stat-val" id="s-paid">—</div><div class="stat-lbl">Betaald</div></div>
        </div>
      </div>
      <div class="dash-grid" style="grid-template-columns:1fr 1fr 1fr">
        <div class="dash-card">
          <h3>Laatste bestellingen</h3>
          <div id="dash-recent"><div class="empty"><div class="empty-ico">📭</div><p>Nog geen bestellingen</p></div></div>
        </div>
        <div class="dash-card">
          <h3>Vereist actie</h3>
          <div id="dash-attention"><div class="empty"><div class="empty-ico"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"></circle><path d="M8.5 12.5l2.5 2.5 4.5-5"></path></svg></div><p>Je bent helemaal bij!</p></div></div>
        </div>
        <div class="dash-card">
          <h3>Lage / geen voorraad</h3>
          <p class="page-sub" style="margin:-8px 0 12px;font-size:12px">Klik een rij om de kit in de winkel te openen (nieuw tabblad). Bijvullen onder <strong>Producten</strong>.</p>
          <div id="dash-low-stock"><div class="empty"><div class="empty-ico">✅</div><p>Alle producten op voorraad</p></div></div>
        </div>
      </div>
    </div>

    <div class="view" id="view-orders">
      <div class="page-title">Bestellingen</div>
      <div class="page-sub">Klik een rij voor details en status omzetten.</div>
      <div class="toolbar" style="flex-wrap:wrap;gap:10px">
        <input type="search" class="order-search" id="order-search" placeholder="Zoeken op naam, e-mail, bestelnummer…" oninput="filterOrderSearch()">
        <div class="filter-bar">
          <button class="fpill on" onclick="filterOrders('all',this)">Alles</button>
          <button class="fpill" onclick="filterOrders('pending',this)">In afwachting</button>
          <button class="fpill" onclick="filterOrders('confirmed',this)">Bevestigd</button>
          <button class="fpill" onclick="filterOrders('paid',this)">Betaald</button>
          <button class="fpill" onclick="filterOrders('shipped',this)">Verzonden</button>
          <button class="fpill" onclick="filterOrders('delivered',this)">Bezorgd</button>
          <button class="fpill" onclick="filterOrders('cancelled',this)">Geannuleerd</button>
        </div>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Bestelnr.</th><th>Klant</th><th>Plaats</th><th>Totaal</th><th>Status</th><th>Datum</th><th>Snel</th></tr></thead>
          <tbody id="orders-body"><tr><td colspan="7" style="text-align:center;padding:48px;color:var(--ink3)">Bestellingen laden…</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="view" id="view-products">
      <div class="page-title">Producten</div>
      <div class="page-sub">Kits toevoegen, bewerken of verwijderen. Preview gebruikt dezelfde paden als de winkel (uploads of <code style="font-size:11px;background:var(--bg);padding:2px 6px;border-radius:4px">kits/…</code>).</div>

      <div class="cat-cover-section" style="background:var(--white);border:1px solid var(--line);border-radius:10px;padding:20px 24px;margin-bottom:24px">
        <div style="font-weight:700;font-size:15px;margin-bottom:4px">Categorie-coverafbeeldingen</div>
        <div style="font-size:12px;color:var(--ink3);margin-bottom:16px">Klik <strong>Kies foto</strong> om te bepalen welke foto op de homepage per categorie getoond wordt.</div>
        <div class="cat-cover-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px">
          <?php foreach(['shirts'=>'Shirts','sets'=>'Sets','hemdsetjes'=>'Hemdsetjes','retro'=>'Retro','kids'=>'Kids','voorraad'=>'Voorraad'] as $catKey=>$catLabel): ?>
          <div style="border:1px solid var(--line);border-radius:8px;overflow:hidden">
            <div style="font-size:11px;font-weight:700;color:var(--ink2);padding:8px 12px;border-bottom:1px solid var(--line);background:var(--bg)"><?= strtoupper($catLabel) ?></div>
            <div style="padding:10px 12px">
              <div id="cover-thumb-<?= $catKey ?>" style="width:100%;height:110px;border-radius:6px;background:var(--bg);background-size:cover;background-position:center;border:1px solid var(--line);margin-bottom:8px"></div>
              <div id="cover-name-<?= $catKey ?>" style="font-size:11px;color:var(--ink3);margin-bottom:8px;min-height:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
              <button type="button" class="btn btn-sm" onclick="openCoverPicker('<?= $catKey ?>')"
                style="width:100%;background:var(--ink);color:#fff;border:none;padding:7px;border-radius:5px;font-size:12px;cursor:pointer;font-weight:600">
                Kies foto
              </button>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div id="cover-picker" style="display:none;background:var(--white);border:1px solid var(--line);border-radius:10px;padding:20px 24px;margin-bottom:24px">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
          <div style="font-weight:700;font-size:14px" id="cover-picker-title">Cover kiezen — Shirts</div>
          <button type="button" onclick="closeCoverPicker()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--ink3)">✕</button>
        </div>
        <input type="search" class="finput" id="cover-picker-search" placeholder="Zoeken op naam…"
          autocomplete="off" style="margin-bottom:12px" oninput="renderCoverPickerGrid(this.value)">
        <div id="cover-picker-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;max-height:400px;overflow-y:auto"></div>
      </div>

      <div class="products-filters">
        <input type="search" class="pf-search" id="pf-search" placeholder="Zoeken op naam, competitie, ID, kits-map…" autocomplete="off">
        <select class="fselect" id="pf-league" title="Competitie / categorie">
          <option value="all">Alle competities</option>
          <option value="premier">Premier League</option>
          <option value="laliga">La Liga</option>
          <option value="bundesliga">Bundesliga</option>
          <option value="seriea">Serie A</option>
          <option value="ligue1">Ligue 1</option>
          <option value="eredivisie">Eredivisie</option>
          <option value="national">Nationale teams</option>
          <option value="__other">Overig / leeg</option>
        </select>
        <select class="fselect" id="pf-active">
          <option value="all">Alle statussen</option>
          <option value="1">Alleen actief</option>
          <option value="0">Alleen verborgen</option>
        </select>
        <select class="fselect" id="pf-images">
          <option value="all">Alle producten</option>
          <option value="yes">Met foto’s</option>
          <option value="no">Zonder foto’s</option>
        </select>
        <select class="fselect" id="pf-source">
          <option value="all">Alle bronnen</option>
          <option value="kits">Gesynchroniseerd uit kits/</option>
          <option value="manual">Handmatig / uploads</option>
        </select>
        <button type="button" class="btn btn-sm" style="background:var(--bg);border:1px solid var(--line);color:var(--ink2)" onclick="resetProductFilters()">Filters wissen</button>
        <span class="products-count" id="products-count"></span>
      </div>
      <div class="toolbar products-toolbar-split">
        <div class="filter-bar">
          <label style="display:flex;align-items:center;gap:8px;font-size:12px;color:var(--ink3)">
            <input type="checkbox" id="bulk-select-all" onclick="toggleBulkSelectAll(this.checked)">
            Alles selecteren
          </label>
          <select class="fselect" id="bulk-action" style="min-width:180px">
            <option value="">Bulkbewerking…</option>
            <option value="activate">Actief zetten</option>
            <option value="hide">Verbergen</option>
            <option value="badge_new">Badge: Nieuw</option>
            <option value="badge_hot">Badge: Hot</option>
            <option value="badge_none">Badge: geen</option>
            <option value="delete">Geselecteerde verwijderen</option>
          </select>
          <button class="btn btn-sm" style="background:var(--bg);border:1px solid var(--line);color:var(--ink2)" onclick="applyBulkAction()">Toepassen</button>
        </div>
        <div style="display:flex;gap:8px;align-items:center">
          <button class="btn btn-primary" onclick="openProductModal()">
            <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" style="width:15px;height:15px"><path d="M12 4v16m8-8H4"/></svg>
            Product toevoegen
          </button>
        </div>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th style="width:36px"></th><th style="width:120px">Foto</th><th>Kit</th><th>Competitie</th><th>Categorie</th><th>Prijs</th><th>Voorraad</th><th>Badge</th><th>Status</th><th style="width:110px">Snel lever.</th><th>Acties</th></tr></thead>
          <tbody id="products-body"><tr><td colspan="10" style="text-align:center;padding:48px;color:var(--ink3)">Producten laden…</td></tr></tbody>
        </table>
      </div>
    </div>

    <div class="view" id="view-voorraad">
      <div class="page-title">Voorraad</div>
      <div class="page-sub">Producten die je op voorraad hebt — levering <strong>1–2 dagen</strong>. Toevoegen doe je via <strong>Producten → Snel lever.</strong></div>
      <div class="toolbar">
        <input type="search" class="pf-search" id="vr-search" placeholder="Zoeken op naam of competitie…" autocomplete="off" style="max-width:320px" oninput="filterVoorraadRows(this.value)">
        <span id="vr-count" style="font-size:13px;color:var(--ink3)"></span>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th style="width:88px">Foto</th>
              <th>Product</th>
              <th>Competitie</th>
              <th style="width:160px;text-align:center">Op voorraad</th>
            </tr>
          </thead>
          <tbody id="vr-tbody">
            <?php
              $_voorraadItems = array_filter($_allProducts, fn($p) => !empty($p['in_voorraad']));
              function admin_product_slug(array $p): string {
                $id = (int)($p['id'] ?? 0);
                $tail = preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower((string)($p['name'] ?? ''), 'UTF-8'));
                $tail = trim((string)$tail, '-');
                if ($tail === '') { $tail = 'kit'; }
                return $id . '-' . $tail;
              }
              function admin_product_store_href(array $p): string {
                return 'product.php?slug=' . rawurlencode(admin_product_slug($p));
              }
              function vrImgSrc(string $f): string {
                $f = trim(str_replace('\\', '/', $f));
                $f = ltrim($f, '/');
                $prefix = 'uploads/products/';
                while (stripos($f, $prefix) === 0) { $f = substr($f, strlen($prefix)); }
                if ($f === '') { return ''; }
                if (preg_match('#^https?://#i', $f)) { return htmlspecialchars($f, ENT_QUOTES, 'UTF-8'); }
                if (strpos($f, '/') !== false) {
                  $parts = array_filter(explode('/', $f), fn($x) => $x !== '');
                  $enc = array_map('rawurlencode', $parts);
                  return htmlspecialchars($prefix . implode('/', $enc), ENT_QUOTES, 'UTF-8');
                }
                return htmlspecialchars($prefix . rawurlencode($f), ENT_QUOTES, 'UTF-8');
              }
            ?>
            <?php if (empty($_voorraadItems)): ?>
            <tr><td colspan="4" style="text-align:center;padding:48px;color:var(--ink3)">Nog geen producten op voorraad. Zet ze aan via <strong>Producten → Snel lever.</strong></td></tr>
            <?php else: foreach ($_voorraadItems as $_p):
              $_img = $_p['image'] ?? $_p['image2'] ?? $_p['image3'] ?? '';
              $_src = $_img ? vrImgSrc($_img) : '';
              $_href = htmlspecialchars(admin_product_store_href($_p), ENT_QUOTES, 'UTF-8');
            ?>
            <tr id="vr-row-<?= (int)$_p['id'] ?>" onclick="openProductModal(<?= (int)$_p['id'] ?>)" style="cursor:pointer" title="Klik om te bewerken (foto = winkel)">
              <td onclick="event.stopPropagation()">
                <?php if ($_src): ?>
                <a class="vr-thumb-link" href="<?= $_href ?>" target="_blank" rel="noopener noreferrer" title="Open in winkel">
                  <img src="<?= $_src ?>" alt="" loading="lazy" style="width:52px;height:52px;object-fit:cover;border-radius:7px;display:block">
                </a>
                <?php else: ?>
                <div style="width:52px;height:52px;border-radius:7px;background:var(--bg);display:flex;align-items:center;justify-content:center"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.3" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></div>
                <?php endif; ?>
              </td>
              <td style="font-weight:600"><?= htmlspecialchars($_p['name']) ?></td>
              <td style="color:var(--ink3)"><?= htmlspecialchars((string)($_p['league'] ?? '')) ?></td>
              <td style="text-align:center" onclick="event.stopPropagation()">
                <label style="display:inline-flex;align-items:center;gap:9px;cursor:pointer">
                  <div class="vr-toggle vr-toggle--on" onclick="toggleInVoorraad(<?= (int)$_p['id'] ?>,0,this)">
                    <div class="vr-toggle-knob"></div>
                  </div>
                  <span id="vr-lbl-<?= (int)$_p['id'] ?>" style="font-size:12px;font-weight:600;color:#16a34a">Aan</span>
                </label>
              </td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="view" id="view-coupons">
      <div class="page-title">Kortingscodes</div>
      <div class="page-sub">Beheer kortingscodes. Wijzigingen zijn direct actief.</div>
      <div class="toolbar">
        <button class="btn btn-primary" onclick="openCouponForm()">+ Nieuwe code</button>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead><tr><th>Code</th><th>Type</th><th>Waarde</th><th>Gebruikt</th><th>Actief</th><th>Aangemaakt</th><th>Acties</th></tr></thead>
          <tbody id="coupons-body"><tr><td colspan="7" style="text-align:center;padding:48px;color:var(--ink3)">Laden…</td></tr></tbody>
        </table>
      </div>

      <div id="coupon-form-wrap" style="display:none;margin-top:24px;background:var(--white);border-radius:12px;padding:28px 24px;box-shadow:var(--shadow);max-width:480px">
        <h3 style="font-size:16px;font-weight:700;margin-bottom:18px" id="coupon-form-title">Nieuwe kortingscode</h3>
        <input type="hidden" id="cf-id">
        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:6px">Code</label>
          <input class="login-input" id="cf-code" placeholder="bv. ZOMER10" style="text-transform:uppercase">
        </div>
        <div class="coupon-dual-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px">
          <div>
            <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:6px">Type</label>
            <select class="login-input" id="cf-type" style="margin-bottom:0">
              <option value="percent">Procent (%)</option>
              <option value="fixed">Vast bedrag (€)</option>
            </select>
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:6px">Waarde</label>
            <input class="login-input" id="cf-value" type="number" min="0.01" max="100" step="0.01" value="10" style="margin-bottom:0">
          </div>
        </div>
        <div style="margin-bottom:18px;display:flex;align-items:center;gap:10px">
          <input type="checkbox" id="cf-active" checked style="width:18px;height:18px;cursor:pointer">
          <label for="cf-active" style="font-size:14px;cursor:pointer">Actief</label>
        </div>
        <div id="coupon-form-err" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none"></div>
        <div style="display:flex;gap:10px">
          <button class="btn btn-primary" onclick="saveCoupon()">Opslaan</button>
          <button class="btn" style="background:var(--line);color:var(--ink2)" onclick="closeCouponForm()">Annuleren</button>
        </div>
      </div>
    </div>

    <div class="view" id="view-promo">
      <div class="page-title">Promotie &amp; banner</div>
      <div class="page-sub">Teksten op de winkel (banner, popup homepage, FAQ). <strong>Kortingscodes</strong> (random codes, percentage, aan/uit) beheer je onder <strong>Kortingscodes</strong>.</div>
      <div class="promo-settings-box" style="background:var(--white);border:1px solid var(--line);border-radius:12px;padding:24px 28px;max-width:720px">
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:8px">Bannertekst (boven navigatie)</label>
          <textarea class="login-input" id="sf-banner" rows="3" style="resize:vertical;min-height:72px;font-family:inherit;line-height:1.5" placeholder="HTML: &lt;strong&gt; toegestaan"></textarea>
          <div style="font-size:11px;color:var(--ink3);margin-top:6px">Toegestaan: vet/cursief, &lt;br&gt;. Gebruik bijv. <code style="font-size:10px">&lt;strong&gt;KITSBYELBA&lt;/strong&gt;</code> en <code style="font-size:10px">&lt;strong&gt;KitsByElbaa&lt;/strong&gt;</code>.</div>
        </div>
        <div class="promo-dual-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px">
          <div>
            <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:8px">Pop-up titel (alleen homepage)</label>
            <input class="login-input" id="sf-popup-title" type="text">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:8px">Pop-up: getoonde code</label>
            <input class="login-input" id="sf-popup-code" type="text" placeholder="bv. kitsbyelba">
          </div>
        </div>
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:8px">Pop-up subregel</label>
          <input class="login-input" id="sf-popup-sub" type="text">
        </div>
        <div style="margin-bottom:22px">
          <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:8px">FAQ-antwoord (korting — homepage)</label>
          <textarea class="login-input" id="sf-faq" rows="3" style="resize:vertical;min-height:72px;font-family:inherit;line-height:1.5"></textarea>
        </div>
        <div id="sf-err" style="color:var(--red);font-size:13px;margin-bottom:12px;display:none"></div>
        <button type="button" class="btn btn-primary" onclick="savePromoSettings()">Opslaan</button>
      </div>
    </div>

  </main>
</div>

<div class="mbg" id="confirm-modal" style="z-index:9999">
  <div class="modal" style="max-width:380px">
    <div class="modal-body" style="padding:28px 24px 20px">
      <div style="font-size:18px;margin-bottom:6px">🗑️ <strong id="confirm-title">Product verwijderen?</strong></div>
      <div id="confirm-msg" style="font-size:13px;color:var(--ink3);margin-bottom:20px">Dit kan niet ongedaan worden gemaakt.</div>
      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button class="btn btn-sm" onclick="closeConfirm()" style="background:var(--bg);border:1px solid var(--line);color:var(--ink2)">Annuleren</button>
        <button class="btn btn-sm" id="confirm-ok" style="background:#ef4444;color:#fff;border:none">Verwijderen</button>
      </div>
    </div>
  </div>
</div>

<div class="mbg" id="restock-modal">
  <div class="modal restock-modal">
    <div class="modal-head">
      <span class="modal-title" id="restock-title">Snel bijvullen</span>
      <button class="xbtn" onclick="closeRestockModal()">✕</button>
    </div>
    <div class="modal-body restock-body">
      <div id="restock-name" class="restock-name"></div>
      <div class="size-stock-grid restock-grid" id="restock-grid"></div>
      <div class="restock-total">Totaal: <strong id="restock-total">0</strong></div>
      <button class="restock-save" onclick="saveQuickRestock()">Voorraad opslaan</button>
    </div>
  </div>
</div>

<div class="mbg" id="order-modal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title" id="om-title">Bestelling</span>
      <button class="xbtn" onclick="closeOrderModal()">✕</button>
    </div>
    <div class="modal-body" id="om-body">Laden…</div>
  </div>
</div>

<div class="mbg" id="product-modal">
  <div class="modal">
    <div class="modal-head">
      <span class="modal-title" id="pm-title">Product toevoegen</span>
      <button class="xbtn" onclick="closeProductModal()">✕</button>
    </div>
    <div class="modal-body">
      <div class="form-grid">
        <div class="fg full">
          <label class="flabel">Hoofdafbeelding</label>
          <div class="img-upload-area" id="p-img-area-1" onclick="document.getElementById('p-img-input-1').click()">
            <div id="p-img-preview-1">
              <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24" style="width:32px;height:32px;opacity:.4"><path d="M2.25 15.75l5.159-5.159a2.25 2.25 0 013.182 0l5.159 5.159m-1.5-1.5l1.409-1.409a2.25 2.25 0 013.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 001.5-1.5V6a1.5 1.5 0 00-1.5-1.5H3.75A1.5 1.5 0 002.25 6v12a1.5 1.5 0 001.5 1.5zm10.5-11.25h.008v.008h-.008V8.25zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z"/></svg>
              <div>Klik om een foto te uploaden</div>
              <div class="img-upload-hint">JPG, PNG of WebP · max. 5 MB</div>
            </div>
          </div>
          <input type="file" id="p-img-input-1" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="previewImageSlot(this,1)">
          <input type="hidden" id="p-img-current-1">
          <button type="button" class="img-remove-btn" id="p-img-remove-1" style="display:none" onclick="removeImageSlot(1)">✕ Foto verwijderen</button>
        </div>

        <div class="fg">
          <label class="flabel">Galerij 2 (optioneel)</label>
          <div class="img-upload-area" id="p-img-area-2" onclick="document.getElementById('p-img-input-2').click()">
            <div id="p-img-preview-2">
              <div>Klik om een foto te uploaden</div>
              <div class="img-upload-hint">JPG, PNG of WebP</div>
            </div>
          </div>
          <input type="file" id="p-img-input-2" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="previewImageSlot(this,2)">
          <input type="hidden" id="p-img-current-2">
          <button type="button" class="img-remove-btn" id="p-img-remove-2" style="display:none" onclick="removeImageSlot(2)">✕ Foto verwijderen</button>
        </div>

        <div class="fg">
          <label class="flabel">Galerij 3 (optioneel)</label>
          <div class="img-upload-area" id="p-img-area-3" onclick="document.getElementById('p-img-input-3').click()">
            <div id="p-img-preview-3">
              <div>Klik om een foto te uploaden</div>
              <div class="img-upload-hint">JPG, PNG of WebP</div>
            </div>
          </div>
          <input type="file" id="p-img-input-3" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="previewImageSlot(this,3)">
          <input type="hidden" id="p-img-current-3">
          <button type="button" class="img-remove-btn" id="p-img-remove-3" style="display:none" onclick="removeImageSlot(3)">✕ Foto verwijderen</button>
        </div>

        <div class="fg full">
          <label class="flabel">Productbeschrijving</label>
          <textarea class="finput" id="p-desc" placeholder="Wat maakt deze kit bijzonder? Materiaal, pasvorm…"></textarea>
        </div>
        <div class="fg"><label class="flabel">Pasvorm</label><textarea class="finput" id="p-fit" placeholder="bijv. slank / regular"></textarea></div>
        <div class="fg"><label class="flabel">Maatadvies</label><textarea class="finput" id="p-size-advice" placeholder="bijv. een maat groter voor relaxed"></textarea></div>
        <div class="fg"><label class="flabel">Materiaal</label><textarea class="finput" id="p-material" placeholder="bijv. licht ademend polyester"></textarea></div>
        <div class="fg"><label class="flabel">Verzending</label><textarea class="finput" id="p-ship-info" placeholder="bijv. levering binnen enkele werkdagen"></textarea></div>
        <div class="fg"><label class="flabel">Retour</label><textarea class="finput" id="p-returns-info" placeholder="bijv. retourtermijn voor niet-gepersonaliseerde items"></textarea></div>
        <div class="fg"><label class="flabel">Personalisatiebeleid</label><textarea class="finput" id="p-pers-policy" placeholder="bijv. gepersonaliseerde items niet retour"></textarea></div>
        <div class="fg"><label class="flabel">Wasinstructies</label><textarea class="finput" id="p-care" placeholder="bijv. wassen op 30°C, niet drogen"></textarea></div>
        <div class="fg"><label class="flabel">Volgorde galerij</label><input class="finput" id="p-img-order" placeholder="1,2,3"></div>

        <div class="fg full"><label class="flabel">Kitnaam *</label><input class="finput" id="p-name" placeholder="bijv. Arsenal thuis 25/26"></div>
        <div class="fg full" id="p-kits-path-row" style="display:none">
          <label class="flabel">Kits-map (sync)</label>
          <input class="finput" id="p-kits-path" type="text" readonly style="background:var(--bg);color:var(--ink2);cursor:default" title="Via kits/-sync — opnieuw syncen om afbeeldingen te wijzigen">
        </div>
        <div class="fg"><label class="flabel">Competitie *</label><input class="finput" id="p-league" placeholder="bijv. Premier League"></div>
        <div class="fg"><label class="flabel">Categorie</label>
          <select class="fselect" id="p-cat">
            <option value="">— geen —</option>
            <option value="premier">Premier League</option>
            <option value="laliga">La Liga</option>
            <option value="bundesliga">Bundesliga</option>
            <option value="seriea">Serie A</option>
            <option value="ligue1">Ligue 1</option>
            <option value="eredivisie">Eredivisie</option>
            <option value="national">National</option>
            <option value="retro">Retro</option>
            <option value="hemdsetjes">Hemdsetjes</option>
          </select>
        </div>
        <div class="fg" style="display:none"><label class="flabel">Versie</label>
          <select class="fselect" id="p-version">
            <option value="">Beide / niet gespecificeerd</option>
            <option value="fan">Fanversie</option>
            <option value="player">Spelersversie</option>
          </select>
        </div>
        <div class="fg" style="display:none"><label class="flabel">Emoji (als er geen foto is)</label><input class="finput" id="p-emoji" placeholder="👕" maxlength="4"></div>
        <div class="fg"><label class="flabel">Prijs (€) *</label><input class="finput" id="p-price" type="number" step="0.01" min="0" placeholder="29.99"></div>
        <div class="fg full">
          <label class="flabel">Voorraad per maat <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--ink3);font-size:11px">— totaal wordt automatisch berekend</span></label>
          <div class="ss-stock-card">
            <div class="ss-qs">
              <span class="ss-qs-lbl">Snel invullen</span>
              <button type="button" class="ss-qs-btn" onclick="fillStockPreset(1)">1× per maat</button>
              <button type="button" class="ss-qs-btn" onclick="fillStockPreset(2)">2× per maat</button>
              <button type="button" class="ss-qs-btn" onclick="fillStockPreset(5)">5× per maat</button>
              <button type="button" class="ss-qs-btn danger" onclick="fillStockPreset(0)">Alles 0</button>
            </div>
          </div>
          <div class="size-stock-grid">
            <div class="ss-item"><span class="ss-lbl">XS</span><input class="finput ss-input" id="ss-XS" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item"><span class="ss-lbl">S</span><input class="finput ss-input" id="ss-S" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item"><span class="ss-lbl">M</span><input class="finput ss-input" id="ss-M" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item"><span class="ss-lbl">L</span><input class="finput ss-input" id="ss-L" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item"><span class="ss-lbl">XL</span><input class="finput ss-input" id="ss-XL" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item"><span class="ss-lbl">XXL</span><input class="finput ss-input" id="ss-XXL" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item ss-item--fan-only"><span class="ss-lbl">2XL</span><input class="finput ss-input" id="ss-2XL" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item ss-item--fan-only"><span class="ss-lbl">3XL</span><input class="finput ss-input" id="ss-3XL" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
            <div class="ss-item ss-item--fan-only"><span class="ss-lbl">4XL</span><input class="finput ss-input" id="ss-4XL" type="number" min="0" step="1" value="0" oninput="recalcTotalStock()"></div>
          </div>
          <div class="ss-total">Voorraad totaal: <strong id="ss-total-val">0</strong></div>
        </div>
        <div class="fg full" style="padding-top:18px;border-top:1px solid var(--line);margin-top:4px">
          <label class="flabel">Player-versie <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--ink3);font-size:11px">— laat prijs leeg én voorraad 0 als dit product geen player-versie heeft</span></label>
          <div style="margin:8px 0 12px;max-width:220px">
            <label class="flabel">Player-prijs (€)</label>
            <input class="finput" id="p-player-price" type="number" step="0.01" min="0" placeholder="bijv. 39.99">
          </div>
          <label class="flabel">Player-voorraad per maat <span style="font-weight:400;text-transform:none;letter-spacing:0;color:var(--ink3);font-size:11px">— player heeft geen 2XL/3XL/4XL</span></label>
          <div class="size-stock-grid">
            <div class="ss-item"><span class="ss-lbl">XS</span><input class="finput ss-input" id="ps-XS" type="number" min="0" step="1" value="0"></div>
            <div class="ss-item"><span class="ss-lbl">S</span><input class="finput ss-input" id="ps-S" type="number" min="0" step="1" value="0"></div>
            <div class="ss-item"><span class="ss-lbl">M</span><input class="finput ss-input" id="ps-M" type="number" min="0" step="1" value="0"></div>
            <div class="ss-item"><span class="ss-lbl">L</span><input class="finput ss-input" id="ps-L" type="number" min="0" step="1" value="0"></div>
            <div class="ss-item"><span class="ss-lbl">XL</span><input class="finput ss-input" id="ps-XL" type="number" min="0" step="1" value="0"></div>
            <div class="ss-item"><span class="ss-lbl">XXL</span><input class="finput ss-input" id="ps-XXL" type="number" min="0" step="1" value="0"></div>
          </div>
        </div>
        <input type="hidden" id="p-stock">
        <div class="fg"><label class="flabel">Badge</label>
          <select class="fselect" id="p-badge">
            <option value="">Geen</option>
            <option value="new">Nieuw</option>
            <option value="hot">Populair</option>
          </select>
        </div>
        <div class="fg"><label class="flabel">Sorteervolgorde</label><input class="finput" id="p-sort" type="number" min="0" placeholder="0"></div>
        <div class="fg full" style="display:flex;gap:28px;flex-wrap:wrap;padding-top:18px;border-top:1px solid var(--line);margin-top:4px">
          <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer">
            <input type="checkbox" id="p-active" checked style="width:16px;height:16px"> Actief (zichtbaar in winkel)
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;cursor:pointer">
            <input type="checkbox" id="p-in-voorraad" style="width:16px;height:16px">
            <span>Op voorraad <span style="font-size:12px;color:var(--ink3);font-weight:400">— levering 1–2 dagen</span></span>
          </label>
        </div>
      </div>
      <input type="hidden" id="p-id">
      <div class="pm-actions">
        <button type="button" class="save-btn" onclick="saveProduct()">Product opslaan</button>
        <button type="button" id="pm-dup-btn" class="btn pm-dup" style="display:none" onclick="duplicateCurrentProduct()">Product dupliceren</button>
      </div>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
/* Server-injecties (enige PHP-in-JS). Overige admin-JS: js/admin.js */
const KBE_EMAILJS_PUBLIC_KEY = <?= json_encode((string)EMAILJS_PK, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EJSVC_ORDER = <?= json_encode((string)EMAILJS_SVC_ORDER, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EJSVC_RESTOCK = <?= json_encode((string)EMAILJS_SVC_RESTOCK, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EJTPL = <?= json_encode((string)EMAILJS_TPL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const CSRF_TOKEN = <?= json_encode((string)($_SESSION['csrf_token'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
let productsCache = <?= json_encode($_allProducts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE) ?: '[]' ?>;
/** Admin inbox — BCC copy when sending payment mail (must match optional “Bcc” field in EmailJS template, e.g. {{bcc}}) */
const ADMIN_NOTIFY_EMAIL  = <?= json_encode(ADMIN_NOTIFY_EMAIL, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EMAIL_PUBLIC_BASE   = <?= json_encode(rtrim($cfg['public_site_url'] ?? '', '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
const EMAILJS_RESTOCK_TPL = <?= json_encode((string)EMAILJS_RESTOCK, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script defer src="<?= kits_asset('js/admin.js') ?>"></script>

<?php endif; ?>
</body>
</html>












