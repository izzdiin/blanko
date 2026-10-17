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
</style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen font-sans antialiased" x-data>

<!-- Header -->
<header class="sticky top-0 z-40 bg-white/90 backdrop-blur border-b border-slate-200">
  <div class="max-w-5xl mx-auto px-4 h-14 flex items-center justify-between">
    <a href="?page=dashboard" class="flex items-center gap-2 font-bold text-lg text-indigo-700">
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
        <div class="hidden sm:flex items-center gap-2 pl-1">
          <span class="w-8 h-8 rounded-full bg-indigo-100 text-indigo-700 grid place-items-center text-sm font-bold"><?= e(mb_substr($u['name'], 0, 1)) ?></span>
          <div class="leading-tight">
            <div class="text-sm font-medium"><?= e($u['name']) ?></div>
            <div class="text-xs text-slate-400 capitalize"><?= e($u['role']) ?></div>
          </div>
        </div>
        <a href="?page=logout" class="h-9 px-3 rounded-full text-sm font-medium text-slate-500 hover:bg-slate-100 hidden sm:flex items-center">Keluar</a>
      <?php else: ?>
        <a href="?page=login" class="h-9 px-4 rounded-full bg-indigo-600 text-white text-sm font-medium flex items-center hover:bg-indigo-500">Masuk</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- Konten -->
<main class="max-w-5xl mx-auto px-4 pt-5 pb-28 sm:pb-12">
  <?= flash_show() ?>
  <?= $content ?>
</main>

<?php if ($u): ?>
<!-- Bottom tab bar (mobile) -->
<nav class="sm:hidden fixed bottom-0 inset-x-0 z-40 bg-white border-t border-slate-200 pb-safe">
  <div class="grid grid-cols-4 text-center">
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
    <a href="?page=settings" class="py-2.5 flex flex-col items-center gap-1 text-xs <?= $page==='settings' ? 'tab-active' : 'text-slate-400' ?>">
      <i class="fa-solid fa-user text-lg"></i>Profil<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
    <a href="?page=logout" class="py-2.5 flex flex-col items-center gap-1 text-xs text-slate-400">
      <i class="fa-solid fa-right-from-bracket text-lg"></i>Keluar<span class="tab-dot w-1 h-1 rounded-full bg-indigo-600"></span>
    </a>
  </div>
</nav>
<?php endif; ?>

<footer class="hidden sm:block text-center text-xs text-slate-400 pb-8">Blanko PWA — tools penerjemah tersumpah</footer>
</body>
</html>
<?php }
