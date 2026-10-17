<?php
// Upload dokumen oleh mitra + validasi + notifikasi ke penerjemah
declare(strict_types=1);
require_once __DIR__ . '/../includes/Token.php';
require_once __DIR__ . '/../includes/Notify.php';

$u = require_role('mitra');

const UPLOAD_MAX = 10 * 1024 * 1024;
const UPLOAD_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $f = $_FILES['doc'] ?? null;

    $err = null;
    if ($title === '') $err = 'Judul wajib diisi.';
    elseif (!$f || $f['error'] !== UPLOAD_ERR_OK) $err = 'File gagal diupload.';
    elseif ($f['size'] > UPLOAD_MAX) $err = 'Ukuran maksimal 10 MB.';
    else {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($f['tmp_name']);
        if (!isset(UPLOAD_MIME[$mime])) $err = 'Format harus PDF, JPG, atau PNG.';
    }

    if ($err) {
        flash($err, 'err');
    } else {
        $ext = UPLOAD_MIME[$mime];
        $stored = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir = '/var/www/data/uploads';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $dest = $dir . '/' . $stored;
        if (!move_uploaded_file($f['tmp_name'], $dest)) {
            flash('Gagal menyimpan file.', 'err');
        } else {
            $sha = hash_file('sha256', $dest);
            $st = db()->prepare(
                "INSERT INTO documents (user_id, title, original_name, stored_name, mime, size, sha256)
                 VALUES (?,?,?,?,?,?,?)"
            );
            $st->execute([$u['id'], $title, $f['name'], $stored, $mime, $f['size'], $sha]);
            $docId = (int)db()->lastInsertId();

            // token approve/decline + notifikasi ke semua penerjemah
            $tokA = token_issue($docId, 'approve');
            $tokD = token_issue($docId, 'decline');
            $prev = app_url('?page=preview&id=' . $docId);

            $ps = db()->query("SELECT * FROM users WHERE role = 'penerjemah'")->fetchAll();
            foreach ($ps as $p) {
                $msg = "Blanko: Permintaan pengesahan dokumen.\n"
                     . "Mitra: {$u['name']}\nJudul: {$title}\n\n"
                     . "Preview: {$prev}\n"
                     . "Setujui: " . action_url($tokA) . "\n"
                     . "Tolak: " . action_url($tokD);
                if ($p['phone']) notify_wa((int)$p['id'], $p['phone'], $msg);
                if ($p['email']) notify_email((int)$p['id'], $p['email'],
                    'Permintaan pengesahan dokumen — Blanko', $msg);
                notify_inbox((int)$p['id'], 'Permintaan pengesahan: ' . $title, $msg);
            }
            flash('Dokumen diupload. Penerjemah tersumpah telah dinotifikasi via WA & email.');
            redirect('?page=dashboard');
        }
    }
}

ob_start(); ?>
<div class="max-w-lg mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Upload Dokumen</h1>
  <p class="text-sm text-slate-500 mb-5">Dokumen akan dikirim ke penerjemah tersumpah untuk pengesahan.</p>
  <form method="post" enctype="multipart/form-data" class="bg-white rounded-3xl shadow-sm p-6 space-y-5">
    <?= csrf_field() ?>
    <div>
      <label class="block text-sm font-medium mb-1.5">Judul dokumen</label>
      <input type="text" name="title" required maxlength="200"
             class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="cth: Terjemahan Akta Kelahiran">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1.5">File (PDF/JPG/PNG, maks 10 MB)</label>
      <label class="block border-2 border-dashed border-slate-200 rounded-2xl p-6 text-center cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/50 transition">
        <i class="fa-solid fa-cloud-arrow-up text-3xl text-indigo-400 mb-2"></i>
        <div class="text-sm font-medium text-slate-700">Ketuk untuk pilih file</div>
        <div class="text-xs text-slate-400 mt-1" id="file-label">Belum ada file dipilih</div>
        <input type="file" name="doc" required accept=".pdf,.jpg,.jpeg,.png" class="hidden" onchange="document.getElementById('file-label').textContent = this.files[0]?.name || 'Belum ada file dipilih'">
      </label>
    </div>
    <button class="w-full h-12 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25 active:scale-[.98] transition">
      <i class="fa-solid fa-upload mr-2"></i>Upload & Minta Pengesahan
    </button>
  </form>
</div>
<?php layout('Upload', ob_get_clean(), $u); ?>
