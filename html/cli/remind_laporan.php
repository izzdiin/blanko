<?php
// Pengingat tenggat laporan tahunan (F5, CLI saja — dijalankan cron harian).
// Untuk penerjemah dengan Tahun Lapor aktif: pada H-30 dan H-7 dari tanggal
// selesai periode, kirim pengingat via WA + email (+ inbox). Setiap kanal
// dicatat di report_reminder_log agar tidak terkirim dobel; status kirim
// sebenarnya (sent/logged/failed) tercatat di tabel notifications oleh
// Notify.php — bila gateway WA/SMTP belum dikonfigurasi, status = 'logged'.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Notify.php';
require_once __DIR__ . '/../includes/Rekap.php';

$db = db();
$base = getenv('BLANKO_BASE_URL') ?: 'https://blanko.netter.my.id';
$today = date('Y-m-d');

$users = $db->query(
    "SELECT * FROM users
     WHERE role = 'penerjemah' AND status = 'active'
       AND rekap_periode_aktif = 1
       AND rekap_periode_selesai IS NOT NULL AND rekap_periode_selesai <> ''"
)->fetchAll();

$sent = 0;
foreach ($users as $p) {
    $end = (string)$p['rekap_periode_selesai'];
    $days = (int)round((strtotime($end) - strtotime($today)) / 86400);
    $kind = $days === 30 ? 'H-30' : ($days === 7 ? 'H-7' : null);
    if ($kind === null) continue;

    $from = (string)($p['rekap_periode_mulai'] ?: $today);
    $arah = lang_pair_display($p['lang_pair'] ?? '', $p['lang_reverse'] ?? 0);
    $rows = rekap_rows($db, (int)$p['id'], $from, $end, $arah);
    $inc = 0;
    foreach ($rows as $r) if (empty($r['jenis']) || $r['halaman'] === null) $inc++;
    $mirrors = rekap_pencocokan($db, (int)$p['id'], $from, $end);

    $msg = "Blanko: Pengingat laporan tahunan penerjemah tersumpah.\n"
        . "Tenggat: " . rekap_fmt_tanggal($end) . " ({$days} hari lagi).\n"
        . "Periode: " . rekap_fmt_tanggal($from) . " s.d. " . rekap_fmt_tanggal($end) . ".\n"
        . "Pekerjaan tercatat: " . count($rows) . ($inc ? " ({$inc} belum lengkap)" : "") . ".\n"
        . "Catatan pencocokan dari mitra: " . count($mirrors) . ".\n"
        . "Buka rekap: {$base}/?page=rekap";

    $chk = $db->prepare('SELECT COUNT(*) FROM report_reminder_log WHERE user_id=? AND period_end=? AND kind=?');
    $chk->execute([$p['id'], $end, $kind]);
    $firstRun = (int)$chk->fetchColumn() === 0;

    $ins = $db->prepare('INSERT OR IGNORE INTO report_reminder_log (user_id, period_end, kind, channel) VALUES (?,?,?,?)');
    foreach (['wa', 'email'] as $ch) {
        $done = $db->prepare('SELECT COUNT(*) FROM report_reminder_log WHERE user_id=? AND period_end=? AND kind=? AND channel=?');
        $done->execute([$p['id'], $end, $kind, $ch]);
        if ((int)$done->fetchColumn() > 0) continue;
        if ($ch === 'wa') {
            if (!empty($p['phone'])) notify_wa((int)$p['id'], $p['phone'], $msg);
        } else {
            if (!empty($p['email'])) notify_email((int)$p['id'], $p['email'], 'Pengingat laporan tahunan — Blanko', $msg);
        }
        $ins->execute([$p['id'], $end, $kind, $ch]);
        $sent++;
    }
    if ($firstRun) notify_inbox((int)$p['id'], 'Pengingat laporan tahunan (' . $kind . ')', $msg);
    echo "user {$p['id']} ({$p['name']}): {$kind} tenggat {$end}\n";
}
echo "remind_laporan selesai: {$sent} pengiriman kanal diproses.\n";
