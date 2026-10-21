-- Migration: create rebalancer_jobs table + extend rebalancer_results enum
-- Run once in phpMyAdmin on primefin_db
-- Used by: ai/rebalance-mf.php (queue), ai/rebalance-mf-status.php,
--          ai/rebalance-mf-single.php, data-fetcher/process-rebalancer-queue.php

CREATE TABLE IF NOT EXISTS `rebalancer_jobs` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`        INT UNSIGNED NOT NULL,
  `rebalance_type` ENUM('mutual_fund') NOT NULL DEFAULT 'mutual_fund',
  `status`         ENUM('queued','processing','completed','failed') NOT NULL DEFAULT 'queued',
  `result`         LONGTEXT NULL,
  `error_message`  VARCHAR(500) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `started_at`     DATETIME NULL,
  `completed_at`   DATETIME NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_status` (`user_id`, `status`),
  KEY `idx_status_created` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adds 'mutual_fund_single' so per-fund analyses are tracked separately from
-- full-portfolio runs and don't consume the monthly full-run limit.
ALTER TABLE `rebalancer_results`
  MODIFY `rebalance_type` ENUM('mutual_fund','equity','mutual_fund_single') NOT NULL DEFAULT 'mutual_fund';
