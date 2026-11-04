<?php
// Notifikasi WA / Email / Inbox.
// WA: gateway diatur admin dari halaman ?page=gateway (tabel settings,
// lihat includes/settings.php) — tidak di-hardcode. Bila gateway nonaktif
// atau belum diatur, WA masuk mode log (tersimpan di DB + tampil di inbox).
// Email: kredensial SMTP dari environment; kosong = mode log juga.
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function notify_cfg(): array {
    // Konfigurasi email (SMTP). WA tidak lagi diatur di sini.
    return [
        'smtp_host'      => getenv('BLANKO_SMTP_HOST') ?: '',
        'smtp_port'      => (int)(getenv('BLANKO_SMTP_PORT') ?: 587),
        'smtp_user'      => getenv('BLANKO_SMTP_USER') ?: '',
        'smtp_pass'      => getenv('BLANKO_SMTP_PASS') ?: '',
        'smtp_from'      => getenv('BLANKO_SMTP_FROM') ?: 'noreply@blanko.local',
    ];
}

function notify_log(?int $userId, string $channel, string $recipient, string $body, ?string $subject, string $status): void {
    $st = db()->prepare(
        "INSERT INTO notifications (user_id, channel, recipient, subject, body, status) VALUES (?,?,?,?,?,?)"
    );
    $st->execute([$userId, $channel, $recipient, $subject, $body, $status]);
}

function notify_inbox(int $userId, string $subject, string $body): void {
    notify_log($userId, 'inbox', (string)$userId, $body, $subject, 'sent');
}

function notify_wa(?int $userId, string $phone, string $message): bool {
    $cfg = wa_gateway_cfg();
    if (!$cfg['enabled'] || $cfg['base_url'] === '') {
        notify_log($userId, 'wa', $phone, $message, null, 'logged');
        return false;
    }
    $r = wa_gateway_send($phone, $message);
    // 'sent' bila gateway menerima; 'failed' bila dicoba tapi ditolak/gagal
    // (dibedakan dari 'logged' = memang tidak dicoba).
    notify_log($userId, 'wa', $phone, $message, null, $r['ok'] ? 'sent' : 'failed');
    return $r['ok'];
}

function notify_email(?int $userId, string $to, string $subject, string $body): bool {
    $cfg = notify_cfg();
    $ok = false;
    if ($cfg['smtp_host'] !== '') {
        $ok = smtp_send($cfg, $to, $subject, $body);
    }
    notify_log($userId, 'email', $to, $body, $subject, $ok ? 'sent' : 'logged');
    return $ok;
}

// SMTP client minimal (native, tanpa library).
function smtp_send(array $cfg, string $to, string $subject, string $body): bool {
    try {
        $fp = fsockopen($cfg['smtp_host'], $cfg['smtp_port'], $errno, $errstr, 15);
        if (!$fp) return false;
        $rd = static function () use ($fp) { return fgets($fp, 1024); };
        $wr = static function (string $s) use ($fp) { fwrite($fp, $s . "\r\n"); };
        $rd();
        $wr('EHLO blanko'); $rd();
        $wr('STARTTLS'); $rd();
        stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
        $wr('EHLO blanko'); $rd();
        $wr('AUTH LOGIN'); $rd();
        $wr(base64_encode($cfg['smtp_user'])); $rd();
        $wr(base64_encode($cfg['smtp_pass'])); $rd();
        $wr('MAIL FROM:<' . $cfg['smtp_from'] . '>'); $rd();
        $wr('RCPT TO:<' . $to . '>'); $rd();
        $wr('DATA'); $rd();
        $headers = "From: {$cfg['smtp_from']}\r\nTo: {$to}\r\nSubject: {$subject}\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $wr($headers . $body . "\r\n."); $rd();
        $wr('QUIT');
        fclose($fp);
        return true;
    } catch (Throwable) {
        return false;
    }
}
