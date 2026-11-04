<?php
// Migrasi skema berversi untuk Blanko.
//
// Aturan main:
// - Skema hanya berubah lewat file baru di ../migrations/NNNN_nama.sql.
// - File migrasi yang sudah diterapkan TIDAK PERNAH diedit lagi.
// - Migrator ini berjalan sekali per proses pada koneksi pertama (dari db()).
// - Migrasi normal berjalan dalam satu transaksi; gagal = pengecualian
//   (keras, terlihat), bukan ditelan.
// - Versi 1-2 adalah adopsi warisan (baseline + migrate_profile lama):
//   basis data yang keadaannya sudah mencakup isinya ditandai diterapkan
//   TANPA mengeksekusi ulang. Khusus versi 2 ada jalur toleran warisan
//   untuk basis data sangat lama yang keadaannya belum lengkap.
declare(strict_types=1);

function blanko_migrate(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS _migrations (
        version INTEGER PRIMARY KEY,
        name TEXT NOT NULL,
        applied_at TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    $applied = [];
    foreach ($pdo->query("SELECT version FROM _migrations")->fetchAll(PDO::FETCH_COLUMN) as $v) {
        $applied[(int)$v] = true;
    }

    $files = glob(__DIR__ . '/../migrations/*.sql') ?: [];
    sort($files, SORT_STRING);
    foreach ($files as $file) {
        $base = basename($file);
        if (!preg_match('/^(\d{4})_/', $base, $m)) {
            continue;
        }
        $version = (int)$m[1];
        if (isset($applied[$version])) {
            continue;
        }
        $sql = (string)file_get_contents($file);

        if ($version <= 2 && blanko_legacy_state($pdo, $version)) {
            blanko_mark_applied($pdo, $version, $base);
            continue;
        }
        if ($version === 2) {
            // Jalur toleran warisan (meniru perilaku db.php lama): pernyataan
            // yang sudah terwakili di skema diabaikan satu per satu.
            foreach (explode(';', $sql) as $q) {
                $q = trim($q);
                if ($q === '') {
                    continue;
                }
                try {
                    $pdo->exec($q);
                } catch (PDOException) {
                }
            }
            blanko_mark_applied($pdo, $version, $base);
            continue;
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec($sql);
            blanko_mark_applied($pdo, $version, $base);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

// TRUE bila basis data (yang dibuat sebelum sistem versi ada) sudah
// mencakup isi versi tersebut, sehingga cukup ditandai diterapkan.
function blanko_legacy_state(PDO $pdo, int $version): bool
{
    $hasUsers = (bool)$pdo->query(
        "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'users'"
    )->fetchColumn();
    if (!$hasUsers) {
        return false;
    }
    if ($version === 1) {
        return true;
    }
    // Penanda versi 2: kolom hasil migrate_profile.sql pada documents.
    $cols = $pdo->query("PRAGMA table_info(documents)")->fetchAll(PDO::FETCH_COLUMN, 1);
    return in_array('halaman_sumber', $cols, true);
}

function blanko_mark_applied(PDO $pdo, int $version, string $name): void
{
    $st = $pdo->prepare("INSERT OR IGNORE INTO _migrations (version, name) VALUES (?, ?)");
    $st->execute([$version, $name]);
}
