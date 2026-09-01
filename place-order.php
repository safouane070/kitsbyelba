<?php
// Never output PHP errors/warnings to the browser — they break JSON parsing
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL); // still logs to error_log, just not to browser output

// Buffer all output so accidental whitespace/notices don't corrupt JSON
ob_start();

// Start session so we can read user_id if the customer is logged in
require_once __DIR__ . '/includes/session.php';
kits_session_start('Lax', 60 * 120);  // gc_maxlifetime nu vóór start (was erna = no-op)

header('Content-Type: application/json');
$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/emailjs_send.php';
require_once __DIR__ . '/includes/cors.php';
require_once __DIR__ . '/includes/rate_limit.php';
require_once __DIR__ . '/includes/app_log.php';
kits_emit_cors_headers($cfg, true);
header('Access-Control-Allow-Headers: Content-Type, Cookie, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Max-Age: 86400');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(204);
    exit;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (
    !kits_rate_limit_allow('place_order_' . $ip, 40, 3600) ||
    !kits_rate_limit_allow('place_order_burst_' . $ip, 8, 600)
) {
    ob_end_clean();
    http_response_code(429);
    echo json_encode(['error' => 'Te veel bestellingen van dit netwerk. Probeer het later opnieuw.']);
    exit;
}

// ===== CONNECT =====
try {
    $pdo = kits_pdo($cfg);
} catch (PDOException $e) {
    ob_end_clean();
    kits_log('error', 'place_order_db_connect_failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Dienst tijdelijk niet beschikbaar. Probeer het opnieuw.']);
    exit;
}

// ===== GET DATA =====
$data = json_decode(file_get_contents("php://input"), true);
if (!$data) {
    ob_end_clean(); http_response_code(400);
    echo json_encode(["error" => "Ongeldige gegevens"]);
    exit;
}

// ===== CSRF (checkout token from api/checkout_csrf.php) =====
$csrfIn = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $data['csrf_token'] ?? '');
$csrfSession = $_SESSION['checkout_csrf'] ?? '';
$csrfOk = is_string($csrfSession) && $csrfSession !== ''
    && $csrfIn !== '' && hash_equals($csrfSession, $csrfIn);
if (!$csrfOk) {
    ob_end_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Beveiligingssessie verlopen. Vernieuw de pagina en probeer opnieuw.']);
    exit;
}

// Honeypot: must stay empty (bots often fill hidden "website" fields)
if (trim((string)($data['website'] ?? '')) !== '') {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Ongeldige gegevens']);
    exit;
}

// ===== REQUIRED FIELD VALIDATION =====
$required = ["name","email","phone","street","zip","city","items"];
foreach ($required as $field) {
    if (empty($data[$field])) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["error" => "Ontbrekend veld: $field"]);
        exit;
    }
}
if (!filter_var($data["email"], FILTER_VALIDATE_EMAIL)) {
    ob_end_clean(); http_response_code(400);
    echo json_encode(["error" => "Ongeldig e-mailadres"]);
    exit;
}
if (!is_array($data["items"]) || !count($data["items"])) {
    ob_end_clean(); http_response_code(400);
    echo json_encode(["error" => "Geen artikelen in de bestelling"]);
    exit;
}

// ===== INPUT LENGTH LIMITS =====
$maxLens = ['name'=>120,'email'=>254,'phone'=>30,'street'=>200,'zip'=>20,'city'=>100];
foreach ($maxLens as $field => $max) {
    if (isset($data[$field]) && mb_strlen((string)$data[$field]) > $max) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["error" => "Veld '$field' is te lang"]);
        exit;
    }
}
if (isset($data['notes']) && mb_strlen((string)$data['notes']) > 500) {
    $data['notes'] = mb_substr($data['notes'], 0, 500);
}

require_once __DIR__ . '/includes/address_input_validate.php';
$addrErr = kits_validate_checkout_address_fields($data);
if ($addrErr !== null) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => $addrErr]);
    exit;
}

// ===== IDEMPOTENCY (double submit / flaky network — replay same JSON response) =====
$idemRaw = trim((string)($data['idempotency_key'] ?? ''));
if ($idemRaw === '' || strlen($idemRaw) > 72) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['error' => 'Vernieuw de pagina en probeer opnieuw.']);
    exit;
}
$idemSlot = 'checkout_idem_' . hash('sha256', $idemRaw);
if (!empty($_SESSION[$idemSlot]) && is_array($_SESSION[$idemSlot])) {
    $idemCached = $_SESSION[$idemSlot];
    $idemBody = (string)($idemCached['body'] ?? '');
    $idemTs = (int)($idemCached['ts'] ?? 0);
    if ($idemBody !== '' && (time() - $idemTs) < 86400) {
        ob_end_clean();
        echo $idemBody;
        exit;
    }
}

// ===== PRICES FROM DB (never trust client prices) =====
$subtotal    = 0;
$printFee    = (float)($cfg['custom_printing_price'] ?? 0);
$badgeExtra  = (float)($cfg['badge_extra_price'] ?? 3);
$priceStmt   = $pdo->prepare("SELECT name, price, player_price, image, image2, image3 FROM products WHERE id=? AND active=1 LIMIT 1");
foreach ($data["items"] as &$item) {
    $pid = (int)($item["id"] ?? $item["product_id"] ?? 0);
    $qty = (int)($item["qty"] ?? $item["quantity"] ?? 0);
    if ($pid <= 0 || $qty <= 0 || $qty > 99) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["error" => "Ongeldige regel in winkelwagen"]);
        exit;
    }
    $priceStmt->execute([$pid]);
    $row = $priceStmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        ob_end_clean(); http_response_code(400);
        echo json_encode(["error" => "Product niet gevonden of niet beschikbaar"]);
        exit;
    }
    // Fan (basis) of Player-versie — bepaalt prijs én uit welke voorraad wordt afgeboekt.
    $version = (strtolower(trim((string)($item['version'] ?? 'fan'))) === 'player') ? 'player' : 'fan';
    $item['version'] = $version;
    $unit = (float)$row["price"];
    if ($version === 'player' && $row['player_price'] !== null && $row['player_price'] !== '') {
        $unit = (float)$row['player_price'];
    }
    $item["name"] = (string)($row["name"] ?? $item["name"] ?? '');
    $item["image"] = $row["image"] ?? null;
    $item["image2"] = $row["image2"] ?? null;
    $item["image3"] = $row["image3"] ?? null;

    // Validate print fields (server-side, not just frontend)
    if (isset($item['print_name'])   && mb_strlen((string)$item['print_name'])   > 15) $item['print_name']   = mb_substr($item['print_name'],   0, 15);
    if (isset($item['print_number']) && mb_strlen((string)$item['print_number']) > 4)  $item['print_number'] = mb_substr($item['print_number'],  0, 4);
    if (isset($item['print_badges']) && mb_strlen((string)$item['print_badges']) > 80) $item['print_badges'] = mb_substr($item['print_badges'],  0, 80);
    if (isset($item['print_name'])) {
        $item['print_name'] = trim((string)preg_replace('/[^\p{L}\p{N}\s.\'-]/u', '', (string)$item['print_name']));
    }
    if (isset($item['print_number'])) {
        $item['print_number'] = trim((string)preg_replace('/[^0-9]/', '', (string)$item['print_number']));
    }
    if (isset($item['print_badges'])) {
        $item['print_badges'] = trim((string)strip_tags((string)$item['print_badges']));
    }

    $opt = strtolower(trim((string)($item['printing_option'] ?? 'none')));
    if ($opt !== 'custom' && $opt !== 'none') {
        $opt = 'none';
    }
    $hasName  = trim((string)($item['print_name'] ?? '')) !== '';
    $hasNum   = trim((string)($item['print_number'] ?? '')) !== '';
    $hasBadge = trim((string)($item['print_badges'] ?? '')) !== '';
    if ($opt === 'custom') {
        if (!$hasName && !$hasNum && !$hasBadge) {
            ob_end_clean(); http_response_code(400);
            echo json_encode(["error" => "Kies minstens een naam, nummer of badge voor bedrukking"]);
            exit;
        }
        if ($hasName || $hasNum) {
            $unit += $printFee;
        }
        if ($hasBadge) {
            $unit += $badgeExtra;
        }
        if (!$hasName) {
            $item['print_name'] = null;
        }
        if (!$hasNum) {
            $item['print_number'] = null;
        }
        if (!$hasBadge) {
            $item['print_badges'] = null;
        }
    } else {
        $item['print_name'] = null;
        $item['print_number'] = null;
        $item['print_badges'] = null;
    }
    $item['printing_option'] = $opt;
    $item["price"] = $unit;
    $subtotal += $item["price"] * $qty;
}
unset($item);

// ===== COUPON VALIDATION (from database) =====
$couponCode    = strtoupper(trim($data['coupon_code'] ?? ''));
$discount      = 0.0;
$couponApplied = '';
if ($couponCode !== '') {
    $cpStmt = $pdo->prepare("SELECT type, value FROM coupons WHERE code=? AND active=1 LIMIT 1");
    $cpStmt->execute([$couponCode]);
    $cp = $cpStmt->fetch(PDO::FETCH_ASSOC);
    if ($cp) {
        if ($cp['type'] === 'percent') {
            // Clamp percentage to 0–100 so a misconfigured code can't exceed 100%.
            $pct = max(0.0, min(100.0, (float)$cp['value']));
            $discount = round($subtotal * $pct / 100, 2);
        } else {
            $discount = round((float)$cp['value'], 2);
        }
        // Hard cap: one coupon per order, and the discount can NEVER exceed the
        // order value or make it negative/free (guards stacking + bad config).
        $discount = max(0.0, min($discount, $subtotal));
        $couponApplied = $couponCode;
        // Increment usage count
        $pdo->prepare("UPDATE coupons SET uses_count = uses_count + 1 WHERE code=?")->execute([$couponCode]);
    }
}
$discountedSub = max(0, $subtotal - $discount);
$shipping      = $discountedSub >= $cfg['free_shipping_from'] ? 0.0 : (float)$cfg['shipping_cost'];
$total         = $discountedSub + $shipping;

// ===== ORDER ID =====
$orderId = "KD-" . random_int(100000, 999999);

try {
    $pdo->beginTransaction();

    // Check and deduct stock (FOR UPDATE locks rows — prevents overselling)
    $stockStmt       = $pdo->prepare("SELECT stock, stock_sizes, player_stock_sizes, name FROM products WHERE id=? FOR UPDATE");
    $stockUpdateStmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id=?");
    $sizeUpdateStmt  = $pdo->prepare("UPDATE products SET stock_sizes = ?, stock = stock - ? WHERE id=?");
    $playerSizeUpdateStmt = $pdo->prepare("UPDATE products SET player_stock_sizes = ? WHERE id=?");
    foreach ($data["items"] as $item) {
        $pid  = (int)($item["id"] ?? $item["product_id"] ?? 0);
        $qty  = (int)($item["qty"] ?? $item["quantity"] ?? 0);
        $size = strtoupper(trim((string)($item["size"] ?? '')));
        $version = (strtolower(trim((string)($item['version'] ?? 'fan'))) === 'player') ? 'player' : 'fan';
        $stockStmt->execute([$pid]);
        $prod = $stockStmt->fetch(PDO::FETCH_ASSOC);
        if (!$prod) throw new Exception("Product niet gevonden");

        // ── PLAYER-versie: aparte per-maat voorraad indien ingesteld; anders deelt
        //    Player de gewone voorraad (dan door naar het reguliere blok hieronder). ──
        $ps = ($version === 'player' && $prod['player_stock_sizes'])
            ? json_decode((string)$prod['player_stock_sizes'], true) : null;
        if ($version === 'player' && is_array($ps) && count($ps)) {
            if ($size === '') {
                throw new Exception('Player-versie niet op voorraad voor ' . $prod['name']);
            }
            if ($size === 'XXL' || $size === '2XL') {
                $nXxl = (int)($ps['XXL'] ?? 0);
                $n2   = (int)($ps['2XL'] ?? 0);
                if ($nXxl + $n2 < $qty) {
                    throw new Exception('Niet genoeg voorraad in maat XXL/2XL (Player) voor ' . $prod['name']);
                }
                $need = $qty;
                foreach (['XXL', '2XL'] as $k) {
                    if (!array_key_exists($k, $ps)) continue;
                    $avail = (int)$ps[$k];
                    if ($avail <= 0) continue;
                    $take = min($need, $avail);
                    $ps[$k] = $avail - $take;
                    $need -= $take;
                    if ($need <= 0) break;
                }
                if ($need > 0) {
                    throw new Exception('Niet genoeg voorraad in maat XXL/2XL (Player) voor ' . $prod['name']);
                }
            } elseif (isset($ps[$size])) {
                if ((int)$ps[$size] < $qty) {
                    throw new Exception("Niet genoeg voorraad in maat $size (Player) voor " . $prod['name']);
                }
                $ps[$size] = (int)$ps[$size] - $qty;
            } else {
                throw new Exception("Niet genoeg voorraad in maat $size (Player) voor " . $prod['name']);
            }
            $playerSizeUpdateStmt->execute([json_encode($ps), $pid]);
            continue;
        }

        $ss = $prod['stock_sizes'] ? json_decode((string)$prod['stock_sizes'], true) : null;
        if (!is_array($ss)) {
            $ss = null;
        }
        if ($ss && $size !== '') {
            // XXL en 2XL delen dezelfde maat — voorraad kan onder beide JSON-keys staan
            if ($size === 'XXL' || $size === '2XL') {
                $nXxl = (int)($ss['XXL'] ?? 0);
                $n2   = (int)($ss['2XL'] ?? 0);
                if ($nXxl + $n2 < $qty) {
                    throw new Exception('Niet genoeg voorraad in maat XXL/2XL voor ' . $prod['name']);
                }
                $need = $qty;
                foreach (['XXL', '2XL'] as $k) {
                    if (!array_key_exists($k, $ss)) {
                        continue;
                    }
                    $avail = (int)$ss[$k];
                    if ($avail <= 0) {
                        continue;
                    }
                    $take   = min($need, $avail);
                    $ss[$k] = $avail - $take;
                    $need  -= $take;
                    if ($need <= 0) {
                        break;
                    }
                }
                if ($need > 0) {
                    throw new Exception('Niet genoeg voorraad in maat XXL/2XL voor ' . $prod['name']);
                }
                $sizeUpdateStmt->execute([json_encode($ss), $qty, $pid]);
            } elseif (isset($ss[$size])) {
                if ((int)$ss[$size] < $qty) {
                    throw new Exception("Niet genoeg voorraad in maat $size voor " . $prod['name']);
                }
                $ss[$size] = (int)$ss[$size] - $qty;
                $sizeUpdateStmt->execute([json_encode($ss), $qty, $pid]);
            } else {
                if ((int)$prod['stock'] < $qty) {
                    throw new Exception('Niet genoeg voorraad voor ' . $prod['name']);
                }
                $stockUpdateStmt->execute([$qty, $pid]);
            }
        } else {
            if ((int)$prod['stock'] < $qty) {
                throw new Exception('Niet genoeg voorraad voor ' . $prod['name']);
            }
            $stockUpdateStmt->execute([$qty, $pid]);
        }
    }

    // Link to user account if logged in
    $userId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    // Insert order
    $stmt = $pdo->prepare("
        INSERT INTO orders
        (user_id, order_id, customer_name, email, phone, street, zip, city, country,
         notes, coupon_code, discount, subtotal, shipping, total)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId, $orderId,
        $data["name"], $data["email"], $data["phone"],
        $data["street"], $data["zip"], $data["city"], $data['country'],
        $data["notes"] ?? "",
        $couponApplied ?: null,
        $discount,
        $subtotal, $shipping, $total
    ]);
    $orderDbId = $pdo->lastInsertId();

    // Insert items
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items
        (order_id, product_id, name, size, version, quantity, price,
         printing_option, print_name, print_number, print_badges)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($data["items"] as $item) {
        $pid = (int)($item["id"] ?? $item["product_id"] ?? 0);
        $qty = (int)($item["qty"] ?? $item["quantity"] ?? 1);
        $itemStmt->execute([
            $orderDbId, $pid,
            $item["name"],
            $item["size"],
            (($item['version'] ?? 'fan') === 'player' ? 'player' : 'fan'),
            $qty,
            $item["price"],
            $item["printing_option"] ?? 'none',
            $item["print_name"]    ?? null,
            $item["print_number"]  ?? null,
            $item["print_badges"]  ?? null,
        ]);
    }

    $pdo->commit();

    $orderEmailSent = false;
    $orderEmailHint = null;
    $pk = trim((string)($cfg['emailjs_pk'] ?? ''));
    $svc = trim((string)($cfg['emailjs_service_order'] ?? ''));
    $tpl = trim((string)($cfg['emailjs_template_order'] ?? ''));
    $accessTok = trim((string)($cfg['emailjs_access_token'] ?? ''));
    if ($pk !== '' && $svc !== '' && $tpl !== '') {
        $tplParams = kits_emailjs_order_confirmation_params(
            $cfg,
            $orderId,
            $data,
            $data['items'],
            (float) $subtotal,
            (float) $discount,
            (float) $shipping,
            (float) $total,
            $couponApplied
        );
        $sendErr = kits_emailjs_send_rest($pk, $svc, $tpl, $tplParams, $accessTok);
        if ($sendErr === null) {
            $orderEmailSent = true;
        } else {
            kits_log('warning', 'order_confirmation_email_failed', ['order_id' => $orderId, 'error' => $sendErr]);
            if (stripos($sendErr, 'non-browser') !== false) {
                $orderEmailHint = 'emailjs_allow_nonbrowser';
            } elseif (stripos($sendErr, 'private') !== false && stripos($sendErr, 'key') !== false) {
                $orderEmailHint = 'emailjs_private_key';
            }
        }
    }

    $successPayload = json_encode([
        'success'            => true,
        'order_id'           => $orderId,
        'subtotal'           => (float) $subtotal,
        'discount'           => (float) $discount,
        'coupon_applied'     => $couponApplied,
        'shipping'           => (float) $shipping,
        'total'              => (float) $total,
        'order_email_sent'   => $orderEmailSent,
        'order_email_hint'   => $orderEmailHint,
    ], JSON_UNESCAPED_UNICODE);
    $_SESSION[$idemSlot] = ['ts' => time(), 'body' => $successPayload];

    ob_end_clean();
    echo $successPayload;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    kits_log('error', 'place_order_failed', ['error' => $e->getMessage()]);
    ob_end_clean(); http_response_code(400);
    // Surface stock/validation errors clearly; hide internal DB errors
    $msg = $e->getMessage();
    $userMsg = (str_contains($msg, 'voorraad') || str_contains($msg, 'Product niet'))
        ? $msg
        : 'Bestelling niet voltooid. Probeer opnieuw of neem contact op via WhatsApp.';
    echo json_encode(['error' => $userMsg]);
}
