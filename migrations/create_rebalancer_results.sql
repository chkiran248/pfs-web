-- Migration: create rebalancer_results table
-- Run once in phpMyAdmin on primefin_db
-- Used by: ai/rebalance-mf.php, ai/rebalance-equity.php

CREATE TABLE IF NOT EXISTS `rebalancer_results` (
  `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED     NOT NULL,
  `rebalance_type` ENUM('mutual_fund','equity') NOT NULL DEFAULT 'mutual_fund',
  `holding_name`   VARCHAR(200)     NOT NULL,
  `verdict`        ENUM('hold','buy_more','switch','sell','accumulate','reduce','exit','review') NOT NULL DEFAULT 'hold',
  `verdict_label`  VARCHAR(50)      NOT NULL DEFAULT 'HOLD',
  `confidence`     ENUM('high','medium','low') NOT NULL DEFAULT 'medium',
  `reason`         TEXT,
  `action_detail`  TEXT,
  `priority`       ENUM('urgent','moderate','low') NOT NULL DEFAULT 'low',
  `generated_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_type_date` (`user_id`, `rebalance_type`, `generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
