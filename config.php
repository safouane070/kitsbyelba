<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap_env.php';
kits_load_dotenv(__DIR__);

/**
 * Read environment variables (set in the server, or in a local .env file).
 * Prefix every variable with KITS_, e.g. KITS_DB_HOST, KITS_ADMIN_PASSWORD_HASH.
 */
function kits_env(string $key, ?string $default = null): ?string
{
    $name = 'KITS_' . $key;
    $v = getenv($name);
    if ($v !== false && $v !== '') {
        return $v;
    }
    if (isset($_ENV[$name]) && (string)$_ENV[$name] !== '') {
        return (string)$_ENV[$name];
    }
    return $default;
}

$trustedFromEnv = array_values(array_filter(array_map('trim', explode(',', (string)kits_env('TRUSTED_ORIGINS', '')))));
$publicSiteUrl = rtrim((string)kits_env('PUBLIC_SITE_URL', 'http://localhost/kitsbyelba'), '/');
$isLocalPublicUrl = (static function (string $url): bool {
    $p = parse_url($url ?: 'http://localhost');
    $h = strtolower((string)($p['host'] ?? ''));
    return $h === '' || $h === 'localhost' || $h === '127.0.0.1' || str_ends_with($h, '.localhost');
})($publicSiteUrl);
$appEnv = strtolower((string)kits_env('ENV', $isLocalPublicUrl ? 'local' : 'production'));

return [

    // Database — prefer KITS_DB_* in production (never commit real passwords).
    'db_host' => kits_env('DB_HOST', '127.0.0.1'),
    'db_name' => kits_env('DB_NAME', 'kitsbyelba'),
    'db_user' => kits_env('DB_USER', 'root'),
    'db_pass' => kits_env('DB_PASS', ''),

    // Public shop URL without trailing slash (KITS_PUBLIC_SITE_URL)
    'public_site_url' => $publicSiteUrl,

    // Optional: extra CORS origins, comma-separated in KITS_TRUSTED_ORIGINS
    'trusted_origins' => $trustedFromEnv,

    // Temporary: KITS_DEBUG_DB=1 → api/healthz.php includes db_error message (remove after fixing).
    'debug_db' => kits_env('DEBUG_DB', '') === '1',

    // Optional admin lock: comma-separated IPs (e.g. 203.0.113.10,192.168.1.*). Empty = allow all.
    'admin_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string)kits_env('ADMIN_ALLOWED_IPS', ''))))),
    // Set KITS_TRUST_PROXY_FOR_IP=1 only behind a reverse proxy that sets X-Forwarded-For correctly.
    'trust_proxy_for_ip' => kits_env('TRUST_PROXY_FOR_IP', '') === '1',

    // Store
    'whatsapp' => (string)kits_env('WHATSAPP', '31684446255'),
    'season' => (string)kits_env('SEASON', '25/26'),
    'free_shipping_from' => (float)kits_env('FREE_SHIPPING_FROM', '40'),
    'shipping_cost' => (float)kits_env('SHIPPING_COST', '4.99'),

    'custom_printing_price' => (float)kits_env('CUSTOM_PRINTING_PRICE', '5'),
    /** Meerprijs per shirt bij gekozen patch/badge (dropdown of tekst), naast bedrukking. */
    'badge_extra_price' => (float)kits_env('BADGE_EXTRA_PRICE', '3'),

    // EmailJS (public keys — still restrict CORS; rotate in dashboard if leaked). Trim: .env line endings/spaces break v4.
    'emailjs_pk' => trim((string)kits_env('EMAILJS_PK', '')),
    'emailjs_service_order' => trim((string)kits_env('EMAILJS_SERVICE_ORDER', (string)kits_env('EMAILJS_SERVICE', 'service_ekpmrrp'))),
    'emailjs_service_restock' => trim((string)kits_env(
        'EMAILJS_SERVICE_RESTOCK',
        (string)kits_env('EMAILJS_SERVICE_ORDER', (string)kits_env('EMAILJS_SERVICE', 'service_4ie7p0c'))
    )),
    // Beide kunnen hetzelfde EmailJS-template-ID zijn: zie emailjs-templates/transactional-email.html
    'emailjs_template_order' => trim((string)kits_env('EMAILJS_TEMPLATE_ORDER', 'template_bb9p79e')),
    'emailjs_template_paid' => trim((string)kits_env('EMAILJS_TEMPLATE_PAID', 'template_bb9p79e')),
    'emailjs_template_restock' => trim((string)kits_env('EMAILJS_TEMPLATE_RESTOCK', 'template_zna2rkn')),
    // Optioneel: Account → Security → “Private key”; alleen nodig als die modus aan staat (REST accessToken).
    'emailjs_access_token' => trim((string)kits_env('EMAILJS_ACCESS_TOKEN', '')),

    // Import/sync scripts (?secret=). Always set explicitly via KITS_IMPORT_SECRET.
    'import_secret' => (string)kits_env('IMPORT_SECRET', ''),

    // Admin — bcrypt only. Always set explicitly via KITS_ADMIN_PASSWORD_HASH.
    'admin_email' => (string)kits_env('ADMIN_EMAIL', 'KitsByElbaa@outlook.com'),
    // BCC voor bestel-/nabestel-mails (EmailJS). Standaard zakelijke inbox — niet afhankelijk van persoonlijke KITS_ADMIN_EMAIL.
    'notify_bcc_email' => (static function (): string {
        $n = trim((string)kits_env('NOTIFY_BCC', ''));
        if ($n !== '' && filter_var($n, FILTER_VALIDATE_EMAIL)) {
            return $n;
        }
        return 'KitsByElbaa@outlook.com';
    })(),
    'admin_password_hash' => (string)kits_env('ADMIN_PASSWORD_HASH', ''),

];
