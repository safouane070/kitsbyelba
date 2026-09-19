<?php
// ── KitsByElbaa Account Page ──────────────────────────────────
require_once __DIR__ . '/includes/session.php';
kits_session_start('Lax', 60 * 120);

if (isset($_GET['logout'])) {
    require_once __DIR__ . '/includes/session.php';
    kits_destroy_session();
    header('Location: account.php');
    exit;
}

// Security headers
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_settings.php';

$user   = null;
$orders = [];
$pdo    = null;
$promoBannerHtml = kits_site_settings_defaults()['promo_banner'];

try {
    $pdo = kits_pdo($cfg);
    $promoBannerHtml = kits_load_site_settings($pdo)['promo_banner'];
} catch (PDOException $e) { /* handled below */ }

if (!empty($_SESSION['user_id']) && $pdo) {
    $stmt = $pdo->prepare(
        "SELECT id, name, email, phone, street, zip, city, created_at FROM users WHERE id=? LIMIT 1"
    );
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    if ($user) {
        $stmt2 = $pdo->prepare("
            SELECT o.id, o.order_id, o.status, o.subtotal, o.shipping, o.total, o.created_at,
                   GROUP_CONCAT(oi.name ORDER BY oi.id SEPARATOR '<br>') AS items_list,
                   SUM(oi.quantity) AS total_qty
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE o.user_id = ?
            GROUP BY o.id
            ORDER BY o.created_at DESC
            LIMIT 50
        ");
        $stmt2->execute([$user['id']]);
        $orders = $stmt2->fetchAll();
    }
}

$esc = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$nlMonths = ['', 'januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'];
$orderStatusNl = [
    'pending' => 'In afwachting',
    'confirmed' => 'Bevestigd',
    'paid' => 'Betaald',
    'shipped' => 'Verzonden',
    'delivered' => 'Afgeleverd',
    'cancelled' => 'Geannuleerd',
];
$statusLabel = static function (string $s) use ($orderStatusNl): string {
    return $orderStatusNl[$s] ?? $s;
};
$memberSinceNl = static function (string $mysqlDate) use ($nlMonths): string {
    $t = strtotime($mysqlDate);
    return $nlMonths[(int)date('n', $t)] . ' ' . date('Y', $t);
};
$displayNameNice = static function (?string $name): string {
    $s = trim((string)$name);
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_convert_case')) {
        return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
    }
    return ucwords(strtolower($s));
};
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f9faf7">
<title>Mijn account — KitsByElbaa</title>
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
html{height:100%}
body{background:var(--cream);color:var(--ink);font-family:var(--font-body);font-weight:400;overflow-x:hidden;min-height:100vh;min-height:100dvh;display:flex;flex-direction:column}
a{text-decoration:none;color:inherit}
.page{flex:1}
/* Site header = zelfde patroon als index.html (desktop + hamburger ≤960px in responsive-global.css) */
.site-nav{background:var(--cream);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:var(--z-nav);backdrop-filter:blur(16px)}
.promo-banner{background:var(--accent-light);border-bottom:1px solid rgba(45,90,39,.15);text-align:center;padding:9px 16px;font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);font-weight:700}
.nav-top{max-width:1360px;margin:0 auto;padding:0 48px;height:74px;display:flex;align-items:center;justify-content:space-between;position:relative}
.logo{font-family:var(--font-display);font-size:26px;font-weight:700;letter-spacing:.02em;color:var(--ink);text-decoration:none;display:flex;align-items:center;gap:10px}
.logo-img{height:64px;width:auto;display:block;object-fit:contain}
.logo-text{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
.nav-links{display:flex;list-style:none;gap:36px;margin:0;padding:0}
.nav-links a{font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;color:var(--ink3);text-decoration:none;transition:color .2s;position:relative;padding-bottom:2px}
.nav-links a::after{content:'';position:absolute;bottom:0;left:0;right:0;height:1px;background:var(--ink);transform:scaleX(0);transform-origin:left;transition:transform .22s}
.nav-links a:hover{color:var(--ink)}
.nav-links a:hover::after,.nav-links a.active::after{transform:scaleX(1)}
.nav-links a.active{color:var(--ink)}
.nav-links>li{position:relative}
.nav-links .has-mega>.top-link{display:inline-flex;align-items:center;gap:6px}
.nav-links .has-mega>.top-link::before{content:''}
.nav-links .has-mega>.top-link::after{content:'▾';font-size:10px;line-height:1;opacity:.65;position:static;background:none;transform:none;height:auto;transition:transform .2s ease}
.nav-mega{position:absolute;top:100%;left:50%;transform:translate(-50%,6px);min-width:240px;background:#fff;border:1px solid #e2dbcf;box-shadow:0 20px 40px rgba(28,26,23,.14);padding:8px;border-radius:12px;z-index:var(--z-nav-mega);opacity:0;visibility:hidden;pointer-events:none;transition:opacity .18s ease,transform .2s ease,visibility .18s}
.nav-mega a{display:flex;align-items:center;gap:10px;padding:8px 12px;font-size:11px;letter-spacing:.09em;text-transform:uppercase;color:var(--ink3);border-bottom:1px solid #eee7da;white-space:nowrap;border-radius:8px}
.nav-mega a:last-child{border-bottom:none}
.nav-mega a::after{display:none}
.nav-mega a:hover{background:var(--cream2);color:var(--ink)}
.nav-mega a::before{content:'';width:26px;height:26px;flex-shrink:0;background-color:#fff;border:1px solid var(--line);border-radius:6px;background-repeat:no-repeat;background-position:center;background-size:18px 18px}
.nav-mega a[href*="league=premier"]::before{background-image:url("images/leagues/premier.png")}
.nav-mega a[href*="league=laliga"]::before{background-image:url("images/leagues/laliga.png")}
.nav-mega a[href*="league=bundesliga"]::before{background-image:url("images/leagues/bundesliga.png")}
.nav-mega a[href*="league=seriea"]::before{background-image:url("images/leagues/seriea.png")}
.nav-mega a[href*="league=ligue1"]::before{background-image:url("images/leagues/ligue1.png")}
.nav-mega a[href*="league=eredivisie"]::before{background-image:url("images/leagues/eredivisie.png")}
.nav-mega a[href*="league=national"]::before{background-image:url("images/leagues/national.svg")}
.nav-mega a[href*="league=overig"]::before{background-image:url("images/leagues/overig.svg")}
.nav-links .has-mega:hover .nav-mega,.nav-links .has-mega:focus-within .nav-mega{opacity:1;visibility:visible;pointer-events:auto;transform:translate(-50%,0)}
.nav-links .has-mega:hover>.top-link::after,.nav-links .has-mega:focus-within>.top-link::after{transform:rotate(180deg)}
@media(max-width:960px){.nav-mega{display:none !important}.nav-links .has-mega>.top-link::after{display:none}}
.nav-right{display:flex;align-items:center;gap:14px;flex-shrink:0}
.nav-cart{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--ink);color:var(--cream);border:none;padding:11px 22px;font-family:var(--font-body);font-size:12px;font-weight:600;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;transition:all .2s;white-space:nowrap;flex-shrink:0}
.nav-cart-ico{font-size:1.05rem;line-height:1}
.nav-cart-label{display:inline}
.nav-cart:hover{background:var(--ink2)}
/* Page */
.page{max-width:1100px;margin:0 auto;padding:40px 24px 80px}
.page-title{font-family:var(--font-display);font-size:36px;font-weight:700;margin-bottom:8px}
.page-sub{color:var(--ink3);font-size:14px;margin-bottom:40px;line-height:1.5}
.page-sub .account-user-name{text-transform:none;letter-spacing:.02em;font-weight:600;color:var(--ink2)}
/* Auth panel (not logged in) */
.auth-wrap{max-width:440px;margin:0 auto}
.auth-tabs{display:flex;border-bottom:2px solid var(--line);margin-bottom:32px}
.atab{flex:1;text-align:center;padding:14px;font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;transition:all .18s}
.atab.on{color:var(--ink);border-color:var(--ink)}
.apanel{display:none}.apanel.on{display:block}
.form-group{margin-bottom:18px}
.form-group label{display:block;font-size:11px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--ink3);margin-bottom:7px}
.form-group input{width:100%;padding:12px 14px;border:1px solid var(--line);background:#fff;font-family:var(--font-body);font-size:14px;color:var(--ink);outline:none;transition:border-color .18s}
.form-group input:focus{border-color:var(--ink)}
.form-hint{font-size:12px;color:var(--ink4);margin-top:5px}
.btn-primary{width:100%;padding:14px;background:var(--ink);color:var(--cream);border:none;font-family:var(--font-body);font-size:12px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;cursor:pointer;transition:background .18s;margin-top:8px}
.btn-primary:hover{background:var(--ink2)}
.btn-primary:disabled{opacity:.5;cursor:not-allowed}
.form-err{background:#fef2f2;border:1px solid #fca5a5;color:#991b1b;padding:10px 14px;font-size:13px;margin-bottom:16px;display:none}
.form-ok{background:var(--accent-light);border:1px solid rgba(45,90,39,.3);color:var(--accent);padding:10px 14px;font-size:13px;margin-bottom:16px;display:none}
/* Dashboard */
.dash-grid{display:grid;grid-template-columns:220px 1fr;gap:32px;align-items:start;min-width:0}
.dash-nav{position:relative;z-index:1;background:#fff;border:1px solid var(--line);padding:8px 0}
.dash-nav a{display:flex;align-items:center;gap:10px;padding:13px 20px;font-size:13px;font-weight:500;color:var(--ink3);cursor:pointer;transition:all .18s;border-left:3px solid transparent}
.dash-nav a:hover{color:var(--ink);background:var(--cream2)}
.dash-nav a.on{color:var(--ink);background:var(--cream2);border-left-color:var(--ink);font-weight:600}
/* Geen emoji: past bij rest van site (Manrope + Space Grotesk) */
.dash-nav .nav-icon{display:none}
.dash-nav .danger-link{color:var(--red)}
.dash-nav .danger-link:hover{color:var(--red);background:#fff5f5}
.dpanel{display:none;min-width:0}.dpanel.on{display:block}
.dpanel-title{font-family:var(--font-display);font-size:24px;font-weight:700;margin-bottom:6px}
.dpanel-sub{color:var(--ink3);font-size:13px;margin-bottom:28px}
/* Stats row */
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-bottom:32px}
.stat-card{background:#fff;border:1px solid var(--line);padding:20px 24px;box-shadow:var(--shadow-sm)}
.stat-val{font-family:var(--font-display);font-size:28px;font-weight:700;margin-bottom:4px}
.stat-lbl{font-size:11px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--ink4)}
/* Orders table */
.orders-table{width:100%;border-collapse:collapse;font-size:13px}
.orders-table th{font-size:10px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink4);padding:10px 14px;text-align:left;border-bottom:2px solid var(--line);white-space:nowrap}
.orders-table td{padding:14px;border-bottom:1px solid var(--cream3);vertical-align:top}
.orders-table tr:last-child td{border-bottom:none}
.order-status{display:inline-block;padding:3px 10px;font-size:10px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border-radius:2px;white-space:nowrap;max-width:100%;box-sizing:border-box}
.orders-table th:last-child,.orders-table td:last-child{min-width:8.5rem}
.st-pending{background:#fef3c7;color:#92400e}
.st-confirmed{background:#dbeafe;color:#1e40af}
.st-paid{background:var(--accent-light);color:var(--accent)}
.st-shipped{background:#ede9fe;color:#5b21b6}
.st-delivered{background:#d1fae5;color:#065f46}
.st-cancelled{background:#fee2e2;color:#991b1b}
.empty-orders{text-align:center;padding:48px 24px;color:var(--ink3);border:1px dashed var(--line);background:#fff}
.empty-orders p{margin-bottom:16px;font-size:15px}
/* Profile form */
.profile-form{background:#fff;border:1px solid var(--line);padding:28px}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.btn-secondary{padding:11px 24px;background:transparent;border:1px solid var(--line);font-family:var(--font-body);font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--ink3);cursor:pointer;transition:all .18s}
.btn-secondary:hover{border-color:var(--ink);color:var(--ink)}
.form-actions{display:flex;gap:12px;margin-top:24px}
/* Danger zone */
.danger-box{background:#fff5f5;border:1px solid #fca5a5;padding:24px 28px}
.danger-box h3{font-size:14px;font-weight:700;color:var(--red);text-transform:uppercase;letter-spacing:.1em;margin-bottom:8px}
.danger-box p{font-size:13px;color:var(--ink2);line-height:1.7;margin-bottom:20px}
.btn-danger{padding:12px 24px;background:var(--red);color:#fff;border:none;font-family:var(--font-body);font-size:12px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;cursor:pointer;transition:background .18s}
.btn-danger:hover{background:#a93226}
/* Modal */
.modal-bg{position:fixed;inset:0;background:rgba(28,26,23,.55);z-index:var(--z-modal-bg);display:none;align-items:center;justify-content:center;padding:24px}
.modal-bg.on{display:flex}
.modal{background:#fff;padding:36px;max-width:420px;width:100%;position:relative}
.modal h3{font-family:var(--font-display);font-size:22px;margin-bottom:8px}
.modal p{font-size:13px;color:var(--ink3);margin-bottom:20px;line-height:1.65}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;font-size:20px;cursor:pointer;color:var(--ink3)}
/* Footer */
footer{background:var(--ink);color:rgba(250,248,244,.6);margin-top:0}
.foot-inner{max-width:1280px;margin:0 auto;padding:56px 24px 32px;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:48px}
.foot-logo{font-family:var(--font-display);font-size:26px;font-weight:700;color:var(--cream);margin-bottom:16px}
footer p,footer a{display:block;font-size:13px;color:rgba(250,248,244,.5);text-decoration:none;margin-bottom:10px;line-height:1.7;transition:color .18s}
footer a:hover{color:var(--cream)}
footer h4{font-family:var(--font-body);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:var(--cream);margin-bottom:20px;opacity:.8}
.foot-bottom{max-width:1280px;margin:0 auto;padding:20px 24px;border-top:1px solid rgba(250,248,244,.1);display:flex;justify-content:space-between;font-size:12px;color:rgba(250,248,244,.35)}
/* Responsive */
@media(max-width:1024px){
  .logo-img{height:50px}
}
@media(max-width:768px){
  .dash-grid{grid-template-columns:1fr}
  .dash-nav{display:flex;overflow-x:auto;padding:10px 0 0;scroll-snap-type:x proximity;scroll-padding:0 16px;border:none;border-bottom:1px solid var(--line);background:#fff;gap:0;box-shadow:0 1px 0 rgba(28,26,23,.06)}
  .dash-nav a{border-left:none;border-bottom:3px solid transparent;margin-bottom:-1px;white-space:nowrap;padding:14px 16px;font-size:12px;font-weight:600;letter-spacing:.06em}
  .dash-nav a.on{border-bottom-color:var(--ink);border-left:none;background:transparent;color:var(--ink)}
  .stat-row{grid-template-columns:1fr 1fr}
  .form-row{grid-template-columns:1fr}
  .foot-inner{grid-template-columns:1fr 1fr;gap:32px}
}
@media(max-width:480px){
  .stat-row{grid-template-columns:1fr}
  .orders-table .hide-mobile{display:none}
}
</style>
<link rel="stylesheet" href="css/app.css?v=8">
<link rel="stylesheet" href="css/responsive-global.css?v=16">
<script defer src="js/kbe-ios-helpers.js?v=2"></script>
</head>
<body class="account-page">

<?php include __DIR__ . '/includes/announce.php'; ?>

<?php
$navActive = 'account';
$navClass = 'site-nav';
$navCartOnclick = "window.location.href='index.html?openCart=1'";
include __DIR__ . '/includes/nav.php';
?>

<!-- PAGE CONTENT -->
<div class="page">

<?php if (!$user): ?>
<!-- ===== AUTH (not logged in) ===== -->
<div class="auth-wrap">
  <h1 class="page-title">Mijn account</h1>
  <p class="page-sub">Log in om je bestellingen te bekijken en je gegevens te beheren.</p>

  <div class="auth-tabs">
    <div class="atab on" id="tab-login" onclick="switchAuthTab('login')">Inloggen</div>
    <div class="atab"    id="tab-register" onclick="switchAuthTab('register')">Account aanmaken</div>
  </div>

  <!-- LOGIN -->
  <div class="apanel on" id="panel-login">
    <div class="form-err" id="login-err"></div>
    <div class="form-group">
      <label>E-mailadres</label>
      <input type="email" id="login-email" placeholder="jij@voorbeeld.nl" autocomplete="email">
    </div>
    <div class="form-group">
      <label>Wachtwoord</label>
      <input type="password" id="login-pass" placeholder="Je wachtwoord" autocomplete="current-password">
    </div>
    <button type="button" class="btn-primary" id="login-btn" onclick="doLogin()">Inloggen</button>
  </div>

  <!-- REGISTER -->
  <div class="apanel" id="panel-register">
    <div class="form-err" id="reg-err"></div>
    <div class="form-group">
      <label>Volledige naam</label>
      <input type="text" id="reg-name" placeholder="Je naam" autocomplete="name">
    </div>
    <div class="form-group">
      <label>E-mailadres</label>
      <input type="email" id="reg-email" placeholder="jij@voorbeeld.nl" autocomplete="email">
    </div>
    <div class="form-group">
      <label>Wachtwoord</label>
      <input type="password" id="reg-pass" placeholder="Minimaal 8 tekens" autocomplete="new-password">
      <p class="form-hint">Minimaal 8 tekens.</p>
    </div>
    <button type="button" class="btn-primary" id="reg-btn" onclick="doRegister()">Account aanmaken</button>
    <p class="form-hint" style="margin-top:16px;line-height:1.65">
      Door een account aan te maken ga je ermee akkoord dat we je naam, e-mail en adres
      alleen bewaren om je bestellingen te verwerken. Je kunt je account en gegevens altijd verwijderen.
      Zie ons <a href="#" style="text-decoration:underline;color:var(--ink3)">privacybeleid</a>.
    </p>
  </div>
</div>

<?php else: ?>
<!-- ===== DASHBOARD (logged in) ===== -->
<h1 class="page-title">Mijn account</h1>
<p class="page-sub">Welkom terug, <strong class="account-user-name"><?= $esc($displayNameNice($user['name'])) ?></strong> · <a href="?logout=1" style="color:var(--ink3);text-decoration:underline;font-size:13px">Uitloggen</a></p>

<div class="dash-grid">

  <!-- Sidebar nav -->
  <nav class="dash-nav">
    <a class="on" id="dnav-overview" onclick="switchTab('overview')" href="#"><span class="nav-icon">📊</span> Overzicht</a>
    <a id="dnav-orders"   onclick="switchTab('orders')"   href="#"><span class="nav-icon">📦</span> Mijn bestellingen</a>
    <a id="dnav-profile"  onclick="switchTab('profile')"  href="#"><span class="nav-icon">👤</span> Mijn gegevens</a>
    <a id="dnav-security" onclick="switchTab('security')" href="#"><span class="nav-icon">🔒</span> Wachtwoord</a>
    <a id="dnav-delete"   onclick="switchTab('delete')"   href="#" class="danger-link"><span class="nav-icon">🗑</span> Account verwijderen</a>
  </nav>

  <!-- Main content -->
  <div>

    <!-- OVERVIEW -->
    <div class="dpanel on" id="dpanel-overview">
      <div class="dpanel-title">Overzicht</div>
      <div class="dpanel-sub">Lid sinds <?= $esc($memberSinceNl($user['created_at'])) ?></div>
      <div class="stat-row">
        <div class="stat-card">
          <div class="stat-val"><?= count($orders) ?></div>
          <div class="stat-lbl">Geplaatste bestellingen</div>
        </div>
        <div class="stat-card">
          <div class="stat-val">€<?= number_format(array_sum(array_column($orders, 'total')), 2) ?></div>
          <div class="stat-lbl">Totaal uitgegeven</div>
        </div>
        <div class="stat-card">
          <div class="stat-val"><?= count(array_filter($orders, fn($o) => $o['status'] === 'delivered')) ?></div>
          <div class="stat-lbl">Afgeleverd</div>
        </div>
      </div>
      <?php if ($orders): ?>
      <p style="font-size:13px;font-weight:600;margin-bottom:12px;text-transform:uppercase;letter-spacing:.1em;color:var(--ink4)">Recente bestellingen</p>
      <div class="orders-table-scroll">
      <table class="orders-table">
        <thead><tr>
          <th>Bestelling</th>
          <th>Datum</th>
          <th class="hide-mobile">Artikelen</th>
          <th>Totaal</th>
          <th>Status</th>
        </tr></thead>
        <tbody>
          <?php foreach(array_slice($orders, 0, 3) as $o): ?>
          <tr>
            <td><strong><?= $esc($o['order_id']) ?></strong></td>
            <td><?= date('d-m-Y', strtotime($o['created_at'])) ?></td>
            <td class="hide-mobile" style="color:var(--ink3);font-size:12px"><?= nl2br(htmlspecialchars((string)$o['items_list'], ENT_QUOTES, 'UTF-8')) ?></td>
            <td><strong>€<?= number_format($o['total'], 2) ?></strong></td>
            <td><span class="order-status st-<?= $esc($o['status']) ?>"><?= $esc($statusLabel($o['status'])) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php if(count($orders) > 3): ?>
        <p style="margin-top:12px"><a href="#" onclick="switchTab('orders');return false" style="font-size:13px;text-decoration:underline;color:var(--ink3)">Alle <?= count($orders) ?> bestellingen bekijken →</a></p>
      <?php endif; ?>
      <?php else: ?>
      <div class="empty-orders">
        <p>Je hebt nog geen bestellingen geplaatst.</p>
        <a href="index.html#shop" class="btn-primary" style="display:inline-block;padding:12px 32px;text-decoration:none">Tenues bekijken</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- ORDERS -->
    <div class="dpanel" id="dpanel-orders">
      <div class="dpanel-title">Mijn bestellingen</div>
      <div class="dpanel-sub">Volledige geschiedenis gekoppeld aan je account</div>
      <?php if ($orders): ?>
      <div class="orders-table-scroll">
      <table class="orders-table">
        <thead><tr>
          <th>Bestelnr.</th>
          <th>Datum</th>
          <th class="hide-mobile">Artikelen</th>
          <th>Verzending</th>
          <th>Totaal</th>
          <th>Status</th>
        </tr></thead>
        <tbody>
          <?php foreach($orders as $o): ?>
          <tr>
            <td><strong><?= $esc($o['order_id']) ?></strong></td>
            <td style="white-space:nowrap"><?= date('d-m-Y', strtotime($o['created_at'])) ?></td>
            <td class="hide-mobile" style="color:var(--ink3);font-size:12px;max-width:220px"><?= nl2br(htmlspecialchars((string)$o['items_list'], ENT_QUOTES, 'UTF-8')) ?></td>
            <td><?= $o['shipping'] == 0 ? 'Gratis' : '€' . number_format($o['shipping'], 2) ?></td>
            <td><strong>€<?= number_format($o['total'], 2) ?></strong></td>
            <td><span class="order-status st-<?= $esc($o['status']) ?>"><?= $esc($statusLabel($o['status'])) ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php else: ?>
      <div class="empty-orders">
        <p>Geen bestellingen gevonden. Bestellingen die je ingelogd plaatst, verschijnen hier.</p>
        <a href="index.html#shop" class="btn-primary" style="display:inline-block;padding:12px 32px;text-decoration:none">Tenues bekijken</a>
      </div>
      <?php endif; ?>
    </div>

    <!-- PROFILE -->
    <div class="dpanel" id="dpanel-profile">
      <div class="dpanel-title">Mijn gegevens</div>
      <div class="dpanel-sub">Werk je naam, telefoon en bezorgadres bij</div>
      <div class="profile-form">
        <div class="form-ok" id="profile-ok">Wijzigingen opgeslagen.</div>
        <div class="form-err" id="profile-err"></div>
        <div class="form-row">
          <div class="form-group">
            <label>Volledige naam</label>
            <input type="text" id="p-name" value="<?= $esc($user['name']) ?>" autocomplete="name">
          </div>
          <div class="form-group">
            <label>Telefoonnummer</label>
            <input type="tel" id="p-phone" value="<?= $esc($user['phone']) ?>" placeholder="+31 6 12 34 56 78" autocomplete="tel">
          </div>
        </div>
        <div class="form-group">
          <label>Straat en huisnummer</label>
          <input type="text" id="p-street" value="<?= $esc($user['street']) ?>" placeholder="Hoofdstraat 12" autocomplete="street-address">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Postcode</label>
            <input type="text" id="p-zip" value="<?= $esc($user['zip']) ?>" placeholder="1234 AB" autocomplete="postal-code">
          </div>
          <div class="form-group">
            <label>Plaats</label>
            <input type="text" id="p-city" value="<?= $esc($user['city']) ?>" placeholder="Amsterdam" autocomplete="address-level2">
          </div>
        </div>
        <div class="form-group">
          <label>E-mailadres</label>
          <input type="email" value="<?= $esc($user['email']) ?>" disabled style="background:var(--cream2);color:var(--ink4);cursor:not-allowed">
          <p class="form-hint">E-mail kan niet worden gewijzigd. Neem via WhatsApp contact op als je hulp nodig hebt.</p>
        </div>
        <div class="form-actions">
          <button type="button" class="btn-primary" style="width:auto;padding:12px 32px" onclick="saveProfile()">Opslaan</button>
        </div>
      </div>
    </div>

    <!-- SECURITY -->
    <div class="dpanel" id="dpanel-security">
      <div class="dpanel-title">Wachtwoord wijzigen</div>
      <div class="dpanel-sub">Gebruik een sterk wachtwoord van minimaal 8 tekens</div>
      <div class="profile-form">
        <div class="form-ok" id="pw-ok">Wachtwoord gewijzigd.</div>
        <div class="form-err" id="pw-err"></div>
        <div class="form-group">
          <label>Huidig wachtwoord</label>
          <input type="password" id="pw-current" autocomplete="current-password">
        </div>
        <div class="form-group">
          <label>Nieuw wachtwoord</label>
          <input type="password" id="pw-new" autocomplete="new-password">
          <p class="form-hint">Minimaal 8 tekens.</p>
        </div>
        <div class="form-group">
          <label>Bevestig nieuw wachtwoord</label>
          <input type="password" id="pw-confirm" autocomplete="new-password">
        </div>
        <div class="form-actions">
          <button type="button" class="btn-primary" style="width:auto;padding:12px 32px" onclick="changePassword()">Wachtwoord bijwerken</button>
        </div>
      </div>
    </div>

    <!-- DELETE ACCOUNT -->
    <div class="dpanel" id="dpanel-delete">
      <div class="dpanel-title">Account verwijderen</div>
      <div class="dpanel-sub">Verwijder je persoonsgegevens permanent bij KitsByElbaa</div>
      <div class="danger-box">
        <h3><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px"><path d="M12 3.2l9.2 16.3H2.8z"></path><path d="M12 10v4.2M12 17.2v.02"></path></svg>Dit kan niet ongedaan worden gemaakt</h3>
        <p>
          Als je je account verwijdert, wissen we je naam, e-mailadres, telefoonnummer
          en bezorgadres uit onze systemen. Dat is je recht onder de
          <strong>Algemene Verordening Gegevensbescherming (AVG)</strong>.<br><br>
          Bestelgegevens worden anoniem 7 jaar bewaard vanwege de Nederlandse fiscale bewaarplicht
          (Belastingdienst), maar niet meer aan jou gekoppeld.
        </p>
        <button type="button" class="btn-danger" onclick="openDeleteModal()">Mijn account &amp; gegevens verwijderen</button>
      </div>
    </div>

  </div><!-- /main content -->
</div><!-- /dash-grid -->

<!-- Logout link at bottom -->
<p style="margin-top:40px;font-size:13px;color:var(--ink4)">
  <a href="?logout=1" style="text-decoration:underline">Uitloggen</a> ·
  <a href="index.html" style="text-decoration:underline">Terug naar de shop</a>
</p>
<?php endif; ?>

</div><!-- /page -->

<!-- DELETE ACCOUNT MODAL -->
<div class="modal-bg" id="deleteModal">
  <div class="modal">
    <button type="button" class="modal-close" onclick="closeDeleteModal()">✕</button>
    <h3>Account verwijderen?</h3>
    <p>Voer je wachtwoord ter bevestiging in. Hiermee worden al je persoonsgegevens direct en permanent verwijderd.</p>
    <div class="form-err" id="del-err"></div>
    <div class="form-group">
      <label>Je wachtwoord</label>
      <input type="password" id="del-pass" placeholder="Je wachtwoord">
    </div>
    <div style="display:flex;gap:12px;margin-top:8px">
      <button type="button" class="btn-secondary" onclick="closeDeleteModal()">Annuleren</button>
      <button type="button" class="btn-danger" id="del-confirm-btn" onclick="confirmDelete()">Ja, alles verwijderen</button>
    </div>
  </div>
</div>

<?php
$footerAnchorPrefix   = 'index.html';
$footerHideAlleTenues = false;
include __DIR__ . '/includes/footer.php';
?>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function initKbeNavToggleAria() {
  const cb = document.getElementById('kbeNavToggle');
  const ham = document.getElementById('ham');
  const nav = document.getElementById('navLinks');
  if (!cb || !ham || !nav) return;
  const sync = () => ham.setAttribute('aria-expanded', cb.checked ? 'true' : 'false');
  cb.addEventListener('change', sync);
  nav.querySelectorAll('a').forEach((a) => {
    a.addEventListener('click', () => {
      cb.checked = false;
      sync();
    });
  });
  sync();
}
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initKbeNavToggleAria);
} else {
  initKbeNavToggleAria();
}

// ── NAV CART COUNTER (read-only: synchroniseer met localStorage van home) ──
function kbeUpdateNavCartCount() {
  try {
    const raw = localStorage.getItem('kbe_cart_main');
    const cart = raw ? JSON.parse(raw) : [];
    const count = Array.isArray(cart)
      ? cart.reduce((s, i) => s + Number(i && i.qty ? i.qty : 0), 0)
      : 0;
    const el = document.getElementById('cartN');
    if (el) el.textContent = String(count);
  } catch (_) {}
}
kbeUpdateNavCartCount();
window.addEventListener('storage', function (e) {
  if (!e || e.key === 'kbe_cart_main' || e.key === null) kbeUpdateNavCartCount();
});

// ── AUTH TABS ────────────────────────────────────────────────
function switchAuthTab(tab) {
  document.querySelectorAll('.atab').forEach(el => el.classList.remove('on'));
  document.querySelectorAll('.apanel').forEach(el => el.classList.remove('on'));
  document.getElementById('tab-' + tab).classList.add('on');
  document.getElementById('panel-' + tab).classList.add('on');
}

// ── DASHBOARD TABS ───────────────────────────────────────────
function switchTab(tab) {
  document.querySelectorAll('.dpanel').forEach(el => el.classList.remove('on'));
  document.querySelectorAll('.dash-nav a').forEach(el => el.classList.remove('on'));
  document.getElementById('dpanel-' + tab).classList.add('on');
  document.getElementById('dnav-' + tab).classList.add('on');
  return false;
}

// ── API HELPER ───────────────────────────────────────────────
async function authApi(body) {
  try {
    const res = await fetch('api/auth.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
      body: JSON.stringify(body),
    });
    const text = await res.text();
    let data;
    try {
      data = text ? JSON.parse(text) : {};
    } catch (_) {
      return { ok: false, error: 'Serverfout (ongeldig antwoord). Blijft dit? Neem contact op.' };
    }
    if (!res.ok && data.ok !== false) {
      data.ok = false;
      data.error = data.error || ('HTTP ' + res.status);
    }
    return data;
  } catch (_) {
    return { ok: false, error: 'Netwerkfout — server niet bereikbaar. Controleer je verbinding en de site-URL.' };
  }
}

function showErr(id, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg; el.style.display = 'block';
}
function hideMsg(id) {
  const el = document.getElementById(id);
  if (el) el.style.display = 'none';
}
function showOk(id, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg; el.style.display = 'block';
  setTimeout(() => { el.style.display = 'none'; }, 4000);
}

// ── LOGIN ────────────────────────────────────────────────────
async function doLogin() {
  hideMsg('login-err');
  const btn = document.getElementById('login-btn');
  const email = document.getElementById('login-email').value.trim();
  const pass  = document.getElementById('login-pass').value;
  if (!email || !pass) { showErr('login-err', 'Vul alle velden in.'); return; }
  btn.disabled = true; btn.textContent = 'Bezig met inloggen…';
  const r = await authApi({ action: 'login', email, password: pass });
  if (r.ok) { window.location.reload(); }
  else { showErr('login-err', r.error || 'Inloggen mislukt.'); btn.disabled = false; btn.textContent = 'Inloggen'; }
}

// ── REGISTER ─────────────────────────────────────────────────
async function doRegister() {
  hideMsg('reg-err');
  const btn = document.getElementById('reg-btn');
  const name  = document.getElementById('reg-name').value.trim();
  const email = document.getElementById('reg-email').value.trim();
  const pass  = document.getElementById('reg-pass').value;
  if (!name || !email || !pass) { showErr('reg-err', 'Vul alle velden in.'); return; }
  btn.disabled = true; btn.textContent = 'Account wordt aangemaakt…';
  const r = await authApi({ action: 'register', name, email, password: pass });
  if (r.ok) { window.location.reload(); }
  else { showErr('reg-err', r.error || 'Registratie mislukt.'); btn.disabled = false; btn.textContent = 'Account aanmaken'; }
}

// ── SAVE PROFILE ─────────────────────────────────────────────
async function saveProfile() {
  hideMsg('profile-err'); hideMsg('profile-ok');
  const r = await authApi({
    action:  'update_profile',
    name:   document.getElementById('p-name').value.trim(),
    phone:  document.getElementById('p-phone').value.trim(),
    street: document.getElementById('p-street').value.trim(),
    zip:    document.getElementById('p-zip').value.trim(),
    city:   document.getElementById('p-city').value.trim(),
  });
  if (r.ok) showOk('profile-ok', 'Opgeslagen.');
  else showErr('profile-err', r.error || 'Kon niet opslaan.');
}

// ── CHANGE PASSWORD ───────────────────────────────────────────
async function changePassword() {
  hideMsg('pw-err'); hideMsg('pw-ok');
  const cur  = document.getElementById('pw-current').value;
  const nw   = document.getElementById('pw-new').value;
  const conf = document.getElementById('pw-confirm').value;
  if (nw !== conf) { showErr('pw-err', 'Nieuwe wachtwoorden komen niet overeen.'); return; }
  const r = await authApi({ action: 'change_password', current_password: cur, new_password: nw });
  if (r.ok) {
    showOk('pw-ok', 'Wachtwoord bijgewerkt.');
    document.getElementById('pw-current').value = '';
    document.getElementById('pw-new').value = '';
    document.getElementById('pw-confirm').value = '';
  } else {
    showErr('pw-err', r.error || 'Wachtwoord niet bijgewerkt.');
  }
}

// ── DELETE ACCOUNT ────────────────────────────────────────────
function openDeleteModal()  { document.getElementById('deleteModal').classList.add('on'); }
function closeDeleteModal() { document.getElementById('deleteModal').classList.remove('on'); hideMsg('del-err'); }

async function confirmDelete() {
  hideMsg('del-err');
  const pass = document.getElementById('del-pass').value;
  if (!pass) { showErr('del-err', 'Voer je wachtwoord in.'); return; }
  const btn = document.getElementById('del-confirm-btn');
  btn.disabled = true; btn.textContent = 'Bezig met verwijderen…';
  const r = await authApi({ action: 'delete_account', password: pass });
  if (r.ok) {
    window.location.href = 'index.html?deleted=1';
  } else {
    showErr('del-err', r.error || 'Account niet verwijderd. Controleer je wachtwoord.');
    btn.disabled = false; btn.textContent = 'Ja, alles verwijderen';
  }
}

// Enter key on forms
document.addEventListener('keydown', e => {
  if (e.key !== 'Enter') return;
  const active = document.querySelector('.apanel.on');
  if (active?.id === 'panel-login') doLogin();
  if (active?.id === 'panel-register') doRegister();
});
</script>

</body>
</html>





