<?php
/**
 * CodeArena — PHP Backend Web Entry Point
 */

// If requested URI is an existing file, let the built-in server serve it directly
if (php_sapi_name() === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
    $filePath = __DIR__ . $path;
    if ($path !== '/' && file_exists($filePath) && !is_dir($filePath)) {
        return false;
    }
}

require_once __DIR__ . '/Router.php';

$router = new Router();
$router->handleRequest();
