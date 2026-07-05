<?php
// =============================================================
// includes/db.php — PDO connection (singleton)
// =============================================================
// Configuration: copy .env.example → .env (not committed).
// =============================================================

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

require_once ROOT . '/includes/env.php';

$cfg = load_app_config();

function mysql_dsn(array $cfg): string
{
    $db = $cfg['db_name'];
    $socket = trim((string) ($cfg['db_socket'] ?? ''));
    if ($socket !== '') {
        return sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $socket, $db);
    }
    $host = $cfg['db_host'];
    $port = trim((string) ($cfg['db_port'] ?? ''));
    if ($port !== '' && ctype_digit($port)) {
        return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $db);
    }
    return sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $db);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    global $cfg;
    $dsn = mysql_dsn($cfg);
    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    // PHP 8.5+: PDO::MYSQL_ATTR_INIT_COMMAND is deprecated — use Pdo\Mysql::ATTR_INIT_COMMAND
    $initSql = 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci';
    if (class_exists(\Pdo\Mysql::class, false)) {
        $opts[\Pdo\Mysql::ATTR_INIT_COMMAND] = $initSql;
    } else {
        $opts[PDO::MYSQL_ATTR_INIT_COMMAND] = $initSql;
    }
    $pdo = new PDO($dsn, $cfg['db_user'], $cfg['db_pass'], $opts);
    return $pdo;
}
