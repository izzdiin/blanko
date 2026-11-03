<?php
// Backfill Rekap Tahunan (CLI saja, aman dijalankan berulang):
// 1) halaman hasil lama dari berkas hasil (stamped_name) — kolom lama;
// 2) halaman TEKS SUMBER dari berkas asli unggahan (stored_name);
// 3) nomor register format Blanko HANYA untuk dokumen yang di-approve pada
//    bulan & tahun berjalan (rumus memakai tanggal approve, jadi untuk bulan
//    lain nilainya tidak bisa direkonstruksi pasti — penerjemah mengisinya
//    sendiri lewat panel, memang boleh format bebas);
// 4) jenis dokumen SENGAJA dibiarkan kosong -> daftar "perlu dilengkapi".
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

// --- halaman teks sumber dari berkas asli ---
$rows2 = $db->query(
    "SELECT id, stored_name FROM documents
     WHERE status = 'approved' AND halaman_sumber IS NULL AND stored_name IS NOT NULL AND stored_name <> ''"
)->fetchAll();
$upd2 = $db->prepare('UPDATE documents SET halaman_sumber = ? WHERE id = ?');
$ok2 = 0; $gagal2 = 0;
foreach ($rows2 as $r) {
    $n = rekap_pdf_pages('/var/www/data/uploads/' . basename((string)$r['stored_name']));
    if ($n === null) { $gagal2++; continue; }
    $upd2->execute([$n, $r['id']]);
    $ok2++;
}
echo "halaman teks sumber: {$ok2} terisi, {$gagal2} gagal, " . count($rows2) . " kandidat.\n";

// --- nomor register: hanya approve bulan & tahun berjalan (rekonstruksi pasti) ---
$ym = date('Y-m');
$rows3 = $db->query(
    "SELECT d.id, d.reviewed_at, u.name AS mitra_name FROM documents d
     JOIN users u ON u.id = d.user_id
     WHERE d.status = 'approved' AND (d.reg_no IS NULL OR d.reg_no = '')
       AND substr(d.reviewed_at, 1, 7) = '{$ym}'"
)->fetchAll();
$upd3 = $db->prepare('UPDATE documents SET reg_no = ? WHERE id = ?');
foreach ($rows3 as $r) {
    $upd3->execute([rekap_reg_saran((string)$r['mitra_name'], (int)$r['id'], (string)$r['reviewed_at']), $r['id']]);
}
echo "nomor register (bulan {$ym}): " . count($rows3) . " terisi.\n";
