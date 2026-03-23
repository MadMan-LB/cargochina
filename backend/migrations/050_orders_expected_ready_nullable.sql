-- Make expected_ready_date optional on orders so buyers can save drafts/orders without a ready date.
-- Rollback: ALTER TABLE orders MODIFY expected_ready_date DATE NOT NULL;

ALTER TABLE orders
    MODIFY expected_ready_date DATE NULL;
