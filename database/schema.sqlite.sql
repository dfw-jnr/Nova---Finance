-- SQLite schema for local development (MySQL remains production target)
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  password_hash TEXT NOT NULL,
  currency TEXT NOT NULL DEFAULT 'EUR',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS accounts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'bank',
  currency TEXT NOT NULL DEFAULT 'EUR',
  balance TEXT NOT NULL DEFAULT '0.00',
  is_default INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NULL,
  name TEXT NOT NULL,
  type TEXT NOT NULL DEFAULT 'expense',
  icon TEXT NULL,
  color TEXT NULL,
  is_system INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  account_id INTEGER NOT NULL,
  category_id INTEGER NULL,
  client_id TEXT NULL,
  type TEXT NOT NULL,
  amount TEXT NOT NULL,
  currency TEXT NOT NULL DEFAULT 'EUR',
  merchant TEXT NOT NULL,
  description TEXT NULL,
  notes TEXT NULL,
  txn_date TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, client_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id),
  FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE TABLE IF NOT EXISTS budgets (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  month INTEGER NOT NULL,
  year INTEGER NOT NULL,
  currency TEXT NOT NULL DEFAULT 'EUR',
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, year, month, name),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS budget_categories (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  budget_id INTEGER NOT NULL,
  category_id INTEGER NOT NULL,
  limit_amount TEXT NOT NULL,
  UNIQUE (budget_id, category_id),
  FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS goals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  name TEXT NOT NULL,
  target_amount TEXT NOT NULL,
  current_amount TEXT NOT NULL DEFAULT '0.00',
  currency TEXT NOT NULL DEFAULT 'EUR',
  target_date TEXT NULL,
  accent TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS recurring_transactions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  account_id INTEGER NOT NULL,
  category_id INTEGER NULL,
  name TEXT NOT NULL,
  amount TEXT NOT NULL,
  currency TEXT NOT NULL DEFAULT 'EUR',
  type TEXT NOT NULL,
  frequency TEXT NOT NULL,
  next_date TEXT NOT NULL,
  is_active INTEGER NOT NULL DEFAULT 1,
  last_run_at TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE,
  FOREIGN KEY (category_id) REFERENCES categories(id)
);

CREATE TABLE IF NOT EXISTS settings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  setting_key TEXT NOT NULL,
  setting_value TEXT NOT NULL,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, setting_key),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS sync_keys (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  client_id TEXT NOT NULL,
  entity TEXT NOT NULL,
  entity_id INTEGER NOT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (user_id, client_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

INSERT OR IGNORE INTO categories (id, user_id, name, type, icon, color, is_system) VALUES
(1, NULL, 'Food', 'expense', 'food', '#F5A524', 1),
(2, NULL, 'Groceries', 'expense', 'groceries', '#3DDC97', 1),
(3, NULL, 'Transport', 'expense', 'transport', '#5B8DEF', 1),
(4, NULL, 'Rent', 'expense', 'rent', '#94A3B8', 1),
(5, NULL, 'Utilities', 'expense', 'utilities', '#F472B6', 1),
(6, NULL, 'Shopping', 'expense', 'shopping', '#C084FC', 1),
(7, NULL, 'Entertainment', 'expense', 'entertainment', '#FB7185', 1),
(8, NULL, 'Education', 'expense', 'education', '#38BDF8', 1),
(9, NULL, 'Travel', 'expense', 'travel', '#2DD4BF', 1),
(10, NULL, 'Health', 'expense', 'health', '#34D399', 1),
(11, NULL, 'Subscriptions', 'expense', 'subscriptions', '#A78BFA', 1),
(12, NULL, 'Salary', 'income', 'salary', '#3DDC97', 1),
(13, NULL, 'Freelance', 'income', 'freelance', '#6EA8FE', 1),
(14, NULL, 'Other', 'both', 'other', '#94A3B8', 1);

CREATE TABLE IF NOT EXISTS receipts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  transaction_id INTEGER NOT NULL UNIQUE,
  mime TEXT NOT NULL DEFAULT 'image/jpeg',
  data_blob BLOB NOT NULL,
  ocr_text TEXT NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);
