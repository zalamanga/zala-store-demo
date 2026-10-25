<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use ZalaStore\Problem;
use ZalaStore\Service;
use ZalaStore\Store;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

try {
    $store = new Store(getenv('ZALA_DATA_DIR') ?: dirname(__DIR__) . '/var');
    $service = new Service($store);
    if (($argv[1] ?? '') === 'admin' && count($argv) === 3) {
        $password = getenv('ZALA_ADMIN_PASSWORD');
        putenv('ZALA_ADMIN_PASSWORD');
        if ($password === false) {
            throw new Problem(422, 'Atur ZALA_ADMIN_PASSWORD sementara dari prompt tersembunyi; lihat README.');
        }
        try {
            $user = $service->register($argv[2], $password, true);
            fwrite(STDOUT, 'Admin dibuat: ' . $user['username'] . PHP_EOL);
        } finally {
            $password = '';
        }
    } elseif (($argv[1] ?? '') === 'reset' && ($argv[2] ?? '') === '--confirm' && count($argv) === 3) {
        fwrite(STDOUT, "Hentikan server lebih dulu. Reset menghapus riwayat/stock simulasi, akun tetap ada.\nKetik RESET untuk membuat backup lalu melanjutkan: ");
        if (trim((string) fgets(STDIN)) !== 'RESET') {
            throw new Problem(422, 'Reset dibatalkan.');
        }
        $backup = $store->resetWithBackup();
        fwrite(STDOUT, 'Reset selesai. Cadangan: ' . $backup . PHP_EOL);
    } else {
        fwrite(STDERR, "Pemakaian: php bin/console.php admin USERNAME | reset --confirm\nPassword tidak boleh diberikan sebagai argumen.\n");
        exit(2);
    }
} catch (Problem $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
} catch (Throwable $error) {
    fwrite(STDERR, "Operasi gagal. Periksa akses penyimpanan dan versi PHP; jangan mengulang sebelum memeriksa data.\n");
    exit(1);
}
