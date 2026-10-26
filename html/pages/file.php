<?php
// Serve file dokumen (aman: cek hak akses, X-Sendfile tidak ada -> readfile)
declare(strict_types=1);
$id = (int)($_GET['id'] ?? 0);
$u = current_user();
if (!$u) {
    // Reviewer lewat token (WA/email) boleh melihat dokumen yang sama
    // seperti di halaman review; token tidak dikonsumsi di sini.
    require_once __DIR__ . '/../includes/Token.php';
    $tr = !empty($_GET['token']) ? token_verify((string)$_GET['token']) : null;
    if (!$tr || (int)$tr['document_id'] !== $id) { redirect('?page=login'); }
}
$st = db()->prepare('SELECT * FROM documents WHERE id = ?');
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { http_response_code(404); exit; }
if ($u && $u['role'] === 'mitra' && (int)$d['user_id'] !== (int)$u['id']) {
    http_response_code(403); exit;
}
$path = '/var/www/data/uploads/' . basename($d['stored_name']);
if (!is_file($path)) { http_response_code(404); exit; }
header('Content-Type: ' . $d['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . addslashes($d['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
