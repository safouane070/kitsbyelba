<?php
declare(strict_types=1);

/**
 * EmailJS REST send (https://www.emailjs.com/docs/rest-api/send/).
 * Uses public key as user_id — no browser SDK; works when .env is correct on the server.
 *
 * @return null|string null = success, string = error message
 */
function kits_emailjs_send_rest(
    string $publicKey,
    string $serviceId,
    string $templateId,
    array $templateParams,
    string $accessToken = ''
): ?string {
    $publicKey = trim($publicKey);
    $serviceId = trim($serviceId);
    $templateId = trim($templateId);
    $accessToken = trim($accessToken);
    if ($publicKey === '' || $serviceId === '' || $templateId === '') {
        return 'EmailJS: ontbrekende user_id, service_id of template_id.';
    }

    $payload = [
        'service_id'      => $serviceId,
        'template_id'     => $templateId,
        'user_id'         => $publicKey,
        'template_params' => $templateParams,
    ];
    if ($accessToken !== '') {
        $payload['accessToken'] = $accessToken;
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return 'EmailJS: JSON encode mislukt.';
    }

    if (function_exists('curl_init')) {
        $ch = curl_init('https://api.emailjs.com/api/v1.0/email/send');
        if ($ch === false) {
            return 'EmailJS: cURL init mislukt.';
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($json),
                'User-Agent: KitsByElbaa-PHP/1',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err !== '') {
            return 'EmailJS transport: ' . $err;
        }
        if ($code >= 200 && $code < 300) {
            return null;
        }
        $msg = is_string($body) ? trim($body) : '';

        return 'EmailJS HTTP ' . $code . ($msg !== '' ? (': ' . $msg) : '');
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\n",
            'content' => $json,
            'timeout' => 25,
        ],
    ]);
    $body = @file_get_contents('https://api.emailjs.com/api/v1.0/email/send', false, $ctx);
    if ($body === false) {
        return 'EmailJS: file_get_contents mislukt (controleer allow_url_fopen / firewall).';
    }
    /** @var array<int, string>|null $hdr */
    $hdr = $http_response_header ?? null;
    $code = 0;
    if (is_array($hdr) && isset($hdr[0]) && preg_match('#\b(\d{3})\b#', (string)$hdr[0], $m)) {
        $code = (int)$m[1];
    }
    if ($code >= 200 && $code < 300) {
        return null;
    }

    return 'EmailJS HTTP ' . $code . ($body !== '' ? (': ' . trim($body)) : '');
}

function kits_product_image_web_path(string $file): string
{
    $file = trim(str_replace('\\', '/', $file));
    while (stripos($file, 'uploads/products/') === 0) {
        $file = substr($file, strlen('uploads/products/'));
    }
    $file = ltrim($file, '/');
    if ($file === '' || preg_match('#^https?://#i', $file)) {
        return $file;
    }
    $prefix = 'uploads/products/';
    if (str_contains($file, '/')) {
        return $prefix . implode('/', array_map('rawurlencode', explode('/', $file)));
    }

    return $prefix . rawurlencode($file);
}

function kits_absolute_site_url(array $cfg, string $relativePath): string
{
    $base = rtrim((string)($cfg['public_site_url'] ?? ''), '/');
    $rel  = ltrim($relativePath, '/');
    if ($base === '') {
        return '/' . $rel;
    }

    return $base . '/' . $rel;
}

/**
 * Build template params for order confirmation (same fields as shop/index EmailJS payload).
 *
 * @param list<array<string, mixed>> $items Order lines after DB validation (name, price, qty, size, printing_* …)
 */
function kits_emailjs_order_confirmation_params(
    array $cfg,
    string $orderId,
    array $data,
    array $items,
    float $subtotal,
    float $discount,
    float $shipping,
    float $total,
    string $couponApplied
): array {
    $name   = trim((string)($data['name'] ?? ''));
    $email  = trim((string)($data['email'] ?? ''));
    $phone  = trim((string)($data['phone'] ?? ''));
    $street = trim((string)($data['street'] ?? ''));
    $zip    = trim((string)($data['zip'] ?? ''));
    $city   = trim((string)($data['city'] ?? ''));
    $notes  = trim((string)($data['notes'] ?? ''));

    $verShip = $shipping;
    $verTotal = $total;
    $verDiscount = $discount;

    $itemsText = '';
    $orders = [];
    foreach ($items as $i) {
        $qty = (int)($i['qty'] ?? $i['quantity'] ?? 1);
        $nm = (string)($i['name'] ?? '');
        $sz = (string)($i['size'] ?? '');
        $pr = (float)($i['price'] ?? 0);
        $opt = strtolower(trim((string)($i['printing_option'] ?? 'none')));
        $extra = '';
        if ($opt === 'custom') {
            $pn = trim((string)($i['print_name'] ?? ''));
            $num = trim((string)($i['print_number'] ?? ''));
            $bad = trim((string)($i['print_badges'] ?? ''));
            $extra = ' · ' . $pn . ($num !== '' ? ' #' . $num : '') . ($bad !== '' ? ' (' . $bad . ')' : '');
        }
        $ver = (strtolower(trim((string)($i['version'] ?? 'fan'))) === 'player') ? 'Player' : 'Fan';
        $line = $qty . 'x ' . $nm . ' (' . $ver . ' · Maat: ' . $sz . $extra . ') — €' . number_format($pr * $qty, 2, '.', '');
        $itemsText .= ($itemsText === '' ? '' : "\n") . $line;

        $imgFile = (string)($i['image'] ?? '');
        if ($imgFile === '') {
            $imgFile = (string)($i['image2'] ?? '');
        }
        if ($imgFile === '') {
            $imgFile = (string)($i['image3'] ?? '');
        }
        $imgPath = $imgFile !== '' ? kits_product_image_web_path($imgFile) : '';
        $imageUrl = ($imgPath !== '' && !preg_match('#^https?://#i', $imgPath))
            ? kits_absolute_site_url($cfg, $imgPath)
            : $imgPath;

        $orders[] = [
            'name'      => $nm . ' (' . $ver . ' · ' . $sz . $extra . ')',
            'units'     => $qty,
            'price'     => number_format($pr * $qty, 2, '.', ''),
            'image_url' => $imageUrl,
            'league'    => (string)($i['league'] ?? ''),
            'size'      => $sz,
            'version'   => $ver,
        ];
    }

    $base = rtrim((string)($cfg['public_site_url'] ?? ''), '/');
    $shipStr = $verShip === 0.0 ? 'GRATIS' : ('€' . number_format($verShip, 2, '.', ''));
    $totalStr = '€' . number_format($verTotal, 2, '.', '');
    $discStr = $verDiscount > 0 ? ('€' . number_format($verDiscount, 2, '.', '')) : '';

    $params = [
        'new_order'       => 1,
        'order_id'        => $orderId,
        'email'           => $email,
        'customer_name'   => $name,
        'customer_email'  => $email,
        'order_items'     => $itemsText,
        'shipping_cost'   => $shipStr,
        'order_total'     => $totalStr,
        'order_discount'  => $discStr,
        'coupon_code'     => $couponApplied,
        'customer_address'=> $street . ', ' . $zip . ' ' . $city,
        'customer_phone'  => $phone,
        'customer_notes'  => $notes,
        'orders'          => $orders,
        'cost'            => [
            'shipping' => $shipStr,
            'total'    => $totalStr,
        ],
        'order_date'      => date('d M Y'),
        'site_url'        => $base,
        'logo_url'        => $base !== '' ? ($base . '/images/logo.png') : '',
        'to_email'        => $email,
        'to_name'         => $name,
    ];

    $bcc = trim((string)($cfg['notify_bcc_email'] ?? 'KitsByElbaa@outlook.com'));
    if ($bcc !== '' && strcasecmp($email, $bcc) !== 0) {
        $params['bcc'] = $bcc;
    }

    return $params;
}

function kits_store_product_slug(int $id, string $name): string
{
    $tail = strtolower($name);
    $tail = preg_replace('/[^a-z0-9]+/', '-', $tail) ?? '';
    $tail = trim((string)$tail, '-') ?: 'kit';

    return $id . '-' . $tail;
}

/**
 * Send restock / back-in-stock emails from PHP (same template params as admin.js sendRestockNotifications).
 * Verwijdert alleen DB-regels voor ontvangers waarvoor de send geslaagd is (geen bulk-delete per maat).
 *
 * @return array{attempted:bool, sent:int, failed:int}
 */
function kits_emailjs_try_restock(PDO $pdo, array $cfg, int $productId, array $restockedSizes): array
{
    $pk = trim((string)($cfg['emailjs_pk'] ?? ''));
    $svc = trim((string)($cfg['emailjs_service_restock'] ?? ''));
    $tpl = trim((string)($cfg['emailjs_template_restock'] ?? ''));
    if ($pk === '' || $svc === '' || $tpl === '' || $restockedSizes === []) {
        return ['attempted' => false, 'sent' => 0, 'failed' => 0];
    }

    $sizes = array_values(array_filter(array_map(static function ($s): string {
        return trim((string)$s);
    }, $restockedSizes), static fn (string $s): bool => $s !== ''));
    if ($sizes === []) {
        return ['attempted' => false, 'sent' => 0, 'failed' => 0];
    }

    $ph = implode(',', array_fill(0, count($sizes), '?'));
    $stmt = $pdo->prepare(
        "SELECT email, size FROM stock_notifications WHERE product_id=? AND size IN ($ph)"
    );
    $stmt->execute(array_merge([$productId], $sizes));
    $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$notifications) {
        return ['attempted' => true, 'sent' => 0, 'failed' => 0];
    }

    $pstmt = $pdo->prepare('SELECT id, name, image, image2, image3 FROM products WHERE id=? LIMIT 1');
    $pstmt->execute([$productId]);
    $p = $pstmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $productName = (string)($p['name'] ?? ('Product #' . $productId));
    $slug = rawurlencode(kits_store_product_slug($productId, $productName));
    $base = rtrim((string)($cfg['public_site_url'] ?? ''), '/');
    $productUrl = $base !== '' ? ($base . '/product.php?slug=' . $slug) : ('/product.php?slug=' . $slug);

    $imgFile = (string)($p['image'] ?? '');
    if ($imgFile === '') {
        $imgFile = (string)($p['image2'] ?? '');
    }
    if ($imgFile === '') {
        $imgFile = (string)($p['image3'] ?? '');
    }
    $imgPath = $imgFile !== '' ? kits_product_image_web_path($imgFile) : '';
    $productImage = ($imgPath !== '' && !preg_match('#^https?://#i', $imgPath))
        ? kits_absolute_site_url($cfg, $imgPath)
        : $imgPath;

    $bccDefault = trim((string)($cfg['notify_bcc_email'] ?? 'KitsByElbaa@outlook.com'));

    $sent = 0;
    $failed = 0;
    $delOne = $pdo->prepare('DELETE FROM stock_notifications WHERE product_id = ? AND email = ? AND size = ?');

    foreach ($notifications as $n) {
        $to = trim((string)($n['email'] ?? ''));
        $sz = trim((string)($n['size'] ?? ''));
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $failed++;

            continue;
        }

        $params = [
            'to_email'      => $to,
            'to_name'       => strstr($to, '@', true) ?: 'klant',
            'product_name'  => $productName,
            'size'          => $sz,
            'product_url'   => $productUrl,
            'product_image' => $productImage,
            'site_url'      => $base,
            'logo_url'      => $base !== '' ? ($base . '/images/logo.jpeg') : '',
        ];
        if ($bccDefault !== '' && strcasecmp($to, $bccDefault) !== 0) {
            $params['bcc'] = $bccDefault;
        }

        $at = trim((string)($cfg['emailjs_access_token'] ?? ''));
        $err = kits_emailjs_send_rest($pk, $svc, $tpl, $params, $at);
        if ($err === null) {
            $sent++;
            $delOne->execute([$productId, strtolower($to), $sz]);
        } else {
            $failed++;
            if (function_exists('kits_log')) {
                kits_log('warning', 'emailjs_restock_failed', ['email' => $to, 'error' => $err]);
            }
        }
    }

    return ['attempted' => true, 'sent' => $sent, 'failed' => $failed];
}
