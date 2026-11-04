<?php
// Pengaturan dinamis (tabel settings) + konfigurasi gateway WhatsApp.
//
// Gateway WA TIDAK di-hardcode: admin mengaturnya dari halaman
// ?page=gateway. Urutan sumber konfigurasi WA:
//   1. tabel settings (halaman admin) — sumber utama
//   2. env BLANKO_WA_URL / BLANKO_WA_DEVICE_ID / BLANKO_WA_TOKEN —
//      hanya sebagai nilai awal bila baris settings belum ada
//   3. kosong -> mode log (notifikasi hanya dicatat, tidak dikirim)
declare(strict_types=1);

function app_setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT key, value FROM settings") as $r) {
                $cache[$r['key']] = $r['value'];
            }
        } catch (PDOException) {
            // tabel belum ada (migrasi belum jalan) -> semua default
        }
    }
    return $cache[$key] ?? $default;
}

function app_setting_set(string $key, string $value): void
{
    $st = db()->prepare(
        "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at"
    );
    $st->execute([$key, $value]);
}

// Konfigurasi gateway WA efektif saat ini.
function wa_gateway_cfg(): array
{
    $tplDefault = '{"device_id":"{device_id}","to":"{to}","message":"{message}"}';
    return [
        'enabled'  => app_setting('wa_enabled', '0') === '1',
        'base_url' => app_setting('wa_base_url', getenv('BLANKO_WA_URL') ?: '') ?? '',
        'send_path' => app_setting('wa_send_path', '/send') ?: '/send',
        'device_id' => app_setting('wa_device_id', getenv('BLANKO_WA_DEVICE_ID') ?: '') ?? '',
        'token'    => app_setting('wa_token', getenv('BLANKO_WA_TOKEN') ?: '') ?? '',
        'payload_template' => app_setting('wa_payload_template', $tplDefault) ?: $tplDefault,
    ];
}

// Tangani POST pengaturan gateway (simpan / kirim tes) — dipakai bersama
// oleh halaman ?page=gateway dan tab Gateway WA di ?page=settings.
// $backUrl = alamat kembali setelah POST.
function wa_gateway_handle_post(array $u, string $backUrl): void
{
    if (($u['role'] ?? '') !== 'admin' || $_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!isset($_POST['save']) && !isset($_POST['test'])) return;
    require_once __DIR__ . '/Notify.php';
    csrf_check();
    if (isset($_POST['save'])) {
        $baseUrl = trim($_POST['wa_base_url'] ?? '');
        $sendPath = trim($_POST['wa_send_path'] ?? '/send');
        $deviceId = trim($_POST['wa_device_id'] ?? '');
        $token = trim($_POST['wa_token'] ?? '');
        $tpl = trim($_POST['wa_payload_template'] ?? '');
        $err = null;
        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            $err = 'Base URL harus diawali http:// atau https://.';
        } elseif ($sendPath === '' || $sendPath[0] !== '/') {
            $err = 'Path kirim harus diawali garis miring, mis. /send.';
        } elseif ($tpl !== '') {
            $probe = strtr($tpl, ['{to}' => '62000', '{message}' => 'tes', '{device_id}' => 'dev']);
            if (json_decode($probe) === null) {
                $err = 'Templat payload harus menghasilkan JSON yang sah setelah placeholder diganti.';
            }
        }
        if ($err !== null) {
            flash($err, 'err');
        } else {
            app_setting_set('wa_enabled', isset($_POST['wa_enabled']) ? '1' : '0');
            app_setting_set('wa_base_url', $baseUrl);
            app_setting_set('wa_send_path', $sendPath);
            app_setting_set('wa_device_id', $deviceId);
            // Token: kosong = pertahankan yang lama, kecuali admin menandai hapus.
            if (!empty($_POST['wa_token_clear'])) {
                app_setting_set('wa_token', '');
            } elseif ($token !== '') {
                app_setting_set('wa_token', $token);
            }
            app_setting_set('wa_payload_template', $tpl);
            flash('Pengaturan gateway WhatsApp tersimpan.');
        }
        redirect($backUrl);
    }
    // Kirim tes memakai pengaturan yang tersimpan.
    $phone = preg_replace('/\D+/', '', $_POST['test_phone'] ?? '');
    if ($phone === '') {
        flash('Isi nomor tujuan tes dulu.', 'err');
        redirect($backUrl);
    }
    $msg = '[Blanko] Pesan tes gateway WhatsApp — abaikan pesan ini.';
    $r = wa_gateway_send($phone, $msg);
    $cfgNow = wa_gateway_cfg();
    $attempted = $cfgNow['enabled'] && $cfgNow['base_url'] !== '';
    notify_log((int)$u['id'], 'wa', $phone, $msg, null, !$attempted ? 'logged' : ($r['ok'] ? 'sent' : 'failed'));
    $_SESSION['wa_test_result'] = $r + ['attempted' => $attempted];
    redirect($backUrl);
}

// Data untuk panel gateway (dipakai halaman gateway + tab settings).
function wa_gateway_panel_data(): array
{
    $testResult = null;
    if (!empty($_SESSION['wa_test_result'])) {
        $testResult = $_SESSION['wa_test_result'];
        unset($_SESSION['wa_test_result']);
    }
    $cfg = wa_gateway_cfg();
    return [
        'cfg' => $cfg,
        'hasToken' => $cfg['token'] !== '',
        'mode' => !$cfg['enabled'] ? 'Nonaktif' : ($cfg['base_url'] === '' ? 'Aktif, tapi Base URL kosong (mode log)' : 'Aktif'),
        'lastWa' => db()->query(
            "SELECT recipient, status, created_at FROM notifications WHERE channel='wa' ORDER BY id DESC LIMIT 5"
        )->fetchAll(),
        'testResult' => $testResult,
    ];
}

// Kirim satu pesan WA lewat gateway yang diatur admin.
// Mengembalikan ['ok'=>bool, 'http'=>int, 'response'=>string, 'error'=>string].
function wa_gateway_send(string $phone, string $message): array
{
    $cfg = wa_gateway_cfg();
    if (!$cfg['enabled'] || $cfg['base_url'] === '') {
        return ['ok' => false, 'http' => 0, 'response' => '', 'error' => 'gateway nonaktif / base_url kosong'];
    }
    $path = '/' . ltrim($cfg['send_path'], '/');
    $url = rtrim($cfg['base_url'], '/') . $path;
    $body = strtr($cfg['payload_template'], [
        '{to}' => $phone,
        '{message}' => $message,
        '{device_id}' => $cfg['device_id'],
    ]);
    if (json_decode($body) === null) {
        // Templat menghasilkan JSON tidak sah -> jatuh ke bentuk baku.
        $body = (string)json_encode([
            'device_id' => $cfg['device_id'],
            'to' => $phone,
            'message' => $message,
        ]);
    }
    $headers = ['Content-Type: application/json'];
    if ($cfg['token'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $cfg['token'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $res = curl_exec($ch);
    if ($res === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['ok' => false, 'http' => 0, 'response' => '', 'error' => $err];
    }
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['ok' => $code < 400, 'http' => $code, 'response' => (string)$res, 'error' => ''];
}
