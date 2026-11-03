<?php
// Ekspor Rekap Tahunan (F4): PDF / CSV / XLSX — isi persis periode yang
// dipilih di panel. Hanya baris RESMI (dokumen Blanko + entri penerjemah);
// catatan pencocokan mitra tidak pernah masuk ekspor.
declare(strict_types=1);
require_once __DIR__ . '/../includes/Rekap.php';

$u = require_role('penerjemah');
$db = db();

$st = $db->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$u['id']]);
$prof = $st->fetch();

$format = $_GET['format'] ?? '';
if (!in_array($format, ['pdf', 'csv', 'xlsx'], true)) {
    http_response_code(400);
    exit('Format ekspor tidak dikenal.');
}

[$from, $to, $view, $labelPeriode] = rekap_resolve_range($prof, $_GET);
$arahDefault = lang_pair_display($prof['lang_pair'] ?? '', $prof['lang_reverse'] ?? 0);
$rows = rekap_rows($db, (int)$u['id'], $from, $to, $arahDefault);

$slug = trim(preg_replace('/[^a-z0-9]+/i', '-', (string)$prof['name']), '-') ?: 'penerjemah';
$base = 'rekap-tahunan-' . $slug . '-' . $from . '_' . $to;
$header = ['Nomor Register', 'Tanggal', 'Jenis Dokumen Terjemahan', 'Halaman Teks Sumber', 'Arah Bahasa', 'Identitas Pengguna Jasa', 'Judul', 'Sumber'];

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $base . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM agar rapi di Excel Indonesia
    fputcsv($out, $header, ';', '"', '\\');
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['reg_no'] ?: '',
            rekap_fmt_tanggal($r['tanggal']),
            $r['jenis'] ?: '',
            $r['halaman'] !== null ? $r['halaman'] : '',
            $r['arah'] ?: '',
            rekap_klien_label($r['klien_tipe'], $r['klien']) === '-' ? '' : rekap_klien_label($r['klien_tipe'], $r['klien']),
            $r['judul'],
            $r['sumber'],
        ], ';', '"', '\\');
    }
    fclose($out);
    exit;
}

if ($format === 'xlsx') {
    $data = [];
    foreach ($rows as $r) {
        $data[] = [
            $r['reg_no'] ?: '',
            $r['tanggal'],
            $r['jenis'] ?: '',
            $r['halaman'],
            $r['arah'] ?: '',
            rekap_klien_label($r['klien_tipe'], $r['klien']) === '-' ? '' : rekap_klien_label($r['klien_tipe'], $r['klien']),
            $r['judul'],
            $r['sumber'],
        ];
    }
    $bin = rekap_xlsx_build('Rekap Tahunan', $header, $data, [1]);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
    header('Content-Length: ' . strlen($bin));
    echo $bin;
    exit;
}

// PDF via mPDF; kop dibangun dari profil penerjemah (nama, SK, alamat,
// pasangan bahasa) karena template blanko tidak memuat kop surat.
require_once __DIR__ . '/../vendor/autoload.php';
$tmpDir = '/var/www/data/mpdf-tmp';
if (!is_dir($tmpDir)) mkdir($tmpDir, 0775, true);
$mpdf = new \Mpdf\Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 18, 'margin_right' => 18, 'margin_top' => 16, 'margin_bottom' => 16,
    'tempDir' => $tmpDir,
    'fontDir' => ['/var/www/html/vendor/mpdf/ttfonts', '/var/www/data/fonts'],
    'default_font' => 'dejavusans',
]);

$totHal = 0; $nInc = 0; $incDetail = [];
$labelInc = ['register' => 'nomor register kosong', 'jenis' => 'jenis dokumen kosong', 'halaman' => 'halaman teks sumber kosong', 'klien' => 'identitas pengguna jasa kosong', 'identitas' => 'identitas pengguna jasa masih berupa kategori, belum nama'];
foreach ($rows as $r) {
    if ($r['halaman'] !== null) $totHal += $r['halaman'];
    if (!empty($r['kurang'])) {
        $nInc++;
        foreach ($r['kurang'] as $c) $incDetail[$c] = ($incDetail[$c] ?? 0) + 1;
    }
}
$incText = [];
foreach ($incDetail as $c => $n) $incText[] = ($labelInc[$c] ?? $c) . ' (' . $n . ')';

$skLine = trim((string)($prof['sk'] ?? ''));
if (!empty($prof['sk_date'])) $skLine .= ($skLine !== '' ? ' — ' : '') . 'Tgl. ' . $prof['sk_date'];
$addrLine = trim(trim((string)($prof['address'] ?? '')) . ' ' . trim((string)($prof['city'] ?? '')));

$tbl = '';
foreach ($rows as $i => $r) {
    $tbl .= '<tr>'
        . '<td style="text-align:center">' . ($i + 1) . '</td>'
        . '<td>' . e(rekap_fmt_tanggal($r['tanggal'])) . '</td>'
        . '<td>' . e($r['reg_no'] ?: '-') . '</td>'
        . '<td>' . e($r['judul']) . '</td>'
        . '<td>' . e($r['jenis'] ?: '-') . '</td>'
        . '<td>' . e($r['arah'] ?: '-') . '</td>'
        . '<td style="text-align:right">' . ($r['halaman'] !== null ? (int)$r['halaman'] : '-') . '</td>'
        . '<td>' . e(rekap_klien_label($r['klien_tipe'], $r['klien'])) . '</td>'
        . '</tr>';
}
if ($tbl === '') $tbl = '<tr><td colspan="8" style="text-align:center">Tidak ada pekerjaan tercatat pada periode ini.</td></tr>';

$html = '<style>
  body { font-family: dejavusans; font-size: 10pt; color: #111; }
  .kop { text-align: center; border-bottom: 2.5px solid #111; padding-bottom: 8px; margin-bottom: 14px; }
  .kop .nama { font-size: 15pt; font-weight: bold; }
  .kop .sub { font-size: 9.5pt; }
  h1 { font-size: 13pt; text-align: center; margin: 10px 0 2px; }
  .periode { text-align: center; font-size: 10pt; margin-bottom: 12px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { border: 0.5pt solid #666; padding: 4px 5px; font-size: 9pt; vertical-align: top; }
  th { background: #eee; }
  .ringkas { margin-bottom: 12px; }
  .catatan { font-size: 9pt; margin-top: 10px; }
  .ttd { margin-top: 34px; width: 45%; margin-left: 55%; text-align: left; font-size: 10pt; }
</style>'
. '<div class="kop">'
. '<div class="nama">' . e($prof['name']) . '</div>'
. '<div class="sub">Penerjemah Tersumpah — ' . e($arahDefault) . '</div>'
. ($skLine !== '' ? '<div class="sub">' . e($skLine) . '</div>' : '')
. ($addrLine !== '' ? '<div class="sub">' . e($addrLine) . '</div>' : '')
. '</div>'
. '<h1>REKAP TAHUNAN PEKERJAAN PENERJEMAH TERSUMPAH</h1>'
. '<div class="periode">Periode: ' . e($labelPeriode) . '</div>'
. '<table class="ringkas"><tr>'
. '<td>Total pekerjaan: <b>' . count($rows) . '</b></td>'
. '<td>Total halaman teks sumber: <b>' . number_format($totHal, 0, ',', '.') . '</b></td>'
. '<td>Dari Blanko: <b>' . count(array_filter($rows, static fn($r) => $r['sumber'] === 'Blanko')) . '</b></td>'
. '<td>Entri manual: <b>' . count(array_filter($rows, static fn($r) => $r['sumber'] === 'Manual')) . '</b></td>'
. '</tr></table>'
. '<table><thead><tr>'
. '<th style="width:4%">No</th><th style="width:11%">Tanggal</th><th style="width:19%">Nomor Register</th>'
. '<th style="width:18%">Judul</th><th style="width:13%">Jenis Dokumen</th><th style="width:14%">Arah Bahasa</th><th style="width:6%">Hal. Sumber</th>'
. '<th style="width:15%">Identitas Pengguna Jasa</th>'
. '</tr></thead><tbody>' . $tbl . '</tbody></table>'
. ($nInc > 0 ? '<p class="catatan">Catatan kekurangan: ' . $nInc . ' pekerjaan belum lengkap — ' . e(implode('; ', $incText)) . '.</p>' : '')
. '<p class="catatan">Dokumen ini disiapkan oleh Blanko sebagai bahan laporan pelaksanaan jabatan penerjemah tersumpah, dengan kolom mengikuti isi reportorium Pasal 17 ayat (3) huruf a Permenkum Nomor 4 Tahun 2025 (nomor register, jenis dokumen terjemahan, jumlah halaman teks sumber, arah bahasa, dan identitas pengguna jasa). Pengiriman laporan ke Ditjen AHU dilakukan oleh penerjemah melalui akun AHU masing-masing.</p>'
. '<div class="ttd">' . e($prof['city'] ?: '') . ($prof['city'] ? ', ' : '') . e(rekap_fmt_tanggal(date('Y-m-d')))
. '<br>Penerjemah Tersumpah,<br><br><br><br><b>' . e($prof['name']) . '</b></div>';

$mpdf->WriteHTML($html);
$mpdf->Output($base . '.pdf', 'D');
exit;
