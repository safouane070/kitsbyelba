<?php
header('Content-Type: application/json');

$cfg = require __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/cors.php';
kits_emit_cors_headers($cfg, false);

$promoDefaults = [
    'promoBanner'     => '10% KORTING — code <strong>KITSBYELBA</strong> · <strong>KitsByElbaa</strong>',
    'promoPopupTitle' => '10% KORTING',
    'promoPopupSub'   => 'Gebruik deze code bij het afrekenen:',
    'promoPopupCode'  => 'kitsbyelba',
    'promoFaqAnswer'  => 'Ja! Gebruik code <strong>KITSBYELBA</strong> bij het afrekenen voor <strong>10% korting</strong> op je bestelling.',
];
try {
    require_once __DIR__ . '/../includes/site_settings.php';
    $pdo = new PDO(
        'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4',
        $cfg['db_user'],
        $cfg['db_pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $s = kits_load_site_settings($pdo);
    $promoDefaults = [
        'promoBanner'     => $s['promo_banner'],
        'promoPopupTitle' => $s['promo_popup_title'],
        'promoPopupSub'   => $s['promo_popup_sub'],
        'promoPopupCode'  => $s['promo_popup_code'],
        'promoFaqAnswer'  => $s['promo_faq_answer'],
    ];
} catch (Throwable $e) {
    // DB unavailable — fall back to built-in defaults above
}

// Only expose what the frontend needs (never expose db credentials or admin email)
echo json_encode(array_merge([
    'whatsapp'        => $cfg['whatsapp'],
    'season'          => $cfg['season'],
    'freeShippingFrom'=> (float)$cfg['free_shipping_from'],
    'shippingCost'    => (float)$cfg['shipping_cost'],
    'customPrintingPrice' => (float)$cfg['custom_printing_price'],
    'badgeExtraPrice'     => (float)($cfg['badge_extra_price'] ?? 3),
    'publicSiteUrl'   => rtrim((string)($cfg['public_site_url'] ?? ''), '/'),
    'emailjsPk'       => $cfg['emailjs_pk'],
    'emailjsService'  => $cfg['emailjs_service_order'],
    'emailjsTemplate' => $cfg['emailjs_template_order'],
], $promoDefaults));
