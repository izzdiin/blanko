<?php
// Rekap Tahunan Siap Lapor — panel penerjemah (F2 entri sendiri, F3 panel,
// F6 aktivasi periode). Catatan mitra berjenis 'pencocokan' tampil di blok
// terpisah dan TIDAK terhitung di ringkasan maupun ekspor.
declare(strict_types=1);
require_once __DIR__ . '/../includes/Rekap.php';

$u = require_role('penerjemah');
$db = db();

$st = $db->prepare('SELECT * FROM users WHERE id = ?');
$st->execute([$u['id']]);
$prof = $st->fetch();

$arahDefault = lang_pair_display($prof['lang_pair'] ?? '', $prof['lang_reverse'] ?? 0);
$targetLang = lang_pair_target($prof['lang_pair'] ?? '');
$arahOpsi = [];
if (trim((string)($prof['lang_pair'] ?? '')) !== '') $arahOpsi[] = $prof['lang_pair'];
if (!empty($prof['lang_reverse']) && $targetLang !== '') $arahOpsi[] = 'Bahasa ' . $targetLang . ' ke Bahasa Indonesia';

$backParams = [];
if (!empty($_GET['view'])) $backParams['view'] = $_GET['view'] === 'kalender' ? 'kalender' : 'lapor';
if (!empty($_GET['year'])) $backParams['year'] = (int)$_GET['year'];
$backUrl = '?page=rekap' . ($backParams ? '&' . http_build_query($backParams) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $ret = ($_POST['return'] ?? '') === 'settings' ? '?page=settings' : $backUrl;

    if ($do === 'period_save') {
        $aktif = !empty($_POST['periode_aktif']);
        $mulai = rekap_parse_tanggal(trim((string)($_POST['periode_mulai'] ?? '')));
        $selesai = rekap_parse_tanggal(trim((string)($_POST['periode_selesai'] ?? '')));
        if ($aktif && ($mulai === null || $selesai === null)) {
            flash('Tanggal mulai dan selesai periode wajib diisi dengan benar untuk mengaktifkan Tahun Lapor.', 'err');
        } elseif ($aktif && $mulai > $selesai) {
            flash('Tanggal mulai periode tidak boleh setelah tanggal selesai.', 'err');
        } else {
            rekap_save_period($db, (int)$u['id'], $aktif, $mulai, $selesai);
            flash($aktif ? 'Tahun Lapor aktif. Tenggat mengikuti tanggal selesai periode.' : 'Pengaturan Tahun Lapor tersimpan.');
        }
        redirect($ret);
    }

    if ($do === 'entry_save') {
        $id = (int)($_POST['entry_id'] ?? 0);
        $tanggal = rekap_parse_tanggal(trim((string)($_POST['tanggal'] ?? '')));
        $judul = trim((string)($_POST['judul'] ?? ''));
        $jenis = rekap_jenis_clean($_POST['jenis_dokumen'] ?? '');
        $arah = mb_substr(trim((string)($_POST['arah_bahasa'] ?? '')), 0, 120);
        $hal = trim((string)($_POST['halaman'] ?? ''));
        $halaman = $hal === '' ? null : max(0, (int)$hal);
        [$kTipe, $kVal] = rekap_klien_from_post($_POST);
        $catatan = mb_substr(trim((string)($_POST['catatan'] ?? '')), 0, 500);
        if ($tanggal === null) flash('Tanggal pekerjaan wajib diisi dengan benar.', 'err');
        elseif ($judul === '') flash('Judul pekerjaan wajib diisi.', 'err');
        elseif ($id > 0) {
            $db->prepare(
                "UPDATE work_entries SET tanggal=?, judul=?, jenis_dokumen=?, arah_bahasa=?, halaman=?, klien_tipe=?, klien=?, catatan=?, updated_at=datetime('now')
                 WHERE id=? AND penerjemah_id=? AND jenis_catatan='rekap'"
            )->execute([$tanggal, $judul, $jenis, $arah, $halaman, $kTipe, $kVal, $catatan, $id, $u['id']]);
            flash('Entri rekap diperbarui.');
        } else {
            $db->prepare(
                "INSERT INTO work_entries (penerjemah_id, created_by, jenis_catatan, tanggal, judul, jenis_dokumen, arah_bahasa, halaman, klien_tipe, klien, catatan)
                 VALUES (?,?, 'rekap', ?,?,?,?,?,?,?,?)"
            )->execute([$u['id'], $u['id'], $tanggal, $judul, $jenis, $arah, $halaman, $kTipe, $kVal, $catatan]);
            flash('Entri rekap ditambahkan.');
        }
        redirect($backUrl);
    }

    if ($do === 'entry_delete') {
        $id = (int)($_POST['entry_id'] ?? 0);
        $db->prepare("DELETE FROM work_entries WHERE id=? AND penerjemah_id=? AND jenis_catatan='rekap'")
            ->execute([$id, $u['id']]);
        flash('Entri rekap dihapus.');
        redirect($backUrl);
    }

    if ($do === 'doc_save') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $jenis = rekap_jenis_clean($_POST['jenis_dokumen'] ?? '');
        $hal = trim((string)($_POST['halaman'] ?? ''));
        $halaman = $hal === '' ? null : max(0, (int)$hal);
        [$kTipe, $kVal] = rekap_klien_from_post($_POST);
        $db->prepare(
            "UPDATE documents SET jenis_dokumen=?, halaman=?, klien_tipe=?, klien=?
             WHERE id=? AND reviewed_by=? AND status='approved'"
        )->execute([$jenis, $halaman, $kTipe, $kVal, $docId, $u['id']]);
        flash('Data rekap dokumen diperbarui.');
        redirect($backUrl);
    }

    if ($do === 'mirror_copy') {
        $id = (int)($_POST['entry_id'] ?? 0);
        $q = $db->prepare("SELECT * FROM work_entries WHERE id=? AND penerjemah_id=? AND jenis_catatan='pencocokan'");
        $q->execute([$id, $u['id']]);
        $w = $q->fetch();
        if ($w) {
            $db->prepare(
                "INSERT INTO work_entries (penerjemah_id, created_by, jenis_catatan, tanggal, judul, jenis_dokumen, arah_bahasa, halaman, klien_tipe, klien, catatan)
                 VALUES (?,?, 'rekap', ?,?,?,?,?,?,?,?)"
            )->execute([$u['id'], $u['id'], $w['tanggal'], $w['judul'], $w['jenis_dokumen'], $w['arah_bahasa'], $w['halaman'], $w['klien_tipe'], $w['klien'], $w['catatan']]);
            flash('Catatan mitra disalin menjadi entri rekap resmi Anda.');
        }
        redirect($backUrl);
    }

    if ($do === 'mirror_delete') {
        $id = (int)($_POST['entry_id'] ?? 0);
        $db->prepare("DELETE FROM work_entries WHERE id=? AND penerjemah_id=? AND jenis_catatan='pencocokan'")
            ->execute([$id, $u['id']]);
        flash('Catatan pencocokan dihapus.');
        redirect($backUrl);
    }
}

[$from, $to, $view, $labelPeriode] = rekap_resolve_range($prof, $_GET);
$rows = rekap_rows($db, (int)$u['id'], $from, $to, $arahDefault);
$mirrors = rekap_pencocokan($db, (int)$u['id'], $from, $to);

$totPekerjaan = count($rows);
$totHalaman = 0;
$nBlanko = 0; $nManual = 0;
$belumLengkap = [];
$aggArah = []; $aggJenis = [];
foreach ($rows as $r) {
    if ($r['halaman'] !== null) $totHalaman += $r['halaman'];
    if ($r['sumber'] === 'Blanko') $nBlanko++; else $nManual++;
    if (empty($r['jenis']) || $r['halaman'] === null) $belumLengkap[] = $r;
    $a = trim((string)$r['arah']) !== '' ? $r['arah'] : '-';
    $aggArah[$a] = ($aggArah[$a] ?? 0) + 1;
    $j = !empty($r['jenis']) ? $r['jenis'] : '(belum diisi)';
    $aggJenis[$j] = ($aggJenis[$j] ?? 0) + 1;
}
arsort($aggArah); arsort($aggJenis);
$aggBulan = rekap_per_bulan($rows);
$mirrorBulan = rekap_per_bulan($mirrors);
$bulanKeys = array_unique(array_merge(array_keys($aggBulan), array_keys($mirrorBulan)));
sort($bulanKeys);

$periodeAktif = !empty($prof['rekap_periode_aktif']) && !empty($prof['rekap_periode_mulai']) && !empty($prof['rekap_periode_selesai']);
$skIso = rekap_parse_tanggal($prof['sk_date'] ?? '');
$prefill = rekap_default_period($skIso);
$hariIni = date('Y-m-d');
$sisaHari = null; $lewatTenggat = false;
if ($periodeAktif) {
    $sisaHari = (int)round((strtotime($prof['rekap_periode_selesai']) - strtotime($hariIni)) / 86400);
    $lewatTenggat = $sisaHari < 0;
}

$editEntry = null;
if (!empty($_GET['edit'])) {
    $q = $db->prepare("SELECT * FROM work_entries WHERE id=? AND penerjemah_id=? AND jenis_catatan='rekap'");
    $q->execute([(int)$_GET['edit'], $u['id']]);
    $editEntry = $q->fetch() ?: null;
}

$exportQs = http_build_query(['view' => $view, 'year' => (int)($_GET['year'] ?? date('Y'))]);
ob_start(); ?>
<div class="max-w-3xl mx-auto">
  <h1 class="text-2xl font-bold tracking-tight mb-1">Rekap Tahunan</h1>
  <p class="text-sm text-slate-500 mb-5">Bahan laporan pelaksanaan jabatan (Permenkum 4/2025). Rekap ini menyiapkan data sampai siap kirim; pengiriman ke AHU tetap Anda lakukan lewat akun AHU Anda.</p>

  <?php if (!$periodeAktif): ?>
  <div class="bg-indigo-50 border border-indigo-200 rounded-3xl p-6 mb-5">
    <h2 class="font-bold text-indigo-900">Tahun Lapor belum aktif</h2>
    <p class="text-sm text-indigo-800 mt-1">Aktifkan untuk hitung mundur tenggat dan pengingat laporan tahunan via WA dan email. Tanggal periode di bawah adalah usulan dari tanggal pengangkatan SK Anda<?= $skIso ? ' (' . e(rekap_fmt_tanggal($skIso)) . ')' : '' ?> dan <b>dapat diubah bebas</b>.</p>
    <?php if (!$skIso): ?>
    <p class="text-sm text-amber-700 mt-2">Tanggal pengangkatan SK belum terisi atau tidak terbaca, jadi tanggal diisi manual. Isi dulu di <a class="underline font-medium" href="?page=settings">Profil &gt; Tanggal SK Pengangkatan</a> bila ingin usulan otomatis.</p>
    <?php endif; ?>
    <form method="post" class="mt-4 grid sm:grid-cols-3 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="period_save">
      <input type="hidden" name="periode_aktif" value="1">
      <div><label class="block text-sm font-medium mb-1.5">Mulai periode</label>
        <input type="date" name="periode_mulai" required value="<?= e($prefill[0] ?? '') ?>" class="w-full h-12 bg-white border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Selesai / tenggat</label>
        <input type="date" name="periode_selesai" required value="<?= e($prefill[1] ?? '') ?>" class="w-full h-12 bg-white border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <button class="h-12 px-6 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500">Aktifkan Tahun Lapor</button>
    </form>
  </div>
  <?php else: ?>
  <div class="bg-white rounded-3xl shadow-sm p-6 mb-5" x-data="{ubah:false}">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <div class="text-xs uppercase tracking-wide text-slate-400">Tahun Lapor aktif</div>
        <div class="font-bold text-lg mt-0.5"><?= e(rekap_fmt_tanggal($prof['rekap_periode_mulai'])) ?> s.d. <?= e(rekap_fmt_tanggal($prof['rekap_periode_selesai'])) ?></div>
        <div class="text-sm text-slate-500 mt-1">SK: <?= e($prof['sk'] ?: '-') ?></div>
        <?php if ($lewatTenggat): ?>
        <div class="text-sm text-red-600 font-medium mt-2">Periode telah berakhir <?= abs($sisaHari) ?> hari lalu. Tetapkan periode berikutnya agar rekap dan pengingat berjalan lagi.</div>
        <?php else: ?>
        <div class="text-sm text-slate-600 mt-2">Tenggat laporan: <b><?= e(rekap_fmt_tanggal($prof['rekap_periode_selesai'])) ?></b> — <?= $sisaHari ?> hari lagi.</div>
        <?php endif; ?>
      </div>
      <button type="button" @click="ubah=!ubah" class="h-10 px-4 rounded-2xl bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">Ubah periode</button>
    </div>
    <?php if ($lewatTenggat): ?>
    <form method="post" class="mt-4 flex flex-wrap gap-2 items-center">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="period_save">
      <input type="hidden" name="periode_aktif" value="1">
      <input type="hidden" name="periode_mulai" value="<?= e(rekap_add_year($prof['rekap_periode_mulai'], 1)) ?>">
      <input type="hidden" name="periode_selesai" value="<?= e(rekap_add_year($prof['rekap_periode_selesai'], 1)) ?>">
      <span class="text-sm text-slate-500">Usulan periode berikutnya: <?= e(rekap_fmt_tanggal(rekap_add_year($prof['rekap_periode_mulai'], 1))) ?> s.d. <?= e(rekap_fmt_tanggal(rekap_add_year($prof['rekap_periode_selesai'], 1))) ?></span>
      <button class="h-10 px-4 rounded-2xl bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500">Pakai usulan ini</button>
    </form>
    <?php endif; ?>
    <form method="post" x-show="ubah" class="mt-4 grid sm:grid-cols-3 gap-3 items-end border-t pt-4">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="period_save">
      <div><label class="block text-sm font-medium mb-1.5">Mulai periode</label>
        <input type="date" name="periode_mulai" value="<?= e($prof['rekap_periode_mulai']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Selesai / tenggat</label>
        <input type="date" name="periode_selesai" value="<?= e($prof['rekap_periode_selesai']) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div class="flex gap-2">
        <label class="flex items-center gap-1.5 text-sm"><input type="checkbox" name="periode_aktif" value="1" checked class="w-4 h-4 accent-indigo-600">Aktif</label>
        <button class="h-12 px-5 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500">Simpan</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="flex flex-wrap items-center gap-2 mb-5">
    <?php if ($periodeAktif): ?>
    <a href="?page=rekap&view=lapor" class="h-10 px-4 rounded-2xl text-sm font-medium flex items-center <?= $view === 'lapor' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 shadow-sm' ?>">Tahun Lapor</a>
    <?php endif; ?>
    <a href="?page=rekap&view=kalender&year=<?= (int)($_GET['year'] ?? date('Y')) ?>" class="h-10 px-4 rounded-2xl text-sm font-medium flex items-center <?= $view === 'kalender' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 shadow-sm' ?>">Tahun Kalender</a>
    <?php if ($view === 'kalender'): ?>
    <form method="get" class="flex items-center gap-2">
      <input type="hidden" name="page" value="rekap">
      <input type="hidden" name="view" value="kalender">
      <select name="year" onchange="this.form.submit()" class="h-10 bg-white shadow-sm border-0 rounded-2xl px-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        <?php for ($y = (int)date('Y') + 1; $y >= 2022; $y--): ?>
        <option value="<?= $y ?>" <?= (int)($_GET['year'] ?? date('Y')) === $y ? 'selected' : '' ?>><?= $y ?></option>
        <?php endfor; ?>
      </select>
    </form>
    <?php endif; ?>
    <span class="text-sm text-slate-500 ml-auto">Periode tampil: <b><?= e($labelPeriode) ?></b></span>
  </div>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-3xl shadow-sm p-4"><div class="text-2xl font-bold"><?= $totPekerjaan ?></div><div class="text-xs text-slate-400 mt-0.5">Total pekerjaan</div></div>
    <div class="bg-white rounded-3xl shadow-sm p-4"><div class="text-2xl font-bold"><?= number_format($totHalaman, 0, ',', '.') ?></div><div class="text-xs text-slate-400 mt-0.5">Total halaman hasil</div></div>
    <div class="bg-white rounded-3xl shadow-sm p-4"><div class="text-2xl font-bold"><?= $nBlanko ?></div><div class="text-xs text-slate-400 mt-0.5">Dari Blanko</div></div>
    <div class="bg-white rounded-3xl shadow-sm p-4"><div class="text-2xl font-bold"><?= $nManual ?></div><div class="text-xs text-slate-400 mt-0.5">Entri manual</div></div>
  </div>

  <div class="flex flex-wrap gap-2 mb-6">
    <span class="text-sm text-slate-500 self-center">Ekspor periode ini:</span>
    <a href="?page=rekap_export&format=pdf&<?= $exportQs ?>" class="h-10 px-4 rounded-2xl bg-white shadow-sm text-sm font-medium flex items-center gap-1.5 hover:bg-slate-50"><i class="fa-solid fa-file-pdf text-red-500"></i>PDF</a>
    <a href="?page=rekap_export&format=xlsx&<?= $exportQs ?>" class="h-10 px-4 rounded-2xl bg-white shadow-sm text-sm font-medium flex items-center gap-1.5 hover:bg-slate-50"><i class="fa-solid fa-file-excel text-emerald-600"></i>Excel</a>
    <a href="?page=rekap_export&format=csv&<?= $exportQs ?>" class="h-10 px-4 rounded-2xl bg-white shadow-sm text-sm font-medium flex items-center gap-1.5 hover:bg-slate-50"><i class="fa-solid fa-file-csv text-slate-500"></i>CSV</a>
  </div>

  <?php if ($belumLengkap): ?>
  <div class="bg-amber-50 border border-amber-200 rounded-3xl p-6 mb-5">
    <h2 class="font-bold text-amber-900">Perlu dilengkapi (<?= count($belumLengkap) ?>)</h2>
    <p class="text-sm text-amber-800 mt-1 mb-4">Jenis dokumen atau halaman masih kosong. Rekap tetap bisa diekspor, tetapi PDF akan mencatat jumlah data yang belum lengkap ini.</p>
    <?php foreach ($belumLengkap as $r): ?>
    <form method="post" class="bg-white rounded-2xl p-4 mb-3 grid sm:grid-cols-4 gap-3 items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="doc_save">
      <input type="hidden" name="doc_id" value="<?= (int)$r['doc_id'] ?>">
      <div class="sm:col-span-4 text-sm"><b><?= e($r['judul']) ?></b> <span class="text-slate-400">— <?= e(rekap_fmt_tanggal($r['tanggal'])) ?></span></div>
      <div><label class="block text-xs font-medium mb-1">Jenis dokumen</label>
        <input type="text" name="jenis_dokumen" list="rk-jenis-list" maxlength="100" value="<?= e($r['jenis'] ?? '') ?>" class="w-full h-11 bg-slate-50 border-0 rounded-xl px-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-xs font-medium mb-1">Halaman hasil</label>
        <input type="number" name="halaman" min="0" value="<?= $r['halaman'] !== null ? (int)$r['halaman'] : '' ?>" class="w-full h-11 bg-slate-50 border-0 rounded-xl px-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-xs font-medium mb-1">Klien</label>
        <input type="hidden" name="klien_tipe" value="<?= e($r['klien_tipe']) ?>">
        <?php if ($r['klien_tipe'] === 'nama'): ?>
        <input type="text" name="klien_nama" maxlength="150" value="<?= e($r['klien'] ?? '') ?>" class="w-full h-11 bg-slate-50 border-0 rounded-xl px-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        <?php else: ?>
        <select name="klien_kategori" class="w-full h-11 bg-slate-50 border-0 rounded-xl px-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          <option value="">— kategori —</option>
          <?php foreach (REKAP_KATEGORI as $k): ?>
          <option value="<?= e($k) ?>" <?= ($r['klien'] ?? '') === $k ? 'selected' : '' ?>><?= e($k) ?></option>
          <?php endforeach; ?>
        </select>
        <?php endif; ?>
      </div>
      <button class="h-11 px-4 bg-indigo-600 text-white rounded-xl text-sm font-semibold hover:bg-indigo-500">Simpan</button>
    </form>
    <?php endforeach; ?>
    <datalist id="rk-jenis-list">
      <?php foreach (REKAP_JENIS as $j): ?>
      <option value="<?= e($j) ?>"></option>
      <?php endforeach; ?>
    </datalist>
  </div>
  <?php endif; ?>

  <div class="grid sm:grid-cols-3 gap-3 mb-5">
    <div class="bg-white rounded-3xl shadow-sm p-5">
      <h3 class="font-bold text-sm mb-3">Per arah bahasa</h3>
      <?php if (!$aggArah): ?><p class="text-sm text-slate-400">Belum ada data.</p><?php endif; ?>
      <?php foreach ($aggArah as $k => $v): ?>
      <div class="flex justify-between text-sm py-1 border-b border-slate-50 last:border-0"><span class="text-slate-600"><?= e($k) ?></span><b><?= $v ?></b></div>
      <?php endforeach; ?>
    </div>
    <div class="bg-white rounded-3xl shadow-sm p-5">
      <h3 class="font-bold text-sm mb-3">Per jenis dokumen</h3>
      <?php if (!$aggJenis): ?><p class="text-sm text-slate-400">Belum ada data.</p><?php endif; ?>
      <?php foreach ($aggJenis as $k => $v): ?>
      <div class="flex justify-between text-sm py-1 border-b border-slate-50 last:border-0"><span class="text-slate-600"><?= e($k) ?></span><b><?= $v ?></b></div>
      <?php endforeach; ?>
    </div>
    <div class="bg-white rounded-3xl shadow-sm p-5">
      <h3 class="font-bold text-sm mb-3">Per bulan</h3>
      <?php if (!$aggBulan): ?><p class="text-sm text-slate-400">Belum ada data.</p><?php endif; ?>
      <?php foreach ($aggBulan as $k => $v): [$yy, $mm] = explode('-', $k); ?>
      <div class="flex justify-between text-sm py-1 border-b border-slate-50 last:border-0"><span class="text-slate-600"><?= e(REKAP_BULAN[(int)$mm]) ?> <?= e($yy) ?></span><b><?= $v ?></b></div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="bg-white rounded-3xl shadow-sm p-6 mb-5">
    <h2 class="font-bold mb-4">Rincian pekerjaan tercatat</h2>
    <?php if (!$rows): ?>
    <p class="text-sm text-slate-400">Belum ada pekerjaan tercatat pada periode ini. Dokumen yang Anda setujui di Blanko akan muncul otomatis di sini.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-slate-400 border-b">
        <th class="py-2 pr-3 font-medium">Tanggal</th><th class="py-2 pr-3 font-medium">Judul</th>
        <th class="py-2 pr-3 font-medium">Jenis</th><th class="py-2 pr-3 font-medium">Arah</th>
        <th class="py-2 pr-3 font-medium text-right">Hal.</th><th class="py-2 pr-3 font-medium">Klien</th>
        <th class="py-2 pr-3 font-medium">Sumber</th><th class="py-2 font-medium"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
      <tr class="border-b border-slate-50 last:border-0 align-top">
        <td class="py-2 pr-3 whitespace-nowrap"><?= e(rekap_fmt_tanggal($r['tanggal'])) ?></td>
        <td class="py-2 pr-3"><?= e($r['judul']) ?></td>
        <td class="py-2 pr-3"><?= e($r['jenis'] ?: '-') ?></td>
        <td class="py-2 pr-3"><?= e($r['arah'] ?: '-') ?></td>
        <td class="py-2 pr-3 text-right"><?= $r['halaman'] !== null ? (int)$r['halaman'] : '-' ?></td>
        <td class="py-2 pr-3"><?= e(rekap_klien_label($r['klien_tipe'], $r['klien'])) ?></td>
        <td class="py-2 pr-3"><span class="px-2 py-0.5 rounded-full text-xs <?= $r['sumber'] === 'Blanko' ? 'bg-indigo-50 text-indigo-700' : 'bg-slate-100 text-slate-600' ?>"><?= e($r['sumber']) ?></span></td>
        <td class="py-2 whitespace-nowrap text-right">
          <?php if ($r['doc_id']): ?>
          <a href="?page=preview&id=<?= (int)$r['doc_id'] ?>" class="text-indigo-600 text-xs font-medium">Buka</a>
          <?php else: ?>
          <a href="?page=rekap&edit=<?= (int)$r['entry_id'] ?>&<?= $exportQs ?>#form-entri" class="text-indigo-600 text-xs font-medium">Ubah</a>
          <form method="post" class="inline" onsubmit="return confirm('Hapus entri ini?')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="entry_delete">
            <input type="hidden" name="entry_id" value="<?= (int)$r['entry_id'] ?>">
            <button class="text-red-500 text-xs font-medium ml-2">Hapus</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>

  <div class="bg-white rounded-3xl shadow-sm p-6 mb-5" id="form-entri">
    <h2 class="font-bold mb-1"><?= $editEntry ? 'Ubah entri manual' : 'Catat pekerjaan di luar Blanko' ?></h2>
    <p class="text-sm text-slate-500 mb-4">Pekerjaan yang Anda kerjakan tanpa lewat Blanko. Entri ini langsung menjadi catatan resmi rekap Anda.</p>
    <form method="post" class="grid sm:grid-cols-2 gap-4" x-data="{mode:'<?= e($editEntry['klien_tipe'] ?? 'kategori') ?>'}">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="entry_save">
      <input type="hidden" name="entry_id" value="<?= (int)($editEntry['id'] ?? 0) ?>">
      <div><label class="block text-sm font-medium mb-1.5">Tanggal selesai</label>
        <input type="date" name="tanggal" required value="<?= e($editEntry['tanggal'] ?? date('Y-m-d')) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Judul pekerjaan</label>
        <input type="text" name="judul" required maxlength="200" value="<?= e($editEntry['judul'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="cth: Terjemahan ijazah untuk beasiswa"></div>
      <div><label class="block text-sm font-medium mb-1.5">Jenis dokumen</label>
        <input type="text" name="jenis_dokumen" list="rk-jenis-list2" maxlength="100" value="<?= e($editEntry['jenis_dokumen'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="— pilih atau ketik —">
        <datalist id="rk-jenis-list2">
          <?php foreach (REKAP_JENIS as $j): ?>
          <option value="<?= e($j) ?>"></option>
          <?php endforeach; ?>
        </datalist></div>
      <div><label class="block text-sm font-medium mb-1.5">Arah bahasa</label>
        <input type="text" name="arah_bahasa" list="rk-arah-list" maxlength="120" value="<?= e($editEntry['arah_bahasa'] ?? ($arahOpsi[0] ?? '')) ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
        <datalist id="rk-arah-list">
          <?php foreach ($arahOpsi as $a): ?>
          <option value="<?= e($a) ?>"></option>
          <?php endforeach; ?>
        </datalist></div>
      <div><label class="block text-sm font-medium mb-1.5">Halaman hasil</label>
        <input type="number" name="halaman" min="0" value="<?= isset($editEntry['halaman']) && $editEntry['halaman'] !== null ? (int)$editEntry['halaman'] : '' ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div>
        <label class="block text-sm font-medium mb-1.5">Klien</label>
        <div class="flex gap-4 mb-2 text-sm">
          <label class="flex items-center gap-1.5"><input type="radio" name="klien_tipe" value="kategori" <?= ($editEntry['klien_tipe'] ?? 'kategori') !== 'nama' ? 'checked' : '' ?> @click="mode='kategori'" class="w-4 h-4 accent-indigo-600">Kategori</label>
          <label class="flex items-center gap-1.5"><input type="radio" name="klien_tipe" value="nama" <?= ($editEntry['klien_tipe'] ?? '') === 'nama' ? 'checked' : '' ?> @click="mode='nama'" class="w-4 h-4 accent-indigo-600">Nama bebas</label>
        </div>
        <select name="klien_kategori" x-show="mode==='kategori'" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
          <option value="">— pilih kategori —</option>
          <?php foreach (REKAP_KATEGORI as $k): ?>
          <option value="<?= e($k) ?>" <?= ($editEntry['klien_tipe'] ?? '') === 'kategori' && ($editEntry['klien'] ?? '') === $k ? 'selected' : '' ?>><?= e($k) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="klien_nama" x-show="mode==='nama'" maxlength="150" placeholder="Nama klien"
               value="<?= ($editEntry['klien_tipe'] ?? '') === 'nama' ? e($editEntry['klien'] ?? '') : '' ?>"
               class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
      </div>
      <div class="sm:col-span-2"><label class="block text-sm font-medium mb-1.5">Catatan <span class="text-slate-400 font-normal">(opsional)</span></label>
        <input type="text" name="catatan" maxlength="500" value="<?= e($editEntry['catatan'] ?? '') ?>" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div class="sm:col-span-2 flex gap-2">
        <button class="h-12 px-6 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500"><?= $editEntry ? 'Simpan Perubahan' : 'Tambah Entri' ?></button>
        <?php if ($editEntry): ?>
        <a href="?page=rekap&<?= $exportQs ?>" class="h-12 px-5 rounded-2xl bg-slate-100 text-slate-600 font-medium flex items-center">Batal</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <div class="bg-white rounded-3xl shadow-sm p-6">
    <h2 class="font-bold mb-1">Catatan pencocokan dari mitra</h2>
    <p class="text-sm text-slate-500 mb-4">Pekerjaan di luar Blanko yang dicatat mitra Anda, sebatas bahan cocok data. Catatan ini <b>tidak terhitung</b> di rekap dan tidak masuk ekspor. Bila ada yang cocok dan belum tercatat, salin menjadi entri resmi Anda.</p>
    <?php if ($bulanKeys): ?>
    <div class="overflow-x-auto mb-4">
    <table class="w-full text-sm max-w-md">
      <thead><tr class="text-left text-slate-400 border-b"><th class="py-2 pr-3 font-medium">Bulan</th><th class="py-2 pr-3 font-medium">Tercatat resmi</th><th class="py-2 font-medium">Catatan mitra</th></tr></thead>
      <tbody>
      <?php foreach ($bulanKeys as $k): [$yy, $mm] = explode('-', $k); ?>
      <tr class="border-b border-slate-50 last:border-0">
        <td class="py-2 pr-3"><?= e(REKAP_BULAN[(int)$mm]) ?> <?= e($yy) ?></td>
        <td class="py-2 pr-3"><?= (int)($aggBulan[$k] ?? 0) ?></td>
        <td class="py-2"><?= (int)($mirrorBulan[$k] ?? 0) ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
    <?php if (!$mirrors): ?>
    <p class="text-sm text-slate-400">Belum ada catatan pencocokan dari mitra pada periode ini.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
      <thead><tr class="text-left text-slate-400 border-b">
        <th class="py-2 pr-3 font-medium">Tanggal</th><th class="py-2 pr-3 font-medium">Judul</th>
        <th class="py-2 pr-3 font-medium">Jenis</th><th class="py-2 pr-3 font-medium text-right">Hal.</th>
        <th class="py-2 pr-3 font-medium">Klien</th><th class="py-2 pr-3 font-medium">Dicatat oleh</th><th class="py-2 font-medium"></th>
      </tr></thead>
      <tbody>
      <?php foreach ($mirrors as $w): ?>
      <tr class="border-b border-slate-50 last:border-0 align-top">
        <td class="py-2 pr-3 whitespace-nowrap"><?= e(rekap_fmt_tanggal($w['tanggal'])) ?></td>
        <td class="py-2 pr-3"><?= e($w['judul']) ?><?php if (!empty($w['catatan'])): ?><div class="text-xs text-slate-400"><?= e($w['catatan']) ?></div><?php endif; ?></td>
        <td class="py-2 pr-3"><?= e($w['jenis_dokumen'] ?: '-') ?></td>
        <td class="py-2 pr-3 text-right"><?= $w['halaman'] !== null ? (int)$w['halaman'] : '-' ?></td>
        <td class="py-2 pr-3"><?= e(rekap_klien_label($w['klien_tipe'], $w['klien'])) ?></td>
        <td class="py-2 pr-3"><?= e($w['mitra_name']) ?></td>
        <td class="py-2 whitespace-nowrap text-right">
          <form method="post" class="inline">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="mirror_copy">
            <input type="hidden" name="entry_id" value="<?= (int)$w['id'] ?>">
            <button class="text-indigo-600 text-xs font-medium">Jadikan entri rekap</button>
          </form>
          <form method="post" class="inline" onsubmit="return confirm('Hapus catatan pencocokan ini?')">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="mirror_delete">
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
<?php layout('Rekap Tahunan', ob_get_clean(), $u); ?>
