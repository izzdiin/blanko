<?php
// Autoloader minimal untuk mPDF + dependensinya (tanpa composer).
// Prefix terpanjang dicek dulu agar Mpdf\Psr* tidak tertangkap Mpdf\.
spl_autoload_register(function ($class) {
    static $map = null;
    if ($map === null) {
        $b = __DIR__;
        $map = [
            'Mpdf\\PsrHttpMessageShim\\' => $b . '/psr-http-message-shim/src/',
            'Mpdf\\PsrLogAwareTrait\\' => $b . '/psr-log-aware-trait/src/',
            'Mpdf\\' => $b . '/mpdf/src/',
            'Psr\\Log\\' => $b . '/psr-log/src/',
            'Psr\\Http\\Message\\' => $b . '/psr-http-message/src/',
            'DeepCopy\\' => $b . '/deep-copy/src/DeepCopy/',
            'setasign\\Fpdi\\' => $b . '/fpdi/src/',
        ];
    }
    foreach ($map as $prefix => $dir) {
        $len = strlen($prefix);
        if (strncmp($class, $prefix, $len) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, $len)) . '.php';
            if (is_file($file)) { require $file; return; }
        }
    }
});
require_once __DIR__ . '/mpdf/src/functions.php';
require_once __DIR__ . '/deep-copy/src/DeepCopy/deep_copy.php';
require_once __DIR__ . '/random-compat/random.php';
require_once __DIR__ . '/fpdf/fpdf.php';
