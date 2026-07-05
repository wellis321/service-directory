<?php
declare(strict_types=1);

/**
 * Load key=value pairs from a .env file (no Composer dependency).
 * Supports # comments, optional double/single quotes around values.
 */
function parse_dotenv_file(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    $out = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        if (
            $value !== ''
            && (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            )
        ) {
            $value = stripcslashes(substr($value, 1, -1));
        }
        $out[$key] = $value;
    }
    return $out;
}

/**
 * Map of ENV_VAR_NAME => $cfg key (used by load_app_config).
 */
function app_config_env_map(): array
{
    return [
        'DB_HOST' => 'db_host',
        'DB_PORT' => 'db_port',
        'DB_SOCKET' => 'db_socket',
        'DB_NAME' => 'db_name',
        'DB_USER' => 'db_user',
        'DB_PASS' => 'db_pass',
        'SITE_URL' => 'site_url',
        'SITE_NAME' => 'site_name',
        'SMTP_HOST' => 'smtp_host',
        'SMTP_PORT' => 'smtp_port',
        'SMTP_USER' => 'smtp_user',
        'SMTP_PASS' => 'smtp_pass',
        'FROM_EMAIL' => 'from_email',
        'FROM_NAME' => 'from_name',
        'STRIPE_SECRET_KEY' => 'stripe_secret_key',
        'STRIPE_PUBLISHABLE_KEY' => 'stripe_publishable_key',
        'STRIPE_WEBHOOK_SECRET' => 'stripe_webhook_secret',
        'STRIPE_PRICE_PREMIUM' => 'stripe_price_premium',
        'STRIPE_PRICE_PRO' => 'stripe_price_pro',
        'CI_CSV_URL' => 'ci_csv_url',
    ];
}

function app_config_defaults(): array
{
    return [
        'db_host' => '127.0.0.1',
        'db_port' => '',
        'db_socket' => '',
        'db_name' => 'service-directory',
        'db_user' => '',
        'db_pass' => '',
        'site_url' => 'http://localhost',
        'site_name' => 'CareScotland',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_user' => '',
        'smtp_pass' => '',
        'from_email' => '',
        'from_name' => 'CareScotland',
        'stripe_secret_key' => '',
        'stripe_publishable_key' => '',
        'stripe_webhook_secret' => '',
        'stripe_price_premium' => '',
        'stripe_price_pro' => '',
        'ci_csv_url' => 'https://www.careinspectorate.com/images/Datastore/260331_DatastoreExternal.csv',
    ];
}

/**
 * Application config: defaults < .env file < real getenv() (Hostinger panel, Docker, CLI).
 * Safe to call multiple times (cached).
 */
function load_app_config(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    if (!defined('ROOT')) {
        define('ROOT', dirname(__DIR__));
    }

    $defaults = app_config_defaults();
    $map = app_config_env_map();
    $filePath = ROOT . '/.env';
    $fromFile = parse_dotenv_file($filePath);

    $cfg = $defaults;
    foreach ($map as $envName => $cfgKey) {
        if (isset($fromFile[$envName]) && $fromFile[$envName] !== '') {
            $cfg[$cfgKey] = $fromFile[$envName];
        }
    }
    foreach ($map as $envName => $cfgKey) {
        $v = getenv($envName);
        if ($v !== false && $v !== '') {
            $cfg[$cfgKey] = $v;
        }
    }

    if ($cfg['smtp_port'] !== '' && is_numeric($cfg['smtp_port'])) {
        $cfg['smtp_port'] = (string) (int) $cfg['smtp_port'];
    }

    $cfg['site_url'] = rtrim((string) $cfg['site_url'], '/');

    return $cache = $cfg;
}

function app_env(): string
{
    $path = (defined('ROOT') ? ROOT : dirname(__DIR__)) . '/.env';
    $v = parse_dotenv_file($path)['APP_ENV'] ?? getenv('APP_ENV');
    if ($v === false || $v === '') {
        return 'production';
    }
    return strtolower((string) $v);
}

function app_debug(): bool
{
    $path = (defined('ROOT') ? ROOT : dirname(__DIR__)) . '/.env';
    $raw = parse_dotenv_file($path)['APP_DEBUG'] ?? getenv('APP_DEBUG');
    if ($raw === false || $raw === '') {
        return false;
    }
    return filter_var($raw, FILTER_VALIDATE_BOOLEAN);
}
