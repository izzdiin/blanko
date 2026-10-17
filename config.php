<?php
// --- Load .env (tanpa dependensi eksternal) ---
function loadEnv($path) {
    if (!file_exists($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // hapus quote pembungkus
        if (strlen($val) >= 2 && (($val[0] === '"' && substr($val, -1) === '"') || ($val[0] === "'" && substr($val, -1) === "'"))) {
            $val = substr($val, 1, -1);
        }
        if (getenv($key) === false) putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}
loadEnv(__DIR__ . '/.env');

function env($key, $default = '') {
    $v = getenv($key);
    return $v === false ? $default : $v;
}

// --- Konfigurasi Database (SQLite via PDO) ---
// File database di host: /usr/local/app/blanko/data/blanko.sqlite
// Di-mount ke container sebagai: /var/www/data/blanko.sqlite
define('DB_PATH', env('DB_PATH', '/var/www/data/blanko.sqlite'));

// --- Konfigurasi SMTP ---
define('SMTP_HOST', env('SMTP_HOST', 'email.com'));
define('SMTP_USER', env('SMTP_USER', 'noreply@email.com'));
define('SMTP_PASS', env('SMTP_PASS', ''));
define('SMTP_PORT', (int)env('SMTP_PORT', '465'));
define('SMTP_SECURE', env('SMTP_SECURE', 'ssl'));

// --- Konfigurasi WhatsApp Gateway ---
define('WA_BASE_URL', env('WA_BASE_URL', 'https://wa.demo.my.id'));
define('WA_DEVICE_ID', env('WA_DEVICE_ID', 'iqra'));

function getWaHeaders()
{
    return [
        'X-Device-Id: ' . WA_DEVICE_ID
    ];
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', 1);
    session_start();
}

// --- Koneksi SQLite (PDO) ---
// $conn adalah objek PDO. Contoh pakai:
//   $res = $conn->query("SELECT * FROM users");
//   while ($row = $res->fetch()) { ... }
//   $stmt = $conn->prepare("INSERT INTO users (nama) VALUES (:nama)");
//   $stmt->execute([':nama' => $nama]);
try {
    $conn = new PDO('sqlite:' . DB_PATH);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $conn->exec('PRAGMA foreign_keys = ON;');  // aktifkan foreign key constraint
    $conn->exec('PRAGMA journal_mode = WAL;'); // mode WAL: concurrent read/write lebih baik
    $conn->exec('PRAGMA busy_timeout = 5000;'); // tunggu maks 5 detik bila DB terkunci
} catch (PDOException $e) {
    error_log("Koneksi Database Gagal: " . $e->getMessage());
    die("Maaf, terjadi gangguan koneksi database.");
}

function generate_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token ?? '');
}

function sanitize(string $data): string
{
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}
