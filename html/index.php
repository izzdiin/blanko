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
    'templates'  => 'pages/templates.php',
    'template_edit' => 'pages/template_edit.php',
    'template_preview' => 'pages/template_preview.php',
    'api_docs'   => 'pages/api_docs.php',
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
$public = ['login', 'review'];
if (!in_array($page, $public, true) && !current_user()) {
    redirect('?page=login');
}

require __DIR__ . '/' . $routes[$page];
