<?php
// Tanda Tangan Elektronik — SIMULASI (bukan TTE tersertifikasi PSrE).
// Jahitan produksi: nanti fungsi-fungsi di sini diganti panggilan API
// Mekari eSign / Peruri tanpa mengubah alur halaman.
declare(strict_types=1);
require_once __DIR__ . '/Token.php';

function esign_dir(): string {
    $d = '/var/www/data/esign';
    if (!is_dir($d)) mkdir($d, 0755, true);
    return $d;
}

// Token verifikasi deterministik per (dokumen, penerjemah), HMAC dengan
// secret server. Tidak perlu baris DB sebelum approve, jadi GET review
// tidak mengubah state.
function esign_token(int $docId, int $userId): string {
    return hash_hmac('sha256', $docId . ':esign:' . $userId, token_secret());
}

function esign_verify_url(string $token): string {
    $proto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    if ($proto === '') {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (str_ends_with($host, '.netter.my.id')) $proto = 'https';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    return $proto . '://' . $host . $base . '/?page=verify-sign&token=' . urlencode($token);
}

function esign_valid_token(string $token): bool {
    return (bool)preg_match('/^[a-f0-9]{64}$/', $token);
}

// Simpan PNG QR kiriman browser (canvas) untuk satu token. Return path atau ''.
function esign_save_qr(string $dataUrl, string $token): string {
    if (!esign_valid_token($token)) return '';
    if (!preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', trim($dataUrl), $m)) return '';
    $raw = base64_decode($m[1], true);
    if ($raw === false || strlen($raw) > 200 * 1024) return '';
    $info = @getimagesizefromstring($raw);
    if (!$info || $info['mime'] !== 'image/png' || $info[0] !== $info[1] || $info[0] < 100 || $info[0] > 2000) return '';
    $dest = esign_dir() . '/qr_' . $token . '.png';
    return file_put_contents($dest, $raw) !== false ? $dest : '';
}

function esign_find(string $token): ?array {
    $st = db()->prepare('SELECT * FROM esign_records WHERE token = ?');
    $st->execute([$token]);
    $row = $st->fetch();
    return $row ?: null;
}

// Ratakan QR ke latar putih (kanvas QR harus opaque agar mudah dipindai).
function esign_flatten_qr(string $qrPng) {
    $qr = @imagecreatefrompng($qrPng);
    if (!$qr) return null;
    $w = imagesx($qr); $h = imagesy($qr);
    $flat = imagecreatetruecolor($w, $h);
    $white = imagecolorallocate($flat, 255, 255, 255);
    imagefill($flat, 0, 0, $white);
    imagecopy($flat, $qr, 0, 0, 0, 0, $w, $h);
    imagedestroy($qr);
    return $flat;
}

// Tempel QR ke dokumen gambar (GD). Koordinat fraksi terhadap gambar asli.
// Return path file temp (format sama dengan mime) atau ''.
function esign_apply_image(string $src, string $mime, string $qrPng, float $x, float $y, float $s): string {
    $im = str_contains($mime, 'png') ? @imagecreatefrompng($src) : @imagecreatefromjpeg($src);
    $qr = esign_flatten_qr($qrPng);
    if (!$im || !$qr) return '';
    $w = imagesx($im); $h = imagesy($im);
    $qw = (int)($s * $w); $qh = $qw;
    $qx = (int)($x * $w); $qy = (int)($y * $h);
    if ($qx + $qw > $w) $qx = $w - $qw;
    if ($qy + $qh > $h) $qy = $h - $qh;
    if ($qx < 0) $qx = 0; if ($qy < 0) $qy = 0;
    imagecopyresampled($im, $qr, $qx, $qy, 0, 0, $qw, $qh, imagesx($qr), imagesy($qr));
    $tmp = tempnam(sys_get_temp_dir(), 'esg') . (str_contains($mime, 'png') ? '.png' : '.jpg');
    $ok = str_contains($mime, 'png') ? imagepng($im, $tmp) : imagejpeg($im, $tmp, 92);
    imagedestroy($im); imagedestroy($qr);
    return $ok ? $tmp : '';
}

// Bangun 1 halaman PDF seukuran halaman target, hanya berisi kotak QR
// pada rect (sisanya kosong) — siap di-overlay via qpdf.
function esign_qr_overlay_pdf(string $qrPng, float $pw, float $ph, float $x, float $y, float $s): string {
    $qr = esign_flatten_qr($qrPng);
    if (!$qr) return '';
    $qw = imagesx($qr); $qh = imagesy($qr);
    $raw = '';
    for ($yy = 0; $yy < $qh; $yy++) {
        for ($xx = 0; $xx < $qw; $xx++) {
            $c = imagecolorat($qr, $xx, $yy);
            $raw .= chr(($c >> 16) & 0xFF) . chr(($c >> 8) & 0xFF) . chr($c & 0xFF);
        }
    }
    imagedestroy($qr);
    $data = gzcompress($raw, 9);
    $wPt = $s * $pw; $hPt = $wPt;
    $xPt = $x * $pw;
    $yPt = $ph - ($y * $ph) - $hPt; // UI: dari atas; PDF: dari bawah
    if ($xPt + $wPt > $pw) $xPt = $pw - $wPt;
    if ($yPt < 0) $yPt = 0;
    $content = "q\n$wPt 0 0 $hPt $xPt $yPt cm\n/Im1 Do\nQ\n";
    $objs = [];
    $objs[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objs[] = "<< /Type /Pages /Kids [3 0 R] /Count 1 >>";
    $objs[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pw $ph] "
        . "/Resources << /XObject << /Im1 4 0 R >> >> /Contents 5 0 R >>";
    $objs[] = "<< /Type /XObject /Subtype /Image /Width $qw /Height $qh /ColorSpace /DeviceRGB "
        . "/BitsPerComponent 8 /Filter /FlateDecode /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
    $objs[] = "<< /Length " . strlen($content) . " >>\nstream\n$content\nendstream";
    $pdf = "%PDF-1.4\n"; $off = [];
    foreach ($objs as $i => $b) { $off[$i+1] = strlen($pdf); $pdf .= ($i+1) . " 0 obj\n$b\nendobj\n"; }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objs)+1) . "\n0000000000 65535 f \n";
    foreach ($off as $o) $pdf .= sprintf("%010d 00000 n \n", $o);
    $pdf .= "trailer\n<< /Size " . (count($objs)+1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
    $out = tempnam(sys_get_temp_dir(), 'esq') . '.pdf';
    file_put_contents($out, $pdf);
    return $out;
}

// Tempel QR ke PDF (qpdf). $pages: first/last/all. Return path final atau ''.
function esign_apply_pdf(string $pdfPath, string $qrPng, float $x, float $y, float $s, string $pages): string {
    $qpdf = trim((string)shell_exec('which qpdf 2>/dev/null'));
    if ($qpdf === '') return '';
    $npages = (int)trim((string)shell_exec(
        escapeshellcmd($qpdf) . ' --show-npages ' . escapeshellarg($pdfPath) . ' 2>/dev/null'));
    if ($npages < 1) $npages = 1;
    $pw = 595.0; $ph = 842.0; // sama seperti asumsi strip (A4)
    $ovl = esign_qr_overlay_pdf($qrPng, $pw, $ph, $x, $y, $s);
    if ($ovl === '') return '';
    $t = sys_get_temp_dir();
    $u = bin2hex(random_bytes(6));
    $dest = $pdfPath . '.esign.pdf';
    $ok = false;
    if ($pages === 'all' || $npages === 1) {
        shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($pdfPath)
            . ' --overlay ' . escapeshellarg($ovl) . ' -- ' . escapeshellarg($dest) . ' 2>&1');
        $ok = file_exists($dest) && filesize($dest) > 0;
    } elseif ($pages === 'first') {
        $p1 = "$t/esq{$u}a.pdf"; $p1s = "$t/esq{$u}b.pdf"; $prest = "$t/esq{$u}c.pdf";
        shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($pdfPath) . ' --pages ' . escapeshellarg($pdfPath) . ' 1 -- ' . escapeshellarg($p1) . ' 2>&1');
        shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($p1) . ' --overlay ' . escapeshellarg($ovl) . ' -- ' . escapeshellarg($p1s) . ' 2>&1');
        shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($pdfPath) . ' --pages ' . escapeshellarg($pdfPath) . ' 2-' . $npages . ' -- ' . escapeshellarg($prest) . ' 2>&1');
        shell_exec(escapeshellcmd($qpdf) . ' --empty --pages ' . escapeshellarg($p1s) . ' ' . escapeshellarg($prest) . ' -- ' . escapeshellarg($dest) . ' 2>&1');
        $ok = file_exists($dest) && filesize($dest) > 0;
        @unlink($p1); @unlink($p1s); @unlink($prest);
    } else { // last
        $p1 = "$t/esq{$u}a.pdf"; $p2 = "$t/esq{$u}b.pdf"; $p2s = "$t/esq{$u}c.pdf";
        shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($pdfPath) . ' --pages ' . escapeshellarg($pdfPath) . ' 1-' . ($npages - 1) . ' -- ' . escapeshellarg($p1) . ' 2>&1');
        shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($pdfPath) . ' --pages ' . escapeshellarg($pdfPath) . " $npages -- " . escapeshellarg($p2) . ' 2>&1');
        shell_exec(escapeshellcmd($qpdf) . ' ' . escapeshellarg($p2) . ' --overlay ' . escapeshellarg($ovl) . ' -- ' . escapeshellarg($p2s) . ' 2>&1');
        shell_exec(escapeshellcmd($qpdf) . ' --empty --pages ' . escapeshellarg($p1) . ' ' . escapeshellarg($p2s) . ' -- ' . escapeshellarg($dest) . ' 2>&1');
        $ok = file_exists($dest) && filesize($dest) > 0;
        @unlink($p1); @unlink($p2); @unlink($p2s);
    }
    @unlink($ovl);
    return $ok ? $dest : '';
}
