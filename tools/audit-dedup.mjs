import fs from 'fs';
// Gate G1: the 32 genuinely-shared functions live ONLY in js/shop-core.js,
// and are gone from BOTH inline scripts. (Grid/filter/banner/hero stay inline
// per page by design — divergente filter-architectuur, zie NOTES.md.)
const MERGE = ['addToCart','renderCart','mergeCartItem','clampCartToStock','getMaxQtyForCartItem','maxQtyForProductSize','getStockState','productHasSellableStock','calcDiscount','couponAppliedLabel','setCartItemSize','chQ','showConfirm','getProductSearchScore','applyNavSearch','setSearchTerm','initRevealAnimations','initKbeMobileTapFixes','setGalleryMainImage','switchMaten','refreshDetailDrawerPrice','openDetail','versionPrice','productHasPlayer','productHasSize','versionStockSizes','applyCoupon','applyCouponFromCart','ensureCheckoutCsrf','placeOrder','restoreCouponState','validateCouponCode'];

function inlineScript(html){
  const blocks = html.match(/<script>[\s\S]*?<\/script>/g) || [];
  return blocks.map(b=>b.replace(/^<script>/,'').replace(/<\/script>$/,'')).join('\n');
}
const defined = (src, name) =>
  new RegExp('^(?:async\\s+)?function\\s+'+name+'\\s*\\(', 'm').test(src);

const idx  = inlineScript(fs.readFileSync('index.html','utf8'));
const shop = inlineScript(fs.readFileSync('shop.html','utf8'));
const core = fs.readFileSync('js/shop-core.js','utf8');

let stillInline = [], missingFromCore = [];
for (const n of MERGE){
  if (defined(idx,n) || defined(shop,n)) stillInline.push(n);
  if (!defined(core,n)) missingFromCore.push(n);
}
console.log('DUP_SHARED=' + stillInline.length);
if (stillInline.length) console.log('  nog inline:', stillInline.join(', '));
if (missingFromCore.length) console.log('  MIST in core:', missingFromCore.join(', '));
process.exit(stillInline.length===0 && missingFromCore.length===0 ? 0 : 1);
