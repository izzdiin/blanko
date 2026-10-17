<?php
// Inbox notifikasi — modern
declare(strict_types=1);
$u = require_login();
$st = db()->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50");
$st->execute([$u['id']]);
$rows = $st->fetchAll();

$chStyle = [
  'wa' => ['icon' => 'fa-brands fa-whatsapp', 'bg' => 'bg-emerald-100 text-emerald-600'],
  'email' => ['icon' => 'fa-solid fa-envelope', 'bg' => 'bg-indigo-100 text-indigo-600'],
  'inbox' => ['icon' => 'fa-solid fa-bell', 'bg' => 'bg-slate-100 text-slate-500'],
];

ob_start(); ?>
<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Notifikasi</h1>
  <p class="text-sm text-slate-500 mb-5">Riwayat pemberitahuan untuk Anda</p>
  <div class="space-y-3">
  <?php foreach ($rows as $n):
    $s = $chStyle[$n['channel']] ?? $chStyle['inbox'];
  ?>
    <div class="bg-white rounded-2xl shadow-sm p-4 flex gap-3">
      <div class="w-10 h-10 rounded-xl grid place-items-center shrink-0 <?= $s['bg'] ?>">
        <i class="<?= $s['icon'] ?>"></i>
      </div>
      <div class="flex-1 min-w-0 overflow-hidden">
        <?php if ($n['subject']): ?><div class="font-semibold text-[15px] truncate"><?= e($n['subject']) ?></div><?php endif; ?>
        <div class="text-sm text-slate-600 whitespace-pre-line break-all mt-0.5"><?= e($n['body']) ?></div>
        <div class="text-xs text-slate-400 mt-2"><?= e($n['created_at']) ?> · <?= e($n['channel']) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
  <?php if (!$rows): ?>
    <div class="text-center py-16">
      <div class="w-16 h-16 rounded-2xl bg-slate-100 grid place-items-center mx-auto mb-3 text-slate-400 text-2xl"><i class="fa-solid fa-bell-slash"></i></div>
      <p class="text-slate-500 text-sm">Belum ada notifikasi.</p>
    </div>
  <?php endif; ?>
  </div>
</div>
<?php layout('Notifikasi', ob_get_clean(), $u); ?>
