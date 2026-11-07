<?php
declare(strict_types=1);
$cfg = require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/site_settings.php';

$eur = static fn($v) => number_format((float)$v, 2, ',', '');
$free = (int)($cfg['free_shipping_from'] ?? 40);

// Kortingsantwoord komt uit de admin-instellingen (zelfde bron als de announce-balk)
$promoFaq = kits_site_settings_defaults()['promo_faq_answer'];
try {
    $promoFaq = kits_load_site_settings(kits_pdo($cfg))['promo_faq_answer'] ?: $promoFaq;
} catch (Throwable $e) {
    // DB onbereikbaar → standaardtekst
}
$promoFaq = kits_sanitize_promo_html((string)$promoFaq);

/** @var array<int,array{0:string,1:string}> vraag → antwoord (HTML, alleen vaste opmaak) */
$faqs = [
    ['Hoe plaats ik een bestelling?', 'Kies je tenue en maat en klik op <strong>Bestellen via WhatsApp</strong>. Vul je gegevens in; je hoort snel van ons.'],
    ['Hoe betaal ik?', 'Betaling verloopt via <strong>Tikkie</strong>. Na je bestelling via WhatsApp sturen we je een betaallink die je gewoon in je browser opent en betaalt.'],
    ['Wat is de levertijd?', '<strong>Op voorraad:</strong> meestal <strong>1–2 werkdagen</strong>. <strong>Nabestelling:</strong> <strong>7–12 werkdagen</strong>. Voor de actuele status kun je altijd WhatsAppen.'],
    ['Wat zijn de verzendkosten?', 'Verzenden kost <strong>€' . $eur($cfg['shipping_cost'] ?? 4.99) . '</strong> via PostNL. Vanaf <strong>€' . $free . '</strong> is verzending gratis, automatisch bij afrekenen.'],
    ['Is er een kortingscode?', $promoFaq],
    ['Hoe volg ik mijn pakket?', 'Zodra je bestelling verstuurd is, sturen we de <strong>PostNL track &amp; trace</strong> via WhatsApp.'],
    ['Zijn dit originele shirts?', 'Het zijn <strong>replica’s</strong> met dezelfde uitstraling als de officiële tenues, tegen een scherpere prijs.'],
    ['Kloppen alle logo’s op het shirt?', 'Ja, logo’s, clubbadges en sponsors worden <strong>correct en nauwkeurig</strong> aangebracht, zoals op de officiële tenues.'],
    ['Verschil fan- en player version?', 'De <strong>fan version</strong> heeft een ruimere pasvorm voor dagelijks dragen. De <strong>player version</strong> valt slanker: neem vaak één maat groter. Zie de <a href="maattabel.php">maattabel</a>.'],
    ['Kan ik naam en nummer laten bedrukken?', 'Ja. Kies <strong>bedrukking</strong> bij je bestelling. Naam en/of nummer samen is één vaste meerprijs van <strong>+€' . $eur($cfg['custom_printing_price'] ?? 5) . ' per shirt</strong>. Een <strong>badge/patch</strong> (Champions League, eigen competitie, enz.) kost <strong>+€' . $eur($cfg['badge_extra_price'] ?? 3) . '</strong> extra per shirt.'],
    ['Kan ik retourneren?', 'Wij accepteren geen retouren. Uitzondering: als er een fout aan onze kant is (bijv. verkeerd product gestuurd), lossen we dat gratis op.'],
    ['Hoe neem ik contact op?', 'We zijn <strong>bereikbaar via WhatsApp</strong>. Stuur een bericht, we reageren zo snel mogelijk.'],
];

$infoTitle = 'Veelgestelde vragen | KitsByElbaa';
$infoDesc = 'Antwoorden over bestellen, betalen met Tikkie, levertijd, verzendkosten, bedrukking en maten bij KitsByElbaa.';
$infoPath = 'faq.php';
$infoLd = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(static fn($f) => [
        '@type' => 'Question',
        'name' => $f[0],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => trim(html_entity_decode(strip_tags($f[1]), ENT_QUOTES, 'UTF-8'))],
    ], $faqs),
];
$wa = preg_replace('/\D/', '', (string)($cfg['whatsapp'] ?? '31684446255')) ?: '31684446255';

include __DIR__ . '/includes/info_top.php';
?>
  <h1>Veelgestelde vragen</h1>
  <p class="info-lede">Over bestellen, betalen, levering en maten.</p>

  <div class="faq-list">
<?php foreach ($faqs as $f): ?>
    <details class="faq-item">
      <summary><?= htmlspecialchars($f[0], ENT_QUOTES, 'UTF-8') ?></summary>
      <div><?= $f[1] /* vaste tekst hierboven of gesanitized via kits_sanitize_promo_html */ ?></div>
    </details>
<?php endforeach; ?>
  </div>

  <div class="info-cta">
    <p><strong>Staat je vraag er niet bij?</strong>App ons, we reageren zo snel mogelijk.</p>
    <a class="info-wa" href="https://wa.me/<?= htmlspecialchars($wa, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>Stuur ons een WhatsApp</a>
  </div>
<?php include __DIR__ . '/includes/info_bottom.php'; ?>
