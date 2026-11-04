<?php
// Pengaturan Gateway WhatsApp — khusus admin.
// Gateway bersifat dinamis: semua nilai disimpan di tabel settings dan
// dipakai notify_wa() lewat includes/settings.php. Tidak ada hardcode.
declare(strict_types=1);
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/Notify.php';
$u = require_role('admin');

$testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (isset($_POST['save'])) {
        $baseUrl = trim($_POST['wa_base_url'] ?? '');
        $sendPath = trim($_POST['wa_send_path'] ?? '/send');
        $deviceId = trim($_POST['wa_device_id'] ?? '');
        $token = trim($_POST['wa_token'] ?? '');
        $tpl = trim($_POST['wa_payload_template'] ?? '');
        $err = null;
        if ($baseUrl !== '' && !preg_match('#^https?://#i', $baseUrl)) {
            $err = 'Base URL harus diawali http:// atau https://.';
        } elseif ($sendPath === '' || $sendPath[0] !== '/') {
            $err = 'Path kirim harus diawali garis miring, mis. /send.';
        } elseif ($tpl !== '') {
            $probe = strtr($tpl, ['{to}' => '62000', '{message}' => 'tes', '{device_id}' => 'dev']);
            if (json_decode($probe) === null) {
                $err = 'Templat payload harus menghasilkan JSON yang sah setelah placeholder diganti.';
            }
        }
        if ($err !== null) {
            flash($err, 'err');
        } else {
            app_setting_set('wa_enabled', isset($_POST['wa_enabled']) ? '1' : '0');
            app_setting_set('wa_base_url', $baseUrl);
            app_setting_set('wa_send_path', $sendPath);
            app_setting_set('wa_device_id', $deviceId);
            // Token: kosong = pertahankan yang lama, kecuali admin menandai hapus.
            if (!empty($_POST['wa_token_clear'])) {
                app_setting_set('wa_token', '');
            } elseif ($token !== '') {
                app_setting_set('wa_token', $token);
            }
            app_setting_set('wa_payload_template', $tpl);
            flash('Pengaturan gateway WhatsApp tersimpan.');
        }
        redirect('?page=gateway');
    }
    if (isset($_POST['test'])) {
        $phone = preg_replace('/\D+/', '', $_POST['test_phone'] ?? '');
        if ($phone === '') {
            flash('Isi nomor tujuan tes dulu.', 'err');
            redirect('?page=gateway');
        }
        $msg = '[Blanko] Pesan tes gateway WhatsApp — abaikan pesan ini.';
        $r = wa_gateway_send($phone, $msg);
        $cfgNow = wa_gateway_cfg();
        $attempted = $cfgNow['enabled'] && $cfgNow['base_url'] !== '';
        notify_log((int)$u['id'], 'wa', $phone, $msg, null, !$attempted ? 'logged' : ($r['ok'] ? 'sent' : 'failed'));
        $testResult = $r + ['attempted' => $attempted];
        // Simpan hasil tes di sesi agar tampil setelah redirect.
        $_SESSION['wa_test_result'] = $testResult;
        redirect('?page=gateway');
    }
}
if (!empty($_SESSION['wa_test_result'])) {
    $testResult = $_SESSION['wa_test_result'];
    unset($_SESSION['wa_test_result']);
}

$cfg = wa_gateway_cfg();
$hasToken = $cfg['token'] !== '';
$mode = !$cfg['enabled'] ? 'Nonaktif' : ($cfg['base_url'] === '' ? 'Aktif, tapi Base URL kosong (mode log)' : 'Aktif');
$lastWa = db()->query(
    "SELECT recipient, status, created_at FROM notifications WHERE channel='wa' ORDER BY id DESC LIMIT 5"
)->fetchAll();

ob_start(); ?>
<div class="max-w-2xl mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Gateway WhatsApp</h1>
  <p class="text-sm text-slate-500 mb-5">Pengaturan pengiriman WhatsApp untuk notifikasi Blanko (dokumen baru, hasil tinjauan, pengingat laporan). Semua nilai dinamis — disimpan di database dan langsung berlaku tanpa deploy ulang.</p>

  <div class="bg-white rounded-3xl shadow-sm p-6 mb-5">
    <div class="flex items-center justify-between">
      <h2 class="font-bold text-lg tracking-tight">Status</h2>
      <span class="text-xs font-semibold px-2.5 py-1 rounded-full <?= $cfg['enabled'] && $cfg['base_url'] !== '' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500' ?>"><?= e($mode) ?></span>
    </div>
    <?php if ($cfg['base_url'] !== ''): ?>
      <p class="text-sm text-slate-500 mt-2">Tujuan kirim: <span class="font-mono text-[13px] text-slate-700"><?= e(rtrim($cfg['base_url'], '/') . '/' . ltrim($cfg['send_path'], '/')) ?></span></p>
    <?php else: ?>
      <p class="text-sm text-slate-500 mt-2">Selama Base URL kosong, notifikasi WA hanya dicatat (mode log) dan tampil di inbox penerima.</p>
    <?php endif; ?>
    <?php if ($testResult !== null): ?>
      <div class="mt-4 rounded-2xl p-4 text-sm <?= $testResult['ok'] ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800' ?>">
        <?php if (!$testResult['attempted']): ?>
          <b>Tes tidak terkirim:</b> gateway nonaktif atau Base URL kosong — pesan hanya dicatat (mode log).
        <?php elseif ($testResult['ok']): ?>
          <b>Tes terkirim.</b> Gateway menjawab HTTP <?= (int)$testResult['http'] ?>.
        <?php else: ?>
          <b>Tes gagal.</b> HTTP <?= (int)$testResult['http'] ?><?= $testResult['error'] !== '' ? ' — ' . e($testResult['error']) : '' ?>
        <?php endif; ?>
        <?php if (($testResult['response'] ?? '') !== ''): ?>
          <div class="mt-2 font-mono text-xs break-all opacity-80"><?= e(mb_substr($testResult['response'], 0, 300)) ?></div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-3xl shadow-sm p-6 mb-5">
    <h2 class="font-bold text-lg tracking-tight mb-4">Pengaturan</h2>
    <form method="post" class="space-y-4">
      <?= csrf_field() ?>
      <label class="flex items-center gap-2.5 text-sm font-medium">
        <input type="checkbox" name="wa_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?> class="w-4 h-4 accent-indigo-600">
        Aktifkan pengiriman WhatsApp
      </label>
      <div><label class="block text-sm font-medium mb-1.5">Base URL gateway</label>
        <input name="wa_base_url" value="<?= e($cfg['base_url']) ?>" placeholder="https://gateway.contoh.id" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        <p class="text-xs text-slate-400 mt-1">Alamat layanan gateway WA. Kosongkan untuk mode log.</p></div>
      <div class="grid sm:grid-cols-2 gap-4">
        <div><label class="block text-sm font-medium mb-1.5">Path kirim</label>
          <input name="wa_send_path" value="<?= e($cfg['send_path']) ?>" placeholder="/send" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
        <div><label class="block text-sm font-medium mb-1.5">Device ID</label>
          <input name="wa_device_id" value="<?= e($cfg['device_id']) ?>" placeholder="ID perangkat di gateway" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      </div>
      <div><label class="block text-sm font-medium mb-1.5">Token (Authorization: Bearer)</label>
        <input type="password" name="wa_token" value="" placeholder="<?= $hasToken ? 'Sudah terisi — kosongkan bila tidak diubah' : 'Belum ada token' ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        <?php if ($hasToken): ?>
        <label class="flex items-center gap-2 text-xs text-slate-500 mt-2"><input type="checkbox" name="wa_token_clear" value="1" class="w-3.5 h-3.5 accent-red-600">Hapus token tersimpan</label>
        <?php endif; ?>
        <p class="text-xs text-slate-400 mt-1">Dikirim sebagai header Bearer bila terisi. Kosongkan kolom untuk mempertahankan token lama.</p></div>
      <div><label class="block text-sm font-medium mb-1.5">Templat payload JSON</label>
        <textarea name="wa_payload_template" rows="4" class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-[13px] font-mono focus:ring-2 focus:ring-indigo-500 outline-none"><?= e($cfg['payload_template']) ?></textarea>
        <p class="text-xs text-slate-400 mt-1">Placeholder: <span class="font-mono">{device_id}</span>, <span class="font-mono">{to}</span>, <span class="font-mono">{message}</span> — diganti saat pengiriman. Sesuaikan dengan format API gateway yang dipakai.</p></div>
      <button name="save" value="1" class="h-12 px-8 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 active:scale-[.98] transition">
        <i class="fa-solid fa-floppy-disk mr-2"></i>Simpan Pengaturan
      </button>
    </form>
  </div>

  <div class="bg-white rounded-3xl shadow-sm p-6 mb-5">
    <h2 class="font-bold text-lg tracking-tight mb-1">Kirim Tes</h2>
    <p class="text-sm text-slate-500">Mengirim pesan tes memakai pengaturan yang <b>tersimpan</b> (simpan dulu bila baru diubah).</p>
    <form method="post" class="flex flex-col sm:flex-row gap-3 mt-4">
      <?= csrf_field() ?>
      <input name="test_phone" value="<?= e($u['phone'] ?? '') ?>" placeholder="Nomor tujuan (628…)" class="flex-1 h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      <button name="test" value="1" class="h-12 px-6 bg-slate-800 text-white rounded-2xl font-semibold hover:bg-slate-700 active:scale-[.98] transition">
        <i class="fa-brands fa-whatsapp mr-2"></i>Kirim Tes
      </button>
    </form>
  </div>

  <?php if ($lastWa): ?>
  <div class="bg-white rounded-3xl shadow-sm p-6">
    <h2 class="font-bold text-lg tracking-tight mb-3">Pengiriman WA Terakhir</h2>
    <div class="space-y-2">
      <?php foreach ($lastWa as $n): ?>
      <div class="flex items-center justify-between text-sm">
        <span class="font-mono text-[13px]"><?= e($n['recipient']) ?></span>
        <span class="text-xs text-slate-400"><?= e($n['created_at']) ?></span>
        <span class="text-xs font-semibold px-2 py-0.5 rounded-full <?= $n['status'] === 'sent' ? 'bg-emerald-100 text-emerald-700' : ($n['status'] === 'failed' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-500') ?>"><?= e($n['status']) ?></span>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php layout('Gateway WhatsApp', ob_get_clean(), $u); ?>
