<?php
// Migrasi satu kali: lebarkan CHECK role pada tabel users agar menerima
// peran 'admin' (akun admin terpisah dari penerjemah). SQLite tidak bisa
// mengubah CHECK di tempat, jadi tabel dibangun ulang dari definisi live
// (semua kolom & indeks dipertahankan). Idempoten: bila CHECK sudah memuat
// 'admin', tidak melakukan apa-apa.
//
// Jalan sebagai www-data: docker exec -u www-data blanko php /var/www/html/cli/migrate_admin_role.php
declare(strict_types=1);
require_once __DIR__ . '/../includes/db.php';

$pdo = db();
$sql = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
if ($sql === '') { fwrite(STDERR, "tabel users tidak ditemukan\n"); exit(1); }
if (strpos($sql, "'admin'") !== false) { echo "sudah: CHECK role memuat 'admin'.\n"; exit(0); }
if (strpos($sql, "CHECK(role IN ('mitra','penerjemah'))") === false) {
    fwrite(STDERR, "pola CHECK role tidak dikenal; batal.\n"); exit(1);
}

$before = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$newSql = str_replace(
    "CHECK(role IN ('mitra','penerjemah'))",
    "CHECK(role IN ('mitra','penerjemah','admin'))",
    $sql
);
$newSql = preg_replace('/^CREATE TABLE users\b/', 'CREATE TABLE users_new', $newSql, 1);

$pdo->exec('PRAGMA foreign_keys = OFF');
try {
    $pdo->beginTransaction();
    $pdo->exec('DROP TABLE IF EXISTS users_new');
    $pdo->exec($newSql);
    $pdo->exec('INSERT INTO users_new SELECT * FROM users');
    $pdo->exec('DROP TABLE users');
    $pdo->exec('ALTER TABLE users_new RENAME TO users');
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    $pdo->exec('PRAGMA foreign_keys = ON');
    fwrite(STDERR, 'gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
$pdo->exec('PRAGMA foreign_keys = ON');
$fk = $pdo->query('PRAGMA foreign_key_check')->fetchAll();
$after = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$check = (string)$pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='users'")->fetchColumn();
echo "migrasi selesai: users $before -> $after baris; FK check: " . count($fk) . " pelanggaran; CHECK admin: "
    . (strpos($check, "'admin'") !== false ? 'ya' : 'TIDAK') . "\n";
