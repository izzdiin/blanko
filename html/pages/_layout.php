<?php
// Layout shell PWA — modern, clean, mobile-first.
declare(strict_types=1);
function layout(string $title, string $content, ?array $user = null): void {
    $u = $user ?? current_user();
    $page = $_GET['page'] ?? 'dashboard';
    $isPenerjemah = $u && $u['role'] === 'penerjemah';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($title) ?> — Blanko</title>
<meta name="theme-color" content="#4f46e5">
<link rel="manifest" href="manifest.json">
<link rel="icon" href="assets/icon-192.png">
<link rel="apple-touch-icon" href="assets/icon-192.png">
<link rel="preload" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"></noscript>
<link rel="stylesheet" href="assets/app.css?v=20261010b">
<script defer src="https://cdnjs.cloudflare.com/ajax/libs/alpinejs/3.13.8/cdn.min.js"></script>
<script defer src="assets/app.js?v=20261010c"></script>
<style>
  .pb-safe { padding-bottom: env(safe-area-inset-bottom); }
  .tab-active { color: #4f46e5; }
  .tab-active .tab-dot { opacity: 1; transform: scale(1); }
  .tab-dot { opacity: 0; transform: scale(0); transition: all .2s; }
  /* Sidebar desktop: tampil >=640px (selaras breakpoint sm: layout ini) */
  .sidebar-desktop { display: none; }
  /* Logo header mode PC: pojok kiri, di atas kolom sidebar; tersembunyi di HP */
  .header-logo-pc { display: none; }
  .side-link { display: flex; align-items: center; gap: 0.75rem; padding: 0.625rem 0.875rem; border-radius: 0.75rem; color: #475569; font-size: 0.875rem; font-weight: 500; }
  .side-link:hover { background: #f1f5f9; }
  .side-link.active { background: #eef2ff; color: #4f46e5; font-weight: 600; }
  @media (min-width: 640px) {
    .sidebar-desktop { display: flex; flex-direction: column; position: fixed; left: 0; top: 56px; bottom: 0; width: 240px; z-index: 30; background: #fff; border-right: 1px solid #e2e8f0; padding: 1rem 0.75rem; overflow-y: auto; }
    .shift-desktop { margin-left: 240px; }
    .hide-on-desktop { display: none !important; }
    /* Logo header dalam alur disembunyikan di PC (digantikan zona logo pojok kiri); ruangnya tetap dijaga agar tombol header tidak bergeser */
    .logo-hide-desktop { visibility: hidden; }
    /* Zona logo PC: menempel pojok kiri header, selebar sidebar (240px), sejajar isi sidebar */
    .header-logo-pc { display: flex; position: fixed; left: 0; top: 0; width: 240px; height: 56px; align-items: center; padding: 0 0.75rem; z-index: 50; }
  }
</style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen font-sans antialiased" x-data>
<?php
$cntAppr = 0;
if ($u && (!empty($u['is_admin']) || $u['role'] === 'penerjemah')) {
    if (!empty($u['is_admin'])) {
        $cntAppr = (int)db()->query("SELECT COUNT(*) FROM users WHERE status='pending'")->fetchColumn();
    } else {
        $stAppr = db()->prepare("SELECT COUNT(*) FROM users WHERE status='pending' AND role='mitra' AND penerjemah_id=?");
        $stAppr->execute([$u['id']]);
        $cntAppr = (int)$stAppr->fetchColumn();
    }
}
?>

<!-- Header -->
<header class="sticky top-0 z-40 bg-white/90 backdrop-blur border-b border-slate-200">
  <?php if ($u): ?>
  <!-- Logo mode PC: tetap di header, pojok kiri, di atas kolom sidebar -->
  <div class="header-logo-pc">
    <a href="?page=dashboard" class="flex items-center gap-2 font-bold text-lg text-indigo-700">
      <span class="w-8 h-8 rounded-xl bg-indigo-600 text-white grid place-items-center"><i class="fa-solid fa-stamp text-sm"></i></span>
      <span class="tracking-tight">Blanko</span>
    </a>
  </div>
  <?php endif; ?>
  <div class="shift-desktop max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
    <a href="?page=dashboard" class="flex items-center gap-2 font-bold text-lg text-indigo-700<?= $u ? ' logo-hide-desktop' : '' ?>">
      <span class="w-8 h-8 rounded-xl bg-indigo-600 text-white grid place-items-center"><i class="fa-solid fa-stamp text-sm"></i></span>
      <span class="tracking-tight">Blanko</span>
    </a>
    <div class="flex items-center gap-2">
      <button id="pwa-install-btn" hidden class="h-9 px-3 rounded-full bg-emerald-600 text-white text-sm font-medium hover:bg-emerald-500 flex items-center gap-1.5">
        <i class="fa-solid fa-download text-xs"></i><span class="hidden sm:inline">Install</span>
      </button>
      <?php if ($u): ?>
        <a href="?page=inbox" class="w-9 h-9 rounded-full grid place-items-center text-slate-500 hover:bg-slate-100 relative" title="Notifikasi">
          <i class="fa-solid fa-bell"></i>
        </a>
        <?php if (!empty($u['is_admin']) || $u['role'] === 'penerjemah'): ?>
          <a href="?page=approvals" class="hide-on-desktop w-9 h-9 rounded-full grid place-items-center text-slate-500 hover:bg-slate-100 relative" title="Persetujuan pendaftaran">
            <i class="fa-solid fa-user-check"></i>
            <?php if ($cntAppr > 0): ?><span class="absolute -top-0.5 -right-0.5 min-w-4 h-4 px-1 rounded-full bg-red-500 text-white text-[10px] grid place-items-center"><?= $cntAppr ?></span><?php endif; ?>
          </a>
        <?php endif; ?>
        <a href="?page=logout" class="hide-on-desktop h-9 px-3 rounded-full text-sm font-medium text-slate-500 hover:bg-slate-100 hidden sm:flex items-center">Keluar</a>
      <?php else: ?>
        <a href="?page=login" class="h-9 px-4 rounded-full bg-indigo-600 text-white text-sm font-medium flex items-center hover:bg-indigo-500">Masuk</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<?php if ($u): ?>
<!-- Sidebar (desktop) -->
<aside class="sidebar-desktop">
  <div class="flex items-center gap-2" style="padding-bottom:0.75rem;margin-bottom:0.75rem;border-bottom:1px solid #e2e8f0">
    <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 grid place-items-center text-sm font-bold"><?= e(mb_substr($u['name'], 0, 1)) ?></span>
    <div class="leading-tight">
      <div class="text-sm font-medium"><?= e($u['name']) ?></div>
      <div class="text-xs text-slate-400 capitalize"><?= e($u['role']) ?></div>
    </div>
  </div>

  <nav class="flex flex-col gap-1">
    <a href="?page=dashboard" class="side-link<?= $page==='dashboard' ? ' active' : '' ?>"><i class="fa-solid fa-house" style="width:20px;text-align:center"></i>Beranda</a>
    <?php if ($isPenerjemah): ?>
    <a href="?page=templates" class="side-link<?= in_array($page, ['templates','template_edit']) ? ' active' : '' ?>"><i class="fa-solid fa-layer-group" style="width:20px;text-align:center"></i>Template</a>
    <?php else: ?>
    <a href="?page=upload" class="side-link<?= $page==='upload' ? ' active' : '' ?>"><i class="fa-solid fa-cloud-arrow-up" style="width:20px;text-align:center"></i>Upload</a>
    <?php endif; ?>
    <?php if ($isPenerjemah): ?>
    <a href="?page=rekap" class="side-link<?= $page==='rekap' ? ' active' : '' ?>"><i class="fa-solid fa-chart-column" style="width:20px;text-align:center"></i>Rekap Tahunan</a>
    <?php else: ?>
    <a href="?page=catatan" class="side-link<?= $page==='catatan' ? ' active' : '' ?>"><i class="fa-solid fa-clipboard-list" style="width:20px;text-align:center"></i>Catatan Pencocokan</a>
    <?php endif; ?>
    <a href="?page=settings" class="side-link<?= $page==='settings' ? ' active' : '' ?>"><i class="fa-solid fa-user" style="width:20px;text-align:center"></i>Profil</a>
    <?php if (!empty($u['is_admin']) || $u['role'] === 'penerjemah'): ?>
    <a href="?page=approvals" class="side-link<?= $page==='approvals' ? ' active' : '' ?>"><i class="fa-solid fa-user-check" style="width:20px;text-align:center"></i>Persetujuan<?php if ($cntAppr > 0): ?><span class="min-w-4 h-4 px-1 rounded-full bg-red-500 text-white text-[10px] grid place-items-center" style="margin-left:auto"><?= $cntAppr ?></span><?php endif; ?></a>
    <?php endif; ?>
  </nav>
  <div style="margin-top:auto;padding-top:1rem;border-top:1px solid #e2e8f0">
    <a href="?page=logout" class="side-link"><i class="fa-solid fa-right-from-bracket" style="width:20px;text-align:center"></i>Keluar</a>
  </div>
</aside>
<?php endif; ?>

<!-- Konten -->
<main class="shift-desktop max-w-5xl mx-auto px-4 pt-5 pb-28 sm:pb-12">
  <?= flash_show() ?>
  <?= $content ?>
</main>

<?php if ($u): ?>
<!-- Bottom tab bar (mobile) -->
<nav class="sm:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-slate-200 pb-safe">
  <div class="grid grid-cols-5 text-center">
    <a href="?page=dashboard" class="py-2.5 flex flex-col items-center gap-1 text-xs <?= $page==='dashboard' ? 'tab-active' : 'text-slate-400' ?>">
      <i class="fa-solid fa-house text-lg"></i>Beranda<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
    <?php if ($isPenerjemah): ?>
    <a href="?page=templates" class="py-2.5 flex flex-col items-center gap-1 text-xs <?= in_array($page, ['templates','template_edit']) ? 'tab-active' : 'text-slate-400' ?>">
      <i class="fa-solid fa-layer-group text-lg"></i>Template<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
    <?php else: ?>
    <a href="?page=upload" class="py-2.5 flex flex-col items-center gap-1 text-xs <?= $page==='upload' ? 'tab-active' : 'text-slate-400' ?>">
      <i class="fa-solid fa-cloud-arrow-up text-lg"></i>Upload<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
    <?php endif; ?>
    <?php if ($isPenerjemah): ?>
    <a href="?page=rekap" class="py-2.5 flex flex-col items-center gap-1 text-xs <?= $page==='rekap' ? 'tab-active' : 'text-slate-400' ?>">
      <i class="fa-solid fa-chart-column text-lg"></i>Rekap<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
    <?php else: ?>
    <a href="?page=catatan" class="py-2.5 flex flex-col items-center gap-1 text-xs <?= $page==='catatan' ? 'tab-active' : 'text-slate-400' ?>">
      <i class="fa-solid fa-clipboard-list text-lg"></i>Catatan<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
    <?php endif; ?>
    <a href="?page=settings" class="py-2.5 flex flex-col items-center gap-1 text-xs <?= $page==='settings' ? 'tab-active' : 'text-slate-400' ?>">
      <i class="fa-solid fa-user text-lg"></i>Profil<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
    <a href="?page=logout" class="py-2.5 flex flex-col items-center gap-1 text-xs text-slate-400">
      <i class="fa-solid fa-right-from-bracket text-lg"></i>Keluar<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
  </div>
</nav>
<?php endif; ?>

<footer class="shift-desktop hidden sm:block text-center text-xs text-slate-400 pb-8">Blanko PWA — tools penerjemah tersumpah</footer>
</body>
</html>
<?php }
