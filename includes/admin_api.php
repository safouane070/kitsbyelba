<?php
// Admin AJAX-acties (X-Action header). Wordt alleen vanuit admin.php geladen,
// na login- en CSRF-check; draait in dezelfde scope ($pdo, $cfg, $d, $act).
if (!isset($act, $d, $pdo)) { http_response_code(403); exit; }

    switch ($act) {

        case 'stats':
            // Self-healing: cancel + restock orders that were never confirmed/paid.
            // Runs here (dashboard load) so no cron is needed.
            kits_expire_stale_pending_orders($pdo, (int)($cfg['pending_order_ttl_hours'] ?? 96));
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
                    kits_restock_order_items($pdo, $id);
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
                    ? (dirname(__DIR__) . '/' . ltrim($img, '/'))
                    : (dirname(__DIR__) . '/uploads/products/' . basename($img));
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
            require_once dirname(__DIR__) . '/includes/site_settings.php';
            echo json_encode(kits_load_site_settings($pdo));
            break;

        case 'save_site_settings':
            require_once dirname(__DIR__) . '/includes/site_settings.php';
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
            $set('promo_faq_answer', kits_sanitize_promo_html(trim((string)($d['promo_faq_answer'] ?? ''))));
            echo json_encode(['ok' => true]);
            break;

        default:
            echo json_encode(['error' => 'Onbekende actie']);
    }
