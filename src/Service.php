<?php
declare(strict_types=1);

namespace ZalaStore;

final class Service
{
    public function __construct(private Store $store)
    {
    }

    public function products(): array
    {
        return $this->store->read(fn(array $data): array => $data['products']);
    }

    public function user(string $id): ?array
    {
        return $this->store->read(function (array $data) use ($id): ?array {
            foreach ($data['users'] as $user) {
                if ($user['id'] === $id) {
                    return self::publicUser($user);
                }
            }
            return null;
        });
    }

    public function register(mixed $username, mixed $password, bool $admin = false): array
    {
        $name = self::username($username);
        self::password($password);
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
        return $this->store->transaction(function (array &$data) use ($name, $hash, $admin): array {
            foreach ($data['users'] as $user) {
                if ($user['username'] === $name) {
                    throw new Problem(409, 'Nama akun sudah digunakan.');
                }
            }
            if (count($data['users']) >= Store::MAX_USERS) {
                throw new Problem(507, 'Kapasitas akun demo penuh.');
            }
            $user = ['id' => bin2hex(random_bytes(16)), 'username' => $name,
                'password_hash' => $hash, 'role' => $admin ? 'admin' : 'customer'];
            $data['users'][] = $user;
            return self::publicUser($user);
        });
    }

    public function authenticate(mixed $username, mixed $password): array
    {
        $name = self::username($username);
        if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new Problem(401, 'Nama akun atau password tidak sesuai.');
        }
        $found = $this->store->read(function (array $data) use ($name): ?array {
            foreach ($data['users'] as $user) {
                if ($user['username'] === $name) {
                    return $user;
                }
            }
            return null;
        });
        if ($found === null || !password_verify($password, $found['password_hash'])) {
            throw new Problem(401, 'Nama akun atau password tidak sesuai.');
        }
        return self::publicUser($found);
    }

    public function limitAuthentication(string $address): void
    {
        $this->store->transaction(function (array &$data) use ($address): void {
            self::rate($data, 'auth:' . hash('sha256', $address), 20, 300);
        });
    }

    public function orders(string $owner): array
    {
        return $this->store->read(fn(array $data): array => array_map(self::publicOrder(...),
            array_values(array_filter($data['orders'], fn(array $order): bool => $order['owner_id'] === $owner))));
    }

    public function invoice(string $owner, string $id): array
    {
        return $this->store->read(function (array $data) use ($owner, $id): array {
            foreach ($data['orders'] as $order) {
                if ($order['id'] === $id && $order['owner_id'] === $owner) {
                    return self::publicOrder($order);
                }
            }
            throw new Problem(404, 'Pesanan tidak ditemukan.');
        });
    }

    public function checkout(string $owner, mixed $items, string $key): array
    {
        if (!preg_match('/\A[A-Za-z0-9_-]{16,80}\z/D', $key)) {
            throw new Problem(422, 'Kunci checkout tidak valid. Muat ulang halaman sebelum membuat pesanan baru.');
        }
        $cart = self::cart($items);
        $requestHash = hash('sha256', json_encode($cart, JSON_THROW_ON_ERROR));
        return $this->store->transaction(function (array &$data) use ($owner, $cart, $key, $requestHash): array {
            if (!in_array($owner, array_column($data['users'], 'id'), true)) {
                throw new Problem(401, 'Silakan masuk kembali.');
            }
            foreach ($data['orders'] as $order) {
                if ($order['owner_id'] === $owner && $order['key'] === $key) {
                    if (!hash_equals($order['request_hash'], $requestHash)) {
                        throw new Problem(409, 'Kunci checkout sudah dipakai untuk isi keranjang lain.');
                    }
                    return self::publicOrder($order);
                }
            }
            if (count($data['orders']) >= Store::MAX_ORDERS) {
                throw new Problem(507, 'Kapasitas pesanan demo penuh. Riwayat dan kunci lama tetap disimpan.');
            }
            self::rate($data, 'checkout:' . $owner, 3, 30);
            $subtotal = 0;
            $lines = [];
            foreach ($cart as $item) {
                $index = array_search($item['id'], array_column($data['products'], 'id'), true);
                if ($index === false) {
                    throw new Problem(422, 'Produk tidak ditemukan. Muat ulang katalog.');
                }
                $product = $data['products'][$index];
                if ($item['qty'] > $product['stock']) {
                    throw new Problem(409, 'Stok berubah atau tidak mencukupi. Perbarui keranjang.');
                }
                $subtotal += $product['price'] * $item['qty'];
                $lines[] = ['id' => $product['id'], 'name' => $product['name'],
                    'price' => $product['price'], 'qty' => $item['qty']];
                $data['products'][$index]['stock'] -= $item['qty'];
            }
            $tax = intdiv($subtotal * 11 + 50, 100);
            $order = ['id' => 'INV-' . strtoupper(bin2hex(random_bytes(12))), 'owner_id' => $owner,
                'key' => $key, 'request_hash' => $requestHash, 'items' => $lines,
                'subtotal' => $subtotal, 'shipping' => 19000, 'tax' => $tax,
                'total' => $subtotal + 19000 + $tax, 'status' => 'simulation_complete',
                'created_at' => gmdate(DATE_ATOM)];
            array_unshift($data['orders'], $order);
            return self::publicOrder($order);
        });
    }

    public function summary(string $actorId): array
    {
        return $this->store->read(function (array $data) use ($actorId): array {
            foreach ($data['users'] as $user) {
                if ($user['id'] === $actorId && $user['role'] === 'admin') {
                    return ['total_sku' => count($data['products']),
                        'available_stock' => array_sum(array_column($data['products'], 'stock')),
                        'total_orders' => count($data['orders']), 'simulation_orders' => count($data['orders'])];
                }
            }
            throw new Problem(403, 'Ringkasan ini hanya untuk akun admin.');
        });
    }

    private static function cart(mixed $items): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 8) {
            throw new Problem(422, 'Keranjang harus berisi satu sampai delapan produk.');
        }
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['id'] ?? null)
                    || !preg_match('/\Aprd-[0-9]{3}\z/D', $item['id'])
                    || !is_int($item['qty'] ?? null) || $item['qty'] < 1 || $item['qty'] > 99
                    || isset($normalized[$item['id']])) {
                throw new Problem(422, 'ID produk harus unik dan jumlah harus bilangan bulat 1 sampai 99.');
            }
            $normalized[$item['id']] = ['id' => $item['id'], 'qty' => $item['qty']];
        }
        ksort($normalized, SORT_STRING);
        return array_values($normalized);
    }

    private static function rate(array &$data, string $key, int $limit, int $window): void
    {
        $now = time();
        foreach ($data['rates'] as $name => $times) {
            $duration = str_starts_with($name, 'auth:') ? 300 : 30;
            $data['rates'][$name] = array_values(array_filter($times,
                fn(int $timestamp): bool => $timestamp > $now - $duration));
            if ($data['rates'][$name] === []) {
                unset($data['rates'][$name]);
            }
        }
        $times = $data['rates'][$key] ?? [];
        if (count($times) >= $limit) {
            throw new Problem(429, 'Terlalu banyak permintaan. Tunggu sebentar sebelum mencoba lagi.');
        }
        if (!isset($data['rates'][$key]) && count($data['rates']) >= 1000) {
            throw new Problem(503, 'Kapasitas pembatas permintaan sedang penuh. Coba lagi nanti.');
        }
        $data['rates'][$key] = [...$times, $now];
    }

    private static function username(mixed $value): string
    {
        if (!is_string($value)) {
            throw new Problem(422, 'Nama akun wajib diisi.');
        }
        $name = strtolower(trim($value));
        if (!preg_match('/\A[a-z0-9][a-z0-9_.-]{2,31}\z/D', $name)) {
            throw new Problem(422, 'Nama akun 3 sampai 32 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
        }
        return $name;
    }

    private static function password(mixed $value): void
    {
        if (!is_string($value) || strlen($value) > 72 || str_contains($value, "\0")
                || preg_match('//u', $value) !== 1 || preg_match_all('/./us', $value) < 12) {
            throw new Problem(422, 'Password minimal 12 karakter dan maksimal 72 byte, tanpa karakter NUL.');
        }
    }

    private static function publicUser(array $user): array
    {
        return ['id' => $user['id'], 'username' => $user['username'], 'role' => $user['role']];
    }

    private static function publicOrder(array $order): array
    {
        unset($order['owner_id'], $order['key'], $order['request_hash']);
        return $order;
    }
}
