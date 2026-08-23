-- Cashfree payment integration schema
-- Run once via phpMyAdmin on primefin_db
-- Safe to run multiple times (uses IF NOT EXISTS / IGNORE)

-- ── payments table ────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS payments (
    id                  INT UNSIGNED        AUTO_INCREMENT PRIMARY KEY,
    user_id             INT UNSIGNED        NOT NULL,
    cashfree_order_id   VARCHAR(100)        NOT NULL,
    cashfree_payment_id VARCHAR(100)        DEFAULT NULL,
    amount              DECIMAL(10,2)       NOT NULL,
    currency            CHAR(3)             NOT NULL DEFAULT 'INR',
    plan_code           VARCHAR(30)         NOT NULL DEFAULT 'premium',
    billing_cycle       ENUM('monthly','annual') NOT NULL,
    status              ENUM('created','paid','failed','expired','user_dropped') NOT NULL DEFAULT 'created',
    payment_method      VARCHAR(100)        DEFAULT NULL,
    paid_at             DATETIME            DEFAULT NULL,
    created_at          TIMESTAMP           NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE  KEY uq_cf_order   (cashfree_order_id),
    KEY     idx_user_id       (user_id),
    KEY     idx_status        (status),
    CONSTRAINT fk_payments_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Add cashfree_order_id reference to user_subscriptions (if missing) ────────
-- Allows linking a subscription back to the payment order
ALTER TABLE user_subscriptions
    ADD COLUMN IF NOT EXISTS cashfree_order_id VARCHAR(100) DEFAULT NULL AFTER coupon_code;
