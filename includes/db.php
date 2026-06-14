<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';

function get_db(bool $force_reconnect = false): PDO {
    static $pdo = null;
    if ($pdo !== null && !$force_reconnect) return $pdo;

    try {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST, DB_PORT, DB_NAME, DB_CHARSET
        );
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION wait_timeout=600",
        ]);
    } catch (PDOException $e) {
        error_log('DB connection failed: ' . $e->getMessage());
        http_response_code(503);
        die('<p style="font-family:sans-serif;color:#c62828;padding:2rem">Service temporarily unavailable. Please try again shortly.</p>');
    }

    return $pdo;
}

function db_ensure_alive(): PDO {
    try {
        get_db()->query('SELECT 1');
    } catch (PDOException $e) {
        // MySQL server has gone away — force a fresh connection
        return get_db(true);
    }
    return get_db();
}
