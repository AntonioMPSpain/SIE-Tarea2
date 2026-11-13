<?php

declare(strict_types=1);

/**
 * Pruebas automáticas del Back-Office (php backoffice/tests/verify_backoffice.php).
 *
 * Usan SIEMPRE una base de datos SQLite temporal propia, que se crea al empezar y se borra al terminar:
 * nunca tocan la base de datos de la aplicación (ni en local ni en el servidor).
 * Devuelven código de salida 0 si todo va bien y 1 si alguna prueba falla.
 */

// --- Entorno de pruebas aislado (antes de cargar nada que lea la configuración) ---------------------
$testDb = sys_get_temp_dir() . '/pq_backoffice_tests_' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("DB_CONNECTION=sqlite");
putenv("DB_SQLITE_PATH={$testDb}");
putenv('INTEGRATION_API_KEY=token-de-pruebas');
putenv('WEBHOOK_HMAC_SECRET=secreto-de-pruebas');
// n8n «apagado»: puerto cerrado, la conexión falla al momento
putenv('N8N_WEBHOOK_OPERATIONAL_CHANGE_URL=http://127.0.0.1:9/n8n-apagado');
putenv('N8N_WEBHOOK_HUMAN_REVIEW_URL=http://127.0.0.1:9/n8n-apagado');

register_shutdown_function(static function () use ($testDb): void {
    foreach ([$testDb, "{$testDb}-journal", "{$testDb}-wal", "{$testDb}-shm"] as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
});

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

use Piquantum\BackOffice\Config\Database;
use Piquantum\BackOffice\Services\IntegrationService;

// --- Mini framework de pruebas (assert() de PHP suele estar desactivado en producción) ---------------
$failures = 0;
$passed = 0;
function check(bool $condition, string $description, string $detail = ''): void
{
    global $failures, $passed;
    if ($condition) {
        $passed++;
        echo "[OK]    {$description}\n";
    } else {
        $failures++;
        echo "[FALLO] {$description}" . ($detail !== '' ? "\n        → {$detail}" : '') . "\n";
    }
}
function payload(string $name): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . "/payloads/{$name}.json"), true, 512, JSON_THROW_ON_ERROR);
}
function scalar(PDO $db, string $sql, array $params = []): mixed
{
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

echo "=================================================================\n";
echo " PRUEBAS AUTOMÁTICAS DEL BACK-OFFICE ERP PIQUANTUM (TAREA 2)\n";
echo " Base de datos temporal: {$testDb}\n";
echo "=================================================================\n\n";

$db = Database::getConnection();
$service = new IntegrationService($db);

// --- C3 / C5: base de datos vacía con solo el catálogo real -------------------------------------------
$counts = $db->query('SELECT (SELECT COUNT(*) FROM erp_orders) o, (SELECT COUNT(*) FROM erp_customers) c,
    (SELECT COUNT(*) FROM erp_tickets) t, (SELECT COUNT(*) FROM erp_integration_logs) l,
    (SELECT COUNT(*) FROM erp_ai_audits) a, (SELECT COUNT(*) FROM erp_inventory) i')->fetch();
check($counts['o'] + $counts['c'] + $counts['t'] + $counts['l'] + $counts['a'] === 0, 'C3: BD nueva sin pedidos, clientes, incidencias, logs ni propuestas', json_encode($counts));
check((int) $counts['i'] === 13, 'C5: almacén con los 13 productos de la tienda', "productos: {$counts['i']}");
check(scalar($db, 'SELECT product_name FROM erp_inventory WHERE sku = ?', ['SAL-002-100ML']) === 'Salsa Chipotle Ahumado', 'C5: código SAL-002-100ML = Salsa Chipotle Ahumado');

// --- Escenario 1: pedido válido ---------------------------------------------------------------------
$order = payload('escenario1_pedido_integrado');
$res = $service->processOrderEvent($order);
check($res['ok'] === true && ($res['idempotent_update'] ?? null) === false, 'Escenario 1: pedido válido creado', $res['message'] ?? '');
check((int) scalar($db, 'SELECT stock_available FROM erp_inventory WHERE sku = ?', ['SAL-002-100ML']) === 18, 'C5: SAL-002-100ML × 2 descuenta el stock de 20 a 18');
check(scalar($db, 'SELECT phone FROM erp_customers WHERE email = ?', ['laura@example.com']) === null, 'C6: cliente sin teléfono se guarda con teléfono NULL');
check(scalar($db, 'SELECT variant_size FROM erp_order_items WHERE sku = ?', ['SAL-005']) === null, 'C6: línea sin tamaño se guarda con variant_size NULL');

// --- C6: rechazar en lugar de inventar ----------------------------------------------------------------
$bad = $order;
$bad['order_ref'] = 'PQ-TEST-SIN-PRECIO';
$bad['event_id'] = 'evt-pq-test-sin-precio';
unset($bad['items'][1]['unit_price']);
$res = $service->processOrderEvent($bad);
check($res['ok'] === false && $res['error_code'] === 'ERR_CAMPOS_OBLIGATORIOS' && str_contains($res['message'], 'items[1].unit_price'), 'C6: línea sin unit_price → ERR_CAMPOS_OBLIGATORIOS que la nombra', $res['message'] ?? '');
$bad = $order;
$bad['order_ref'] = 'PQ-TEST-ESTADO';
$bad['event_id'] = 'evt-pq-test-estado';
$bad['status'] = 'estado_inventado';
$res = $service->processOrderEvent($bad);
check($res['ok'] === false && str_contains($res['message'], 'status'), 'C6: estado fuera del catálogo → rechazado');
$res = $service->processOrderEvent(payload('escenario1_error_datos_incompletos'));
check($res['ok'] === false, 'Escenario 1: payload incompleto → rama de error', $res['message'] ?? '');

// --- C7: SKU desconocido y stock insuficiente ---------------------------------------------------------
$ordersBefore = (int) scalar($db, 'SELECT COUNT(*) FROM erp_orders');
$bad = $order;
$bad['order_ref'] = 'PQ-TEST-SKU';
$bad['event_id'] = 'evt-pq-test-sku';
$bad['items'][1]['sku'] = 'SAL-999';
$res = $service->processOrderEvent($bad);
check($res['ok'] === false && $res['error_code'] === 'ERR_ENTIDAD_NO_ENCONTRADA' && str_contains($res['message'], 'SAL-999'), 'C7: pedido con SAL-999 → ERR_ENTIDAD_NO_ENCONTRADA', $res['message'] ?? '');
check((int) scalar($db, 'SELECT COUNT(*) FROM erp_orders') === $ordersBefore, 'C7: ... y ninguna fila nueva en erp_orders');
$bad = $order;
$bad['order_ref'] = 'PQ-TEST-STOCK';
$bad['event_id'] = 'evt-pq-test-stock';
unset($bad['discount_code'], $bad['discount_amount']);
$bad['items'] = [['sku' => 'SAL-001-200ML', 'product_name' => 'Salsa Carolina Reaper Inferno', 'variant_size' => '200 ml', 'quantity' => 50, 'unit_price' => 18.50]];
$bad['total_amount'] = 929.95;
$res = $service->processOrderEvent($bad);
check($res['ok'] === false && $res['error_code'] === 'ERR_STOCK_INSUFICIENTE', 'C7: 50 uds de SAL-001-200ML → ERR_STOCK_INSUFICIENTE', $res['message'] ?? '');
check((int) scalar($db, 'SELECT stock_available FROM erp_inventory WHERE sku = ?', ['SAL-001-200ML']) === 5, 'C7: ... y el stock no cambia');

// --- Escenario 2: cambio operativo --------------------------------------------------------------------
$res = $service->processOperationalChange(payload('escenario2_cambio_operativo'), false);
check($res['ok'] === true && $res['new_status'] === 'enviado', 'Escenario 2: cambio de estado a enviado', $res['message'] ?? '');

// --- C13: el total tiene que cuadrar (líneas − descuento + envío, ±0,01) -----------------------------
$bad = $order;
$bad['order_ref'] = 'PQ-TEST-TOTAL';
$bad['event_id'] = 'evt-pq-test-total';
unset($bad['discount_code'], $bad['discount_amount']);
$bad['items'] = [['sku' => 'SAL-002-100ML', 'product_name' => 'Salsa Chipotle Ahumado', 'variant_size' => '100 ml', 'quantity' => 2, 'unit_price' => 6.50]];
$bad['total_amount'] = 15.00; // 13,00 + 4,95 = 17,95
$res = $service->processOrderEvent($bad);
check($res['ok'] === false && $res['error_code'] === 'ERR_IMPORTE_INVALIDO' && abs($res['expected_total'] - 17.95) < 0.001, 'C13: total 15,00 con líneas 13,00 + envío 4,95 → ERR_IMPORTE_INVALIDO', $res['message'] ?? '');
$bad['total_amount'] = 17.95;
$bad['discount_code'] = 'BIENVENIDA10';
$res = $service->processOrderEvent($bad);
check($res['ok'] === false && str_contains($res['message'], 'discount_amount'), 'C13: discount_code sin discount_amount → rechazado');
$row = $db->query("SELECT discount_code, discount_amount, subtotal_amount, total_amount FROM erp_orders WHERE order_ref = 'PQ-20261108-A901BC'")->fetch();
check($row['discount_code'] === 'BIENVENIDA10' && abs((float) $row['discount_amount'] - 2.19) < 0.001 && abs((float) $row['subtotal_amount'] - 21.90) < 0.001, 'C13: el pedido guarda discount_amount y subtotal = suma de líneas', json_encode($row));

// --- C11: transiciones de estado (misma tabla que Order::TRANSITIONS de la tienda) --------------------
$res = $service->processOperationalChange(payload('escenario2_cambio_operativo'), false);
check($res['ok'] === true && ($res['unchanged'] ?? false) === true, 'C11: mismo estado → 200 sin cambios', $res['message'] ?? '');
$cancelOrder = $order;
$cancelOrder['order_ref'] = 'PQ-TEST-CANCELADO';
$cancelOrder['event_id'] = 'evt-pq-test-cancelado';
unset($cancelOrder['discount_code'], $cancelOrder['discount_amount']);
$cancelOrder['items'] = [['sku' => 'SAL-003', 'product_name' => 'Salsa Verde Jalapeño & Lima', 'variant_size' => null, 'quantity' => 1, 'unit_price' => 5.90]];
$cancelOrder['total_amount'] = 10.85;
$service->processOrderEvent($cancelOrder);
$res = $service->processOperationalChange(['change_type' => 'ORDER_STATUS', 'order_ref' => 'PQ-TEST-CANCELADO', 'new_status' => 'cancelado'], false);
check($res['ok'] === true, 'C11: pendiente_preparacion → cancelado permitido', $res['message'] ?? '');
$res = $service->processOperationalChange(['change_type' => 'ORDER_STATUS', 'order_ref' => 'PQ-TEST-CANCELADO', 'new_status' => 'enviado'], false);
check($res['ok'] === false && $res['error_code'] === 'ERR_TRANSICION_ESTADO', 'C11: cancelado → enviado → ERR_TRANSICION_ESTADO', $res['message'] ?? '');
check(scalar($db, 'SELECT order_status FROM erp_orders WHERE order_ref = ?', ['PQ-TEST-CANCELADO']) === 'cancelado', 'C11: ... y el pedido sigue cancelado');

// --- C12: idempotencia por event_id y sin retroceder estados ---------------------------------------
$ordersBefore = (int) scalar($db, 'SELECT COUNT(*) FROM erp_orders');
$res = $service->processOrderEvent($order);
check($res['ok'] === true && ($res['result'] ?? '') === 'duplicate', 'C12: mismo evento enviado dos veces → la segunda vez «duplicate»', $res['message'] ?? '');
check((int) scalar($db, 'SELECT COUNT(*) FROM erp_orders') === $ordersBefore, 'C12: ... sin pedidos nuevos');
$late = $order;
$late['event_id'] = 'evt-order-created-tardio';
$late['status'] = 'creado';
$late['items'][0]['quantity'] = 3; // total distinto pero coherente: 19,50 + 8,90 − 2,19 + 4,95 = 31,16
$late['total_amount'] = 31.16;
$res = $service->processOrderEvent($late);
check($res['ok'] === true && ($res['result'] ?? '') === 'ignored', 'C12: order.created que llega después de «enviado» → se ignora', $res['message'] ?? '');
$row = $db->query("SELECT order_status, total_amount FROM erp_orders WHERE order_ref = 'PQ-20261108-A901BC'")->fetch();
check($row['order_status'] === 'enviado' && abs((float) $row['total_amount'] - 24.66) < 0.001, 'C12: ... el estado no retrocede y el total no se sobrescribe', json_encode($row));
$noEvent = $order;
unset($noEvent['event_id']);
$res = $service->processOrderEvent($noEvent);
check($res['ok'] === false && str_contains($res['message'], 'event_id'), 'C12: pedido sin event_id → rechazado');
$created = $order;
$created['order_ref'] = 'PQ-TEST-AVANZA';
$created['event_id'] = 'evt-avanza-1';
$created['status'] = 'creado';
$service->processOrderEvent($created);
$paid = $created;
$paid['event_id'] = 'evt-avanza-2';
$paid['status'] = 'pagado_simulado';
$res = $service->processOrderEvent($paid);
check(($res['result'] ?? '') === 'updated' && scalar($db, 'SELECT order_status FROM erp_orders WHERE order_ref = ?', ['PQ-TEST-AVANZA']) === 'pendiente_preparacion', 'C12: payment.simulated hace avanzar creado → pendiente_preparacion', $res['message'] ?? '');

// --- Escenario 3 y C8: incidencia y chat_id de Telegram -----------------------------------------------
$ticket = payload('escenario3_incidencia_postventa');
$res = $service->processSupportTicket($ticket);
check($res['ok'] === true && $res['telegram_chat_id'] === null, 'C8: incidencia sin telegram_chat_id se crea igualmente', $res['message'] ?? '');
$badTicket = $ticket;
$badTicket['ticket_ref'] = 'INC-TEST-ARROBA';
$badTicket['event_id'] = 'evt-inc-test-arroba';
$badTicket['telegram_chat_id'] = '@laura_piquantum_test';
$res = $service->processSupportTicket($badTicket);
check($res['ok'] === false && str_contains($res['message'], 'telegram_chat_id'), 'C8: telegram_chat_id con @usuario → rechazado');

// --- Tools del agente y propuesta estructurada ------------------------------------------------------
$toolOrder = $service->getAgentOrderContext('PQ-20261108-A901BC');
$toolStock = $service->getAgentStockContext('SAL-002-100ML');
check($toolOrder['found'] === true && $toolStock['found'] === true && $toolStock['stock_available'] === (int) scalar($db, 'SELECT stock_available FROM erp_inventory WHERE sku = ?', ['SAL-002-100ML']), 'Tools del agente: consulta de pedido y de stock');
$proposal = payload('escenario3_salida_estructurada_agente_ia');
$incomplete = $proposal;
unset($incomplete['mensaje_propuesto_telegram']);
$res = $service->registerAgentProposal($incomplete);
check($res['ok'] === false && $res['error_code'] === 'ERR_SALIDA_AGENTE_INCOMPLETA', 'C6: propuesta sin mensaje para el cliente → rechazada');
$res = $service->registerAgentProposal($proposal);
check($res['ok'] === true && $res['final_status'] === 'pendiente_de_revision', 'Agente IA: propuesta con los 7 campos queda pendiente_de_revision');
$auditId = (int) ($res['audit_id'] ?? 0);

// --- Supervisión humana -------------------------------------------------------------------------------
$res = $service->reviewAgentProposal($auditId, '', '');
check($res['ok'] === false && $res['error_code'] === 'ERR_CAMPOS_OBLIGATORIOS', 'C6: revisión sin decisión ni revisor → rechazada (nunca se aprueba por defecto)');
$res = $service->reviewAgentProposal($auditId, 'aprobado', 'operador@erp.piquantum.test');
check($res['ok'] === true && $res['telegram_notified'] === false && str_contains($res['message'], 'Sin chat_id'), 'C8: aprobar sin chat_id → no se notifica y queda en el log', $res['message'] ?? '');

// C4: con chat_id y n8n apagado → telegram_notified = 0, sin resolver y error en el log
$tgTicket = $ticket;
$tgTicket['ticket_ref'] = 'INC-TEST-TG';
$tgTicket['event_id'] = 'evt-inc-test-tg';
$tgTicket['telegram_chat_id'] = '123456789';
$service->processSupportTicket($tgTicket);
$tgProposal = $proposal;
$tgProposal['ticket_ref'] = 'INC-TEST-TG';
$tgAudit = (int) $service->registerAgentProposal($tgProposal)['audit_id'];
$res = $service->reviewAgentProposal($tgAudit, 'aprobado', 'operador@erp.piquantum.test');
check((int) scalar($db, 'SELECT telegram_notified FROM erp_ai_audits WHERE id = ?', [$tgAudit]) === 0, 'C4: n8n apagado → telegram_notified = 0');
check(scalar($db, 'SELECT status FROM erp_tickets WHERE ticket_ref = ?', ['INC-TEST-TG']) !== 'RESUELTA', 'C4: ... y la incidencia no se marca como resuelta');
check((int) scalar($db, 'SELECT COUNT(*) FROM erp_integration_logs WHERE error_type = ?', ['ERR_NOTIFICACION_TELEGRAM']) === 1, 'C4: ... y el fallo queda en el log (ERR_NOTIFICACION_TELEGRAM)');

// --- C14: primero se guarda, después se envía; reintentos con el mismo event_id ---------------------
$res = $service->processOperationalChange(['change_type' => 'ORDER_STATUS', 'order_ref' => 'PQ-TEST-AVANZA', 'new_status' => 'enviado'], true);
$outbox = $db->query("SELECT * FROM erp_outbox_webhooks WHERE entity_type = 'order' AND entity_ref = 'PQ-TEST-AVANZA'")->fetch();
check($res['ok'] === true && $res['sync_status'] === 'PENDIENTE_SINCRONIZACION', 'C14: cambio de estado con n8n apagado → se guarda y queda PENDIENTE_SINCRONIZACION', $res['message'] ?? '');
check(scalar($db, 'SELECT order_status FROM erp_orders WHERE order_ref = ?', ['PQ-TEST-AVANZA']) === 'enviado' && $outbox && $outbox['status'] === 'PENDIENTE' && (int) $outbox['attempts'] === 1, 'C14: ... el cambio está guardado y el aviso en la bandeja de salida (1 intento)');

// Aviso que se agota: stock de SAL-004 con n8n apagado → 5 intentos → FALLIDO
$service->processOperationalChange(['change_type' => 'STOCK_UPDATE', 'sku' => 'SAL-004', 'new_stock' => 69], true);
$stockOutboxId = (int) scalar($db, "SELECT id FROM erp_outbox_webhooks WHERE entity_type = 'inventory' AND entity_ref = 'SAL-004'");
for ($i = 0; $i < IntegrationService::MAX_WEBHOOK_ATTEMPTS - 1; $i++) {
    $service->deliverWebhook($stockOutboxId); // solo este aviso; los demás siguen pendientes
}
$stockOutbox = $db->query("SELECT status, attempts FROM erp_outbox_webhooks WHERE entity_type = 'inventory' AND entity_ref = 'SAL-004'")->fetch();
check($stockOutbox['status'] === 'FALLIDO' && (int) $stockOutbox['attempts'] === IntegrationService::MAX_WEBHOOK_ATTEMPTS, 'C14: tras el máximo de intentos el aviso queda FALLIDO', json_encode($stockOutbox));
check(scalar($db, 'SELECT sync_status FROM erp_inventory WHERE sku = ?', ['SAL-004']) === 'ERROR_SINCRONIZACION', 'C14: ... y el producto queda en ERROR_SINCRONIZACION');

// Se «enciende» n8n (servidor PHP simulado) y se lanza el reintento
$fakeLog = sys_get_temp_dir() . '/pq_fake_n8n_' . bin2hex(random_bytes(4)) . '.log';
$fakePort = random_int(20000, 40000);
$fakeEnv = array_merge(getenv(), ['FAKE_N8N_LOG' => $fakeLog]);
$nullDev = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
$fake = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$fakePort}", __DIR__ . '/fixtures/fake_n8n.php'], [1 => ['file', $nullDev, 'w'], 2 => ['file', $nullDev, 'w']], $pipes, null, $fakeEnv);
for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $fakePort) === false; $i++) {
    usleep(100000);
}
putenv("N8N_WEBHOOK_OPERATIONAL_CHANGE_URL=http://127.0.0.1:{$fakePort}/webhook/operational-change");
putenv("N8N_WEBHOOK_HUMAN_REVIEW_URL=http://127.0.0.1:{$fakePort}/webhook/human-review");
$summary = $service->retryPendingWebhooks();
proc_terminate($fake);
$received = file_exists($fakeLog) ? array_map(fn ($l) => json_decode($l, true), file($fakeLog, FILE_IGNORE_NEW_LINES)) : [];
@unlink($fakeLog);
check($summary['sent'] >= 2, 'C14: reintento con n8n encendido → avisos entregados', json_encode(array_diff_key($summary, ['details' => 1])));
check(scalar($db, 'SELECT sync_status FROM erp_orders WHERE order_ref = ?', ['PQ-TEST-AVANZA']) === 'SINCRONIZADO', 'C14: ... el pedido pasa a SINCRONIZADO');
$receivedIds = array_map(fn ($r) => json_decode($r['body'], true)['event_id'] ?? null, $received);
check(in_array($outbox['event_id'], $receivedIds, true), 'C14: ... con el mismo event_id del primer intento', $outbox['event_id']);
$expectedSig = 'sha256=' . hash_hmac('sha256', (string) $outbox['body_json'], 'secreto-de-pruebas');
check(in_array($expectedSig, array_column($received, 'signature'), true), 'C14: ... y el cuerpo firmado con HMAC es exactamente el guardado');
check((int) scalar($db, 'SELECT telegram_notified FROM erp_ai_audits WHERE id = ?', [$tgAudit]) === 1
    && scalar($db, 'SELECT status FROM erp_tickets WHERE ticket_ref = ?', ['INC-TEST-TG']) === 'RESUELTA', 'C14/C4: la notificación pendiente de Telegram se entrega → telegram_notified = 1 y la incidencia se resuelve');
check(scalar($db, 'SELECT status FROM erp_outbox_webhooks WHERE entity_type = ? AND entity_ref = ?', ['inventory', 'SAL-004']) === 'FALLIDO', 'C14: un aviso FALLIDO no se reintenta automáticamente');

// --- C4: cuadro de mando solo con datos reales ------------------------------------------------------
$m = $service->getDashboardMetrics();
check($m['orders']['total_count'] === 2, 'Cuadro de mando: 2 pedidos (el cancelado no cuenta)', (string) $m['orders']['total_count']);
check(array_keys($m['conversion']['funnel']) === ['orders_integrated', 'orders_paid', 'orders_with_ticket'], 'C4: el embudo solo tiene etapas con datos del Back-Office');
check($m['conversion']['funnel']['orders_integrated'] === 3 && $m['conversion']['funnel']['orders_with_ticket'] === 1, 'C4: cifras del embudo salen de la BD', json_encode($m['conversion']['funnel']));
$realMinutes = (float) scalar($db, 'SELECT AVG((julianday(resolved_at) - julianday(created_at)) * 1440) FROM erp_tickets WHERE resolved_at IS NOT NULL');
check($m['tickets']['avg_resolution_minutes'] !== null && abs($m['tickets']['avg_resolution_minutes'] - round($realMinutes, 1)) < 0.05, 'C4: tiempo de resolución real, sin mínimos', (string) $m['tickets']['avg_resolution_minutes']);
check($m['integration']['failed_events'] >= 5, 'Cuadro de mando: errores de integración contabilizados por tipo', (string) $m['integration']['failed_events']);

// --- C15: carga del catálogo desde la tienda (alta o actualización por sku, idempotente) --------------
$import = payload('carga_catalogo_tienda');
$import['products'][] = ['sku' => 'SAL-010', 'product_name' => 'Producto nuevo de prueba', 'category' => 'suaves', 'variant_size' => null, 'unit_price' => 6.00, 'stock_available' => 12];
$res1 = $service->importInventory($import);
$res2 = $service->importInventory($import);
check($res1['ok'] === true && $res1['created'] === 1, 'C15: importar el catálogo da de alta los productos nuevos', $res1['message'] ?? '');
check($res2['ok'] === true && $res2['created'] === 0 && $res2['updated'] === 0 && $res2['unchanged'] === 14, 'C15: importar dos veces la misma lista → sin cambios', $res2['message'] ?? '');
check((int) scalar($db, 'SELECT COUNT(*) FROM erp_inventory') === 14, 'C15: ... y sin productos duplicados');
check((int) scalar($db, 'SELECT stock_available FROM erp_inventory WHERE sku = ?', ['SAL-002-100ML']) === 20, 'C15: el stock queda como el de la tienda');
$badImport = ['products' => [['sku' => 'SAL-011', 'product_name' => 'X', 'category' => 'suaves', 'unit_price' => 5.0, 'stock_available' => 3], ['sku' => 'SAL 012', 'product_name' => '', 'category' => 'suaves', 'unit_price' => 0, 'stock_available' => -1]]];
$res = $service->importInventory($badImport);
check($res['ok'] === false && (int) scalar($db, 'SELECT COUNT(*) FROM erp_inventory WHERE sku = ?', ['SAL-011']) === 0, 'C15: si un producto no es válido no se guarda ninguno', $res['message'] ?? '');
check((int) scalar($db, "SELECT COUNT(*) FROM erp_integration_logs WHERE scenario = 'CARGA_CATALOGO'") === 3, 'C15: cada ejecución queda en el log');

// --- C16: Filtros operativos, Ficha de Clientes CRM y Exportación CSV de Auditoría/Logs ---------------
$filteredSent = $service->getFilteredOrders('enviado', null, null);
check(count($filteredSent) >= 1 && $filteredSent[0]['order_status'] === 'enviado', 'C16: filtro de pedidos por estado operativo (enviado)');
$filteredSearch = $service->getFilteredOrders(null, null, 'PQ-20261108-A901BC');
check(count($filteredSearch) === 1 && $filteredSearch[0]['order_ref'] === 'PQ-20261108-A901BC', 'C16: buscador de pedidos por referencia única');

$customersWithTickets = $service->getCustomersWithTicketCounts(null, 'laura@example.com');
check(count($customersWithTickets) === 1 && (int) $customersWithTickets[0]['tickets_count'] >= 1 && isset($customersWithTickets[0]['name'], $customersWithTickets[0]['shipping_address']), 'C16: ficha de clientes sincronizados (erp_customers) incluye nombre, dirección y recuento de incidencias postventa');

$errorLogs = $service->getFilteredLogs('ERROR', null, null, 100);
check(count($errorLogs) >= 5 && $errorLogs[0]['status'] === 'ERROR', 'C16: filtro de logs de trazabilidad por resultado ERROR');

$csvLogs = $service->exportLogsToCsv('ERROR', null);
check(str_starts_with($csvLogs, "\xEF\xBB\xBFid;execution_id;scenario;") && str_contains($csvLogs, ';ERROR;'), 'C16: exportación de logs de trazabilidad a CSV con BOM UTF-8');

$csvAudits = $service->exportAuditsToCsv();
check(str_starts_with($csvAudits, "\xEF\xBB\xBFaudit_id;ticket_ref;order_ref;") && str_contains($csvAudits, 'INC-20261108-3045'), 'C16: exportación de auditoría del Agente IA a CSV');

// --- Resumen ------------------------------------------------------------------------------------------
echo "\n-----------------------------------------------------------------\n";
echo " {$passed} pruebas correctas, {$failures} fallidas\n";
echo "-----------------------------------------------------------------\n";
exit($failures === 0 ? 0 : 1);
