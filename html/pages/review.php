<?php
// Review: approve / decline via signed token (dari WA/email) atau sesi penerjemah.
declare(strict_types=1);
require_once __DIR__ . '/../includes/Token.php';
require_once __DIR__ . '/../includes/esign.php';
require_once __DIR__ . '/../includes/Notify.php';
require_once __DIR__ . '/../includes/Attestation.php';
require_once __DIR__ . '/../includes/HtmlAttestation.php';

$tokenRow = null;
if (!empty($_GET['token'])) {
    $tokenRow = token_verify($_GET['token']);
    if (!$tokenRow) { http_response_code(403); exit('Link tidak valid atau kedaluwarsa.'); }
}

$docId = $tokenRow ? (int)$tokenRow['document_id'] : (int)($_GET['id'] ?? 0);
$action = $tokenRow ? $tokenRow['action'] : ($_GET['do'] ?? '');

$u = current_user();
$isPenerjemah = $u && $u['role'] === 'penerjemah';
// Reviewer untuk approve (dipakai juga oleh editor e-sign di bawah).
$reviewer = $isPenerjemah ? (int)$u['id'] : (int)(db()->query("SELECT id FROM users WHERE role='penerjemah' LIMIT 1")->fetchColumn());
$esignSigFile = null;
if ($reviewer) {
    $es = db()->prepare('SELECT esign_sig_file FROM users WHERE id = ?');
    $es->execute([$reviewer]);
    $esignSigFile = $es->fetchColumn() ?: null;
}
$esignToken = $esignSigFile ? esign_token($docId, $reviewer) : null;
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
        $stampedName = null;

        if ($do === 'approve') {            // profil penerjemah untuk blanko
            $pr = db()->prepare('SELECT * FROM users WHERE id = ?');
            $pr->execute([$reviewer]);
            $prov = $pr->fetch();
            // no. registrasi: YYYY-ROMAN-RDW-xxx
            $mitraShort = strtoupper(preg_replace('/[^A-Z]/i', '', explode(' ', $d['mitra_name'])[0] ?? 'XX'));
            $regNo = reg_number($docId, substr($mitraShort, 0, 3), 'TRJ');
            $bln = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
            $dateId = date('d') . ' ' . $bln[(int)date('n') - 1] . ' ' . date('Y');

            $prof = [
                'name' => $prov['name'] ?: 'Penerjemah Tersumpah',
                'ar_name' => $prov['name_ar'] ?: '',
                'name_zh' => $prov['name_zh'] ?? '',
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
            // template blanko (milik reviewer; admin boleh memakai template mana pun)
            $tplRow = null;
            $tplId = (int)($_POST['template_id'] ?? 0);
            $isReviewerAdmin = !empty($prov['is_admin']);
            if ($tplId > 0) {
                $ts = db()->prepare('SELECT * FROM blanko_templates WHERE id = ?');
                $ts->execute([$tplId]);
                $row = $ts->fetch();
                if ($row && ($isReviewerAdmin || (int)($row['user_id'] ?? 0) === $reviewer)) $tplRow = $row;
            }
            if (!$tplRow) {
                $ts = db()->prepare('SELECT * FROM blanko_templates WHERE user_id = ? AND is_default = 1 LIMIT 1');
                $ts->execute([$reviewer]);
                $tplRow = $ts->fetch() ?: null;
            }
            $tplCfg = $tplRow ? (json_decode($tplRow['config'] ?? '{}', true) ?: []) : [];
            $tplIsHtml = $tplRow && trim((string)($tplRow['body_html'] ?? '')) !== '';
            $tplPlacement = $tplRow && in_array($tplRow['placement'] ?? '', ['strip','page'], true) ? $tplRow['placement'] : 'strip';
            $tplLangPair = $tplRow['lang_pair'] ?? '';
            // Approve SELALU memakai e-sign (jalur cap dihapus 26 Okt).
            // Syarat: penerjemah sudah merekam TTD + token e-sign cocok.
            $final = '';
            if ($esignToken === null) {
                flash('Anda belum merekam tanda tangan elektronik. Rekam dulu di Pengaturan sebelum approve.', 'err');
            } elseif (!hash_equals($esignToken, (string)($_POST['esign_token'] ?? ''))) {
                flash('Sesi e-sign tidak valid. Muat ulang halaman review lalu coba lagi.', 'err');
            } else {
                $ex = (float)($_POST['esign_x'] ?? -1);
                $ey = (float)($_POST['esign_y'] ?? -1);
                $es = (float)($_POST['esign_size'] ?? 0);
                $qrPath = esign_save_qr((string)($_POST['esign_qr_data'] ?? ''), $esignToken);
                $sigPath = $esignSigFile ? (esign_dir() . '/' . basename($esignSigFile)) : '';
                if ($qrPath === '' || $sigPath === '' || !is_file($sigPath)
                    || $ex < 0 || $ex > 1 || $ey < 0 || $ey > 1 || $es < 0.05 || $es > 0.6) {
                    flash('QR e-sign tidak valid. Atur ulang posisi QR lalu coba lagi.', 'err');
                } else {
                    $ex = min($ex, 1 - $es); $ey = min($ey, 1 - $es);
                    $tplCfg['show_stamp'] = false; // strip tanpa cap
                    $upDir = '/var/www/data/uploads/';
                    // template HTML: QR menarget halaman pengesahan bila mode halaman
                    $qrPages = ($tplIsHtml && $tplPlacement === 'page') ? 'last' : $blankoPages;
                    if ($tplIsHtml) {
                        // jalur HTML: hasil SELALU PDF (dokumen gambar pun jadi PDF)
                        // Kotak pengesahan kustom (mode strip, digeser/diubah ukuran di editor).
                        $attRect = null;
                        if ($tplPlacement === 'strip' && ($_POST['att_custom'] ?? '') === '1') {
                            $ax = (float)($_POST['att_x'] ?? 0); $ay = (float)($_POST['att_y'] ?? 0);
                            $aw = (float)($_POST['att_w'] ?? 0); $ah = (float)($_POST['att_h'] ?? 0);
                            if ($aw > 0 && $ah > 0 && $ax >= 0 && $ay >= 0 && $ax <= 1 && $ay <= 1) {
                                $attRect = [min($ax, 1 - $aw), min($ay, 1 - $ah), $aw, $ah];
                            }
                        }
                        $merged = html_merge_document($src, $d['mime'], (string)$tplRow['body_html'], $prof, $dyn, $tplLangPair, $tplPlacement, $blankoPages, (string)($tplRow['font'] ?? ''), $attRect);
                        if ($merged[0] !== '') {
                            $qrFinal = esign_apply_pdf($merged[0], $qrPath, $ex, $ey, $es, $qrPages);
                            $clean = $upDir . $d['stored_name'] . '.stamped.pdf';
                            if ($qrFinal !== '' && copy($qrFinal, $clean)) {
                                @unlink($qrFinal);
                                if ($merged[0] !== $clean) @unlink($merged[0]);
                                $final = $clean;
                            } else {
                                @unlink($qrFinal); @unlink($merged[0]);
                                flash('Gagal membubuhkan QR e-sign.', 'err');
                            }
                        } else {
                            flash('Gagal membubuhkan blanko: ' . $merged[1], 'err');
                        }
                    } elseif (str_contains($d['mime'], 'pdf')) {
                        $stripped = blanko_append($src, $d['mime'], $prof, $dyn, '', $blankoPages, $tplCfg);
                        if ($stripped !== '') {
                            $qrFinal = esign_apply_pdf($stripped, $qrPath, $ex, $ey, $es, $blankoPages);
                            $clean = $upDir . $d['stored_name'] . '.stamped.pdf';
                            if ($qrFinal !== '' && copy($qrFinal, $clean)) {
                                @unlink($qrFinal);
                                if ($stripped !== $clean) @unlink($stripped);
                                $final = $clean;
                            } else {
                                @unlink($qrFinal); @unlink($stripped);
                                flash('Gagal membubuhkan QR e-sign.', 'err');
                            }
                        } else {
                            flash('Gagal membubuhkan blanko.', 'err');
                        }
                    } else {
                        // Blanko dulu, baru QR: koordinat editor dihitung terhadap
                        // dokumen komposit (gambar + strip), bukan gambar asli.
                        $stamped = blanko_append($src, $d['mime'], $prof, $dyn, '', $blankoPages, $tplCfg);
                        if ($stamped !== '') {
                            $qrd = esign_apply_image($stamped, 'image/jpeg', $qrPath, $ex, $ey, $es);
                            $clean = $upDir . $d['stored_name'] . '.stamped.jpg';
                            if ($qrd !== '' && copy($qrd, $clean)) {
                                @unlink($qrd);
                                $final = $clean;
                            } else {
                                @unlink($qrd); @unlink($stamped);
                                flash('Gagal membubuhkan QR e-sign.', 'err');
                            }
                        } else {
                            flash('Gagal membubuhkan blanko.', 'err');
                        }
                    }
                    if ($final !== '') {
                        $snap = 'sig_' . $esignToken . '.png';
                        @copy($sigPath, esign_dir() . '/' . $snap);
                        db()->prepare("INSERT INTO esign_records (document_id, user_id, token, status, qr_file, sig_file, pos_x, pos_y, pos_size, pos_pages, created_at, signed_at) VALUES (?,?,?,?,?,?,?,?,?,?,datetime('now'),datetime('now'))")
                            ->execute([$docId, $reviewer, $esignToken, 'signed', basename($qrPath), $snap, $ex, $ey, $es, $qrPages]);
                    }
                }
            }
            if ($final !== '') {
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
                flash('Dokumen disetujui dan QR Tanda Tangan Elektronik (simulasi) telah dibubuhkan.');
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


$esignVerifyUrl = $esignToken ? esign_verify_url($esignToken) : null;

$esignPreviewBase = '?page=esign-preview&id=' . $docId . ($tokenRow ? '&token=' . urlencode((string)($_GET['token'] ?? '')) : '');
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

// template untuk form (milik reviewer; admin melihat semua)
$isFormAdmin = !empty($u['is_admin']);
if ($isFormAdmin) {
    $tplsForm = db()->query('SELECT id, name, is_default, placement, body_html, font, lang_pair FROM blanko_templates ORDER BY is_default DESC, id')->fetchAll();
} else {
    $stf = db()->prepare('SELECT id, name, is_default, placement, body_html, font, lang_pair FROM blanko_templates WHERE user_id = ? ORDER BY is_default DESC, id');
    $stf->execute([$reviewer]);
    $tplsForm = $stf->fetchAll();
}
$defaultTplForm = null;
foreach ($tplsForm as $tp) { if ($tp['is_default']) { $defaultTplForm = $tp; break; } }
if (!$defaultTplForm && $tplsForm) $defaultTplForm = $tplsForm[0];
$initialUsePdf = $isPdf || ($defaultTplForm && trim((string)($defaultTplForm['body_html'] ?? '')) !== '');

// Data overlay pengesahan (mode strip): isi template per id + variabel contoh
$attProfRow = [];
if ($reviewer) { $ps = db()->prepare('SELECT * FROM users WHERE id = ?'); $ps->execute([$reviewer]); $attProfRow = $ps->fetch() ?: []; }
$attMitraShort = strtoupper(preg_replace('/[^A-Z]/i', '', explode(' ', $d['mitra_name'])[0] ?? 'XX'));
$attRegNo = reg_number($docId, substr($attMitraShort, 0, 3), 'TRJ');
$attBln = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$attDateId = date('d') . ' ' . $attBln[(int)date('n') - 1] . ' ' . date('Y');
$attPair = $attProfRow['lang_pair'] ?? '';
$attFrom = $attPair; $attTo = '';
if (($pp = mb_strpos($attPair, ' ke ')) !== false) { $attFrom = mb_substr($attPair, 0, $pp); $attTo = mb_substr($attPair, $pp + 4); }
$attVars = [
    'name' => $attProfRow['name'] ?? 'Nama Penerjemah',
    'name_foreign' => ($attProfRow['name_ar'] ?? '') !== '' ? $attProfRow['name_ar'] : ($attProfRow['name_zh'] ?? ''),
    'sk' => $attProfRow['sk'] ?? '', 'sk_date' => $attProfRow['sk_date'] ?? '',
    'reg_no' => $attRegNo, 'date' => $attDateId, 'doc_title' => $d['title'],
    'lang_pair' => $attPair, 'lang_from' => $attFrom, 'lang_to' => $attTo,
    'city' => $attProfRow['city'] ?? '', 'address' => $attProfRow['address'] ?? '',
    'phone' => $attProfRow['phone'] ?? '', 'email' => $attProfRow['email'] ?? '',
];
$attTplMap = [];
foreach ($tplsForm as $tp) {
    $attTplMap[(int)$tp['id']] = ['body' => (string)($tp['body_html'] ?? ''), 'font' => (string)($tp['font'] ?? ''), 'lang' => (string)($tp['lang_pair'] ?? ''), 'placement' => (string)($tp['placement'] ?? 'strip')];
}
$attDefaultTplId = $defaultTplForm ? (int)$defaultTplForm['id'] : 0;

ob_start(); ?>
<div class="max-w-3xl mx-auto">
  <a href="?page=dashboard" class="inline-flex items-center gap-2 text-sm text-slate-500 hover:text-slate-700 mb-4">
    <i class="fa-solid fa-arrow-left"></i>Kembali
  </a>
  <div class="bg-white rounded-3xl shadow-sm p-5 sm:p-6">
    <h1 class="text-xl font-bold tracking-tight">Review Dokumen</h1>
    <p class="text-sm text-slate-500 mt-1 mb-4"><?= e($d['title']) ?> · Mitra: <?= e($d['mitra_name']) ?></p>



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
        <p id="esign-approve-note" class="text-sm text-slate-600 bg-emerald-50 rounded-2xl px-4 py-3">Blanko pernyataan penerjemah tersumpah akan ditempel di dokumen, dan QR Tanda Tangan Elektronik (simulasi) akan dibubuhkan pada posisi yang diatur.</p>
        <?php if ($showPageOpt): ?>
        <div class="text-sm bg-slate-50 rounded-2xl p-4" id="blanko-pages-wrap"<?= ($defaultTplForm && ($defaultTplForm['placement'] ?? '') === 'page') ? ' style="display:none"' : '' ?>>
          <div class="font-semibold mb-2">Dokumen <?= $npages ?> halaman — beri blanko di:</div>
          <div class="space-y-2">
            <label class="flex items-center gap-2.5"><input type="radio" name="blanko_pages" value="first" class="w-4 h-4 accent-indigo-600">Halaman 1 saja</label>
            <label class="flex items-center gap-2.5"><input type="radio" name="blanko_pages" value="last" checked class="w-4 h-4 accent-indigo-600">Halaman terakhir saja</label>
            <label class="flex items-center gap-2.5"><input type="radio" name="blanko_pages" value="all" class="w-4 h-4 accent-indigo-600">Semua halaman</label>
          </div>
        </div>
        <?php endif; ?>
        <?php if (count($tplsForm) > 1): ?>
        <div>
          <label class="block text-sm font-medium mb-1.5">Template blanko</label>
          <select name="template_id" id="tpl-select" class="w-full h-12 bg-slate-50 border-0 rounded-2xl px-4 text-[15px] focus:ring-2 focus:ring-indigo-500 outline-none">
            <?php foreach ($tplsForm as $tp): ?>
              <option value="<?= (int)$tp['id'] ?>" data-placement="<?= e($tp['placement'] ?? 'strip') ?>" data-is-html="<?= trim((string)($tp['body_html'] ?? '')) !== '' ? '1' : '0' ?>" <?= $tp['is_default'] ? 'selected' : '' ?>><?= e($tp['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <script>
        (function () {
          var sel = document.getElementById('tpl-select');
          var wrap = document.getElementById('blanko-pages-wrap');
          if (!sel || !wrap) return;
          function t() {
            var o = sel.options[sel.selectedIndex];
            if (o) wrap.style.display = (o.dataset.placement === 'page') ? 'none' : '';
          }
          sel.addEventListener('change', t);
          t();
        })();
        </script>
        <?php if ($esignToken): ?>
        <div class="border-t pt-4">
          <p class="text-sm font-medium">QR Tanda Tangan Elektronik (simulasi)</p>
          <p class="text-xs text-slate-500 mt-1">Geser kotak pengesahan (mode strip) dan QR pada preview untuk mengatur tata letaknya.</p>
          <div id="esign-editor" class="mt-3">
            <div id="esign-preview-wrap" class="relative bg-slate-100 rounded-2xl overflow-hidden select-none">
              <canvas id="esign-pdf-canvas" class="w-full h-auto block"<?= $initialUsePdf ? '' : ' style="display:none"' ?>></canvas>
              <img id="esign-preview-img" src="<?= e($esignPreviewBase . '&pages=last') ?>" class="w-full h-auto block" alt="Preview dokumen"<?= $initialUsePdf ? ' style="display:none"' : '' ?>>
              <div id="esign-att-box" class="absolute" style="left:7.14%;top:70%;width:85.71%;touch-action:none;cursor:move;user-select:none;display:none">
                <div id="esign-att-content" style="background:#fff;border:1.5px solid #6366f1;border-radius:6px;padding:10px 12px;font-size:11pt;line-height:1.5;overflow:hidden"></div>
                <div id="esign-att-handle" class="absolute -bottom-2 -right-2 z-10 flex items-center justify-center" style="width:44px;height:44px;touch-action:none;cursor:nwse-resize;" title="Tarik untuk mengubah ukuran"><span class="block w-7 h-7 bg-emerald-600 rounded-full border-2 border-white shadow"></span></div>
              </div>
              <div id="esign-qr-box" class="absolute" style="left:70%;top:70%;width:20%;aspect-ratio:1/1;touch-action:none;cursor:move;">
                <div id="esign-qr" class="w-full h-full"></div>
                <div id="esign-qr-handle" class="absolute -bottom-2 -right-2 z-10 flex items-center justify-center" style="width:44px;height:44px;touch-action:none;cursor:nwse-resize;" title="Tarik untuk mengubah ukuran"><span class="block w-7 h-7 bg-indigo-600 rounded-full border-2 border-white shadow"></span></div>
              </div>
            </div>
            <div id="esign-page-nav" class="flex flex-wrap items-center gap-2 mt-2" style="display:none">
              <button type="button" id="esign-page-prev" class="h-9 px-4 rounded-xl bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">‹ Sebelumnya</button>
              <span id="esign-page-ind" class="text-sm text-slate-600">Halaman 1 dari 1</span>
              <button type="button" id="esign-page-next" class="h-9 px-4 rounded-xl bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">Berikutnya ›</button>
              <button type="button" id="esign-page-target" class="h-9 px-4 rounded-xl bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">Kembali ke halaman blanko</button>
            </div>
            <div class="flex flex-wrap items-center gap-2 mt-2">
              <span class="text-xs text-slate-500">Ukuran QR:</span>
              <button type="button" id="esign-size-down" class="h-9 px-4 rounded-xl bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">− Perkecil</button>
              <button type="button" id="esign-size-up" class="h-9 px-4 rounded-xl bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">+ Perbesar</button>
              <span class="text-xs text-slate-500">atau tarik gagang biru di sudut kanan bawah QR</span>
              <button type="button" id="esign-att-toggle" class="h-9 px-4 rounded-xl bg-slate-100 text-slate-700 text-sm font-medium hover:bg-slate-200">Sembunyikan kotak pengesahan</button>
            </div>
            <p class="text-xs text-slate-500 mt-2">Mode strip: preview menampilkan dokumen sumber; kotak pengesahan dan QR digeser di atasnya, lalu dirender pada posisi tersebut saat approve. Mode halaman: preview sudah termasuk halaman pengesahan.</p>
            <p class="text-xs text-slate-500 mt-2" id="esign-page-note"<?= $initialUsePdf ? '' : ' style="display:none"' ?>>Pratinjau halaman terakhir.</p>
            <input type="hidden" name="esign_token" value="<?= e($esignToken) ?>">
            <input type="hidden" name="esign_x" id="esign-x" value="0.70">
            <input type="hidden" name="esign_y" id="esign-y" value="0.70">
            <input type="hidden" name="esign_size" id="esign-size" value="0.20">
            <input type="hidden" name="esign_qr_data" id="esign-qr-data">
            <input type="hidden" name="att_x" id="att-x" value="">
            <input type="hidden" name="att_y" id="att-y" value="">
            <input type="hidden" name="att_w" id="att-w" value="">
            <input type="hidden" name="att_h" id="att-h" value="">
            <input type="hidden" name="att_custom" id="att-custom" value="0">
          </div>
        </div>
        <style>#esign-qr canvas, #esign-qr img { width: 100% !important; height: 100% !important; display: block; pointer-events: none; } #esign-att-box img, #esign-att-box canvas { pointer-events: none; }</style>
        <script src="assets/qrcode.min.js"></script>
        <script src="assets/pdf.min.js"></script>
        <script>
        (function () {
          var wrap = document.getElementById('esign-preview-wrap');
          var box = document.getElementById('esign-qr-box');
          var handle = document.getElementById('esign-qr-handle');
          if (!wrap || !box) return;
          var attBox = document.getElementById('esign-att-box');
          var attHandle = document.getElementById('esign-att-handle');
          var attContent = document.getElementById('esign-att-content');
          var attTpls = <?= json_encode($attTplMap, JSON_UNESCAPED_UNICODE) ?>;
          var attVars = <?= json_encode($attVars, JSON_UNESCAPED_UNICODE) ?>;
          var attDefaultTplId = <?= (int)$attDefaultTplId ?>;
          var attFontCss = { 'arial': 'Arial, sans-serif', 'notosanssc': "'Noto Sans SC', sans-serif", 'dejavusans': "'DejaVu Sans', sans-serif" };
          var attCustom = false;
          var attHidden = false;
          function attBaseFontPx() {
            var w = wrap ? wrap.clientWidth : 0;
            return w > 0 ? w * 0.0185 : 14.67;
          }
          var attDef = { l: 0.0714, t: 0.7, w: 0.8571, h: 0.2 };
          var qrEl = document.getElementById('esign-qr');
          var verifyUrl = <?= json_encode($esignVerifyUrl) ?>;
          var previewBase = <?= json_encode($esignPreviewBase) ?>;
          function currentTplId() {
            var tpl = document.querySelector('select[name="template_id"]');
            if (tpl && tpl.value) return parseInt(tpl.value, 10);
            return attDefaultTplId;
          }
          function currentTplPlacement() {
            var sel = document.getElementById('tpl-select');
            if (sel && sel.options[sel.selectedIndex]) return sel.options[sel.selectedIndex].dataset.placement || 'strip';
            var t = attTpls[currentTplId()];
            return t ? t.placement : 'strip';
          }
          function attMode() {
            var t = attTpls[currentTplId()];
            if (!t || !t.body || t.body.replace(/<[^>]*>/g, '').trim() === '') return false;
            return currentTplPlacement() === 'strip';
          }
          function buildPreviewUrl() {
            var pages = document.querySelector('input[name="blanko_pages"]:checked');
            var tpl = document.querySelector('select[name="template_id"]');
            var url = previewBase + '&pages=' + encodeURIComponent(pages ? pages.value : 'last') + '&template_id=' + encodeURIComponent(tpl ? tpl.value : '0');
            if (attMode()) url += '&raw=1';
            return url;
          }
          new QRCode(qrEl, { text: verifyUrl, width: 400, height: 400, correctLevel: QRCode.CorrectLevel.M });
          function syncInputs() {
            var r = wrap.getBoundingClientRect();
            if (r.width === 0) return;
            var b = box.getBoundingClientRect();
            document.getElementById('esign-x').value = ((b.left - r.left) / r.width).toFixed(4);
            document.getElementById('esign-y').value = ((b.top - r.top) / r.height).toFixed(4);
            document.getElementById('esign-size').value = (b.width / r.width).toFixed(4);
          }
          function escAtt(s) {
            return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
          }
          function attFontFamily(t) {
            if (!t) return attFontCss['arial'];
            if (t.font && attFontCss[t.font]) return attFontCss[t.font];
            var lp = (t.lang || '').toLowerCase();
            if (lp.indexOf('mandarin') !== -1 || lp.indexOf('cina') !== -1 || lp.indexOf('tiongkok') !== -1) return attFontCss['notosanssc'];
            return attFontCss['arial'];
          }
          function renderAttOverlay() {
            if (!attBox || !attContent) return;
            if (box) box.style.visibility = esignPageIsTarget(esignPdfPage, esignQrTargetPage()) ? '' : 'hidden';
            if (!attMode()) { attBox.style.display = 'none'; return; }
            var t = attTpls[currentTplId()];
            if (!t) { attBox.style.display = 'none'; return; }
            attContent.innerHTML = t.body.replace(/\{(\w+)\}/g, function(m, k) {
              return (k in attVars) ? escAtt(attVars[k]) : m;
            });
            attContent.style.fontFamily = attFontFamily(t);
            attBox.style.display = '';
            if (!attCustom) placeAttDefault();
            if (attHidden) attBox.style.display = 'none';
            if (!esignPageIsTarget(esignPdfPage, esignStripTargetPage())) attBox.style.display = 'none';
          }
          function placeAttDefault() {
            if (!attBox || !attContent) return;
            attBox.style.left = '7.14%';
            attBox.style.width = '85.71%';
            attBox.style.height = 'auto';
            var r = wrap.getBoundingClientRect();
            if (r.width === 0 || r.height === 0) return;
            var fs = attBaseFontPx();
            attContent.style.fontSize = fs.toFixed(2) + 'px';
            var bh = attBox.getBoundingClientRect().height;
            for (var i = 0; i < 3 && bh > r.height && bh > 0; i++) {
              fs = fs * r.height / bh;
              attContent.style.fontSize = fs.toFixed(2) + 'px';
              bh = attBox.getBoundingClientRect().height;
            }
            var top = Math.max(0, 1 - bh / r.height);
            attBox.style.top = (top * 100) + '%';
            attDef = { l: 0.0714, t: top, w: 0.8571, h: bh / r.height };
            syncAttInputs();
          }
          function syncAttInputs() {
            var r = wrap.getBoundingClientRect();
            if (r.width === 0 || r.height === 0 || !attBox || attBox.style.display === 'none') return;
            var b = attBox.getBoundingClientRect();
            var l = (b.left - r.left) / r.width, t = (b.top - r.top) / r.height;
            var w = b.width / r.width, h = b.height / r.height;
            document.getElementById('att-x').value = l.toFixed(4);
            document.getElementById('att-y').value = t.toFixed(4);
            document.getElementById('att-w').value = w.toFixed(4);
            document.getElementById('att-h').value = h.toFixed(4);
            var changed = Math.abs(l - attDef.l) > 0.005 || Math.abs(t - attDef.t) > 0.005
              || Math.abs(w - attDef.w) > 0.005 || Math.abs(h - attDef.h) > 0.005;
            if (changed) attCustom = true;
            document.getElementById('att-custom').value = attCustom ? '1' : '0';
          }
          function fitAttContent() {
            if (!attBox || !attContent) return;
            var savedH = attBox.style.height;
            attBox.style.height = 'auto';
            var base = attBaseFontPx();
            attContent.style.fontSize = base.toFixed(2) + 'px';
            var nat = attContent.getBoundingClientRect().height;
            attBox.style.height = savedH;
            var bh = attBox.getBoundingClientRect().height;
            if (nat > bh && bh > 0) attContent.style.fontSize = (base * bh / nat).toFixed(2) + 'px';
          }
          var drag = null;
          box.addEventListener('pointerdown', function (ev) {
            if (handle.contains(ev.target)) return;
            var r = wrap.getBoundingClientRect(); var b = box.getBoundingClientRect();
            drag = { dx: ev.clientX - b.left, dy: ev.clientY - b.top };
            box.setPointerCapture(ev.pointerId);
          });
          box.addEventListener('pointermove', function (ev) {
            if (!drag) return;
            var r = wrap.getBoundingClientRect();
            var s = box.getBoundingClientRect().width / r.width;
            var x = (ev.clientX - drag.dx - r.left) / r.width;
            var y = (ev.clientY - drag.dy - r.top) / r.height;
            x = Math.max(0, Math.min(1 - s, x)); y = Math.max(0, Math.min(1 - s, y));
            box.style.left = (x * 100) + '%'; box.style.top = (y * 100) + '%';
            syncInputs();
          });
          function endDrag() { drag = null; syncInputs(); }
          box.addEventListener('pointerup', endDrag);
          box.addEventListener('pointercancel', endDrag);
          var rs = null;
          handle.addEventListener('pointerdown', function (ev) {
            ev.stopPropagation();
            var r = wrap.getBoundingClientRect();
            rs = { x0: ev.clientX, w0: box.getBoundingClientRect().width / r.width };
            handle.setPointerCapture(ev.pointerId);
          });
          handle.addEventListener('pointermove', function (ev) {
            if (!rs) return;
            var r = wrap.getBoundingClientRect();
            var s = rs.w0 + (ev.clientX - rs.x0) / r.width;
            s = Math.max(0.08, Math.min(0.5, s));
            box.style.width = (s * 100) + '%';
            var x = parseFloat(box.style.left) / 100, y = parseFloat(box.style.top) / 100;
            if (x + s > 1) box.style.left = ((1 - s) * 100) + '%';
            if (y + s > 1) box.style.top = ((1 - s) * 100) + '%';
            syncInputs();
          });
          handle.addEventListener('pointerup', function () { rs = null; syncInputs(); });
          handle.addEventListener('pointercancel', function () { rs = null; });
          var attDrag = null;
          if (attBox) attBox.addEventListener('pointerdown', function (ev) {
            if (attHandle && attHandle.contains(ev.target)) return;
            var r = wrap.getBoundingClientRect(); var b = attBox.getBoundingClientRect();
            attDrag = { dx: ev.clientX - b.left, dy: ev.clientY - b.top };
            attBox.setPointerCapture(ev.pointerId);
          });
          if (attBox) attBox.addEventListener('pointermove', function (ev) {
            if (!attDrag) return;
            var r = wrap.getBoundingClientRect();
            var w = attBox.getBoundingClientRect().width / r.width;
            var h = attBox.getBoundingClientRect().height / r.height;
            var x = (ev.clientX - attDrag.dx - r.left) / r.width;
            var y = (ev.clientY - attDrag.dy - r.top) / r.height;
            x = Math.max(0, Math.min(1 - w, x)); y = Math.max(0, Math.min(1 - h, y));
            attBox.style.left = (x * 100) + '%'; attBox.style.top = (y * 100) + '%';
            syncAttInputs();
          });
          function endAttDrag() { attDrag = null; syncAttInputs(); }
          if (attBox) { attBox.addEventListener('pointerup', endAttDrag); attBox.addEventListener('pointercancel', endAttDrag); }
          var attRs = null;
          if (attHandle) attHandle.addEventListener('pointerdown', function (ev) {
            ev.stopPropagation();
            var r = wrap.getBoundingClientRect();
            attRs = { x0: ev.clientX, y0: ev.clientY, w0: attBox.getBoundingClientRect().width / r.width, h0: attBox.getBoundingClientRect().height / r.height, l0: parseFloat(attBox.style.left) / 100, t0: parseFloat(attBox.style.top) / 100 };
            attHandle.setPointerCapture(ev.pointerId);
          });
          if (attHandle) attHandle.addEventListener('pointermove', function (ev) {
            if (!attRs) return;
            var r = wrap.getBoundingClientRect();
            var w = Math.max(0.2, Math.min(1 - attRs.l0, attRs.w0 + (ev.clientX - attRs.x0) / r.width));
            var h = Math.max(0.05, Math.min(1 - attRs.t0, attRs.h0 + (ev.clientY - attRs.y0) / r.height));
            attBox.style.width = (w * 100) + '%';
            attBox.style.height = (h * 100) + '%';
            syncAttInputs();
          });
          function endAttRs() { attRs = null; syncAttInputs(); fitAttContent(); }
          if (attHandle) { attHandle.addEventListener('pointerup', endAttRs); attHandle.addEventListener('pointercancel', function () { attRs = null; }); }
          function currentQrSize() {
            var r = wrap.getBoundingClientRect();
            if (r.width > 0) return box.getBoundingClientRect().width / r.width;
            var v = parseFloat(document.getElementById('esign-size').value);
            return isNaN(v) ? 0.2 : v;
          }
          function setQrSize(s) {
            if (isNaN(s)) return;
            s = Math.max(0.08, Math.min(0.5, s));
            box.style.width = (s * 100) + '%';
            var x = parseFloat(box.style.left) / 100, y = parseFloat(box.style.top) / 100;
            if (isNaN(x)) x = 0.7; if (isNaN(y)) y = 0.7;
            if (x + s > 1) box.style.left = ((1 - s) * 100) + '%';
            if (y + s > 1) box.style.top = ((1 - s) * 100) + '%';
            syncInputs();
          }
          var btnDown = document.getElementById('esign-size-down');
          var btnUp = document.getElementById('esign-size-up');
          if (btnDown) btnDown.addEventListener('click', function () { setQrSize(currentQrSize() - 0.02); });
          if (btnUp) btnUp.addEventListener('click', function () { setQrSize(currentQrSize() + 0.02); });
          var attToggle = document.getElementById('esign-att-toggle');
          if (attToggle) attToggle.addEventListener('click', function () {
            attHidden = !attHidden;
            renderAttOverlay();
            attToggle.textContent = attHidden ? 'Tampilkan kotak pengesahan' : 'Sembunyikan kotak pengesahan';
          });
          var attResizeTimer = null;
          window.addEventListener('resize', function () {
            if (attResizeTimer) clearTimeout(attResizeTimer);
            attResizeTimer = setTimeout(function () { if (!attCustom) placeAttDefault(); }, 150);
          });
          var isPdfSource = <?= $isPdf ? 'true' : 'false' ?>;
          var tplDefaultIsHtml = <?= ($defaultTplForm && trim((string)($defaultTplForm['body_html'] ?? '')) !== '') ? 'true' : 'false' ?>;
          function currentTplIsHtml() {
            var sel = document.getElementById('tpl-select');
            if (sel && sel.options[sel.selectedIndex]) return sel.options[sel.selectedIndex].dataset.isHtml === '1';
            return tplDefaultIsHtml;
          }
          function usePdfPreview() { return isPdfSource || currentTplIsHtml(); }
          function showPreview() {
            var pdfMode = usePdfPreview();
            var canvas = document.getElementById('esign-pdf-canvas');
            var img = document.getElementById('esign-preview-img');
            var note = document.getElementById('esign-page-note');
            if (canvas) canvas.style.display = pdfMode ? '' : 'none';
            if (img) img.style.display = pdfMode ? 'none' : '';
            if (note) note.style.display = pdfMode ? '' : 'none';
            if (pdfMode) { loadEsignPreview(); }
            else if (img) { img.src = buildPreviewUrl(); img.onload = function () { if (!attCustom) placeAttDefault(); }; }
            renderAttOverlay();
          }
          var pdfDoc = null, pdfPageCount = 0, esignPdfPage = 0;
          var pageNote = document.getElementById('esign-page-note');
          function renderEsignPage(n) {
            if (!pdfDoc) return;
            esignPdfPage = n;
            pdfDoc.getPage(n).then(function (page) {
              var canvas = document.getElementById('esign-pdf-canvas');
              var ctx = canvas.getContext('2d');
              var vp = page.getViewport({ scale: 1 });
              var scale = wrap.clientWidth / vp.width;
              var vp2 = page.getViewport({ scale: scale });
              canvas.width = vp2.width; canvas.height = vp2.height;
              page.render({ canvasContext: ctx, viewport: vp2 }).promise.then(function () { syncInputs(); if (!attCustom) placeAttDefault(); renderAttOverlay(); });
              updateNav();
              if (pageNote) {
                if (esignPageIsTarget(n, esignQrTargetPage())) {
                  if (blankoChoice() === 'all' && !(currentTplIsHtml() && currentTplPlacement() === 'page')) pageNote.textContent = 'QR akan dibubuhkan di semua halaman pada posisi yang sama (pratinjau: halaman ' + n + ').';
                  else pageNote.textContent = 'Pratinjau halaman ' + n + ' dari ' + pdfPageCount + '.';
                } else {
                  pageNote.textContent = 'Halaman ' + n + ' dari ' + pdfPageCount + ' — pratinjau saja, bukan halaman target blanko.';
                }
              }
            });
          }
          var previewSeq = 0;
          function loadEsignPreview() {
            var my = ++previewSeq;
            if (typeof pdfjsLib === 'undefined') {
              if (pageNote) pageNote.textContent = 'Preview gagal dimuat: pustaka PDF tidak termuat. Muat ulang halaman.';
              return;
            }
            pdfjsLib.getDocument(buildPreviewUrl()).promise.then(function (doc) {
              if (my !== previewSeq) return;
              pdfDoc = doc; pdfPageCount = doc.numPages;
              var choice = document.querySelector('input[name="blanko_pages"]:checked');
              var v = choice ? choice.value : 'last';
              if (v === 'first') renderEsignPage(1);
              else if (v === 'all') { renderEsignPage(1); if (pageNote) pageNote.textContent = 'QR akan dibubuhkan di semua halaman pada posisi yang sama (pratinjau: halaman 1).'; }
              else renderEsignPage(pdfPageCount);
            }).catch(function (err) {
              if (my !== previewSeq) return;
              if (pageNote) pageNote.textContent = 'Preview gagal dimuat (' + (err && err.message ? err.message : 'kesalahan tidak diketahui') + '). Coba muat ulang halaman atau ganti template.';
            });
          }
          var navRow = document.getElementById('esign-page-nav');
          var navInd = document.getElementById('esign-page-ind');
          var navPrev = document.getElementById('esign-page-prev');
          var navNext = document.getElementById('esign-page-next');
          var navTarget = document.getElementById('esign-page-target');
          function blankoChoice() { var r = document.querySelector('input[name="blanko_pages"]:checked'); return r ? r.value : 'last'; }
          function esignQrTargetPage() {
            if (currentTplIsHtml() && currentTplPlacement() === 'page') return pdfPageCount;
            var v = blankoChoice();
            if (v === 'first') return 1;
            if (v === 'last') return pdfPageCount;
            return 0;
          }
          function esignStripTargetPage() {
            var v = blankoChoice();
            if (v === 'first') return 1;
            if (v === 'last') return pdfPageCount;
            return 0;
          }
          function esignPageIsTarget(n, target) {
            if (!pdfDoc || n < 1 || target === 0) return true;
            return n === target;
          }
          function updateNav() {
            if (navRow) navRow.style.display = (pdfDoc && pdfPageCount > 1) ? '' : 'none';
            if (navInd) navInd.textContent = 'Halaman ' + (esignPdfPage || 1) + ' dari ' + (pdfPageCount || 1);
            if (navPrev) { navPrev.disabled = (esignPdfPage <= 1); navPrev.style.opacity = navPrev.disabled ? '0.4' : ''; }
            if (navNext) { navNext.disabled = (esignPdfPage >= pdfPageCount); navNext.style.opacity = navNext.disabled ? '0.4' : ''; }
            if (navTarget) navTarget.style.display = (pdfDoc && pdfPageCount > 1 && !esignPageIsTarget(esignPdfPage, esignQrTargetPage())) ? '' : 'none';
          }
          if (navPrev) navPrev.addEventListener('click', function () { if (esignPdfPage > 1) renderEsignPage(esignPdfPage - 1); });
          if (navNext) navNext.addEventListener('click', function () { if (esignPdfPage < pdfPageCount) renderEsignPage(esignPdfPage + 1); });
          if (navTarget) navTarget.addEventListener('click', function () { var t = esignQrTargetPage(); renderEsignPage(t > 0 ? t : 1); });
          pdfjsLib.GlobalWorkerOptions.workerSrc = 'assets/pdf.worker.min.js';
          showPreview();
          document.querySelectorAll('input[name="blanko_pages"]').forEach(function (r) {
            r.addEventListener('change', showPreview);
          });
          var tplSel = document.querySelector('select[name="template_id"]');
          if (tplSel) tplSel.addEventListener('change', function () {
            attCustom = false;
            document.getElementById('att-custom').value = '0';
            showPreview();
          });
          if (window.ResizeObserver) {
            var lastW = 0;
            new ResizeObserver(function () {
              var w = wrap.clientWidth;
              if (w > 0 && w !== lastW) {
                lastW = w;
                if (usePdfPreview() && pdfDoc) renderEsignPage(esignPdfPage || pdfPageCount);
                syncInputs(); if (!attCustom) placeAttDefault();
              }
            }).observe(wrap);
          }
          var form = box.closest('form');
          if (form) form.addEventListener('submit', function () {
            var cv = qrEl.querySelector('canvas');
            if (cv) document.getElementById('esign-qr-data').value = cv.toDataURL('image/png');
          });
          syncInputs();
        })();
        </script>
        <?php elseif ($isPenerjemah): ?>
        <p class="text-sm text-slate-600 bg-slate-50 rounded-2xl px-4 py-3">Belum ada TTD elektronik terekam — <a href="?page=settings" class="text-indigo-600 font-medium">rekam di Pengaturan</a>. Approve memakai QR e-sign (simulasi) dan membutuhkan TTD terekam.</p>
        <?php else: ?>
        <p class="text-sm text-slate-600 bg-slate-50 rounded-2xl px-4 py-3">TTD elektronik penerjemah belum direkam, sehingga dokumen ini belum bisa disetujui.</p>
        <?php endif; ?>
        <?php if ($esignToken): ?>
        <button id="esign-approve-btn" class="w-full h-12 bg-emerald-600 text-white rounded-2xl font-semibold hover:bg-emerald-500 shadow-lg shadow-emerald-600/25 active:scale-[.98] transition">
          <i class="fa-solid fa-qrcode mr-2"></i>Approve & Bubuhkan QR e-sign
        </button>
        <?php endif; ?>
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
