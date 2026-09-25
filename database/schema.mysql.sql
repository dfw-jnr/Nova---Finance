-- NOVA Finance — idempotent MySQL schema (safe to re-run; never drops data)

CREATE TABLE IF NOT EXISTS users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'EUR',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS accounts (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(120) NOT NULL,
  type       ENUM('cash','bank','savings','credit','other') NOT NULL DEFAULT 'bank',
  currency   CHAR(3) NOT NULL DEFAULT 'EUR',
  balance    DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_accounts_user (user_id),
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NULL,
  name       VARCHAR(80) NOT NULL,
  type       ENUM('expense','income','both') NOT NULL DEFAULT 'expense',
  icon       VARCHAR(40) NULL,
  color      CHAR(7) NULL,
  is_system  TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_categories_user (user_id),
  CONSTRAINT fk_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  account_id      BIGINT UNSIGNED NOT NULL,
  category_id     BIGINT UNSIGNED NULL,
  client_id       CHAR(36) NULL,
  type            ENUM('expense','income') NOT NULL,
  amount          DECIMAL(14,2) NOT NULL,
  currency        CHAR(3) NOT NULL DEFAULT 'EUR',
  merchant        VARCHAR(160) NOT NULL,
  description     VARCHAR(500) NULL,
  notes           TEXT NULL,
  txn_date        DATE NOT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_txn_client (user_id, client_id),
  KEY idx_txn_user_date (user_id, txn_date),
  KEY idx_txn_user_type (user_id, type),
  KEY idx_txn_category (category_id),
  KEY idx_txn_account (account_id),
  CONSTRAINT fk_txn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_txn_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_txn_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budgets (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(120) NOT NULL,
  month      TINYINT UNSIGNED NOT NULL,
  year       SMALLINT UNSIGNED NOT NULL,
  currency   CHAR(3) NOT NULL DEFAULT 'EUR',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_period (user_id, year, month, name),
  KEY idx_budgets_user (user_id),
  CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS budget_categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  budget_id   BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  limit_amount DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_cat (budget_id, category_id),
  CONSTRAINT fk_bc_budget FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goals (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id       BIGINT UNSIGNED NOT NULL,
  name          VARCHAR(120) NOT NULL,
  target_amount DECIMAL(14,2) NOT NULL,
  current_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  currency      CHAR(3) NOT NULL DEFAULT 'EUR',
  target_date   DATE NULL,
  accent        CHAR(7) NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_goals_user (user_id),
  CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS recurring_transactions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  account_id   BIGINT UNSIGNED NOT NULL,
  category_id  BIGINT UNSIGNED NULL,
  name         VARCHAR(160) NOT NULL,
  amount       DECIMAL(14,2) NOT NULL,
  currency     CHAR(3) NOT NULL DEFAULT 'EUR',
  type         ENUM('expense','income') NOT NULL,
  frequency    ENUM('weekly','monthly','yearly') NOT NULL,
  next_date    DATE NOT NULL,
  is_active    TINYINT(1) NOT NULL DEFAULT 1,
  last_run_at  DATETIME NULL,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_recurring_user_next (user_id, next_date, is_active),
  CONSTRAINT fk_rec_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  CONSTRAINT fk_rec_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_user_key (user_id, setting_key),
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sync_keys (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  client_id  CHAR(36) NOT NULL,
  entity     VARCHAR(40) NOT NULL,
  entity_id  BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync (user_id, client_id),
  CONSTRAINT fk_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS receipts (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id         BIGINT UNSIGNED NOT NULL,
  transaction_id  BIGINT UNSIGNED NOT NULL,
  mime            VARCHAR(64) NOT NULL DEFAULT 'image/jpeg',
  data_blob       MEDIUMBLOB NOT NULL,
  ocr_text        TEXT NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_receipt_txn (transaction_id),
  KEY idx_receipts_user (user_id),
  CONSTRAINT fk_receipt_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_receipt_txn FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
