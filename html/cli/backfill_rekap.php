<?php
// Backfill Rekap Tahunan (CLI saja, aman dijalankan berulang):
// hitung halaman dokumen approved lama dari berkas hasil (stamped_name).
// Jenis dokumen SENGAJA dibiarkan kosong -> masuk daftar "perlu dilengkapi".
// Menjalankan skrip ini juga menerapkan migrasi skema (db() menjalankannya).
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Rekap.php';

$db = db();
$rows = $db->query(
    "SELECT id, stamped_name FROM documents
     WHERE status = 'approved' AND halaman IS NULL AND stamped_name IS NOT NULL AND stamped_name <> ''"
)->fetchAll();

$upd = $db->prepare('UPDATE documents SET halaman = ? WHERE id = ?');
$ok = 0; $gagal = 0;
foreach ($rows as $r) {
    $n = rekap_pdf_pages('/var/www/data/uploads/' . basename((string)$r['stamped_name']));
    if ($n === null) { $gagal++; continue; }
    $upd->execute([$n, $r['id']]);
    $ok++;
}
echo "backfill selesai: {$ok} dokumen terisi halaman, {$gagal} gagal dihitung, "
    . count($rows) . " kandidat.\n";
