-- SecureAuth Admin production schema
-- MySQL 8+ / MariaDB 10.5+. No real credentials or secrets are included.
-- Create the database and user outside this file, then import with:
-- mysql --default-character-set=utf8mb4 -u secure_login_user -p secure-login < database/schema.sql

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL DEFAULT 'Admin',
  email VARCHAR(255) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  twofa_enabled TINYINT(1) NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  otp VARCHAR(255) DEFAULT NULL,
  otp_expiry INT UNSIGNED DEFAULT NULL,
  profile_photo VARCHAR(255) NOT NULL DEFAULT '',
  last_login_ip VARCHAR(45) DEFAULT NULL,
  last_login_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_email (email),
  KEY idx_admins_twofa_enabled (twofa_enabled),
  KEY idx_admins_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  ip VARCHAR(45) NOT NULL,
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  last_attempt DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  email VARCHAR(255) DEFAULT NULL COMMENT 'Keyed hash of submitted email, not the raw address',
  PRIMARY KEY (ip),
  KEY idx_login_attempts_last_attempt (last_attempt),
  KEY idx_login_attempts_email_hash (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL DEFAULT 0,
  action VARCHAR(255) NOT NULL,
  ip VARCHAR(45) DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_logs_admin_id (admin_id),
  KEY idx_admin_logs_created_at (created_at),
  KEY idx_admin_logs_action (action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;