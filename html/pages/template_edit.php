<?php
// Editor template blanko (tambah/edit) + live preview
declare(strict_types=1);
$u = require_role('penerjemah');
$id = (int)($_GET['id'] ?? 0);

if ($id && isset($_GET['delete'])) {
    csrf_check();
    db()->prepare('DELETE FROM blanko_templates WHERE id = ? AND is_default = 0')->execute([$id]);
    flash('Template dihapus.');
    redirect('?page=templates');
}
if ($id && isset($_GET['setdefault'])) {
    csrf_check();
    db()->exec('UPDATE blanko_templates SET is_default = 0');
    db()->prepare('UPDATE blanko_templates SET is_default = 1 WHERE id = ?')->execute([$id]);
    flash('Template dijadikan default.');
    redirect('?page=templates');
}

$t = null; $cfg = [];
if ($id) {
    $st = db()->prepare('SELECT * FROM blanko_templates WHERE id = ?');
    $st->execute([$id]);
    $t = $st->fetch();
    if (!$t) { http_response_code(404); exit('Template tidak ditemukan.'); }
    $cfg = json_decode($t['config'] ?? '{}', true) ?: [];
}

$defaults = [
    'statement_id' => 'Saya, {name}, Penerjemah Tersumpah di Republik Indonesia berdasarkan peraturan perundang-undangan yang berlaku di Republik Indonesia, dengan ini menerangkan dan menyatakan, sesuai dengan sumpah jabatan saya, bahwa dokumen ini merupakan terjemahan yang benar, setia, dan lengkap dari dokumen sumber yang diberikan kepada saya.',
    'statement_foreign' => '',
    'foreign_rtl' => false,
    'use_foreign_image' => false,
    'font_size_base' => 13,
    'font_size_name' => 14,
    'font_size_foreign' => 15,
    'line_height' => 1.35,
    'show_border' => true,
    'columns' => 2,
    'show_stamp' => true,
    'stamp_size' => 200,
    'stamp_dx' => 0,
    'stamp_dy' => 0,
    'show_foreign_name' => false,
];
$cfg = array_merge($defaults, $cfg);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $lang_pair = trim($_POST['lang_pair'] ?? '');
    if ($name === '') { flash('Nama template wajib diisi.', 'err'); }
    else {
        $newCfg = [
            'statement_id' => trim($_POST['statement_id'] ?? ''),
            'statement_foreign' => trim($_POST['statement_foreign'] ?? ''),
            'foreign_rtl' => isset($_POST['foreign_rtl']) ? true : false,
            'use_foreign_image' => isset($_POST['use_foreign_image']) ? true : false,
            'font_size_base' => max(8, min(24, (int)($_POST['font_size_base'] ?? 13))),
            'font_size_name' => max(8, min(28, (int)($_POST['font_size_name'] ?? 14))),
            'font_size_foreign' => max(8, min(28, (int)($_POST['font_size_foreign'] ?? 15))),
            'line_height' => max(1.0, min(2.5, (float)($_POST['line_height'] ?? 1.35))),
            'show_border' => isset($_POST['show_border']),
            'columns' => (int)($_POST['columns'] ?? 2) === 1 ? 1 : 2,
            'show_stamp' => isset($_POST['show_stamp']),
            'stamp_size' => max(80, min(400, (int)($_POST['stamp_size'] ?? 200))),
            'stamp_dx' => max(-600, min(600, (int)($_POST['stamp_dx'] ?? 0))),
            'stamp_dy' => max(-300, min(300, (int)($_POST['stamp_dy'] ?? 0))),
            'show_foreign_name' => isset($_POST['show_foreign_name']),
            'align_id' => in_array($_POST['align_id'] ?? '', ['left','center','right','justify'], true) ? $_POST['align_id'] : 'justify',
            'align_foreign' => in_array($_POST['align_foreign'] ?? '', ['left','center','right','justify'], true) ? $_POST['align_foreign'] : 'justify',
        ];
        if ($id) {
            $st = db()->prepare('UPDATE blanko_templates SET name=?, lang_pair=?, config=?, updated_at=datetime("now") WHERE id=?');
            $st->execute([$name, $lang_pair, json_encode($newCfg, JSON_UNESCAPED_UNICODE), $id]);
            flash('Template diperbarui.');
        } else {
            $st = db()->prepare('INSERT INTO blanko_templates (name, lang_pair, config) VALUES (?,?,?)');
            $st->execute([$name, $lang_pair, json_encode($newCfg, JSON_UNESCAPED_UNICODE)]);
            $id = (int)db()->lastInsertId();
            flash('Template ditambahkan.');
        }
        redirect('?page=template_edit&id=' . $id);
    }
}

ob_start(); ?>
<div class="max-w-6xl mx-auto grid lg:grid-cols-2 gap-6">
<div class="bg-white rounded-3xl shadow-sm p-6">
  <h1 class="text-xl font-bold mb-1"><?= $id ? 'Edit Template' : 'Tambah Template' ?></h1>
  <p class="text-sm text-slate-500 mb-4">Gunakan <code>{name}</code> untuk nama penerjemah otomatis.</p>
  <form method="post" class="space-y-4" id="tpl-form">
    <?= csrf_field() ?>
    <div class="grid sm:grid-cols-2 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Nama template</label>
        <input name="name" required value="<?= e($t['name'] ?? '') ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Indonesia - Inggris"></div>
      <div><label class="block text-sm font-medium mb-1.5">Pasangan bahasa</label>
        <input name="lang_pair" value="<?= e($t['lang_pair'] ?? '') ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none" placeholder="Bahasa Indonesia ke Bahasa Inggris"></div>
    </div>
    <div><label class="block text-sm font-medium mb-1.5">Pernyataan Bahasa Indonesia</label>
      <textarea name="statement_id" rows="4" class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"><?= e($cfg['statement_id']) ?></textarea></div>
    <div><label class="block text-sm font-medium mb-1.5">Pernyataan Bahasa Asing (kosongkan jika tidak ada)</label>
      <textarea name="statement_foreign" rows="4" dir="auto" class="w-full bg-slate-50 border-0 rounded-2xl px-4 py-3 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"><?= e($cfg['statement_foreign']) ?></textarea></div>
    <div class="grid sm:grid-cols-3 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Ukuran font isi</label>
        <input type="number" name="font_size_base" min="8" max="24" value="<?= (int)$cfg['font_size_base'] ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Ukuran font nama</label>
        <input type="number" name="font_size_name" min="8" max="28" value="<?= (int)$cfg['font_size_name'] ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Ukuran font asing</label>
        <input type="number" name="font_size_foreign" min="8" max="28" value="<?= (int)$cfg['font_size_foreign'] ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
    </div>
    <div class="grid sm:grid-cols-3 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Jarak baris</label>
        <input type="number" name="line_height" step="0.05" min="1" max="2.5" value="<?= e((string)$cfg['line_height']) ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Ukuran cap (px)</label>
        <input type="number" name="stamp_size" min="80" max="400" value="<?= (int)$cfg['stamp_size'] ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Geser cap X (px)</label>
        <input type="number" id="stamp_dx" name="stamp_dx" min="-600" max="600" value="<?= (int)($cfg['stamp_dx'] ?? 0) ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
      <div><label class="block text-sm font-medium mb-1.5">Geser cap Y (px)</label>
        <input type="number" id="stamp_dy" name="stamp_dy" min="-300" max="300" value="<?= (int)($cfg['stamp_dy'] ?? 0) ?>" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none"></div>
    </div>
    <div class="grid sm:grid-cols-3 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Kolom</label>
        <select name="columns" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          <option value="2" <?= (int)$cfg['columns'] === 2 ? 'selected' : '' ?>>2 kolom (ID | Asing)</option>
          <option value="1" <?= (int)$cfg['columns'] === 1 ? 'selected' : '' ?>>1 kolom</option>
        </select></div>
    </div>
    <div class="grid sm:grid-cols-2 gap-4">
      <div><label class="block text-sm font-medium mb-1.5">Rata teks Indonesia</label>
        <select name="align_id" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          <?php foreach (['left'=>'Kiri','center'=>'Tengah','right'=>'Kanan','justify'=>'Justify'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($cfg['align_id'] ?? 'justify') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select></div>
      <div><label class="block text-sm font-medium mb-1.5">Rata teks asing</label>
        <select name="align_foreign" class="w-full h-11 bg-slate-50 border-0 rounded-2xl px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
          <?php foreach (['left'=>'Kiri','center'=>'Tengah','right'=>'Kanan','justify'=>'Justify'] as $v=>$l): ?>
          <option value="<?= $v ?>" <?= ($cfg['align_foreign'] ?? 'justify') === $v ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select></div>
    </div>
    <div class="flex flex-wrap gap-4 text-sm">
      <label><input type="checkbox" name="show_border" <?= $cfg['show_border'] ? 'checked' : '' ?> class="mr-2">Garis atas</label>
      <label><input type="checkbox" name="show_stamp" <?= $cfg['show_stamp'] ? 'checked' : '' ?> class="mr-2">Tampilkan cap</label>
      <label><input type="checkbox" name="foreign_rtl" <?= $cfg['foreign_rtl'] ? 'checked' : '' ?> class="mr-2">Teks asing RTL</label>
      <label><input type="checkbox" name="use_foreign_image" <?= $cfg['use_foreign_image'] ? 'checked' : '' ?> class="mr-2">Arab pakai gambar</label>
      <label><input type="checkbox" name="show_foreign_name" <?= $cfg['show_foreign_name'] ? 'checked' : '' ?> class="mr-2">Nama Arab kanan bawah</label>
    </div>
    <div class="flex gap-3">
      <button class="h-11 px-6 bg-indigo-600 text-white rounded-2xl font-semibold hover:bg-indigo-500 shadow-lg shadow-indigo-600/25"><i class="fa-solid fa-save mr-2"></i>Simpan</button>
      <a href="?page=templates" class="h-11 px-6 rounded-2xl border border-slate-200 flex items-center hover:bg-slate-50">Kembali</a>
    </div>
  </form>
</div>
<div class="bg-white rounded shadow p-6 lg:sticky lg:top-6 self-start">
  <h2 class="text-lg font-bold mb-1">Live Preview</h2>
  <p class="text-xs text-slate-500 mb-3">Render persis hasil akhir (data contoh). <span class="text-indigo-600">Drag bingkai untuk geser, drag sudut kanan-bawah untuk ubah ukuran cap.</span></p>
  <div id="tpl-preview-wrap" class="relative border rounded overflow-hidden bg-slate-100 min-h-[200px]">
    <img id="tpl-preview" alt="Preview blanko" class="w-full h-auto block select-none" draggable="false">
    <div id="stamp-handle" class="absolute border-2 border-dashed border-indigo-500 bg-indigo-500/10 cursor-move hidden touch-none" style="z-index:10" title="Drag untuk geser cap">
      <span class="absolute -top-6 left-0 text-[10px] bg-indigo-600 text-white px-1.5 py-0.5 rounded whitespace-nowrap">cap</span>
      <span id="stamp-resize" class="absolute -bottom-2 -right-2 w-5 h-5 bg-indigo-600 border-2 border-white rounded-full cursor-nwse-resize shadow" title="Drag untuk ubah ukuran"></span>
    </div>
  </div>
  <div class="flex items-center gap-2 mt-3 flex-wrap">
    <button type="button" id="stamp-apply" class="h-9 px-4 rounded-xl bg-emerald-600 text-white text-sm font-semibold hover:bg-emerald-500 disabled:opacity-40" disabled title="Terapkan posisi & ukuran cap"><i class="fa-solid fa-check mr-1.5"></i>Terapkan</button>
    <button type="button" id="stamp-undo" class="h-9 px-4 rounded-xl bg-slate-100 text-slate-700 text-sm font-semibold hover:bg-slate-200 disabled:opacity-40" disabled title="Kembalikan ke posisi sebelumnya"><i class="fa-solid fa-rotate-left mr-1.5"></i>Undo</button>
    <button type="button" id="stamp-edit" class="h-9 px-4 rounded-xl bg-indigo-50 text-indigo-700 text-sm font-semibold hover:bg-indigo-100" title="Tampilkan bingkai untuk atur posisi cap"><i class="fa-solid fa-up-down-left-right mr-1.5"></i>Atur posisi</button>
    <span id="stamp-dirty" class="text-xs text-amber-600 hidden"><i class="fa-solid fa-circle-exclamation mr-1"></i>Belum diterapkan</span>
  </div>
  <div id="tpl-loading" class="text-xs text-slate-400 mt-2 hidden">Merender...</div>
</div>
</div>
<script>
(function() {
  var form = document.getElementById('tpl-form');
  var img = document.getElementById('tpl-preview');
  var wrap = document.getElementById('tpl-preview-wrap');
  var handle = document.getElementById('stamp-handle');
  var resizeEl = document.getElementById('stamp-resize');
  var dxInput = document.getElementById('stamp_dx');
  var dyInput = document.getElementById('stamp_dy');
  var sizeInput = form.querySelector('input[name="stamp_size"]');
  var applyBtn = document.getElementById('stamp-apply');
  var undoBtn = document.getElementById('stamp-undo');
  var editBtn = document.getElementById('stamp-edit');
  var dirtyEl = document.getElementById('stamp-dirty');
  var loading = document.getElementById('tpl-loading');
  var timer = null, seq = 0, lastUrl = null;
  var STRIP_W = 1240, STRIP_H = 380, MX = 89, BOTTOM_PAD = 44;

  // posisi terakhir yang sudah "diterapkan"
  var committed = { dx: 0, dy: 0, size: 200 };
  var handleDismissed = false; // true setelah Terapkan ditekan
  function readInputs() {
    return {
      dx: parseInt(dxInput.value, 10) || 0,
      dy: parseInt(dyInput.value, 10) || 0,
      size: parseInt(sizeInput.value, 10) || 200
    };
  }
  function isDirty() {
    var c = readInputs();
    return c.dx !== committed.dx || c.dy !== committed.dy || c.size !== committed.size;
  }
  function refreshButtons() {
    var d = isDirty();
    applyBtn.disabled = !d;
    undoBtn.disabled = !d;
    dirtyEl.classList.toggle('hidden', !d);
  }
  function markCommitted() {
    committed = readInputs();
    refreshButtons();
  }

  function render() {
    var mySeq = ++seq;
    loading.classList.remove('hidden');
    var fd = new FormData(form);
    fetch('?page=template_preview', { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r) { if (!r.ok) throw new Error(r.status); return r.blob(); })
      .then(function(b) {
        if (mySeq !== seq) return;
        if (lastUrl) URL.revokeObjectURL(lastUrl);
        lastUrl = URL.createObjectURL(b);
        img.src = lastUrl;
        loading.classList.add('hidden');
      })
      .catch(function() { loading.classList.add('hidden'); });
  }
  function scheduleRender(ms) { clearTimeout(timer); timer = setTimeout(render, ms || 700); }
  form.addEventListener('input', function() { handleDismissed = false; scheduleRender(700); setTimeout(refreshButtons, 50); });
  form.addEventListener('change', function() { handleDismissed = false; scheduleRender(300); setTimeout(refreshButtons, 50); });

  // --- drag & resize cap ---
  function stampVisible() {
    var cb = form.querySelector('input[name="show_stamp"]');
    return cb && cb.checked;
  }
  function positionHandle(force) {
    if (!stampVisible() || !img.clientWidth || (handleDismissed && !force)) { handle.classList.add('hidden'); return; }
    var scale = img.clientWidth / STRIP_W;
    var c = readInputs();
    var x = (STRIP_W - MX - c.size + c.dx) * scale;
    var y = (STRIP_H - c.size - BOTTOM_PAD + c.dy) * scale;
    var s = c.size * scale;
    handle.style.left = x + 'px';
    handle.style.top = y + 'px';
    handle.style.width = s + 'px';
    handle.style.height = s + 'px';
    handle.classList.remove('hidden');
  }
  img.addEventListener('load', function() { positionHandle(); });
  form.addEventListener('change', function() { setTimeout(positionHandle, 900); });
  form.addEventListener('input', function() { setTimeout(positionHandle, 900); });

  // geser (move)
  var dragging = false, startX = 0, startY = 0, startDx = 0, startDy = 0;
  function moveDown(e) {
    if (e.target === resizeEl) return; // biar resize yang handle
    dragging = true;
    var p = e.touches ? e.touches[0] : e;
    startX = p.clientX; startY = p.clientY;
    startDx = parseInt(dxInput.value, 10) || 0;
    startDy = parseInt(dyInput.value, 10) || 0;
    e.preventDefault(); e.stopPropagation();
  }
  function moveMove(e) {
    if (!dragging) return;
    var p = e.touches ? e.touches[0] : e;
    var scale = img.clientWidth / STRIP_W;
    var ndx = Math.round(startDx + (p.clientX - startX) / scale);
    var ndy = Math.round(startDy + (p.clientY - startY) / scale);
    dxInput.value = Math.max(-600, Math.min(600, ndx));
    dyInput.value = Math.max(-300, Math.min(300, ndy));
    positionHandle(); refreshButtons();
    e.preventDefault();
  }
  function moveUp() {
    if (!dragging) return;
    dragging = false;
    scheduleRender(400);
  }

  // ubah ukuran (resize) via sudut kanan-bawah
  var resizing = false, rsX = 0, rsSize = 200;
  function resizeDown(e) {
    resizing = true;
    var p = e.touches ? e.touches[0] : e;
    rsX = p.clientX;
    rsSize = parseInt(sizeInput.value, 10) || 200;
    e.preventDefault(); e.stopPropagation();
  }
  function resizeMove(e) {
    if (!resizing) return;
    var p = e.touches ? e.touches[0] : e;
    var scale = img.clientWidth / STRIP_W;
    var ns = Math.round(rsSize + (p.clientX - rsX) / scale);
    sizeInput.value = Math.max(80, Math.min(400, ns));
    positionHandle(); refreshButtons();
    e.preventDefault();
  }
  function resizeUp() {
    if (!resizing) return;
    resizing = false;
    scheduleRender(400);
  }

  handle.addEventListener('mousedown', moveDown);
  document.addEventListener('mousemove', function(e) { moveMove(e); resizeMove(e); });
  document.addEventListener('mouseup', function() { moveUp(); resizeUp(); });
  handle.addEventListener('touchstart', moveDown, { passive: false });
  document.addEventListener('touchmove', function(e) { moveMove(e); resizeMove(e); }, { passive: false });
  document.addEventListener('touchend', function() { moveUp(); resizeUp(); });
  resizeEl.addEventListener('mousedown', resizeDown);
  resizeEl.addEventListener('touchstart', resizeDown, { passive: false });

  // terapkan & undo
  applyBtn.addEventListener('click', function() {
    markCommitted();
    handleDismissed = true;
    handle.classList.add('hidden'); // sembunyikan bingkai & label setelah diterapkan
    scheduleRender(200);
  });
  undoBtn.addEventListener('click', function() {
    dxInput.value = committed.dx;
    dyInput.value = committed.dy;
    sizeInput.value = committed.size;
    positionHandle(); refreshButtons();
    scheduleRender(200);
  });
  editBtn.addEventListener('click', function() {
    handleDismissed = false;
    positionHandle(true); // tampilkan kembali bingkai
  });

  // init: anggap nilai awal form sebagai committed
  markCommitted();
  render();
})();
</script>
<?php layout($id ? 'Edit Template' : 'Tambah Template', ob_get_clean(), $u); ?>
