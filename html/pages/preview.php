<?php
// Preview dokumen (mitra pemilik / penerjemah). Lazy loading untuk gambar.
declare(strict_types=1);
$u = require_login();
$id = (int)($_GET['id'] ?? 0);

$st = db()->prepare(
    "SELECT d.*, u.name AS mitra_name FROM documents d JOIN users u ON u.id = d.user_id WHERE d.id = ?"
);
$st->execute([$id]);
$d = $st->fetch();
if (!$d) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }
if ($u['role'] === 'mitra' && (int)$d['user_id'] !== (int)$u['id']) {
    http_response_code(403); exit('Akses ditolak.');
}

$fileUrl = '?page=file&id=' . $id;
$isPdf = str_contains($d['mime'], 'pdf');
// jika sudah diapprove, preview tampilkan versi stamped (dengan blanko + cap + ttd)
if ($d['status'] === 'approved' && $d['stamped_name']) {
    $fileUrl = '?page=download&id=' . $id . '&preview=1';
}

ob_start(); ?>
<div class="max-w-3xl mx-auto">
  <a href="?page=dashboard" class="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700 mb-4">
    <i class="fa-solid fa-arrow-left"></i>Kembali
  </a>
  <div class="bg-white rounded-3xl shadow-sm p-5 sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div class="min-w-0">
        <h1 class="text-xl font-bold tracking-tight truncate"><?= e($d['title']) ?></h1>
        <p class="text-xs text-slate-500 mt-1">
          <?= e($d['original_name']) ?> · <?= number_format($d['size'] / 1024, 1) ?> KB ·
          Mitra: <?= e($d['mitra_name']) ?>
        </p>
        <div class="mt-2"><?= $d['status'] === 'approved' ? '<span class="text-xs bg-emerald-100 text-emerald-700 px-2.5 py-1 rounded-full font-medium">Disetujui</span>' : ($d['status'] === 'declined' ? '<span class="text-xs bg-red-100 text-red-700 px-2.5 py-1 rounded-full font-medium">Ditolak</span>' : '<span class="text-xs bg-amber-100 text-amber-700 px-2.5 py-1 rounded-full font-medium">Menunggu</span>') ?></div>
        <?php if ($d['status'] === 'declined' && $d['decline_reason']): ?>
          <p class="text-sm text-red-600 mt-2 bg-red-50 rounded-xl px-3 py-2">Alasan penolakan: <?= e($d['decline_reason']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <div class="rounded-2xl overflow-hidden bg-slate-100 border border-slate-100">
      <?php if ($isPdf): ?>
        <div class="p-3 bg-amber-50 text-sm flex items-center gap-2">
          <i class="fa-solid fa-circle-info text-amber-600"></i>
          <span>Jika preview tidak tampil di HP, <a href="<?= e($fileUrl) ?>" target="_blank" rel="noopener" class="font-semibold text-indigo-700 underline">buka di tab baru</a>.</span>
        </div>
        <iframe src="<?= e($fileUrl) ?>" class="w-full" style="height:70vh" loading="lazy" title="Preview PDF"></iframe>
      <?php else: ?>
        <img src="<?= e($fileUrl) ?>" alt="Preview dokumen" loading="lazy" class="w-full h-auto">
      <?php endif; ?>
    </div>

    <?php if ($d['status'] === 'approved' && $d['stamped_name']): ?>
      <a href="?page=download&id=<?= $id ?>" class="mt-4 h-12 rounded-2xl bg-emerald-600 text-white font-semibold flex items-center justify-center gap-2 hover:bg-emerald-500 shadow-lg shadow-emerald-600/25">
        <i class="fa-solid fa-download"></i>Unduh Versi Sah
      </a>
    <?php endif; ?>
  </div>
</div>
<?php layout('Preview', ob_get_clean(), $u); ?>
