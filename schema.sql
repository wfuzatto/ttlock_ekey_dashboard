-- Execute dentro de um banco MySQL/MariaDB já criado na Locaweb.
-- O servidor da aplicação usa UTC; horário exibido no painel: America/Sao_Paulo.
CREATE TABLE IF NOT EXISTS admin_users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(80) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 username VARCHAR(80) NOT NULL,
 ip_hash CHAR(64) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_login_recent (created_at), INDEX idx_login_username (username, created_at), INDEX idx_login_ip (ip_hash, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS locks (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 label VARCHAR(120) NOT NULL,
 room VARCHAR(60) NOT NULL,
 ttlock_id BIGINT UNSIGNED NULL UNIQUE,
 mac VARCHAR(32) NULL,
 mode ENUM('simulation','bench','production') NOT NULL DEFAULT 'simulation',
 status ENUM('active','inactive') NOT NULL DEFAULT 'active',
 last_synced_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_room (room)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 code VARCHAR(80) NOT NULL UNIQUE,
 guest_name VARCHAR(150) NOT NULL,
 receiver_username VARCHAR(150) NOT NULL,
 lock_id INT UNSIGNED NOT NULL,
 checkin_utc DATETIME NOT NULL,
 checkout_utc DATETIME NOT NULL,
 status ENUM('confirmed','checked_in','checked_out','cancelled') NOT NULL DEFAULT 'confirmed',
 notes TEXT NULL,
 needs_review TINYINT(1) NOT NULL DEFAULT 0,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_res_lock FOREIGN KEY (lock_id) REFERENCES locks(id),
 INDEX idx_res_window (lock_id, checkin_utc, checkout_utc),
 INDEX idx_res_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ekeys (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reservation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 lock_id INT UNSIGNED NOT NULL,
 key_id BIGINT UNSIGNED NULL UNIQUE,
 receiver_username VARCHAR(150) NOT NULL,
 valid_from_utc DATETIME NOT NULL,
 valid_until_utc DATETIME NOT NULL,
 state ENUM('issued','frozen','deleted','failed') NOT NULL DEFAULT 'issued',
 simulated TINYINT(1) NOT NULL DEFAULT 1,
 sync_state ENUM('pending','confirmed','unknown') NOT NULL DEFAULT 'pending',
 last_error_code VARCHAR(60) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_ek_res FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 CONSTRAINT fk_ek_lock FOREIGN KEY (lock_id) REFERENCES locks(id),
 INDEX idx_ek_state (state,valid_from_utc,valid_until_utc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ttlock_tokens (
 id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
 access_token_enc TEXT NULL,
 refresh_token_enc TEXT NULL,
 expires_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_calls (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 endpoint VARCHAR(100) NOT NULL,
 lock_id INT UNSIGNED NULL,
 result VARCHAR(40) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_api_month (created_at), INDEX idx_api_lock (lock_id),
 CONSTRAINT fk_api_lock FOREIGN KEY (lock_id) REFERENCES locks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 reservation_id BIGINT UNSIGNED NULL,
 lock_id INT UNSIGNED NULL,
 key_id BIGINT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 result VARCHAR(40) NOT NULL,
 detail VARCHAR(220) NOT NULL DEFAULT '',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_audit_date (created_at), INDEX idx_audit_res (reservation_id),
 CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL,
 CONSTRAINT fk_audit_res FOREIGN KEY (reservation_id) REFERENCES reservations(id) ON DELETE SET NULL,
 CONSTRAINT fk_audit_lock FOREIGN KEY (lock_id) REFERENCES locks(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS webhook_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 event_id VARCHAR(120) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hfvm_nonces (
 nonce_hash CHAR(64) NOT NULL PRIMARY KEY,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_nonce_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
 name VARCHAR(80) NOT NULL PRIMARY KEY,
 value VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (name,value) VALUES
('monthly_quota','30000'), ('alert_80','80'), ('alert_90','90'), ('alert_100','100');
