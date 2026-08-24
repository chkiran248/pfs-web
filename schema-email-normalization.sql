-- Run this manually on the production database BEFORE deploying the
-- register.php / auth.php changes that reference `email_canonical`.
-- (The application code degrades gracefully if this hasn't been run yet —
-- it detects the missing column and falls back to the old behavior — but
-- duplicate-account protection for Gmail dot-trick / +tag addresses only
-- takes effect once this migration has been applied.)

ALTER TABLE users
  ADD COLUMN email_canonical VARCHAR(255) NULL AFTER email;

-- Backfill existing rows: lowercase, strip "+tag", and for Gmail/Googlemail
-- strip dots from the local part (mirrors normalize_email() in includes/auth.php).
UPDATE users
SET email_canonical = CONCAT(
    CASE
        WHEN LOWER(SUBSTRING_INDEX(email, '@', -1)) IN ('gmail.com', 'googlemail.com')
        THEN REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(email), '@', 1), '+', 1), '.', '')
        ELSE SUBSTRING_INDEX(SUBSTRING_INDEX(LOWER(email), '@', 1), '+', 1)
    END,
    '@',
    LOWER(SUBSTRING_INDEX(email, '@', -1))
)
WHERE email_canonical IS NULL;

-- Non-unique index only: existing rows already contain real duplicates
-- (that's the problem this migration addresses), so a UNIQUE index would
-- fail to create. This just speeds up the new duplicate-check query in
-- register.php. New registrations are blocked at the application layer.
CREATE INDEX idx_users_email_canonical ON users (email_canonical);

-- Optional follow-up (not run automatically): find existing duplicate
-- accounts sharing a canonical email so you can decide how to merge/remove
-- them manually:
--
-- SELECT email_canonical, COUNT(*) AS accounts, GROUP_CONCAT(id) AS user_ids
-- FROM users
-- WHERE email_canonical IS NOT NULL
-- GROUP BY email_canonical
-- HAVING COUNT(*) > 1;
