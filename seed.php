<?php
// Seed akun demo Blanko. Jalankan via CLI di container: php /var/www/seed.php
declare(strict_types=1);
require_once '/var/www/html/includes/db.php';

$users = [
    ['Mitra Demo', 'mitra@blanko.demo', '6281234567890', 'mitra123', 'mitra'],
    ['Penerjemah Tersumpah Demo', 'tersumpah@blanko.demo', '6289876543210', 'tersumpah123', 'penerjemah'],
];

foreach ($users as [$name, $email, $phone, $pass, $role]) {
    $hash = password_hash($pass, PASSWORD_DEFAULT);
    $st = db()->prepare(
        "INSERT INTO users (name, email, phone, password_hash, role)
         VALUES (?,?,?,?,?)
         ON CONFLICT(email) DO UPDATE SET name=excluded.name, phone=excluded.phone,
             password_hash=excluded.password_hash, role=excluded.role"
    );
    $st->execute([$name, $email, $phone, $hash, $role]);
    echo "OK: $email / $pass ($role)\n";
}
