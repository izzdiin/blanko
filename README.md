# Blanko

Alat kerja internal untuk **penerjemah tersumpah** dan mitranya: mitra mengunggah
dokumen, penerjemah meninjau dan menyetujui, dokumen terbit dengan bubuhan
strip penerjemah, QR verifikasi, dan nomor register — dan semua pekerjaan yang
disetujui otomatis membentuk **rekap tahunan** untuk laporan berkala ke
Kementerian Hukum sesuai Permenkum 4/2025 Pasal 17 ayat (3) huruf a.

Blanko bukan marketplace dan bukan situs publik: satu-satunya bagian yang bisa
dibuka tanpa login adalah halaman verifikasi tanda tangan.

> **Status tanda tangan elektronik:** saat ini **SIMULASI** — belum terhubung
> ke Penyelenggara Sertifikasi Elektronik (PSrE) berlisensi, dan halaman
> verifikasi menyatakan itu secara eksplisit. Jahitan integrasi PSrE ada di
> `html/includes/esign.php`.

## Fitur utama

- **Alur dokumen**: unggah (PDF/JPG) → tinjau → setujui/tolak dengan alasan.
  Persetujuan membubuhkan strip + QR + gambar tanda tangan ke PDF hasil
  (`.stamped.pdf` / `.stamped.jpg`), menghitung halaman teks sumber, dan
  menyimpan nomor register.
- **Verifikasi publik**: `?page=verify-sign&token=...` — token deterministik
  HMAC-SHA256 per (dokumen, penerjemah); membuka halaman ini tidak mengubah
  data apa pun.
- **Rekap Tahunan Siap Lapor**: entri otomatis dari dokumen yang disetujui +
  entri manual penerjemah; ekspor **CSV / PDF / XLSX** dengan urutan kolom
  Pasal 17(3)a (Nomor Register; Tanggal; Jenis Dokumen Terjemahan; Halaman
  Teks Sumber; Arah Bahasa; Identitas Pengguna Jasa; Judul; Sumber).
  Pengingat tenggat H-30 dan H-7 lewat email dan WhatsApp (mode log selama
  gateway belum diatur).
- **Template blanko** dengan teks Arab (perapian RTL) dan bahasa lain,
  pratinjau PDF, dan unduhan hasil.
- **Dashboard responsif** (Tailwind + Alpine.js) dengan pencarian dokumen
  real-time lewat API JSON internal.

## Instalasi

### Cara 1 — Docker (dipakai di produksi)

Prasyarat: Docker + Docker Compose.

```bash
git clone https://github.com/izzdiin/blanko.git
cd blanko/html
```

Siapkan `config.php` **di luar docroot** (berisi secret aplikasi; tidak pernah
masuk repo) dan folder data, lalu sesuaikan jalur volume di
`docker-compose.yml` bila perlu:

```bash
docker compose up -d --build
```

Aplikasi berjalan di `http://localhost:1000`. Isi akun demo (opsional, untuk
mencoba):

```bash
docker exec blanko php /var/www/seed.php   # sesuaikan jalur seed.php Anda
```

Seed membuat akun demo **Mitra Demo** (`mitra@blanko.demo`) dan
**Penerjemah Tersumpah Demo** (`tersumpah@blanko.demo`); kata sandinya ada di
`seed.php`. **Jangan pakai akun demo di produksi.**

### Cara 2 — Manual

- PHP 8.2+ (produksi: 8.4) dengan ekstensi `pdo_sqlite` dan `gd`
  (jpeg/freetype/webp), serta biner `qpdf` di PATH.
- Web server apa pun yang mengarahkan request ke `html/index.php`
  (produksi: Apache + `mod_rewrite`, `AllowOverride All`).
- Folder data harus bisa ditulis oleh user web server; database SQLite
  dibuat otomatis pada koneksi pertama (skema dari
  `html/includes/schema.sql` + `migrate_profile.sql`).

### Konfigurasi notifikasi (opsional)

Tanpa konfigurasi, notifikasi ditulis ke log saja. WhatsApp diatur dari
halaman admin **Gateway WA** (`?page=gateway`) - base URL, path kirim,
device ID, token, dan templat payload tersimpan di tabel `settings`
(migrasi 0003), tanpa hardcode. Env `BLANKO_WA_URL` /
`BLANKO_WA_DEVICE_ID` / `BLANKO_WA_TOKEN` hanya menjadi nilai awal bila
pengaturan belum pernah disimpan. Untuk email:

| Variabel env | Isi |
| --- | --- |
| `BLANKO_WA_TOKEN` | Token Bearer gateway (bila ada) |
| `BLANKO_SMTP_HOST` / `BLANKO_SMTP_PORT` | Server SMTP (bawaan port 587, STARTTLS) |
| `BLANKO_SMTP_USER` / `BLANKO_SMTP_PASS` | Kredensial SMTP |
| `BLANKO_SMTP_FROM` | Alamat pengirim (bawaan `noreply@blanko.local`) |

### Cron pengingat laporan

```cron
20 8 * * * docker exec -u www-data blanko php /var/www/html/cli/remind_laporan.php >> /var/log/blanko-rekap-reminder.log 2>&1
```

## Deploy (Docker Compose)

`html/docker-compose.yml` adalah konfigurasi yang berjalan di produksi:

```yaml
services:
  blanko:
    build: .
    container_name: blanko
    volumes:
      - /usr/local/app/blanko/html:/var/www/html          # kode aplikasi
      - /usr/local/app/blanko/config.php:/var/www/config.php  # secret, di luar repo
      - /usr/local/app/blanko/data:/var/www/data          # SQLite + unggahan + aset TTD
    restart: always
    ports:
      - "1000:80"
```

Catatan deploy:

- Image dibangun dari `html/Dockerfile` (`php:8.4-apache` + gd + pdo_sqlite +
  qpdf + mod_rewrite).
- Karena kode di-bind-mount, memperbarui aplikasi cukup menyalin file ke
  folder `html` di host — tanpa rebuild image. Rebuild hanya bila
  `Dockerfile` berubah.
- Semua state ada di volume `data` (`blanko.sqlite` mode WAL, folder
  unggahan, aset tanda tangan): backup = salin folder itu.
- File `*.bak*` dan folder `backup-*` tidak pernah didorong ke repo ini.

## Arsitektur

Satu aplikasi PHP 8 native (tanpa framework), front controller tunggal, dan
satu file SQLite. Tidak ada proses build: CSS/JS adalah aset statis
(Tailwind terkompilasi + Alpine.js via CDN).

```
html/
├── index.php            # front controller: peta $routes + gerbang login/status akun
├── pages/               # satu file per rute (dashboard, upload, review, ...)
│   ├── _layout.php      # kerangka HTML bersama
│   └── api_docs.php     # endpoint JSON daftar dokumen (lihat bagian API)
├── includes/
│   ├── db.php           # PDO SQLite singleton; menjalankan migrasi berversi (migrate.php)
│   ├── bootstrap.php    # helper: e(), csrf, current_user, require_role, flash
│   ├── Attestation.php  # pembubuhan strip/QR ke PDF + nomor register
│   ├── Stamp.php / HtmlAttestation.php / ArabicShape.php
│   ├── esign.php        # token HMAC + jahitan integrasi PSrE (simulasi saat ini)
│   ├── Notify.php       # WhatsApp/SMTP (mode log bila belum diatur)
│   └── Rekap.php        # logika rekap tahunan + ekspor
├── migrations/          # migrasi skema berversi: 0001_baseline.sql, 0002_profil_dan_rekap.sql, 0003_settings.sql, 0004_login_attempts.sql, ...
├── cli/                 # remind_laporan.php, backfill_rekap.php, migrate_admin_role.php
├── assets/              # app.css (Tailwind terkompilasi), app.js, font
└── vendor/              # mPDF (+ fpdf/fpdi) — ter-vendor di repo
data/ (di luar repo, bind-mount)
├── blanko.sqlite        # seluruh state (WAL)
├── uploads/             # berkas sumber + hasil .stamped
└── esign/               # gambar tanda tangan penerjemah
```

Tabel database: `users`, `documents`, `action_tokens`, `notifications`,
`esign_records`, `work_entries`, `report_reminder_log`.

**Migrasi skema:** versi tercatat di tabel `_migrations`. Perubahan skema
selalu berupa file baru `migrations/NNNN_nama.sql` — file lama tidak pernah
diedit. Migrasi berjalan sekali, dalam transaksi, saat koneksi pertama;
kegagalan menghentikan aplikasi dengan error yang terlihat (tidak ditelan).
Basis data lama dari era `schema.sql`/`migrate_profile.sql` diadopsi otomatis:
versi 1–2 ditandai diterapkan tanpa mengeksekusi ulang isinya.

Alur persetujuan dokumen: penerjemah membuka `review`, menempatkan QR di
pratinjau, lalu `approve` — sistem menghitung halaman teks sumber dari berkas
asli dengan `qpdf`, membubuhkan strip + QR + tanda tangan (mPDF/GD), menyimpan
`esign_records`, dan membuat entri `work_entries` untuk rekap tahunan.

## Roles dan flow approval

| Role | Kemampuan inti |
| --- | --- |
| **mitra** | Mengunggah dokumen ke penerjemah pilihannya; melihat dokumen miliknya sendiri; membuat catatan pencocokan manual (pekerjaan di luar Blanko — tidak terhitung di rekap/ekspor, tidak perlu persetujuan). |
| **penerjemah** | Meninjau dokumen dari mitranya (setujui/tolak); mengelola profil blanko, pasangan bahasa, spesimen tanda tangan; rekap tahunan + ekspor; menyetujui/menolak **mitra yang memilihnya**. |
| **admin** | Menyetujui/menolak **semua** pendaftar, termasuk pendaftar penerjemah; mengelola akun. |

Flow registrasi:

1. Pendaftar memilih role **mitra** atau **penerjemah**. Mitra **wajib**
   memilih penerjemah tujuannya; penerjemah wajib melengkapi profil
   (pasangan bahasa, nomor SK, nama aksara) sebelum bisa diaktifkan.
2. Akun baru berstatus **pending**: hanya bisa membuka halaman pending untuk
   melengkapi profil.
3. Persetujuan: pendaftar **mitra** disetujui/ditolak oleh penerjemah yang
   dipilihnya; pendaftar **penerjemah** hanya oleh **admin** (penerjemah
   lain bahkan tidak melihatnya); admin bisa menyetujui siapa pun.
4. Akun **rejected** tidak bisa login (sesi dihancurkan).

Flow dokumen: `pending` → `approved` (terbit hasil stamped + nomor register +
masuk rekap) atau `declined` (dengan alasan, terlihat oleh mitra).

## API

API Blanko bersifat **internal dan berbasis sesi login** (cookie) — tidak ada
token API publik. Satu-satunya endpoint JSON:

### `GET ?page=api_docs`

Daftar dokumen untuk dashboard (pencarian, filter, pagination).

| Parameter | Isi |
| --- | --- |
| `q` | Kata kunci pada judul / nama berkas asli (opsional) |
| `status` | `pending` \| `approved` \| `declined` (opsional) |
| `p` | Halaman (bawaan 1; ukuran halaman tetap 10) |

Aturan akses: role **mitra** hanya menerima dokumen miliknya; penerjemah dan
admin menerima semua dokumen. Tanpa sesi login, request dialihkan ke halaman
login.

Contoh respons:

```json
{
  "docs": [
    {
      "id": 36,
      "title": "Ijazah — Terjemahan Inggris",
      "original_name": "ijazah.pdf",
      "status": "pending",
      "created_at": "2026-11-03 09:12:44",
      "stamped_name": null,
      "decline_reason": null,
      "mitra_name": "Nama Mitra"
    }
  ],
  "page": 1,
  "pages": 1,
  "total": 1,
  "role": "penerjemah"
}
```

Endpoint non-JSON yang berkaitan:

| Rute | Akses | Fungsi |
| --- | --- | --- |
| `?page=verify-sign&token=...` | Publik | Verifikasi keaslian bubuhan tanda tangan (token HMAC-SHA256) |
| `?page=review&token=...` | Token sekali pakai (`action_tokens`) | Tautan tinjau tanpa login |
| `?page=rekap_export` | Login (penerjemah) | Unduh rekap CSV / PDF / XLSX |
| `?page=download`, `?page=file`, `?page=preview` | Login | Unduh/lihat berkas sesuai kepemilikan |
| `?page=esign-asset`, `?page=font-asset` | Publik (aset) | Melayani aset tanda tangan dan font |

Kontrak keluar (integrasi): gateway WhatsApp menerima `POST` JSON sesuai
templat payload yang diatur admin (bawaan
`{"device_id":"{device_id}","to":"{to}","message":"{message}"}`) dengan
header `Authorization: Bearer <token>` bila token diatur — lihat
`html/includes/settings.php` dan `html/includes/Notify.php`.

## Keamanan (ringkas)

Prepared statement (PDO) di semua query, token CSRF di semua form POST,
hash kata sandi `password_hash`, lockout login bertahap per email dan IP
(migrasi 0004), validasi unggahan berlapis (MIME finfo + pemeriksaan isi +
kesesuaian ekstensi, maks 10 MB, throttle 30/jam), dan gerbang role +
kepemilikan di setiap rute berkas. `config.php` dan folder `data` tidak
pernah masuk repo.

## Lisensi

Repositori ini berisi kode sumber aplikasi internal legaly.id; hubungi
pemilik repo untuk penggunaan di luar keperluan tersebut.
