<?php
declare(strict_types=1);

// Run: docker compose run --rm --no-deps glue php tests/merge_test.php
require __DIR__ . '/../src/sync.php';

$owners = MAP['customer']['owner'];
$base = ['name' => 'Acme', 'email' => 'a@x', 'address' => 'Street 1', 'city' => 'Linz'];

// in sync: nothing to do
$r = merge($base, $base, $base, $owners);
assert($r['erp'] === [] && $r['wms'] === [] && $r['conflicts'] === []);

// lost-update case: ERP changed email, WMS changed address -> both survive, no conflict
$r = merge($base, [...$base, 'email' => 'new@x'], [...$base, 'address' => 'Dock 3'], $owners);
assert($r['erp'] === ['address' => 'Dock 3']);
assert($r['wms'] === ['email' => 'new@x']);
assert($r['conflicts'] === []);

// owner wins: WMS edited an ERP-owned field -> reverted in the WMS
$r = merge($base, $base, [...$base, 'name' => 'Typo'], $owners);
assert($r['wms'] === ['name' => 'Acme'] && $r['erp'] === []);

// true conflict on a shared field -> nobody is written, base kept, conflict reported
$r = merge($base, [...$base, 'city' => 'Wien'], [...$base, 'city' => 'Graz'], $owners);
assert($r['conflicts'] === ['city' => ['Linz', 'Wien', 'Graz']]);
assert($r['erp'] === [] && $r['wms'] === [] && $r['merged']['city'] === 'Linz');

// both sides made the same change -> in sync
$r = merge($base, [...$base, 'city' => 'Wien'], [...$base, 'city' => 'Wien'], $owners);
assert($r['conflicts'] === [] && $r['merged']['city'] === 'Wien');

// stock is WMS-owned; numbers normalise (12.50 == 12.5)
$p = merge([], canon('product', 'erp', ['SKU' => 'S', 'DESCR' => 'Bolt', 'PRICE' => '12.50', 'QTY_ON_HAND' => 120]),
    canon('product', 'wms', ['sku' => 'S', 'description' => 'Bolt', 'unitPrice' => 12.5, 'onHand' => 112]), MAP['product']['owner']);
assert($p['erp'] === ['stock' => '112'] && $p['wms'] === []);

// mapping round-trip
assert(native('customer', 'wms', ['address' => 'Dock 3']) === ['shipAddress' => 'Dock 3']);

// replay check: only what the message carries is compared; an older value means stale
$wmsNow = canon('product', 'wms', ['sku' => 'S', 'description' => 'Bolt', 'unitPrice' => 12.5, 'onHand' => 107]);
assert(isCurrent(['stock' => norm(107)], $wmsNow));
assert(!isCurrent(['stock' => norm(112)], $wmsNow));
assert(isCurrent(['code' => 'S', 'name' => null], $wmsNow)); // null = field not sent

echo "merge tests passed\n";
