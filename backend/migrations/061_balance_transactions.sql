-- Migration 061: Employee-safe balance transaction ledger.
-- Rollback: DROP TABLE IF EXISTS balance_transactions;

CREATE TABLE IF NOT EXISTS balance_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_type ENUM('customer', 'supplier') NOT NULL,
  party_id INT UNSIGNED NOT NULL,
  transaction_type ENUM('payment_received', 'payment_sent', 'deposit', 'invoice', 'adjustment', 'refund', 'other') NOT NULL,
  direction ENUM('increase_balance', 'reduce_balance') NOT NULL DEFAULT 'reduce_balance',
  amount DECIMAL(12,4) NOT NULL,
  currency ENUM('USD', 'RMB') NOT NULL DEFAULT 'USD',
  payment_method VARCHAR(50) NULL,
  payment_account_label VARCHAR(150) NULL,
  payment_account_value VARCHAR(255) NULL,
  payment_account_qr_path VARCHAR(255) NULL,
  reference_number VARCHAR(100) NULL,
  notes TEXT NULL,
  created_by INT UNSIGNED NULL,
  transaction_date DATE NOT NULL,
  source_table VARCHAR(64) NULL,
  source_id INT UNSIGNED NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_balance_tx_party (party_type, party_id),
  INDEX idx_balance_tx_date (transaction_date),
  INDEX idx_balance_tx_currency (currency),
  INDEX idx_balance_tx_reference (reference_number),
  INDEX idx_balance_tx_source (source_table, source_id),
  CONSTRAINT fk_balance_tx_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
