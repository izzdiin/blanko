<?php
// PDO SQLite singleton. Native PHP 8, prepared statements everywhere.
// Skema dikelola migrasi berversi — lihat includes/migrate.php dan
// folder migrations/. db() tidak lagi mengeksekusi schema.sql /
// migrate_profile.sql (keduanya dibekukan sebagai migrations/0001-0002).
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
        require_once __DIR__ . '/migrate.php';
        blanko_migrate($pdo);
    }
    return $pdo;
}
