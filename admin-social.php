<?php
/**
 * Beheer van de "Echt gedragen"-sectie (uploads/social/) — foto's & video's.
 * Zelfstandig: hergebruikt de admin-sessie (login via admin.php). Raakt admin.php niet.
 * Uploads worden op extensie + MIME + grootte gevalideerd; .htaccess in de map blokkeert scripts.
 */
require_once __DIR__ . '/includes/session.php';
kits_session_start('Lax');
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/kits_admin_guard.php';
if (!kits_admin_ip_allowed($cfg)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Toegang geweigerd');
}
if (empty($_SESSION['admin'])) {           // inloggen gebeurt op admin.php (gedeelde sessie)
    header('Location: admin.php');
    exit;
}

$DIR      = __DIR__ . '/uploads/social';
$ALLOWED  = ['jpg', 'jpeg', 'png', 'webp', 'mp4', 'webm'];
$OKMIME   = ['image/jpeg', 'image/png', 'image/webp', 'video/mp4', 'video/webm'];
$MAXBYTES = 40 * 1024 * 1024; // 40 MB per bestand
if (!is_dir($DIR)) {
    @mkdir($DIR, 0755, true);
}

function kbe_social_list(string $dir, array $allowed): array
{
    $out = [];
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f === '' || $f[0] === '.') {
            continue;
        }
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            $out[] = $f;
        }
    }
    sort($out);
    return $out;
}
function kbe_social_rest(string $name): string
{
    // Strip een leidend volgnummer OF het tijdelijke 'zzz_'-uploadmerk, zodat de definitieve naam schoon is.
    return (string)preg_replace('/^(?:\d+|zzz)_/', '', $name);
}
/** Poster (stilstaand beeld) van een video: posters/<naam zonder volgnummer>.jpg — overleeft hernummeren. */
function kbe_social_poster(string $dir, string $video): string
{
    return $dir . '/posters/' . pathinfo(kbe_social_rest($video), PATHINFO_FILENAME) . '.jpg';
}
/** Tegel-thumbnail (432px WebP) van een foto of video-poster: thumbs/<naam zonder volgnummer>.webp. */
function kbe_social_thumb(string $dir, string $file): string
{
    return $dir . '/thumbs/' . pathinfo(kbe_social_rest($file), PATHINFO_FILENAME) . '.webp';
}
/** Hernummert de hele map naar 01_, 02_, … in de meegegeven volgorde (twee-fasen tegen naamsbotsing). */
function kbe_social_renumber(string $dir, array $ordered): void
{
    $tmp = [];
    foreach (array_values($ordered) as $i => $name) {
        $rest = kbe_social_rest($name);
        $t    = '__tmp' . $i . '_' . $rest;
        if (@rename($dir . '/' . $name, $dir . '/' . $t)) {
            $tmp[] = ['t' => $t, 'rest' => $rest];
        }
    }
    foreach ($tmp as $i => $info) {
        $nn = sprintf('%02d', $i + 1);
        @rename($dir . '/' . $info['t'], $dir . '/' . $nn . '_' . $info['rest']);
    }
}

$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'] ?? '', (string)($_POST['csrf_token'] ?? ''))) {
        $err = 'Sessie verlopen — herlaad de pagina en probeer opnieuw.';
    } else {
        $a = (string)($_POST['_a'] ?? '');
        if ($a === 'poster') {
            // Door de browser gemaakte poster (JPEG) opslaan — geen ffmpeg nodig op de server.
            header('Content-Type: application/json');
            $t  = basename((string)($_POST['file'] ?? ''));
            $up = $_FILES['poster'] ?? null;
            $ok = in_array($t, kbe_social_list($DIR, $ALLOWED), true)
                && preg_match('/\.(mp4|webm)$/i', $t)
                && $up && ($up['error'] ?? 1) === UPLOAD_ERR_OK
                && $up['size'] > 0 && $up['size'] <= 3 * 1024 * 1024
                && (($info = @getimagesize($up['tmp_name'])) !== false) && $info[2] === IMAGETYPE_JPEG;
            if (!$ok) {
                http_response_code(400);
                exit(json_encode(['ok' => false]));
            }
            @mkdir($DIR . '/posters', 0755, true);
            exit(json_encode(['ok' => @move_uploaded_file($up['tmp_name'], kbe_social_poster($DIR, $t))]));
        }
        if ($a === 'thumb') {
            // Door de browser verkleinde tegel-versie (WebP, max 600px breed) — scheelt ~75% gewicht op de homepage.
            header('Content-Type: application/json');
            $t  = basename((string)($_POST['file'] ?? ''));
            $up = $_FILES['thumb'] ?? null;
            $ok = in_array($t, kbe_social_list($DIR, $ALLOWED), true)
                && $up && ($up['error'] ?? 1) === UPLOAD_ERR_OK
                && $up['size'] > 0 && $up['size'] <= 1024 * 1024
                && (($info = @getimagesize($up['tmp_name'])) !== false)
                && $info[2] === IMAGETYPE_WEBP && $info[0] <= 600;
            if (!$ok) {
                http_response_code(400);
                exit(json_encode(['ok' => false]));
            }
            @mkdir($DIR . '/thumbs', 0755, true);
            exit(json_encode(['ok' => @move_uploaded_file($up['tmp_name'], kbe_social_thumb($DIR, $t))]));
        }
        if ($a === 'upload' && !empty($_FILES['files']['name'])) {
            $f       = $_FILES['files'];
            $count   = is_array($f['name']) ? count($f['name']) : 0;
            $added   = 0;
            $useMime = class_exists('finfo');
            $fi      = $useMime ? new finfo(FILEINFO_MIME_TYPE) : null;
            for ($i = 0; $i < $count; $i++) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                    continue;
                }
                if ($f['size'][$i] <= 0 || $f['size'][$i] > $MAXBYTES) {
                    $err = 'Bestand te groot (max 40 MB) of leeg.';
                    continue;
                }
                $orig = (string)$f['name'][$i];
                $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                if (!in_array($ext, $ALLOWED, true)) {
                    $err = 'Type niet toegestaan: .' . htmlspecialchars($ext);
                    continue;
                }
                if ($fi) {
                    $mime = (string)$fi->file($f['tmp_name'][$i]);
                    if (!in_array($mime, $OKMIME, true)) {
                        $err = 'Ongeldig bestand (herkend als ' . htmlspecialchars($mime) . ').';
                        continue;
                    }
                }
                $base = (string)preg_replace('/[^a-zA-Z0-9._-]+/', '-', pathinfo($orig, PATHINFO_FILENAME));
                $base = trim($base, '-._');
                if ($base === '') {
                    $base = 'media';
                }
                $base = substr($base, 0, 60);
                $safe = 'zzz_' . $base . '.' . $ext;     // 'zzz_' → sorteert achteraan, komt zo achteraan de volgorde
                $c    = 1;
                while (file_exists($DIR . '/' . $safe)) {
                    $safe = 'zzz_' . $base . '-' . $c . '.' . $ext;
                    $c++;
                }
                if (@move_uploaded_file($f['tmp_name'][$i], $DIR . '/' . $safe)) {
                    $added++;
                }
            }
            kbe_social_renumber($DIR, kbe_social_list($DIR, $ALLOWED));
            if ($added > 0) {
                $msg = $added . ' bestand(en) toegevoegd.';
            } elseif ($err === '') {
                $err = 'Geen bestanden geüpload.';
            }
        } elseif ($a === 'delete') {
            $t  = basename((string)($_POST['file'] ?? ''));
            $p  = $DIR . '/' . $t;
            $rp = realpath($p);
            if ($t !== '' && is_file($p) && $rp !== false && strpos($rp, (string)realpath($DIR)) === 0) {
                @unlink($p);
                @unlink(kbe_social_poster($DIR, $t));
                @unlink(kbe_social_thumb($DIR, $t));
                $msg = 'Verwijderd.';
            } else {
                $err = 'Bestand niet gevonden.';
            }
            kbe_social_renumber($DIR, kbe_social_list($DIR, $ALLOWED));
        } elseif ($a === 'up' || $a === 'down') {
            $t    = basename((string)($_POST['file'] ?? ''));
            $list = kbe_social_list($DIR, $ALLOWED);
            $idx  = array_search($t, $list, true);
            if ($idx !== false) {
                $j = $a === 'up' ? $idx - 1 : $idx + 1;
                if ($j >= 0 && $j < count($list)) {
                    [$list[$idx], $list[$j]] = [$list[$j], $list[$idx]];
                    kbe_social_renumber($DIR, $list);
                    $msg = 'Volgorde aangepast.';
                }
            }
        }
        $_SESSION['flash'] = ['msg' => $msg, 'err' => $err];
        header('Location: admin-social.php');   // PRG: geen dubbele upload bij refresh
        exit;
    }
}
if (!empty($_SESSION['flash'])) {
    $msg = (string)($_SESSION['flash']['msg'] ?? '');
    $err = (string)($_SESSION['flash']['err'] ?? '');
    unset($_SESSION['flash']);
}

$files    = kbe_social_list($DIR, $ALLOWED);
$csrf     = (string)$_SESSION['csrf_token'];
$e        = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$nImg     = 0;
$nVid     = 0;
foreach ($files as $fn) {
    preg_match('/\.(mp4|webm)$/i', $fn) ? $nVid++ : $nImg++;
}
// Sidebar-navigatie identiek aan admin.php (icoon + label). Deze linken terug naar admin.php;
// "Social media" is de actieve pagina.
$nav = [
    ['admin.php', 'Overzicht',          'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    ['admin.php', 'Bestellingen',       'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
    ['admin.php', 'Producten',          'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
    ['admin.php', 'Voorraad',           'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
    ['admin.php', 'Kortingscodes',      'M7 7h.01M17 17h.01M9 9l6 6M3 12l9-9 9 9-9 9-9-9z'],
    ['admin.php', 'Promotie &amp; banner', 'M11 5.882V19.24a1.76 1.76 0 01-3.417.592l-2.147-6.15M18 13a3 3 0 100-6M5.436 13.683A4.001 4.001 0 017 6h1.26c.477 0 .935.164 1.29.454l3.336 2.667M18 13V9a2 2 0 00-2-2h-1.343M11 5.882V5a2 2 0 012-2h2.343'],
];
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" href="images/icon-192.png" sizes="192x192">
<link rel="apple-touch-icon" href="images/apple-touch-icon.png">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Social media — Beheer · KitsByElbaa</title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
  --bg:#f0f2f5;--white:#fff;--sidebar:#1a1d23;--sidebar2:#22262e;
  --accent:#1c1e22;--accent2:#33373f;          /* neutraal-donker (GEEN groen) */
  --ink:#1c1a17;--ink2:#374151;--ink3:#6b7280;--line:#e5e7eb;
  --danger:#e5484d;--ok:#177245;
  --shadow:0 1px 8px rgba(0,0,0,.08);--shadow-md:0 8px 30px rgba(0,0,0,.12);
}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink2);min-height:100vh;-webkit-text-size-adjust:100%}
body.nav-open{overflow:hidden}
a{color:inherit;text-decoration:none}
button{cursor:pointer;font-family:inherit}
.layout{display:flex;min-height:100vh}

/* ── SIDEBAR (identiek aan admin.php) ── */
.sidebar{width:230px;background:var(--sidebar);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:106}
.s-logo{padding:24px 20px 18px;font-size:17px;font-weight:700;color:#fff;letter-spacing:.04em;border-bottom:1px solid rgba(255,255,255,.08)}
.s-logo span{color:rgba(255,255,255,.5);font-weight:400;font-size:11px;display:block;letter-spacing:.12em;text-transform:uppercase;margin-top:3px}
.s-nav{flex:1;padding:16px 12px;overflow-y:auto}
.sn{width:100%;display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:8px;border:none;background:transparent;color:rgba(255,255,255,.55);font-size:13.5px;font-weight:500;transition:all .18s;text-align:left;margin-bottom:2px}
.sn:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.9)}
.sn.on{background:var(--accent2);color:#fff}
.sn svg{width:17px;height:17px;flex-shrink:0}
.s-bottom{padding:16px 12px;border-top:1px solid rgba(255,255,255,.08)}
.s-store,.s-logout{width:100%;display:flex;align-items:center;gap:9px;padding:10px 14px;border-radius:8px;color:rgba(255,255,255,.45);font-size:13px;transition:all .18s;border:none;background:none;text-align:left}
.s-store{margin-bottom:6px}
.s-store:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.75)}
.s-logout:hover{background:rgba(229,72,77,.16);color:#ff6b6f}
.s-store svg,.s-logout svg{width:16px;height:16px}

/* ── MOBILE TOPBAR + DRAWER ── */
.topbar{display:none;position:sticky;top:0;z-index:60;background:var(--sidebar);color:#fff;align-items:center;gap:12px;padding:12px 16px}
.topbar .tb-logo{font-weight:700;letter-spacing:.04em}
.menu-toggle{width:40px;height:40px;border:none;background:rgba(255,255,255,.08);border-radius:9px;color:#fff;display:flex;align-items:center;justify-content:center}
.backdrop{display:none;position:fixed;inset:0;background:rgba(15,18,20,.55);z-index:105}
.backdrop.on{display:block}

/* ── MAIN ── */
.main{margin-left:230px;flex:1;padding:34px 36px 72px;min-height:100vh;max-width:1180px}
.page-title{font-size:23px;font-weight:700;color:var(--ink);margin-bottom:6px}
.page-sub{font-size:13.5px;color:var(--ink3);margin-bottom:26px;line-height:1.6;max-width:70ch}
.page-sub strong{color:var(--ink2)}

.flash{padding:12px 16px;border-radius:10px;font-size:13.5px;margin-bottom:20px;display:flex;align-items:center;gap:9px;font-weight:500}
.flash svg{width:18px;height:18px;flex-shrink:0}
.flash.ok{background:#e7f4ec;color:var(--ok);border:1px solid #c3e3ce}
.flash.err{background:#fdecec;color:var(--danger);border:1px solid #f5cccd}

.card{background:var(--white);border-radius:14px;box-shadow:var(--shadow);padding:22px;margin-bottom:22px}
.card-head{display:flex;align-items:center;gap:10px;font-size:12px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;color:var(--ink3);margin-bottom:16px}
.count{background:var(--bg);color:var(--ink2);border-radius:100px;padding:2px 10px;font-size:11px;letter-spacing:.02em}

/* ── UPLOAD DROPZONE ── */
.dropzone{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:8px;padding:34px 20px;border:2px dashed #cdd3db;border-radius:12px;background:#fafbfc;cursor:pointer;transition:all .18s}
.dropzone:hover{border-color:var(--ink3);background:#f6f8fa}
.dropzone.drag{border-color:var(--accent);background:#f1f3f5}
.dropzone .dz-ico{width:44px;height:44px;border-radius:12px;background:var(--accent);color:#fff;display:flex;align-items:center;justify-content:center}
.dropzone .dz-ico svg{width:22px;height:22px}
.dz-title{font-size:14.5px;font-weight:600;color:var(--ink)}
.dz-title span{color:var(--accent);text-decoration:underline;text-underline-offset:2px}
.dz-sub{font-size:12.5px;color:var(--ink3)}
.dz-selected{font-size:13px;color:var(--ink2);font-weight:600;margin:14px 0 0;min-height:0}
.upload-actions{display:flex;justify-content:flex-end;margin-top:16px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:11px 22px;border-radius:9px;border:none;font-size:13.5px;font-weight:600;transition:all .18s;background:var(--accent);color:#fff}
.btn:hover{background:var(--accent2)}
.btn svg{width:16px;height:16px}

/* ── MEDIA GRID ── */
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(158px,1fr));gap:18px}
.tile{position:relative;border-radius:13px;overflow:hidden;background:var(--white);border:1px solid var(--line);box-shadow:var(--shadow);display:flex;flex-direction:column}
.tile .media{aspect-ratio:9/16;width:100%;object-fit:cover;display:block;background:#1f2320}
.tile .pos{position:absolute;top:9px;left:9px;background:rgba(18,20,18,.82);color:#fff;font-size:11px;font-weight:700;padding:3px 9px;border-radius:100px;letter-spacing:.02em}
.tile .kind{position:absolute;top:9px;right:9px;background:rgba(18,20,18,.72);color:#fff;font-size:9.5px;font-weight:700;padding:3px 8px;border-radius:100px;letter-spacing:.08em;text-transform:uppercase}
.tile .bar{display:flex;align-items:center;gap:6px;padding:9px 10px;border-top:1px solid var(--line)}
.tile .bar form{margin:0;display:flex}
.iconbtn{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border:1px solid var(--line);background:#fff;border-radius:8px;font-size:14px;color:var(--ink2);transition:all .15s;line-height:1}
.iconbtn:hover{border-color:var(--ink3);background:#f7f8fa}
.iconbtn:disabled{opacity:.3;cursor:not-allowed}
.iconbtn.del{margin-left:auto;color:var(--danger);border-color:#f0d2d3}
.iconbtn.del:hover{background:#fdecec;border-color:var(--danger)}
.iconbtn svg{width:15px;height:15px}
.empty{display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;padding:34px 20px;color:var(--ink3);font-size:14px}
.empty strong{color:var(--ink2)}

@media(max-width:860px){
  .sidebar{transform:translateX(-100%);transition:transform .26s cubic-bezier(.4,0,.2,1);width:min(280px,84vw);box-shadow:none}
  .sidebar.open{transform:translateX(0);box-shadow:14px 0 44px rgba(0,0,0,.28)}
  .topbar{display:flex}
  .main{margin-left:0;padding:22px 16px 64px}
}
</style>
</head>
<body>
<div class="topbar">
  <button class="menu-toggle" type="button" aria-label="Menu" onclick="toggleNav()">
    <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:22px;height:22px"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
  </button>
  <span class="tb-logo">KitsByElbaa</span>
</div>
<div class="backdrop" id="backdrop" onclick="toggleNav()"></div>

<div class="layout">
  <aside class="sidebar" id="sidebar">
    <div class="s-logo">KitsByElbaa<span>Beheer</span></div>
    <nav class="s-nav">
      <?php foreach ($nav as [$href, $label, $path]): ?>
      <a class="sn" href="<?= $e($href) ?>"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $path ?>"/></svg><?= $label ?></a>
      <?php endforeach; ?>
      <a class="sn on" href="admin-social.php"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="10" r="1.5"/><path stroke-linecap="round" stroke-linejoin="round" d="m21 16-5-5L5 20"/></svg>Social media</a>
    </nav>
    <div class="s-bottom">
      <a class="s-store" href="index.html" target="_blank" rel="noopener"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>Winkel bekijken</a>
      <form method="post" action="admin.php" style="margin:0">
        <input type="hidden" name="_a" value="logout">
        <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
        <button type="submit" class="s-logout"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Uitloggen</button>
      </form>
    </div>
  </aside>

  <main class="main">
    <h1 class="page-title">Social media</h1>
    <p class="page-sub">De sectie <strong>“Echt gedragen”</strong> op de homepage — echte foto’s en video’s van je shirts. Wat hier staat, staat op de site. Is dit leeg, dan verschijnt de sectie niet.</p>

    <?php if ($msg !== ''): ?>
    <div class="flash ok"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg><?= $e($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
    <div class="flash err"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg><?= $e($err) ?></div>
    <?php endif; ?>

    <form class="card" method="post" enctype="multipart/form-data" id="upForm">
      <div class="card-head">Toevoegen</div>
      <input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>">
      <input type="hidden" name="_a" value="upload">
      <label class="dropzone" id="dropzone">
        <span class="dz-ico"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg></span>
        <span class="dz-title">Sleep bestanden hierheen of <span>klik om te kiezen</span></span>
        <span class="dz-sub">Foto’s en video’s · staand (9:16) staat het mooist · max 40&nbsp;MB per bestand</span>
        <input type="file" name="files[]" id="fileInput" accept=".jpg,.jpeg,.png,.webp,.mp4,.webm,image/*,video/mp4,video/webm" multiple hidden>
      </label>
      <div class="dz-selected" id="dzSelected"></div>
      <div class="upload-actions">
        <button class="btn" type="submit"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0L8 8m4-4l4 4M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2"/></svg>Uploaden</button>
      </div>
    </form>

    <div class="card">
      <div class="card-head">Huidige media <span class="count"><?= count($files) ?> · <?= $nImg ?> foto<?= $nImg === 1 ? '' : '\'s' ?>, <?= $nVid ?> video<?= $nVid === 1 ? '' : '\'s' ?></span></div>
      <?php if (!$files): ?>
        <div class="empty"><strong>Nog geen media.</strong>Upload hierboven je eerste foto of video — daarna verschijnt de sectie op de homepage.</div>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($files as $i => $name):
              $src   = 'uploads/social/' . rawurlencode($name);
              $isVid = (bool)preg_match('/\.(mp4|webm)$/i', $name);
          ?>
          <div class="tile">
            <span class="pos">#<?= $i + 1 ?></span>
            <span class="kind"><?= $isVid ? 'Video' : 'Foto' ?></span>
            <?php
              $hasPoster = $isVid && is_file(kbe_social_poster($DIR, $name));
              $prep = ' data-file="' . $e($name) . '"'
                  . ($isVid && !$hasPoster ? ' data-needs-poster="1"' : '')
                  . (is_file(kbe_social_thumb($DIR, $name)) ? '' : ' data-needs-thumb="1"')
                  . ($hasPoster ? ' data-poster="uploads/social/posters/' . $e(rawurlencode(basename(kbe_social_poster($DIR, $name)))) . '"' : '');
              if ($isVid): ?>
              <video class="media" src="<?= $e($src) ?>" muted preload="metadata" playsinline controls<?= $prep ?>></video>
            <?php else: ?>
              <img class="media" src="<?= $e($src) ?>" alt="" loading="lazy"<?= $prep ?>>
            <?php endif; ?>
            <div class="bar">
              <form method="post"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="_a" value="up"><input type="hidden" name="file" value="<?= $e($name) ?>"><button class="iconbtn" type="submit" title="Naar voren" <?= $i === 0 ? 'disabled' : '' ?>><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 15l7-7 7 7"/></svg></button></form>
              <form method="post"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="_a" value="down"><input type="hidden" name="file" value="<?= $e($name) ?>"><button class="iconbtn" type="submit" title="Naar achteren" <?= $i === count($files) - 1 ? 'disabled' : '' ?>><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg></button></form>
              <form method="post" onsubmit="return confirm('Dit beeld verwijderen?')"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="_a" value="delete"><input type="hidden" name="file" value="<?= $e($name) ?>"><button class="iconbtn del" type="submit" title="Verwijderen"><svg fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M6 6l12 12M18 6L6 18"/></svg></button></form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<script>
function toggleNav(){
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('backdrop').classList.toggle('on');
  document.body.classList.toggle('nav-open');
}
(function(){
  var fi=document.getElementById('fileInput'),dz=document.getElementById('dropzone'),sel=document.getElementById('dzSelected');
  function show(){sel.textContent=fi.files&&fi.files.length?fi.files.length+' bestand(en) gekozen — klik Uploaden':'';}
  fi.addEventListener('change',show);
  ['dragenter','dragover'].forEach(function(ev){dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.add('drag');});});
  ['dragleave','drop'].forEach(function(ev){dz.addEventListener(ev,function(e){e.preventDefault();dz.classList.remove('drag');});});
  dz.addEventListener('drop',function(e){if(e.dataTransfer&&e.dataTransfer.files.length){fi.files=e.dataTransfer.files;show();}});
})();

// Automatisch klaarzetten voor de homepage, in je eigen browser (de server heeft geen ffmpeg):
//  - poster: video zonder stilstaand beeld → scherpste frame uit de eerste seconden
//    (zonder poster ziet een iPhone in energiebesparingsmodus een zwarte tegel);
//  - thumb: 432px WebP-tegel van elke foto/poster (scheelt ~75% gewicht op de homepage).
(async function(){
  var todo = document.querySelectorAll('[data-needs-poster],[data-needs-thumb]');
  var CSRF = <?= json_encode($csrf) ?>;
  function wait(v, ev){ return new Promise(function(r){ v.addEventListener(ev, r, {once: true}); }); }
  function send(action, file, field, blob, name){
    var fd = new FormData();
    fd.append('csrf_token', CSRF); fd.append('_a', action); fd.append('file', file); fd.append(field, blob, name);
    return fetch('admin-social.php', {method: 'POST', body: fd, credentials: 'same-origin'})
      .then(function(r){ return r.json(); }).then(function(j){ return !!j.ok; }, function(){ return false; });
  }
  async function makeThumb(src, w, h){
    var c = document.createElement('canvas'); c.width = 432; c.height = Math.round(432 * h / w);
    c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
    var blob = await new Promise(function(r){ c.toBlob(r, 'image/webp', 0.82); });
    return blob && blob.type === 'image/webp' ? blob : null; // oudere Safari kent geen WebP-export → overslaan
  }
  function loadImg(url){ return new Promise(function(ok, no){ var i = new Image(); i.onload = function(){ ok(i); }; i.onerror = no; i.src = url; }); }
  function sharpness(ctx, w, h){
    var d = ctx.getImageData(0, 0, w, h).data, s = 0;
    for (var y = 1; y < h - 1; y += 2) for (var x = 1; x < w - 1; x += 2) {
      var i = (y * w + x) * 4 + 1, l = 4 * d[i] - d[i - 4] - d[i + 4] - d[i - w * 4] - d[i + w * 4];
      s += l * l;
    }
    return s;
  }
  for (var n = 0; n < todo.length; n++) {
    var el = todo[n], file = el.dataset.file, isVid = el.tagName === 'VIDEO';
    var label = el.closest('.tile').querySelector('.kind'), kind = isVid ? 'Video' : 'Foto', ok = true;
    try {
      label.textContent = kind + ' · klaarzetten…';
      var src = null, w = 0, h = 0; // bron voor de thumb: poster-frame (video) of de foto zelf
      if (isVid && el.dataset.needsPoster) {
        var v = document.createElement('video');
        v.muted = true; v.preload = 'auto'; v.src = el.currentSrc || el.src;
        await wait(v, 'loadeddata');
        var sc = document.createElement('canvas'); sc.width = 144; sc.height = Math.round(144 * v.videoHeight / v.videoWidth);
        var sx = sc.getContext('2d', {willReadFrequently: true}), best = {t: 0, s: -1};
        for (var t = 0.3; t < Math.min(4, v.duration - 0.2); t += 0.25) {
          v.currentTime = t; await wait(v, 'seeked');
          sx.drawImage(v, 0, 0, sc.width, sc.height);
          var s = sharpness(sx, sc.width, sc.height); if (s > best.s) best = {t: t, s: s};
        }
        v.currentTime = best.t; await wait(v, 'seeked');
        var c = document.createElement('canvas'); c.width = v.videoWidth; c.height = v.videoHeight;
        c.getContext('2d').drawImage(v, 0, 0);
        var poster = await new Promise(function(r){ c.toBlob(r, 'image/jpeg', 0.9); });
        ok = await send('poster', file, 'poster', poster, 'poster.jpg');
        src = c; w = c.width; h = c.height;
      } else if (isVid) {
        src = await loadImg(el.dataset.poster); w = src.naturalWidth; h = src.naturalHeight;
      } else {
        src = el.complete && el.naturalWidth ? el : await loadImg(el.currentSrc || el.src);
        w = src.naturalWidth; h = src.naturalHeight;
      }
      if (ok && el.dataset.needsThumb) {
        var thumb = await makeThumb(src, w, h);
        if (thumb) ok = await send('thumb', file, 'thumb', thumb, 'thumb.webp');
      }
      label.textContent = kind + (ok ? ' · klaar ✓' : ' · klaarzetten mislukt');
    } catch (e) {
      label.textContent = kind + ' · klaarzetten mislukt';
    }
  }
})();
</script>
</body>
</html>
