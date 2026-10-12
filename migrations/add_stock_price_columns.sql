-- Migration: add live price columns to stock_research
-- Run once in phpMyAdmin on primefin_db

ALTER TABLE `stock_research`
  ADD COLUMN `current_price`     DECIMAL(10,2) NULL     AFTER `price_at_report`,
  ADD COLUMN `price_change_pct`  DECIMAL(6,2)  NULL     AFTER `current_price`,
  ADD COLUMN `last_price_refresh` DATETIME     NULL     AFTER `price_change_pct`;
