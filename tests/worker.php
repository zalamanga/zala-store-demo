<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use ZalaStore\Problem;
use ZalaStore\Service;
use ZalaStore\Store;

if (PHP_SAPI !== 'cli' || count($argv) !== 6) {
    fwrite(STDERR, "CLI test worker: DATA_DIR BARRIER checkout|counter OWNER KEY\n");
    exit(2);
}
try {
    $store = new Store($argv[1]);
    $barrierDirectory = realpath(dirname($argv[2]));
    if ($barrierDirectory === false || $barrierDirectory !== $store->directory()) {
        throw new Problem(422, 'Barrier must be inside this test store.');
    }
    fwrite(STDOUT, "READY\n");
    fflush(STDOUT);
    $deadline = microtime(true) + 10;
    while (!file_exists($argv[2])) {
        clearstatcache(true, $argv[2]);
        if (microtime(true) >= $deadline) { throw new Problem(503, 'Test barrier timeout.'); }
        usleep(10_000);
    }
    if ($argv[3] === 'checkout') {
        $result = (new Service($store))->checkout($argv[4], [['id' => 'prd-102', 'qty' => 1]], $argv[5]);
        echo json_encode(['status' => 201, 'id' => $result['id']], JSON_THROW_ON_ERROR) . "\n";
    } elseif ($argv[3] === 'counter') {
        $result = $store->transaction(function (array &$data): int {
            $stock = $data['products'][0]['stock'];
            usleep(100_000);
            $data['products'][0]['stock'] = $stock + 1;
            return $stock + 1;
        });
        echo json_encode(['status' => 200, 'stock' => $result], JSON_THROW_ON_ERROR) . "\n";
    } else {
        throw new Problem(422, 'Unknown worker mode.');
    }
} catch (Problem $error) {
    echo json_encode(['status' => $error->status], JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . "\n");
    exit(1);
}
