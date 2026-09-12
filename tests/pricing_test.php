<?php
declare(strict_types=1);

// Checkout money math — coupons, verzendkosten en eindtotaal.
require_once __DIR__ . '/../includes/pricing.php';

test('coupon: procentkorting rekent en rondt af op 2 decimalen', function (): void {
    // 10% van 49,99 = 4,999 → 5,00
    assert_eq(5.00, kits_coupon_discount(49.99, 'percent', 10.0));
});

test('coupon: vast bedrag wordt afgerond op 2 decimalen', function (): void {
    assert_eq(7.50, kits_coupon_discount(100.0, 'fixed', 7.499));
});

test('coupon: percentage boven 100 wordt geklemd op 100 (max = subtotaal)', function (): void {
    assert_eq(80.0, kits_coupon_discount(80.0, 'percent', 250.0));
});

test('coupon: vaste korting kan het subtotaal nooit overschrijden', function (): void {
    // €50 korting op een bestelling van €30 → hooguit €30 (nooit gratis/negatief)
    assert_eq(30.0, kits_coupon_discount(30.0, 'fixed', 50.0));
});

test('coupon: negatieve waarde geeft geen korting', function (): void {
    assert_eq(0.0, kits_coupon_discount(40.0, 'fixed', -10.0));
});

test('verzendkosten: gratis op of boven de drempel', function (): void {
    assert_eq(0.0, kits_shipping_for(40.0, 40.0, 4.99), 'precies op de drempel = gratis');
    assert_eq(0.0, kits_shipping_for(59.95, 40.0, 4.99));
});

test('verzendkosten: onder de drempel het vaste tarief', function (): void {
    assert_eq(4.99, kits_shipping_for(39.99, 40.0, 4.99));
});

test('totaal: subtotaal − korting + verzendkosten', function (): void {
    // 60 (≥ 40 → gratis verzending), 60 − 10 = 50 → 50
    $sub = 60.0;
    $disc = kits_coupon_discount($sub, 'fixed', 10.0);
    $ship = kits_shipping_for($sub, 40.0, 4.99);
    assert_eq(50.0, kits_order_total($sub, $disc, $ship));
});

test('verzendkosten: gratis-drempel geldt VÓÓR korting — een code sloopt gratis verzending niet', function (): void {
    // Product-subtotaal 45 (≥ 40 → gratis), ook al duwt de €10-code het te betalen
    // bedrag naar 35: 45 − 10 = 35, geen verzendkosten → 35,00
    $sub = 45.0;
    $disc = kits_coupon_discount($sub, 'fixed', 10.0);
    $ship = kits_shipping_for($sub, 40.0, 4.99);
    assert_eq(0.0, $ship, 'subtotaal ≥ drempel → gratis, ongeacht de korting');
    assert_eq(35.0, kits_order_total($sub, $disc, $ship));
});
