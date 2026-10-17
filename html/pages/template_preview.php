<?php
// Live preview template blanko (PNG) — POST config, return PNG
declare(strict_types=1);
$u = require_role('penerjemah');
require_once __DIR__ . '/../includes/Attestation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
csrf_check();

$cfg = [
    'statement_id' => trim($_POST['statement_id'] ?? ''),
    'statement_foreign' => trim($_POST['statement_foreign'] ?? ''),
    'foreign_rtl' => isset($_POST['foreign_rtl']),
    'use_foreign_image' => isset($_POST['use_foreign_image']),
    'font_size_base' => max(8, min(24, (int)($_POST['font_size_base'] ?? 13))),
    'font_size_name' => max(8, min(28, (int)($_POST['font_size_name'] ?? 14))),
    'font_size_foreign' => max(8, min(28, (int)($_POST['font_size_foreign'] ?? 15))),
    'line_height' => max(1.0, min(2.5, (float)($_POST['line_height'] ?? 1.35))),
    'show_border' => isset($_POST['show_border']),
    'columns' => (int)($_POST['columns'] ?? 2) === 1 ? 1 : 2,
    'show_stamp' => isset($_POST['show_stamp']),
    'stamp_size' => max(80, min(400, (int)($_POST['stamp_size'] ?? 200))),
    'stamp_dx' => max(-600, min(600, (int)($_POST['stamp_dx'] ?? 0))),
    'stamp_dy' => max(-300, min(300, (int)($_POST['stamp_dy'] ?? 0))),
    'show_foreign_name' => isset($_POST['show_foreign_name']),
    'align_id' => in_array($_POST['align_id'] ?? '', ['left','center','right','justify'], true) ? $_POST['align_id'] : 'justify',
    'align_foreign' => in_array($_POST['align_foreign'] ?? '', ['left','center','right','justify'], true) ? $_POST['align_foreign'] : 'justify',
];

// profil penerjemah saat ini sebagai contoh
$pr = db()->prepare('SELECT * FROM users WHERE id = ?');
$pr->execute([$u['id']]);
$prov = $pr->fetch() ?: [];
$p = [
    'name' => $prov['name'] ?? 'NAMA PENERJEMAH',
    'sk' => $prov['sk'] ?? 'No. AHU-XX',
    'sk_date' => $prov['sk_date'] ?? '',
    'address' => $prov['address'] ?? 'Alamat contoh',
    'city' => $prov['city'] ?? 'Depok',
    'phone' => $prov['phone'] ?? '0812xxxx',
    'email' => $prov['email'] ?? 'email@contoh.com',
    'lang_pair' => trim($_POST['lang_pair'] ?? '') ?: 'Bahasa Indonesia ke Bahasa Asing',
];
$d = ['date_id' => '10 Oktober 2026', 'reg_no' => '2026-X-XXX-XXXX', 'doc_title' => 'Contoh'];

$stampImg = '/var/www/data/stamp-assets/cap-ttd.jpg';
if (!empty($prov['stamp_file']) && file_exists('/var/www/data/stamps/' . $prov['stamp_file']))
    $stampImg = '/var/www/data/stamps/' . $prov['stamp_file'];

$png = blanko_strip($p, $d, $stampImg, $cfg);
header('Content-Type: image/png');
header('Content-Length: ' . filesize($png));
header('Cache-Control: no-store');
readfile($png);
@unlink($png);
