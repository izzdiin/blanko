<?php
// Profil pengguna — penerjemah: profil blanko; mitra: info akun
declare(strict_types=1);
require_once __DIR__ . '/../includes/Rekap.php';
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

// Rekam TTD elektronik (simulasi) — form terpisah dari profil.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['esign_save'])) {
    csrf_check();
    require_once __DIR__ . '/../includes/esign.php';
    $saved = null;
    $dataUrl = trim($_POST['esign_sig_data'] ?? '');
    if ($dataUrl !== '' && preg_match('/^data:image\/png;base64,([A-Za-z0-9+\/=]+)$/', $dataUrl, $m)) {
        $raw = base64_decode($m[1], true);
        if ($raw !== false && strlen($raw) <= 2 * 1024 * 1024) {
            $info = @getimagesizefromstring($raw);
            if ($info && $info['mime'] === 'image/png') $saved = $raw;
        }
    }
    if ($saved === null && isset($_FILES['esign_sig_upload']) && $_FILES['esign_sig_upload']['error'] === UPLOAD_ERR_OK) {
        $fl = $_FILES['esign_sig_upload'];
        if ($fl['size'] <= 2 * 1024 * 1024) {
            $fi = new finfo(FILEINFO_MIME_TYPE);
            $m = $fi->file($fl['tmp_name']);
            if (in_array($m, ['image/jpeg', 'image/png'], true)) {
                $im = @imagecreatefromstring((string)file_get_contents($fl['tmp_name']));
                if ($im) {
                    $w = imagesx($im); $h = imagesy($im);
                    if ($w > 800) {
                        $nw = 800; $nh = (int)($h * 800 / $w);
                        $tmp = imagecreatetruecolor($nw, $nh);
                        imagealphablending($tmp, false); imagesavealpha($tmp, true);
                        $trans = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
                        imagefill($tmp, 0, 0, $trans);
                        imagecopyresampled($tmp, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
                        imagedestroy($im); $im = $tmp;
                    }
                    ob_start(); imagepng($im); $saved = (string)ob_get_clean();
                    imagedestroy($im);
                }
            }
        }
    }
    if ($saved === null) {
        flash('Gambar tanda tangan tidak valid. Gambar di kanvas atau unggah JPG/PNG (maks 2MB).', 'err');
    } else {
        $name = 'sig_' . $u['id'] . '.png';
        if (file_put_contents(esign_dir() . '/' . $name, $saved) !== false) {
            db()->prepare('UPDATE users SET esign_sig_file=? WHERE id=?')->execute([$name, $u['id']]);
            flash('Tanda tangan elektronik (simulasi) tersimpan.');
        } else {
            flash('Gagal menyimpan tanda tangan.', 'err');
        }
    }
    redirect('?page=settings');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'name' => trim($_POST['name'] ?? ''),
        'name_ar' => trim($_POST['name_ar'] ?? ''),
        'name_zh' => trim($_POST['name_zh'] ?? ''),
        'sk' => trim($_POST['sk'] ?? ''),
        'sk_date' => trim($_POST['sk_date'] ?? ''),
        'address' => trim($_POST['address'] ?? ''),
        'city' => trim($_POST['city'] ?? ''),
        'phone' => trim($_POST['phone'] ?? ''),
        'email' => trim($_POST['email'] ?? ''),
        'lang_pair' => lang_pair_compose(trim($_POST['lang_target'] ?? '')),
        'lang_reverse' => !empty($_POST['lang_reverse']) ? 1 : 0,
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

        $sql = "UPDATE users SET name=?, name_ar=?, name_zh=?, sk=?, sk_date=?, address=?, city=?, phone=?, email=?, lang_pair=?, lang_reverse=?";
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
      <div id="name-ar-wrap"><label class="block text-sm font-medium mb-1.5">Nama Arab penerjemah</label>
        <input name="name_ar" value="<?= e($p['name_ar']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" dir="rtl"></div>
    </div>
    <div id="name-zh-wrap"><label class="block text-sm font-medium mb-1.5">Nama Mandarin penerjemah</label>
      <input name="name_zh" value="<?= e($p['name_zh'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Untuk penerjemah Mandarin"></div>
    <div><label class="block text-sm font-medium mb-1.5">SK Pengangkatan</label>
      <input name="sk" value="<?= e($p['sk']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"
             placeholder="Surat Keputusan Menteri Hukum dan HAM RI No. ..."></div>
    <div><label class="block text-sm font-medium mb-1.5">Tanggal SK Pengangkatan</label>
      <input name="sk_date" value="<?= e($p['sk_date'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"
             placeholder="cth: 14 Maret 2022">
      <p class="text-xs text-slate-400 mt-1">Tercetak di blanko pengesahan dan menjadi dasar usulan periode Tahun Lapor.</p></div>
    <div><label class="block text-sm font-medium mb-1.5">Pasangan bahasa</label>
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
    <div><label class="flex items-start gap-2 text-sm"><input type="checkbox" name="lang_reverse" value="1" class="shrink-0 mt-1" <?= !empty($p['lang_reverse']) ? 'checked' : '' ?>><span>dan sebaliknya (Bahasa <span id="reverse-pair"><?= e(lang_pair_target($p['lang_pair'] ?? '') ?: '...') ?></span> ke Bahasa Indonesia)</span></label></div>
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
<div class="border-t pt-4 mt-4">
  <h2 class="font-bold text-lg tracking-tight">Tanda Tangan Elektronik (Simulasi)</h2>
  <p class="text-sm text-slate-500 mt-1">Gambar TTD di kanvas. TTD ini dipakai untuk QR e-sign saat approve. Ini simulasi, bukan TTE tersertifikasi.</p>
  <?php if (!empty($p['esign_sig_file'])): ?>
    <p class="text-sm font-medium mt-4 mb-1.5">TTD tersimpan saat ini:</p>
    <img src="?page=esign-asset&kind=sig" alt="Tanda tangan tersimpan" class="max-h-24 bg-white border border-slate-200 rounded-2xl px-2 py-1">
  <?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="space-y-3 mt-4" id="esign-form">
    <?= csrf_field() ?>
    <input type="hidden" name="esign_save" value="1">
    <input type="hidden" name="esign_sig_data" id="esign-sig-data">
    <div>
      <label class="block text-sm font-medium mb-1.5">Gambar di kanvas</label>
      <canvas id="esign-canvas" width="600" height="200" style="touch-action:none" class="w-full bg-white border border-slate-200 rounded-2xl touch-none cursor-crosshair"></canvas>
    </div>
    <div class="flex gap-2">
      <button type="button" id="esign-clear" class="h-11 px-5 rounded-2xl bg-slate-100 text-slate-700 font-medium hover:bg-slate-200">Hapus Gambar</button>
      <button class="h-11 px-6 rounded-2xl bg-indigo-600 text-white font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25">Simpan Tanda Tangan</button>
    </div>
  </form>
</div>
<script>
(function () {
  var cv = document.getElementById('esign-canvas');
  if (!cv) return;
  var ctx = cv.getContext('2d');
  ctx.strokeStyle = '#111'; ctx.lineWidth = 2.5; ctx.lineCap = 'round'; ctx.lineJoin = 'round';
  var drawing = false, hasStroke = false, last = null;
  function pos(ev) {
    var r = cv.getBoundingClientRect();
    return { x: (ev.clientX - r.left) * cv.width / r.width, y: (ev.clientY - r.top) * cv.height / r.height };
  }
  cv.addEventListener('pointerdown', function (ev) { drawing = true; last = pos(ev); cv.setPointerCapture(ev.pointerId); });
  cv.addEventListener('pointermove', function (ev) {
    if (!drawing || !last) return;
    var p = pos(ev);
    ctx.beginPath(); ctx.moveTo(last.x, last.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    last = p; hasStroke = true;
  });
  function endDraw() { drawing = false; last = null; }
  cv.addEventListener('pointerup', endDraw);
  cv.addEventListener('pointercancel', endDraw);
  document.getElementById('esign-clear').addEventListener('click', function () {
    ctx.clearRect(0, 0, cv.width, cv.height); hasStroke = false;
    document.getElementById('esign-sig-data').value = '';
  });
  document.getElementById('esign-form').addEventListener('submit', function (ev) {
    if (!hasStroke) {
      ev.preventDefault();
      alert('Gambar dulu tanda tangan di kanvas.');
      return;
    }
    document.getElementById('esign-sig-data').value = cv.toDataURL('image/png');
  });
})();
</script>
  </div>
<?php
$rkAktif = !empty($p['rekap_periode_aktif']);
$rkPrefill = rekap_default_period(rekap_parse_tanggal($p['sk_date'] ?? ''));
$rkMulai = $p['rekap_periode_mulai'] ?? ($rkPrefill[0] ?? '');
$rkSelesai = $p['rekap_periode_selesai'] ?? ($rkPrefill[1] ?? '');
?>
<div class="bg-white rounded-3xl shadow-sm p-6 mt-5">
  <h2 class="font-bold text-lg tracking-tight">Tahun Lapor — Rekap Tahunan</h2>
  <p class="text-sm text-slate-500 mt-1">Periode laporan pelaksanaan jabatan tahunan Anda. Bila aktif, panel rekap menampilkan hitung mundur tenggat dan pengingat dikirim via WA dan email pada H-30 dan H-7. Tanggal usulan berasal dari tanggal SK pengangkatan dan dapat disesuaikan bebas.</p>
  <form method="post" action="?page=rekap" class="grid sm:grid-cols-3 gap-4 items-end mt-4">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="period_save">
    <input type="hidden" name="return" value="settings">
    <div><label class="block text-sm font-medium mb-1.5">Mulai periode</label>
      <input type="date" name="periode_mulai" value="<?= e($rkMulai) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
    <div><label class="block text-sm font-medium mb-1.5">Selesai / tenggat</label>
      <input type="date" name="periode_selesai" value="<?= e($rkSelesai) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
    <div class="flex items-center gap-3">
      <label class="flex items-center gap-1.5 text-sm shrink-0"><input type="checkbox" name="periode_aktif" value="1" <?= $rkAktif ? 'checked' : '' ?> class="w-4 h-4 accent-indigo-600">Aktif</label>
      <button class="h-12 px-5 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500">Simpan</button>
    </div>
  </form>
  <a href="?page=rekap" class="inline-block text-sm text-indigo-600 font-medium mt-4">Buka panel Rekap Tahunan →</a>
</div>
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
<?php layout('Pengaturan', ob_get_clean(), $u); ?>
