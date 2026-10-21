-- Migration: add asset_type column to portfolio_entries
-- Run once in phpMyAdmin on primefin_db
-- Used by: portal/portfolio.php, ai/confirm-holdings.php, ai/rebalancer-core.php,
--          ai/rebalance-equity.php, ai/rebalance-equity-single.php,
--          portal/rebalancer.php, portal/overlap-analyzer.php
--
-- fund_type alone cannot distinguish an individual stock from an equity
-- mutual fund scheme, or a standalone gold/SGB holding from a gold mutual
-- fund -- both pairs share the same fund_type value today ('equity' and
-- 'gold' respectively). This left the MF Rebalancer and Equity Analyser
-- unable to cleanly separate their holdings (a stock like "Reliance" could
-- appear in both), the Portfolio page unable to show real category totals,
-- and silently miscategorised insurance/PMS/AIF/crypto holdings as "other"
-- in portal/portfolio.php's stale fund_type whitelist.
--
-- asset_type is captured directly from the user's own Step 1 selection in
-- the add-holding form going forward, so it's authoritative rather than
-- inferred. Existing rows are backfilled with the best available heuristic
-- (the same fund-name keyword check portal/portfolio.php's edit form and
-- ai/rebalance-equity.php already used ad hoc) -- any that guess wrong can
-- be corrected by re-saving the holding via Edit.

ALTER TABLE `portfolio_entries`
  ADD COLUMN `asset_type` VARCHAR(20) NULL AFTER `fund_type`;

UPDATE `portfolio_entries` SET `asset_type` = CASE
  WHEN `fund_type` IN ('debt','hybrid','elss','index','international','liquid') THEN 'mutual_fund'
  WHEN `fund_type` = 'equity' AND LOWER(`fund_name`) LIKE '%fund%' THEN 'mutual_fund'
  WHEN `fund_type` = 'equity' THEN 'stock'
  WHEN `fund_type` = 'gold' AND LOWER(`fund_name`) LIKE '%fund%' THEN 'mutual_fund'
  WHEN `fund_type` = 'gold' THEN 'gold'
  WHEN `fund_type` IN ('fd','nps','insurance','pms','aif','crypto','fixed_income') THEN `fund_type`
  ELSE 'other'
END;
