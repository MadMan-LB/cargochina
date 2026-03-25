ALTER TABLE suppliers
    ADD COLUMN payment_facility_days INT NULL AFTER commission_applied_on,
    ADD COLUMN payment_links JSON NULL AFTER payment_facility_days;

ALTER TABLE supplier_payments
    ADD COLUMN payment_channel VARCHAR(50) NULL AFTER payment_type,
    ADD COLUMN settlement_delta DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER discount_amount,
    ADD COLUMN settlement_mode VARCHAR(50) NULL AFTER marked_full_payment,
    ADD COLUMN settlement_note TEXT NULL AFTER settlement_mode;
