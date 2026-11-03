-- Blanko: PWA tools penerjemah tersumpah
-- SQLite schema

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  email TEXT UNIQUE NOT NULL,
  phone TEXT,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN ('mitra','penerjemah','admin')),
  stamp_file TEXT,
  signature_file TEXT,
  name_ar TEXT DEFAULT '',
  sk TEXT DEFAULT '',
  sk_date TEXT DEFAULT '',
  rekap_periode_aktif INTEGER NOT NULL DEFAULT 0,
  rekap_periode_mulai TEXT,
  rekap_periode_selesai TEXT,
  address TEXT DEFAULT '',
  city TEXT DEFAULT '',
  lang_pair TEXT DEFAULT '',
  lang_reverse INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'active',
  penerjemah_id INTEGER REFERENCES users(id),
  is_admin INTEGER NOT NULL DEFAULT 0,
  name_zh TEXT DEFAULT '',
  esign_sig_file TEXT DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS documents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  title TEXT NOT NULL,
  original_name TEXT NOT NULL,
  stored_name TEXT NOT NULL,
  mime TEXT NOT NULL,
  size INTEGER NOT NULL,
  sha256 TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','declined')),
  decline_reason TEXT,
  reviewed_by INTEGER REFERENCES users(id),
  reviewed_at TEXT,
  stamped_name TEXT,
  template_id INTEGER,
  jenis_dokumen TEXT,
  halaman INTEGER,
  reg_no TEXT,
  halaman_sumber INTEGER,
  klien_tipe TEXT NOT NULL DEFAULT 'kategori',
  klien TEXT,
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_documents_user ON documents(user_id);
CREATE INDEX IF NOT EXISTS idx_documents_status ON documents(status);

CREATE TABLE IF NOT EXISTS action_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  document_id INTEGER NOT NULL REFERENCES documents(id),
  action TEXT NOT NULL CHECK(action IN ('approve','decline')),
  token TEXT UNIQUE NOT NULL,
  expires_at TEXT NOT NULL,
  used_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_tokens_token ON action_tokens(token);

CREATE TABLE IF NOT EXISTS notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER REFERENCES users(id),
  channel TEXT NOT NULL CHECK(channel IN ('wa','email','inbox')),
  recipient TEXT NOT NULL,
  subject TEXT,
  body TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'logged' CHECK(status IN ('logged','sent','failed')),
  created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_notif_user ON notifications(user_id);

CREATE TABLE IF NOT EXISTS esign_records (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  document_id INTEGER NOT NULL REFERENCES documents(id),
  user_id INTEGER NOT NULL REFERENCES users(id),
  token TEXT NOT NULL UNIQUE,
  status TEXT NOT NULL DEFAULT 'signed',
  qr_file TEXT DEFAULT '',
  sig_file TEXT DEFAULT '',
  pos_x REAL DEFAULT 0,
  pos_y REAL DEFAULT 0,
  pos_size REAL DEFAULT 0,
  pos_pages TEXT DEFAULT 'last',
  created_at TEXT DEFAULT '',
  signed_at TEXT DEFAULT ''
);
CREATE INDEX IF NOT EXISTS idx_esign_token ON esign_records(token);

-- Rekap Tahunan: pekerjaan di luar Blanko.
-- jenis_catatan 'rekap' = catatan resmi penerjemah (terhitung);
-- 'pencocokan' = catatan mitra (bahan cocok data, tidak terhitung).
CREATE TABLE IF NOT EXISTS work_entries (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  penerjemah_id INTEGER NOT NULL REFERENCES users(id),
  created_by INTEGER NOT NULL REFERENCES users(id),
  jenis_catatan TEXT NOT NULL CHECK(jenis_catatan IN ('rekap','pencocokan')),
  tanggal TEXT NOT NULL,
  judul TEXT NOT NULL,
  jenis_dokumen TEXT,
  arah_bahasa TEXT NOT NULL DEFAULT '',
  halaman INTEGER,
  reg_no TEXT,
  klien_tipe TEXT NOT NULL DEFAULT 'kategori',
  klien TEXT,
  catatan TEXT DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now')),
  updated_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_work_entries_penerjemah ON work_entries(penerjemah_id, tanggal);
CREATE INDEX IF NOT EXISTS idx_work_entries_creator ON work_entries(created_by);

-- Rekap Tahunan: penanda pengingat tenggat (anti kirim dobel per kanal).
CREATE TABLE IF NOT EXISTS report_reminder_log (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id),
  period_end TEXT NOT NULL,
  kind TEXT NOT NULL CHECK(kind IN ('H-30','H-7')),
  channel TEXT NOT NULL CHECK(channel IN ('wa','email')),
  sent_at TEXT NOT NULL DEFAULT (datetime('now')),
  UNIQUE(user_id, period_end, kind, channel)
);
