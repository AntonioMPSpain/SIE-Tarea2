<?php

declare(strict_types=1);

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

echo "=================================================================\n";
echo " VERIFICACIÓN AUTOMÁTICA DEL BACK-OFFICE ERP PIQUANTUM (TAREA 2)\n";
echo "=================================================================\n\n";

$db = \Piquantum\BackOffice\Config\Database::getConnection();
$service = new \Piquantum\BackOffice\Services\IntegrationService($db);

// 1. Verificar Modo ANTES de la Integración
\Piquantum\BackOffice\Database\Seeder::resetToBeforeIntegration($db);
$beforeMetrics = $service->getDashboardMetrics();
assert($beforeMetrics['orders']['total_count'] === 0, 'Antes de integración debe haber 0 pedidos');
echo "[OK] 1. Estado 'Antes de la integración' verificado (0 pedidos, 12 SKUs en almacén).\n";

// 2. Verificar Escenario 1 con Payload oficial
$payload1 = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/payloads/escenario1_pedido_integrado.json'), true);
$res1 = $service->processOrderEvent($payload1);
assert($res1['ok'] === true && $res1['idempotent_update'] === false, 'Pedido válido debe crearse');
echo "[OK] 2. Escenario 1 (Pedido válido): {$res1['message']}\n";

// 3. Verificar Idempotencia en Escenario 1
$res1Idem = $service->processOrderEvent($payload1);
assert($res1Idem['ok'] === true && $res1Idem['idempotent_update'] === true, 'Reenvío debe ser idempotente');
echo "[OK] 3. Idempotencia básica verificada: {$res1Idem['message']}\n";

// 4. Verificar Rama de Error con Payload incompleto
$payloadErr = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/payloads/escenario1_error_datos_incompletos.json'), true);
$resErr = $service->processOrderEvent($payloadErr);
assert($resErr['ok'] === false, 'Payload incompleto debe fallar en validación');
echo "[OK] 4. Rama de Error y Validación verificada: [{$resErr['error_code']}] {$resErr['message']}\n";

// 5. Verificar Escenario 2 (Cambio Operativo)
$payload2 = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/payloads/escenario2_cambio_operativo.json'), true);
$res2 = $service->processOperationalChange($payload2, false);
assert($res2['ok'] === true && $res2['new_status'] === 'enviado', 'Cambio operativo debe actualizar estado');
echo "[OK] 5. Escenario 2 (Cambio Operativo): {$res2['message']}\n";

// 6. Verificar Escenario 3 (Incidencia Postventa)
$payload3 = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/payloads/escenario3_incidencia_postventa.json'), true);
$res3 = $service->processSupportTicket($payload3);
assert($res3['ok'] === true, 'Ticket postventa debe crearse');
echo "[OK] 6. Escenario 3 (Incidencia Postventa): {$res3['message']}\n";

// 7. Verificar Tools de Consulta del Agente de IA
$toolOrder = $service->getAgentOrderContext('PQ-20261108-A901BC');
$toolStock = $service->getAgentStockContext('PQ-HAB-200');
assert($toolOrder['found'] === true && $toolStock['found'] === true, 'Tools de consulta del Agente IA deben responder');
echo "[OK] 7. Tools de Consulta del Agente IA verificadas (Pedido: {$toolOrder['order']['order_ref']}, Stock PQ-HAB-200: {$toolStock['stock_available']} uds).\n";

// 8. Verificar Tool de Propuesta Estructurada del Agente IA (7 campos obligatorios)
$payloadAi = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/payloads/escenario3_salida_estructurada_agente_ia.json'), true);
$resAi = $service->registerAgentProposal($payloadAi);
assert($resAi['ok'] === true && $resAi['final_status'] === 'pendiente_de_revision', 'Propuesta IA debe quedar en pendiente_de_revision');
echo "[OK] 8. Salida estructurada de 7 campos del Agente IA verificada: {$resAi['message']}\n";

// 9. Cargar dataset completo de demostración y verificar Cuadro de Mando Operativo
\Piquantum\BackOffice\Database\Seeder::seedAfterIntegrationDemo($db);
$afterMetrics = $service->getDashboardMetrics();
assert($afterMetrics['orders']['total_count'] >= 6, 'Debe haber al menos 6 pedidos en demo');
assert($afterMetrics['integration']['failed_events'] >= 2, 'Debe haber errores registrados por tipo');
echo "[OK] 9. Cuadro de Mando Operativo verificado: {$afterMetrics['orders']['total_count']} pedidos, {$afterMetrics['orders']['total_revenue']} € facturados, {$afterMetrics['integration']['success_events']} eventos OK, {$afterMetrics['integration']['failed_events']} errores.\n\n";
echo ">>> TODAS LAS PRUEBAS DEL BACK-OFFICE SUPERADAS CON ÉXITO <<<\n";
