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
require_once __DIR__ . '/includes/order_stock.php';
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

    require __DIR__ . '/includes/admin_api.php';
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
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="images/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="images/apple-touch-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>KitsByElbaa — Beheer</title>
<script defer src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4.4.1/dist/email.min.js" integrity="sha384-SALc35EccAf6RzGw4iNsyj7kTPr33K7RoGzYu+7heZhT8s0GZouafRiCg1qy44AS" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<link rel="stylesheet" href="<?= kits_asset('css/admin.css') ?>">
<link rel="stylesheet" href="<?= kits_asset('css/responsive-global.css') ?>">
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
      <a class="sn" href="admin-social.php" style="text-decoration:none">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 16-5-5L5 20"/></svg>
        Social media
      </a>
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
      <div class="page-sub">Teksten op de winkel (banner, FAQ). <strong>Kortingscodes</strong> (random codes, percentage, aan/uit) beheer je onder <strong>Kortingscodes</strong>.</div>
      <div class="promo-settings-box" style="background:var(--white);border:1px solid var(--line);border-radius:12px;padding:24px 28px;max-width:720px">
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:var(--ink3);margin-bottom:8px">Bannertekst (boven navigatie)</label>
          <textarea class="login-input" id="sf-banner" rows="3" style="resize:vertical;min-height:72px;font-family:inherit;line-height:1.5" placeholder="HTML: &lt;strong&gt; toegestaan"></textarea>
          <div style="font-size:11px;color:var(--ink3);margin-top:6px">Toegestaan: vet/cursief, &lt;br&gt;. Gebruik bijv. <code style="font-size:10px">&lt;strong&gt;KITSBYELBA&lt;/strong&gt;</code> en <code style="font-size:10px">&lt;strong&gt;KitsByElbaa&lt;/strong&gt;</code>.</div>
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
<script defer src="<?= kits_asset('js/product-classify.js') ?>"></script><script defer src="<?= kits_asset('js/admin.js') ?>"></script>

<?php endif; ?>
</body>
</html>












