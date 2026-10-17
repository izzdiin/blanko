<?php
// API dokumen: search, filter, pagination (JSON) — untuk AlpineJS
declare(strict_types=1);
$u = require_login();

$q = trim($_GET['q'] ?? '');
$status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['p'] ?? 1));
$perPage = 10;

$where = [];
$params = [];
if ($u['role'] === 'mitra') {
    $where[] = 'd.user_id = ?';
    $params[] = $u['id'];
}
if ($q !== '') {
    $where[] = '(d.title LIKE ? OR d.original_name LIKE ?)';
    $params[] = "%$q%";
    $params[] = "%$q%";
}
if (in_array($status, ['pending','approved','declined'], true)) {
    $where[] = "d.status = ?";
    $params[] = $status;
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$st = db()->prepare("SELECT COUNT(*) FROM documents d $whereSql");
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $perPage));
$page = min($page, $pages);
$offset = ($page - 1) * $perPage;

$st = db()->prepare(
    "SELECT d.id, d.title, d.original_name, d.status, d.created_at, d.stamped_name, d.decline_reason, u.name AS mitra_name
     FROM documents d JOIN users u ON u.id = d.user_id
     $whereSql ORDER BY d.id DESC LIMIT $perPage OFFSET $offset"
);
$st->execute($params);
$docs = $st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode([
    'docs' => $docs,
    'page' => $page,
    'pages' => $pages,
    'total' => $total,
    'role' => $u['role'],
], JSON_UNESCAPED_UNICODE);
