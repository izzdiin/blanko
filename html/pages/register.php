<?php
// Registrasi akun baru Blanko — mitra & penerjemah.
// Akun baru berstatus pending: mitra disetujui penerjemahnya, penerjemah
// disetujui admin. Field penerjemah opsional saat daftar, wajib dilengkapi
// sebelum aktivasi (lihat pages/pending.php).
declare(strict_types=1);

$me = current_user();
if ($me) {
    redirect(($me['status'] ?? 'active') === 'pending' ? '?page=pending' : '?page=dashboard');
}

$pens = [];
foreach (db()->query("SELECT id, name FROM users WHERE role = 'penerjemah' AND status = 'active' ORDER BY name") as $r) $pens[] = $r;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $pass = $_POST['password'] ?? '';
    $pass2 = $_POST['password2'] ?? '';
    $role = $_POST['role'] ?? '';
    $penId = (int)($_POST['penerjemah_id'] ?? 0);
    $target = trim($_POST['lang_target'] ?? '');
    $reverse = !empty($_POST['lang_reverse']) ? 1 : 0;
    $sk = trim($_POST['sk'] ?? '');
    $nameAr = trim($_POST['name_ar'] ?? '');
    $nameZh = trim($_POST['name_zh'] ?? '');
    $pen = null;
    $err = '';
    if ($name === '' || $email === '') $err = 'Nama dan email wajib diisi.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Format email tidak valid.';
    elseif (strlen($pass) < 8) $err = 'Password minimal 8 karakter.';
    elseif ($pass !== $pass2) $err = 'Konfirmasi password tidak cocok.';
    elseif (!in_array($role, ['mitra', 'penerjemah'], true)) $err = 'Peran tidak valid.';
    elseif ($role === 'mitra') {
        $st = db()->prepare("SELECT id, name FROM users WHERE id = ? AND role = 'penerjemah' AND status = 'active'");
        $st->execute([$penId]);
        $pen = $st->fetch();
        if (!$pen) $err = 'Pilih penerjemah yang valid.';
    }
    if ($role === 'mitra') {
        // Field penerjemah tidak berlaku untuk mitra (disembunyikan di form).
        $target = $sk = $nameAr = $nameZh = '';
        $reverse = 0;
    }
    if ($role === 'penerjemah' && $err === '') {
        // Field penerjemah kini wajib diisi saat daftar (bukan lagi opsional).
        $missing = penerjemah_profile_missing(['lang_pair' => lang_pair_compose($target), 'sk' => $sk, 'name_ar' => $nameAr, 'name_zh' => $nameZh]);
        if ($missing) $err = 'Field penerjemah wajib diisi: ' . implode(', ', $missing) . '.';
    }
    if ($err === '') {
        $st = db()->prepare('SELECT id FROM users WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetch()) $err = 'Email sudah terdaftar. Silakan masuk.';
    }
    if ($err === '') {
        $pair = lang_pair_compose($target);
        $st = db()->prepare("INSERT INTO users (name, email, phone, password_hash, role, status, penerjemah_id, lang_pair, lang_reverse, sk, name_ar, name_zh) VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?, ?, ?, ?)");
        $st->execute([$name, $email, $phone, password_hash($pass, PASSWORD_DEFAULT), $role, $role === 'mitra' ? $penId : null, $pair, $reverse, $sk, $nameAr, $nameZh]);
        if ($role === 'mitra') {
            flash('Pendaftaran berhasil. Akun menunggu persetujuan dari penerjemah ' . $pen['name'] . '. Silakan masuk untuk melihat statusnya.');
        } else {
            flash('Pendaftaran berhasil. Akun menunggu persetujuan admin. Silakan masuk untuk melengkapi profil sambil menunggu.');
        }
        redirect('?page=login');
    }
    flash($err, 'err');
}
ob_start(); ?>
<div class="max-w-md mx-auto pt-8 sm:pt-14">
  <div class="text-center mb-8">
    <div class="w-16 h-16 rounded-3xl bg-indigo-600 text-white grid place-items-center mx-auto mb-4 shadow-lg shadow-indigo-600/30">
      <i class="fa-solid fa-user-plus text-2xl"></i>
    </div>
    <h1 class="text-2xl font-bold tracking-tight">Buat akun baru</h1>
    <p class="text-sm text-slate-500 mt-1">Akun baru perlu persetujuan sebelum bisa dipakai penuh</p>
  </div>
  <form method="post" class="bg-white rounded-3xl shadow-sm p-6 space-y-4">
    <?= csrf_field() ?>
    <div>
      <label class="block text-sm font-medium mb-1.5">Nama lengkap *</label>
      <input name="name" required value="<?= e($_POST['name'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Nama lengkap">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1.5">Email *</label>
      <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="nama@email.com">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1.5">Telepon</label>
      <input name="phone" value="<?= e($_POST['phone'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="62812...">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1.5">Password * (minimal 8 karakter)</label>
      <input type="password" name="password" required minlength="8" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="••••••••">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1.5">Konfirmasi password *</label>
      <input type="password" name="password2" required minlength="8" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="••••••••">
    </div>
    <div>
      <span class="block text-sm font-medium mb-1.5">Daftar sebagai *</span>
      <label class="inline-flex items-center gap-2 mr-4"><input type="radio" name="role" value="mitra" required <?= ($_POST['role'] ?? '') === 'mitra' ? 'checked' : '' ?>> Mitra</label>
      <label class="inline-flex items-center gap-2"><input type="radio" name="role" value="penerjemah" required <?= ($_POST['role'] ?? '') === 'penerjemah' ? 'checked' : '' ?>> Penerjemah</label>
    </div>
    <div id="fields-mitra">
      <label class="block text-sm font-medium mb-1.5">Khusus mitra: pilih penerjemah kamu</label>
      <select name="penerjemah_id" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        <option value="">-- pilih penerjemah --</option>
        <?php foreach ($pens as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= (int)($_POST['penerjemah_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (!$pens): ?><p class="text-xs text-red-600 mt-1">Belum ada penerjemah aktif, pendaftaran mitra belum bisa dilakukan.</p><?php endif; ?>
      <p class="text-xs text-slate-500 mt-1">Mitra disetujui oleh penerjemah yang dipilih.</p>
    </div>
    <div class="border-t pt-4" id="fields-penerjemah">
      <p class="text-sm font-medium mb-1.5">Khusus penerjemah (wajib diisi)</p>
      <p class="text-xs text-slate-500 mb-3">Wajib diisi saat daftar: bahasa target, SK, dan nama aksara sesuai bahasa.</p>
      <div class="space-y-4">
        <div>
          <label class="block text-sm font-medium mb-1.5">Pasangan bahasa *</label>
          <div class="flex items-center gap-2">
            <span class="h-12 px-4 rounded-2xl bg-slate-100 text-slate-500 flex items-center shrink-0 text-[15px]">Bahasa Indonesia</span>
            <span class="text-sm text-slate-500 shrink-0">ke Bahasa</span>
            <input name="lang_target" id="lang-target" list="daftar-bahasa" value="<?= e($_POST['lang_target'] ?? '') ?>" class="flex-1 min-w-0 h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Inggris">
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
          <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="lang_reverse" value="1" class="shrink-0 mt-1" <?= !empty($_POST['lang_reverse']) ? 'checked' : '' ?>><span>dan sebaliknya (Bahasa <span id="reverse-pair"><?= e(lang_pair_target($_POST['lang_target'] ?? '') ?: '...') ?></span> ke Bahasa Indonesia)</span></label>
        </div>
        <div>
          <label class="block text-sm font-medium mb-1.5">SK pengangkatan *</label>
          <input name="sk" id="sk-input" value="<?= e($_POST['sk'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Surat Keputusan ...">
        </div>
        <div id="name-ar-wrap">
          <label class="block text-sm font-medium mb-1.5">Nama Arab penerjemah *</label>
          <input name="name_ar" id="name-ar-input" value="<?= e($_POST['name_ar'] ?? '') ?>" dir="rtl" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
        <div id="name-zh-wrap">
          <label class="block text-sm font-medium mb-1.5">Nama Mandarin penerjemah *</label>
          <input name="name_zh" id="name-zh-input" value="<?= e($_POST['name_zh'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        </div>
      </div>
    </div>
    <button class="w-full h-12 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25 active:scale-[.98] transition">
      Daftar
    </button>
  </form>
  <p class="text-center text-sm text-slate-500 mt-4">Sudah punya akun? <a href="?page=login" class="text-indigo-600 font-semibold hover:underline">Masuk di sini</a></p>
</div>
<script>
(function () {
  var mitra = document.getElementById('fields-mitra');
  var pen = document.getElementById('fields-penerjemah');
  function syncRole() {
    if (!mitra || !pen) return;
    var r = document.querySelector('input[name="role"]:checked');
    mitra.style.display = (r && r.value === 'mitra') ? '' : 'none';
    pen.style.display = (r && r.value === 'penerjemah') ? '' : 'none';
    syncLang();
  }
  var radios = document.querySelectorAll('input[name="role"]');
  for (var i = 0; i < radios.length; i++) radios[i].addEventListener('change', syncRole);
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
      var r = document.querySelector('input[name="role"]:checked');
      var isPen = !!(r && r.value === 'penerjemah');
      if (tgt) tgt.required = isPen;
      var sk = document.getElementById('sk-input');
      if (sk) sk.required = isPen;
      var arIn = document.getElementById('name-ar-input');
      if (arIn) arIn.required = isPen && isArab;
      var zhIn = document.getElementById('name-zh-input');
      if (zhIn) zhIn.required = isPen && isMan;
    }
    if (tgt) tgt.addEventListener('input', syncLang);
    syncLang();
  syncRole();
})();
</script>
<?php layout('Daftar', ob_get_clean()); ?>
