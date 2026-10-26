<?php
// Blanko bootstrap: session secure, CSRF, helpers, auth. Native PHP 8.
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/db.php';

function e(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void {
    $t = $_POST['csrf'] ?? '';
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)$t)) {
        http_response_code(419);
        exit('CSRF token tidak valid.');
    }
}

function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    $st = db()->prepare('SELECT id, name, email, phone, role, status, is_admin, penerjemah_id FROM users WHERE id = ?');
    $st->execute([$_SESSION['uid']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function require_login(): array {
    $u = current_user();
    if (!$u) { header('Location: ?page=login'); exit; }
    return $u;
}

function require_role(string $role): array {
    $u = require_login();
    if ($u['role'] !== $role) { http_response_code(403); exit('Akses ditolak.'); }
    return $u;
}

// Apakah user boleh menyetujui pendaftaran (admin atau penerjemah).
function is_approver(?array $u): bool {
    return (bool)$u && (!empty($u['is_admin']) || $u['role'] === 'penerjemah');
}

// Apakah $u boleh menyetujui $t: admin boleh semua; penerjemah hanya
// mitra yang mendaftar di bawahnya (penerjemah_id == id penyetuju).
function can_approve(array $u, array $t): bool {
    if (!empty($u['is_admin'])) return true;
    return $u['role'] === 'penerjemah'
        && $t['role'] === 'mitra'
        && (int)($t['penerjemah_id'] ?? 0) === (int)$u['id'];
}

// Field profil penerjemah yang masih kurang untuk aktivasi.
// Nama aksara mengikuti bahasa target: Arab -> nama Arab, Mandarin/Cina -> nama Cina.
function penerjemah_profile_missing(array $p): array {
    $miss = [];
    if (trim($p['lang_pair'] ?? '') === '') $miss[] = 'Bahasa target';
    if (trim($p['sk'] ?? '') === '') $miss[] = 'SK pengangkatan';
    $lp = mb_strtolower(trim($p['lang_pair'] ?? ''));
    if (str_contains($lp, 'arab') || str_contains($lp, 'arabic')) {
        if (trim($p['name_ar'] ?? '') === '') $miss[] = 'Nama Arab';
    }
    if (str_contains($lp, 'mandarin') || str_contains($lp, 'cina') || str_contains($lp, 'tiongkok') || str_contains($lp, 'tionghoa') || str_contains($lp, 'chinese')) {
        if (trim($p['name_zh'] ?? '') === '') $miss[] = 'Nama Cina/Mandarin';
    }
    return $miss;
}

// Pasangan bahasa selalu dari Bahasa Indonesia ke bahasa target.
// lang_pair menyimpan frasa lengkap; lang_reverse = 1 bila berlisensi sebaliknya.
// Strip pengesahan (Attestation.php) mencetak lang_pair apa adanya, jadi
// frasa ini TIDAK boleh diubah formatnya tanpa mengubah strip.
// Kembalikan nama bahasa saja (tanpa 'Bahasa Indonesia ke ' dan 'Bahasa '),
// sesuai isian kolom target di form ('Arab', 'Inggris', 'Mandarin', ...).
function lang_pair_target(string $pair): string {
    $pair = trim($pair);
    $pre = 'Bahasa Indonesia ke ';
    if (mb_stripos($pair, $pre) === 0) $pair = trim(mb_substr($pair, mb_strlen($pre)));
    if (mb_stripos($pair, 'Bahasa ') === 0) $pair = trim(mb_substr($pair, mb_strlen('Bahasa ')));
    return $pair;
}

function lang_pair_compose(string $target): string {
    $target = lang_pair_target($target);
    if ($target === '') return '';
    $target = mb_strtoupper(mb_substr($target, 0, 1)) . mb_substr($target, 1);
    return 'Bahasa Indonesia ke Bahasa ' . $target;
}

function lang_pair_display(string $pair, $reverse): string {
    $pair = trim($pair);
    if ($pair === '') return '-';
    if (!empty($reverse) && !str_contains($pair, 'sebaliknya')) $pair .= ' dan sebaliknya';
    return $pair;
}

function redirect(string $url): void {
    header('Location: ' . $url);
    exit;
}

function flash(string $msg, string $type = 'ok'): void {
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function flash_show(): string {
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $cls = $f['type'] === 'err' ? 'bg-red-100 text-red-800 border-red-300' : 'bg-green-100 text-green-800 border-green-300';
    return '<div class="border rounded px-4 py-3 mb-4 ' . $cls . '">' . e($f['msg']) . '</div>';
}

function app_url(string $path = ''): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $base . '/' . ltrim($path, '/');
}
