<?php
// Preview dokumen untuk editor e-sign: komposit dokumen + blanko (tanpa QR),
// atau dokumen sumber mentah bila raw=1 (untuk editor geser pengesahan mode strip).
// Dibuat on-the-fly dari salinan temp, dialirkan, lalu dihapus — tidak
// menulis ke uploads dan tidak mengubah state apa pun.
declare(strict_types=1);
require_once __DIR__ . '/../includes/Token.php';
require_once __DIR__ . '/../includes/Attestation.php';
require_once __DIR__ . '/../includes/HtmlAttestation.php';

$tokenRow = null;
if (!empty($_GET['token'])) {
    $tokenRow = token_verify($_GET['token']);
    if (!$tokenRow) { http_response_code(403); exit('Link tidak valid atau kedaluwarsa.'); }
}
$docId = $tokenRow ? (int)$tokenRow['document_id'] : (int)($_GET['id'] ?? 0);
$u = current_user();
$isPenerjemah = $u && $u['role'] === 'penerjemah';
if (!$tokenRow && !$isPenerjemah) { redirect('?page=login'); }
$reviewer = $isPenerjemah ? (int)$u['id'] : (int)(db()->query("SELECT id FROM users WHERE role='penerjemah' LIMIT 1")->fetchColumn());

$st = db()->prepare(
    "SELECT d.*, u.name AS mitra_name FROM documents d JOIN users u ON u.id = d.user_id WHERE d.id = ?"
);
$st->execute([$docId]);
$d = $st->fetch();
if (!$d) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }

$pr = db()->prepare('SELECT * FROM users WHERE id = ?');
$pr->execute([$reviewer]);
$prov = $pr->fetch();
if (!$prov) { http_response_code(404); exit('Penerjemah tidak ditemukan.'); }
$mitraShort = strtoupper(preg_replace('/[^A-Z]/i', '', explode(' ', $d['mitra_name'])[0] ?? 'XX'));
$regNo = reg_number($docId, substr($mitraShort, 0, 3), 'TRJ');
$bln = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$dateId = date('d') . ' ' . $bln[(int)date('n') - 1] . ' ' . date('Y');
$prof = [
    'name' => $prov['name'] ?: 'Penerjemah Tersumpah',
    'ar_name' => $prov['name_ar'] ?: '',
    'name_zh' => $prov['name_zh'] ?? '',
    'sk' => $prov['sk'] ?: '',
    'sk_date' => $prov['sk_date'] ?? '',
    'address' => $prov['address'] ?: '',
    'city' => $prov['city'] ?: '',
    'phone' => $prov['phone'] ?: '',
    'email' => $prov['email'] ?: '',
    'lang_pair' => $prov['lang_pair'] ?: '',
];
$dyn = ['date_id' => $dateId, 'reg_no' => $regNo, 'doc_title' => $d['title']];

$blankoPages = $_GET['pages'] ?? 'last';
if (!in_array($blankoPages, ['first', 'last', 'all'], true)) $blankoPages = 'last';
$tplRow = null;
$tplId = (int)($_GET['template_id'] ?? 0);
if ($tplId > 0) {
    $ts = db()->prepare('SELECT * FROM blanko_templates WHERE id = ?');
    $ts->execute([$tplId]);
    $tplRow = $ts->fetch() ?: null;
} else {
    $ts = db()->prepare('SELECT * FROM blanko_templates WHERE user_id = ? AND is_default = 1 LIMIT 1');
    $ts->execute([$reviewer]);
    $tplRow = $ts->fetch() ?: null;
}
$tplCfg = $tplRow ? (json_decode($tplRow['config'] ?? '{}', true) ?: []) : [];
$tplCfg['show_stamp'] = false; // mode e-sign: strip tanpa cap
$tplIsHtml = $tplRow && trim((string)($tplRow['body_html'] ?? '')) !== '';
$tplPlacement = $tplRow && in_array($tplRow['placement'] ?? '', ['strip','page'], true) ? $tplRow['placement'] : 'strip';
$tplLangPair = $tplRow['lang_pair'] ?? '';

$src = '/var/www/data/uploads/' . $d['stored_name'];
if (!is_file($src)) { http_response_code(404); exit('File dokumen tidak ditemukan.'); }
$base = tempnam(sys_get_temp_dir(), 'espv');
@unlink($base);
$ext = str_contains($d['mime'], 'pdf') ? '.pdf' : (str_contains($d['mime'], 'png') ? '.png' : '.jpg');
$tmpSrc = $base . $ext;
if (!copy($src, $tmpSrc)) { http_response_code(500); exit('Gagal membuat preview.'); }
if (($_GET['raw'] ?? '') === '1') {
    if (!str_contains($d['mime'], 'pdf') && $tplIsHtml) {
        // Dokumen gambar + template HTML: preview mentah = halaman A4 berisi gambar
        // saja (tanpa strip), agar fraksi overlay sama persis dengan halaman hasil
        // approve (image-base PDF juga A4 dengan penempatan gambar yang sama).
        $rawBase = html_image_base_pdf($tmpSrc, $d['mime']);
        if ($rawBase === '') { http_response_code(500); exit('Gagal membuat preview.'); }
        header('Content-Type: application/pdf');
        header('Content-Length: ' . filesize($rawBase));
        header('Cache-Control: no-store');
        readfile($rawBase);
        @unlink($tmpSrc); @unlink($rawBase);
        exit;
    }
    $rawMime = str_contains($d['mime'], 'pdf') ? 'application/pdf' : (str_contains($d['mime'], 'png') ? 'image/png' : 'image/jpeg');
    header('Content-Type: ' . $rawMime);
    header('Content-Length: ' . filesize($tmpSrc));
    header('Cache-Control: no-store');
    readfile($tmpSrc);
    @unlink($tmpSrc);
    exit;
}
if ($tplIsHtml) {
    [$out, $merr] = html_merge_document($tmpSrc, $d['mime'], (string)$tplRow['body_html'], $prof, $dyn, $tplLangPair, $tplPlacement, $blankoPages, (string)($tplRow['font'] ?? ''));
    if ($out === '') { http_response_code(500); exit('Gagal membuat preview: ' . $merr); }
} else {
    $out = blanko_append($tmpSrc, $d['mime'], $prof, $dyn, '', $blankoPages, $tplCfg);
}
@unlink($tmpSrc);
if ($out === '' || !is_file($out)) { http_response_code(500); exit('Gagal membuat preview.'); }
$isPdf = $tplIsHtml || str_contains($d['mime'], 'pdf');
header('Content-Type: ' . ($isPdf ? 'application/pdf' : 'image/jpeg'));
header('Content-Length: ' . filesize($out));
header('Cache-Control: no-store');
readfile($out);
@unlink($out);
