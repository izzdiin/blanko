<?php
// Serve gambar TTD e-sign: publik hanya lewat token rekaman yang sudah
// dibubuhkan; selain itu wajib login dan hanya file milik sendiri.
declare(strict_types=1);
require_once __DIR__ . '/../includes/esign.php';
$kind = $_GET['kind'] ?? '';
$token = (string)($_GET['token'] ?? '');
$path = '';
if ($kind === 'sig' && esign_valid_token($token)) {
    $rec = esign_find($token);
    if ($rec && $rec['status'] === 'signed' && ($rec['sig_file'] ?? '') !== '') {
        $path = esign_dir() . '/' . basename($rec['sig_file']);
    }
} elseif ($kind === 'sig') {
    $u = current_user();
    if ($u) {
        $st = db()->prepare('SELECT esign_sig_file FROM users WHERE id = ?'); $st->execute([$u['id']]);
        $f = $st->fetchColumn();
        if ($f) $path = esign_dir() . '/' . basename($f);
    }
}
if ($path === '' || !is_file($path)) { http_response_code(404); exit; }
header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
readfile($path);
