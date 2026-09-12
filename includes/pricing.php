<?php
declare(strict_types=1);

/**
 * Pure money math for the checkout — no DB, no I/O, no globals.
 * Extracted from place-order.php so the pricing rules can be unit-tested.
 * Elke functie is deterministisch: zelfde input → zelfde output.
 */

/**
 * Bereken de korting van één coupon over een subtotaal.
 *
 * - type 'percent': waarde is een percentage, geklemd op 0–100 zodat een
 *   verkeerd geconfigureerde code nooit méér dan 100% kan geven.
 * - elk ander type: waarde is een vast bedrag in euro's.
 *
 * De korting wordt op 2 decimalen afgerond en kan de bestelling nooit
 * negatief/gratis maken: hij is nooit groter dan het subtotaal.
 */
function kits_coupon_discount(float $subtotal, string $type, float $value): float
{
    if ($type === 'percent') {
        $pct = max(0.0, min(100.0, $value));
        $discount = round($subtotal * $pct / 100, 2);
    } else {
        $discount = round($value, 2);
    }

    $cap = max(0.0, $subtotal);
    return max(0.0, min($discount, $cap));
}

/**
 * Verzendkosten: gratis vanaf de drempel, anders het vaste tarief.
 *
 * De drempel wordt getoetst op het subtotaal VÓÓR korting: wie voor ≥ €drempel
 * aan producten in de mand heeft, houdt gratis verzending ook als een kortingscode
 * het te betalen bedrag onder de drempel duwt. Callers geven dus het onafgeronde
 * productsubtotaal door, niet het bedrag ná coupon.
 */
function kits_shipping_for(float $subtotalBeforeDiscount, float $freeFrom, float $shippingCost): float
{
    return $subtotalBeforeDiscount >= $freeFrom ? 0.0 : $shippingCost;
}

/**
 * Eindtotaal: (subtotaal − korting, minimaal 0) + verzendkosten.
 */
function kits_order_total(float $subtotal, float $discount, float $shipping): float
{
    $discountedSub = max(0.0, $subtotal - $discount);
    return $discountedSub + $shipping;
}
