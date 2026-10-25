<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use ZalaStore\Problem;
use ZalaStore\Service;
use ZalaStore\Store;

ini_set('display_errors', '0');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');

function reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}

function body(array $allowed): array
{
    if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new Problem(415, 'Gunakan Content-Type application/json.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536) {
        throw new Problem(413, 'Isi permintaan terlalu besar.');
    }
    $input = fopen('php://input', 'rb');
    if ($input === false) {
        throw new Problem(400, 'Isi permintaan tidak dapat dibaca.');
    }
    try {
        $raw = stream_get_contents($input, 65537);
    } finally {
        fclose($input);
    }
    if ($raw === false || strlen($raw) > 65536) {
        throw new Problem(413, 'Isi permintaan terlalu besar.');
    }
    try {
        $object = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new Problem(400, 'JSON tidak valid.');
    }
    if (!$object instanceof stdClass || !is_array($data) || array_diff(array_keys($data), $allowed)) {
        throw new Problem(422, 'Bentuk atau field permintaan tidak sesuai.');
    }
    return $data;
}

function newSession(?array $user): array
{
    if (!session_regenerate_id(true)) {
        throw new Problem(503, 'Sesi belum dapat diperbarui.');
    }
    $_SESSION = ['csrf' => bin2hex(random_bytes(32))];
    if ($user !== null) {
        $_SESSION['user_id'] = $user['id'];
    }
    return ['user' => $user, 'csrf' => $_SESSION['csrf']];
}

try {
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $configuredOrigin = getenv('ZALA_PUBLIC_ORIGIN');
    if ($configuredOrigin !== false && $configuredOrigin !== '') {
        $parts = parse_url($configuredOrigin);
        if (!is_array($parts) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
                || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['query']) || isset($parts['fragment']) || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new Problem(503, 'Konfigurasi origin tidak valid.');
        }
        $origin = rtrim($configuredOrigin, '/');
        $expectedHost = strtolower($parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
        if ($host !== $expectedHost) {
            throw new Problem(400, 'Host tidak diizinkan.');
        }
        $https = $parts['scheme'] === 'https';
    } else {
        if (!preg_match('/\A(?:localhost|127\.0\.0\.1|\[::1\])(?::[0-9]{1,5})?\z/Di', $host)) {
            throw new Problem(400, 'Demo lokal hanya menerima host localhost atau loopback.');
        }
        $origin = ($https ? 'https://' : 'http://') . $host;
    }
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $static = ['/' => ['index.html', 'text/html; charset=utf-8'],
        '/index.html' => ['index.html', 'text/html; charset=utf-8'],
        '/app.js' => ['app.js', 'text/javascript; charset=utf-8'],
        '/styles.css' => ['styles.css', 'text/css; charset=utf-8'],
        '/assets/zalastore-products.png' => ['assets/zalastore-products.png', 'image/png']];
    if (isset($static[$path])) {
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            header('Allow: GET, HEAD');
            throw new Problem(405, 'Metode tidak diizinkan.');
        }
        header('Content-Type: ' . $static[$path][1]);
        if ($method !== 'HEAD') {
            readfile(__DIR__ . '/' . $static[$path][0]);
        }
        return;
    }
    $routes = ['GET /api/session', 'POST /api/register', 'POST /api/login', 'POST /api/logout',
        'GET /api/products', 'GET /api/orders', 'POST /api/checkout', 'GET /api/admin/summary'];
    $invoice = is_string($path) && preg_match('#\A/api/invoices/(INV-[A-F0-9]{24})\z#D', $path, $matches);
    if (!in_array($method . ' ' . $path, $routes, true) && !($method === 'GET' && $invoice)) {
        throw new Problem(404, 'Route tidak ditemukan.');
    }
    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (($requestOrigin !== '' && $requestOrigin !== $origin)
            || ($method === 'POST' && $requestOrigin !== $origin)
            || ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') {
        throw new Problem(403, 'Asal permintaan tidak diizinkan.');
    }
    $store = new Store(getenv('ZALA_DATA_DIR') ?: dirname(__DIR__) . '/var');
    $sessions = $store->directory() . '/sessions';
    if (!is_dir($sessions) && !@mkdir($sessions, 0700) && !is_dir($sessions)) {
        throw new Problem(503, 'Penyimpanan sesi belum tersedia.');
    }
    ini_set('log_errors', '1');
    ini_set('error_log', $store->directory() . '/php-error.log');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_save_path($sessions);
    session_name('ZALASTORE_SESSION');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https,
        'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) {
        throw new Problem(503, 'Sesi belum dapat dibuka.');
    }
    if (!is_string($_SESSION['csrf'] ?? null)) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    if ($method === 'POST' && (!is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
            || !hash_equals($_SESSION['csrf'], $_SERVER['HTTP_X_CSRF_TOKEN']))) {
        throw new Problem(403, 'Sesi formulir berubah. Muat ulang halaman lalu coba lagi.');
    }
    $service = new Service($store);
    $user = is_string($_SESSION['user_id'] ?? null) ? $service->user($_SESSION['user_id']) : null;
    if ($user === null) {
        unset($_SESSION['user_id']);
    }
    if ($path === '/api/session') {
        reply(['data' => ['user' => $user, 'csrf' => $_SESSION['csrf']]]);
    } elseif ($path === '/api/products') {
        reply(['data' => $service->products()]);
    } elseif ($path === '/api/register' || $path === '/api/login') {
        $payload = body(['username', 'password']);
        $service->limitAuthentication($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        $account = $path === '/api/register'
            ? $service->register($payload['username'] ?? null, $payload['password'] ?? null)
            : $service->authenticate($payload['username'] ?? null, $payload['password'] ?? null);
        reply(['data' => newSession($account)], $path === '/api/register' ? 201 : 200);
    } elseif ($path === '/api/logout') {
        body([]);
        reply(['data' => newSession(null)]);
    } else {
        if ($user === null) {
            throw new Problem(401, 'Silakan masuk untuk membuka pesanan atau membuat checkout simulasi.');
        }
        if ($path === '/api/orders') {
            reply(['data' => $service->orders($user['id'])]);
        } elseif ($invoice) {
            reply(['data' => $service->invoice($user['id'], $matches[1])]);
        } elseif ($path === '/api/admin/summary') {
            reply(['data' => $service->summary($user['id'])]);
        } elseif ($path === '/api/checkout') {
            $payload = body(['items']);
            $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
            reply(['data' => $service->checkout($user['id'], $payload['items'] ?? null, $key)], 201);
        }
    }
} catch (Problem $error) {
    if ($error->status === 429) {
        header('Retry-After: ' . (in_array($path ?? '', ['/api/login', '/api/register'], true) ? '300' : '30'));
    }
    reply(['message' => $error->getMessage()], $error->status);
} catch (Throwable $error) {
    error_log('ZalaStore request failed: ' . get_class($error));
    reply(['message' => 'Permintaan belum dapat diselesaikan. Periksa pesanan sebelum mencoba checkout lagi.'], 503);
}
