-- Migrasi 0004 — jejak percobaan login gagal untuk rate limiting /
-- perlindungan brute-force (lockout bertahap per email dan per IP).
CREATE TABLE IF NOT EXISTS login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  identifier TEXT NOT NULL,
  attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_lookup ON login_attempts (identifier, attempted_at);
