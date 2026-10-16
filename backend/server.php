<?php
/**
 * CodeArena — Backend Server Launcher
 * Run with: php server.php or php app.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/ExcelDatabase.php';

echo "\n";
echo "  ======================================================\n";
echo "    CodeArena — High-Performance PHP Contest Backend\n";
echo "  ======================================================\n";
echo "\n";

// 1. Verify extensions
if (!extension_loaded('zip')) {
    echo "  [Warning] 'zip' extension not detected in PHP. Please enable extension=zip in php.ini\n";
}

// 2. Initialize database
try {
    $db = new ExcelDatabase();
    $stats = $db->getStats();
    echo "  [Database] Excel Workbook: " . DATA_FILE . "\n";
    echo "  [Database] Total Records : " . $stats['total_records'] . " across 7 worksheets\n";
} catch (Exception $e) {
    echo "  [Database Error] " . $e->getMessage() . "\n";
}

// 3. Port & Host
$port = PORT;
$host = '0.0.0.0';

echo "  [Server]   Starting built-in server on http://localhost:{$port}\n";
echo "  [API]      Base API URL: http://localhost:{$port}/api\n";
echo "  [Admin]    Default account: admin / admin123\n";
echo "\n";
echo "  Press Ctrl+C to stop the server.\n\n";

// 4. Launch PHP built-in server
$routerFile = escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'index.php');
passthru("php -S {$host}:{$port} {$routerFile}");
