<?php
// Upload dokumen oleh mitra + validasi + notifikasi ke penerjemah
declare(strict_types=1);
require_once __DIR__ . '/../includes/Token.php';
require_once __DIR__ . '/../includes/Notify.php';
require_once __DIR__ . '/../includes/Rekap.php';
require_once __DIR__ . '/../includes/ratelimit.php';

$u = require_role('mitra');

const UPLOAD_MAX = 10 * 1024 * 1024;
const UPLOAD_PER_JAM = 30;
const UPLOAD_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
];

// DOCX adalah ZIP OOXML: tanda tangan PK + dua berkas wajib di dalamnya
// (nama berkas tampil apa adanya di direktori pusat ZIP).
function upload_is_docx(string $tmp): bool
{
    if ((string)file_get_contents($tmp, false, null, 0, 4) !== "PK\x03\x04") {
        return false;
    }
    $raw = (string)file_get_contents($tmp);
    return str_contains($raw, '[Content_Types].xml')
        && str_contains($raw, 'word/document.xml');
}

// Periksa isi berkas sesuai format terdeteksi — lapisan kedua di atas
// finfo agar berkas samaran (isi lain, nama/MIME dipalsukan) tertolak.
function upload_content_matches(string $tmp, string $mime): bool
{
    switch ($mime) {
        case 'application/pdf':
            return str_starts_with((string)file_get_contents($tmp, false, null, 0, 8), '%PDF-');
        case 'image/jpeg':
            $im = @getimagesize($tmp);
            return $im !== false && $im[2] === IMAGETYPE_JPEG;
        case 'image/png':
            $im = @getimagesize($tmp);
            return $im !== false && $im[2] === IMAGETYPE_PNG;
        case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
            return upload_is_docx($tmp);
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $title = trim($_POST['title'] ?? '');
    $jenisDok = rekap_jenis_clean($_POST['jenis_dokumen'] ?? '');
    [$klienTipe, $klienVal] = rekap_klien_from_post($_POST);
    $f = $_FILES['doc'] ?? null;

    $err = null;
    $mime = '';
    $realSize = 0;
    if (rl_uploads_last_hour((int)$u['id']) >= UPLOAD_PER_JAM) {
        $err = 'Terlalu banyak upload dalam satu jam terakhir. Coba lagi nanti.';
    } elseif ($title === '') {
        $err = 'Judul wajib diisi.';
    } elseif (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
        $err = 'Pilih file dokumen dulu.';
    } elseif ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        $err = 'Ukuran file melebihi batas 10 MB.';
    } elseif ($f['error'] !== UPLOAD_ERR_OK) {
        $err = 'File gagal diupload.';
    } else {
        $realSize = (int)(@filesize($f['tmp_name']) ?: 0);
        if ($realSize <= 0) {
            $err = 'File kosong atau gagal terbaca.';
        } elseif ($realSize > UPLOAD_MAX) {
            $err = 'Ukuran maksimal 10 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = (string)$finfo->file($f['tmp_name']);
            // finfo kadang melaporkan DOCX hanya sebagai application/zip.
            if ($mime === 'application/zip' && upload_is_docx($f['tmp_name'])) {
                $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            }
            if (!isset(UPLOAD_MIME[$mime])) {
                $err = 'Format harus PDF, DOCX, JPG, atau PNG.';
            } elseif (!upload_content_matches($f['tmp_name'], $mime)) {
                $err = 'Isi file tidak sesuai formatnya. Unggah berkas asli tanpa diubah namanya.';
            } else {
                $extOrig = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
                $families = ['pdf' => ['pdf'], 'jpg' => ['jpg', 'jpeg'], 'png' => ['png'], 'docx' => ['docx']];
                if (!in_array($extOrig, $families[UPLOAD_MIME[$mime]], true)) {
                    $err = 'Ekstensi file tidak sesuai isinya. Unggah berkas dengan nama aslinya.';
                }
            }
        }
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
                "INSERT INTO documents (user_id, title, original_name, stored_name, mime, size, sha256, jenis_dokumen, klien_tipe, klien)
                 VALUES (?,?,?,?,?,?,?,?,?,?)"
            );
            $st->execute([$u['id'], $title, $f['name'], $stored, $mime, $realSize, $sha, $jenisDok, $klienTipe, $klienVal]);
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
      <label class="block text-sm font-medium mb-1.5">Jenis dokumen <span class="text-slate-400 font-normal">(untuk rekap tahunan penerjemah)</span></label>
      <select name="jenis_dokumen" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        <option value="">— pilih jenis dokumen —</option>
        <?php foreach (REKAP_JENIS as $j): ?>
        <option value="<?= e($j) ?>"><?= e($j) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div x-data="{mode:'kategori'}">
      <label class="block text-sm font-medium mb-1.5">Klien <span class="text-slate-400 font-normal">(opsional)</span></label>
      <div class="flex gap-4 mb-2 text-sm">
        <label class="flex items-center gap-1.5"><input type="radio" name="klien_tipe" value="kategori" checked @click="mode='kategori'" class="w-4 h-4 accent-indigo-600">Kategori</label>
        <label class="flex items-center gap-1.5"><input type="radio" name="klien_tipe" value="nama" @click="mode='nama'" class="w-4 h-4 accent-indigo-600">Nama bebas</label>
      </div>
      <select name="klien_kategori" x-show="mode==='kategori'" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        <option value="">— pilih kategori klien —</option>
        <?php foreach (REKAP_KATEGORI as $k): ?>
        <option value="<?= e($k) ?>"><?= e($k) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="klien_nama" x-show="mode==='nama'" maxlength="150" placeholder="Nama klien"
             class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
    </div>
    <div>
      <label class="block text-sm font-medium mb-1.5">File (PDF/DOCX/JPG/PNG, maks 10 MB)</label>
      <label class="block border-2 border-dashed border-slate-200 rounded-2xl p-6 text-center cursor-pointer hover:border-indigo-400 hover:bg-indigo-50/50 transition">
        <i class="fa-solid fa-cloud-arrow-up text-3xl text-indigo-400 mb-2"></i>
        <div class="text-sm font-medium text-slate-700">Ketuk untuk pilih file</div>
        <div class="text-xs text-slate-400 mt-1" id="file-label">Belum ada file dipilih</div>
        <input type="file" name="doc" required accept=".pdf,.docx,.jpg,.jpeg,.png" class="hidden" onchange="document.getElementById('file-label').textContent = this.files[0]?.name || 'Belum ada file dipilih'">
      </label>
    </div>
    <button class="w-full h-12 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25 active:scale-[.98] transition">
      <i class="fa-solid fa-upload mr-2"></i>Upload & Minta Pengesahan
    </button>
  </form>
</div>
<?php layout('Upload', ob_get_clean(), $u); ?>
