-- CLMS Migration 004: Notifications & Customer Confirmations
-- Rollback: DROP TABLE IF EXISTS customer_confirmations, notifications;

CREATE TABLE
IF NOT EXISTS notifications
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    type VARCHAR
(50) NOT NULL,
    title VARCHAR
(255) NOT NULL,
    body TEXT,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY
(user_id) REFERENCES users
(id) ON
DELETE CASCADE,
    INDEX idx_notifications_user_read (user_id, read_at
)
);

CREATE TABLE
IF NOT EXISTS customer_confirmations
(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id INT UNSIGNED NOT NULL,
    confirmed_by INT UNSIGNED,
    confirmed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    accepted_actuals JSON,
    FOREIGN KEY
(order_id) REFERENCES orders
(id) ON
DELETE CASCADE,
    FOREIGN KEY (confirmed_by)
REFERENCES users
(id) ON
DELETE
SET NULL
);
