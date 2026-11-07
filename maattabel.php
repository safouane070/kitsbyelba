<?php
declare(strict_types=1);
$cfg = require __DIR__ . '/config.php';

$infoTitle = 'Maattabel voetbalshirts — fan, player en kids | KitsByElbaa';
$infoDesc = 'Vind je maat: snelle gids op lengte en gewicht, plus de volledige maattabel in cm voor fan version, player version en kids.';
$infoPath = 'maattabel.php';

include __DIR__ . '/includes/info_top.php';
?>
  <h1>Maattabel</h1>
  <p class="info-lede">Twijfel je tussen twee maten? App ons gerust, dan denken we mee.</p>
  <?php include __DIR__ . '/includes/sizeguide.php'; ?>
  <p class="info-more"><a href="shop.html">Bekijk alle tenues</a></p>
<?php include __DIR__ . '/includes/info_bottom.php'; ?>
