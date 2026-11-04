-- Migrasi 0002 — profil penerjemah, esign, template, rekap (DIBEKUKAN 2026-11-04).
-- Isi: migrate_profile.sql terakhir, disimpan sebagai riwayat. File ini
-- tidak dieksekusi pada basis data yang keadaannya sudah mencakup isinya
-- (ditandai diterapkan oleh migrator). Jangan edit file ini.
--
ALTER TABLE users ADD COLUMN name_ar TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN sk TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN address TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN city TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN lang_pair TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN status TEXT NOT NULL DEFAULT 'active';
ALTER TABLE users ADD COLUMN penerjemah_id INTEGER REFERENCES users(id);
ALTER TABLE users ADD COLUMN is_admin INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN name_zh TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN lang_reverse INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN esign_sig_file TEXT DEFAULT '';
CREATE TABLE IF NOT EXISTS esign_records (id INTEGER PRIMARY KEY AUTOINCREMENT, document_id INTEGER NOT NULL, user_id INTEGER NOT NULL, token TEXT NOT NULL UNIQUE, status TEXT NOT NULL DEFAULT 'signed', qr_file TEXT DEFAULT '', sig_file TEXT DEFAULT '', pos_x REAL DEFAULT 0, pos_y REAL DEFAULT 0, pos_size REAL DEFAULT 0, pos_pages TEXT DEFAULT 'last', created_at TEXT DEFAULT '', signed_at TEXT DEFAULT '');
CREATE INDEX IF NOT EXISTS idx_esign_token ON esign_records(token);
ALTER TABLE blanko_templates ADD COLUMN user_id INTEGER REFERENCES users(id);
ALTER TABLE blanko_templates ADD COLUMN body_html TEXT DEFAULT '';
ALTER TABLE blanko_templates ADD COLUMN placement TEXT NOT NULL DEFAULT 'strip';
CREATE INDEX IF NOT EXISTS idx_templates_user ON blanko_templates(user_id);
ALTER TABLE blanko_templates ADD COLUMN font TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN sk_date TEXT DEFAULT '';
ALTER TABLE users ADD COLUMN rekap_periode_aktif INTEGER NOT NULL DEFAULT 0;
ALTER TABLE users ADD COLUMN rekap_periode_mulai TEXT;
ALTER TABLE users ADD COLUMN rekap_periode_selesai TEXT;
ALTER TABLE documents ADD COLUMN jenis_dokumen TEXT;
ALTER TABLE documents ADD COLUMN halaman INTEGER;
ALTER TABLE documents ADD COLUMN reg_no TEXT;
ALTER TABLE documents ADD COLUMN halaman_sumber INTEGER;
ALTER TABLE work_entries ADD COLUMN reg_no TEXT;
ALTER TABLE documents ADD COLUMN klien_tipe TEXT NOT NULL DEFAULT 'kategori';
ALTER TABLE documents ADD COLUMN klien TEXT;
CREATE TABLE IF NOT EXISTS work_entries (id INTEGER PRIMARY KEY AUTOINCREMENT, penerjemah_id INTEGER NOT NULL REFERENCES users(id), created_by INTEGER NOT NULL REFERENCES users(id), jenis_catatan TEXT NOT NULL CHECK(jenis_catatan IN ('rekap','pencocokan')), tanggal TEXT NOT NULL, judul TEXT NOT NULL, jenis_dokumen TEXT, arah_bahasa TEXT NOT NULL DEFAULT '', halaman INTEGER, reg_no TEXT, klien_tipe TEXT NOT NULL DEFAULT 'kategori', klien TEXT, catatan TEXT DEFAULT '', created_at TEXT NOT NULL DEFAULT (datetime('now')), updated_at TEXT);
CREATE INDEX IF NOT EXISTS idx_work_entries_penerjemah ON work_entries(penerjemah_id, tanggal);
CREATE INDEX IF NOT EXISTS idx_work_entries_creator ON work_entries(created_by);
CREATE TABLE IF NOT EXISTS report_reminder_log (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL REFERENCES users(id), period_end TEXT NOT NULL, kind TEXT NOT NULL CHECK(kind IN ('H-30','H-7')), channel TEXT NOT NULL CHECK(channel IN ('wa','email')), sent_at TEXT NOT NULL DEFAULT (datetime('now')), UNIQUE(user_id, period_end, kind, channel));
