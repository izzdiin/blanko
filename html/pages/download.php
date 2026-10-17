<?php
// Download dokumen yang sudah disahkan
declare(strict_types=1);
$u = require_login();
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare('SELECT * FROM documents WHERE id = ? AND status = ?');
$st->execute([$id, 'approved']);
$d = $st->fetch();
if (!$d || !$d['stamped_name']) { http_response_code(404); exit('Belum tersedia.'); }
if ($u['role'] === 'mitra' && (int)$d['user_id'] !== (int)$u['id']) {
    http_response_code(403); exit;
}
$path = '/var/www/data/uploads/' . basename($d['stamped_name']);
if (!is_file($path)) { http_response_code(404); exit; }
$mime = str_ends_with($path, '.pdf') ? 'application/pdf' : 'image/jpeg';
header('Content-Type: ' . $mime);
$inline = isset($_GET['preview']);
$fname = preg_replace('/[^A-Za-z0-9._-]/', '_', $d['original_name']);
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . ($inline ? $fname : 'disahkan-' . $fname) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
