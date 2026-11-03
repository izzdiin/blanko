<?php
// Persetujuan pendaftaran: KHUSUS Admin Blanko, untuk semua pendaftar
// (penerjemah maupun mitra). Penerjemah yang profilnya belum lengkap
// tidak bisa disetujui (tombol nonaktif + penegakan server).
declare(strict_types=1);
$u = require_login();
if (!is_approver($u)) { http_response_code(403); exit('Akses ditolak.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tid = (int)($_POST['id'] ?? 0);
    $act = $_POST['action'] ?? '';
    $st = db()->prepare('SELECT id, name, email, role, status, penerjemah_id, lang_pair, sk, name_ar, name_zh FROM users WHERE id = ? AND status = ?');
    $st->execute([$tid, 'pending']);
    $t = $st->fetch();
    if (!$t) {
        flash('Pendaftar tidak ditemukan atau sudah diproses.', 'err');
    } elseif (!can_approve($u, $t)) {
        flash('Kamu tidak berhak menyetujui pendaftar ini.', 'err');
    } elseif ($act === 'approve' && $t['role'] === 'penerjemah' && penerjemah_profile_missing($t)) {
        flash('Profil penerjemah belum lengkap: ' . implode(', ', penerjemah_profile_missing($t)) . '. Minta pendaftar melengkapinya dulu.', 'err');
    } elseif ($act === 'approve') {
        db()->prepare("UPDATE users SET status='active' WHERE id=? AND status='pending'")->execute([$tid]);
        flash('Akun ' . $t['name'] . ' disetujui dan sudah aktif.');
    } elseif ($act === 'reject') {
        db()->prepare("UPDATE users SET status='rejected' WHERE id=? AND status='pending'")->execute([$tid]);
        flash('Akun ' . $t['name'] . ' ditolak.');
    } else {
        flash('Aksi tidak dikenal.', 'err');
    }
    redirect('?page=approvals');
}

$cols = "u.id, u.name, u.email, u.phone, u.role, u.status, u.penerjemah_id, u.lang_pair, u.lang_reverse, u.sk, u.name_ar, u.name_zh, u.created_at, (SELECT name FROM users p WHERE p.id = u.penerjemah_id) AS pen_name";
$rows = db()->query("SELECT $cols FROM users u WHERE u.status='pending' ORDER BY u.created_at")->fetchAll();
ob_start(); ?>
<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Persetujuan pendaftaran</h1>
  <p class="text-sm text-slate-500 mb-5">Sebagai admin, kamu menyetujui pendaftar penerjemah dan mitra.</p>
  <?php if (!$rows): ?>
    <div class="bg-white rounded-3xl shadow-sm p-6 text-sm text-slate-500">Tidak ada pendaftaran menunggu persetujuan.</div>
  <?php endif; ?>
  <div class="space-y-4">
  <?php foreach ($rows as $t): $miss = $t['role'] === 'penerjemah' ? penerjemah_profile_missing($t) : []; ?>
    <div class="bg-white rounded-3xl shadow-sm p-6">
      <div class="flex items-start justify-between gap-4">
        <div>
          <div class="font-bold text-lg"><?= e($t['name']) ?></div>
          <div class="text-sm text-slate-500"><?= e($t['email']) ?><?= $t['phone'] ? ' &bull; ' . e($t['phone']) : '' ?></div>
          <div class="text-xs text-slate-400 capitalize mt-0.5"><?= e($t['role']) ?><?= $t['role'] === 'mitra' ? ' &bull; penerjemah: ' . e($t['pen_name'] ?? '-') : '' ?> &bull; daftar <?= e($t['created_at']) ?></div>
        </div>
        <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-amber-100 text-amber-800">pending</span>
      </div>
      <?php if ($t['role'] === 'penerjemah'): ?>
        <div class="text-sm mt-3">
          <div>Pasangan bahasa: <b><?= e(lang_pair_display($t['lang_pair'] ?? '', $t['lang_reverse'] ?? 0)) ?></b></div>
          <div>SK: <b><?= e($t['sk'] ?: '-') ?></b></div>
          <?php if ($miss): ?>
            <div class="text-amber-800 mt-1">Belum lengkap: <?= e(implode(', ', $miss)) ?></div>
          <?php else: ?>
            <div class="text-green-700 mt-1">Profil lengkap.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
      <div class="flex gap-2 mt-4">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="action" value="approve">
          <button <?= $miss ? 'disabled title="Profil belum lengkap"' : '' ?> class="h-11 px-5 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 disabled:opacity-40 disabled:cursor-not-allowed">Setujui</button>
        </form>
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
          <input type="hidden" name="action" value="reject">
          <button class="h-11 px-5 bg-red-50 text-red-700 rounded-2xl font-semibold hover:bg-red-100">Tolak</button>
        </form>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
</div>
<?php layout('Persetujuan', ob_get_clean(), $u); ?>
