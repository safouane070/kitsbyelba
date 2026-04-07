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
$appEnv = strtolower((string)kits_env('ENV', 'production'));
$isLocalPublicUrl = (static function (string $url): bool {
    $p = parse_url($url ?: 'http://localhost');
    $h = strtolower((string)($p['host'] ?? ''));
    return $h === '' || $h === 'localhost' || $h === '127.0.0.1' || str_ends_with($h, '.localhost');
})($publicSiteUrl);

// Known dev-only defaults — ONLY used when KITS_ENV=local|development.
$devImportSecretDefault = '3b00e6893da7a53a68643c0b3777377b6c92f5599e1eb98b';
$devAdminHashDefault = '$2y$12$kp4aKEQ5LjroU7sToyXYj.DhyFc/s0UZIN6CDnrGCTdocMKjOCIGm';
$allowDevDefaults = in_array($appEnv, ['local', 'development'], true) && $isLocalPublicUrl;

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

    // Optional admin lock: comma-separated IPs (e.g. 203.0.113.10,192.168.1.*). Empty = allow all.
    'admin_allowed_ips' => array_values(array_filter(array_map('trim', explode(',', (string)kits_env('ADMIN_ALLOWED_IPS', ''))))),
    // Set KITS_TRUST_PROXY_FOR_IP=1 only behind a reverse proxy that sets X-Forwarded-For correctly.
    'trust_proxy_for_ip' => kits_env('TRUST_PROXY_FOR_IP', '') === '1',

    // Store
    'whatsapp' => (string)kits_env('WHATSAPP', '31643554052'),
    'season' => (string)kits_env('SEASON', '25/26'),
    'free_shipping_from' => (float)kits_env('FREE_SHIPPING_FROM', '40'),
    'shipping_cost' => (float)kits_env('SHIPPING_COST', '4.99'),

    'custom_printing_price' => (float)kits_env('CUSTOM_PRINTING_PRICE', '5'),

    // EmailJS (public keys — still restrict CORS; rotate in dashboard if leaked)
    'emailjs_pk' => (string)kits_env('EMAILJS_PK', '3dyna3NuBl7--nUZs'),
    'emailjs_service_order' => (string)kits_env('EMAILJS_SERVICE_ORDER', (string)kits_env('EMAILJS_SERVICE', 'service_ekpmrrp')),
    'emailjs_service_restock' => (string)kits_env(
        'EMAILJS_SERVICE_RESTOCK',
        (string)kits_env('EMAILJS_SERVICE_ORDER', (string)kits_env('EMAILJS_SERVICE', 'service_4ie7p0c'))
    ),
    // Beide kunnen hetzelfde EmailJS-template-ID zijn: zie emailjs-templates/transactional-email.html
    'emailjs_template_order' => (string)kits_env('EMAILJS_TEMPLATE_ORDER', 'template_bb9p79e'),
    'emailjs_template_paid' => (string)kits_env('EMAILJS_TEMPLATE_PAID', 'template_bb9p79e'),
    'emailjs_template_restock' => (string)kits_env('EMAILJS_TEMPLATE_RESTOCK', 'template_zna2rkn'),

    // Import/sync scripts (?secret=). Production: set KITS_IMPORT_SECRET. Local-only fallback when host is localhost.
    'import_secret' => (string)(kits_env('IMPORT_SECRET', '') ?: ($allowDevDefaults ? $devImportSecretDefault : '')),

    // Admin — bcrypt only. Production: set KITS_ADMIN_PASSWORD_HASH. Local-only fallback when host is localhost.
    'admin_email' => (string)kits_env('ADMIN_EMAIL', 'slahoua8@gmail.com'),
    'admin_password_hash' => (string)(kits_env('ADMIN_PASSWORD_HASH', '') ?: ($allowDevDefaults ? $devAdminHashDefault : '')),

];
