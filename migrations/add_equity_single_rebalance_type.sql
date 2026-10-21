-- Migration: add 'equity_single' to rebalancer_results.rebalance_type
-- Run once in phpMyAdmin on primefin_db
-- Used by: ai/rebalance-equity-single.php

ALTER TABLE `rebalancer_results`
  MODIFY `rebalance_type` ENUM('mutual_fund','equity','mutual_fund_single','equity_single') NOT NULL DEFAULT 'mutual_fund';
