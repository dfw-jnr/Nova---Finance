-- NOVA Finance schema
-- Money: DECIMAL only. Currency stored explicitly (EUR default).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS nova_finance
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE nova_finance;

DROP TABLE IF EXISTS sync_keys;
DROP TABLE IF EXISTS recurring_transactions;
DROP TABLE IF EXISTS goals;
DROP TABLE IF EXISTS budget_categories;
DROP TABLE IF EXISTS budgets;
DROP TABLE IF EXISTS transactions;
DROP TABLE IF EXISTS categories;
DROP TABLE IF EXISTS accounts;
DROP TABLE IF EXISTS settings;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(190) NOT NULL,
  name          VARCHAR(120) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  currency      CHAR(3) NOT NULL DEFAULT 'EUR',
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE accounts (
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
) ENGINE=InnoDB;

CREATE TABLE categories (
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
) ENGINE=InnoDB;

CREATE TABLE transactions (
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
  CONSTRAINT fk_txn_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT chk_txn_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE budgets (
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
  CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_budget_month CHECK (month BETWEEN 1 AND 12)
) ENGINE=InnoDB;

CREATE TABLE budget_categories (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  budget_id   BIGINT UNSIGNED NOT NULL,
  category_id BIGINT UNSIGNED NOT NULL,
  limit_amount DECIMAL(14,2) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_budget_cat (budget_id, category_id),
  CONSTRAINT fk_bc_budget FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  CONSTRAINT fk_bc_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
  CONSTRAINT chk_bc_limit CHECK (limit_amount > 0)
) ENGINE=InnoDB;

CREATE TABLE goals (
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
  CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT chk_goal_target CHECK (target_amount > 0),
  CONSTRAINT chk_goal_current CHECK (current_amount >= 0)
) ENGINE=InnoDB;

CREATE TABLE recurring_transactions (
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
  CONSTRAINT fk_rec_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT chk_rec_amount CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE settings (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  setting_key VARCHAR(80) NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_settings_user_key (user_id, setting_key),
  CONSTRAINT fk_settings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sync_keys (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  client_id  CHAR(36) NOT NULL,
  entity     VARCHAR(40) NOT NULL,
  entity_id  BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sync (user_id, client_id),
  CONSTRAINT fk_sync_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET FOREIGN_KEY_CHECKS = 1;

-- System categories (user_id NULL)
INSERT INTO categories (user_id, name, type, icon, color, is_system) VALUES
(NULL, 'Food', 'expense', 'food', '#F5A524', 1),
(NULL, 'Groceries', 'expense', 'groceries', '#3DDC97', 1),
(NULL, 'Transport', 'expense', 'transport', '#5B8DEF', 1),
(NULL, 'Rent', 'expense', 'rent', '#94A3B8', 1),
(NULL, 'Utilities', 'expense', 'utilities', '#F472B6', 1),
(NULL, 'Shopping', 'expense', 'shopping', '#C084FC', 1),
(NULL, 'Entertainment', 'expense', 'entertainment', '#FB7185', 1),
(NULL, 'Education', 'expense', 'education', '#38BDF8', 1),
(NULL, 'Travel', 'expense', 'travel', '#2DD4BF', 1),
(NULL, 'Health', 'expense', 'health', '#34D399', 1),
(NULL, 'Subscriptions', 'expense', 'subscriptions', '#A78BFA', 1),
(NULL, 'Salary', 'income', 'salary', '#3DDC97', 1),
(NULL, 'Freelance', 'income', 'freelance', '#6EA8FE', 1),
(NULL, 'Other', 'both', 'other', '#94A3B8', 1);
