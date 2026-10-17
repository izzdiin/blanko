<?php
// Notifikasi WA / Email / Inbox. Pluggable: isi kredensial di config untuk kirim asli,
// default mode log (tersimpan di DB + tampil di inbox).
declare(strict_types=1);

function notify_cfg(): array {
    // TODO produksi: isi dari environment / config aman
    return [
        'wa_gateway_url' => getenv('BLANKO_WA_URL') ?: '',   // POST JSON {to, message}
        'wa_token'       => getenv('BLANKO_WA_TOKEN') ?: '',
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
    $cfg = notify_cfg();
    $ok = false;
    if ($cfg['wa_gateway_url'] !== '') {
        $payload = json_encode(['to' => $phone, 'message' => $message]);
        $ch = curl_init($cfg['wa_gateway_url']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
                + ($cfg['wa_token'] !== '' ? ['Authorization: Bearer ' . $cfg['wa_token']] : []),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $res = curl_exec($ch);
        $ok = $res !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);
    }
    notify_log($userId, 'wa', $phone, $message, null, $ok ? 'sent' : 'logged');
    return $ok;
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
