-- Email confirmation codes for login and registration, plus a Google identity hook.
-- MariaDB 10.11 supports IF NOT EXISTS, so this is safe to re-run.

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS email_verified_at DATETIME NULL DEFAULT NULL AFTER status;

ALTER TABLE users
  ADD COLUMN IF NOT EXISTS google_sub VARCHAR(64) NULL DEFAULT NULL AFTER email_verified_at;

CREATE INDEX IF NOT EXISTS idx_users_google_sub ON users (google_sub);

CREATE TABLE IF NOT EXISTS user_email_codes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  purpose ENUM('login','register') NOT NULL,
  code_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_codes_lookup (user_id, purpose, consumed_at),
  CONSTRAINT fk_codes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
