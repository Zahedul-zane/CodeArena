<?php
/**
 * CodeArena — Backend Configuration
 */

// Load .env file if available
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            list($key, $val) = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\0\x0B\"'");
            if (!getenv($key)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
            }
        }
    }
}

// Configuration Constants
define('SECRET_KEY', getenv('SECRET_KEY') ?: 'codearena-secret-2026');
define('REDIS_ADDR', getenv('REDIS_ADDR') ?: '127.0.0.1:6379');
define('REDIS_QUEUE', getenv('REDIS_QUEUE') ?: 'submissions');
define('REDIS_RESULT_PREFIX', getenv('REDIS_RESULT_PREFIX') ?: 'result:');
define('PORT', (int)(getenv('PORT') ?: 5000));

// Resolve Database Path
$dataFileEnv = getenv('DATA_FILE') ?: 'data/contest.xlsx';
if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $dataFileEnv) || str_starts_with($dataFileEnv, '/')) {
    define('DATA_FILE', $dataFileEnv);
} else {
    define('DATA_FILE', __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $dataFileEnv));
}

// Result Cache Directory
define('RESULTS_DIR', __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'results');
if (!is_dir(RESULTS_DIR)) {
    @mkdir(RESULTS_DIR, 0777, true);
}
