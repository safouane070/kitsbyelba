<?php
declare(strict_types=1);
$cfg = require __DIR__ . '/config.php';
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

$publicBase = rtrim((string)($cfg['public_site_url'] ?? ''), '/');
$seoTitle = 'Voetbaltenue | KitsByElbaa';
$seoDesc = 'Voetbalshirts en tenues bij KitsByElbaa. Snelle levering, veilig afrekenen en bedrukking met naam & nummer.';
$seoImage = '';
$seoCanonical = $publicBase !== '' ? $publicBase . '/product.php' : '';
$seoPrice = null;
$seoName = '';
$slugQ = isset($_GET['slug']) ? (string)$_GET['slug'] : '';
$contactEmail = 'KitsByElbaa@outlook.com';
$pdpWaDigits = preg_replace('/\D/', '', (string)($cfg['whatsapp'] ?? '31684446255')) ?: '31684446255';
$pdpWaDisplay = '+31 6 84446255';
$pdpWaHref = $pdpWaDigits !== '' ? 'https://wa.me/' . $pdpWaDigits : '';

if ($slugQ !== '' && preg_match('/^(\d+)/', $slugQ, $m)) {
    try {
        $pdoSeo = new PDO(
            'mysql:host=' . $cfg['db_host'] . ';dbname=' . $cfg['db_name'] . ';charset=utf8mb4',
            $cfg['db_user'],
            $cfg['db_pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $st = $pdoSeo->prepare('SELECT id, name, description, image, price FROM products WHERE id = ? AND active = 1 LIMIT 1');
        $st->execute([(int)$m[1]]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $seoName = (string)$row['name'];
            $seoTitle = $seoName . ' | KitsByElbaa';
            $plain = trim(strip_tags((string)($row['description'] ?? '')));
            if ($plain !== '') {
                $seoDesc = mb_strlen($plain) > 160 ? mb_substr($plain, 0, 157) . '…' : $plain;
            }
            $img = trim((string)($row['image'] ?? ''));
            if ($img !== '') {
                if (preg_match('#^https?://#i', $img)) {
                    $seoImage = $img;
                } elseif ($publicBase !== '') {
                    $seoImage = $publicBase . '/' . ltrim(str_replace('\\', '/', $img), '/');
                }
            }
            if ($publicBase !== '') {
                $seoCanonical = $publicBase . '/product.php?' . http_build_query(['slug' => $slugQ]);
            }
            $seoPrice = (float)$row['price'];
        }
    } catch (Throwable $e) {
        // keep defaults
    }
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?></title>
<meta name="description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($seoCanonical !== ''): ?>
<link rel="canonical" href="<?= htmlspecialchars($seoCanonical, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="product">
<meta property="og:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($seoCanonical, ENT_QUOTES, 'UTF-8') ?>">
<?php if ($seoImage !== ''): ?><meta property="og:image" content="<?= htmlspecialchars($seoImage, ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($seoTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($seoDesc, ENT_QUOTES, 'UTF-8') ?>">
<?php endif; ?>
<?php if ($seoName !== '' && $seoPrice !== null && $seoCanonical !== ''): ?>
<?php
$ldProduct = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $seoName,
    'description' => $seoDesc,
    'offers' => [
        '@type' => 'Offer',
        'priceCurrency' => 'EUR',
        'price' => round($seoPrice, 2),
        'availability' => 'https://schema.org/InStock',
        'url' => $seoCanonical,
    ],
];
if ($seoImage !== '') {
    $ldProduct['image'] = [$seoImage];
}
?>
<script type="application/ld+json"><?= json_encode($ldProduct, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
<style>
a{text-decoration:none;color:inherit}
.wrap{max-width:1280px;margin:0 auto;padding:18px}
.top{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px}
.crumbs{font-size:11px;letter-spacing:.08em;text-transform:uppercase;color:#8a857b;margin-bottom:10px}
.crumbs a{color:#6f6b63}
.brand{font-family:var(--font-display);font-weight:700;font-size:28px}
.back{font-size:13px;color:var(--ink3)}
.grid{display:grid;grid-template-columns:1.05fr .95fr;gap:28px;background:#fff;padding:20px;border:1px solid #eaeaea}
.gallery{position:relative}
.main-img-box{position:relative;background:var(--cream2);border:1px solid #e9e9e9;aspect-ratio:1/1;overflow:hidden}
.main-img{width:100%;height:100%;object-fit:contain;display:block;cursor:zoom-in}
.garr{position:absolute;top:50%;transform:translateY(-50%);width:38px;height:38px;border-radius:999px;border:none;background:#fff;box-shadow:0 2px 10px rgba(0,0,0,.16);cursor:pointer}
.garr.prev{left:10px}.garr.next{right:10px}
.thumbs{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-top:10px}
.thumb{border:1px solid #ddd;background:#fff;aspect-ratio:1/1;overflow:hidden;cursor:pointer}
.thumb.on{outline:2px solid var(--ink)}
.thumb img{width:100%;height:100%;object-fit:cover}
.info h1{margin:0 0 8px;font-family:var(--font-display);font-size:40px;line-height:1.05}
.price{font-family:var(--font-display);font-size:34px;font-weight:700;margin-bottom:14px}
.lbl{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#666;margin:14px 0 8px}
.sizes{display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start}
.versie-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:4px}
.versie-btn{border:1.5px solid var(--line);background:#fff;border-radius:8px;padding:9px 16px;cursor:pointer;display:flex;flex-direction:column;align-items:flex-start;gap:1px;font-family:var(--font-body);transition:all .16s;min-width:96px}
.versie-btn:hover{border-color:var(--ink)}
.versie-btn.on{background:var(--ink);border-color:var(--ink)}
.versie-name{font-size:13px;font-weight:700;letter-spacing:.02em;color:var(--ink)}
.versie-btn.on .versie-name{color:var(--cream)}
.versie-price{font-size:12px;font-weight:600;color:var(--ink3)}
.versie-btn.on .versie-price{color:rgba(255,255,255,.72)}
.toast{position:fixed;bottom:28px;left:50%;transform:translateX(-50%) translateY(80px);background:var(--ink);color:var(--cream);padding:12px 22px;border-radius:100px;font-size:13px;font-weight:600;letter-spacing:.01em;z-index:9000;transition:transform .3s cubic-bezier(.4,0,.2,1);white-space:nowrap;box-shadow:var(--shadow-lg);display:inline-flex;align-items:center;gap:9px;max-width:min(90vw,520px)}
.toast.on{transform:translateX(-50%) translateY(0)}
.toast-ico{display:inline-flex;flex-shrink:0}
.toast-ico svg{width:16px;height:16px;display:block}
.toast-msg{overflow:hidden;text-overflow:ellipsis}
.toast--success{background:var(--accent);color:#fff}
.toast--error{background:var(--red);color:#fff}
.sz-wrap{display:inline-flex;flex-direction:column;align-items:center;gap:4px}
.sz-notify-btn{
  border:none;background:transparent;color:var(--accent);font-size:10px;font-weight:700;
  letter-spacing:.05em;text-transform:uppercase;cursor:pointer;padding:0 2px 4px;font-family:inherit
}
.sz-notify-btn:hover{text-decoration:underline}
.stock-notify-panel{margin-top:12px;padding:12px 14px;border:1px solid #ddd;background:#faf9f6;border-radius:8px;display:none}
.stock-notify-panel.on{display:block}
.stock-notify-hint{font-size:12px;color:#444;margin-bottom:8px}
.stock-notify-fb{font-size:12px;margin-top:8px;font-weight:600;min-height:1.2em}
.sz{border:1px solid #ddd;background:#fff;padding:10px 14px;cursor:pointer;position:relative;display:flex;flex-direction:column;align-items:center;gap:2px;font-size:13px;font-weight:600}
.sz.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.sz.out{opacity:.45;cursor:not-allowed;text-decoration:line-through}
.sz.low{border-color:#d97706}
.sz-stock{font-size:10px;font-weight:600;letter-spacing:.04em;color:#d97706;line-height:1}
.sz.on .sz-stock{color:#fcd34d}
.sz.out .sz-stock{color:#991b1b;text-decoration:none}
.inp{width:100%;padding:11px;border:1px solid #ddd;background:#fff}
.qtyrow{display:flex;gap:10px;align-items:center;margin-top:12px}
.pdp-wish-btn{
  width:44px;height:44px;border-radius:50%;border:1px solid rgba(28,26,23,.14);
  background:rgba(255,255,255,.96);color:#a3a3a3;cursor:pointer;display:flex;align-items:center;justify-content:center;
  font-size:18px;line-height:1;box-shadow:0 6px 16px rgba(28,26,23,.1);
  transition:color .2s,border-color .2s,background .2s,transform .15s,box-shadow .2s;
}
.main-img-box .pdp-wish-btn{position:absolute;top:12px;right:12px;z-index:4}
.pdp-wish-btn:hover{border-color:rgba(185,28,28,.35);color:#b91c1c}
.pdp-wish-btn:active{transform:scale(.95)}
.pdp-wish-btn.on{
  background:linear-gradient(180deg,#ef4444,#b91c1c);
  border-color:#b91c1c;color:#fff;
  box-shadow:0 8px 18px rgba(185,28,28,.28);
}
.pdp-wish-fb{margin:8px 0 0;font-size:12px;color:#2d5a27;font-weight:600;line-height:1.4;min-height:0}
.qty{display:flex;border:1px solid #ddd}
.qty button{width:34px;height:34px;border:none;background:#fff;cursor:pointer}
.qty span{width:34px;display:flex;align-items:center;justify-content:center}
.cta{flex:1;background:var(--ink);color:#fff;border:none;padding:12px 16px;font-weight:700;cursor:pointer}
.small{font-size:12px;color:#666;margin-top:8px}
.stock-note{font-size:12px;margin:0 0 12px}
.stock-note.ok{color:#065f46}
.stock-note.low{color:#92400e}
.stock-note.out{color:#991b1b;font-weight:700}
.stock-note.slow{color:#92400e;font-weight:600}
.pdp-trust-row{display:flex;flex-wrap:wrap;gap:8px 14px;margin:14px 0 10px;padding:12px 14px;background:var(--cream2);border:1px solid var(--line);border-radius:10px;font-size:12px;font-weight:600;color:var(--ink2);line-height:1.4}
.pdp-trust-row a{color:var(--ink);text-decoration:underline;text-underline-offset:2px}
.pdp-trust-row a:hover{color:var(--accent)}
.pdp-lever-note{font-size:12px;font-weight:600;color:#065f46;margin:0 0 10px;padding:8px 12px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;display:none}
.pdp-lever-note.show{display:block}
/* KIDS Jersey — zelfde taal als index maattabel (maten-table-wrap + .maten-table) */
.kids-jersey-pdp{margin-top:14px;max-width:100%;min-width:0}
.kids-jersey-pdp .maten-table-wrap{margin:0;background:var(--cream);border:1px solid var(--line);overflow:hidden}
.kids-jersey-pdp .kids-maten-banner{
  text-align:center;background:var(--cream2);border-bottom:1px solid var(--line);
  padding:14px 16px 12px;
}
.kids-jersey-pdp .kids-maten-title{
  font-family:var(--font-display);font-size:clamp(18px,2.6vw,24px);font-weight:700;color:var(--ink);margin:0 0 6px;
}
.kids-jersey-pdp .kids-maten-remark{margin:0;font-size:11px;font-weight:600;color:var(--ink3);line-height:1.45}
.kids-jersey-pdp .kids-maten-scroll{overflow-x:auto;-webkit-overflow-scrolling:touch;position:relative}
.kids-jersey-pdp .maten-scroll-hint{
  display:none;font-size:11px;font-weight:600;color:var(--ink2);text-align:center;
  padding:8px 12px;margin:8px auto 0;border:1px dashed var(--line2);background:var(--cream2);
  border-radius:999px;max-width:max-content;
}
.kids-jersey-pdp .maten-table{width:100%;border-collapse:collapse}
.kids-jersey-pdp .maten-table th{
  padding:11px 14px;font-size:9px;font-weight:700;letter-spacing:.14em;text-transform:uppercase;
  color:var(--ink3);text-align:left;border-bottom:1px solid var(--line);
}
.kids-jersey-pdp .maten-table td{
  padding:11px 14px;font-size:12px;color:var(--ink2);
  border-bottom:1px solid var(--line);transition:background .15s;
}
.kids-jersey-pdp .maten-table tr:last-child td{border-bottom:none}
.kids-jersey-pdp .maten-table tbody tr:hover td{background:var(--cream2)}
.kids-jersey-pdp .maten-table td:first-child{
  font-weight:700;color:var(--ink);position:sticky;left:0;z-index:2;
  background:var(--cream2);box-shadow:1px 0 0 var(--line);
}
.kids-jersey-pdp .maten-table th:first-child{
  position:sticky;left:0;z-index:3;background:var(--cream2);box-shadow:1px 0 0 var(--line);
}
@media(max-width:720px){
  .kids-jersey-pdp .kids-maten-scroll{
    border:1px solid var(--line);border-radius:8px;background:var(--cream);
    overflow-x:auto;-webkit-overflow-scrolling:touch;
    padding-right:0;
    scroll-padding-right:0;
    touch-action:pan-x;
    scrollbar-width:thin;
    scrollbar-color:var(--line2) transparent;
  }
  .kids-jersey-pdp .kids-maten-scroll::-webkit-scrollbar{height:8px;display:block}
  .kids-jersey-pdp .kids-maten-scroll::-webkit-scrollbar-thumb{background:var(--line2);border-radius:999px}
  .kids-jersey-pdp .kids-maten-scroll::-webkit-scrollbar-track{background:transparent}
  .kids-jersey-pdp .kids-maten-scroll .maten-table{
    min-width:640px;
    width:max-content;
    table-layout:auto;
  }
  .kids-jersey-pdp .maten-table th,
  .kids-jersey-pdp .maten-table td{
    padding:10px 12px;
    font-size:11px;
    white-space:nowrap;
    word-break:normal;
    line-height:1.25;
  }
  .kids-jersey-pdp .maten-table td:first-child,
  .kids-jersey-pdp .maten-table th:first-child{
    position:static;
    box-shadow:none;
  }
  .kids-jersey-pdp .maten-scroll-hint{display:block}
}
.sections{margin-top:20px;background:#fff;padding:24px 22px;border:1px solid #ececec;border-radius:6px}
.sec{margin-bottom:22px;padding-bottom:18px;border-bottom:1px solid #f1f1f1}
.sec:last-child{margin-bottom:0;padding-bottom:0;border-bottom:none}
.sec h3{font-size:13px;margin:0 0 10px;text-transform:uppercase;letter-spacing:.12em;color:#111;font-weight:700}
.sec p{margin:0;color:#3a3a3a;line-height:1.75;font-size:15px}
.reco{margin-top:20px;background:var(--white);padding:20px 12px 28px;border:1px solid var(--line);max-width:100%}
.reco-h{
  text-align:center;font-family:var(--font-display);font-size:clamp(22px,4vw,30px);
  font-weight:700;letter-spacing:.03em;color:var(--ink);margin:0 0 20px;line-height:1.2;
}
.reco-strip{
  display:grid;grid-template-columns:44px minmax(0,1fr) 44px;align-items:center;gap:0 10px;max-width:100%;
}
.reco-viewport{
  position:relative;min-width:0;overflow-x:auto;overflow-y:hidden;
  -webkit-overflow-scrolling:touch;scrollbar-width:none;overscroll-behavior-x:contain;
  container-type:inline-size;container-name:reco;
  scroll-snap-type:x proximity;
  scroll-padding-inline:0 14px;
  background:var(--white);
  border-radius:10px;
}
.reco-viewport::-webkit-scrollbar{display:none}
.reco-track{display:flex;gap:16px;padding:4px 0 12px 0;width:max-content;box-sizing:border-box;margin:0}
.reco-track::after{
  content:'';flex:0 0 16px;width:16px;align-self:stretch;flex-shrink:0;
}
.reco-track .card{
  border:1px solid var(--line);background:var(--white);cursor:pointer;display:flex;flex-direction:column;min-height:0;
  text-decoration:none;color:inherit;overflow:hidden;box-shadow:var(--shadow-sm);
  flex:0 0 auto;
  width:min(248px,calc(100vw - 128px));
  max-width:min(268px,calc(100vw - 128px));
  scroll-snap-align:start;
  transition:transform .28s ease,box-shadow .28s ease,border-color .28s ease;
  -webkit-tap-highlight-color:transparent;
}
@supports (width:100cqw){
  .reco-track .card{width:min(268px,calc(100cqw - 24px))}
}
.reco-track .card:focus{outline:none}
.reco-track .card:focus-visible{outline:2px solid var(--accent);outline-offset:3px}
.reco-track .card:hover{
  transform:translateY(-4px);box-shadow:var(--shadow-lg);border-color:var(--line2);
}
.cimg{
  aspect-ratio:4/5;background:linear-gradient(155deg,var(--cream2),var(--cream));
  overflow:hidden;flex-shrink:0;position:relative;
}
.cimg--empty::after{
  content:'';position:absolute;inset:0;opacity:.4;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239a9d95' stroke-width='1.2' stroke-linejoin='round'%3E%3Cpath d='M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:center;background-size:42px 42px;
}
.cimg img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .4s ease}
.reco-track .card:hover .cimg img{transform:scale(1.03)}
.ctxt{padding:14px 16px 18px;flex:1;display:flex;flex-direction:column;gap:8px}
.cn{
  font-family:var(--font-display);font-size:15px;font-weight:600;line-height:1.3;color:var(--ink);
  display:-webkit-box;line-clamp:2;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;min-height:2.6em;
}
.cp{font-family:var(--font-display);font-weight:700;font-size:20px;color:var(--ink);margin-top:auto}
.reco-arr{
  flex-shrink:0;width:42px;height:42px;border-radius:50%;border:1.5px solid var(--line);background:var(--cream2);
  font-size:22px;line-height:1;color:var(--ink);cursor:pointer;z-index:1;
  display:flex;align-items:center;justify-content:center;padding:0;
  transition:opacity .2s,transform .2s,background .2s,border-color .2s,color .2s;
}
.reco-arr:hover:not(:disabled){background:var(--ink);color:var(--cream);border-color:var(--ink)}
.reco-arr:disabled{opacity:.35;cursor:default;pointer-events:none}
.sticky{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #ddd;padding:8px 10px;display:none;gap:8px;z-index:20}
.sticky .cta{padding:10px}
.sticky .cta-wa{background:#25D366}
.sticky .cta-wa:hover{filter:brightness(1.05)}
.pdp-cart-bg{position:fixed;inset:0;background:rgba(0,0,0,.35);opacity:0;pointer-events:none;transition:.2s;z-index:220}
.pdp-cart-bg.on{opacity:1;pointer-events:auto}
.pdp-cart{
  position:fixed;top:0;right:0;bottom:0;width:350px;background:#fff;border-left:1px solid #e6e6e6;
  transform:translateX(100%);transition:.25s ease;z-index:221;display:flex;flex-direction:column;
}
.pdp-cart.on{transform:translateX(0)}
.pdp-cart-head{padding:16px 16px 14px;background:var(--cream);border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;gap:10px;position:relative}
.pdp-cart-back{
  flex-shrink:0;border:none;background:transparent;cursor:pointer;font-family:var(--font-body);
  font-size:13px;font-weight:600;color:var(--accent);padding:8px 6px;min-height:44px;line-height:1.2;text-align:left;
}
.pdp-cart-back:hover{color:#1c1a17}
@media (min-width:769px){
  .pdp-cart-back{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
}
.pdp-cart-title{font-family:var(--font-display);font-size:clamp(18px,4.5vw,34px);line-height:1;color:var(--ink);font-weight:700;flex:1;min-width:0}
.pdp-cart-head .pdp-cart-close{border:1px solid var(--line);background:#fff;color:var(--ink3);font-size:20px;width:34px;height:34px;cursor:pointer;flex-shrink:0}
.pdp-empty-cta{margin-top:14px;padding:12px 20px;font-family:var(--font-body);font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;background:var(--ink);color:#fff;border:none;cursor:pointer;width:100%;max-width:280px}
.pdp-empty-cta:hover{background:#3d3a34}
.pdp-cart-body{padding:12px 14px;overflow:auto;flex:1}
.pdp-empty{color:#777;text-align:center;padding:26px 6px}
.pdp-line{display:flex;gap:10px;padding:10px 0;border-bottom:1px solid #eee}
.pdp-line-media{width:56px;height:68px;border:1px solid #ddd;background:var(--cream2);display:flex;align-items:center;justify-content:center;overflow:hidden}
.pdp-line-media img{width:100%;height:100%;object-fit:cover}
a.pdp-line-media{display:flex;text-decoration:none;color:inherit;cursor:pointer;transition:box-shadow .15s}
a.pdp-line-media:hover{box-shadow:0 0 0 1px var(--line)}
.pdp-line-name{font-size:13px;font-weight:700;color:var(--ink)}
a.pdp-line-name{display:block;text-decoration:none;margin-bottom:2px}
a.pdp-line-name:hover{color:var(--accent)}
.pdp-line-view{font-size:9px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;color:var(--accent);text-decoration:none}
.pdp-line-view:hover{text-decoration:underline}
.pdp-line-meta{font-size:11px;color:#8f9e98;margin-top:4px}
.pdp-line-price{margin-left:auto;font-size:14px;font-weight:700;color:var(--ink)}
.pdp-qty{display:flex;align-items:center;border:1px solid #ddd;margin-top:7px;width:max-content}
.pdp-qty button{width:24px;height:24px;border:none;background:#fff;cursor:pointer}
.pdp-qty span{width:28px;text-align:center;font-size:12px;border-left:1px solid #ddd;border-right:1px solid #ddd}
.pdp-remove{border:none;background:transparent;color:#999;font-size:12px;cursor:pointer;margin-top:6px}
.pdp-cart-foot{padding:12px 14px;border-top:1px solid #eee}
.pdp-coupon{margin-bottom:10px}
.pdp-coupon-row{display:flex;gap:8px}
.pdp-coupon-row input{flex:1;border:1px solid #ddd;padding:9px 10px}
.pdp-coupon-row button{border:none;background:#111;color:#fff;padding:9px 12px;font-size:11px;letter-spacing:.08em;text-transform:uppercase;cursor:pointer}
.pdp-coupon-fb{font-size:11px;margin-top:6px;font-weight:700}
.pdp-coupon-fb.ok{color:#065f46}
.pdp-coupon-fb.err{color:#991b1b}
.pdp-coupon-remove{display:none;margin-top:6px;background:none;border:none;padding:0;font-size:11px;font-weight:700;color:#065f46;cursor:pointer;text-decoration:underline;font-family:inherit}
.pdp-coupon-remove:hover{color:#1c1a17}
.pdp-discount{display:flex;justify-content:space-between;font-size:13px;color:#2d5a27;font-weight:700;margin-bottom:6px}
.pdp-total{display:flex;justify-content:space-between;font-weight:700;margin-bottom:8px;font-size:18px}
.pdp-check{width:100%;border:none;background:#25D366;color:#fff;padding:12px;font-size:14px;letter-spacing:.08em;font-weight:700;cursor:pointer;text-transform:uppercase}
.pdp-note{text-align:center;font-size:12px;margin-top:8px;font-weight:700;color:var(--ink)}
/* ── BACK TO TOP ── */
.back-to-top{position:fixed;bottom:28px;right:28px;width:38px;height:38px;border-radius:50%;background:var(--ink);color:#fff;border:none;cursor:pointer;font-size:16px;display:flex;align-items:center;justify-content:center;opacity:0;transform:translateY(10px);transition:opacity .25s,transform .25s;z-index:140;box-shadow:0 2px 12px rgba(28,26,23,.18)}
.back-to-top.on{opacity:1;transform:translateY(0)}
.back-to-top:hover{background:#3d3a34}
/* ── PAGE LOAD FADE ── */
.wrap{animation:pageFadeIn .45s ease both}
@keyframes pageFadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
/* ── GALLERY CROSSFADE ── */
.main-img{transition:opacity .22s ease}
.main-img.switching{opacity:0}
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.92);display:none;align-items:center;justify-content:center;z-index:500}
.lightbox.on{display:flex}.lightbox img{max-width:92vw;max-height:88vh;object-fit:contain}
.lb-arr{position:absolute;top:50%;transform:translateY(-50%);width:44px;height:44px;border:none;border-radius:50%;background:#fff;cursor:pointer}
.lb-arr.prev{left:18px}.lb-arr.next{right:18px}
.close{position:absolute;top:16px;right:16px;background:#fff;border:none;width:36px;height:36px;border-radius:50%;cursor:pointer}
/* ── FOOTER — shared markup from includes/footer.php (same as every other page) ── */
footer{background:var(--ink);color:rgba(250,248,244,.6);margin-top:28px}
.foot-inner{max-width:1360px;margin:0 auto;padding:64px 48px 40px;display:grid;grid-template-columns:2fr 1fr 1fr 1fr;gap:48px}
.foot-logo{font-family:var(--font-display);font-size:26px;font-weight:700;color:var(--cream);margin-bottom:16px}
footer p,footer a{display:block;font-size:13px;color:rgba(250,248,244,.5);text-decoration:none;margin-bottom:10px;line-height:1.7;transition:color .18s}
footer a:hover{color:var(--cream)}
footer h4{font-family:var(--font-body);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.16em;color:var(--cream);margin-bottom:20px;opacity:.8}
.foot-bottom{border-top:1px solid rgba(250,248,244,.08);padding:20px 48px;max-width:1360px;margin:0 auto;display:flex;justify-content:space-between;font-size:11px;color:rgba(250,248,244,.3);letter-spacing:.06em}
@media(max-width:960px){.foot-inner{grid-template-columns:1fr 1fr;padding:40px 24px 24px;gap:28px}.foot-bottom{padding-left:24px;padding-right:24px}}
@media(max-width:480px){.foot-inner{grid-template-columns:1fr;gap:28px}.foot-bottom{flex-direction:column;gap:6px;text-align:center;padding:16px 24px}}
/* ── TABLET / MOBILE NAV (zelfde als index.html) ── */
@media(max-width:960px){
  .nav-links{display:none;position:absolute;top:100%;left:0;right:0;background:var(--cream);border-bottom:1px solid var(--line);flex-direction:column;padding:8px 20px 14px;gap:0;box-shadow:0 8px 24px rgba(28,26,23,.08);z-index:89;max-height:min(82dvh,82vh);overflow:auto}
  .nav-links li{padding:0}
  .nav-links a{font-size:13px;padding:10px 0;display:block;border-bottom:1px solid var(--line);letter-spacing:.1em}
  .nav-links li:last-child a{border-bottom:none}
  .nav-mega{display:none !important}
  .nav-links .has-mega>.top-link::after{display:none}
  .ham{display:flex}
  .nav-top{
    gap:10px;height:auto;min-height:64px;padding-top:6px;padding-bottom:6px;
    align-items:center;
  }
  .nav-top .logo{
    font-size:clamp(17px,4.2vw,24px);
    flex:1;min-width:0;
    overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  }
  .logo-img{height:50px}
  .ham{align-self:center;flex-shrink:0;min-width:44px;min-height:44px;justify-content:center;box-sizing:border-box}
  .nav-cart{
    padding:8px 11px;
    gap:6px;
    letter-spacing:.05em;
    text-transform:none;
    font-size:11px;
    font-weight:600;
  }
  .nav-cart-label{display:none}
  .nav-cart-ico{font-size:1.28rem;line-height:1}
  .nav-top{padding-left:24px;padding-right:24px}
}
@media(max-width:980px){
  .grid{grid-template-columns:1fr}
  .info h1{font-size:32px}
  .reco-track .card{width:min(268px,calc(100cqw - 24px));max-width:min(268px,calc(100vw - 108px))}
  .sticky{display:flex}
  .wrap{padding:14px 14px 80px}
  .pdp-cart{width:100%}
}
/* ── MOBILE ── */
@media(max-width:600px){
  .nav-top{padding:6px 14px;min-height:56px}
  .logo{font-size:18px;gap:8px}
  .logo-img{height:46px}
  .nav-cart{padding:7px 10px}
  .nav-cart-num{width:16px;height:16px;font-size:9px}
  .info h1{font-size:26px}
  .price{font-size:26px}
  .gallery{position:relative}
  .main-img-box{
    aspect-ratio:auto;
    min-height:340px;
    max-height:72vh;
    display:flex;
    align-items:center;
    justify-content:center;
    padding:8px;
  }
  .main-img{
    width:100%;
    height:100%;
    max-height:calc(72vh - 16px);
    object-fit:contain;
    object-position:center center;
  }
  .garr{
    width:34px;
    height:34px;
    top:auto;
    bottom:10px;
    transform:none;
    opacity:.95;
  }
  .garr.prev{left:10px}
  .garr.next{right:10px}
  .thumbs{grid-template-columns:repeat(4,1fr)}
  .reco{padding:20px 8px 24px}
  .reco-strip{grid-template-columns:40px minmax(0,1fr) 40px;gap:0 8px}
  .reco-arr{width:36px;height:36px;font-size:20px}
  .reco-track .card{width:min(248px,calc(100cqw - 20px));max-width:min(248px,calc(100vw - 100px))}
  .wrap{padding:12px 12px 80px}
}
/* ── SMALL MOBILE ── */
@media(max-width:400px){
  .nav-top{padding:6px 12px}
  .logo{font-size:17px}
  .logo-img{height:42px}
  .nav-cart{padding:7px 9px}
  .info h1{font-size:22px}
  .price{font-size:22px}
  .main-img-box{
    min-height:280px;
    max-height:66vh;
    padding:6px;
  }
  .main-img{max-height:calc(66vh - 12px)}
  .thumbs{grid-template-columns:repeat(3,1fr)}
  .reco-h{font-size:clamp(18px,5.5vw,26px);margin-bottom:16px}
  .reco-track .card{width:min(232px,calc(100cqw - 16px));max-width:min(232px,calc(100vw - 92px))}
  .sz{padding:8px 10px;font-size:12px}
  .qtyrow{flex-direction:column;align-items:stretch}
  .qty{justify-content:center}
  .cta{width:100%;padding:14px}
}
</style>
<link rel="stylesheet" href="css/app.css?v=2">
<link rel="stylesheet" href="css/responsive-global.css?v=15">
<script defer src="js/kbe-ios-helpers.js?v=2"></script>
</head>
<body>
<?php include __DIR__ . '/includes/announce.php'; ?>
<?php $navCartOnclick = 'openPdpCart()'; $navCartNumId = 'cartNProduct'; include __DIR__ . '/includes/nav.php'; ?>
<div class="wrap">
  <div class="top"><a class="back" id="backLink" href="index.html#shop">← Terug naar shop</a></div>

  <div class="grid">
    <div class="gallery">
      <div class="main-img-box" id="mainBox">
        <button type="button" class="garr prev" onclick="shiftImg(-1)" aria-label="Vorige productafbeelding">‹</button>
        <img class="main-img" id="mainImg" alt="Product image">
        <button type="button" class="garr next" onclick="shiftImg(1)" aria-label="Volgende productafbeelding">›</button>
        <button type="button" class="pdp-wish-btn" id="pdpWishBtn" onclick="togglePdpWishlist()" aria-pressed="false" aria-label="Toevoegen aan wishlist" title="Wishlist">♥</button>
      </div>
      <p class="pdp-wish-fb" id="pdpWishFb" hidden></p>
      <div class="thumbs" id="thumbs"></div>
    </div>
    <div class="info">
      <h1 id="pName">Laden…</h1>
      <div class="price" id="pPrice">€0.00</div>
      <p class="stock-note" id="pStock">Op voorraad</p>
      <p id="pdpLeverNote" class="pdp-lever-note" hidden></p>
      <div class="pdp-trust-row" aria-label="Levering en service">
        <span>Verzending vanaf €<?= (int)$cfg['free_shipping_from'] ?> gratis (NL)</span>
        <span>·</span>
        <a href="index.html#faq">Veelgestelde vragen</a>
        <?php if ($pdpWaHref !== ''): ?>
        <span>·</span>
        <a href="<?php echo htmlspecialchars($pdpWaHref, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">WhatsApp</a>
        <?php endif; ?>
      </div>

      <div class="lbl" id="versieLbl" hidden>Versie</div>
      <div class="versie-row" id="versieRow" hidden></div>
      <div class="lbl">Maat</div>
      <div class="sizes" id="sizes"></div>
      <div id="kidsJerseyChartPdp" class="kids-jersey-pdp" hidden>
        <div class="maten-table-wrap">
          <div class="kids-maten-banner">
            <h3 class="kids-maten-title">Maattabel kids jersey</h3>
            <p class="kids-maten-remark">Let op: door rek in de stof kan de afwijking ongeveer 1–2 cm zijn.</p>
          </div>
          <div class="kids-maten-scroll">
            <table class="maten-table">
              <thead>
                <tr>
                  <th>Maat</th>
                  <th>16</th><th>18</th><th>20</th><th>22</th><th>24</th><th>26</th><th>28</th>
                </tr>
              </thead>
              <tbody>
                <tr><td>Leeftijd</td><td>2-3</td><td>4-5</td><td>5-6</td><td>7-8</td><td>8-9</td><td>10-11</td><td>12-13</td></tr>
                <tr><td>Lichaamslengte (cm)</td><td>95-105</td><td>105-115</td><td>115-125</td><td>125-135</td><td>135-145</td><td>145-155</td><td>155-165</td></tr>
                <tr><td>Lengte shirt (cm)</td><td>44</td><td>47</td><td>50</td><td>53</td><td>56</td><td>59</td><td>62</td></tr>
                <tr><td>½ borst (cm)</td><td>35</td><td>37</td><td>39</td><td>41</td><td>43</td><td>45</td><td>47</td></tr>
                <tr><td>Lengte short (cm)</td><td>32</td><td>34</td><td>36</td><td>38</td><td>39</td><td>40</td><td>43</td></tr>
                <tr><td>½ taille (cm)</td><td>20-37</td><td>21-39</td><td>22-41</td><td>23-42</td><td>24-44</td><td>25-47</td><td>26-50</td></tr>
              </tbody>
            </table>
            <p class="maten-scroll-hint" aria-hidden="true">← Veeg voor alle maten →</p>
          </div>
        </div>
      </div>
      <div class="stock-notify-panel" id="stockNotifyPanel">
        <div class="stock-notify-hint" id="stockNotifyHint"></div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
          <input type="email" class="inp" id="stockNotifyEmail" placeholder="jij@voorbeeld.nl" autocomplete="email" style="flex:1;min-width:200px">
          <button type="button" class="cta" style="padding:10px 18px;font-size:12px" onclick="submitStockNotify()">Melding sturen</button>
        </div>
        <p class="stock-notify-fb" id="stockNotifyFb"></p>
      </div>

      <div class="lbl">Bedrukking (max. 15 tekens)</div>
      <input class="inp" id="printName" placeholder="bijv. Palmer" maxlength="15">
      <div class="lbl">Nummer (max. 4 cijfers)</div>
      <input class="inp" id="printNumber" placeholder="bijv. 10" maxlength="4" inputmode="numeric" pattern="[0-9]*">
      <div class="lbl">Badges (optioneel)</div>
      <select class="inp" id="printBadges">
        <option value="">Geen badge</option>
      </select>

      <div class="qtyrow">
        <div class="qty"><button type="button" onclick="chgQty(-1)" aria-label="Aantal verlagen">−</button><span id="qtyV">1</span><button type="button" onclick="chgQty(1)" aria-label="Aantal verhogen">+</button></div>
        <button type="button" class="cta" id="addBtn" onclick="addToCart()">In winkelwagen</button>
      </div>
      <div class="small" id="addMsg">Bedrukking (naam en/of nummer): <strong>één</strong> meerprijs van €<?= number_format((float)$cfg['custom_printing_price'],2,',','') ?> per shirt. Badge/patch (indien gekozen): +€<?= number_format((float)($cfg['badge_extra_price'] ?? 3),2,',','') ?> per shirt. Gratis verzending vanaf €<?= (int)$cfg['free_shipping_from'] ?>.</div>
    </div>
  </div>

  <div class="sections">
    <div class="sec"><h3>Productomschrijving</h3><p id="sDesc">—</p></div>
    <div class="sec"><h3>Pasvorm</h3><p id="sFit">—</p></div>
    <div class="sec"><h3>Maatadvies</h3><p id="sSizeAdvice">—</p></div>
    <div class="sec"><h3>Materiaal</h3><p id="sMaterial">—</p></div>
    <div class="sec"><h3>Verzending</h3><p id="sShipping">—</p></div>
    <div class="sec"><h3>Verzorging</h3><p id="sCare">—</p></div>
  </div>

  <div class="reco">
    <h2 class="reco-h">Anderen kochten ook</h2>
    <div class="reco-strip">
      <button type="button" class="reco-arr reco-arr-prev" id="recoPrev" aria-label="Vorige producten">‹</button>
      <div class="reco-viewport" id="recoViewport">
        <div class="reco-track" id="reco"></div>
      </div>
      <button type="button" class="reco-arr reco-arr-next" id="recoNext" aria-label="Volgende producten">›</button>
    </div>
  </div>
</div>

<div class="sticky">
  <button type="button" class="cta" id="stickyAddBtn" onclick="document.getElementById('addBtn').click()">In winkelwagen</button>
  <button type="button" class="cta cta-wa" id="stickyWaBtn" onclick="openStickyWhatsApp()">WhatsApp</button>
</div>

<div class="pdp-cart-bg" id="pcbg" onclick="closePdpCart()"></div>
<aside class="pdp-cart" id="pcart">
  <div class="pdp-cart-head">
    <button type="button" class="pdp-cart-back" onclick="closePdpCart()" aria-label="Terug naar product">← Terug</button>
    <span class="pdp-cart-title" id="pcartTitle">Mijn artikelen • 0</span>
    <button type="button" class="pdp-cart-close" onclick="closePdpCart()" aria-label="Sluiten">✕</button>
  </div>
  <div class="pdp-cart-body" id="pcartBody"><div class="pdp-empty">Je winkelwagen is leeg.<br><button type="button" class="pdp-empty-cta" onclick="closePdpCart()">Terug naar winkelen</button></div></div>
  <div class="pdp-cart-foot">
    <div class="pdp-coupon">
      <div class="pdp-coupon-row">
        <input id="pcCouponInput" placeholder="Kortingscode">
        <button type="button" onclick="applyPdpCoupon()">Toepassen</button>
      </div>
      <div class="pdp-coupon-fb" id="pcCouponFb"></div>
      <button type="button" id="pcCouponRemoveBtn" class="pdp-coupon-remove" onclick="removePdpCoupon()">Korting wijzigen of verwijderen</button>
    </div>
    <div class="pdp-discount" id="pcDiscount" style="display:none"><span id="pcDiscountLabel">Korting</span><span id="pcDiscountAmt">-€0.00</span></div>
    <div class="pdp-total"><span>Totaal</span><span id="pcartTotal">€0.00</span></div>
    <button type="button" class="pdp-check" onclick="goToCart()" style="display:inline-flex;align-items:center;justify-content:center;gap:8px"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>Naar afrekenen</button>
    <button type="button" class="pdp-note" onclick="closePdpCart()" style="background:none;border:none;cursor:pointer;width:100%;padding:10px 0;font-family:inherit;font-size:12px;color:#888;font-weight:700">← Verder winkelen</button>
  </div>
</aside>

<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button type="button" class="lb-arr prev" onclick="event.stopPropagation();shiftImg(-1);openLightbox()" aria-label="Vorige afbeelding">‹</button>
  <button type="button" class="lb-arr next" onclick="event.stopPropagation();shiftImg(1);openLightbox()" aria-label="Volgende afbeelding">›</button>
  <button type="button" class="close" onclick="closeLightbox();event.stopPropagation()" aria-label="Lightbox sluiten">✕</button>
  <img id="lbImg" alt="Vergrote afbeelding">
</div>

<?php include __DIR__ . '/includes/footer.php'; // shared 4-column footer, identical to the rest of the site ?>

<script>
let CONFIG = {
  whatsapp:'<?= htmlspecialchars($pdpWaDigits, ENT_QUOTES, 'UTF-8') ?>',
  customPrintingPrice: <?= (float)$cfg['custom_printing_price'] ?>,
  badgeExtraPrice: <?= (float)($cfg['badge_extra_price'] ?? 3) ?>
};
function kbeWaDigits() {
  const d = String(CONFIG.whatsapp || '31684446255').replace(/\D/g, '');
  return d || '31684446255';
}
let PRODUCTS = [];
let P = null;
let gallery = [];
let gi = 0;
let qty = 1;
let pickedSize = '';
let pickedVersion = 'fan';
let pdpCart = [];
let __kbePdpCartHistory = 0;
const CART_STORAGE_KEY = 'kbe_cart_main';
/** Zelfde sleutel als index.html / shop.html — array van product-id’s */
const WISHLIST_STORAGE_KEY = 'kbe_wishlist_main';
let wishlist = [];

function isWishlisted(id) {
  return wishlist.includes(Number(id));
}
function saveWishlistState() {
  try { localStorage.setItem(WISHLIST_STORAGE_KEY, JSON.stringify(wishlist)); } catch (_) {}
}
function syncPdpWishlistButton() {
  const btn = document.getElementById('pdpWishBtn');
  if (!btn || !P) return;
  const on = isWishlisted(P.id);
  btn.classList.toggle('on', on);
  btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  btn.setAttribute('aria-label', on ? 'Verwijderen van wishlist' : 'Toevoegen aan wishlist');
}
function togglePdpWishlist() {
  if (!P) return;
  const pid = Number(P.id);
  const i = wishlist.indexOf(pid);
  const on = i < 0;
  if (on) wishlist.push(pid);
  else wishlist.splice(i, 1);
  saveWishlistState();
  syncPdpWishlistButton();
  const fb = document.getElementById('pdpWishFb');
  if (fb) {
    fb.textContent = on
      ? 'Toegevoegd aan je wishlist. Open Wishlist in het menu om alles te zien.'
      : 'Verwijderd uit je wishlist.';
    fb.hidden = false;
    clearTimeout(window.__pdpWishFbT);
    window.__pdpWishFbT = setTimeout(() => {
      fb.hidden = true;
      fb.textContent = '';
    }, 2600);
  }
}

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

// Vaste site-promo: 10% / KITSBYELBA in banner; extra codes via admin + api/coupon_validate.php
async function validateCouponCode(raw) {
  const code = (raw || '').trim();
  if (!code) return { ok: false };
  try {
    const r = await fetch('api/coupon_validate.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ code }),
      credentials: 'same-origin'
    });
    return await r.json();
  } catch (_) {
    return { ok: false, error: 'network' };
  }
}

function couponAppliedLabel(c) {
  if (!c) return '';
  const bit = c.type === 'percent' ? c.value + '%' : '€' + Number(c.value).toFixed(2).replace(/\.00$/, '');
  return 'Kortingscode toegepast — ' + bit + ' korting';
}

function calcPdpDiscount(sub) {
  if (!pdpCoupon) return 0;
  if (pdpCoupon.type === 'percent') return sub * pdpCoupon.value / 100;
  return Math.min(pdpCoupon.value, sub);
}
const COUPON_STORAGE_KEY = 'kbe_coupon_main';
let pdpCoupon = null;

function getStockState(stock){
  const s = Number(stock || 0);
  if (s <= 0) return { key:'out', label:'Niet op voorraad' };
  if (s <= 5) return { key:'low', label:`Nog maar ${s} stuks!` };
  return { key:'ok', label:'Op voorraad' };
}
/** Zelfde `in_voorraad`-logica als shop: alleen bij snelle voorraad groene “op voorraad”-tekst; anders nabestelling 7–12 dagen. */
function getPdpStockState(p){
  if (!p) return { key:'out', label:'Niet op voorraad' };
  const ss = versionStockSizes(p, pickedVersion);
  const units = ss ? Object.values(ss).reduce((a, q) => a + Math.max(0, Number(q) || 0), 0) : Number(p.stock || 0);
  if (units <= 0) return { key:'out', label:'Niet op voorraad' };
  // Op voorraad → snelle levering 1–2 werkdagen; anders nabestelling 7–12 werkdagen.
  if (parseInt(p.in_voorraad, 10) === 1) return getStockState(units);
  return { key:'slow', label:'Nabestelling — levering 7 tot 12 werkdagen' };
}
/** Zelfde logica als shop / index: kids-tenues tonen de KIDS Jersey Size Chart bij maat. */
function isKidsProduct(p){
  if (!p) return false;
  const name = String(p.name || '').toLowerCase();
  const desc = String(p.description || '').toLowerCase();
  if (name.includes('retro kids')) return true;
  if (name.includes('kids kit') || name.includes(' kids ') || /\bkids\b/.test(name)) return true;
  if (/\bkids\b/.test(desc)) return true;
  return false;
}
/**
 * Fan vs player — gelijk aan shop/index: eerst `version` uit admin, daarna tekst (EN/NL).
 * Spelerskits: geen 3XL/4XL op de PDP (zie renderProduct → allSizes).
 */
function detectPdpProductVersion(p) {
  if (!p) return 'fan';
  const v = String(p.version || '').trim().toLowerCase();
  if (v === 'player' || v === 'fan') return v;
  const hay = `${p.name || ''} ${p.description || ''} ${p.fit_info || ''} ${p.size_advice || ''}`.toLowerCase();
  if (
    hay.includes('player version') ||
    hay.includes('player fit') ||
    hay.includes('players version') ||
    /\bplayer\b/.test(hay)
  ) {
    return 'player';
  }
  if (
    hay.includes('spelersversie') ||
    hay.includes('spelerversie') ||
    hay.includes('spelers versie') ||
    hay.includes('speler versie') ||
    hay.includes('spelers kit') ||
    hay.includes('spelerseditie') ||
    hay.includes('spelers editie')
  ) {
    return 'player';
  }
  if (hay.includes('fan version') || hay.includes('fan fit') || /\bfan\b/.test(hay)) return 'fan';
  if (hay.includes('fanversie') || hay.includes('fan versie') || hay.includes('fansversie')) return 'fan';
  return 'fan';
}
/* Fan = basis (price / stock_sizes); Player = aparte prijs + aparte per-maat voorraad. */
function versionStockSizes(p, version){
  if (!p) return null;
  if (version === 'player') {
    const ps = p.player_stock_sizes;
    // Aparte player-voorraad indien ingesteld; anders deelt Player de gewone voorraad.
    if (ps && typeof ps === 'object' && Object.keys(ps).length) return ps;
  }
  return (p.stock_sizes && typeof p.stock_sizes === 'object') ? p.stock_sizes : null;
}
function versionPrice(p, version){
  if (version === 'player' && p && p.player_price != null && p.player_price !== '') return Number(p.player_price);
  return Number((p && p.price) || 0);
}
function productHasPlayer(p){
  if (!p) return false;
  if (p.player_price != null && p.player_price !== '') return true;
  const ps = p.player_stock_sizes;
  return !!(ps && typeof ps === 'object' && Object.values(ps).some(q => Number(q) > 0));
}
function maxQtyForProductSize(p, sizeStr){
  if (!p || !sizeStr) return 0;
  const ss = versionStockSizes(p, pickedVersion);
  if (ss && typeof ss === 'object') {
    if (sizeStr === 'XXL') {
      return Math.max(0, Math.floor(Number(ss.XXL || 0) + Number(ss['2XL'] || 0)));
    }
    if (Object.prototype.hasOwnProperty.call(ss, sizeStr)) {
      return Math.max(0, Math.floor(Number(ss[sizeStr]) || 0));
    }
  }
  return Math.max(0, Math.floor(Number(p.stock) || 0));
}
function productHasSellableStock(p){
  if (!p) return false;
  const ss = versionStockSizes(p, pickedVersion);
  if (ss && typeof ss === 'object' && Object.keys(ss).length) {
    return Object.values(ss).some(q => Number(q) > 0);
  }
  return Number(p.stock || 0) > 0;
}
function pdpPrintingMatch(i, po, pn, pnum, pb){
  return (i.printing_option || 'none') === po &&
    (i.print_name || '') === pn &&
    (i.print_number || '') === pnum &&
    (i.print_badges || '') === pb;
}
function pdpQtyUsedForVariant(pId, sizeStr, po, pn, pnum, pb, version){
  const ver = version || 'fan';
  return pdpCart.reduce((s, i) => {
    if (i.id !== pId || i.size !== sizeStr || (i.version || 'fan') !== ver || !pdpPrintingMatch(i, po, pn, pnum, pb)) return s;
    return s + Number(i.qty || 0);
  }, 0);
}
function clampPdpCartToStock(){
  let changed = false;
  for (let i = pdpCart.length - 1; i >= 0; i--) {
    const item = pdpCart[i];
    const p = PRODUCTS.find(x => x.id === item.id);
    if (!p) continue;
    const max = maxQtyForProductSize(p, item.size);
    if (item.qty > max) {
      item.qty = max;
      changed = true;
    }
    if (item.qty <= 0) {
      pdpCart.splice(i, 1);
      changed = true;
    }
  }
  if (changed) {
    try { localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart)); } catch (_) {}
  }
}
function unitBadgeExtraPdp() {
  const v = Number(CONFIG.badgeExtraPrice);
  return Number.isFinite(v) && v >= 0 ? v : 3;
}
function getPendingPrintFields(){
  const pn = (document.getElementById('printName').value || '').trim().slice(0,15);
  const num = (document.getElementById('printNumber').value || '').trim().replace(/\D/g,'').slice(0,4);
  const badges = (document.getElementById('printBadges').value || '').trim();
  const custom = !!(pn || num || badges);
  return {
    printing_option: custom ? 'custom' : 'none',
    print_name: custom ? pn : '',
    print_number: custom ? num : '',
    print_badges: custom ? badges : ''
  };
}
function getLeagueBadgeForProduct(p){
  const league = String(p?.league || '').toLowerCase();
  if (league.includes('premier')) return 'Premier League';
  if (league.includes('la liga')) return 'La Liga';
  if (league.includes('serie a')) return 'Serie A';
  if (league.includes('bundesliga')) return 'Bundesliga';
  if (league.includes('erediv')) return 'Eredivisie';
  if (league.includes('ligue 1') || league.includes('ligue1')) return 'Ligue 1';
  return '';
}
function renderBadgeOptions(p){
  const sel = document.getElementById('printBadges');
  if (!sel) return;
  const current = (sel.value || '').trim();
  const options = [''];
  const leagueBadge = getLeagueBadgeForProduct(p);
  if (leagueBadge) options.push(leagueBadge);
  options.push('Champions League');
  const unique = Array.from(new Set(options));
  sel.innerHTML = unique.map(v => `<option value="${v}">${v || 'Geen badge'}</option>`).join('');
  if (unique.includes(current)) sel.value = current;
}
function productImgSrc(file){
  if(!file) return '';
  let s = String(file).trim().replace(/\\/g,'/').replace(/^\/+/,'');
  if(/^https?:\/\//i.test(s)) return s;
  const enc = (seg) => encodeURIComponent(seg);
  const prefix = 'uploads/products/';
  while(s.toLowerCase().startsWith(prefix)) s = s.slice(prefix.length);
  if(!s) return '';
  if(s.indexOf('/') !== -1) return prefix + s.split('/').filter(Boolean).map(enc).join('/');
  return prefix + enc(s);
}

function slugify(name){ return (name||'').toLowerCase().replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,''); }
function shareSlug(p){ return `${p.id}-${slugify(p.name)}`; }
function escHtml(s){ return String(s??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function parseIdFromQuery(){
  const s = new URLSearchParams(location.search).get('slug') || '';
  const id = parseInt(s.split('-')[0],10);
  return Number.isFinite(id) ? id : null;
}

// ── BACK TO TOP ──
(function(){
  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'back-to-top';
  btn.title = 'Terug naar boven';
  btn.innerHTML = '↑';
  btn.onclick = () => (typeof kbeScrollToTop === 'function' ? kbeScrollToTop() : window.scrollTo(0, 0));
  document.body.appendChild(btn);
  window.addEventListener('scroll', () => btn.classList.toggle('on', window.scrollY > 300), {passive:true});
})();

Promise.all([
  fetch('api/config.php').then(r=>r.json()).catch(()=>({})),
  fetch('api/products.php?view=detail').then(async r => {
    const j = await r.json().catch(() => []);
    return Array.isArray(j) ? j : [];
  }).catch(() => [])
]).then(async ([cfg, prods])=>{
  Object.assign(CONFIG,cfg||{});
  PRODUCTS = prods || [];
  const id = parseIdFromQuery();
  P = PRODUCTS.find(x=>x.id===id) || PRODUCTS[0] || null;
  if(!P){ document.getElementById('pName').textContent='Product niet gevonden'; return; }
  // Default versie: Fan, tenzij Fan geen voorraad heeft maar Player wel.
  pickedVersion = (function(){
    const fanOk = (P.stock_sizes && typeof P.stock_sizes === 'object')
      ? Object.values(P.stock_sizes).some(q => Number(q) > 0)
      : Number(P.stock || 0) > 0;
    return (!fanOk && productHasPlayer(P)) ? 'player' : 'fan';
  })();
  // Shared cart key with index.html (with fallback migration from legacy key)
  try {
    pdpCart = JSON.parse(localStorage.getItem(CART_STORAGE_KEY) || '[]');
  } catch (_) {
    pdpCart = [];
  }
  if (!Array.isArray(pdpCart) || !pdpCart.length) {
    try {
      const legacy = JSON.parse(localStorage.getItem('kbe_cart') || '[]');
      if (Array.isArray(legacy) && legacy.length) {
        pdpCart = legacy;
        localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
        localStorage.removeItem('kbe_cart');
      }
    } catch (_) {}
  }
  try {
    const wr = localStorage.getItem(WISHLIST_STORAGE_KEY);
    const parsed = wr ? JSON.parse(wr) : [];
    wishlist = Array.isArray(parsed) ? parsed.map(Number).filter(Number.isFinite) : [];
  } catch (_) {
    wishlist = [];
  }
  await restoreCouponState();
  recalcPdpCartLinePrices();
  clampPdpCartToStock();
  renderProduct();
  renderPdpCart();
});

/** Zelfde prijslogica als place-order.php: bedrukking (naam/nummer) + optionele badge. */
function recalcPdpCartLinePrices() {
  if (!Array.isArray(pdpCart) || !PRODUCTS.length) return;
  let changed = false;
  pdpCart.forEach(i => {
    const p = PRODUCTS.find(x => x.id === i.id);
    if (!p) return;
    const opt = (i.printing_option || 'none') === 'custom' ? 'custom' : 'none';
    const pn = String(i.print_name || '').trim();
    const num = String(i.print_number || '').trim();
    const bd = String(i.print_badges || '').trim();
    const printAdd = opt === 'custom' && (pn || num) ? Number(CONFIG.customPrintingPrice || 5) : 0;
    const badgeAdd = opt === 'custom' && bd ? unitBadgeExtraPdp() : 0;
    const expected = Number(p.price) + printAdd + badgeAdd;
    if (Math.abs(Number(i.price) - expected) > 0.005) {
      i.price = expected;
      changed = true;
    }
  });
  if (changed) {
    try { localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart)); } catch (_) {}
  }
}

async function restoreCouponState(){
  try{
    const raw = localStorage.getItem(COUPON_STORAGE_KEY);
    if(!raw) return;
    const parsed = JSON.parse(raw);
    if(!parsed || !parsed.code) return;
    const res = await validateCouponCode(parsed.code);
    if(res.ok){
      pdpCoupon = { code: res.code, type: res.type, value: Number(res.value) };
    } else {
      localStorage.removeItem(COUPON_STORAGE_KEY);
    }
  }catch(_){}
}
function persistCouponState(){
  try{
    if(pdpCoupon) localStorage.setItem(COUPON_STORAGE_KEY, JSON.stringify(pdpCoupon));
    else localStorage.removeItem(COUPON_STORAGE_KEY);
  }catch(_){}
}

function orderedImages(p){
  const map = {1:p.image,2:p.image2,3:p.image3};
  const order = String(p.image_order || '1,2,3').split(',').map(n=>parseInt(n,10)).filter(n=>[1,2,3].includes(n));
  const imgs = order.map(n=>map[n]).filter(Boolean);
  if(!imgs.length){ const fb=[p.image,p.image2,p.image3].filter(Boolean); return fb; }
  return imgs;
}

let stockNotifySize = '';
let stockNotifyCsrf = '';

function openStockNotify(sz) {
  stockNotifySize = sz;
  const panel = document.getElementById('stockNotifyPanel');
  const hint = document.getElementById('stockNotifyHint');
  const fb = document.getElementById('stockNotifyFb');
  if (fb) {
    fb.textContent = '';
    fb.style.color = '';
  }
  if (hint) {
    hint.textContent = 'We mailen je wanneer maat ' + sz + ' weer op voorraad is:';
  }
  if (panel) {
    panel.classList.add('on');
  }
  const em = document.getElementById('stockNotifyEmail');
  if (em) {
    em.focus();
  }
}

async function submitStockNotify() {
  const email = (document.getElementById('stockNotifyEmail').value || '').trim();
  const fb = document.getElementById('stockNotifyFb');
  if (!email || !email.includes('@')) {
    if (fb) {
      fb.style.color = '#991b1b';
      fb.textContent = 'Voer een geldig e-mailadres in.';
    }
    return;
  }
  if (!P || !stockNotifySize) return;
  try {
    const csrf = await ensureStockNotifyCsrf();
    const r = await fetch('api/stock_notify.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ email, product_id: P.id, size: stockNotifySize, csrf_token: csrf }),
      credentials: 'same-origin'
    });
    const j = await r.json();
    if (j.ok) {
      if (fb) {
        fb.style.color = '#065f46';
        fb.textContent = 'Je staat op de lijst — we mailen zodra deze maat terug is.';
      }
    } else {
      if (fb) {
        fb.style.color = '#991b1b';
        fb.textContent = j.error || 'Kon niet opslaan.';
      }
    }
  } catch (e) {
    if (fb) {
      fb.style.color = '#991b1b';
      fb.textContent = 'Netwerkfout — probeer opnieuw.';
    }
  }
}

async function ensureStockNotifyCsrf() {
  if (stockNotifyCsrf) return stockNotifyCsrf;
  const r = await fetch('api/checkout_csrf.php', { credentials: 'same-origin' });
  const j = await r.json();
  if (!r.ok || !j || !j.ok || !j.csrf) throw new Error('csrf');
  stockNotifyCsrf = String(j.csrf);
  return stockNotifyCsrf;
}

function syncPdpAddButtons() {
  if (!P) return;
  const ss = versionStockSizes(P, pickedVersion);
  const totalOut = ss ? Object.values(ss).every(q => Number(q) <= 0) : Number(P.stock || 0) <= 0;
  const addBtn = document.getElementById('addBtn');
  const stickyBtn = document.getElementById('stickyAddBtn');
  addBtn.disabled = totalOut;
  addBtn.textContent = totalOut ? 'Niet op voorraad' : 'In winkelwagen';
  addBtn.style.background = '';
  if (stickyBtn) {
    stickyBtn.disabled = totalOut;
    stickyBtn.textContent = addBtn.textContent;
    stickyBtn.style.background = '';
  }
  const levNote = document.getElementById('pdpLeverNote');
  if (levNote) {
    const quick = parseInt(P.in_voorraad, 10) === 1;
    if (totalOut || !quick) {
      levNote.classList.remove('show');
      levNote.hidden = true;
      levNote.textContent = '';
    } else {
      levNote.textContent = 'Levering meestal binnen 1 à 2 werkdagen (kan per week wisselen).';
      levNote.classList.add('show');
      levNote.hidden = false;
    }
  }
}

function renderVersionToggle(){
  const row = document.getElementById('versieRow');
  const lbl = document.getElementById('versieLbl');
  if (!row || !lbl) return;
  // Fan/Player is overal beschikbaar: toggle altijd tonen.
  lbl.hidden = false; row.hidden = false;
  const opts = [{ key: 'fan', label: 'Fan' }, { key: 'player', label: 'Player' }];
  row.innerHTML = opts.map(o => {
    const on = pickedVersion === o.key ? ' on' : '';
    const price = versionPrice(P, o.key);
    return `<button type="button" class="versie-btn${on}" onclick="pickVersion('${o.key}')">`
      + `<span class="versie-name">${o.label}</span>`
      + `<span class="versie-price">€${price.toFixed(2)}</span>`
      + `</button>`;
  }).join('');
}
function pickVersion(v){
  const nv = (v === 'player') ? 'player' : 'fan';
  if (nv === pickedVersion) return;
  pickedVersion = nv;
  pickedSize = '';        // maat resetten: maten en voorraad verschillen per versie
  qty = 1;
  const qv = document.getElementById('qtyV'); if (qv) qv.textContent = '1';
  renderProduct();
}
function renderProduct(){
  const snp = document.getElementById('stockNotifyPanel');
  if (snp) {
    snp.classList.remove('on');
  }
  stockNotifySize = '';
  const snFb = document.getElementById('stockNotifyFb');
  if (snFb) {
    snFb.textContent = '';
  }

  document.title = `${P.name} — KitsByElbaa`;
  document.getElementById('pName').textContent = P.name;
  document.getElementById('mainImg').alt = P.name;
  document.getElementById('pPrice').textContent = `€${versionPrice(P, pickedVersion).toFixed(2)}`;
  renderVersionToggle();
  const stock = getPdpStockState(P);
  const stockEl = document.getElementById('pStock');
  stockEl.className = 'stock-note ' + stock.key;
  stockEl.textContent = stock.label;
  const ss = versionStockSizes(P, pickedVersion);
  const baseSizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
  /* 2XL = zelfde maat als XXL in voorraad-JSON; één knop, voorraad optellen */
  const fanOnlyLarge = ['3XL', '4XL'];
  const isPlayer = (pickedVersion === 'player');
  const allSizes = ss && !isPlayer
    ? [...baseSizes, ...fanOnlyLarge]
    : baseSizes;
  syncPdpAddButtons();
  renderBadgeOptions(P);
  const kChart = document.getElementById('kidsJerseyChartPdp');
  if (kChart) kChart.hidden = !isKidsProduct(P);

  document.getElementById('sizes').innerHTML = allSizes.map(sz => {
    const qty = ss
      ? (sz === 'XXL' ? (Number(ss.XXL ?? 0) + Number(ss['2XL'] ?? 0)) : Number(ss[sz] ?? 0))
      : (isPlayer ? 0 : (Number(P.stock||0) > 0 ? 99 : 0));
    const isOut = qty <= 0;
    const isLow = !isOut && qty <= 5;
    const stockLabel = isOut ? 'Uit' : isLow ? `${qty} over` : '';
    return `<div class="sz-wrap">
      <button type="button" class="sz${isOut?' out':isLow?' low':''}" onclick="pickSize(this,'${sz}')"
      ${isOut?'disabled':''} data-size="${sz}" data-qty="${qty}">
      ${sz}${stockLabel ? `<span class="sz-stock">${stockLabel}</span>` : ''}
    </button>
    ${isOut ? `<button type="button" class="sz-notify-btn" onclick="openStockNotify('${sz}')">Informeer mij</button>` : ''}
    </div>`;
  }).join('');
  gallery = orderedImages(P);
  gi = 0;
  renderGallery();
  document.getElementById('sDesc').textContent = P.description || 'Nog geen productomschrijving.';
  document.getElementById('sFit').textContent = P.fit_info || 'Standaard pasvorm.';
  document.getElementById('sSizeAdvice').textContent = P.size_advice || 'Bekijk de maattabel op de site. Twijfel je over je maat? App ons gerust via WhatsApp, dan helpen we je persoonlijk de juiste maat te kiezen.';
  document.getElementById('sMaterial').textContent = P.material_info || 'Ademende performance-stof.';
  document.getElementById('sShipping').textContent = P.shipping_info || 'Op voorraad: levering 1–2 werkdagen. Nabestelling: 7–12 werkdagen.';
  document.getElementById('sCare').textContent = P.care_instructions || 'Wassen op 30°C, binnenstebuiten.';
  renderReco();
  syncPdpWishlistButton();
}

function renderGallery(animate){
  const main = document.getElementById('mainImg');
  const t = document.getElementById('thumbs');
  if(!gallery.length){
    main.src = '';
    main.alt = 'Geen afbeelding';
    t.innerHTML = '';
    return;
  }
  const doSwap = () => {
    main.src = productImgSrc(gallery[gi]);
    main.onclick = ()=>openLightbox();
    t.innerHTML = gallery.map((g,idx)=>`<button type="button" class="thumb ${idx===gi?'on':''}" onclick="setImg(${idx})"><img src="${productImgSrc(g)}" alt="View ${idx+1}"></button>`).join('');
    if(animate) { main.classList.remove('switching'); }
  };
  if(animate){ main.classList.add('switching'); setTimeout(doSwap, 220); }
  else doSwap();
}
function setImg(i){ gi=i; renderGallery(true); }
function shiftImg(d){ if(!gallery.length) return; gi = (gi + d + gallery.length) % gallery.length; renderGallery(true); }
function openLightbox(){
  if(!gallery.length) return;
  const lb = document.getElementById('lightbox');
  document.getElementById('lbImg').src = productImgSrc(gallery[gi]);
  lb.classList.add('on');
  document.body.style.overflow = 'hidden';
}
function closeLightbox(){
  document.getElementById('lightbox').classList.remove('on');
  const cartOpen = document.getElementById('pcart')?.classList.contains('on');
  document.body.style.overflow = cartOpen ? 'hidden' : '';
}
function pickSize(el,s){
  if(!P) return;
  const qAvail = Number(el.dataset.qty ?? 0);
  if(qAvail <= 0) return;
  document.querySelectorAll('.sz').forEach(x=>x.classList.remove('on'));
  el.classList.add('on');
  pickedSize = el.dataset.size || s;
  qty = 1;
  document.getElementById('qtyV').textContent = String(qty);
}
function chgQty(d){
  if(!P || !pickedSize) return;
  const pr = getPendingPrintFields();
  const max = maxQtyForProductSize(P, pickedSize);
  const used = pdpQtyUsedForVariant(P.id, pickedSize, pr.printing_option, pr.print_name, pr.print_number, pr.print_badges, pickedVersion);
  const remaining = Math.max(0, max - used);
  if (remaining <= 0) {
    qty = 1;
    document.getElementById('qtyV').textContent = String(qty);
    return;
  }
  let next = qty + d;
  if (next < 1) next = 1;
  if (next > remaining) next = remaining;
  qty = next;
  document.getElementById('qtyV').textContent = String(qty);
}

function pdpToast(msg, type){
  let t = document.getElementById('pdpToastEl');
  if (!t) { t = document.createElement('div'); t.id = 'pdpToastEl'; document.body.appendChild(t); }
  const icons = {
    error:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="12" cy="12" r="9"></circle><path d="M12 8v5M12 16.4v.01"></path></svg>',
    success: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"></path></svg>'
  };
  t.className = 'toast' + (type ? ' toast--' + type : '');
  t.innerHTML = (icons[type] ? `<span class="toast-ico">${icons[type]}</span>` : '') + '<span class="toast-msg"></span>';
  t.querySelector('.toast-msg').textContent = String(msg);
  void t.offsetWidth;
  t.classList.add('on');
  clearTimeout(pdpToast._t);
  pdpToast._t = setTimeout(() => t.classList.remove('on'), 2800);
}
function addToCart(){
  if(!P) return;
  if(!productHasSellableStock(P)){ pdpToast('Dit product is niet op voorraad.', 'error'); return; }
  if(!pickedSize){ pdpToast('Kies eerst een maat.', 'error'); return; }
  const nameEl = document.getElementById('printName');
  const numEl = document.getElementById('printNumber');
  let pn = (nameEl.value || '').trim().slice(0,15);
  const numRaw = (numEl.value || '').trim();
  let num = numRaw.replace(/\D/g,'').slice(0,4);
  if(numRaw && numRaw !== num){ pdpToast('Nummer mag alleen cijfers bevatten (max. 4).', 'error'); return; }
  nameEl.value = pn;
  numEl.value = num;
  let badges = (document.getElementById('printBadges').value || '').trim();
  const custom = !!(pn || num || badges);
  if (!custom) { pn = ''; num = ''; badges = ''; }
  const printing_option = custom ? 'custom' : 'none';
  const maxAllowed = maxQtyForProductSize(P, pickedSize);
  const used = pdpQtyUsedForVariant(P.id, pickedSize, printing_option, pn, num, badges, pickedVersion);
  const remaining = maxAllowed - used;
  const addQty = Math.min(Math.max(1, Number(qty) || 1), Math.max(0, remaining));
  if (addQty <= 0 || remaining <= 0) {
    pdpToast('Onvoldoende voorraad voor deze maat (' + maxAllowed + ' beschikbaar voor deze optie).', 'error');
    return;
  }
  const printAdd = custom && (pn || num) ? Number(CONFIG.customPrintingPrice || 5) : 0;
  const badgeAdd = custom && badges ? unitBadgeExtraPdp() : 0;
  const unit = versionPrice(P, pickedVersion) + printAdd + badgeAdd;
  const cartItem = {
    id: P.id,
    name: P.name,
    league: P.league,
    cat: P.cat,
    emoji: P.emoji || '👕',
    image: P.image || '',
    image2: P.image2 || '',
    image3: P.image3 || '',
    stock: maxAllowed,
    size: pickedSize,
    version: pickedVersion,
    printing_option,
    print_name: pn,
    print_number: num,
    print_badges: badges,
    price: unit,
    qty: addQty
  };
  const sameIdx = pdpCart.findIndex(i =>
    i.id === P.id && i.size === pickedSize && (i.version || 'fan') === pickedVersion && pdpPrintingMatch(i, printing_option, pn, num, badges)
  );
  if (sameIdx >= 0) {
    pdpCart[sameIdx].qty = Math.min(maxAllowed, Number(pdpCart[sameIdx].qty || 0) + addQty);
  } else {
    pdpCart.push(cartItem);
  }
  qty = 1;
  document.getElementById('qtyV').textContent = '1';
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
  renderPdpCart();

  // Button feedback: show "✓ Added!" briefly, then open the cart panel
  const addBtnEl = document.getElementById('addBtn');
  const stickyBtnEl = document.getElementById('stickyAddBtn');
  addBtnEl.textContent = 'Toegevoegd!';
  addBtnEl.style.background = '#2d5a27';
  addBtnEl.disabled = true;
  if (stickyBtnEl) {
    stickyBtnEl.textContent = 'Toegevoegd!';
    stickyBtnEl.style.background = '#2d5a27';
    stickyBtnEl.disabled = true;
  }
  setTimeout(() => {
    syncPdpAddButtons();
    openPdpCart();
  }, 700);
}

function openStickyWhatsApp() {
  const size = document.querySelector('.sz.on')?.textContent?.trim() || '';
  const bits = [
    'Hi! Ik wil deze graag bestellen:',
    P ? P.name : '',
    size ? `(maat ${size})` : ''
  ].filter(Boolean);
  const msg = bits.join(' ');
  const wa = 'https://wa.me/' + kbeWaDigits() + '?text=' + encodeURIComponent(msg);
  window.open(wa, '_blank');
}

document.getElementById('printName').addEventListener('input', function(){
  this.value = this.value.slice(0,15);
});
document.getElementById('printNumber').addEventListener('input', function(){
  this.value = this.value.replace(/\D/g,'').slice(0,4);
});

function goToCart(){
  window.location.href = 'index.html?openCart=1';
}

function openPdpCart(){
  const pc = document.getElementById('pcart');
  const alreadyOpen = pc.classList.contains('on');
  document.getElementById('pcbg').classList.add('on');
  pc.classList.add('on');
  document.body.style.overflow = 'hidden';
  if (!alreadyOpen) {
    try {
      history.pushState({ kbePdpCart: 1 }, '');
      __kbePdpCartHistory++;
    } catch (_) {}
  }
}
window.addEventListener('popstate', function () {
  const pc = document.getElementById('pcart');
  if (pc && pc.classList.contains('on')) {
    document.getElementById('pcbg').classList.remove('on');
    pc.classList.remove('on');
    document.body.style.overflow = '';
    __kbePdpCartHistory = Math.max(0, __kbePdpCartHistory - 1);
  }
});
function closePdpCart(){
  const pc = document.getElementById('pcart');
  if (!pc.classList.contains('on')) return;
  document.getElementById('pcbg').classList.remove('on');
  pc.classList.remove('on');
  document.body.style.overflow = '';
  if (__kbePdpCartHistory > 0) {
    __kbePdpCartHistory--;
    try { history.back(); } catch (_) {}
  }
}
function renderPdpCart(){
  const body = document.getElementById('pcartBody');
  const title = document.getElementById('pcartTitle');
  const totalEl = document.getElementById('pcartTotal');
  const navCount = document.getElementById('cartNProduct');
  const count = pdpCart.reduce((s,i)=>s+Number(i.qty||1),0);
  const subtotal = pdpCart.reduce((s,i)=>s+Number(i.price||0)*Number(i.qty||1),0);
  const discount = calcPdpDiscount(subtotal);
  const total = subtotal - discount;
  title.textContent = `Mijn artikelen • ${count}`;
  if (navCount) navCount.textContent = String(count);
  totalEl.textContent = `€${total.toFixed(2)}`;
  const discRow = document.getElementById('pcDiscount');
  const discAmt = document.getElementById('pcDiscountAmt');
  const cpIn = document.getElementById('pcCouponInput');
  const cpFb = document.getElementById('pcCouponFb');
  if (cpIn) {
    cpIn.value = pdpCoupon ? pdpCoupon.code : '';
    cpIn.disabled = false;
  }
  const pcRm = document.getElementById('pcCouponRemoveBtn');
  if (pcRm) pcRm.style.display = pdpCoupon ? 'inline-block' : 'none';
  if (cpFb) {
    cpFb.className = 'pdp-coupon-fb' + (pdpCoupon ? ' ok' : '');
    cpFb.textContent = pdpCoupon ? couponAppliedLabel(pdpCoupon) : '';
  }
  const discLbl = document.getElementById('pcDiscountLabel');
  if (discLbl) {
    discLbl.textContent = pdpCoupon
      ? ('Korting (' + (pdpCoupon.type === 'percent' ? pdpCoupon.value + '%' : '€' + Number(pdpCoupon.value).toFixed(2)) + ')')
      : 'Korting';
  }
  if (discRow && discAmt) {
    if (discount > 0) {
      discRow.style.display = 'flex';
      discAmt.textContent = `-€${discount.toFixed(2)}`;
    } else {
      discRow.style.display = 'none';
    }
  }
  if(!pdpCart.length){
    body.innerHTML = '<div class="pdp-empty">Je winkelwagen is leeg.<br><button type="button" class="pdp-empty-cta" onclick="closePdpCart()">Terug naar winkelen</button></div>';
    return;
  }
  body.innerHTML = pdpCart.map((i, idx) => {
    const img = i.image || i.image2 || i.image3;
    const slug = i.id != null ? shareSlug(i) : '';
    const href = slug ? `product.php?slug=${encodeURIComponent(slug)}` : '';
    const mediaInner = img ? `<img src="${productImgSrc(img)}" alt="">` : `<span class="pdp-line-noimg" aria-hidden="true"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#9a9d95" stroke-width="1.3" stroke-linejoin="round"><path d="M4 4l4-2 4 2 4-2 4 2v4l-3 1v11H7V9L4 8z"></path></svg></span>`;
    const mediaBlock = href
      ? `<a class="pdp-line-media" href="${href}" title="Product bekijken">${mediaInner}</a>`
      : `<div class="pdp-line-media">${mediaInner}</div>`;
    const nameBlock = href
      ? `<a class="pdp-line-name" href="${href}">${escHtml(i.name)}</a><div><a class="pdp-line-view" href="${href}">Product bekijken</a></div>`
      : `<div class="pdp-line-name">${escHtml(i.name)}</div>`;
    return `<div class="pdp-line">
      ${mediaBlock}
      <div>
        ${nameBlock}
        <div class="pdp-line-meta">${i.version==='player' ? 'Player · ' : ''}Maat: ${i.size}${i.printing_option==='custom' ? ' · ' + (i.print_name||'') + (i.print_number ? ' #' + i.print_number : '') + (i.print_badges ? ' · ' + escHtml(i.print_badges) : '') : ''}</div>
        <div class="pdp-qty">
          <button type="button" onclick="pdpChQ(${idx},-1)" aria-label="Aantal verlagen">−</button>
          <span>${i.qty}</span>
          <button type="button" onclick="pdpChQ(${idx},1)" aria-label="Aantal verhogen">+</button>
        </div>
        <button type="button" class="pdp-remove" onclick="pdpRemove(${idx})">Verwijderen</button>
      </div>
      <div class="pdp-line-price">€${(Number(i.price||0)*Number(i.qty||1)).toFixed(2)}</div>
    </div>`;
  }).join('');
}

async function applyPdpCoupon(){
  const input = document.getElementById('pcCouponInput');
  const fb = document.getElementById('pcCouponFb');
  const code = (input.value || '').trim();
  if(!code){
    fb.className = 'pdp-coupon-fb err';
    fb.textContent = 'Voer een kortingscode in.';
    return;
  }
  fb.className = 'pdp-coupon-fb';
  fb.textContent = 'Even geduld…';
  const res = await validateCouponCode(code);
  if(res.ok){
    pdpCoupon = { code: res.code, type: res.type, value: Number(res.value) };
    persistCouponState();
    fb.className = 'pdp-coupon-fb ok';
    fb.textContent = couponAppliedLabel(pdpCoupon);
    input.value = res.code;
  } else {
    pdpCoupon = null;
    persistCouponState();
    fb.className = 'pdp-coupon-fb err';
    fb.textContent = (res.error === 'network' ? 'Kon code niet controleren.' : (res.error || 'Ongeldige kortingscode.'));
  }
  renderPdpCart();
}

function removePdpCoupon() {
  pdpCoupon = null;
  persistCouponState();
  const input = document.getElementById('pcCouponInput');
  if (input) { input.value = ''; input.disabled = false; }
  const fb = document.getElementById('pcCouponFb');
  if (fb) { fb.className = 'pdp-coupon-fb'; fb.textContent = ''; }
  renderPdpCart();
}

function pdpChQ(idx,delta){
  if (idx < 0 || idx >= pdpCart.length) return;
  const item = pdpCart[idx];
  const p = PRODUCTS.find(x => x.id === item.id) || P;
  const max = p ? maxQtyForProductSize(p, item.size) : Number(item.stock) || 0;
  let n = Number(item.qty || 1) + delta;
  if (n > max) n = max;
  if (n <= 0) pdpCart.splice(idx, 1);
  else pdpCart[idx].qty = n;
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
  renderPdpCart();
}

function pdpRemove(idx){
  if (idx < 0 || idx >= pdpCart.length) return;
  pdpCart.splice(idx,1);
  localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(pdpCart));
  renderPdpCart();
}

function renderReco(){
  const score = (x) => {
    let s = 0;
    if (x.cat && P.cat && x.cat === P.cat) s += 3;
    if (x.league && P.league && x.league === P.league) s += 2;
    if (x.badge === 'hot') s += 1;
    return s;
  };
  const list = PRODUCTS
    .filter(x=>x.id!==P.id)
    .map(x=>({x,s:score(x)}))
    .sort((a,b)=> b.s - a.s || a.x.price - b.x.price)
    .map(v=>v.x)
    .slice(0, 10);
  const recoEl = document.querySelector('.reco');
  const track = document.getElementById('reco');
  if (!track) return;
  if (!list.length) {
    if (recoEl) recoEl.style.display = 'none';
    return;
  }
  if (recoEl) recoEl.style.display = '';
  const priceNl = (n) => '€' + Number(n).toFixed(2).replace('.', ',');
  track.innerHTML = list.map(x=>{
    const safeName = escHtml(x.name);
    const slug = encodeURIComponent(shareSlug(x));
    const hasImg = orderedImages(x).filter(Boolean).length > 0;
    const imgTag = hasImg
      ? `<img class="reco-img" alt="${safeName}" loading="lazy" decoding="async" sizes="(max-width:600px) 72vw, 268px">`
      : '';
    return `<a class="card" href="product.php?slug=${slug}">
      <div class="cimg${hasImg ? '' : ' cimg--empty'}">${imgTag}</div>
      <div class="ctxt"><div class="cn">${safeName}</div><div class="cp">${priceNl(x.price)}</div></div>
    </a>`;
  }).join('');
  bindRecoCardImages(list);
  track.querySelectorAll('.cimg img.reco-img').forEach(el => {
    el.addEventListener('load', () => { window.requestAnimationFrame(() => { window.requestAnimationFrame(updateRecoArrows); }); }, { once: true });
  });
  initRecoSlider();
}

/** Horizontale strip + lazy: eerste bron faalde vaak; probeer image / image2 / image3. */
function bindRecoCardImages(list) {
  const track = document.getElementById('reco');
  if (!track) return;
  list.forEach((p, i) => {
    const card = track.children[i];
    if (!card) return;
    const cimg = card.querySelector('.cimg');
    const img = cimg && cimg.querySelector('img.reco-img');
    const urls = orderedImages(p).filter(Boolean).map(u => productImgSrc(u)).filter(Boolean);
    if (!cimg) return;
    if (!urls.length) {
      cimg.classList.add('cimg--empty');
      return;
    }
    if (!img) {
      cimg.classList.add('cimg--empty');
      return;
    }
    let idx = 0;
    img.onerror = function recoImgErr() {
      idx += 1;
      if (idx < urls.length) {
        this.src = urls[idx];
        return;
      }
      this.remove();
      cimg.classList.add('cimg--empty');
    };
    img.onload = function() {
      cimg.classList.remove('cimg--empty');
    };
    img.src = urls[0];
  });
}

function updateRecoArrows(){
  const vp = document.getElementById('recoViewport');
  const prev = document.getElementById('recoPrev');
  const next = document.getElementById('recoNext');
  if (!vp || !prev || !next) return;
  const cards = vp.querySelectorAll('.reco-track .card');
  const max = Math.max(0, vp.scrollWidth - vp.clientWidth);
  const left = vp.scrollLeft;
  prev.disabled = left <= 2;
  next.disabled = cards.length ? left >= max - 2 : true;
}

let __recoSliderBound = false;
function initRecoSlider(){
  const vp = document.getElementById('recoViewport');
  const prev = document.getElementById('recoPrev');
  const next = document.getElementById('recoNext');
  if (!vp || !prev || !next) return;

  const gap = 16;

  function go(dir){
    const cards = vp.querySelectorAll('.reco-track .card');
    if (!cards.length) return;
    const maxScroll = Math.max(0, vp.scrollWidth - vp.clientWidth);
    const w = cards[0].getBoundingClientRect().width;
    const step = w + gap;
    let target = vp.scrollLeft + dir * step;
    target = Math.max(0, Math.min(maxScroll, target));
    if (typeof kbeScrollElementTo === 'function') {
      kbeScrollElementTo(vp, target, 0, true);
    } else {
      vp.scrollTo({ left: target, behavior: 'smooth' });
    }
    window.requestAnimationFrame(() => window.requestAnimationFrame(updateRecoArrows));
  }

  if (!__recoSliderBound) {
    __recoSliderBound = true;
    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));
    vp.addEventListener('scroll', updateRecoArrows, { passive: true });
    window.addEventListener('resize', () => { window.requestAnimationFrame(updateRecoArrows); });
  }
  window.requestAnimationFrame(() => {
    vp.scrollLeft = 0;
    window.requestAnimationFrame(() => {
      vp.scrollLeft = 0;
      updateRecoArrows();
    });
  });
}

// Smart back link — goes to previous page if it was our site, otherwise falls back to index.html
(function(){
  const back = document.getElementById('backLink');
  if (!back) return;
  const ref = document.referrer;
  if (ref && ref.includes('index.html')) {
    back.href = ref;
  }
})();

document.addEventListener('keydown', (e) => {
  if (e.key === 'ArrowLeft') shiftImg(-1);
  if (e.key === 'ArrowRight') shiftImg(1);
  if (e.key === 'Escape') closeLightbox();
});
</script>
</body>
</html>









