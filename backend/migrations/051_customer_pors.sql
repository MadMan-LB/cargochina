CREATE TABLE IF NOT EXISTS customer_pors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_id INT UNSIGNED NOT NULL,
    por_value VARCHAR(120) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_customer_pors_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE,
    CONSTRAINT uq_customer_pors_value UNIQUE (customer_id, por_value),
    INDEX idx_customer_pors_customer (customer_id),
    INDEX idx_customer_pors_value (por_value)
);
