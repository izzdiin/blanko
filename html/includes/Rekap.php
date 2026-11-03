<?php
// Rekap Tahunan Siap Lapor — pustaka bersama untuk panel rekap, ekspor,
// dan pengingat. Dua kedudukan catatan:
//  - 'resmi'     : dokumen Blanko yang di-approve + entri manual penerjemah
//                  (jenis_catatan='rekap') — terhitung di ringkasan & ekspor.
//  - 'pencocokan': catatan mitra atas pekerjaan di luar Blanko — sebatas
//                  bahan cocok data, TIDAK terhitung, tanpa persetujuan.
declare(strict_types=1);

const REKAP_JENIS = [
    'Akta Kelahiran',
    'Kartu Keluarga',
    'KTP/Paspor',
    'Akta/Buku Nikah',
    'Akta Cerai',
    'Akta Kematian',
    'Ijazah',
    'Transkrip Nilai',
    'Rapor',
    'Akta Pendirian/Perubahan Perusahaan',
    'Perjanjian/Kontrak',
    'Putusan Pengadilan',
    'Surat Kuasa',
    'Dokumen Medis',
    'Lainnya',
];

const REKAP_KATEGORI = ['Pribadi', 'Perusahaan', 'Lembaga', 'Pemerintah'];

const REKAP_BULAN = [
    1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
    'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
];

/** Jenis dokumen dari isian bebas: rapikan, batasi panjang, kosong -> null. */
function rekap_jenis_clean(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    return mb_substr($s, 0, 100);
}

/**
 * Baca klien dinamis dari POST.
 * Mode 'kategori': klien diambil dari pilihan kategori baku.
 * Mode 'nama': teks bebas. Mengembalikan [tipe, nilai|null].
 */
function rekap_klien_from_post(array $post, string $prefix = 'klien'): array {
    $tipe = ($post[$prefix . '_tipe'] ?? 'kategori') === 'nama' ? 'nama' : 'kategori';
    if ($tipe === 'nama') {
        $v = trim((string)($post[$prefix . '_nama'] ?? ''));
        return ['nama', $v === '' ? null : mb_substr($v, 0, 150)];
    }
    $v = trim((string)($post[$prefix . '_kategori'] ?? ''));
    if (!in_array($v, REKAP_KATEGORI, true)) $v = '';
    return ['kategori', $v === '' ? null : $v];
}

/** Label klien untuk tampilan (kategori apa adanya; nama apa adanya). */
function rekap_klien_label($tipe, $nilai): string {
    $v = trim((string)($nilai ?? ''));
    return $v === '' ? '-' : $v;
}

/**
 * Baris rekap RESMI pada rentang tanggal [from, to] (inklusif) milik
 * penerjemah $penId: dokumen approved (reviewed_by = penerjemah) digabung
 * entri manual 'rekap'. Terurut tanggal naik.
 */
function rekap_rows(PDO $db, int $penId, string $from, string $to, string $arahDefault): array {
    $rows = [];
    $st = $db->prepare(
        "SELECT id, title, jenis_dokumen, halaman, klien_tipe, klien, reviewed_at
         FROM documents
         WHERE status = 'approved' AND reviewed_by = ? AND date(reviewed_at) BETWEEN ? AND ?
         ORDER BY reviewed_at"
    );
    $st->execute([$penId, $from, $to]);
    foreach ($st->fetchAll() as $d) {
        $rows[] = [
            'tanggal' => substr((string)$d['reviewed_at'], 0, 10),
            'judul' => $d['title'],
            'jenis' => $d['jenis_dokumen'],
            'arah' => $arahDefault,
            'halaman' => $d['halaman'] !== null ? (int)$d['halaman'] : null,
            'klien_tipe' => $d['klien_tipe'] ?: 'kategori',
            'klien' => $d['klien'],
            'sumber' => 'Blanko',
            'doc_id' => (int)$d['id'],
            'entry_id' => null,
        ];
    }
    $st = $db->prepare(
        "SELECT id, tanggal, judul, jenis_dokumen, arah_bahasa, halaman, klien_tipe, klien
         FROM work_entries
         WHERE penerjemah_id = ? AND jenis_catatan = 'rekap' AND tanggal BETWEEN ? AND ?
         ORDER BY tanggal"
    );
    $st->execute([$penId, $from, $to]);
    foreach ($st->fetchAll() as $w) {
        $rows[] = [
            'tanggal' => $w['tanggal'],
            'judul' => $w['judul'],
            'jenis' => $w['jenis_dokumen'],
            'arah' => $w['arah_bahasa'],
            'halaman' => $w['halaman'] !== null ? (int)$w['halaman'] : null,
            'klien_tipe' => $w['klien_tipe'] ?: 'kategori',
            'klien' => $w['klien'],
            'sumber' => 'Manual',
            'doc_id' => null,
            'entry_id' => (int)$w['id'],
        ];
    }
    usort($rows, static fn($a, $b) => strcmp($a['tanggal'], $b['tanggal']));
    return $rows;
}

/** Catatan pencocokan dari mitra untuk penerjemah $penId pada rentang. */
function rekap_pencocokan(PDO $db, int $penId, string $from, string $to): array {
    $st = $db->prepare(
        "SELECT w.*, u.name AS mitra_name
         FROM work_entries w JOIN users u ON u.id = w.created_by
         WHERE w.penerjemah_id = ? AND w.jenis_catatan = 'pencocokan'
           AND w.tanggal BETWEEN ? AND ?
         ORDER BY w.tanggal"
    );
    $st->execute([$penId, $from, $to]);
    return $st->fetchAll();
}

/** Hitung baris per bulan 'YYYY-MM' untuk ringkasan pembanding. */
function rekap_per_bulan(array $rows, string $key = 'tanggal'): array {
    $out = [];
    foreach ($rows as $r) {
        $k = substr((string)$r[$key], 0, 7);
        $out[$k] = ($out[$k] ?? 0) + 1;
    }
    ksort($out);
    return $out;
}

/**
 * Urai tanggal: menerima 'YYYY-MM-DD' atau format panjang Indonesia
 * ('14 Maret 2022'). Kembalikan 'YYYY-MM-DD' atau null bila tak terbaca.
 * sk_date disimpan sebagai teks tampilan (dipakai apa adanya oleh strip
 * pengesahan), jadi penguraian di sini yang menjembatani ke hitungan.
 */
function rekap_parse_tanggal(?string $s): ?string {
    $s = trim((string)$s);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? $s : null;
    }
    $bln = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6,
        'jul' => 7, 'agu' => 8, 'ags' => 8, 'sep' => 9, 'sept' => 9,
        'okt' => 10, 'nov' => 11, 'des' => 12,
    ];
    if (preg_match('/^(\d{1,2})\s+([A-Za-z]+)\s+(\d{4})$/', $s, $m)) {
        $b = $bln[mb_strtolower($m[2])] ?? null;
        if ($b !== null && checkdate($b, (int)$m[1], (int)$m[3])) {
            return sprintf('%04d-%02d-%02d', (int)$m[3], $b, (int)$m[1]);
        }
    }
    return null;
}

/** Format 'YYYY-MM-DD' -> '14 Maret 2022'. */
function rekap_fmt_tanggal(?string $iso): string {
    $iso = rekap_parse_tanggal($iso);
    if ($iso === null) return '-';
    [$y, $m, $d] = array_map('intval', explode('-', $iso));
    return $d . ' ' . REKAP_BULAN[$m] . ' ' . $y;
}

/** Tambah tahun dengan penjepit 29 Feb -> 28 Feb pada tahun non-kabisat. */
function rekap_add_year(string $iso, int $years): string {
    [$y, $m, $d] = array_map('intval', explode('-', $iso));
    $y += $years;
    if (!checkdate($m, $d, $y)) $d = 28;
    return sprintf('%04d-%02d-%02d', $y, $m, $d);
}

/**
 * Periode Tahun Lapor bawaan dari tanggal pengangkatan (ISO):
 * [mulai, selesai] 12 bulan yang berakhir pada ulang tahun pengangkatan
 * berikutnya dari hari ini. Null bila tanggal SK tak terbaca.
 */
function rekap_default_period(?string $skIso, ?string $today = null): ?array {
    if ($skIso === null) return null;
    $today = $today ?: date('Y-m-d');
    $k = 1;
    while (true) {
        $end = rekap_add_year($skIso, $k);
        if ($end >= $today) break;
        $k++;
        if ($k > 80) return null;
    }
    $start = $k === 1 ? $skIso : date('Y-m-d', strtotime(rekap_add_year($skIso, $k - 1) . ' +1 day'));
    return [$start, $end];
}

/**
 * Tentukan rentang tampilan panel/ekspor dari baris users + parameter GET.
 * view 'lapor' hanya berlaku bila periode aktif; selain itu tahun kalender.
 * Mengembalikan [from, to, viewAktif, labelPeriode].
 */
function rekap_resolve_range(array $prof, array $get): array {
    $aktif = !empty($prof['rekap_periode_aktif'])
        && !empty($prof['rekap_periode_mulai'])
        && !empty($prof['rekap_periode_selesai']);
    $view = ($get['view'] ?? '') === 'kalender' ? 'kalender' : 'lapor';
    if ($view === 'lapor' && $aktif) {
        $from = $prof['rekap_periode_mulai'];
        $to = $prof['rekap_periode_selesai'];
        return [$from, $to, 'lapor', rekap_fmt_tanggal($from) . ' s.d. ' . rekap_fmt_tanggal($to)];
    }
    $year = (int)($get['year'] ?? date('Y'));
    if ($year < 2000 || $year > 2100) $year = (int)date('Y');
    return [$year . '-01-01', $year . '-12-31', 'kalender', 'Tahun ' . $year];
}

/** Jumlah halaman berkas hasil: PDF dihitung via qpdf; gambar = 1. */
function rekap_pdf_pages(string $path): ?int {
    if (!is_file($path)) return null;
    if (!str_ends_with(strtolower($path), '.pdf')) return 1;
    $qpdf = trim((string)shell_exec('which qpdf 2>/dev/null'));
    if ($qpdf === '') return null;
    $n = (int)trim((string)shell_exec(
        escapeshellcmd($qpdf) . ' --show-npages ' . escapeshellarg($path) . ' 2>/dev/null'
    ));
    return $n > 0 ? $n : null;
}

/** Simpan pengaturan Tahun Lapor ke baris users. */
function rekap_save_period(PDO $db, int $userId, bool $aktif, ?string $mulai, ?string $selesai): void {
    $db->prepare('UPDATE users SET rekap_periode_aktif = ?, rekap_periode_mulai = ?, rekap_periode_selesai = ? WHERE id = ?')
        ->execute([$aktif ? 1 : 0, $mulai, $selesai, $userId]);
}

/* ---------------------------------------------------------------------
 * Penulis XLSX minimal tanpa ext-zip: arsip ZIP metode "stored"
 * (tanpa kompresi) + string inline. Excel/LibreOffice membaca berkas
 * metode stored secara normal; tanggal ditulis sebagai nomor seri
 * Excel dengan format yyyy-mm-dd, angka sebagai angka.
 * ------------------------------------------------------------------- */

function rekap_zip_stored(array $files): string {
    $out = '';
    $central = '';
    foreach ($files as $name => $data) {
        $crc = crc32($data);
        if ($crc < 0) $crc += 4294967296;
        $size = strlen($data);
        $offset = strlen($out);
        // local file header, tanggal DOS tetap 1980-01-01
        $out .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, 0, 0x21, $crc, $size, $size, strlen($name), 0)
            . $name . $data;
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, 0, 0x21, $crc, $size, $size,
            strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
    }
    $out .= $central;
    $out .= pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central),
        strlen($out) - strlen($central), 0);
    return $out;
}

function rekap_xlsx_cell_ref(int $col, int $row): string {
    $s = '';
    $c = $col;
    while (true) {
        $s = chr(65 + ($c % 26)) . $s;
        $c = intdiv($c, 26) - 1;
        if ($c < 0) break;
    }
    return $s . $row;
}

/**
 * Bangun berkas XLSX satu lembar.
 * $header: daftar judul kolom. $rows: daftar baris; setiap sel string|int|float|null.
 * $dateCols: indeks kolom (0-based) berisi 'YYYY-MM-DD' yang ditulis sebagai tanggal.
 */
function rekap_xlsx_build(string $sheetName, array $header, array $rows, array $dateCols = []): string {
    $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_COMPAT, 'UTF-8');
    $epoch = strtotime('1899-12-30');

    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $all = array_merge([$header], $rows);
    foreach ($all as $ri => $row) {
        $r = $ri + 1;
        $sheet .= '<row r="' . $r . '">';
        foreach (array_values($row) as $ci => $val) {
            $ref = rekap_xlsx_cell_ref($ci, $r);
            if ($val === null || $val === '') {
                $sheet .= '<c r="' . $ref . '"/>';
            } elseif (in_array($ci, $dateCols, true) && is_string($val) && rekap_parse_tanggal($val) !== null) {
                $serial = intdiv(strtotime($val) - $epoch, 86400);
                $sheet .= '<c r="' . $ref . '" s="1"><v>' . $serial . '</v></c>';
            } elseif (is_numeric($val) && !is_string($val)) {
                $sheet .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
            } else {
                $sheet .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $esc((string)$val) . '</t></is></c>';
            }
        }
        $sheet .= '</row>';
    }
    $sheet .= '</sheetData></worksheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $esc(mb_substr($sheetName, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
            . '</styleSheet>',
        'xl/worksheets/sheet1.xml' => $sheet,
    ];
    return rekap_zip_stored($files);
}
