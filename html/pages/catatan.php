<?php
// Catatan pencocokan oleh mitra (F2): pekerjaan yang dikerjakan mitra di
// luar Blanko, dicatat sebatas bahan cocok data bagi penerjemahnya.
// Tersimpan langsung, TANPA persetujuan, dan tidak masuk rekap resmi.
declare(strict_types=1);
require_once __DIR__ . '/../includes/Rekap.php';

$u = require_role('mitra');
$db = db();

$pens = $db->query(
    "SELECT id, name, lang_pair, lang_reverse FROM users WHERE role = 'penerjemah' AND status = 'active' ORDER BY name"
)->fetchAll();
$penById = [];
foreach ($pens as $p) $penById[(int)$p['id']] = $p;
$fixedPen = null;
if (!empty($u['penerjemah_id']) && isset($penById[(int)$u['penerjemah_id']])) {
    $fixedPen = $penById[(int)$u['penerjemah_id']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';

    if ($do === 'save') {
        $penId = $fixedPen ? (int)$fixedPen['id'] : (int)($_POST['penerjemah_id'] ?? 0);
        $tanggal = rekap_parse_tanggal(trim((string)($_POST['tanggal'] ?? '')));
        $judul = trim((string)($_POST['judul'] ?? ''));
        $jenis = rekap_jenis_clean($_POST['jenis_dokumen'] ?? '');
        $arah = mb_substr(trim((string)($_POST['arah_bahasa'] ?? '')), 0, 120);
        $hal = trim((string)($_POST['halaman'] ?? ''));
        $halaman = $hal === '' ? null : max(0, (int)$hal);
        [$kTipe, $kVal] = rekap_klien_from_post($_POST);
        $catatan = mb_substr(trim((string)($_POST['catatan'] ?? '')), 0, 500);
        $id = (int)($_POST['entry_id'] ?? 0);
        if (!isset($penById[$penId])) {
            flash('Penerjemah tujuan tidak valid.', 'err');
        } elseif ($tanggal === null) {
            flash('Tanggal pekerjaan wajib diisi dengan benar.', 'err');
        } elseif ($judul === '') {
            flash('Judul pekerjaan wajib diisi.', 'err');
        } elseif ($id > 0) {
            $db->prepare(
                "UPDATE work_entries SET penerjemah_id=?, tanggal=?, judul=?, jenis_dokumen=?, arah_bahasa=?, halaman=?, klien_tipe=?, klien=?, catatan=?, updated_at=datetime('now')
                 WHERE id=? AND created_by=? AND jenis_catatan='pencocokan'"
            )->execute([$penId, $tanggal, $judul, $jenis, $arah, $halaman, $kTipe, $kVal, $catatan, $id, $u['id']]);
            flash('Catatan pencocokan diperbarui.');
        } else {
            $db->prepare(
                "INSERT INTO work_entries (penerjemah_id, created_by, jenis_catatan, tanggal, judul, jenis_dokumen, arah_bahasa, halaman, klien_tipe, klien, catatan)
                 VALUES (?,?, 'pencocokan', ?,?,?,?,?,?,?,?)"
            )->execute([$penId, $u['id'], $tanggal, $judul, $jenis, $arah, $halaman, $kTipe, $kVal, $catatan]);
            flash('Catatan pencocokan tersimpan. Catatan ini langsung terlihat oleh penerjemah sebagai bahan cocok data.');
        }
        redirect('?page=catatan');
    }

    if ($do === 'delete') {
        $id = (int)($_POST['entry_id'] ?? 0);
        $db->prepare("DELETE FROM work_entries WHERE id=? AND created_by=? AND jenis_catatan='pencocokan'")
            ->execute([$id, $u['id']]);
        flash('Catatan pencocokan dihapus.');
        redirect('?page=catatan');
    }
}

$st = $db->prepare(
    "SELECT w.*, p.name AS penerjemah_name
     FROM work_entries w JOIN users p ON p.id = w.penerjemah_id
     WHERE w.created_by = ? AND w.jenis_catatan = 'pencocokan'
     ORDER BY w.tanggal DESC, w.id DESC"
);
$st->execute([$u['id']]);
$notes = $st->fetchAll();

$editNote = null;
if (!empty($_GET['edit'])) {
    $q = $db->prepare("SELECT * FROM work_entries WHERE id=? AND created_by=? AND jenis_catatan='pencocokan'");
    $q->execute([(int)$_GET['edit'], $u['id']]);
    $editNote = $q->fetch() ?: null;
}

ob_start(); ?>
<div class="max-w-3xl mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Catatan Pencocokan</h1>
  <p class="text-sm text-slate-500 mb-5">Catat pekerjaan yang Anda kerjakan untuk penerjemah <b>di luar Blanko</b>. Catatan ini sebatas bahan pencocokan data bagi penerjemah: langsung tersimpan tanpa menunggu persetujuan, dan tidak masuk hitungan rekap resmi penerjemah.</p>

  <div class="bg-white rounded-3xl shadow-sm p-6 mb-5">
    <h2 class="font-bold mb-4"><?= $editNote ? 'Ubah catatan' : 'Tambah catatan' ?></h2>
    <form method="post" class="grid sm:grid-cols-2 gap-4" x-data="{mode:'<?= e($editNote['klien_tipe'] ?? 'kategori') ?>'}">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="entry_id" value="<?= (int)($editNote['id'] ?? 0) ?>">
      <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1.5">Penerjemah tujuan</label>
        <?php if ($fixedPen): ?>
        <input type="hidden" name="penerjemah_id" value="<?= (int)$fixedPen['id'] ?>">
        <div class="h-12 bg-slate-100 rounded-2xl px-4 flex items-center text-[15px] text-slate-600"><?= e($fixedPen['name']) ?></div>
        <?php else: ?>
        <select name="penerjemah_id" required class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
          <option value="">— pilih penerjemah —</option>
          <?php foreach ($pens as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)($editNote['penerjemah_id'] ?? 0) === (int)$p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <div><label class="block text-sm font-medium mb-1.5">Tanggal selesai</label>
        <input type="date" name="tanggal" required value="<?= e($editNote['tanggal'] ?? date('Y-m-d')) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Judul pekerjaan</label>
        <input type="text" name="judul" required maxlength="200" value="<?= e($editNote['judul'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="cth: Terjemahan akta kelahiran (offline)"></div>
      <div><label class="block text-sm font-medium mb-1.5">Jenis dokumen</label>
        <input type="text" name="jenis_dokumen" list="ct-jenis-list" maxlength="100" value="<?= e($editNote['jenis_dokumen'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="— pilih atau ketik —">
        <datalist id="ct-jenis-list">
          <?php foreach (REKAP_JENIS as $j): ?>
          <option value="<?= e($j) ?>"></option>
          <?php endforeach; ?>
        </datalist></div>
      <div><label class="block text-sm font-medium mb-1.5">Arah bahasa</label>
        <input type="text" name="arah_bahasa" maxlength="120" value="<?= e($editNote['arah_bahasa'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="cth: Bahasa Indonesia ke Bahasa Inggris"></div>
      <div><label class="block text-sm font-medium mb-1.5">Halaman hasil</label>
        <input type="number" name="halaman" min="0" value="<?= isset($editNote['halaman']) && $editNote['halaman'] !== null ? (int)$editNote['halaman'] : '' ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div>
        <label class="block text-sm font-medium mb-1.5">Klien</label>
        <div class="flex gap-4 mb-2 text-sm">
          <label class="flex items-center gap-1.5"><input type="radio" name="klien_tipe" value="kategori" <?= ($editNote['klien_tipe'] ?? 'kategori') !== 'nama' ? 'checked' : '' ?> @click="mode='kategori'" class="w-4 h-4 accent-indigo-600">Kategori</label>
          <label class="flex items-center gap-1.5"><input type="radio" name="klien_tipe" value="nama" <?= ($editNote['klien_tipe'] ?? '') === 'nama' ? 'checked' : '' ?> @click="mode='nama'" class="w-4 h-4 accent-indigo-600">Nama bebas</label>
        </div>
        <select name="klien_kategori" x-show="mode==='kategori'" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
          <option value="">— pilih kategori —</option>
          <?php foreach (REKAP_KATEGORI as $k): ?>
          <option value="<?= e($k) ?>" <?= ($editNote['klien_tipe'] ?? '') === 'kategori' && ($editNote['klien'] ?? '') === $k ? 'selected' : '' ?>><?= e($k) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="klien_nama" x-show="mode==='nama'" maxlength="150" placeholder="Nama klien"
               value="<?= ($editNote['klien_tipe'] ?? '') === 'nama' ? e($editNote['klien'] ?? '') : '' ?>"
               class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1.5">Catatan <span class="text-slate-400 font-normal">(opsional)</span></label>
        <input type="text" name="catatan" maxlength="500" value="<?= e($editNote['catatan'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div class="sm:col-span-2 flex gap-2">
        <button class="h-12 px-6 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500"><?= $editNote ? 'Simpan Perubahan' : 'Simpan Catatan' ?></button>
        <?php if ($editNote): ?>
        <a href="?page=catatan" class="h-12 px-5 rounded-2xl bg-slate-100 text-slate-600 font-medium flex items-center">Batal</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-3xl shadow-sm p-6">
    <h2 class="font-bold mb-4">Catatan saya</h2>
    <?php if (!$notes): ?>
    <p class="text-sm text-slate-400">Belum ada catatan pencocokan.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-slate-400 border-b">
        <th class="py-2 pr-3 font-medium">Tanggal</th><th class="py-2 pr-3 font-medium">Judul</th>
        <th class="py-2 pr-3 font-medium">Jenis</th><th class="py-2 pr-3 font-medium text-right">Hal.</th>
        <th class="py-2 pr-3 font-medium">Klien</th><th class="py-2 pr-3 font-medium">Untuk</th><th class="py-2 font-medium"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($notes as $w): ?>
      <tr class="border-b border-slate-50 last:border-0 align-top">
        <td class="py-2 pr-3 whitespace-nowrap"><?= e(rekap_fmt_tanggal($w['tanggal'])) ?></td>
        <td class="py-2 pr-3"><?= e($w['judul']) ?><?php if (!empty($w['catatan'])): ?><div class="text-xs text-slate-400"><?= e($w['catatan']) ?></div><?php endif; ?></td>
        <td class="py-2 pr-3"><?= e($w['jenis_dokumen'] ?: '-') ?></td>
        <td class="py-2 pr-3 text-right"><?= $w['halaman'] !== null ? (int)$w['halaman'] : '-' ?></td>
        <td class="py-2 pr-3"><?= e(rekap_klien_label($w['klien_tipe'], $w['klien'])) ?></td>
        <td class="py-2 pr-3"><?= e($w['penerjemah_name']) ?></td>
        <td class="py-2 whitespace-nowrap text-right">
          <a href="?page=catatan&edit=<?= (int)$w['id'] ?>" class="text-indigo-600 text-xs font-medium">Ubah</a>
          <form method="post" class="inline" onsubmit="return confirm('Hapus catatan ini?')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="delete">
            <input type="hidden" name="entry_id" value="<?= (int)$w['id'] ?>">
            <button class="text-red-500 text-xs font-medium ml-2">Hapus</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php layout('Catatan Pencocokan', ob_get_clean(), $u); ?>
