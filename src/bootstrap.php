<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');

function app_config(): array {
    static $config;
    if ($config !== null) return $config;
    $paths = [
        dirname(__DIR__, 3) . '/ttlock_ekey_config.php', // acima de public_html / htdocs
        dirname(__DIR__) . '/config.local.php',
    ];
    foreach ($paths as $path) {
        if (is_file($path)) {
            $loaded = require $path;
            if (!is_array($loaded)) throw new RuntimeException('Arquivo de configuração inválido.');
            $config = $loaded;
            return $config;
        }
    }
    throw new RuntimeException('Configuração ausente. Consulte README.md.');
}

function config(string $name, mixed $fallback = null): mixed {
    return app_config()[$name] ?? $fallback;
}

function db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    $dsn = 'mysql:host=' . config('db_host', 'localhost') .
        ';port=' . (int) config('db_port', 3306) .
        ';dbname=' . config('db_name') . ';charset=utf8mb4';
    $db = new PDO($dsn, (string) config('db_user'), (string) config('db_password'), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $db->exec("SET time_zone = '+00:00'");
    return $db;
}

function app_https(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function app_localhost(): bool {
    return in_array(strtolower((string) ($_SERVER['HTTP_HOST'] ?? '')), [
        'localhost', 'localhost:80', '127.0.0.1', '127.0.0.1:80', '[::1]'
    ], true);
}

function security_headers(): void {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; base-uri 'none'; frame-ancestors 'none'; object-src 'none'; form-action 'self'");
    header('Cache-Control: no-store, private');
    if (!app_https() && !app_localhost() && PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit('HTTPS obrigatório para o dashboard.');
    }
}
security_headers();

session_name('ttlock_ekey_session');
session_set_cookie_params([
    'lifetime' => 0, 'path' => '/',
    'secure' => app_https(), 'httponly' => true, 'samesite' => 'Lax'
]);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

require_once __DIR__ . '/core.php';
require_once __DIR__ . '/ttlock.php';
