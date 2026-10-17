<?php
// Signed action tokens untuk link approve/decline via WA/email. HMAC-SHA256.
declare(strict_types=1);

function token_secret(): string {
    $f = '/var/www/data/.token_secret';
    if (!file_exists($f)) {
        file_put_contents($f, bin2hex(random_bytes(32)));
        chmod($f, 0600);
    }
    return trim((string)file_get_contents($f));
}

function token_issue(int $docId, string $action, int $ttlHours = 72): string {
    $raw = bin2hex(random_bytes(16));
    $sig = hash_hmac('sha256', $docId . ':' . $action . ':' . $raw, token_secret());
    $token = $raw . '.' . $sig;
    $exp = gmdate('Y-m-d H:i:s', time() + $ttlHours * 3600);
    $st = db()->prepare("INSERT INTO action_tokens (document_id, action, token, expires_at) VALUES (?,?,?,?)");
    $st->execute([$docId, $action, $token, $exp]);
    return $token;
}

function token_verify(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2) return null;
    [$raw, $sig] = $parts;
    $st = db()->prepare("SELECT * FROM action_tokens WHERE token = ?");
    $st->execute([$token]);
    $row = $st->fetch();
    if (!$row || $row['used_at'] !== null) return null;
    if (strtotime($row['expires_at']) < time()) return null;
    $expect = hash_hmac('sha256', $row['document_id'] . ':' . $row['action'] . ':' . $raw, token_secret());
    if (!hash_equals($expect, $sig)) return null;
    return $row;
}

function token_consume(int $id): void {
    $st = db()->prepare("UPDATE action_tokens SET used_at = datetime('now') WHERE id = ?");
    $st->execute([$id]);
}

function action_url(string $token): string {
    return app_url('?page=review&token=' . urlencode($token));
}
