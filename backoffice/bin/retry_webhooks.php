<?php

declare(strict_types=1);

/**
 * Reintenta los avisos a n8n que quedaron pendientes (bandeja de salida erp_outbox_webhooks).
 * Pensado para un cron, por ejemplo cada minuto:
 *   * * * * * php /app/bin/retry_webhooks.php >> /proc/1/fd/1 2>&1
 * Alternativa sin cron: POST /api/v1/internal/webhooks/retry (con X-Integration-Token) desde n8n.
 * Cada aviso se reintenta con el mismo cuerpo y el mismo event_id, hasta MAX_WEBHOOK_ATTEMPTS intentos.
 */

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
            $fullPath = $dir . str_replace('\\', '/', substr($relative, strlen($nsPrefix))) . '.php';
            if (file_exists($fullPath)) {
                require_once $fullPath;
            }
            return;
        }
    }
});
date_default_timezone_set('Europe/Madrid');

use Piquantum\BackOffice\Config\Database;
use Piquantum\BackOffice\Services\IntegrationService;

$missing = Database::missingRequiredSecrets();
if (!empty($missing)) {
    fwrite(STDERR, 'Configuración incompleta: faltan ' . implode(', ', $missing) . "\n");
    exit(1);
}

$summary = (new IntegrationService())->retryPendingWebhooks();
printf(
    "[%s] Reintento de avisos a n8n: %d procesados, %d entregados, %d siguen pendientes, %d agotados (FALLIDO)\n",
    date('Y-m-d H:i:s'),
    $summary['processed'],
    $summary['sent'],
    $summary['still_pending'],
    $summary['failed']
);
exit(0);
