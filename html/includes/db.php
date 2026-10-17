<?php
// PDO SQLite singleton. Native PHP 8, prepared statements everywhere.
declare(strict_types=1);

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dbFile = '/var/www/data/blanko.sqlite';
        $dir = dirname($dbFile);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $pdo = new PDO('sqlite:' . $dbFile, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("PRAGMA foreign_keys = ON");
        $pdo->exec("PRAGMA journal_mode = WAL");
        $pdo->exec("PRAGMA busy_timeout = 5000");
        $schema = file_get_contents(__DIR__ . '/schema.sql');
        $pdo->exec($schema);
        // migrasi profil penerjemah (abaikan jika kolom sudah ada)
        $mig = file_get_contents(__DIR__ . '/migrate_profile.sql');
        foreach (explode(';', $mig) as $q) {
            $q = trim($q);
            if ($q === '') continue;
            try { $pdo->exec($q); } catch (PDOException) {}
        }
    }
    return $pdo;
}
