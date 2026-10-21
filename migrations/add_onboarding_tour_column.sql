-- Migration: add has_seen_tour column to users
-- Run once in phpMyAdmin on primefin_db
-- Used by: auth/login.php, portal/complete-tour.php, portal/dashboard.php

ALTER TABLE `users`
  ADD COLUMN `has_seen_tour` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

-- Existing accounts are already familiar with the portal -- only newly
-- registered users (inserted after this migration, which omits this column
-- and relies on the DEFAULT 0 above) should see the onboarding tour.
UPDATE `users` SET `has_seen_tour` = 1;
