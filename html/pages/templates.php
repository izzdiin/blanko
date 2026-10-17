<?php
// Daftar template blanko (penerjemah)
declare(strict_types=1);
$u = require_role('penerjemah');

$tpls = db()->query('SELECT * FROM blanko_templates ORDER BY is_default DESC, id')->fetchAll();

ob_start(); ?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
  <div>
    <h1 class="text-2xl font-bold tracking-tight">Template Blanko</h1>
    <p class="text-sm text-slate-500 mt-0.5">Kelola versi blanko pengesahan</p>
  </div>
  <a href="?page=template_edit" class="h-11 px-5 rounded-full bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 flex items-center gap-2 shadow-lg shadow-indigo-600/25">
    <i class="fa-solid fa-plus"></i>Tambah Template
  </a>
</div>
<div class="grid gap-3 sm:grid-cols-2">
  <?php foreach ($tpls as $t): ?>
    <div class="bg-white rounded-2xl shadow-sm p-4">
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <div class="font-semibold text-[15px] truncate"><?= e($t['name']) ?></div>
          <div class="text-xs text-slate-500 mt-0.5"><?= e($t['lang_pair']) ?></div>
        </div>
        <?php if ($t['is_default']): ?>
          <span class="text-xs bg-emerald-100 text-emerald-700 px-2.5 py-1 rounded-full font-medium shrink-0">Default</span>
        <?php endif; ?>
      </div>
      <div class="flex flex-wrap gap-2 mt-3">
        <a href="?page=template_edit&id=<?= (int)$t['id'] ?>" class="h-9 px-4 rounded-full bg-slate-100 text-slate-700 text-xs font-semibold flex items-center gap-1.5 hover:bg-slate-200"><i class="fa-solid fa-pen"></i>Edit</a>
        <?php if (!$t['is_default']): ?>
        <a href="?page=template_edit&id=<?= (int)$t['id'] ?>&setdefault=1" class="h-9 px-4 rounded-full bg-emerald-100 text-emerald-700 text-xs font-semibold flex items-center gap-1.5 hover:bg-emerald-200">Default</a>
        <a href="?page=template_edit&id=<?= (int)$t['id'] ?>&delete=1" class="h-9 px-4 rounded-full bg-red-100 text-red-700 text-xs font-semibold flex items-center gap-1.5 hover:bg-red-200" onclick="return confirm('Hapus template ini?')"><i class="fa-solid fa-trash"></i></a>
        <?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$tpls): ?>
    <div class="col-span-full text-center py-16 text-slate-400 text-sm">Belum ada template.</div>
  <?php endif; ?>
</div>
<?php layout('Template Blanko', ob_get_clean(), $u); ?>
