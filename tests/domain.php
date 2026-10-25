<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use ZalaStore\Problem;
use ZalaStore\Service;
use ZalaStore\Store;

$checks = 0;
function check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}
function problem(int $status, callable $action): void
{
    try {
        $action();
        throw new RuntimeException('Expected HTTP/domain status ' . $status);
    } catch (Problem $error) {
        check($error->status === $status, 'Correct domain error status');
    }
}
function keyFor(string $value): string
{
    return hash('sha256', $value);
}

$parent = realpath($argv[1] ?? '');
if ($parent === false || !is_dir($parent) || count($argv) !== 2) {
    fwrite(STDERR, "Pemakaian: php -n tests/domain.php DIREKTORI_TEMP_UJI_YANG_SUDAH_ADA\n");
    exit(2);
}
$directory = $parent . '/zala-unit-' . bin2hex(random_bytes(8));
$store = new Store($directory);
$service = new Service($store);
$password = 'Unit-' . bin2hex(random_bytes(14));

check(count($service->products()) === 8, 'Eight source products load without a legacy dataset');
check(!file_exists($directory . '/data.json'), 'A catalogue read does not silently create a data snapshot');
problem(422, fn() => $service->register('ab', $password));
problem(422, fn() => $service->register('valid', 'short'));
problem(422, fn() => $service->register('valid', str_repeat('é', 37)));
problem(422, fn() => $service->register('valid', str_repeat('a', 12) . "\0"));
$alice = $service->register('  Alice  ', $password);
$bob = $service->register('bob', $password);
$admin = $service->register('operator', $password, true);
check($alice['username'] === 'alice' && $alice['role'] === 'customer', 'Registration normalizes username and defaults to customer');
check(!array_key_exists('password_hash', $alice), 'Public account response omits password hash');
$raw = file_get_contents($directory . '/data.json');
check(!str_contains($raw, $password), 'Generated password is never persisted in plaintext');
$hashes = $store->read(fn(array $data): array => array_column($data['users'], 'password_hash'));
check(count(array_unique($hashes)) === 3, 'Same password receives independent salts');
check($service->authenticate('ALICE', $password)['id'] === $alice['id'], 'Real bcrypt login works');
problem(401, fn() => $service->authenticate('alice', 'incorrect'));
problem(401, fn() => $service->authenticate('missing', $password));
problem(409, fn() => $service->register('ALICE', $password));
problem(403, fn() => $service->summary($alice['id']));
check($service->summary($admin['id'])['total_sku'] === 8, 'Summary reloads actual server role');

foreach ([null, [], 'cart', [['id' => 'prd-101', 'qty' => 0]], [['id' => 'prd-101', 'qty' => '1']],
    [['id' => 'prd-101', 'qty' => 1.5]], [['id' => 'prd-101', 'qty' => true]], [['id' => 'prd-101', 'qty' => 100]],
    [['id' => 'prd-101', 'qty' => 1], ['id' => 'prd-101', 'qty' => 1]], [['id' => '../private', 'qty' => 1]]
] as $invalid) {
    problem(422, fn() => $service->checkout($alice['id'], $invalid, keyFor('invalid')));
}
problem(422, fn() => $service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 1]], 'short'));
$unchanged = hash_file('sha256', $directory . '/data.json');
problem(409, fn() => $service->checkout($alice['id'], [
    ['id' => 'prd-101', 'qty' => 1], ['id' => 'prd-102', 'qty' => 6],
], keyFor('rollback')));
check($unchanged === hash_file('sha256', $directory . '/data.json'), 'Late stock conflict rolls back all earlier stock/rate changes');
problem(422, fn() => $service->checkout($alice['id'], [['id' => 'prd-999', 'qty' => 1]], keyFor('unknown')));
problem(401, fn() => $service->checkout(str_repeat('0', 32), [['id' => 'prd-101', 'qty' => 1]], keyFor('no-user')));

$items = [['id' => 'prd-101', 'qty' => 1, 'price' => 1, 'total' => 1]];
$receipt = $service->checkout($alice['id'], $items, keyFor('first'));
check($receipt['subtotal'] === 249000 && $receipt['tax'] === 27390 && $receipt['total'] === 295390,
    'Server catalogue prices and integer example fees override forged price/total');
check($receipt['status'] === 'simulation_complete' && !isset($receipt['payment']), 'No real Paid/provider/webhook is claimed');
check(!isset($receipt['key'], $receipt['owner_id'], $receipt['request_hash']), 'Internal identity/idempotency fields are not returned');
check($service->products()[0]['stock'] === 8, 'One successful checkout decrements stock');
$replay = $service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 1]], keyFor('first'));
check($replay === $receipt && $service->products()[0]['stock'] === 8, 'Same key returns exact receipt without a second decrement');
problem(409, fn() => $service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 2]], keyFor('first')));
check(count($service->orders($alice['id'])) === 1 && $service->orders($bob['id']) === [], 'Orders are scoped to owner');
problem(404, fn() => $service->invoice($bob['id'], $receipt['id']));
check($service->invoice($alice['id'], $receipt['id']) === $receipt, 'Owner can retrieve exact receipt');
check($service->summary($admin['id'])['total_orders'] === 1, 'Admin summary sees actual order count');
$reverse = [['id' => 'prd-103', 'qty' => 1], ['id' => 'prd-102', 'qty' => 1]];
$combined = $service->checkout($bob['id'], $reverse, keyFor('reordered'));
check($service->checkout($bob['id'], array_reverse($reverse), keyFor('reordered')) === $combined,
    'Canonical cart order makes equivalent retry stable');
$service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 1]], keyFor('second'));
$service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 1]], keyFor('third'));
problem(429, fn() => $service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 1]], keyFor('fourth')));
check($service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 1]], keyFor('first')) === $receipt,
    'Idempotent recovery remains available when new checkout quota is full');
for ($index = 0; $index < 20; $index++) {
    $service->limitAuthentication('unit-address');
}
problem(429, fn() => $service->limitAuthentication('unit-address'));

$before = hash_file('sha256', $directory . '/data.json');
problem(503, fn() => $store->transaction(function (array &$data): void { $data['orders'][0]['owner_id'] = []; }));
check(hash_file('sha256', $directory . '/data.json') === $before, 'Malformed storage mutation is rejected without overwriting');
$store->transaction(function (array &$data) use ($receipt): void {
    $first = null;
    foreach ($data['orders'] as $order) {
        if ($order['id'] === $receipt['id']) { $first = $order; break; }
    }
    $data['orders'] = [$first];
    for ($index = 1; $index < Store::MAX_ORDERS; $index++) {
        $copy = $first;
        $copy['id'] = 'INV-' . strtoupper(str_pad(dechex($index), 24, '0', STR_PAD_LEFT));
        $copy['key'] = keyFor('capacity-' . $index);
        $data['orders'][] = $copy;
    }
});
check($service->checkout($alice['id'], [['id' => 'prd-101', 'qty' => 1]], keyFor('first')) === $receipt,
    'Retained key still recovers its order at maximum history capacity');
$before = hash_file('sha256', $directory . '/data.json');
problem(507, fn() => $service->checkout($bob['id'], [['id' => 'prd-101', 'qty' => 1]], keyFor('full-history')));
check(hash_file('sha256', $directory . '/data.json') === $before && count($service->orders($alice['id'])) === 1000,
    'Capacity rejects new write and retains all orders/keys');
$stateBeforeReset = $store->read(fn(array $data): array => $data);
$backup = $store->resetWithBackup();
check(json_decode(file_get_contents($backup), true, 64, JSON_THROW_ON_ERROR) === $stateBeforeReset,
    'Explicit reset backup retains complete order/key history');
check($service->orders($alice['id']) === [] && $service->products()[0]['stock'] === 9,
    'Explicit reset restores seed stock and removes simulated orders');
check($service->authenticate('operator', $password)['role'] === 'admin', 'Explicit reset preserves existing accounts');

$store->transaction(function (array &$data): void {
    $sample = $data['users'][0];
    for ($index = count($data['users']); $index < Store::MAX_USERS; $index++) {
        $user = $sample;
        $user['id'] = md5('fixture-user-' . $index);
        $user['username'] = 'fixture_' . $index;
        $data['users'][] = $user;
    }
});
problem(507, fn() => $service->register('one_more_user', $password));
check($store->read(fn(array $data): int => count($data['users'])) === 100, 'Full user capacity never evicts existing accounts');
file_put_contents($directory . '/data.json', '{broken');
problem(503, fn() => $service->products());
problem(503, fn() => $store->resetWithBackup());
check(file_get_contents($directory . '/data.json') === '{broken', 'Corrupt JSON is retained for recovery, never replaced by seed');
problem(503, fn() => new Store(dirname(__DIR__) . '/public'));

echo "Domain tests: {$checks} checks PASS; real bcrypt and filesystem, one PHP process, no HTTP server.\n";
echo "Private test fixtures retained: {$directory}\n";
