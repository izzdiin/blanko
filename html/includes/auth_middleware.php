<?php
require_once __DIR__ . '/../../config.php';

function check_access(array $allowed_roles): void {
    if (!isset($_SESSION['is_logged_in']) || $_SESSION['is_logged_in'] !== true) {
        header('Location: ../login');
        exit();
    }

    $user_role = $_SESSION['role'] ?? '';
    
    if (!in_array($user_role, $allowed_roles, true)) {
        http_response_code(403);
        die("<div style='font-family:sans-serif; text-align:center; padding:50px;'>
                <h1 style='color:#dc2626;'>403 - Akses Ditolak</h1>
                <p>Role Anda (<strong>".htmlspecialchars($user_role)."</strong>) tidak memiliki izin untuk mengakses halaman ini.</p>
                <a href='../'>Kembali ke Beranda</a>
             </div>");
    }
}