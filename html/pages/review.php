<?php
// Review: approve / decline via signed token (dari WA/email) atau sesi penerjemah.
declare(strict_types=1);
require_once __DIR__ . '/../includes/Token.php';
require_once __DIR__ . '/../includes/Notify.php';
require_once __DIR__ . '/../includes/Attestation.php';

$tokenRow = null;
if (!empty($_GET['token'])) {
    $tokenRow = token_verify($_GET['token']);
    if (!$tokenRow) { http_response_code(403); exit('Link tidak valid atau kedaluwarsa.'); }
}

$docId = $tokenRow ? (int)$tokenRow['document_id'] : (int)($_GET['id'] ?? 0);
$action = $tokenRow ? $tokenRow['action'] : ($_GET['do'] ?? '');

$u = current_user();
$isPenerjemah = $u && $u['role'] === 'penerjemah';
if (!$tokenRow && !$isPenerjemah) {
    redirect('?page=login');
}

$st = db()->prepare(
    "SELECT d.*, u.name AS mitra_name, u.email AS mitra_email, u.phone AS mitra_phone, u.id AS mitra_id
     FROM documents d JOIN users u ON u.id = d.user_id WHERE d.id = ?"
);
$st->execute([$docId]);
$d = $st->fetch();
if (!$d) { http_response_code(404); exit('Dokumen tidak ditemukan.'); }

// Proses submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $reason = trim($_POST['reason'] ?? '');
    if (!in_array($do, ['approve', 'decline'], true)) { flash('Aksi tidak valid.', 'err'); }
    elseif ($d['status'] !== 'pending') { flash('Dokumen sudah diproses sebelumnya.', 'err'); }
    elseif ($do === 'decline' && $reason === '') { flash('Alasan penolakan wajib diisi.', 'err'); }
    else {
        $reviewer = $isPenerjemah ? (int)$u['id']
            : (int)(db()->query("SELECT id FROM users WHERE role='penerjemah' LIMIT 1")->fetchColumn());
        $stampedName = null;

        if ($do === 'approve') {            // profil penerjemah untuk blanko
            $pr = db()->prepare('SELECT * FROM users WHERE id = ?');
            $pr->execute([$reviewer]);
            $prov = $pr->fetch();
            $stampImg = '/var/www/data/stamp-assets/cap-ttd.jpg';
            if ($prov['stamp_file'] && file_exists('/var/www/data/stamps/' . $prov['stamp_file']))
                $stampImg = '/var/www/data/stamps/' . $prov['stamp_file'];

            // no. registrasi: YYYY-ROMAN-RDW-xxx
            $mitraShort = strtoupper(preg_replace('/[^A-Z]/i', '', explode(' ', $d['mitra_name'])[0] ?? 'XX'));
            $regNo = reg_number($docId, substr($mitraShort, 0, 3), 'TRJ');
            $bln = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            $dateId = date('d') . ' ' . $bln[(int)date('n') - 1] . ' ' . date('Y');

            $prof = [
                'name' => $prov['name'] ?: 'Penerjemah Tersumpah',
                'ar_name' => $prov['name_ar'] ?: '',
                'sk' => $prov['sk'] ?: '',
                'sk_date' => $prov['sk_date'] ?? '',
                'address' => $prov['address'] ?: '',
                'city' => $prov['city'] ?: '',
                'phone' => $prov['phone'] ?: '',
                'email' => $prov['email'] ?: '',
                'lang_pair' => $prov['lang_pair'] ?: '',
            ];
            $dyn = ['date_id' => $dateId, 'reg_no' => $regNo, 'doc_title' => $d['title']];

            $src = '/var/www/data/uploads/' . $d['stored_name'];
            $blankoPages = $_POST['blanko_pages'] ?? 'last';
            if (!in_array($blankoPages, ['first', 'last', 'all'], true)) $blankoPages = 'last';
            // template blanko
            $tplCfg = [];
            $tplId = (int)($_POST['template_id'] ?? 0);
            if ($tplId > 0) {
                $ts = db()->prepare('SELECT config FROM blanko_templates WHERE id = ?');
                $ts->execute([$tplId]);
                if ($row = $ts->fetch()) $tplCfg = json_decode($row['config'] ?? '{}', true) ?: [];
            } else {
                $ts = db()->query("SELECT config FROM blanko_templates WHERE is_default = 1 LIMIT 1");
                if ($row = $ts->fetch()) $tplCfg = json_decode($row['config'] ?? '{}', true) ?: [];
            }
            $final = blanko_append($src, $d['mime'], $prof, $dyn, $stampImg, $blankoPages, $tplCfg);
            if ($final === '') { flash('Gagal membubuhkan blanko.', 'err'); }
            else {
                $stampedName = basename($final);
                $up = db()->prepare(
                    "UPDATE documents SET status='approved', reviewed_by=?, reviewed_at=datetime('now'), stamped_name=?, template_id=? WHERE id=?"
                );
                $up->execute([$reviewer, $stampedName, $tplId ?: null, $docId]);
                $msg = "Blanko: Dokumen disetujui.\nJudul: {$d['title']}\n"
                     . "Unduh: " . app_url('?page=download&id=' . $docId);
                if ($d['mitra_phone']) notify_wa((int)$d['mitra_id'], $d['mitra_phone'], $msg);
                notify_email((int)$d['mitra_id'], $d['mitra_email'], 'Dokumen disetujui — Blanko', $msg);
                notify_inbox((int)$d['mitra_id'], 'Dokumen disetujui: ' . $d['title'], $msg);
                flash('Dokumen disetujui dan blanko pengesahan telah dibubuhkan di halaman terakhir.');
            }
        } else {
            $up = db()->prepare(
                "UPDATE documents SET status='declined', decline_reason=?, reviewed_by=?, reviewed_at=datetime('now') WHERE id=?"
            );
            $up->execute([$reason, $reviewer, $docId]);
            $msg = "Blanko: Dokumen DITOLAK.\nJudul: {$d['title']}\nAlasan: {$reason}";
            if ($d['mitra_phone']) notify_wa((int)$d['mitra_id'], $d['mitra_phone'], $msg);
            notify_email((int)$d['mitra_id'], $d['mitra_email'], 'Dokumen ditolak — Blanko', $msg);
            notify_inbox((int)$d['mitra_id'], 'Dokumen ditolak: ' . $d['title'], $msg);
            flash('Dokumen ditolak. Mitra telah dinotifikasi beserta alasannya.');
        }
        if ($tokenRow) token_consume((int)$tokenRow['id']);
        redirect($isPenerjemah ? '?page=dashboard' : '?page=login');
    }
}

$fileUrl = '?page=file&id=' . $docId;
$isPdf = str_contains($d['mime'], 'pdf');
$showForm = $d['status'] === 'pending';

// hitung jumlah halaman (untuk opsi blanko multi-halaman)
$npages = 1;
if ($showForm && $isPdf) {
    $srcPath = '/var/www/data/uploads/' . $d['stored_name'];
    $qpdf = trim((string)shell_exec('which qpdf 2>/dev/null'));
    if ($qpdf !== '' && is_file($srcPath)) {
        $npages = (int)trim((string)shell_exec(
            escapeshellcmd($qpdf) . ' --show-npages ' . escapeshellarg($srcPath) . ' 2>/dev/null'));
        if ($npages < 1) $npages = 1;
    }
}
$showPageOpt = $showForm && $isPdf && $npages > 1;

ob_start(); ?>
<div class="max-w-3xl mx-auto">
  <a href="?page=dashboard" class="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700 mb-4">
    <i class="fa-solid fa-arrow-left"></i>Kembali
  </a>
  <div class="bg-white rounded-3xl shadow-sm p-5 sm:p-6">
    <h1 class="text-xl font-bold tracking-tight">Review Dokumen</h1>
    <p class="text-sm text-slate-500 mt-1 mb-4"><?= e($d['title']) ?> · Mitra: <?= e($d['mitra_name']) ?></p>

    <div class="rounded-2xl overflow-hidden bg-slate-100 border border-slate-100 mb-5">
      <?php if ($isPdf): ?>
        <iframe src="<?= e($fileUrl) ?>" class="w-full" style="height:55vh" loading="lazy" title="Preview"></iframe>
      <?php else: ?>
        <img src="<?= e($fileUrl) ?>" alt="Preview" loading="lazy" class="w-full h-auto">
      <?php endif; ?>
    </div>

    <?php if ($showForm): ?>
    <div x-data="{ tab: '<?= e($action === 'decline' ? 'decline' : 'approve') ?>' }">
      <div class="grid grid-cols-2 gap-2 p-1 bg-slate-100 rounded-2xl mb-4">
        <button type="button" @click="tab='approve'"
                :class="tab==='approve' ? 'bg-white shadow-sm text-emerald-700' : 'text-slate-500'"
                class="h-11 rounded-xl text-sm font-semibold transition">Setujui</button>
        <button type="button" @click="tab='decline'"
                :class="tab==='decline' ? 'bg-white shadow-sm text-red-700' : 'text-slate-500'"
                class="h-11 rounded-xl text-sm font-semibold transition">Tolak</button>
      </div>
      <form method="post" x-show="tab==='approve'" class="space-y-4">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="approve">
        <p class="text-sm text-slate-600 bg-emerald-50 rounded-2xl px-4 py-3">Blanko pernyataan penerjemah tersumpah (bilingual + cap + ttd) akan ditempel di dokumen, lalu mitra dinotifikasi.</p>
        <?php if ($showPageOpt): ?>
        <div class="text-sm bg-slate-50 rounded-2xl p-4">
          <div class="font-semibold mb-2">Dokumen <?= $npages ?> halaman — beri blanko di:</div>
          <div class="space-y-2">
            <label class="flex items-center gap-2.5"><input type="radio" name="blanko_pages" value="first" class="w-4 h-4 accent-indigo-600">Halaman 1 saja</label>
            <label class="flex items-center gap-2.5"><input type="radio" name="blanko_pages" value="last" checked class="w-4 h-4 accent-indigo-600">Halaman terakhir saja</label>
            <label class="flex items-center gap-2.5"><input type="radio" name="blanko_pages" value="all" class="w-4 h-4 accent-indigo-600">Semua halaman</label>
          </div>
        </div>
        <?php endif; ?>
        <?php
        $tpls = db()->query('SELECT id, name, is_default FROM blanko_templates ORDER BY is_default DESC, id')->fetchAll();
        if (count($tpls) > 1):
        ?>
        <div>
          <label class="block text-sm font-medium mb-1.5">Template blanko</label>
          <select name="template_id" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
            <?php foreach ($tpls as $tp): ?>
              <option value="<?= (int)$tp['id'] ?>" <?= $tp['is_default'] ? 'selected' : '' ?>><?= e($tp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <button class="w-full h-12 bg-emerald-600 text-white rounded-2xl font-semibold hover:bg-emerald-500 shadow-lg shadow-emerald-600/25 active:scale-[.98] transition">
          <i class="fa-solid fa-check mr-2"></i>Approve & Bubuhkan Blanko
        </button>
      </form>
      <form method="post" x-show="tab==='decline'" class="space-y-4" style="display:none">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="decline">
        <div>
          <label class="block text-sm font-medium mb-1.5">Alasan penolakan (wajib)</label>
          <textarea name="reason" required rows="3" maxlength="1000"
                    class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-[15px] focus:ring-2 focus:ring-red-500 outline-none" placeholder="Tulis alasan penolakan..."></textarea>
        </div>
        <button class="w-full h-12 bg-red-600 text-white rounded-2xl font-semibold hover:bg-red-500 shadow-lg shadow-red-600/25 active:scale-[.98] transition">
          <i class="fa-solid fa-xmark mr-2"></i>Tolak Dokumen
        </button>
      </form>
    </div>
    <?php elseif ($d['status'] === 'approved'): ?>
      <div class="p-4 bg-emerald-50 rounded-2xl text-sm text-emerald-700">Dokumen ini sudah disetujui.</div>
    <?php else: ?>
      <div class="p-4 bg-red-50 rounded-2xl text-sm text-red-700">Dokumen ini ditolak. Alasan: <?= e($d['decline_reason'] ?? '-') ?></div>
    <?php endif; ?>
  </div>
</div>
<?php layout('Review', ob_get_clean(), $u); ?>
