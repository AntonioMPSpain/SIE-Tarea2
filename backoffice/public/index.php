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

// Sin los secretos obligatorios la aplicación no arranca (nunca con valores por defecto ni con los de .env.example)
$missingSecrets = \Piquantum\BackOffice\Config\Database::missingRequiredSecrets();
if (!empty($missingSecrets)) {
    $msg = 'Configuración incompleta del Back-Office: faltan ' . implode(', ', $missingSecrets)
        . ' (o tienen el valor de ejemplo «cambia-esto»). Defínelas en backoffice/.env o como variables de entorno.';
    error_log($msg);
    http_response_code(500);
    if (str_starts_with($requestUri, '/api/')) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error_code' => 'ERR_CONFIGURACION', 'message' => $msg], JSON_UNESCAPED_UNICODE);
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
    }
    exit;
}

if (str_starts_with($requestUri, '/api/')) {
    $api = new \Piquantum\BackOffice\Controllers\ApiController();
    $api->handle($method, $requestUri);
    exit;
}

$web = new \Piquantum\BackOffice\Controllers\WebController();
$web->handle($method, $requestUri);
