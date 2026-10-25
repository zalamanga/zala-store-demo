<?php
declare(strict_types=1);

namespace ZalaStore;

final class Store
{
    public const MAX_USERS = 100;
    public const MAX_ORDERS = 1000;
    public const MAX_BYTES = 8_388_608;
    private string $directory;

    public function __construct(string $directory)
    {
        if (PHP_INT_SIZE < 8) {
            throw new Problem(503, 'Demo memerlukan PHP 64 bit.');
        }
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new Problem(503, 'Penyimpanan belum tersedia.');
        }
        $resolved = realpath($directory);
        $public = realpath(dirname(__DIR__) . '/public');
        if ($resolved === false || ($public !== false &&
                str_starts_with(strtolower($resolved) . DIRECTORY_SEPARATOR,
                    strtolower($public) . DIRECTORY_SEPARATOR))) {
            throw new Problem(503, 'Direktori data harus berada di luar public.');
        }
        $this->directory = $resolved;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public static function initial(): array
    {
        return ['version' => 1, 'products' => require dirname(__DIR__) . '/seed/catalog.php',
            'users' => [], 'orders' => [], 'rates' => []];
    }

    public function read(callable $reader): mixed
    {
        return $this->locked(false, $reader);
    }

    public function transaction(callable $writer): mixed
    {
        return $this->locked(true, $writer);
    }

    public function resetWithBackup(): string
    {
        return $this->transaction(function (array &$data): string {
            $backup = $this->directory . '/backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
            $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (@file_put_contents($backup, $encoded, LOCK_EX) !== strlen($encoded)) {
                throw new Problem(503, 'Cadangan gagal dibuat; data tidak direset.');
            }
            $users = $data['users'];
            $data = self::initial();
            $data['users'] = $users;
            return $backup;
        });
    }

    private function locked(bool $write, callable $action): mixed
    {
        $lockPath = $this->directory . '/data.lock';
        if (is_link($lockPath) || is_link($this->directory . '/data.json')) {
            throw new Problem(503, 'Berkas penyimpanan tidak valid.');
        }
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new Problem(503, 'Penyimpanan tidak dapat dikunci.');
        }
        try {
            $deadline = microtime(true) + 3;
            while (!flock($lock, ($write ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                if (microtime(true) >= $deadline) {
                    throw new Problem(503, 'Penyimpanan sedang sibuk. Coba lagi.');
                }
                usleep(20_000);
            }
            $data = $this->load();
            $before = $data;
            $result = $action($data);
            if ($write && $data !== $before) {
                $this->validate($data);
                $this->commit($data);
            }
            return $result;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function load(): array
    {
        $path = $this->directory . '/data.json';
        clearstatcache(true, $path);
        if (!file_exists($path)) {
            return self::initial();
        }
        $size = @filesize($path);
        if ($size === false || $size > self::MAX_BYTES) {
            throw new Problem(503, 'Ukuran penyimpanan tidak valid.');
        }
        $raw = @file_get_contents($path);
        try {
            $data = $raw === false ? null : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new Problem(503, 'Data tidak dapat dibaca. Pulihkan cadangan; data tidak direset otomatis.');
        }
        if (!is_array($data)) {
            throw new Problem(503, 'Struktur data tidak valid.');
        }
        $this->validate($data);
        return $data;
    }

    private function validate(array $data): void
    {
        $valid = ($data['version'] ?? null) === 1;
        foreach (['products', 'users', 'orders', 'rates'] as $key) {
            $valid = $valid && isset($data[$key]) && is_array($data[$key]);
        }
        if (!$valid || !array_is_list($data['products']) || !array_is_list($data['users'])
                || !array_is_list($data['orders']) || count($data['products']) !== 8
                || count($data['users']) > self::MAX_USERS || count($data['orders']) > self::MAX_ORDERS
                || count($data['rates']) > 1000) {
            throw new Problem(503, 'Struktur atau kapasitas data tidak valid.');
        }
        $ids = [];
        foreach ($data['products'] as $product) {
            if (!is_array($product) || !is_string($product['id'] ?? null)
                    || !preg_match('/\Aprd-[0-9]{3}\z/D', $product['id']) || isset($ids[$product['id']])
                    || !is_string($product['name'] ?? null) || strlen($product['name']) > 200
                    || !in_array($product['category'] ?? null, ['fashion', 'tech', 'beauty', 'home'], true)
                    || !is_int($product['price'] ?? null) || $product['price'] < 0 || $product['price'] > 10_000_000
                    || !is_int($product['stock'] ?? null) || $product['stock'] < 0 || $product['stock'] > 100_000
                    || !is_string($product['mark'] ?? null) || strlen($product['mark']) > 8
                    || !is_int($product['featured'] ?? null) || $product['featured'] < 0 || $product['featured'] > 100) {
                throw new Problem(503, 'Data produk tidak valid.');
            }
            $ids[$product['id']] = true;
        }
        $userIds = $names = [];
        foreach ($data['users'] as $user) {
            if (!is_array($user) || !is_string($user['id'] ?? null) || isset($userIds[$user['id']])
                    || !preg_match('/\A[a-f0-9]{32}\z/D', $user['id'])
                    || !is_string($user['username'] ?? null) || isset($names[$user['username']])
                    || !preg_match('/\A[a-z0-9][a-z0-9_.-]{2,31}\z/D', $user['username'])
                    || !is_string($user['password_hash'] ?? null)
                    || (password_get_info($user['password_hash'])['algoName'] ?? 'unknown') === 'unknown'
                    || !in_array($user['role'] ?? null, ['customer', 'admin'], true)) {
                throw new Problem(503, 'Data akun tidak valid.');
            }
            $userIds[$user['id']] = $names[$user['username']] = true;
        }
        $orderIds = $keys = [];
        foreach ($data['orders'] as $order) {
            if (!is_array($order) || !is_string($order['id'] ?? null) || isset($orderIds[$order['id']])
                    || !preg_match('/\AINV-[A-F0-9]{24}\z/D', $order['id'])
                    || !is_string($order['owner_id'] ?? null) || !isset($userIds[$order['owner_id']])
                    || !is_string($order['key'] ?? null) || !preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D', $order['key'])
                    || !is_string($order['request_hash'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/D', $order['request_hash'])
                    || !is_array($order['items'] ?? null)
                    || !array_is_list($order['items']) || count($order['items']) < 1 || count($order['items']) > 8
                    || ($order['status'] ?? null) !== 'simulation_complete' || !is_int($order['total'] ?? null)
                    || $order['total'] < 0 || !is_int($order['subtotal'] ?? null)
                    || !is_int($order['shipping'] ?? null) || !is_int($order['tax'] ?? null)
                    || !is_string($order['created_at'] ?? null)) {
                throw new Problem(503, 'Data pesanan tidak valid.');
            }
            $key = $order['owner_id'] . ':' . $order['key'];
            if (isset($keys[$key])) {
                throw new Problem(503, 'Kunci pesanan ganda pada penyimpanan.');
            }
            $subtotal = 0;
            $cart = [];
            foreach ($order['items'] as $item) {
                if (!is_array($item) || !is_string($item['id'] ?? null) || !isset($ids[$item['id']])
                        || isset($cart[$item['id']])
                        || !is_string($item['name'] ?? null) || !is_int($item['qty'] ?? null)
                        || $item['qty'] < 1 || $item['qty'] > 99 || !is_int($item['price'] ?? null)
                        || $item['price'] < 0 || $item['price'] > 10_000_000) {
                    throw new Problem(503, 'Rincian pesanan tidak valid.');
                }
                $subtotal += $item['qty'] * $item['price'];
                $cart[$item['id']] = ['id' => $item['id'], 'qty' => $item['qty']];
            }
            ksort($cart, SORT_STRING);
            $tax = intdiv($subtotal * 11 + 50, 100);
            if ($order['subtotal'] !== $subtotal || $order['tax'] !== $tax || $order['shipping'] !== 19000
                    || $order['total'] !== $subtotal + 19000 + $tax
                    || !hash_equals($order['request_hash'], hash('sha256', json_encode(array_values($cart), JSON_THROW_ON_ERROR)))) {
                throw new Problem(503, 'Total atau kunci rincian pesanan tidak valid.');
            }
            $keys[$key] = $orderIds[$order['id']] = true;
        }
        foreach ($data['rates'] as $key => $times) {
            if (!is_string($key) || !preg_match('/\A(?:auth:[a-f0-9]{64}|checkout:[a-f0-9]{32})\z/D', $key)
                    || !is_array($times) || !array_is_list($times) || count($times) > 20) {
                throw new Problem(503, 'Data pembatas permintaan tidak valid.');
            }
            foreach ($times as $time) {
                if (!is_int($time) || $time < 0) {
                    throw new Problem(503, 'Waktu pembatas permintaan tidak valid.');
                }
            }
        }
    }

    private function commit(array $data): void
    {
        $encoded = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if (strlen($encoded) > self::MAX_BYTES) {
            throw new Problem(507, 'Penyimpanan demo penuh. Tidak ada pesanan atau kunci lama yang dihapus.');
        }
        $temporary = @tempnam($this->directory, '.write-');
        if ($temporary === false || strtolower((string) realpath(dirname($temporary))) !== strtolower($this->directory)) {
            throw new Problem(503, 'Berkas sementara tidak dapat dibuat.');
        }
        try {
            $handle = @fopen($temporary, 'wb');
            if ($handle === false) {
                throw new Problem(503, 'Data tidak dapat disimpan.');
            }
            try {
                if (fwrite($handle, $encoded) !== strlen($encoded) || !fflush($handle)
                        || (function_exists('fsync') && !fsync($handle))) {
                    throw new Problem(503, 'Penulisan data belum berhasil.');
                }
            } finally {
                fclose($handle);
            }
            if (!@rename($temporary, $this->directory . '/data.json')) {
                throw new Problem(503, 'Penggantian data belum berhasil. Data lama dipertahankan.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
