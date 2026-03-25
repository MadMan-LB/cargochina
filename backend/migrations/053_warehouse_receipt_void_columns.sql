ALTER TABLE warehouse_receipts
    ADD COLUMN voided_at DATETIME NULL AFTER received_at,
    ADD COLUMN voided_by INT NULL AFTER voided_at,
    ADD COLUMN void_reason TEXT NULL AFTER voided_by;
