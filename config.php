<?php
// --- Konfigurasi Database (SQLite via PDO) ---
// File database di host: /usr/local/app/blanko/data/blanko.sqlite
// Di-mount ke container sebagai: /var/www/data/blanko.sqlite
define('DB_PATH', '/var/www/data/blanko.sqlite');

// --- Konfigurasi SMTP ---
define('SMTP_HOST', 'email.com');
define('SMTP_USER', 'noreply@email.com');
define('SMTP_PASS', 'passSMTP');
define('SMTP_PORT', 465);
define('SMTP_SECURE', 'ssl');

// --- Konfigurasi WhatsApp Gateway ---
define('WA_BASE_URL', 'https://wa.demo.my.id');
define('WA_DEVICE_ID', 'iqra');

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
