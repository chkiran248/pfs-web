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
define('SMTP_HOST',     'smtp.hostinger.com');  // Hostinger SMTP relay
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

// Registration abuse controls (separate bucket from login lockouts above)
define('MAX_REGISTRATIONS_PER_IP',   5);   // per REGISTRATION_WINDOW_MINUTES
define('REGISTRATION_WINDOW_MINUTES', 60);

// ── CLOUDFLARE TURNSTILE (registration CAPTCHA) ───────────
// Get real keys at https://dash.cloudflare.com/?to=/:account/turnstile
define('TURNSTILE_SITE_KEY',   'YOUR_TURNSTILE_SITE_KEY');
define('TURNSTILE_SECRET_KEY', 'YOUR_TURNSTILE_SECRET_KEY');

// ── ENVIRONMENT ──────────────────────────────────────────
// IMPORTANT: Define APP_ENV before session config so cookie_secure is set correctly
define('APP_ENV',   'development');  // Change to 'production' on Hostinger
define('APP_DEBUG', true);

if (APP_ENV === 'development') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// ── SESSION COOKIE CONFIG ────────────────────────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   APP_ENV === 'production' ? '1' : '0');  // Secure only in production (HTTPS)
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.gc_maxlifetime',  (string) SESSION_LIFETIME);
ini_set('log_errors',              '1');
ini_set('error_log',               __DIR__ . '/../logs/error.log');

// ── CTA LINKS ─────────────────────────────────────────────
define('ONBOARDING_URL', 'https://www.assetplus.in/mfd/YOUR_HANDLE');
define('INSURANCE_URL',  'https://insurance.assetplus.in/YOUR_ARN');
define('CALENDLY_URL',   'https://calendly.com/YOUR_LINK');
define('WHATSAPP_URL',   'https://wa.me/919980001338');

// ── INDIAN CURRENCY FORMAT ────────────────────────────────
if (!function_exists('format_inr')) {
    function format_inr(float|int $amount, bool $symbol = true): string {
        $neg = $amount < 0;
        $n   = (string)(int) round(abs($amount));
        if (strlen($n) <= 3) {
            return ($neg ? '-' : '') . ($symbol ? '₹' : '') . $n;
        }
        $last3     = substr($n, -3);
        $remaining = substr($n, 0, -3);
        $groups    = [];
        while (strlen($remaining) > 0) {
            $groups[] = substr($remaining, -2);
            $remaining = substr($remaining, 0, -2);
        }
        $formatted = implode(',', array_reverse($groups)) . ',' . $last3;
        return ($neg ? '-' : '') . ($symbol ? '₹' : '') . ltrim($formatted, ',');
    }

    function format_inr_short(float|int $amount, bool $symbol = true): string {
        $pre = $symbol ? '₹' : '';
        $abs = abs($amount);
        if ($abs >= 10000000) return $pre . number_format($abs / 10000000, 2) . ' Cr';
        if ($abs >= 100000)   return $pre . number_format($abs / 100000, 2) . ' L';
        return format_inr($amount, $symbol);
    }
}

// ── PRIMO AI ──────────────────────────────────────────────
define('CLAUDE_API_KEY',             'sk-ant-YOUR_CLAUDE_API_KEY');
define('PRIMO_MODEL',                'claude-sonnet-4-6');
define('PRIMO_MAX_TOKENS',           2048);
define('PRIMO_TEMPERATURE',          0.7);
define('PRIMO_HISTORY_LIMIT',        10);
define('PRIMO_CONTEXT_MAX_HOLDINGS', 20);
define('PRIMO_HISTORY_DAYS',         30);
