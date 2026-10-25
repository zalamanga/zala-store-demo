<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use ZalaStore\Service;
use ZalaStore\Store;

if (PHP_SAPI !== 'cli' || count($argv) !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Pemakaian: php -n tests/concurrency.php DIREKTORI_TEMP_UJI_YANG_SUDAH_ADA\n");
    exit(2);
}
if (!function_exists('proc_open')) {
    fwrite(STDERR, "Tes memerlukan proc_open untuk proses PHP yang benar-benar terpisah.\n");
    exit(2);
}
$parent = realpath($argv[1]);
$directory = $parent . '/zala-parallel-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Direktori fixture tidak dapat dibuat.');
}
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { throw new RuntimeException($message); }
    $checks++;
}
function fixture(string $directory): array
{
    $service = new Service(new Store($directory));
    $actors = [];
    for ($index = 0; $index < 4; $index++) {
        $actors[] = $service->register('worker_' . $index, 'Test-' . bin2hex(random_bytes(14)))['id'];
    }
    return $actors;
}
function batch(string $directory, string $mode, array $actors, array $keys): array
{
    $barrier = $directory . '/barrier-' . bin2hex(random_bytes(8));
    $processes = [];
    try {
        foreach ($actors as $index => $actor) {
            $stdout = $barrier . '-' . $index . '.out';
            $stderr = $barrier . '-' . $index . '.err';
            $command = [PHP_BINARY, '-n', '-d', 'memory_limit=128M', '-d', 'sys_temp_dir=' . $directory,
                __DIR__ . '/worker.php', $directory, $barrier, $mode, $actor, $keys[$index]];
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $stdout, 'w'],
                2 => ['file', $stderr, 'w']], $pipes, dirname(__DIR__), null, ['bypass_shell' => true]);
            if ($process === false) { throw new RuntimeException('Worker tidak dapat dimulai.'); }
            fclose($pipes[0]);
            $processes[] = ['process' => $process, 'stdout' => $stdout, 'stderr' => $stderr];
        }
        $deadline = microtime(true) + 10;
        do {
            $ready = 0;
            foreach ($processes as $worker) {
                if (str_contains((string) @file_get_contents($worker['stdout']), "READY\n")) { $ready++; }
            }
            if ($ready === count($actors)) { break; }
            if (microtime(true) >= $deadline) { throw new RuntimeException('Worker tidak mencapai barrier.'); }
            usleep(20_000);
        } while (true);
        if (file_put_contents($barrier, 'go') !== 2) { throw new RuntimeException('Barrier gagal dibuat.'); }
        $results = [];
        foreach ($processes as &$worker) {
            do {
                $status = proc_get_status($worker['process']);
                if (!$status['running']) { break; }
                if (microtime(true) >= $deadline + 10) { throw new RuntimeException('Worker tidak selesai.'); }
                usleep(20_000);
            } while (true);
            $code = $status['exitcode'];
            $closed = proc_close($worker['process']);
            $worker['process'] = null;
            if (($code < 0 ? $closed : $code) !== 0 || filesize($worker['stderr']) !== 0) {
                throw new RuntimeException('Worker gagal; periksa log fixture.');
            }
            $lines = explode("\n", trim((string) file_get_contents($worker['stdout'])));
            $results[] = json_decode(end($lines), true, 16, JSON_THROW_ON_ERROR);
        }
        unset($worker);
        return $results;
    } finally {
        foreach ($processes as $worker) {
            if (is_resource($worker['process'])) {
                $status = proc_get_status($worker['process']);
                if ($status['running']) { proc_terminate($worker['process']); }
                proc_close($worker['process']);
            }
        }
    }
}
function disk(string $directory): array
{
    return json_decode((string) file_get_contents($directory . '/data.json'), true, 64, JSON_THROW_ON_ERROR);
}
function keys(): array
{
    return array_map(static fn(int $index): string => bin2hex(random_bytes(16)), range(0, 3));
}

try {
    $data = $directory . '/data';
    $actors = fixture($data);
    batch($data, 'counter', $actors, $actors);
    batch($data, 'counter', $actors, $actors);
    check(disk($data)['products'][0]['stock'] === 17, 'Delapan RMW tidak kehilangan perubahan.');
    $results = array_merge(batch($data, 'checkout', $actors, keys()), batch($data, 'checkout', $actors, keys()));
    $statuses = array_count_values(array_column($results, 'status'));
    check(($statuses[201] ?? 0) === 5 && ($statuses[409] ?? 0) === 3, 'Lima stok menghasilkan lima sukses dan tiga konflik.');
    $saved = disk($data);
    check($saved['products'][1]['stock'] === 0 && count($saved['orders']) === 5, 'Stok tidak negatif dan order tidak hilang.');
    $replay = $directory . '/replay';
    $owner = fixture($replay)[0];
    $results = batch($replay, 'checkout', array_fill(0, 4, $owner), array_fill(0, 4, bin2hex(random_bytes(16))));
    check(array_column($results, 'status') === [201, 201, 201, 201]
        && count(array_unique(array_column($results, 'id'))) === 1, 'Empat retry paralel mendapat satu receipt.');
    $saved = disk($replay);
    check(count($saved['orders']) === 1 && $saved['products'][1]['stock'] === 4, 'Key paralel mengurangi stok satu kali.');
    echo "Concurrency tests: {$checks} checks PASS; 20 separate PHP worker processes, maximum4 concurrent.\n";
    echo "Private test fixtures retained: {$directory}\n";
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . "\nFixture: {$directory}\n");
    exit(1);
}
