<?php

declare(strict_types=1);

// Autoloader ligero para el namespace Piquantum\BackOffice
spl_autoload_register(function (string $class): void {
    $prefix = 'Piquantum\\BackOffice\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $baseDir = dirname(__DIR__);

    $map = [
        'Config\\'      => $baseDir . '/config/',
        'Database\\'    => $baseDir . '/database/',
        'Services\\'    => $baseDir . '/src/Services/',
        'Controllers\\' => $baseDir . '/src/Controllers/',
    ];

    foreach ($map as $nsPrefix => $dir) {
        if (str_starts_with($relative, $nsPrefix)) {
            $subFile = str_replace('\\', '/', substr($relative, strlen($nsPrefix))) . '.php';
            $fullPath = $dir . $subFile;
            if (file_exists($fullPath)) {
                require_once $fullPath;
            }
            return;
        }
    }
});

date_default_timezone_set('Europe/Madrid');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Si se sirve mediante el servidor embebido de PHP, permitir archivos estáticos si existieran
if (PHP_SAPI === 'cli-server') {
    $staticFile = __DIR__ . $requestUri;
    if ($requestUri !== '/' && is_file($staticFile)) {
        return false;
    }
}

if (str_starts_with($requestUri, '/api/')) {
    $api = new \Piquantum\BackOffice\Controllers\ApiController();
    $api->handle($method, $requestUri);
    exit;
}

$web = new \Piquantum\BackOffice\Controllers\WebController();
$web->handle($method, $requestUri);
