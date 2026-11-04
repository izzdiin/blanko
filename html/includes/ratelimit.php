<?php
// Rate limiting: perlindungan brute-force login + throttle upload.
//
// Login: percobaan gagal dicatat per email dan per IP (tabel
// login_attempts). Ambang email lebih ketat (melindungi akun), ambang
// IP lebih longgar (melindungi dari penyemprotan kata sandi tanpa
// mengunci banyak pengguna di balik satu NAT). Selama terkunci,
// percobaan DITOLAK TANPA DICATAT, jadi kunci selalu berakhir paling
// lambat RL_LOCK_MIN menit setelah kegagalan terakhir yang tercatat.
// Login yang berhasil mengosongkan penghitung email tersebut.
declare(strict_types=1);

const RL_EMAIL_MAX = 5;    // gagal per email -> kunci
const RL_IP_MAX = 20;      // gagal per IP -> kunci
const RL_WINDOW_MIN = 15;  // jendela hitung percobaan
const RL_LOCK_MIN = 15;    // lama kunci setelah ambang tercapai

function rl_client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function rl_id_email(string $email): string
{
    return 'e:' . mb_strtolower(trim($email));
}

function rl_id_ip(string $ip): string
{
    return 'i:' . $ip;
}

// Detik sisa kunci untuk satu identifier; 0 = tidak terkunci.
function rl_locked_for(string $identifier, int $max): int
{
    $st = db()->prepare(
        "SELECT COUNT(*) AS c, MAX(attempted_at) AS last FROM login_attempts
         WHERE identifier = ? AND attempted_at > datetime('now', ?)"
    );
    $st->execute([$identifier, '-' . RL_WINDOW_MIN . ' minutes']);
    $r = $st->fetch();
    if (!$r || (int)$r['c'] < $max || empty($r['last'])) {
        return 0;
    }
    $unlock = strtotime($r['last'] . ' UTC') + RL_LOCK_MIN * 60;
    return max(0, $unlock - time());
}

// Detik sisa kunci login (yang lebih lama antara kunci email dan IP).
function rl_login_locked_seconds(string $email, string $ip): int
{
    return max(
        rl_locked_for(rl_id_email($email), RL_EMAIL_MAX),
        rl_locked_for(rl_id_ip($ip), RL_IP_MAX)
    );
}

function rl_login_record_failure(string $email, string $ip): void
{
    // Sekalian rapikan catatan lama agar tabel tetap kecil.
    db()->exec("DELETE FROM login_attempts WHERE attempted_at < datetime('now', '-1 day')");
    $st = db()->prepare("INSERT INTO login_attempts (identifier) VALUES (?)");
    $st->execute([rl_id_email($email)]);
    $st->execute([rl_id_ip($ip)]);
}

function rl_login_clear_email(string $email): void
{
    $st = db()->prepare("DELETE FROM login_attempts WHERE identifier = ?");
    $st->execute([rl_id_email($email)]);
}

// Jumlah dokumen yang diunggah pengguna ini dalam 60 menit terakhir
// (dokumen yang tersimpan = upload yang berhasil).
function rl_uploads_last_hour(int $userId): int
{
    $st = db()->prepare(
        "SELECT COUNT(*) FROM documents WHERE user_id = ? AND created_at > datetime('now', '-60 minutes')"
    );
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}
