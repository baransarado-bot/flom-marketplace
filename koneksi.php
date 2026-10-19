<?php
$serverName = strtolower((string)($_SERVER['SERVER_NAME'] ?? 'localhost'));
$isLocal = in_array($serverName, ['localhost', '127.0.0.1', '::1'], true);

$documentRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? __DIR__), DIRECTORY_SEPARATOR);
$privateConfigPath = getenv('DINKOP_DB_CONFIG_FILE') ?: dirname($documentRoot) . DIRECTORY_SEPARATOR . 'flom_db_config.php';
$privateConfig = [];
if (is_file($privateConfigPath)) {
    $loadedConfig = require $privateConfigPath;
    if (!is_array($loadedConfig)) {
        http_response_code(500);
        error_log('Dinkop private database configuration must return an array.');
        exit('Konfigurasi database Dinkop tidak valid.');
    }
    $privateConfig = $loadedConfig;
}

$dbHost = getenv('DINKOP_DB_HOST') ?: ($privateConfig['host'] ?? ($isLocal ? 'localhost' : ''));
$dbUser = getenv('DINKOP_DB_USER') ?: ($privateConfig['user'] ?? ($isLocal ? 'root' : ''));
$dbPass = getenv('DINKOP_DB_PASSWORD');
$dbName = getenv('DINKOP_DB_NAME') ?: ($privateConfig['database'] ?? 'db_dinkop');

if ($dbPass === false) {
    $dbPass = $privateConfig['password'] ?? ($isLocal ? '' : '');
}
if (!$dbHost || !$dbUser || !$dbName || (!$isLocal && $dbPass === '')) {
    http_response_code(500);
    exit('Konfigurasi database Dinkop belum lengkap. Atur variabel DINKOP_DB_* atau file flom_db_config.php di luar document root.');
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$koneksi = mysqli_connect($dbHost, $dbUser, $dbPass, $dbName);
mysqli_set_charset($koneksi, 'utf8mb4');

date_default_timezone_set('Asia/Makassar');
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'httponly' => true,
        'secure' => $isHttps,
        'samesite' => 'Lax',
    ]);
    session_start();
}
