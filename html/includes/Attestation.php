<?php
// Blanko kompak: strip di bagian BAWAH halaman terakhir (tetap 1 halaman).
declare(strict_types=1);
require_once __DIR__ . '/ArabicShape.php';

const STRIP_W = 1240;
const STRIP_H = 380;

function blanko_fonts(): array {
    $d = '/var/www/data/fonts/';
    return [$d . 'Arial.ttf', $d . 'Arial-Bold.ttf'];
}

function blanko_wrap(string $text, string $font, int $size, int $maxw): array {
    $words = preg_split('/\s+/', $text);
    $lines = []; $cur = '';
    foreach ($words as $w) {
        $t = trim($cur . ' ' . $w);
        $bb = imagettfbbox($size, 0, $font, $t);
        if (($bb[2] - $bb[0]) > $maxw && $cur !== '') { $lines[] = $cur; $cur = $w; }
        else $cur = $t;
    }
    if ($cur !== '') $lines[] = $cur;
    return $lines;
}

/** Render baris teks dengan alignment: left/center/right/justify. */
function blanko_textline($im, int $size, int $x, int $y, int $color, string $font, string $text, int $width, string $align): void {
    if ($align === 'justify') {
        $words = preg_split('/\s+/u', trim($text));
        if (count($words) <= 1) {
            imagettftext($im, $size, 0, $x, $y, $color, $font, $text);
            return;
        }
        // hitung lebar kata
        $wordW = [];
        $totalW = 0;
        foreach ($words as $w) {
            $bb = imagettfbbox($size, 0, $font, $w);
            $ww = $bb[2] - $bb[0];
            $wordW[] = $ww;
            $totalW += $ww;
        }
        $spaceBb = imagettfbbox($size, 0, $font, ' ');
        $spaceW = $spaceBb[2] - $spaceBb[0];
        $gaps = count($words) - 1;
        $extra = ($width - $totalW - $gaps * $spaceW) / max(1, $gaps);
        $cx = $x;
        foreach ($words as $i => $w) {
            imagettftext($im, $size, 0, (int)$cx, $y, $color, $font, $w);
            $cx += $wordW[$i] + $spaceW + $extra;
        }
        return;
    }
    $bb = imagettfbbox($size, 0, $font, $text);
    $tw = $bb[2] - $bb[0];
    $dx = $x;
    if ($align === 'center') $dx = $x + (int)(($width - $tw) / 2);
    elseif ($align === 'right') $dx = $x + $width - $tw;
    imagettftext($im, $size, 0, $dx, $y, $color, $font, $text);
}

/** Strip blanko — pakai config template (editable via UI). */
function blanko_strip(array $p, array $d, string $stampImg, array $cfg = []): string {
    $cfg = array_merge([
        'statement_id' => 'Saya, {name}, Penerjemah Tersumpah di Republik Indonesia berdasarkan peraturan perundang-undangan yang berlaku di Republik Indonesia, dengan ini menerangkan dan menyatakan, sesuai dengan sumpah jabatan saya, bahwa dokumen ini merupakan terjemahan yang benar, setia, dan lengkap dari dokumen sumber yang diberikan kepada saya.',
        'statement_foreign' => '',
        'foreign_rtl' => false,
        'use_foreign_image' => false,
        'font_size_base' => 13,
        'font_size_name' => 14,
        'font_size_foreign' => 15,
        'show_border' => true,
        'columns' => 2,
        'show_stamp' => true,
        'stamp_size' => 200,
        'show_foreign_name' => true,
        'line_height' => 1.35,
        'align_id' => 'justify',
        'align_foreign' => 'justify',
    ], $cfg);

    [$font, $fontB] = blanko_fonts();
    $W = STRIP_W; $H = STRIP_H;
    $im = imagecreatetruecolor($W, $H);
    $white = imagecolorallocate($im, 255, 255, 255);
    $black = imagecolorallocate($im, 0, 0, 0);
    imagefill($im, 0, 0, $white);

    $mx = 89;
    if ($cfg['show_border']) {
        imagefilledrectangle($im, $mx, 0, $W - $mx, 4, $black);
    }

    $y = 22;
    $sz = (int)$cfg['font_size_base'];
    $lh = (int)($sz * ($cfg['line_height'] ?? 1.35));

    $id_text = str_replace('{name}', $p['name'], $cfg['statement_id']);

    if ((int)$cfg['columns'] === 2) {
        $cw_id = 600; $x_ar = $mx + $cw_id + 36; $cw_ar = $W - $x_ar - $mx;
    } else {
        $cw_id = $W - $mx * 2; $x_ar = $mx; $cw_ar = $cw_id;
    }

    $alignId = $cfg['align_id'] ?? 'justify';
    foreach (blanko_wrap($id_text, $font, $sz, $cw_id) as $ln) {
        blanko_textline($im, $sz, $mx, $y, $black, $font, $ln, $cw_id, $alignId);
        $y += $lh;
    }
    $y_id = $y;

    // kolom bahasa asing
    $y_ar = $y_id;
    $alignF = $cfg['align_foreign'] ?? 'justify';
    // gambar asing didahulukan bila diminta (Arab pakai gambar)
    if ($cfg['use_foreign_image']) {
        $arP = imagecreatefrompng('/var/www/data/stamp-assets/arabic_para.png');
        if ($arP) {
            $aw = imagesx($arP); $ah = imagesy($arP);
            $dw = $cw_ar; $dh = (int)($dw * $ah / $aw);
            $tmp = imagecreatetruecolor($dw, $dh);
            imagefill($tmp, 0, 0, $white);
            imagecopyresampled($tmp, $arP, 0, 0, 0, 0, $dw, $dh, $aw, $ah);
            imagecopy($im, $tmp, $x_ar, 14, 0, 0, $dw, $dh);
            imagedestroy($arP); imagedestroy($tmp);
            $y_ar = 14 + $dh;
        }
    } elseif (!empty($cfg['statement_foreign'])) {
        $f_text = str_replace('{name}', $p['name'], $cfg['statement_foreign']);
        $szF = (int)($cfg['font_size_foreign'] ?? $sz + 2);
        $lhF = (int)($szF * ($cfg['line_height'] ?? 1.35));
        $ya = 22;
        // shaping Arab agar huruf tersambung
        $isArabic = (bool)preg_match('/[\x{0600}-\x{06FF}]/u', $f_text);
        $fontF = $font;
        if ($isArabic) {
            $arialAr = '/var/www/data/fonts/Arial.ttf';
            $amiri = '/var/www/data/fonts/Amiri-Regular.ttf';
            if (file_exists($arialAr)) $fontF = $arialAr;
            elseif (file_exists($amiri)) $fontF = $amiri;
        }
        foreach (blanko_wrap($f_text, $fontF, $szF, $cw_ar) as $ln) {
            // selalu shaping jika teks Arab agar huruf tersambung
            $drawLn = $isArabic ? arabic_shape($ln) : $ln;
            if ($isArabic || ($cfg['foreign_rtl'] ?? false)) {
                // Arab/RTL: render kanan-ke-kiri agar bisa dibaca
                blanko_textline($im, $szF, $x_ar, $ya, $black, $fontF, $drawLn, $cw_ar, 'right');
            } else {
                blanko_textline($im, $szF, $x_ar, $ya, $black, $fontF, $drawLn, $cw_ar, $alignF);
            }
            $ya += $lhF;
        }
        $y_ar = $ya;
    }

    $y = max($y_id, $y_ar) + 12;

    imagettftext($im, $sz, 0, $mx, $y, $black, $font, "{$p['city']}, {$d['date_id']}");
    $y += $sz + 7;
    $szN = (int)$cfg['font_size_name'];
    imagettftext($im, $szN, 0, $mx, $y, $black, $fontB, $p['name']);
    $y += $szN + 6;

    $cred = "Penerjemah Tersumpah [{$p['lang_pair']}] {$p['sk']}";
    if (!empty($p['sk_date'])) $cred .= " Tgl. {$p['sk_date']}";
    $cred .= ". Alamat {$p['address']}, Telp.{$p['phone']}. Email : {$p['email']}.";
    foreach (blanko_wrap($cred, $font, $sz, 640) as $ln) {
        imagettftext($im, $sz, 0, $mx, $y, $black, $font, $ln);
        $y += $lh;
    }
    $y += 6;
    $yReg = $y; // posisi baris No. Reg — nama kanan disejajarkan di sini
    imagettftext($im, $sz, 0, $mx, $y, $black, $font, "No. Reg. Terjemahan : {$d['reg_no']}");

    // nama kanan SEJAJAR dengan baris No. Reg. Terjemahan (seperti contoh)
    $nameY = $yReg;
    $bb = imagettfbbox($szN, 0, $fontB, $p['name']);
    $nameW = $bb[2] - $bb[0];
    $xName = $W - $mx - $nameW;
    if ($cfg['show_foreign_name']) $xName -= 130;
    imagettftext($im, $szN, 0, $xName, $nameY, $black, $fontB, $p['name']);

    if ($cfg['show_foreign_name']) {
        // nama Arab pakai shaping agar tersambung
        $arName = $p['ar_name'] ?? '';
        if ($arName !== '') {
            $shaped = arabic_shape($arName);
            $arialArB = '/var/www/data/fonts/Arial-Bold.ttf';
            $amiriB = '/var/www/data/fonts/Amiri-Bold.ttf';
            $fAr = file_exists($arialArB) ? $arialArB : (file_exists($amiriB) ? $amiriB : $fontB);
            $bbA = imagettfbbox($szN, 0, $fAr, $shaped);
            $wA = $bbA[2] - $bbA[0];
            imagettftext($im, $szN, 0, $W - $mx - $wA, $nameY, $black, $fAr, $shaped);
        } else {
            $arN = imagecreatefrompng('/var/www/data/stamp-assets/arabic_name.png');
            if ($arN) {
                $aw = imagesx($arN); $ah = imagesy($arN);
                $dw = 120; $dh = (int)($dw * $ah / $aw);
                $tmp = imagecreatetruecolor($dw, $dh);
                imagefill($tmp, 0, 0, $white);
                imagecopyresampled($tmp, $arN, 0, 0, 0, 0, $dw, $dh, $aw, $ah);
                imagecopy($im, $tmp, $W - $mx - $dw, $nameY - $dh + 6, 0, 0, $dw, $dh);
                imagedestroy($arN); imagedestroy($tmp);
            }
        }
    }

    // cap
    if ($cfg['show_stamp'] && file_exists($stampImg)) {
        $cap = imagecreatefromjpeg($stampImg);
        if ($cap) {
            $cw = imagesx($cap); $ch = imagesy($cap);
            $dw = (int)$cfg['stamp_size']; $dh = (int)($dw * $ch / $cw);
            $tmp = imagecreatetruecolor($dw, $dh);
            imagealphablending($tmp, false); imagesavealpha($tmp, true);
            $trans = imagecolorallocatealpha($tmp, 255, 255, 255, 127);
            imagefill($tmp, 0, 0, $trans);
            imagecopyresampled($tmp, $cap, 0, 0, 0, 0, $dw, $dh, $cw, $ch);
            for ($yy = 0; $yy < $dh; $yy++)
                for ($xx = 0; $xx < $dw; $xx++) {
                    $c = imagecolorat($tmp, $xx, $yy);
                    if ((($c >> 16) & 0xFF) > 225 && (($c >> 8) & 0xFF) > 225 && ($c & 0xFF) > 225)
                        imagesetpixel($tmp, $xx, $yy, $trans);
                }
            imagealphablending($im, true);
            $sx = $W - $mx - $dw + (int)($cfg['stamp_dx'] ?? 0);
            $sy = $H - $dh - 44 + (int)($cfg['stamp_dy'] ?? 0);
            imagecopy($im, $tmp, $sx, $sy, 0, 0, $dw, $dh);
            imagedestroy($cap); imagedestroy($tmp);
        }
    }

    $out = tempnam(sys_get_temp_dir(), 'bstr') . '.png';
    imagepng($im, $out);
    imagedestroy($im);
    return $out;
}

/** Strip PNG -> PDF seukuran halaman target, strip di BAWAH (sisa transparan). */
function blanko_strip_pdf(string $png, float $pw, float $ph): string {
    $im = imagecreatefrompng($png);
    $w = imagesx($im); $h = imagesy($im);
    $tmp = tempnam(sys_get_temp_dir(), 'bj') . '.jpg';
    $bg = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($bg, 255, 255, 255);
    imagefill($bg, 0, 0, $white);
    imagecopy($bg, $im, 0, 0, 0, 0, $w, $h);
    imagejpeg($bg, $tmp, 92);
    imagedestroy($im); imagedestroy($bg);
    $data = file_get_contents($tmp);
    @unlink($tmp);

    // strip menempati 22% bawah halaman
    $sh = $ph * 0.22;
    $sw = $sh * $w / $h;
    if ($sw > $pw) { $sw = $pw; $sh = $sw * $h / $w; }
    $dx = ($pw - $sw) / 2; $dy = 0;
    $content = "q\n$sw 0 0 $sh $dx $dy cm\n/Im1 Do\nQ\n";

    $objs = [];
    $objs[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objs[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objs[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] "
        . "/Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>";
    $objs[] = "<< /Type /XObject /Subtype /Image /Width $w /Height $h /ColorSpace /DeviceRGB "
        . "/BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    $objs[] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $i => $b) { $off[$i+1] = strlen($pdf); $pdf .= ($i+1) . " 0 obj\n$b\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs)+1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    $pdf .= "trailer\n<< /Size " . (count($objs)+1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    $out = tempnam(sys_get_temp_dir(), 'bp') . '.pdf';
    file_put_contents($out, $pdf);
    return $out;
}

/**
 * Tempel strip blanko di BAWAH halaman. Jumlah halaman tetap.
 * $pages: 'first' (hlm 1 saja), 'last' (hlm terakhir saja), 'all' (semua hlm).
 * PDF: overlay via qpdf. Gambar: sambung vertikal (opsi tidak relevan).
 */
function blanko_append(string $docPath, string $mime, array $p, array $d, string $stampImg, string $pages = 'last', array $cfg = []): string {
    $strip = blanko_strip($p, $d, $stampImg, $cfg);

    if (str_contains($mime, 'pdf')) {
        $qpdf = trim((string)shell_exec('which qpdf 2>/dev/null'));
        if ($qpdf === '') { @unlink($strip); return ''; }
        $npages = (int)trim((string)shell_exec(
            escapeshellcmd($qpdf) . ' --show-npages ' . escapeshellarg($docPath) . ' 2>/dev/null'));
        if ($npages < 1) $npages = 1;

        $pw = 595.0; $ph = 842.0;
        $ovl = blanko_strip_pdf($strip, $pw, $ph);
        @unlink($strip);
        $dest = $docPath . '.stamped.pdf';

        if ($pages === 'all' || $npages === 1) {
            // qpdf mengulang overlay 1 hlm ke semua hlm otomatis
            shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPath)
                . ' --overlay ' . escapeshellarg($ovl) . ' -- ' . escapeshellarg($dest) . ' 2>&1');
        } elseif ($pages === 'first') {
            $t = sys_get_temp_dir();
            $p1 = "$t/bq1.pdf"; $p1s = "$t/bq1s.pdf"; $prest = "$t/bqrest.pdf";
            shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPath)
                . ' --pages ' . escapeshellarg($docPath) . ' 1 -- ' . escapeshellarg($p1) . ' 2>&1');
            shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($p1)
                . ' --overlay ' . escapeshellarg($ovl) . ' -- ' . escapeshellarg($p1s) . ' 2>&1');
            shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPath)
                . ' --pages ' . escapeshellarg($docPath) . ' 2-' . $npages
                . ' -- ' . escapeshellarg($prest) . ' 2>&1');
            shell_exec(escapeshellcmd($qpdf) . ' --empty --pages '
                . escapeshellarg($p1s) . ' ' . escapeshellarg($prest)
                . ' -- ' . escapeshellarg($dest) . ' 2>&1');
            @unlink($p1); @unlink($p1s); @unlink($prest);
        } else { // 'last'
            $t = sys_get_temp_dir();
            $p1 = "$t/bq1.pdf"; $p2 = "$t/bq2.pdf"; $p2s = "$t/bq2s.pdf";
            shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPath)
                . ' --pages ' . escapeshellarg($docPath) . ' 1-' . ($npages - 1)
                . ' -- ' . escapeshellarg($p1) . ' 2>&1');
            shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($docPath)
                . ' --pages ' . escapeshellarg($docPath) . " $npages -- " . escapeshellarg($p2) . ' 2>&1');
            shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($p2)
                . ' --overlay ' . escapeshellarg($ovl) . ' -- ' . escapeshellarg($p2s) . ' 2>&1');
            shell_exec(escapeshellcmd($qpdf) . ' --empty --pages '
                . escapeshellarg($p1) . ' ' . escapeshellarg($p2s)
                . ' -- ' . escapeshellarg($dest) . ' 2>&1');
            @unlink($p1); @unlink($p2); @unlink($p2s);
        }
        @unlink($ovl);
        return (file_exists($dest) && filesize($dest) > 0) ? $dest : '';
    }

    // gambar: sambung vertikal (tetap 1 file)
    $src = match (true) {
        str_contains($mime, 'jpeg') => imagecreatefromjpeg($docPath),
        str_contains($mime, 'png') => imagecreatefrompng($docPath),
        default => null,
    };
    $bl = imagecreatefrompng($strip);
    @unlink($strip);
    if (!$src || !$bl) return '';
    // skala strip selebar gambar
    $sw = imagesx($src); $sh = (int)($sw * imagesy($bl) / imagesx($bl));
    $w = $sw; $h = imagesy($src) + $sh;
    $out = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($out, 255, 255, 255);
    imagefill($out, 0, 0, $white);
    imagecopy($out, $src, 0, 0, 0, 0, imagesx($src), imagesy($src));
    imagecopyresampled($out, $bl, 0, imagesy($src), 0, 0, $sw, $sh, imagesx($bl), imagesy($bl));
    $dest = $docPath . '.stamped.jpg';
    imagejpeg($out, $dest, 92);
    imagedestroy($src); imagedestroy($bl); imagedestroy($out);
    return $dest;
}

function reg_number(int $docId, string $mitraShort, string $docKind): string {
    $rom = ['I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'][(int)date('n') - 1];
    return date('Y') . "-$rom-RDW-" . strtoupper($mitraShort) . '-' . $docKind . '-' . $docId;
}
