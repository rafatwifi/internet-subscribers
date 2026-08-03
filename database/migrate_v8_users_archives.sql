-- Users + monthly archives + activity actor columns
-- Runs automatically via PHP ensure_* on page load; this file is for manual deploy if needed.

CREATE TABLE IF NOT EXISTS admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(60) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL,
  UNIQUE KEY uq_admin_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS monthly_archives (
  year_month CHAR(7) NOT NULL,
  activations INT UNSIGNED NOT NULL DEFAULT 0,
  sales DECIMAL(14,2) NOT NULL DEFAULT 0,
  collected DECIMAL(14,2) NOT NULL DEFAULT 0,
  cost DECIMAL(14,2) NOT NULL DEFAULT 0,
  profit DECIMAL(14,2) NOT NULL DEFAULT 0,
  debt DECIMAL(14,2) NOT NULL DEFAULT 0,
  archived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (year_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If activity_logs already exists without actor columns:
-- ALTER TABLE activity_logs ADD COLUMN actor_user_id INT UNSIGNED DEFAULT NULL AFTER details;
-- ALTER TABLE activity_logs ADD COLUMN actor_name VARCHAR(80) DEFAULT NULL AFTER actor_user_id;
