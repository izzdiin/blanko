<?php
// Verifikasi QR Tanda Tangan Elektronik (SIMULASI) — publik, tanpa login.
declare(strict_types=1);
require_once __DIR__ . '/../includes/esign.php';
$token = (string)($_GET['token'] ?? '');
$rec = esign_valid_token($token) ? esign_find($token) : null;
$doc = null; $pen = null;
if ($rec) {
    $st = db()->prepare('SELECT * FROM documents WHERE id = ?'); $st->execute([$rec['document_id']]); $doc = $st->fetch();
    $st = db()->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$rec['user_id']]); $pen = $st->fetch();
}
ob_start(); ?>
<div class="max-w-md mx-auto">
  <div class="bg-white rounded-3xl shadow-sm p-6">
    <?php if (!$rec || !$doc || !$pen): ?>
      <h1 class="text-xl font-bold tracking-tight">QR tidak ditemukan</h1>
      <p class="text-sm text-slate-500 mt-2">Tautan verifikasi ini tidak valid, atau dokumen belum dibubuhi QR Tanda Tangan Elektronik.</p>
    <?php else: ?>
      <div class="text-xs font-semibold bg-amber-50 text-amber-700 rounded-xl px-3 py-2 mb-4">SIMULASI — bukan tanda tangan elektronik tersertifikasi (PSrE)</div>
      <h1 class="text-xl font-bold tracking-tight">Tanda Tangan Elektronik Terverifikasi</h1>
      <dl class="text-sm mt-4 space-y-2">
        <div><dt class="text-slate-500">Dokumen</dt><dd class="font-medium"><?= e($doc['title']) ?></dd></div>
        <div><dt class="text-slate-500">Penerjemah tersumpah</dt><dd class="font-medium"><?= e($pen['name']) ?></dd></div>
        <div><dt class="text-slate-500">Waktu pembubuhan</dt><dd class="font-medium"><?= e($rec['signed_at']) ?></dd></div>
      </dl>
      <p class="text-sm font-medium mt-4 mb-1.5">Tanda tangan terekam:</p>
      <img src="?page=esign-asset&token=<?= e($token) ?>&kind=sig" alt="Tanda tangan" class="max-h-28 bg-white border border-slate-200 rounded-2xl px-2 py-1">
      <p class="text-xs text-slate-500 mt-4">Halaman ini adalah hasil simulasi e-sign Blanko: verifikasi berasal dari basis data Blanko, bukan dari penyelenggara sertifikasi elektronik. Produksi nanti memakai API Mekari eSign / Peruri.</p>
    <?php endif; ?>
  </div>
</div>
<?php layout('Verifikasi Tanda Tangan', ob_get_clean(), null); ?>
