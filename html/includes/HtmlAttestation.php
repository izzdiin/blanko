<?php
// Template blanko HTML (rich text) -> PDF pengesahan, lalu digabung ke dokumen.
// Pipeline baru (template milik per penerjemah): naskah disimpan sebagai HTML,
// dirender mPDF menjadi PDF VEKTOR (teks tetap tajam/diseleksi, bukan raster).
// Penempatan (dipilih penerjemah di template):
//   'strip' = ditempel di bawah halaman (maks tinggi konten 140mm, selebihnya
//             ditolak dengan pesan jelas agar pindah ke mode halaman).
//   'page'  = halaman pengesahan sendiri di akhir dokumen (boleh multi-halaman).
// Cap tidak ada di jalur ini (keputusan 26 Okt: cap diganti QR e-sign / tanpa cap).
// TTD elektronik TIDAK dibakar ke dokumen (sesuai desain e-sign: QR di dokumen,
// gambar TTD hanya tampil di halaman verifikasi).
declare(strict_types=1);

function html_tpl_font(string $langPair): string {
    $lp = mb_strtolower($langPair);
    if (str_contains($lp, 'arab')) return 'arial';
    if (str_contains($lp, 'mandarin') || str_contains($lp, 'cina') || str_contains($lp, 'tiongkok')) return 'notosanssc';
    return 'arial';
}

// Font pilihan penerjemah (kolom templates.font); kosong = otomatis sesuai bahasa.
// Amiri sengaja TIDAK ditawarkan (instruksi user 26 Okt: jangan gunakan Amiri).
function html_tpl_font_resolved(string $langPair, string $fontOverride): string {
    if (in_array($fontOverride, ['arial', 'notosanssc', 'dejavusans'], true)) return $fontOverride;
    return html_tpl_font($langPair);
}

function html_template_vars(): array {
    return [
        'name' => 'Nama penerjemah',
        'name_foreign' => 'Nama aksara (Arab/Mandarin)',
        'sk' => 'Nomor SK',
        'sk_date' => 'Tanggal SK',
        'reg_no' => 'Nomor registrasi terjemahan',
        'date' => 'Tanggal pengesahan',
        'doc_title' => 'Judul dokumen',
        'lang_pair' => 'Pasangan bahasa',
        'lang_from' => 'Bahasa sumber',
        'lang_to' => 'Bahasa target',
        'city' => 'Kota',
        'address' => 'Alamat',
        'phone' => 'Telepon',
        'email' => 'Email',
    ];
}

/** Ganti {variabel} dengan data profil/dokumen (nilai di-escape HTML). */
function html_substitute_vars(string $html, array $prof, array $dyn, string $tplLangPair): string {
    $pair = $tplLangPair !== '' ? $tplLangPair : ($prof['lang_pair'] ?? '');
    $from = $pair; $to = '';
    if (($p = mb_strpos($pair, ' ke ')) !== false) {
        $from = mb_substr($pair, 0, $p);
        $to = mb_substr($pair, $p + 4);
    }
    $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $map = [
        '{name_foreign}' => $esc($prof['ar_name'] ?? $prof['name_zh'] ?? ''),
        '{name}' => $esc($prof['name'] ?? ''),
        '{sk_date}' => $esc($prof['sk_date'] ?? ''),
        '{sk}' => $esc($prof['sk'] ?? ''),
        '{reg_no}' => $esc($dyn['reg_no'] ?? ''),
        '{date}' => $esc($dyn['date_id'] ?? ''),
        '{doc_title}' => $esc($dyn['doc_title'] ?? ''),
        '{lang_pair}' => $esc($pair),
        '{lang_from}' => $esc($from),
        '{lang_to}' => $esc($to),
        '{city}' => $esc($prof['city'] ?? ''),
        '{address}' => $esc($prof['address'] ?? ''),
        '{phone}' => $esc($prof['phone'] ?? ''),
        '{email}' => $esc($prof['email'] ?? ''),
    ];
    return strtr($html, $map); // strtr: kunci terpanjang didahulukan
}

/** Sanitasi HTML editor: allowlist tag + atribut, buang skrip/gaya berbahaya. */
function html_sanitize(string $html): string {
    $allowedTags = ['p','div','span','br','strong','b','em','i','u','ul','ol','li',
        'h1','h2','h3','h4','table','thead','tbody','tr','td','th','hr'];
    $dropEntirely = ['script','style','iframe','object','embed','link','meta','title',
        'form','input','button','select','textarea','svg','video','audio','img'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"?><div id="__root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    $root = $doc->getElementById('__root');
    if (!$root) return '';
    $cleanNode = function (DOMNode $node) use (&$cleanNode, $allowedTags, $dropEntirely) {
        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, $dropEntirely, true)) { $node->parentNode?->removeChild($node); return; }
            if (!in_array($tag, $allowedTags, true)) {
                // unwrap: pindahkan anak ke parent, lalu hapus node
                $parent = $node->parentNode;
                if ($parent) {
                    while ($node->firstChild) $parent->insertBefore($node->firstChild, $node);
                    $parent->removeChild($node);
                    // anak yang baru dipindah tidak perlu dibersihkan ulang di sini
                    // karena traversal di bawah memakai salinan daftar anak
                }
                return;
            }
            // atribut: hanya dir + style yang dibersihkan
            $keep = [];
            if ($node->hasAttribute('dir')) {
                $d = strtolower($node->getAttribute('dir'));
                if (in_array($d, ['rtl','ltr','auto'], true)) $keep['dir'] = $d;
            }
            if ($node->hasAttribute('style')) {
                $parts = [];
                foreach (explode(';', $node->getAttribute('style')) as $decl) {
                    $decl = trim($decl);
                    if ($decl === '') continue;
                    [$prop] = array_pad(explode(':', $decl, 2), 2, '');
                    $prop = strtolower(trim($prop));
                    $val = strtolower($decl);
                    if (in_array($prop, ['position','top','left','right','bottom','z-index'], true)) continue;
                    if (str_contains($val, 'url(') || str_contains($val, 'expression')
                        || str_contains($val, 'javascript:') || str_contains($val, 'behavior')) continue;
                    $parts[] = $decl;
                }
                if ($parts) $keep['style'] = implode('; ', $parts);
            }
            foreach (iterator_to_array($node->attributes) as $attr) $node->removeAttribute($attr->name);
            foreach ($keep as $k => $v) $node->setAttribute($k, $v);
        }
        foreach (iterator_to_array($node->childNodes) as $child) $cleanNode($child);
    };
    foreach (iterator_to_array($root->childNodes) as $child) $cleanNode($child);
    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return $out;
}

function html_mpdf(array $margins): \Mpdf\Mpdf {
    static $vendorLoaded = false;
    if (!$vendorLoaded) { require_once __DIR__ . '/../vendor/autoload.php'; $vendorLoaded = true; }
    $tmpDir = '/var/www/data/mpdf-tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);
    $old = error_reporting(E_ALL & ~E_DEPRECATED);
    try {
        $mpdf = new \Mpdf\Mpdf([
            'mode' => 'utf-8', 'format' => 'A4',
            'margin_left' => $margins[0], 'margin_right' => $margins[1],
            'margin_top' => $margins[2], 'margin_bottom' => $margins[3],
            'tempDir' => $tmpDir,
            'fontDir' => ['/var/www/html/vendor/mpdf/ttfonts', '/var/www/data/fonts'],
            'fontdata' => [
                // Arial pemberian user (cakup Arab + GSUB shaping, tanpa GPOS).
                'arial' => ['R' => 'Arial.ttf', 'B' => 'Arial-Bold.ttf',
                    'I' => 'Arial.ttf', 'BI' => 'Arial-Bold.ttf',
                    'useOTL' => 0xFF, 'useKashida' => 75],
                'notosanssc' => ['R' => 'NotoSansSC-Regular.ttf'],
                'dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf'],
            ],
            'default_font' => 'arial',
        ]);
    } finally { error_reporting($old); }
    return $mpdf;
}

/**
 * Render body HTML menjadi PDF pengesahan (file temp).
 * @return array [string $pdfPath, string $error] — salah satu kosong.
 */
function html_render_attestation(string $bodyHtml, array $prof, array $dyn, string $tplLangPair, string $placement, string $fontOverride = ''): array {
    $html = html_sanitize(html_substitute_vars($bodyHtml, $prof, $dyn, $tplLangPair));
    if (trim(strip_tags($html)) === '') return ['', 'Isi template kosong.'];
    $font = html_tpl_font_resolved($tplLangPair !== '' ? $tplLangPair : ($prof['lang_pair'] ?? ''), $fontOverride);
    $wrap = '<div style="font-family:' . $font . ';font-size:11pt;line-height:1.5;">' . $html . '</div>';
    $old = error_reporting(E_ALL & ~E_DEPRECATED);
    try {
        if ($placement === 'page') {
            $mpdf = html_mpdf([15, 15, 15, 15]);
            $mpdf->WriteHTML($wrap);
            $stub = tempnam(sys_get_temp_dir(), 'hatt'); @unlink($stub); $out = $stub . '.pdf';
            $mpdf->Output($out, 'F');
            return [is_file($out) && filesize($out) > 0 ? $out : '', $out !== '' && (!is_file($out) || filesize($out) === 0) ? 'Gagal membuat PDF pengesahan.' : ''];
        }
        // strip: ukur tinggi konten dulu (render percobaan, margin 15)
        $m = html_mpdf([15, 15, 15, 15]);
        $m->WriteHTML($wrap);
        if (count($m->pages) > 1) return ['', 'Konten pengesahan terlalu panjang untuk mode strip. Pindah ke mode halaman.'];
        $h = $m->y - 15; // tinggi konten (mm), y sudah termasuk margin atas
        if ($h <= 0) return ['', 'Isi template kosong.'];
        if ($h > 140) return ['', 'Konten pengesahan terlalu tinggi untuk mode strip (' . round($h) . ' mm, maks 140 mm). Pindah ke mode halaman atau perpendek.'];
        // render final: spacer mendorong konten ke bawah halaman (margin 0)
        $spacer = 297 - $h;
        $out = '';
        for ($try = 0; $try < 3; $try++) {
            $m2 = html_mpdf([15, 15, 0, 0]);
            $m2->WriteHTML('<div style="height:' . $spacer . 'mm;line-height:0;font-size:0;"></div>' . $wrap);
            if (count($m2->pages) === 1) {
                $stub = tempnam(sys_get_temp_dir(), 'hatt'); @unlink($stub); $out = $stub . '.pdf';
                $m2->Output($out, 'F');
                if ($m2->y <= 297) break; // muat satu halaman, cukup dekat ke bawah
                @unlink($out); $out = '';
            }
            $spacer -= max(1, ($m2->y - 297) + 1);
            if ($spacer < 0) break;
        }
        if ($out === '' || !is_file($out) || filesize($out) === 0) {
            return ['', 'Gagal menempatkan pengesahan mode strip. Coba mode halaman.'];
        }
        return [$out, ''];
    } catch (Throwable $e) {
        return ['', 'Gagal merender template HTML: ' . $e->getMessage()];
    } finally { error_reporting($old); }
}

/**
 * Render body HTML ke PDF A4 penuh dengan konten di kotak (x,y,w,h) fraksi halaman.
 * Dipakai editor review saat penerjemah menggeser/mengubah ukuran pengesahan
 * (mode strip saja). Skala font dihitung dari dua titik ukur agar tinggi isi pas
 * dengan kotak: tinggi isi = bagian proporsional font + bagian tetap (mis. tabel),
 * sehingga skala = (tinggi kotak - bagian tetap) / bagian proporsional.
 * Bila kotak lebih tinggi dari isi, isi tetap di atas kotak (tidak diperbesar).
 * @return array [string $pdfPath, string $error]
 */
function html_render_attestation_rect(string $bodyHtml, array $prof, array $dyn, string $tplLangPair, array $rect, string $fontOverride = ''): array {
    $html = html_sanitize(html_substitute_vars($bodyHtml, $prof, $dyn, $tplLangPair));
    if (trim(strip_tags($html)) === '') return ['', 'Isi template kosong.'];
    $font = html_tpl_font_resolved($tplLangPair !== '' ? $tplLangPair : ($prof['lang_pair'] ?? ''), $fontOverride);
    [$x, $y, $w, $h] = $rect;
    $xMm = $x * 210; $yMm = $y * 297; $wMm = $w * 210; $hMm = $h * 297;
    if ($wMm <= 0 || $hMm <= 0) return ['', 'Ukuran kotak pengesahan tidak valid.'];
    $old = error_reporting(E_ALL & ~E_DEPRECATED);
    try {
        // Dua titik ukur pada lebar kotak (margin atas 0 agar tinggi terukur penuh;
        // margin bawah 0: margin bawah + tabel memicu pindah halaman palsu di mPDF).
        $rightMm = max(0, 210 - $xMm - $wMm);
        $wrapAt = static fn(float $fs) => '<div style="font-family:' . $font . ';font-size:' . $fs . 'pt;line-height:1.5;">' . $html . '</div>';
        $measure = static function (float $scale) use ($xMm, $rightMm, $wrapAt): float {
            $m = html_mpdf([$xMm, $rightMm, 0, 0]);
            $m->WriteHTML($wrapAt(11 * $scale));
            return (count($m->pages) - 1) * 297 + $m->y;
        };
        $h1 = $measure(1.0);
        if ($h1 <= 0) return ['', 'Isi template kosong.'];
        $h2 = $measure(0.5);
        $tPart = 2 * ($h1 - $h2); // bagian proporsional font
        $fPart = 2 * $h2 - $h1;   // bagian tetap (tabel, padding)
        if ($tPart <= 0) {
            if ($fPart > $hMm) return ['', 'Kotak pengesahan terlalu kecil untuk isinya. Perbesar kotaknya.'];
            $scale = 1.0;
        } else {
            // faktor 0.97: model linear meleset ~2% karena pembulatan tinggi tabel
            $scale = ($hMm * 0.97 - $fPart) / $tPart;
            if ($scale <= 0) return ['', 'Kotak pengesahan terlalu kecil untuk isinya. Perbesar kotaknya.'];
            if ($scale > 1) $scale = 1.0; // kotak lebih tinggi dari isi: jangan diperbesar
        }
        // render final: margin kiri/atas menempatkan isi tepat di kotak;
        // bila masih meluber, kecilkan skala sekali lagi (maks 3 percobaan)
        $m2 = null;
        for ($try = 0; $try < 3; $try++) {
            $m2 = html_mpdf([$xMm, $rightMm, $yMm, 0]);
            $m2->WriteHTML($wrapAt(11 * $scale));
            $totH = (count($m2->pages) - 1) * max(1, 297 - $yMm) + ($m2->y - $yMm);
            if (count($m2->pages) === 1 && $totH <= $hMm + 1) break;
            $scale *= $hMm / max(1, $totH);
            if ($scale <= 0.05) { $m2 = null; break; }
            $m2 = null;
        }
        if ($m2 === null) {
            return ['', 'Kotak pengesahan terlalu kecil untuk isinya. Perbesar kotaknya.'];
        }
        $stub = tempnam(sys_get_temp_dir(), 'hatt'); @unlink($stub); $out = $stub . '.pdf';
        $m2->Output($out, 'F');
        return [is_file($out) && filesize($out) > 0 ? $out : '', ''];
    } catch (Throwable $e) {
        return ['', 'Gagal merender template HTML: ' . $e->getMessage()];
    } finally { error_reporting($old); }
}

function html_qpdf(): string {
    return trim((string)shell_exec('which qpdf 2>/dev/null'));
}
function html_qpdf_npages(string $qpdf, string $pdf): int {
    $n = (int)trim((string)shell_exec(
        escapeshellcmd($qpdf) . ' --show-npages ' . escapeshellarg($pdf) . ' 2>/dev/null'));
    return $n < 1 ? 1 : $n;
}

/** Overlay satu PDF halaman-penuh ke halaman dokumen (first/last/all). Pola sama seperti esign_apply_pdf. */
function html_overlay_pages(string $docPdf, string $overlayPdf, string $pages, string $qpdf): string {
    $npages = html_qpdf_npages($qpdf, $docPdf);
    $t = sys_get_temp_dir(); $u = bin2hex(random_bytes(6));
    $dest = $docPdf . '.ovl.pdf';
    $ok = false;
    $run = static fn(string $cmd) => shell_exec($cmd . ' 2>&1');
    if ($pages === 'all' || $npages === 1) {
        $run(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPdf)
            . ' --overlay ' . escapeshellarg($overlayPdf) . ' -- ' . escapeshellarg($dest));
        $ok = file_exists($dest) && filesize($dest) > 0;
    } elseif ($pages === 'first') {
        $p1 = "$t/ho{$u}a.pdf"; $p1s = "$t/ho{$u}b.pdf"; $prest = "$t/ho{$u}c.pdf";
        $run(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPdf) . ' --pages ' . escapeshellarg($docPdf) . ' 1 -- ' . escapeshellarg($p1));
        $run(escapeshellcmd($qpdf) . ' ' . escapeshellarg($p1) . ' --overlay ' . escapeshellarg($overlayPdf) . ' -- ' . escapeshellarg($p1s));
        $run(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPdf) . ' --pages ' . escapeshellarg($docPdf) . ' 2-' . $npages . ' -- ' . escapeshellarg($prest));
        $run(escapeshellcmd($qpdf) . ' --empty --pages ' . escapeshellarg($p1s) . ' ' . escapeshellarg($prest) . ' -- ' . escapeshellarg($dest));
        $ok = file_exists($dest) && filesize($dest) > 0;
        @unlink($p1); @unlink($p1s); @unlink($prest);
    } else { // last
        $p1 = "$t/ho{$u}a.pdf"; $p2 = "$t/ho{$u}b.pdf"; $p2s = "$t/ho{$u}c.pdf";
        $run(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPdf) . ' --pages ' . escapeshellarg($docPdf) . ' 1-' . ($npages - 1) . ' -- ' . escapeshellarg($p1));
        $run(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPdf) . ' --pages ' . escapeshellarg($docPdf) . " $npages -- " . escapeshellarg($p2));
        $run(escapeshellcmd($qpdf) . ' ' . escapeshellarg($p2) . ' --overlay ' . escapeshellarg($overlayPdf) . ' -- ' . escapeshellarg($p2s));
        $run(escapeshellcmd($qpdf) . ' --empty --pages ' . escapeshellarg($p1) . ' ' . escapeshellarg($p2s) . ' -- ' . escapeshellarg($dest));
        $ok = file_exists($dest) && filesize($dest) > 0;
        @unlink($p1); @unlink($p2); @unlink($p2s);
    }
    return $ok ? $dest : '';
}

/** Halaman pengesahan ditempel di akhir dokumen. */
function html_append_pages(string $docPdf, string $attPdf, string $qpdf): string {
    $dest = $docPdf . '.app.pdf';
    shell_exec(escapeshellcmd($qpdf) . ' --empty --pages ' . escapeshellarg($docPdf) . ' '
        . escapeshellarg($attPdf) . ' -- ' . escapeshellarg($dest) . ' 2>&1');
    return (file_exists($dest) && filesize($dest) > 0) ? $dest : '';
}

/** Dokumen gambar -> PDF satu halaman (gambar muat A4, rata atas). */
function html_image_base_pdf(string $imgPath, string $mime): string {
    $size = @getimagesize($imgPath);
    if (!$size) return '';
    [$iw, $ih] = $size;
    $w = 200.0; $h = 200.0 * $ih / $iw;
    if ($h > 287.0) { $h = 287.0; $w = 287.0 * $iw / $ih; }
    $old = error_reporting(E_ALL & ~E_DEPRECATED);
    try {
        $mpdf = html_mpdf([5, 5, 5, 5]);
        $mpdf->WriteHTML('<div style="text-align:center;"><img src="' . htmlspecialchars($imgPath, ENT_QUOTES, 'UTF-8')
            . '" style="width:' . $w . 'mm;height:' . $h . 'mm;"></div>');
        $stub = tempnam(sys_get_temp_dir(), 'himg'); @unlink($stub); $out = $stub . '.pdf';
        $mpdf->Output($out, 'F');
        return (is_file($out) && filesize($out) > 0) ? $out : '';
    } catch (Throwable) { return ''; } finally { error_reporting($old); }
}

/**
 * Gabung pengesahan HTML ke dokumen. Output SELALU PDF (dokumen gambar pun
 * keluar sebagai PDF — keputusan 26 Okt, tanpa pasang Chromium).
 * @return array [string $outPath, string $error]
 */
function html_merge_document(string $docPath, string $mime, string $bodyHtml, array $prof, array $dyn, string $tplLangPair, string $placement, string $pages = 'last', string $fontOverride = '', ?array $rect = null): array {
    $qpdf = html_qpdf();
    if ($qpdf === '') return ['', 'qpdf tidak tersedia di server.'];
    [$att, $err] = ($rect !== null && $placement === 'strip')
        ? html_render_attestation_rect($bodyHtml, $prof, $dyn, $tplLangPair, $rect, $fontOverride)
        : html_render_attestation($bodyHtml, $prof, $dyn, $tplLangPair, $placement, $fontOverride);
    if ($err !== '' || $att === '') { @unlink($att); return ['', $err !== '' ? $err : 'Gagal merender pengesahan.']; }
    $isPdf = str_contains($mime, 'pdf');
    $base = $docPath;
    $baseTmp = '';
    if (!$isPdf) {
        $baseTmp = html_image_base_pdf($docPath, $mime);
        if ($baseTmp === '') { @unlink($att); return ['', 'Gagal mengubah dokumen gambar menjadi PDF.']; }
        $base = $baseTmp;
    }
    $merged = $placement === 'page'
        ? html_append_pages($base, $att, $qpdf)
        : html_overlay_pages($base, $att, $isPdf ? $pages : 'last', $qpdf);
    @unlink($att);
    if ($baseTmp !== '') @unlink($baseTmp);
    if ($merged === '') return ['', 'Gagal menggabungkan pengesahan ke dokumen.'];
    $dest = $docPath . '.stamped.pdf';
    if ($merged !== $dest) {
        if (!copy($merged, $dest)) { @unlink($merged); return ['', 'Gagal menyimpan dokumen hasil.']; }
        @unlink($merged);
    }
    return [(file_exists($dest) && filesize($dest) > 0) ? $dest : '', $dest !== '' && (!file_exists($dest) || filesize($dest) === 0) ? 'Dokumen hasil kosong.' : ''];
}

/** Konversi config template lama (field tetap) menjadi body HTML awal. */
function html_seed_body(array $cfg): string {
    $esc = static fn($v) => nl2br(htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'));
    $id = $esc($cfg['statement_id'] ?? '');
    $fo = $esc($cfg['statement_foreign'] ?? '');
    $out = '';
    if (!empty($cfg['show_border'])) $out .= '<div style="border-top:3px solid #000000;margin-bottom:10px;"></div>';
    $twoCol = !empty($fo) && (($cfg['columns'] ?? 2) == 2);
    if ($twoCol) {
        $alignId = ($cfg['align_id'] ?? 'justify') === 'justify' ? 'text-align:justify;' : '';
        $dirFo = !empty($cfg['foreign_rtl']) ? ' dir="rtl"' : '';
        $alignFo = ($cfg['align_foreign'] ?? '') === 'right' ? 'text-align:right;' : '';
        $out .= '<table style="width:100%;border-collapse:collapse;"><tr>'
            . '<td style="width:50%;vertical-align:top;padding-right:12px;' . $alignId . '">' . $id . '</td>'
            . '<td style="width:50%;vertical-align:top;' . $alignFo . '"' . $dirFo . '>' . $fo . '</td>'
            . '</tr></table>';
    } else {
        $out .= '<p>' . $id . '</p>';
        if ($fo !== '') {
            $dirFo = !empty($cfg['foreign_rtl']) ? ' dir="rtl"' : '';
            $out .= '<p' . $dirFo . '>' . $fo . '</p>';
        }
    }
    $out .= '<p>{city}, {date}</p>'
        . '<p><strong>{name}</strong></p>'
        . '<p>Penerjemah Tersumpah [{lang_pair}] {sk}. Alamat {address}, Telp.{phone}. Email : {email}.</p>'
        . '<p>No. Reg. Terjemahan : {reg_no}</p>';
    return $out;
}
