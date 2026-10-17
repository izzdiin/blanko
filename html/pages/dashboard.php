<?php
// Dashboard — modern card list, AlpineJS search/filter/pagination
declare(strict_types=1);
$u = require_login();
$isMitra = $u['role'] === 'mitra';

ob_start(); ?>
<div class="flex flex-wrap items-center justify-between gap-3 mb-5">
  <div>
    <h1 class="text-2xl font-bold tracking-tight"><?= $isMitra ? 'Dokumen Saya' : 'Permintaan Pengesahan' ?></h1>
    <p class="text-sm text-slate-500 mt-0.5"><?= $isMitra ? 'Kelola dokumen terjemahan Anda' : 'Review dan sahkan dokumen mitra' ?></p>
  </div>
  <?php if ($isMitra): ?>
    <a href="?page=upload" class="h-11 px-5 rounded-full bg-indigo-600 text-white text-sm font-semibold hover:bg-indigo-500 flex items-center gap-2 shadow-lg shadow-indigo-600/25">
      <i class="fa-solid fa-plus"></i>Upload Dokumen
    </a>
  <?php endif; ?>
</div>

<div x-data="docList()" x-init="load()">
  <!-- Search & filter -->
  <div class="flex gap-2 mb-4">
    <div class="relative flex-1">
      <i class="fa-solid fa-search absolute left-4 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
      <input x-model.debounce.400ms="q" @input="page=1; load()" type="search" placeholder="Cari dokumen..."
             class="w-full h-11 bg-white border-0 rounded-2xl shadow-sm pl-11 pr-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
    </div>
    <select x-model="status" @change="page=1; load()" class="h-11 bg-white border-0 rounded-2xl shadow-sm px-4 text-sm focus:ring-2 focus:ring-indigo-500 outline-none">
      <option value="">Semua</option>
      <option value="pending">Menunggu</option>
      <option value="approved">Disetujui</option>
      <option value="declined">Ditolak</option>
    </select>
  </div>

  <!-- Loading -->
  <div x-show="loading" class="flex justify-center py-12 text-slate-400">
    <i class="fa-solid fa-circle-notch fa-spin text-2xl"></i>
  </div>

  <!-- Cards -->
  <div class="grid gap-3 sm:grid-cols-2" x-show="!loading">
    <template x-for="d in docs" :key="d.id">
      <div class="bg-white rounded-2xl shadow-sm p-4 flex gap-3 hover:shadow-md transition-shadow overflow-hidden">
        <div class="w-11 h-11 rounded-xl grid place-items-center shrink-0"
             :class="d.status==='approved' ? 'bg-emerald-100 text-emerald-600' : d.status==='declined' ? 'bg-red-100 text-red-600' : 'bg-amber-100 text-amber-600'">
          <i class="fa-solid text-lg" :class="d.status==='approved' ? 'fa-circle-check' : d.status==='declined' ? 'fa-circle-xmark' : 'fa-clock'"></i>
        </div>
        <div class="flex-1 min-w-0 overflow-hidden">
          <div class="font-semibold text-[15px] truncate" x-text="d.title"></div>
          <div class="text-xs text-slate-500 truncate mt-0.5">
            <span x-text="d.original_name"></span>
            <?php if (!$isMitra): ?><span> · </span><span x-text="d.mitra_name"></span><?php endif; ?>
          </div>
          <div class="text-xs text-slate-400 mt-0.5" x-text="d.created_at"></div>
          <template x-if="d.status==='declined' && d.decline_reason">
            <div class="text-xs text-red-600 mt-1.5 bg-red-50 rounded-lg px-2.5 py-1.5">Alasan: <span x-text="d.decline_reason"></span></div>
          </template>
          <div class="flex flex-wrap gap-2 mt-3">
            <a :href="'?page=preview&id='+d.id" class="h-9 px-4 rounded-full bg-slate-100 text-slate-700 text-xs font-semibold flex items-center gap-1.5 hover:bg-slate-200">
              <i class="fa-solid fa-eye"></i>Preview
            </a>
            <?php if (!$isMitra): ?>
            <template x-if="d.status==='pending'">
              <a :href="'?page=review&id='+d.id" class="h-9 px-4 rounded-full bg-indigo-600 text-white text-xs font-semibold flex items-center gap-1.5 hover:bg-indigo-500">
                <i class="fa-solid fa-check-to-slot"></i>Review
              </a>
            </template>
            <?php endif; ?>
            <template x-if="d.status==='approved' && d.stamped_name">
              <a :href="'?page=download&id='+d.id" class="h-9 px-4 rounded-full bg-emerald-600 text-white text-xs font-semibold flex items-center gap-1.5 hover:bg-emerald-500">
                <i class="fa-solid fa-download"></i>Unduh
              </a>
            </template>
          </div>
        </div>
      </div>
    </template>
  </div>

  <div x-show="!loading && docs.length===0" class="text-center py-16">
    <div class="w-16 h-16 rounded-2xl bg-slate-100 grid place-items-center mx-auto mb-3 text-slate-400 text-2xl"><i class="fa-solid fa-folder-open"></i></div>
    <p class="text-slate-500 text-sm">Tidak ada dokumen ditemukan.</p>
  </div>

  <!-- Pagination -->
  <div class="flex items-center justify-between mt-5" x-show="pages > 1 && !loading">
    <div class="text-xs text-slate-500"><span x-text="total"></span> dokumen</div>
    <div class="flex items-center gap-1.5">
      <button @click="goto(page-1)" :disabled="page===1" class="w-9 h-9 rounded-full bg-white shadow-sm grid place-items-center disabled:opacity-40"><i class="fa-solid fa-chevron-left text-xs"></i></button>
      <template x-for="p in pageNums()" :key="p">
        <button @click="goto(p)" :class="p===page ? 'bg-indigo-600 text-white shadow-md shadow-indigo-600/25' : 'bg-white shadow-sm'"
                class="w-9 h-9 rounded-full text-sm font-medium" x-text="p"></button>
      </template>
      <button @click="goto(page+1)" :disabled="page===pages" class="w-9 h-9 rounded-full bg-white shadow-sm grid place-items-center disabled:opacity-40"><i class="fa-solid fa-chevron-right text-xs"></i></button>
    </div>
  </div>
</div>

<script>
function docList() {
  return {
    q: '', status: '', page: 1, pages: 1, total: 0, docs: [], loading: false,
    load() {
      this.loading = true;
      const params = new URLSearchParams({ q: this.q, status: this.status, p: this.page });
      fetch('?page=api_docs&' + params, { credentials: 'same-origin' })
        .then(r => r.json())
        .then(j => { this.docs = j.docs; this.page = j.page; this.pages = j.pages; this.total = j.total; this.loading = false; })
        .catch(() => { this.loading = false; });
    },
    goto(p) { p = Math.max(1, Math.min(this.pages, p)); if (p !== this.page) { this.page = p; this.load(); window.scrollTo({top: 0, behavior: 'smooth'}); } },
    pageNums() {
      const out = [], total = this.pages, cur = this.page;
      let s = Math.max(1, cur - 2), e = Math.min(total, cur + 2);
      if (cur <= 3) e = Math.min(total, 5);
      if (cur >= total - 2) s = Math.max(1, total - 4);
      for (let i = s; i <= e; i++) out.push(i);
      return out;
    }
  };
}
</script>
<?php layout('Dashboard', ob_get_clean(), $u); ?>
