<?php
declare(strict_types=1);

// ── APP IDENTITY ─────────────────────────────────────────
define('APP_NAME',      'Prime Financials');
define('APP_TAGLINE',   'Data is Our Power');
define('SITE_URL',      'http://localhost/primefin_website');  // Change to https://primefin.in on Hostinger
define('APP_EMAIL',     'support@primefin.in');
define('APP_PHONE',     '+91 9980001338');
define('WHATSAPP_NUM',  '919980001338');
define('FOUNDED_YEAR',  '2016');
define('AMFI_ARN',      'ARN-XXXXXX');

// ── DATABASE ─────────────────────────────────────────────
define('DB_HOST',       'localhost');
define('DB_PORT',       '3306');
define('DB_NAME',       'primefin_db');
define('DB_USER',       'root');          // XAMPP default
define('DB_PASS',       '');              // XAMPP default — empty
define('DB_CHARSET',    'utf8mb4');

// ── SMTP EMAIL ───────────────────────────────────────────
define('SMTP_HOST',     'mail.primefin.in');
define('SMTP_PORT',     587);
define('SMTP_USER',     'support@primefin.in');
define('SMTP_PASS',     'YOUR_EMAIL_PASSWORD');
define('SMTP_FROM',     'support@primefin.in');
define('SMTP_FROM_NAME','Prime Financials');
define('SMTP_SECURE',   'tls');

// ── FILE STORAGE ─────────────────────────────────────────
define('UPLOAD_PATH',   __DIR__ . '/../uploads/documents/');
define('UPLOAD_URL',    SITE_URL . '/uploads/documents/');
define('MAX_UPLOAD_MB', 10);
define('ALLOWED_EXTENSIONS', ['pdf', 'jpg', 'jpeg', 'png', 'xlsx']);

// ── SECURITY ─────────────────────────────────────────────
define('SESSION_LIFETIME',    86400);
define('OTP_EXPIRY_MINUTES',  10);
define('MAX_LOGIN_ATTEMPTS',  5);
define('LOCKOUT_MINUTES',     15);
define('BCRYPT_COST',         12);
define('CSRF_TOKEN_LENGTH',   32);

// ── SESSION COOKIE CONFIG ────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '0');    // Set to '1' on Hostinger (HTTPS)
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime',  (string) SESSION_LIFETIME);
ini_set('log_errors',              '1');
ini_set('error_log',               __DIR__ . '/../logs/error.log');

// ── ENVIRONMENT ──────────────────────────────────────────
define('APP_ENV',   'development');  // Change to 'production' on Hostinger
define('APP_DEBUG', true);

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// ── PRIMO AI ──────────────────────────────────────────────
define('CLAUDE_API_KEY',          'sk-ant-YOUR_CLAUDE_API_KEY');  // TODO: Add your API key from console.anthropic.com
define('PRIMO_MODEL',             'claude-sonnet-4-6');      // Updated from prompt (was claude-sonnet-4-5)
define('PRIMO_MAX_TOKENS',        1024);
define('PRIMO_TEMPERATURE',       0.7);
define('PRIMO_HISTORY_LIMIT',     10);
define('PRIMO_CONTEXT_MAX_HOLDINGS', 20);
define('PRIMO_HISTORY_DAYS',      30);
