<?php
// Blanko front controller. Native PHP 8.
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/pages/_layout.php';

$routes = [
    'login'      => 'pages/login.php',
    'logout'     => null,
    'dashboard'  => 'pages/dashboard.php',
    'upload'     => 'pages/upload.php',
    'preview'    => 'pages/preview.php',
    'file'       => 'pages/file.php',
    'review'     => 'pages/review.php',
    'download'   => 'pages/download.php',
    'inbox'      => 'pages/inbox.php',
    'settings'   => 'pages/settings.php',
    'gateway'    => 'pages/gateway.php',
    'rekap'      => 'pages/rekap.php',
    'rekap_export' => 'pages/rekap_export.php',
    'catatan'    => 'pages/catatan.php',
    'templates'  => 'pages/templates.php',
    'template_edit' => 'pages/template_edit.php',
    'template_preview' => 'pages/template_preview.php',
    'api_docs'   => 'pages/api_docs.php',
    'register'   => 'pages/register.php',
    'pending'    => 'pages/pending.php',
    'approvals'  => 'pages/approvals.php',
    'verify-sign' => 'pages/verify-sign.php',
    'esign-asset' => 'pages/esign-asset.php',
    'esign-preview' => 'pages/esign-preview.php',
    'font-asset' => 'pages/font-asset.php',
];

$page = $_GET['page'] ?? 'dashboard';

if ($page === 'logout') {
    session_destroy();
    redirect('?page=login');
}

if (!isset($routes[$page])) {
    http_response_code(404);
    exit('Halaman tidak ditemukan.');
}

// halaman publik: login & review via token
$public = ['login', 'review', 'register', 'verify-sign', 'esign-asset', 'esign-preview', 'font-asset'];
if (!in_array($page, $public, true) && !current_user()) {
    redirect('?page=login');
}

// Gerbang status akun: ditolak -> sesi dihancurkan & kembali ke login;
// pending -> hanya boleh membuka halaman pending & login.
$me = current_user();
if ($me && ($me['status'] ?? 'active') === 'rejected') {
    session_destroy();
    redirect('?page=login');
}
if ($me && ($me['status'] ?? 'active') === 'pending' && !in_array($page, ['pending', 'login', 'verify-sign', 'esign-asset', 'esign-preview'], true)) {
    redirect('?page=pending');
}

require __DIR__ . '/' . $routes[$page];
