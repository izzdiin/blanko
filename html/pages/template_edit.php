<?php
// Editor template blanko HTML (rich text) milik penerjemah + preview HTML langsung.
// Aksi default/hapus lewat POST (tautan GET lama tidak pernah lolos csrf_check).
declare(strict_types=1);
require_once __DIR__ . '/../includes/HtmlAttestation.php';
$u = require_role('penerjemah');
$uid = (int)$u['id'];
$isAdmin = !empty($u['is_admin']);
$id = (int)($_GET['id'] ?? 0);

$t = null;
if ($id) {
    $st = db()->prepare('SELECT * FROM blanko_templates WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) { http_response_code(404); exit('Template tidak ditemukan.'); }
    if (!$isAdmin && (int)($t['user_id'] ?? 0) !== $uid) { http_response_code(403); exit('Template ini milik penerjemah lain.'); }
}

// Aksi default/hapus (POST dari daftar template)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $id && isset($_POST['do'])) {
    csrf_check();
    if ($_POST['do'] === 'delete') {
        if ((int)$t['is_default'] !== 0) { flash('Template default tidak bisa dihapus.', 'err'); }
        else {
            db()->prepare('DELETE FROM blanko_templates WHERE id = ?')->execute([$id]);
            flash('Template dihapus.');
        }
    } elseif ($_POST['do'] === 'setdefault') {
        $owner = (int)($t['user_id'] ?? $uid);
        db()->prepare('UPDATE blanko_templates SET is_default = 0 WHERE user_id = ?')->execute([$owner]);
        db()->prepare('UPDATE blanko_templates SET is_default = 1 WHERE id = ?')->execute([$id]);
        flash('Template dijadikan default.');
    }
    redirect('?page=templates');
}

// Isi awal: dari DB, atau konversi config lama bila kosong, atau starter baru
$formBody = '';
$formPlacement = 'strip';
$formFont = '';
if ($t) {
    $formBody = (string)($t['body_html'] ?? '');
    if (trim(strip_tags($formBody)) === '') {
        $cfg = json_decode($t['config'] ?? '{}', true) ?: [];
        $formBody = html_seed_body($cfg);
    }
    $formPlacement = in_array($t['placement'] ?? '', ['strip','page'], true) ? $t['placement'] : 'strip';
    $formFont = in_array($t['font'] ?? '', ['arial', 'notosanssc', 'dejavusans'], true) ? $t['font'] : '';
} else {
    $formBody = '<p>Saya, {name}, Penerjemah Tersumpah di Republik Indonesia berdasarkan peraturan perundang-undangan yang berlaku di Republik Indonesia, dengan ini menerangkan dan menyatakan, sesuai dengan sumpah jabatan saya, bahwa dokumen ini merupakan terjemahan yang benar, setia, dan lengkap dari dokumen sumber yang diberikan kepada saya.</p>'
        . '<p>{city}, {date}</p>'
        . '<p><strong>{name}</strong></p>'
        . '<p>Penerjemah Tersumpah [{lang_pair}] {sk}. Alamat {address}, Telp.{phone}. Email : {email}.</p>'
        . '<p>No. Reg. Terjemahan : {reg_no}</p>';
}
$formName = $t['name'] ?? '';
$formLang = $t['lang_pair'] ?? '';

// Simpan (POST tanpa 'do')
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['do'])) {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $lang_pair = trim($_POST['lang_pair'] ?? '');
    $placementPost = in_array($_POST['placement'] ?? '', ['strip','page'], true) ? $_POST['placement'] : 'strip';
    $fontPost = in_array($_POST['font'] ?? '', ['arial', 'notosanssc', 'dejavusans'], true) ? $_POST['font'] : '';
    $cleanBody = html_sanitize((string)($_POST['body_html'] ?? ''));
    if ($name === '') { flash('Nama template wajib diisi.', 'err'); }
    elseif (trim(strip_tags($cleanBody)) === '') { flash('Isi template tidak boleh kosong.', 'err'); }
    else {
        if ($id) {
            db()->prepare('UPDATE blanko_templates SET name=?, lang_pair=?, body_html=?, placement=?, font=?, updated_at=datetime("now") WHERE id=?')
                ->execute([$name, $lang_pair, $cleanBody, $placementPost, $fontPost, $id]);
            flash('Template diperbarui.');
        } else {
            $cs = db()->prepare('SELECT COUNT(*) FROM blanko_templates WHERE user_id = ?');
            $cs->execute([$uid]);
            $isDef = ((int)$cs->fetchColumn() === 0) ? 1 : 0;
            db()->prepare('INSERT INTO blanko_templates (name, lang_pair, body_html, placement, font, user_id, is_default) VALUES (?,?,?,?,?,?,?)')
                ->execute([$name, $lang_pair, $cleanBody, $placementPost, $fontPost, $uid, $isDef]);
            $id = (int)db()->lastInsertId();
            flash('Template ditambahkan.');
        }
        redirect('?page=template_edit&id=' . $id);
    }
    $formName = $name; $formLang = $lang_pair; $formBody = $cleanBody; $formPlacement = $placementPost; $formFont = $fontPost;
}

// Data contoh untuk preview (profil penerjemah sendiri)
$ps = db()->prepare('SELECT * FROM users WHERE id = ?');
$ps->execute([$uid]);
$prof = $ps->fetch() ?: [];
$bln = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
$dateId = date('d') . ' ' . $bln[(int)date('n') - 1] . ' ' . date('Y');
$pairSample = $formLang !== '' ? $formLang : ($prof['lang_pair'] ?? '');
$fromSample = $pairSample; $toSample = '';
if (($p = mb_strpos($pairSample, ' ke ')) !== false) {
    $fromSample = mb_substr($pairSample, 0, $p);
    $toSample = mb_substr($pairSample, $p + 4);
}
$sample = [
    'name' => $prof['name'] ?? 'Nama Penerjemah',
    'name_foreign' => ($prof['name_ar'] ?? '') !== '' ? $prof['name_ar'] : ($prof['name_zh'] ?? ''),
    'sk' => $prof['sk'] ?? '',
    'sk_date' => $prof['sk_date'] ?? '',
    'reg_no' => 'TRJ-XXX-0001',
    'date' => $dateId,
    'doc_title' => 'Judul Dokumen (contoh)',
    'lang_pair' => $pairSample,
    'lang_from' => $fromSample,
    'lang_to' => $toSample,
    'city' => $prof['city'] ?? '',
    'address' => $prof['address'] ?? '',
    'phone' => $prof['phone'] ?? '',
    'email' => $prof['email'] ?? '',
];

ob_start(); ?>
<style>
@font-face { font-family: 'Arial'; src: url('?page=font-asset&f=Arial.ttf'); font-display: swap; }
@font-face { font-family: 'Arial'; src: url('?page=font-asset&f=Arial-Bold.ttf'); font-weight: bold; font-display: swap; }
@font-face { font-family: 'Noto Sans SC'; src: url('?page=font-asset&f=NotoSansSC-Regular.ttf'); font-display: swap; }
@font-face { font-family: 'DejaVu Sans'; src: url('?page=font-asset&f=DejaVuSans.ttf'); font-display: swap; }
@font-face { font-family: 'DejaVu Sans'; src: url('?page=font-asset&f=DejaVuSans-Bold.ttf'); font-weight: bold; font-display: swap; }
</style>
<div class="max-w-3xl mx-auto">
<div class="bg-white rounded-3xl shadow-sm p-6">
  <h1 class="text-xl font-bold mb-1"><?= $id ? 'Edit Template' : 'Tambah Template' ?></h1>
  <p class="text-sm text-slate-500 mb-4">Tulis naskah pengesahan seperti di Word. Sisipkan variabel agar datanya terisi otomatis saat approve.</p>
  <form method="post" class="space-y-4" id="tpl-form">
    <?= csrf_field() ?>
    <input type="hidden" name="body_html" id="tpl-body-input">
    <div class="grid sm:grid-cols-2 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Nama template</label>
        <input name="name" required value="<?= e($formName) ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Indonesia - Inggris"></div>
      <div><label class="block text-sm font-medium mb-1.5">Pasangan bahasa</label>
        <input name="lang_pair" value="<?= e($formLang) ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Bahasa Indonesia ke Bahasa Inggris"></div>
    </div>
    <div><label class="block text-sm font-medium mb-1.5">Penempatan pengesahan</label>
      <select name="placement" id="tpl-placement" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        <option value="strip" <?= $formPlacement === 'strip' ? 'selected' : '' ?>>Tempel di bawah halaman terakhir (strip)</option>
        <option value="page" <?= $formPlacement === 'page' ? 'selected' : '' ?>>Halaman pengesahan sendiri di akhir dokumen</option>
      </select></div>
    <div><label class="block text-sm font-medium mb-1.5">Font pengesahan</label>
      <select name="font" id="tpl-font" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
        <option value="" <?= $formFont === '' ? 'selected' : '' ?>>Otomatis (sesuai bahasa)</option>
        <option value="arial" <?= $formFont === 'arial' ? 'selected' : '' ?>>Arial</option>
        <option value="notosanssc" <?= $formFont === 'notosanssc' ? 'selected' : '' ?>>Noto Sans SC</option>
        <option value="dejavusans" <?= $formFont === 'dejavusans' ? 'selected' : '' ?>>DejaVu Sans</option>
      </select></div>
    <div>
      <label class="block text-sm font-medium mb-1.5">Isi pengesahan</label>
      <div class="flex flex-wrap items-center gap-1.5 mb-2">
        <button type="button" data-cmd="bold" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200 font-bold" title="Tebal">B</button>
        <button type="button" data-cmd="italic" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200 italic" title="Miring">I</button>
        <button type="button" data-cmd="underline" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200 underline" title="Garis bawah">U</button>
        <button type="button" data-cmd="justifyLeft" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200" title="Rata kiri"><i class="fa-solid fa-align-left"></i></button>
        <button type="button" data-cmd="justifyCenter" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200" title="Rata tengah"><i class="fa-solid fa-align-center"></i></button>
        <button type="button" data-cmd="justifyRight" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200" title="Rata kanan"><i class="fa-solid fa-align-right"></i></button>
        <button type="button" data-cmd="justifyFull" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200" title="Justify"><i class="fa-solid fa-align-justify"></i></button>
        <button type="button" data-cmd="insertUnorderedList" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200" title="Daftar bullet"><i class="fa-solid fa-list-ul"></i></button>
        <button type="button" data-cmd="insertOrderedList" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200" title="Daftar nomor"><i class="fa-solid fa-list-ol"></i></button>
        <button type="button" data-cmd="formatBlock" data-val="h2" class="h-9 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-bold" title="Judul besar">H2</button>
        <button type="button" data-cmd="formatBlock" data-val="h3" class="h-9 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-bold" title="Judul kecil">H3</button>
        <button type="button" data-cmd="formatBlock" data-val="p" class="h-9 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-bold" title="Paragraf biasa">P</button>
        <button type="button" data-cmd="removeFormat" class="h-9 w-9 rounded-xl bg-slate-100 hover:bg-slate-200" title="Hapus format"><i class="fa-solid fa-eraser"></i></button>
        <select id="tpl-fontsize" class="h-9 rounded-xl bg-slate-100 text-xs px-2 outline-none" title="Ukuran font (pilih teks dulu)">
          <option value="">Ukuran font</option>
          <?php foreach ([10,11,12,13,14,16,18,20,24] as $fs): ?><option value="<?= $fs ?>"><?= $fs ?> pt</option><?php endforeach; ?>
        </select>
        <button type="button" data-dir="rtl" class="h-9 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-bold" title="Arah paragraf: kanan ke kiri (Arab)">RTL</button>
        <button type="button" data-dir="ltr" class="h-9 px-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-bold" title="Arah paragraf: kiri ke kanan">LTR</button>
      </div>
      <div class="flex flex-wrap items-center gap-2 mb-2">
        <select id="tpl-var" class="h-9 rounded-xl bg-slate-100 text-xs px-2 outline-none">
          <option value="">Sisipkan variabel...</option>
          <?php foreach (html_template_vars() as $k => $label): ?><option value="<?= $k ?>">{<?= $k ?>} — <?= e($label) ?></option><?php endforeach; ?>
        </select>
        <span class="text-xs text-slate-400">Variabel terisi otomatis dari profil &amp; dokumen saat approve.</span>
      </div>
      <div id="tpl-editor" contenteditable="true" dir="auto" class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none overflow-y-auto" style="min-height:280px"><?= html_sanitize($formBody) ?></div>
    </div>
    <div class="flex gap-3">
      <button type="button" id="tpl-preview-btn" class="h-9 px-4 text-sm rounded-xl border border-slate-200 hover:bg-slate-50 font-semibold"><i class="fa-solid fa-eye mr-2"></i>Preview</button>
      <button class="h-9 px-4 text-sm bg-indigo-600 text-white rounded-xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25"><i class="fa-solid fa-save mr-2"></i>Simpan</button>
      <a href="?page=templates" class="h-9 px-4 text-sm rounded-xl border border-slate-200 flex items-center hover:bg-slate-50">Kembali</a>
    </div>
  </form>
</div>
<div id="tpl-preview-modal" style="display:none;position:fixed;inset:0;z-index:60;background:rgba(15,23,42,.55);align-items:flex-start;justify-content:center;padding:24px 16px;overflow-y:auto">
  <div style="background:#fff;border-radius:20px;width:100%;max-width:560px;padding:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px">
      <h2 style="font-weight:700;font-size:18px">Preview Template</h2>
      <button type="button" id="tpl-preview-close" style="width:36px;height:36px;border-radius:9999px;background:#f1f5f9;font-size:18px;line-height:1" title="Tutup">×</button>
    </div>
    <p style="font-size:12px;color:#64748b;margin:6px 0 12px">HTML langsung dengan data contoh. Hasil PDF akhir dirender dari HTML yang sama (ilustrasi tata letak, bukan piksel-per-piksel).</p>
    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px">
      <label style="font-size:13px;font-weight:500" for="tpl-papersize">Ukuran kertas</label>
      <select id="tpl-papersize" style="height:40px;border-radius:12px;background:#f8fafc;padding:0 12px;font-size:14px;outline:none">
        <option value="210/297" selected>A4 (210 × 297 mm)</option>
        <option value="297/420">A3 (297 × 420 mm)</option>
        <option value="216/356">Legal (216 × 356 mm)</option>
        <option value="215/330">F4 (215 × 330 mm)</option>
      </select>
    </div>
    <div class="border rounded overflow-hidden bg-slate-100" id="tpl-preview-box" style="aspect-ratio:210/297">
      <div id="tpl-preview-content" class="bg-white h-full overflow-hidden" style="display:flex;flex-direction:column;padding:12mm 15mm;font-size:11pt;line-height:1.5;"></div>
    </div>
    <p id="tpl-preview-note" class="text-xs text-slate-500 mt-2"></p>
    <div style="display:flex;justify-content:flex-end;margin-top:12px">
      <button type="button" id="tpl-preview-close2" class="h-11 px-6 rounded-2xl border border-slate-200 hover:bg-slate-50">Tutup</button>
    </div>
  </div>
</div>
</div>
<script>
(function() {
  var editor = document.getElementById('tpl-editor');
  var hidden = document.getElementById('tpl-body-input');
  var form = document.getElementById('tpl-form');
  var content = document.getElementById('tpl-preview-content');
  var note = document.getElementById('tpl-preview-note');
  var placementSel = document.getElementById('tpl-placement');
  var fontSel = document.getElementById('tpl-font');
  var langInput = document.querySelector('input[name="lang_pair"]');
  var modal = document.getElementById('tpl-preview-modal');
  var paperSel = document.getElementById('tpl-papersize');
  var previewBox = document.getElementById('tpl-preview-box');
  var fontCss = { 'arial': 'Arial, sans-serif', 'notosanssc': "'Noto Sans SC', sans-serif", 'dejavusans': "'DejaVu Sans', sans-serif" };
  function currentFontFamily() {
    var f = fontSel ? fontSel.value : '';
    if (f && fontCss[f]) return fontCss[f];
    var lp = (langInput ? langInput.value : '').toLowerCase();
    if (lp.indexOf('mandarin') !== -1 || lp.indexOf('cina') !== -1 || lp.indexOf('tiongkok') !== -1) return fontCss['notosanssc'];
    return fontCss['arial'];
  }
  function openPreview() { modal.style.display = 'flex'; document.body.style.overflow = 'hidden'; renderPreview(); }
  function closePreview() { modal.style.display = 'none'; document.body.style.overflow = ''; }
  var sample = <?= json_encode($sample, JSON_UNESCAPED_UNICODE) ?>;
  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  function renderPreview() {
    var fam = currentFontFamily();
    editor.style.fontFamily = fam;
    content.style.fontFamily = fam;
    var html = editor.innerHTML.replace(/\{(\w+)\}/g, function(m, k) {
      return (k in sample) ? esc(sample[k]) : m;
    });
    content.innerHTML = html;
    var isStrip = placementSel.value === 'strip';
    content.style.justifyContent = isStrip ? 'flex-end' : 'flex-start';
    var dims = (paperSel ? paperSel.value : '210/297').split('/');
    var paperW = parseFloat(dims[0]) || 210;
    previewBox.style.aspectRatio = paperSel ? paperSel.value : '210/297';
    var bw = previewBox.clientWidth;
    if (bw > 0) {
      var scale = bw / paperW;
      content.style.fontSize = (3.8806 * scale) + 'px';
      var pad = (15 * scale) + 'px';
      content.style.padding = pad + ' ' + pad;
    }
    note.textContent = isStrip
      ? 'Mode strip: konten menempel di bawah halaman. Bila terlalu tinggi (di atas 140 mm), approve akan menolak dan meminta pindah ke mode halaman.'
      : 'Mode halaman: konten menjadi halaman pengesahan sendiri di akhir dokumen.';
  }
  function cmd(c, v) {
    editor.focus();
    document.execCommand(c, false, (v === undefined || v === '') ? null : v);
    renderPreview();
  }
  document.querySelectorAll('[data-cmd]').forEach(function(b) {
    b.addEventListener('click', function() { cmd(b.dataset.cmd, b.dataset.val); });
  });
  var sizeSel = document.getElementById('tpl-fontsize');
  sizeSel.addEventListener('change', function() {
    var px = sizeSel.value;
    if (!px) return;
    editor.focus();
    var sel = window.getSelection();
    if (sel.rangeCount && !sel.getRangeAt(0).collapsed) {
      var r = sel.getRangeAt(0);
      var span = document.createElement('span');
      span.style.fontSize = px + 'pt';
      try { r.surroundContents(span); }
      catch (e) { span.appendChild(r.extractContents()); r.insertNode(span); }
    }
    sizeSel.value = '';
    renderPreview();
  });
  document.querySelectorAll('[data-dir]').forEach(function(b) {
    b.addEventListener('click', function() {
      editor.focus();
      var sel = window.getSelection();
      if (!sel.rangeCount) return;
      var n = sel.anchorNode;
      if (n.nodeType === 3) n = n.parentNode;
      var block = n.closest ? n.closest('p,div,li,td') : null;
      if (block && editor.contains(block)) block.dir = b.dataset.dir;
      renderPreview();
    });
  });
  var varSel = document.getElementById('tpl-var');
  varSel.addEventListener('change', function() {
    if (!varSel.value) return;
    editor.focus();
    document.execCommand('insertText', false, '{' + varSel.value + '}');
    varSel.value = '';
    renderPreview();
  });
  placementSel.addEventListener('change', renderPreview);
  if (fontSel) fontSel.addEventListener('change', renderPreview);
  if (langInput) langInput.addEventListener('input', renderPreview);
  if (paperSel) paperSel.addEventListener('change', renderPreview);
  var previewBtn = document.getElementById('tpl-preview-btn');
  if (previewBtn) previewBtn.addEventListener('click', openPreview);
  var closeBtn = document.getElementById('tpl-preview-close');
  var closeBtn2 = document.getElementById('tpl-preview-close2');
  if (closeBtn) closeBtn.addEventListener('click', closePreview);
  if (closeBtn2) closeBtn2.addEventListener('click', closePreview);
  if (modal) modal.addEventListener('click', function(ev) { if (ev.target === modal) closePreview(); });
  document.addEventListener('keydown', function(ev) { if (ev.key === 'Escape') closePreview(); });
  var tplResizeT = null;
  window.addEventListener('resize', function() {
    if (modal.style.display !== 'flex') return;
    if (tplResizeT) clearTimeout(tplResizeT);
    tplResizeT = setTimeout(renderPreview, 150);
  });
  editor.addEventListener('input', renderPreview);
  form.addEventListener('submit', function() { hidden.value = editor.innerHTML; });
  renderPreview();
})();
</script>
<?php layout($id ? 'Edit Template' : 'Tambah Template', ob_get_clean(), $u); ?>
