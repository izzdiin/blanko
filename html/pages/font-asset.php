<?php
// Servis file font untuk editor & preview template (allowlist ketat).
// Font bukan data sensitif, jadi rute ini publik seperti aset statis lainnya.
declare(strict_types=1);
$allowed = [
    'Arial.ttf' => '/var/www/data/fonts/Arial.ttf',
    'Arial-Bold.ttf' => '/var/www/data/fonts/Arial-Bold.ttf',
    'NotoSansSC-Regular.ttf' => '/var/www/data/fonts/NotoSansSC-Regular.ttf',
    'DejaVuSans.ttf' => '/var/www/data/fonts/DejaVuSans.ttf',
    'DejaVuSans-Bold.ttf' => '/var/www/data/fonts/DejaVuSans-Bold.ttf',
];
$f = $_GET['f'] ?? '';
if (!isset($allowed[$f]) || !is_file($allowed[$f])) {
    http_response_code(404);
    exit('Font tidak ditemukan.');
}
header('Content-Type: font/ttf');
header('Cache-Control: public, max-age=31536000');
header('Content-Length: ' . filesize($allowed[$f]));
readfile($allowed[$f]);
