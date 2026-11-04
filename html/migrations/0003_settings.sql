-- Migrasi 0003 — tabel pengaturan dinamis (kunci-nilai).
-- Dipakai pertama kali untuk konfigurasi gateway WhatsApp yang diatur
-- admin dari halaman ?page=gateway (bukan lagi hardcode/env).
CREATE TABLE IF NOT EXISTS settings (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL DEFAULT '',
  updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
