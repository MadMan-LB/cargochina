-- Migration 061: Employee-safe balance transaction ledger.
-- Rollback: DROP TABLE IF EXISTS balance_transactions;

CREATE TABLE IF NOT EXISTS balance_transactions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  party_type VARCHAR(20) NOT NULL,
  party_id INT UNSIGNED NOT NULL,
  transaction_type VARCHAR(40) NOT NULL,
  direction VARCHAR(30) NOT NULL DEFAULT 'reduce_balance',
  amount DECIMAL(12,4) NOT NULL,
  currency VARCHAR(10) NOT NULL DEFAULT 'USD',
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
  CONSTRAINT chk_balance_tx_party_type CHECK (party_type IN ('customer', 'supplier')),
  CONSTRAINT chk_balance_tx_type CHECK (transaction_type IN ('payment_received', 'payment_sent', 'adjustment', 'refund', 'other')),
  CONSTRAINT chk_balance_tx_direction CHECK (direction IN ('increase_balance', 'reduce_balance')),
  CONSTRAINT chk_balance_tx_currency CHECK (currency IN ('USD', 'RMB')),
  CONSTRAINT fk_balance_tx_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
