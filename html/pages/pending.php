<?php
// Halaman status akun pending: info penyetuju + lengkapi profil penerjemah.
// Akun pending hanya boleh membuka halaman ini (dan login/logout),
// ditegakkan di front controller.
declare(strict_types=1);
$u = require_login();
if (($u['status'] ?? 'active') === 'active') redirect('?page=dashboard');
if (($u['status'] ?? 'active') === 'rejected') { session_destroy(); redirect('?page=login'); }

$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$u['id']]);
$p = $st->fetch();

$penName = null;
if ($u['role'] === 'mitra' && !empty($u['penerjemah_id'])) {
    $st = db()->prepare('SELECT name FROM users WHERE id = ?');
    $st->execute([$u['penerjemah_id']]);
    $penName = ($st->fetch()['name'] ?? null);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $u['role'] === 'penerjemah') {
    csrf_check();
    $f = [
        'lang_pair' => lang_pair_compose(trim($_POST['lang_target'] ?? '')),
        'lang_reverse' => !empty($_POST['lang_reverse']) ? 1 : 0,
        'sk' => trim($_POST['sk'] ?? ''),
        'name_ar' => trim($_POST['name_ar'] ?? ''),
        'name_zh' => trim($_POST['name_zh'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
    ];
    $st = db()->prepare("UPDATE users SET lang_pair=?, lang_reverse=?, sk=?, name_ar=?, name_zh=?, phone=?, address=?, city=? WHERE id=? AND status='pending'");
    $st->execute([...array_values($f), $u['id']]);
    flash('Profil tersimpan.');
    redirect('?page=pending');
}

$miss = $u['role'] === 'penerjemah' ? penerjemah_profile_missing($p) : [];
ob_start(); ?>
<div class="max-w-md mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-5">Akun menunggu persetujuan</h1>
  <div class="bg-white rounded-3xl shadow-sm p-6">
    <div class="flex items-center gap-4 mb-4">
      <span class="w-16 h-16 rounded-3xl bg-amber-100 text-amber-700 grid place-items-center text-2xl font-bold"><?= e(mb_substr($u['name'], 0, 1)) ?></span>
      <div>
        <div class="font-bold text-lg"><?= e($u['name']) ?></div>
        <div class="text-sm text-slate-500"><?= e($u['email']) ?></div>
        <div class="text-xs text-slate-400 capitalize mt-0.5"><?= e($u['role']) ?></div>
      </div>
    </div>
    <?php if ($u['role'] === 'mitra'): ?>
      <p class="text-sm text-slate-600">Akun mitra ini menunggu persetujuan dari penerjemah <b><?= e($penName ?? '-') ?></b>. Setelah disetujui, kamu bisa masuk penuh.</p>
    <?php else: ?>
      <?php if (!$miss): ?>
        <p class="text-sm text-green-700 bg-green-50 border border-green-200 rounded-2xl px-4 py-3">Profil lengkap. Menunggu persetujuan admin.</p>
      <?php else: ?>
        <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3">Sebelum bisa diaktifkan, lengkapi dulu: <b><?= e(implode(', ', $miss)) ?></b>.</p>
      <?php endif; ?>
    <?php endif; ?>
    <a href="?page=logout" class="mt-5 w-full h-12 rounded-2xl bg-red-50 text-red-700 font-semibold flex items-center justify-center gap-2 hover:bg-red-100">
      <i class="fa-solid fa-right-from-bracket"></i>Keluar
    </a>
  </div>
  <?php if ($u['role'] === 'penerjemah'): ?>
  <div class="bg-white rounded-3xl shadow-sm p-6 mt-4">
    <h2 class="font-bold mb-4">Lengkapi profil penerjemah</h2>
    <form method="post" class="space-y-4">
      <?= csrf_field() ?>
      <div>
        <label class="block text-sm font-medium mb-1.5">Pasangan bahasa</label>
        <div class="flex items-center gap-2">
          <span class="h-12 px-4 rounded-2xl bg-slate-100 text-slate-500 flex items-center shrink-0 text-[15px]">Bahasa Indonesia</span>
          <span class="text-sm text-slate-500 shrink-0">ke Bahasa</span>
          <input name="lang_target" id="lang-target" list="daftar-bahasa" value="<?= e(lang_pair_target($p['lang_pair'] ?? '')) ?>" class="flex-1 min-w-0 h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Inggris">
        </div>
        <datalist id="daftar-bahasa">
            <option value="Arab"></option>
            <option value="Inggris"></option>
            <option value="Mandarin"></option>
            <option value="Belanda"></option>
            <option value="Jerman"></option>
            <option value="Prancis"></option>
            <option value="Jepang"></option>
            <option value="Korea"></option>
            <option value="Spanyol"></option>
            <option value="Italia"></option>
            <option value="Rusia"></option>
            <option value="Turki"></option>
          </datalist>
      </div>
      <div>
        <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="lang_reverse" value="1" class="shrink-0 mt-1" <?= !empty($p['lang_reverse']) ? 'checked' : '' ?>><span>dan sebaliknya (Bahasa <span id="reverse-pair"><?= e(lang_pair_target($p['lang_pair'] ?? '') ?: '...') ?></span> ke Bahasa Indonesia)</span></label>
      </div>
      <div>
        <label class="block text-sm font-medium mb-1.5">SK pengangkatan</label>
        <input name="sk" value="<?= e($p['sk'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <div id="name-ar-wrap">
        <label class="block text-sm font-medium mb-1.5">Nama Arab penerjemah</label>
        <input name="name_ar" value="<?= e($p['name_ar'] ?? '') ?>" dir="rtl" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <div id="name-zh-wrap">
        <label class="block text-sm font-medium mb-1.5">Nama Mandarin penerjemah</label>
        <input name="name_zh" value="<?= e($p['name_zh'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1.5">Telepon</label>
        <input name="phone" value="<?= e($p['phone'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <div>
        <label class="block text-sm font-medium mb-1.5">Alamat</label>
        <textarea name="address" rows="2" class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"><?= e($p['address'] ?? '') ?></textarea>
      </div>
      <div>
        <label class="block text-sm font-medium mb-1.5">Kota</label>
        <input name="city" value="<?= e($p['city'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <button class="h-12 px-8 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25 active:scale-[.98] transition">
        <i class="fa-solid fa-save mr-2"></i>Simpan Profil
      </button>
    </form>
  </div>
  <?php endif; ?>
</div>
<script>
(function () {
  function bareLang(v) {
      v = (v || '').trim();
      v = v.replace(/^Bahasa Indonesia ke\s+/i, '');
      v = v.replace(/^Bahasa\s+/i, '');
      v = v.trim();
      return v ? v.charAt(0).toUpperCase() + v.slice(1) : '';
    }
    var tgt = document.getElementById('lang-target');
    var rev = document.getElementById('reverse-pair');
    var arWrap = document.getElementById('name-ar-wrap');
    var zhWrap = document.getElementById('name-zh-wrap');
    function syncLang() {
      var b = tgt ? bareLang(tgt.value) : '';
      if (rev) rev.textContent = b || '...';
      var isArab = /arab/i.test(b);
      var isMan = /mandarin|cina|tiongkok|tionghoa|chinese/i.test(b);
      if (arWrap) arWrap.style.display = isArab ? '' : 'none';
      if (zhWrap) zhWrap.style.display = isMan ? '' : 'none';
    }
    if (tgt) tgt.addEventListener('input', syncLang);
    syncLang();
})();
</script>
<?php layout('Menunggu persetujuan', ob_get_clean(), $u); ?>
