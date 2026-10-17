<?php
// Stempel & tanda tangan elektronik. Gambar: GD. PDF: overlay via qpdf.
declare(strict_types=1);

function stamp_paths(): array {
    $d = '/var/www/data/stamps';
    if (!is_dir($d)) mkdir($d, 0755, true);
    return [$d];
}

/** Buat stempel & ttd default untuk penerjemah (GD). */
function stamp_defaults(int $userId, string $name): array {
    [$d] = stamp_paths();
    $stamp = "$d/stamp_$userId.png";
    $sig = "$d/sig_$userId.png";
    if (!file_exists($stamp)) {
        $im = imagecreatetruecolor(360, 360);
        $white = imagecolorallocate($im, 255, 255, 255);
        $blue = imagecolorallocate($im, 20, 60, 160);
        imagefill($im, 0, 0, $white);
        imagesetthickness($im, 6);
        imageellipse($im, 180, 180, 320, 320, $blue);
        imageellipse($im, 180, 180, 280, 280, $blue);
        $font = 5;
        imagestring($im, $font, 60, 130, 'PENERJEMAH', $blue);
        imagestring($im, $font, 55, 155, 'TERSUMPAH', $blue);
        $nm = strtoupper(substr($name, 0, 20));
        imagestring($im, 4, max(10, 180 - strlen($nm) * 5), 200, $nm, $blue);
        imagestring($im, 3, 110, 240, date('d-m-Y'), $blue);
        imagepng($im, $stamp);
        imagedestroy($im);
    }
    if (!file_exists($sig)) {
        $im = imagecreatetruecolor(400, 140);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 20, 20, 20);
        imagefill($im, 0, 0, $white);
        imagestring($im, 5, 20, 30, $name, $black);
        imageline($im, 20, 95, 380, 70, $black);
        imagesetthickness($im, 2);
        imagepng($im, $sig);
        imagedestroy($im);
    }
    return [$stamp, $sig];
}

/** Bubuhkan stempel+ttd ke gambar (JPG/PNG) via GD. */
function stamp_image(string $src, string $dest, string $stampPng, string $sigPng, string $label): bool {
    $info = getimagesize($src);
    if (!$info) return false;
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => imagecreatefromjpeg($src),
        IMAGETYPE_PNG => imagecreatefrompng($src),
        default => null,
    };
    if (!$im) return false;
    $W = imagesx($im); $H = imagesy($im);

    $overlay = function ($png, int $dw, int $dx, int $dy) use ($im, $W, $H) {
        $s = imagecreatefrompng($png);
        if (!$s) return;
        $sw = imagesx($s); $sh = imagesy($s);
        $dh = (int)($dw * $sh / $sw);
        $tmp = imagecreatetruecolor($dw, $dh);
        $white = imagecolorallocate($tmp, 255, 255, 255);
        imagefill($tmp, 0, 0, $white);
        imagecopyresampled($tmp, $s, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        imagecopymerge($im, $tmp, $dx, $H - $dy - $dh, 0, 0, $dw, $dh, 100);
        imagedestroy($s); imagedestroy($tmp);
    };

    $sw = (int)($W * 0.22);
    $overlay($stampPng, $sw, $W - $sw - 30, 40);
    $overlay($sigPng, (int)($W * 0.28), 30, 40);

    $black = imagecolorallocate($im, 30, 30, 30);
    imagestring($im, 4, 30, $H - 30, $label, $black);

    $ok = imagejpeg($im, $dest, 92);
    imagedestroy($im);
    return $ok;
}

/** Buat 1-halaman PDF berisi stempel+ttd (pure PHP, JPEG DCTDecode). */
function stamp_overlay_pdf(string $stampPng, string $sigPng, string $label, float $pw, float $ph): string {
    $tmp = tempnam(sys_get_temp_dir(), 'ovl') . '.pdf';
    $toJpeg = function (string $png, int $maxw): string {
        $s = imagecreatefrompng($png);
        $sw = imagesx($s); $sh = imagesy($s);
        $dw = min($maxw, $sw); $dh = (int)($dw * $sh / $sw);
        $t = imagecreatetruecolor($dw, $dh);
        $white = imagecolorallocate($t, 255, 255, 255);
        imagefill($t, 0, 0, $white);
        imagecopyresampled($t, $s, 0, 0, 0, 0, $dw, $dh, $sw, $sh);
        $f = tempnam(sys_get_temp_dir(), 'sj') . '.jpg';
        imagejpeg($t, $f, 90);
        imagedestroy($s); imagedestroy($t);
        return $f;
    };
    $sj1 = $toJpeg($stampPng, 300);
    $sj2 = $toJpeg($sigPng, 380);
    $d1 = getimagesize($sj1); $d2 = getimagesize($sj2);

    // posisi: kanan-bawah
    $w1 = 110; $h1 = $w1 * $d1[1] / $d1[0];
    $w2 = 150; $h2 = $w2 * $d2[1] / $d2[0];
    $x1 = $pw - $w1 - 36; $y1 = 36;
    $x2 = 36; $y2 = 36;

    $content = "q\n$w1 0 0 $h1 $x1 $y1 cm\n/Im1 Do\nQ\n"
             . "q\n$w2 0 0 $h2 $x2 $y2 cm\n/Im2 Do\nQ\n"
             . "BT /F1 10 Tf 36 " . ($y2 + $h2 + 14) . " Td (" . pdf_escape($label) . ") Tj ET\n";

    $img1 = file_get_contents($sj1);
    $img2 = file_get_contents($sj2);
    @unlink($sj1); @unlink($sj2);

    $objs = [];
    $objs[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objs[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objs[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] "
        . "/Resources << /XObject << /Im1 5 0 R /Im2 6 0 R >> /Font << /F1 7 0 R >> >> "
        . "/Contents 4 0 R >>";
    $objs[] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
    $objs[] = "<< /Type /XObject /Subtype /Image /Width {$d1[0]} /Height {$d1[1]} "
        . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($img1) . " >>\nstream\n" . $img1 . "\nendstream";
    $objs[] = "<< /Type /XObject /Subtype /Image /Width {$d2[0]} /Height {$d2[1]} "
        . "/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($img2) . " >>\nstream\n" . $img2 . "\nendstream";
    $objs[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $body) {
        $offsets[$i + 1] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$body\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    $pdf .= "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    file_put_contents($tmp, $pdf);
    return $tmp;
}

function pdf_escape(string $s): string {
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $s);
}

/** Bubuhkan stempel+ttd ke PDF via qpdf overlay. */
function stamp_pdf(string $src, string $dest, string $stampPng, string $sigPng, string $label): bool {
    $qpdf = trim((string)shell_exec('which qpdf 2>/dev/null'));
    if ($qpdf === '') return false;
    // ambil ukuran halaman pertama
    $json = shell_exec(escapeshellcmd($qpdf) . ' --json ' . escapeshellarg($src) . ' 2>/dev/null');
    $pw = 595.0; $ph = 842.0; // default A4 pt
    if ($json) {
        $d = json_decode($json, true);
        $pg = $d['pages'][0] ?? null;
        // qpdf json tidak selalu beri mediabox; pakai default bila gagal
    }
    $ovl = stamp_overlay_pdf($stampPng, $sigPng, $label, $pw, $ph);
    $cmd = escapeshellcmd($qpdf) . ' ' . escapeshellarg($src)
         . ' --overlay ' . escapeshellarg($ovl)
         . ' -- ' . escapeshellarg($dest) . ' 2>&1';
    $out = shell_exec($cmd);
    @unlink($ovl);
    return file_exists($dest) && filesize($dest) > 0;
}

/** Entry point: stamp dokumen sesuai mime. Return path file hasil atau ''. */
function stamp_document(string $src, string $mime, string $stampPng, string $sigPng, string $label): string {
    $dest = $src . '.stamped' . (str_contains($mime, 'pdf') ? '.pdf' : '.jpg');
    $ok = str_contains($mime, 'pdf')
        ? stamp_pdf($src, $dest, $stampPng, $sigPng, $label)
        : stamp_image($src, $dest, $stampPng, $sigPng, $label);
    return $ok ? $dest : '';
}
