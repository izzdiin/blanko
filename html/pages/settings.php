<?php
// Profil pengguna — penerjemah: profil blanko; mitra: info akun
declare(strict_types=1);
$u = require_login();
$isPen = $u['role'] === 'penerjemah';

if (!$isPen) {
    // mitra: tampilkan info akun sederhana
    ob_start(); ?>
    <div class="max-w-md mx-auto">
      <h1 class="text-2xl font-bold tracking-tight mb-5">Profil</h1>
      <div class="bg-white rounded-3xl shadow-sm p-6">
        <div class="flex items-center gap-4 mb-6">
          <span class="w-16 h-16 rounded-3xl bg-indigo-100 text-indigo-700 grid place-items-center text-2xl font-bold"><?= e(mb_substr($u['name'], 0, 1)) ?></span>
          <div>
            <div class="font-bold text-lg"><?= e($u['name']) ?></div>
            <div class="text-sm text-slate-500"><?= e($u['email']) ?></div>
            <div class="text-xs text-slate-400 capitalize mt-0.5"><?= e($u['role']) ?></div>
          </div>
        </div>
        <a href="?page=logout" class="w-full h-12 rounded-2xl bg-red-50 text-red-700 font-semibold flex items-center justify-center gap-2 hover:bg-red-100">
          <i class="fa-solid fa-right-from-bracket"></i>Keluar
        </a>
      </div>
    </div>
    <?php layout('Profil', ob_get_clean(), $u); return;
}

$st = db()->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$u['id']]);
$p = $st->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'name' => trim($_POST['name'] ?? ''),
        'name_ar' => trim($_POST['name_ar'] ?? ''),
        'sk' => trim($_POST['sk'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'lang_pair' => trim($_POST['lang_pair'] ?? ''),
    ];
    if ($f['name'] === '' || $f['email'] === '') {
        flash('Nama dan email wajib diisi.', 'err');
    } else {
        // upload cap / ttd (opsional, jpg/png maks 2MB)
        $up = function (string $key, string $prefix) use ($u): ?string {
            $fl = $_FILES[$key] ?? null;
            if (!$fl || $fl['error'] !== UPLOAD_ERR_OK) return null;
            if ($fl['size'] > 2 * 1024 * 1024) return null;
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $m = $fi->file($fl['tmp_name']);
            if (!in_array($m, ['image/jpeg', 'image/png'], true)) return null;
            $ext = $m === 'image/png' ? 'png' : 'jpg';
            $d = '/var/www/data/stamps';
            if (!is_dir($d)) mkdir($d, 0755, true);
            $name = $prefix . '_' . $u['id'] . '.' . $ext;
            return move_uploaded_file($fl['tmp_name'], $d . '/' . $name) ? $name : null;
        };
        $stamp = $up('stamp', 'stamp');
        $sig = $up('signature', 'sig');

        $sql = "UPDATE users SET name=?, name_ar=?, sk=?, address=?, city=?, phone=?, email=?, lang_pair=?";
        $par = array_values($f);
        if ($stamp) { $sql .= ", stamp_file=?"; $par[] = $stamp; }
        if ($sig) { $sql .= ", signature_file=?"; $par[] = $sig; }
        $sql .= " WHERE id=?";
        $par[] = $u['id'];
        db()->prepare($sql)->execute($par);
        flash('Profil blanko tersimpan.');
        redirect('?page=settings');
    }
    $p = array_merge($p, $_POST);
}

ob_start(); ?>
<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Profil Blanko Penerjemah</h1>
  <p class="text-sm text-slate-500 mb-5">Data ini tercetak di setiap blanko pengesahan.</p>
  <div class="bg-white rounded-3xl shadow-sm p-6">
  <form method="post" enctype="multipart/form-data" class="space-y-4">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Nama lengkap</label>
        <input name="name" required value="<?= e($p['name']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Nama Arab</label>
        <input name="name_ar" value="<?= e($p['name_ar']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" dir="rtl"></div>
    </div>
    <div><label class="block text-sm font-medium mb-1.5">SK Pengangkatan</label>
      <input name="sk" value="<?= e($p['sk']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"
             placeholder="Surat Keputusan Menteri Hukum dan HAM RI No. ..."></div>
    <div><label class="block text-sm font-medium mb-1.5">Pasangan bahasa</label>
      <input name="lang_pair" value="<?= e($p['lang_pair']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"
             placeholder="Bahasa Indonesia ke Bahasa Arab"></div>
    <div><label class="block text-sm font-medium mb-1.5">Alamat</label>
      <textarea name="address" rows="2" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"><?= e($p['address']) ?></textarea></div>
    <div class="grid sm:grid-cols-3 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Kota</label>
        <input name="city" value="<?= e($p['city']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Telp</label>
        <input name="phone" value="<?= e($p['phone']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Email</label>
        <input name="email" type="email" required value="<?= e($p['email']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
    </div>
    <div class="grid sm:grid-cols-2 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">File cap/stempel (JPG/PNG, maks 2MB)</label>
        <input type="file" name="stamp" accept=".jpg,.jpeg,.png" class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        <?php if ($p['stamp_file']): ?><div class="text-xs text-green-600 mt-1">Sudah ada: <?= e($p['stamp_file']) ?></div><?php endif; ?></div>
      <div><label class="block text-sm font-medium mb-1.5">File tanda tangan (JPG/PNG, maks 2MB)</label>
        <input type="file" name="signature" accept=".jpg,.jpeg,.png" class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        <?php if ($p['signature_file']): ?><div class="text-xs text-green-600 mt-1">Sudah ada: <?= e($p['signature_file']) ?></div><?php endif; ?></div>
    </div>
    <button class="h-12 px-8 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25 active:scale-[.98] transition">
      <i class="fa-solid fa-save mr-2"></i>Simpan Profil
    </button>
  </form>
  </div>
</div>
<?php layout('Pengaturan', ob_get_clean(), $u); ?>
