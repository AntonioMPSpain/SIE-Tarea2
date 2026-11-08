<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Services;

use PDO;
use Piquantum\BackOffice\Config\Database;
use Throwable;

class IntegrationService
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getConnection();
    }

    // =========================================================================
    // ESCENARIO 1: RECEPCIÓN E INTEGRACIÓN DE PEDIDO (order.created / payment.simulated)
    // =========================================================================
    public function processOrderEvent(array $payload, string $direction = 'eCommerce -> n8n -> BackOffice'): array
    {
        $startTime = microtime(true);
        $executionId = $payload['execution_id'] ?? ('exec_' . bin2hex(random_bytes(5)));
        $eventType = (string) ($payload['event_type'] ?? 'order.created');

        // Catálogo de estados del contrato y su normalización (Apartado 3.c)
        $statusMap = [
            'created'               => 'creado',
            'creado'                => 'creado',
            'paid'                  => 'pendiente_preparacion',
            'pagado_simulado'       => 'pendiente_preparacion',
            'pendiente_preparacion' => 'pendiente_preparacion',
            'shipped'               => 'enviado',
            'enviado'               => 'enviado',
            'cancelled'             => 'cancelado',
            'cancelado'             => 'cancelado',
            'incidencia'            => 'incidencia',
        ];

        // 1. Validación estricta de campos obligatorios (Apartado 3.b y 4.b).
        //    Nada se rellena con valores inventados: si falta un obligatorio, se rechaza; los opcionales quedan a NULL.
        $missingFields = [];
        $orderRef = trim((string) ($payload['order_ref'] ?? ''));
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
        $customerEmail = trim((string) ($customer['email'] ?? ''));
        $customerName = trim((string) ($customer['name'] ?? ''));
        $items = $payload['items'] ?? [];
        $rawStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        $occurredAtRaw = trim((string) ($payload['occurred_at'] ?? ''));
        $occurredTs = $occurredAtRaw !== '' ? strtotime($occurredAtRaw) : false;
        $totalAmount = $this->numberOrNull($payload['total_amount'] ?? null);
        $shippingAmount = $this->numberOrNull($payload['shipping_amount'] ?? null);

        if ($orderRef === '') {
            $missingFields[] = 'order_ref';
        }
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $missingFields[] = 'customer.email (formato válido)';
        }
        if ($customerName === '') {
            $missingFields[] = 'customer.name';
        }
        if (!isset($statusMap[$rawStatus])) {
            $missingFields[] = 'status (valores admitidos: ' . implode(', ', array_keys($statusMap)) . ')';
        }
        if ($occurredTs === false) {
            $missingFields[] = 'occurred_at (fecha ISO 8601)';
        }
        if ($shippingAmount === null || $shippingAmount < 0) {
            $missingFields[] = 'shipping_amount (número >= 0)';
        }
        if (!is_array($items) || count($items) === 0) {
            $missingFields[] = 'items[] (mínimo 1 línea de pedido)';
        }
        if ($totalAmount === null || $totalAmount <= 0) {
            $missingFields[] = 'total_amount (debe ser > 0)';
        }

        // Validar estructura de cada línea de pedido
        if (is_array($items)) {
            foreach ($items as $idx => $item) {
                $item = is_array($item) ? $item : [];
                if (trim((string) ($item['sku'] ?? '')) === '') {
                    $missingFields[] = "items[{$idx}].sku";
                }
                if (trim((string) ($item['product_name'] ?? '')) === '') {
                    $missingFields[] = "items[{$idx}].product_name";
                }
                $qty = $item['quantity'] ?? null;
                if (!is_int($qty) && !(is_string($qty) && ctype_digit($qty)) || (int) $qty <= 0) {
                    $missingFields[] = "items[{$idx}].quantity (entero > 0)";
                }
                $price = $this->numberOrNull($item['unit_price'] ?? null);
                if ($price === null || $price <= 0) {
                    $missingFields[] = "items[{$idx}].unit_price (número > 0)";
                }
            }
        }

        if (!empty($missingFields)) {
            $errorType = in_array('total_amount (debe ser > 0)', $missingFields, true)
                ? 'ERR_IMPORTE_INVALIDO'
                : 'ERR_CAMPOS_OBLIGATORIOS';

            $validationMsg = 'Validación rechazada. Campos obligatorios ausentes o inválidos: ' . implode(', ', $missingFields);
            $response = [
                'ok'             => false,
                'execution_id'   => $executionId,
                'scenario'       => 'ESCENARIO_1_PEDIDO',
                'error_code'     => $errorType,
                'invalid_fields' => $missingFields,
                'message'        => $validationMsg,
            ];

            $this->logExecution(
                $executionId,
                'ESCENARIO_1_PEDIDO',
                $eventType,
                $direction,
                $orderRef ?: 'SIN_REFERENCIA',
                'ERROR',
                $errorType,
                $validationMsg,
                $payload,
                $response,
                $startTime
            );

            return $response;
        }

        // 2. Transformación / Normalización de estados y datos (Apartado 3.c)
        $normalizedOrderStatus = $statusMap[$rawStatus];
        $paymentStatus = $this->paymentStatusFor($normalizedOrderStatus);
        $now = date('Y-m-d H:i:s', (int) $occurredTs);
        $customerPhone = $this->stringOrNull($customer['phone'] ?? null);
        $shippingAddress = $this->stringOrNull($payload['shipping_address'] ?? ($customer['address'] ?? null));

        try {
            $this->db->beginTransaction();

            // 3. Control de Idempotencia por order_ref (Apartado 13 - Rúbrica: idempotencia básica)
            $stmtOrd = $this->db->prepare('SELECT * FROM erp_orders WHERE order_ref = :ref LIMIT 1');
            $stmtOrd->execute([':ref' => $orderRef]);
            $existingOrder = $stmtOrd->fetch();

            // 4. Pedido nuevo: TODOS los SKU tienen que existir en el almacén y con stock suficiente,
            //    antes de escribir nada. Si falla una línea, no se guarda nada (rollback).
            if (!$existingOrder) {
                $stockError = $this->checkSkusAndStock($items);
                if ($stockError !== null) {
                    $this->db->rollBack();
                    $response = [
                        'ok'           => false,
                        'execution_id' => $executionId,
                        'scenario'     => 'ESCENARIO_1_PEDIDO',
                        'error_code'   => $stockError['error_code'],
                        'details'      => $stockError['details'],
                        'message'      => $stockError['message'],
                    ];
                    $this->logExecution($executionId, 'ESCENARIO_1_PEDIDO', $eventType, $direction, $orderRef, 'ERROR', $stockError['error_code'], $stockError['message'], $payload, $response, $startTime);
                    return $response;
                }
            }

            // 5. Upsert Cliente en Back-Office
            $stmtCust = $this->db->prepare('SELECT * FROM erp_customers WHERE email = :email LIMIT 1');
            $stmtCust->execute([':email' => strtolower($customerEmail)]);
            $existingCustomer = $stmtCust->fetch();

            if ($existingCustomer) {
                $customerId = (int) $existingCustomer['id'];
                $updCust = $this->db->prepare('
                    UPDATE erp_customers
                    SET name = :name,
                        phone = COALESCE(:phone, phone),
                        shipping_address = COALESCE(:address, shipping_address),
                        updated_at = :updated_at
                    WHERE id = :id
                ');
                $updCust->execute([
                    ':name'       => $customerName,
                    ':phone'      => $customerPhone,
                    ':address'    => $shippingAddress,
                    ':updated_at' => $now,
                    ':id'         => $customerId,
                ]);
            } else {
                $insCust = $this->db->prepare('
                    INSERT INTO erp_customers (external_customer_id, name, email, phone, shipping_address, segment, orders_count, total_spent, created_at, updated_at)
                    VALUES (:ext_id, :name, :email, :phone, :address, :segment, 0, 0.00, :created_at, :updated_at)
                ');
                $insCust->execute([
                    ':ext_id'     => $this->stringOrNull($customer['id'] ?? null),
                    ':name'       => $customerName,
                    ':email'      => strtolower($customerEmail),
                    ':phone'      => $customerPhone,
                    ':address'    => $shippingAddress,
                    ':segment'    => $totalAmount >= 45 ? 'VIP_PICANTE' : 'ESTANDAR',
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
                $customerId = (int) $this->db->lastInsertId();
            }

            $isIdempotentUpdate = false;
            if ($existingOrder) {
                $isIdempotentUpdate = true;
                $orderId = (int) $existingOrder['id'];
                $updOrder = $this->db->prepare('
                    UPDATE erp_orders
                    SET payment_status = :payment_status,
                        order_status = :order_status,
                        total_amount = :total_amount,
                        updated_at = :updated_at
                    WHERE id = :id
                ');
                $updOrder->execute([
                    ':payment_status' => $paymentStatus,
                    ':order_status'   => $normalizedOrderStatus,
                    ':total_amount'   => $totalAmount,
                    ':updated_at'     => date('Y-m-d H:i:s'),
                    ':id'             => $orderId,
                ]);
            } else {
                // Calcular desglose de IVA (21% incluido como en Piquantum Tarea 1)
                $subtotal = round($totalAmount / 1.21, 2);
                $tax = round($totalAmount - $subtotal, 2);
                $shipping = $shippingAmount;

                $insOrd = $this->db->prepare('
                    INSERT INTO erp_orders (
                        order_ref, customer_id, customer_name, customer_email, shipping_address,
                        subtotal_amount, tax_amount, shipping_amount, discount_code, total_amount,
                        payment_method, payment_status, order_status, priority, sync_status,
                        channel_created_at, integrated_at, updated_at
                    ) VALUES (
                        :order_ref, :customer_id, :customer_name, :customer_email, :shipping_address,
                        :subtotal, :tax, :shipping, :discount_code, :total,
                        :payment_method, :payment_status, :order_status, :priority, :sync_status,
                        :channel_created_at, :integrated_at, :updated_at
                    )
                ');
                $insOrd->execute([
                    ':order_ref'          => $orderRef,
                    ':customer_id'        => $customerId,
                    ':customer_name'      => $customerName,
                    ':customer_email'     => strtolower($customerEmail),
                    ':shipping_address'   => $shippingAddress,
                    ':subtotal'           => $subtotal,
                    ':tax'                => $tax,
                    ':shipping'           => $shipping,
                    ':discount_code'      => $this->stringOrNull($payload['discount_code'] ?? null),
                    ':total'              => $totalAmount,
                    ':payment_method'     => $this->stringOrNull($payload['payment_method'] ?? null),
                    ':payment_status'     => $paymentStatus,
                    ':order_status'       => $normalizedOrderStatus,
                    ':priority'           => $totalAmount >= 50 ? 'ALTA' : 'NORMAL',
                    ':sync_status'        => 'SINCRONIZADO',
                    ':channel_created_at' => $now,
                    ':integrated_at'      => date('Y-m-d H:i:s'),
                    ':updated_at'         => date('Y-m-d H:i:s'),
                ]);
                $orderId = (int) $this->db->lastInsertId();

                // Insertar líneas de pedido y descontar stock en almacén
                $insItem = $this->db->prepare('
                    INSERT INTO erp_order_items (order_id, sku, product_name, variant_size, quantity, unit_price, line_total)
                    VALUES (:order_id, :sku, :product_name, :variant_size, :quantity, :unit_price, :line_total)
                ');
                $updStock = $this->db->prepare('
                    UPDATE erp_inventory
                    SET stock_available = stock_available - :qty,
                        updated_at = :updated_at
                    WHERE sku = :sku AND stock_available >= :qty
                ');

                foreach ($items as $item) {
                    $qty = (int) $item['quantity'];
                    $unitPrice = (float) $item['unit_price'];
                    $lineTotal = round($qty * $unitPrice, 2);
                    $sku = strtoupper(trim((string) $item['sku']));

                    $insItem->execute([
                        ':order_id'     => $orderId,
                        ':sku'          => $sku,
                        ':product_name' => trim((string) $item['product_name']),
                        ':variant_size' => $this->stringOrNull($item['variant_size'] ?? null),
                        ':quantity'     => $qty,
                        ':unit_price'   => $unitPrice,
                        ':line_total'   => $lineTotal,
                    ]);

                    $updStock->execute([
                        ':qty'        => $qty,
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':sku'        => $sku,
                    ]);
                    // Red de seguridad: si el stock ha cambiado entre la comprobación y el descuento, se deshace todo
                    if ($updStock->rowCount() !== 1) {
                        throw new \DomainException("Stock insuficiente para {$sku} al descontar {$qty} uds.");
                    }
                }

                // Actualizar métricas acumuladas del cliente
                $this->db->prepare('
                    UPDATE erp_customers
                    SET orders_count = orders_count + 1,
                        total_spent = ROUND(total_spent + :amount, 2),
                        segment = CASE WHEN (total_spent + :amount) >= 50 THEN "VIP_PICANTE" ELSE segment END
                    WHERE id = :id
                ')->execute([':amount' => $totalAmount, ':id' => $customerId]);
            }

            $this->db->commit();

            $msg = $isIdempotentUpdate
                ? "Pedido {$orderRef} ya existía en Back-Office: actualizado de forma idempotente a estado '{$normalizedOrderStatus}'."
                : "Pedido {$orderRef} creado e integrado en Back-Office con " . count($items) . " líneas y stock descontado.";

            $response = [
                'ok'                => true,
                'execution_id'      => $executionId,
                'scenario'          => 'ESCENARIO_1_PEDIDO',
                'idempotent_update' => $isIdempotentUpdate,
                'order_id'          => $orderId,
                'order_ref'         => $orderRef,
                'normalized_status' => $normalizedOrderStatus,
                'message'           => $msg,
            ];

            $this->logExecution(
                $executionId,
                'ESCENARIO_1_PEDIDO',
                $eventType,
                $direction,
                $orderRef,
                'SUCCESS',
                null,
                $msg,
                $payload,
                $response,
                $startTime
            );

            return $response;
        } catch (\DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $response = [
                'ok'           => false,
                'execution_id' => $executionId,
                'scenario'     => 'ESCENARIO_1_PEDIDO',
                'error_code'   => 'ERR_STOCK_INSUFICIENTE',
                'message'      => $e->getMessage(),
            ];
            $this->logExecution($executionId, 'ESCENARIO_1_PEDIDO', $eventType, $direction, $orderRef, 'ERROR', 'ERR_STOCK_INSUFICIENTE', $e->getMessage(), $payload, $response, $startTime);
            return $response;
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $response = [
                'ok'           => false,
                'execution_id' => $executionId,
                'error_code'   => 'ERR_DB_TRANSACTION',
                'message'      => 'Error interno al persistir pedido: ' . $e->getMessage(),
            ];
            $this->logExecution(
                $executionId,
                'ESCENARIO_1_PEDIDO',
                $eventType,
                $direction,
                $orderRef,
                'ERROR',
                'ERR_DB_TRANSACTION',
                $e->getMessage(),
                $payload,
                $response,
                $startTime
            );
            return $response;
        }
    }

    // =========================================================================
    // ESCENARIO 2: CAMBIO OPERATIVO O SINCRONIZACIÓN (Estado Pedido / Stock / Prioridad)
    // =========================================================================
    public function processOperationalChange(array $payload, bool $notifyExternalN8n = true): array
    {
        $startTime = microtime(true);
        $executionId = $payload['execution_id'] ?? ('exec_sync_' . bin2hex(random_bytes(4)));
        $changeType = strtoupper(trim((string) ($payload['change_type'] ?? 'ORDER_STATUS')));
        $direction = (string) ($payload['direction'] ?? 'BackOffice -> n8n -> eCommerce');

        if ($changeType === 'ORDER_STATUS') {
            $orderRef = trim((string) ($payload['order_ref'] ?? ''));
            $newStatus = trim((string) ($payload['new_status'] ?? ''));
            $newPriority = trim((string) ($payload['priority'] ?? ''));

            $allowedStatuses = ['creado', 'pagado_simulado', 'pendiente_preparacion', 'enviado', 'cancelado', 'incidencia'];

            if ($orderRef === '' || !in_array($newStatus, $allowedStatuses, true)) {
                $msg = "Cambio operativo rechazado: order_ref vacío o estado '{$newStatus}' fuera del contrato (" . implode(', ', $allowedStatuses) . ").";
                $res = ['ok' => false, 'error_code' => 'ERR_TRANSICION_ESTADO', 'message' => $msg];
                $this->logExecution($executionId, 'ESCENARIO_2_SINCRONIZACION', 'order.status_changed', $direction, $orderRef ?: 'N/A', 'ERROR', 'ERR_TRANSICION_ESTADO', $msg, $payload, $res, $startTime);
                return $res;
            }

            $stmt = $this->db->prepare('SELECT * FROM erp_orders WHERE order_ref = :ref LIMIT 1');
            $stmt->execute([':ref' => $orderRef]);
            $order = $stmt->fetch();

            if (!$order) {
                $msg = "No existe el pedido '{$orderRef}' en el Back-Office para sincronizar.";
                $res = ['ok' => false, 'error_code' => 'ERR_ENTIDAD_NO_ENCONTRADA', 'message' => $msg];
                $this->logExecution($executionId, 'ESCENARIO_2_SINCRONIZACION', 'order.status_changed', $direction, $orderRef, 'ERROR', 'ERR_ENTIDAD_NO_ENCONTRADA', $msg, $payload, $res, $startTime);
                return $res;
            }

            $prevStatus = $order['order_status'];
            $priorityToSave = $newPriority !== '' ? $newPriority : $order['priority'];

            // Notificar a n8n si el cambio nace en el Back-Office
            $webhookResult = null;
            $syncState = 'SINCRONIZADO';
            if ($notifyExternalN8n) {
                $webhookResult = WebhookNotifier::dispatch('N8N_WEBHOOK_OPERATIONAL_CHANGE_URL', 'order.status_changed', [
                    'order_ref'       => $orderRef,
                    'previous_status' => $prevStatus,
                    'new_status'      => $newStatus,
                    'priority'        => $priorityToSave,
                    'customer_email'  => $order['customer_email'],
                    'origin'          => 'backoffice_erp',
                ]);
                if (!$webhookResult['dispatched']) {
                    $syncState = 'PENDIENTE_SINCRONIZACION';
                }
            }

            $upd = $this->db->prepare('
                UPDATE erp_orders
                SET order_status = :status,
                    priority = :priority,
                    sync_status = :sync_status,
                    updated_at = :updated_at
                WHERE order_ref = :ref
            ');
            $upd->execute([
                ':status'      => $newStatus,
                ':priority'    => $priorityToSave,
                ':sync_status' => $syncState,
                ':updated_at'  => date('Y-m-d H:i:s'),
                ':ref'         => $orderRef,
            ]);

            $msg = "Estado de pedido {$orderRef} actualizado ({$prevStatus} -> {$newStatus}, Prioridad: {$priorityToSave}). Estado sync: {$syncState}.";
            $res = [
                'ok'              => true,
                'execution_id'    => $executionId,
                'scenario'        => 'ESCENARIO_2_SINCRONIZACION',
                'order_ref'       => $orderRef,
                'previous_status' => $prevStatus,
                'new_status'      => $newStatus,
                'sync_status'     => $syncState,
                'webhook_dispatch'=> $webhookResult,
                'message'         => $msg,
            ];

            $this->logExecution($executionId, 'ESCENARIO_2_SINCRONIZACION', 'order.status_changed', $direction, $orderRef, 'SUCCESS', null, $msg, $payload, $res, $startTime);
            return $res;
        }

        // Subcaso B del Escenario 2: Modificación de Stock / Disponibilidad en Almacén
        $sku = strtoupper(trim((string) ($payload['sku'] ?? '')));
        $newStock = isset($payload['new_stock']) ? (int) $payload['new_stock'] : -1;

        if ($sku === '' || $newStock < 0) {
            $msg = "Sincronización de stock rechazada: SKU vacío o stock negativo ({$newStock}).";
            $res = ['ok' => false, 'error_code' => 'ERR_CAMPOS_OBLIGATORIOS', 'message' => $msg];
            $this->logExecution($executionId, 'ESCENARIO_2_SINCRONIZACION', 'inventory.stock_updated', $direction, $sku ?: 'N/A', 'ERROR', 'ERR_CAMPOS_OBLIGATORIOS', $msg, $payload, $res, $startTime);
            return $res;
        }

        $stmtSku = $this->db->prepare('SELECT * FROM erp_inventory WHERE sku = :sku LIMIT 1');
        $stmtSku->execute([':sku' => $sku]);
        $inv = $stmtSku->fetch();

        if (!$inv) {
            $msg = "SKU '{$sku}' no encontrado en el almacén del Back-Office.";
            $res = ['ok' => false, 'error_code' => 'ERR_ENTIDAD_NO_ENCONTRADA', 'message' => $msg];
            $this->logExecution($executionId, 'ESCENARIO_2_SINCRONIZACION', 'inventory.stock_updated', $direction, $sku, 'ERROR', 'ERR_ENTIDAD_NO_ENCONTRADA', $msg, $payload, $res, $startTime);
            return $res;
        }

        $prevStock = (int) $inv['stock_available'];
        $webhookResult = null;
        $syncState = 'SINCRONIZADO';
        if ($notifyExternalN8n) {
            $webhookResult = WebhookNotifier::dispatch('N8N_WEBHOOK_OPERATIONAL_CHANGE_URL', 'inventory.stock_updated', [
                'sku'            => $sku,
                'product_name'   => $inv['product_name'],
                'variant_size'   => $inv['variant_size'],
                'previous_stock' => $prevStock,
                'new_stock'      => $newStock,
                'origin'         => 'backoffice_erp',
            ]);
            if (!$webhookResult['dispatched']) {
                $syncState = 'PENDIENTE_SINCRONIZACION';
            }
        }

        $this->db->prepare('
            UPDATE erp_inventory
            SET stock_available = :stock, sync_status = :sync, updated_at = :updated
            WHERE sku = :sku
        ')->execute([
            ':stock'   => $newStock,
            ':sync'    => $syncState,
            ':updated' => date('Y-m-d H:i:s'),
            ':sku'     => $sku,
        ]);

        $msg = "Stock de {$sku} ({$inv['product_name']} {$inv['variant_size']}) actualizado ({$prevStock} -> {$newStock} uds). Estado sync: {$syncState}.";
        $res = [
            'ok'             => true,
            'execution_id'   => $executionId,
            'scenario'       => 'ESCENARIO_2_SINCRONIZACION',
            'sku'            => $sku,
            'previous_stock' => $prevStock,
            'new_stock'      => $newStock,
            'sync_status'    => $syncState,
            'message'        => $msg,
        ];
        $this->logExecution($executionId, 'ESCENARIO_2_SINCRONIZACION', 'inventory.stock_updated', $direction, $sku, 'SUCCESS', null, $msg, $payload, $res, $startTime);
        return $res;
    }

    // =========================================================================
    // ESCENARIO 3 (PARTE 1): RECEPCIÓN DE INCIDENCIA O SOLICITUD POSTVENTA
    // =========================================================================
    public function processSupportTicket(array $payload, string $direction = 'eCommerce -> n8n -> BackOffice'): array
    {
        $startTime = microtime(true);
        $executionId = $payload['execution_id'] ?? ('exec_inc_' . bin2hex(random_bytes(4)));
        $eventType = (string) ($payload['event_type'] ?? 'support.requested');

        // Sin valores inventados: ticket_ref y customer_name son obligatorios (ni aleatorios ni genéricos)
        $ticketRef = trim((string) ($payload['ticket_ref'] ?? ''));
        $orderRef = $this->stringOrNull($payload['order_ref'] ?? null);
        $customerEmail = trim((string) ($payload['customer_email'] ?? ($payload['customer']['email'] ?? '')));
        $customerName = trim((string) ($payload['customer_name'] ?? ($payload['customer']['name'] ?? '')));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $description = trim((string) ($payload['description'] ?? ''));
        // chat_id de Telegram: opcional y sin valor por defecto. Un bot no puede escribir a un @usuario:
        // necesita el chat_id numérico (negativo en grupos) de alguien que haya escrito antes al bot.
        $telegramChatId = $this->stringOrNull($payload['telegram_chat_id'] ?? null);

        $missing = [];
        if ($ticketRef === '') {
            $missing[] = 'ticket_ref';
        }
        if ($customerName === '') {
            $missing[] = 'customer_name';
        }
        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            $missing[] = 'customer_email';
        }
        if ($subject === '') {
            $missing[] = 'subject';
        }
        if ($description === '') {
            $missing[] = 'description';
        }
        if ($telegramChatId !== null && !preg_match('/^-?\d+$/', $telegramChatId)) {
            $missing[] = 'telegram_chat_id (numérico; no se admite @usuario)';
        }

        if (!empty($missing)) {
            $msg = 'Incidencia rechazada por datos incompletos: ' . implode(', ', $missing);
            $res = ['ok' => false, 'error_code' => 'ERR_CAMPOS_OBLIGATORIOS', 'invalid_fields' => $missing, 'message' => $msg];
            $this->logExecution($executionId, 'ESCENARIO_3_POSTVENTA', $eventType, $direction, $ticketRef ?: 'SIN_REFERENCIA', 'ERROR', 'ERR_CAMPOS_OBLIGATORIOS', $msg, $payload, $res, $startTime);
            return $res;
        }

        $now = date('Y-m-d H:i:s');

        // Si existe el pedido, marcarlo con alerta o vincularlo
        $stmtCheck = $this->db->prepare('SELECT id FROM erp_tickets WHERE ticket_ref = :ref LIMIT 1');
        $stmtCheck->execute([':ref' => $ticketRef]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            $upd = $this->db->prepare('
                UPDATE erp_tickets
                SET subject = :subject, description = :description, priority = :priority
                WHERE ticket_ref = :ref
            ');
            $upd->execute([
                ':subject'     => $subject,
                ':description' => $description,
                ':priority'    => $payload['priority'] ?? 'ALTA',
                ':ref'         => $ticketRef,
            ]);
        } else {
            $ins = $this->db->prepare('
                INSERT INTO erp_tickets (
                    ticket_ref, order_ref, customer_email, customer_name, telegram_chat_id,
                    subject, description, category, priority, status, created_at
                ) VALUES (
                    :ticket_ref, :order_ref, :email, :name, :tg,
                    :subject, :description, :category, :priority, :status, :created_at
                )
            ');
            $ins->execute([
                ':ticket_ref'  => $ticketRef,
                ':order_ref'   => $orderRef,
                ':email'       => strtolower($customerEmail),
                ':name'        => $customerName,
                ':tg'          => $telegramChatId,
                ':subject'     => $subject,
                ':description' => $description,
                ':category'    => $payload['category'] ?? 'INCIDENCIA_PEDIDO',
                ':priority'    => $payload['priority'] ?? 'ALTA',
                ':status'      => 'EN_REVISION_IA',
                ':created_at'  => $now,
            ]);
        }

        $msg = "Incidencia {$ticketRef} registrada en Back-Office y vinculada al pedido " . ($orderRef ?? 'SIN_PEDIDO') . " en estado EN_REVISION_IA.";
        $res = [
            'ok'               => true,
            'execution_id'     => $executionId,
            'scenario'         => 'ESCENARIO_3_POSTVENTA',
            'ticket_ref'       => $ticketRef,
            'order_ref'        => $orderRef,
            'telegram_chat_id' => $telegramChatId,
            'status'           => 'EN_REVISION_IA',
            'message'          => $msg,
        ];

        $this->logExecution($executionId, 'ESCENARIO_3_POSTVENTA', $eventType, $direction, $ticketRef, 'SUCCESS', null, $msg, $payload, $res, $startTime);
        return $res;
    }

    // =========================================================================
    // HERRAMIENTAS (TOOLS) DEL AGENTE DE IA EN N8N (Apartado 6.a y 6.b)
    // =========================================================================

    /**
     * Tool de Consulta 1: Consultar pedido, líneas, cliente e historial de incidencias
     */
    public function getAgentOrderContext(string $orderRef): array
    {
        $stmt = $this->db->prepare('SELECT * FROM erp_orders WHERE order_ref = :ref LIMIT 1');
        $stmt->execute([':ref' => $orderRef]);
        $order = $stmt->fetch();

        if (!$order) {
            return [
                'found'   => false,
                'message' => "No se encontró el pedido {$orderRef} en el Back-Office.",
            ];
        }

        $stmtItems = $this->db->prepare('SELECT sku, product_name, variant_size, quantity, unit_price, line_total FROM erp_order_items WHERE order_id = :id');
        $stmtItems->execute([':id' => $order['id']]);
        $items = $stmtItems->fetchAll();

        $stmtCust = $this->db->prepare('SELECT name, email, segment, orders_count, total_spent FROM erp_customers WHERE id = :cid LIMIT 1');
        $stmtCust->execute([':cid' => $order['customer_id']]);
        $customer = $stmtCust->fetch();

        $stmtTickets = $this->db->prepare('SELECT ticket_ref, subject, status, priority, created_at FROM erp_tickets WHERE customer_email = :email');
        $stmtTickets->execute([':email' => $order['customer_email']]);
        $ticketsHistory = $stmtTickets->fetchAll();

        // Consultar stock actual de los SKUs de ese pedido
        $stockBySku = [];
        $stmtStock = $this->db->prepare('SELECT sku, product_name, variant_size, stock_available FROM erp_inventory WHERE sku = :sku LIMIT 1');
        foreach ($items as $item) {
            $stmtStock->execute([':sku' => $item['sku']]);
            $st = $stmtStock->fetch();
            if ($st) {
                $stockBySku[$item['sku']] = (int) $st['stock_available'];
            }
        }

        return [
            'found'                    => true,
            'tool_name'                => 'consultar_pedido_e_historial_cliente',
            'order'                    => [
                'order_ref'          => $order['order_ref'],
                'order_status'       => $order['order_status'],
                'payment_status'     => $order['payment_status'],
                'payment_method'     => $order['payment_method'],
                'total_amount'       => (float) $order['total_amount'],
                'channel_created_at' => $order['channel_created_at'],
                'items'              => $items,
            ],
            'customer'                 => $customer,
            'previous_tickets_count'   => count($ticketsHistory),
            'tickets_history'          => $ticketsHistory,
            'warehouse_stock_for_items'=> $stockBySku,
        ];
    }

    /**
     * Tool de Consulta 2: Consultar stock y disponibilidad de una salsa por SKU
     */
    public function getAgentStockContext(string $sku): array
    {
        $stmt = $this->db->prepare('SELECT * FROM erp_inventory WHERE sku = :sku LIMIT 1');
        $stmt->execute([':sku' => strtoupper(trim($sku))]);
        $item = $stmt->fetch();

        if (!$item) {
            return ['found' => false, 'sku' => $sku, 'message' => 'SKU no registrado en almacén.'];
        }

        return [
            'found'               => true,
            'tool_name'           => 'consultar_disponibilidad_almacen',
            'sku'                 => $item['sku'],
            'product_name'        => $item['product_name'],
            'variant_size'        => $item['variant_size'],
            'stock_available'     => (int) $item['stock_available'],
            'min_stock_threshold' => (int) $item['min_stock_threshold'],
            'can_replace_item'    => ((int) $item['stock_available']) > 0,
            'warehouse_location'  => $item['warehouse_location'],
        ];
    }

    /**
     * Tool de Acción/Propuesta del Agente IA (Apartado 6):
     * Registra la salida estructurada (con los 7 campos obligatorios) en modo supervisado ("pendiente_de_revision").
     */
    public function registerAgentProposal(array $payload): array
    {
        $startTime = microtime(true);
        $executionId = $payload['execution_id'] ?? ('exec_ai_' . bin2hex(random_bytes(4)));

        // Validar los 7 campos obligatorios de la salida estructurada del Agente IA (Apartado 6)
        $requiredAgentFields = [
            'tipo_caso_detectado',
            'datos_consultados',
            'accion_propuesta',
            'justificacion_breve',
            'nivel_confianza_o_prioridad',
            'riesgos_o_advertencias',
            'estado_final',
        ];

        $missing = [];
        foreach ($requiredAgentFields as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                $missing[] = $field;
            }
        }

        $ticketRef = trim((string) ($payload['ticket_ref'] ?? ''));
        if ($ticketRef === '') {
            $missing[] = 'ticket_ref';
        }
        // Tampoco se inventan el mensaje que se enviará al cliente ni las herramientas que dice haber usado el agente
        if (trim((string) ($payload['mensaje_propuesto_telegram'] ?? '')) === '') {
            $missing[] = 'mensaje_propuesto_telegram';
        }
        $toolsRaw = $payload['herramientas_utilizadas'] ?? null;
        $toolsList = is_array($toolsRaw)
            ? array_values(array_filter(array_map(fn ($t) => trim((string) $t), $toolsRaw), fn ($t) => $t !== ''))
            : array_values(array_filter(array_map('trim', explode(',', (string) $toolsRaw)), fn ($t) => $t !== ''));
        if (count($toolsList) === 0) {
            $missing[] = 'herramientas_utilizadas';
        }

        if (!empty($missing)) {
            $msg = 'Propuesta del Agente IA rechazada por incumplir el contrato de salida estructurada. Faltan: ' . implode(', ', $missing);
            $res = ['ok' => false, 'error_code' => 'ERR_SALIDA_AGENTE_INCOMPLETA', 'invalid_fields' => $missing, 'message' => $msg];
            $this->logExecution($executionId, 'ESCENARIO_3_AGENTE_IA', 'ai.proposal.rejected', 'n8n AI Agent -> BackOffice', $ticketRef ?: 'N/A', 'ERROR', 'ERR_SALIDA_AGENTE_INCOMPLETA', $msg, $payload, $res, $startTime);
            return $res;
        }

        // Forzar barrera de seguridad: el agente NUNCA puede autoejecutar "aprobado" directamente sin revisión humana
        $safeStatus = 'pendiente_de_revision';
        if (isset($payload['estado_final']) && $payload['estado_final'] === 'ejecutado_en_modo_simulado') {
            $safeStatus = 'ejecutado_en_modo_simulado';
        }

        $consultedJson = is_array($payload['datos_consultados'])
            ? json_encode($payload['datos_consultados'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            : (string) $payload['datos_consultados'];

        $toolsUsed = implode(', ', $toolsList);

        $ins = $this->db->prepare('
            INSERT INTO erp_ai_audits (
                ticket_ref, order_ref, agent_model, tools_invoked,
                detected_case_type, consulted_data_json, proposed_action,
                proposed_customer_message, justification, confidence_and_priority,
                risks_and_warnings, final_status, created_at
            ) VALUES (
                :ticket_ref, :order_ref, :agent_model, :tools_invoked,
                :detected_case_type, :consulted_data_json, :proposed_action,
                :proposed_customer_message, :justification, :confidence_and_priority,
                :risks_and_warnings, :final_status, :created_at
            )
        ');

        $ins->execute([
            ':ticket_ref'                => $ticketRef,
            ':order_ref'                 => $this->stringOrNull($payload['order_ref'] ?? null),
            ':agent_model'               => $this->stringOrNull($payload['agent_model'] ?? null),
            ':tools_invoked'             => $toolsUsed,
            ':detected_case_type'        => (string) $payload['tipo_caso_detectado'],
            ':consulted_data_json'       => (string) $consultedJson,
            ':proposed_action'           => (string) $payload['accion_propuesta'],
            ':proposed_customer_message' => trim((string) $payload['mensaje_propuesto_telegram']),
            ':justification'             => (string) $payload['justificacion_breve'],
            ':confidence_and_priority'   => (string) $payload['nivel_confianza_o_prioridad'],
            ':risks_and_warnings'        => (string) $payload['riesgos_o_advertencias'],
            ':final_status'              => $safeStatus,
            ':created_at'                => date('Y-m-d H:i:s'),
        ]);

        $auditId = (int) $this->db->lastInsertId();

        // Actualizar estado del ticket a PENDIENTE_APROBACION_HUMANA
        $this->db->prepare('
            UPDATE erp_tickets
            SET category = :cat, status = :st
            WHERE ticket_ref = :ref
        ')->execute([
            ':cat' => (string) $payload['tipo_caso_detectado'],
            ':st'  => 'PENDIENTE_SUPERVISION_HUMANA',
            ':ref' => $ticketRef,
        ]);

        $msg = "Propuesta estructurada del Agente IA (#{$auditId}) registrada para {$ticketRef} en estado '{$safeStatus}'. En espera de supervisión humana antes de notificar por Telegram.";
        $res = [
            'ok'           => true,
            'execution_id' => $executionId,
            'audit_id'     => $auditId,
            'ticket_ref'   => $ticketRef,
            'final_status' => $safeStatus,
            'message'      => $msg,
        ];

        $this->logExecution($executionId, 'ESCENARIO_3_AGENTE_IA', 'ai.proposal.created', 'n8n AI Agent -> BackOffice', $ticketRef, 'SUCCESS', null, $msg, $payload, $res, $startTime);
        return $res;
    }

    /**
     * Supervisión Humana (Apartado 6): Aprobar o Rechazar explícitamente la propuesta del Agente IA.
     */
    public function reviewAgentProposal(int $auditId, string $decision, string $reviewerName, string $comment = ''): array
    {
        $startTime = microtime(true);
        $stmt = $this->db->prepare('SELECT * FROM erp_ai_audits WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $auditId]);
        $audit = $stmt->fetch();

        if (!$audit) {
            return ['ok' => false, 'error_code' => 'ERR_ENTIDAD_NO_ENCONTRADA', 'message' => "No existe la propuesta de auditoría #{$auditId}."];
        }

        // La decisión y el revisor son obligatorios: nunca se aprueba por defecto
        $normalizedDecision = strtolower(trim($decision));
        $reviewerName = trim($reviewerName);
        $invalid = [];
        if (!in_array($normalizedDecision, ['aprobado', 'rechazado'], true)) {
            $invalid[] = 'decision (aprobado | rechazado)';
        }
        if ($reviewerName === '') {
            $invalid[] = 'reviewed_by';
        }
        if (!empty($invalid)) {
            return [
                'ok'             => false,
                'error_code'     => 'ERR_CAMPOS_OBLIGATORIOS',
                'invalid_fields' => $invalid,
                'message'        => 'Revisión rechazada. Campos obligatorios ausentes o inválidos: ' . implode(', ', $invalid),
            ];
        }
        $now = date('Y-m-d H:i:s');

        // Obtener datos del ticket para el envío a Telegram vía n8n
        $stmtTicket = $this->db->prepare('SELECT * FROM erp_tickets WHERE ticket_ref = :ref LIMIT 1');
        $stmtTicket->execute([':ref' => $audit['ticket_ref']]);
        $ticket = $stmtTicket->fetch();

        $telegramDispatched = 0;
        $webhookInfo = null;

        $chatId = $ticket ? $this->stringOrNull($ticket['telegram_chat_id'] ?? null) : null;
        $noChatId = false;

        if ($normalizedDecision === 'aprobado' && $chatId === null) {
            // Sin chat_id no se puede notificar por Telegram: no se intenta y queda constancia
            $noChatId = true;
            if ($ticket) {
                $this->db->prepare('
                    UPDATE erp_tickets
                    SET status = "RESUELTA", resolution_notes = :notes, resolved_at = :resolved_at
                    WHERE ticket_ref = :ref
                ')->execute([
                    ':notes'       => "Acción IA aprobada por {$reviewerName}: {$audit['proposed_action']}. Sin chat_id de Telegram: no se notifica por Telegram (avisar al cliente por otro canal).",
                    ':resolved_at' => $now,
                    ':ref'         => $audit['ticket_ref'],
                ]);
            }
        } elseif ($normalizedDecision === 'aprobado') {
            $webhookInfo = WebhookNotifier::dispatch('N8N_WEBHOOK_HUMAN_REVIEW_URL', 'ai.proposal.approved', [
                'audit_id'                 => $auditId,
                'ticket_ref'               => $audit['ticket_ref'],
                'order_ref'                => $audit['order_ref'],
                'approved_action'          => $audit['proposed_action'],
                'customer_telegram_chat_id'=> $chatId,
                'customer_message'         => $audit['proposed_customer_message'],
                'reviewed_by'              => $reviewerName,
                'reviewed_at'              => $now,
            ]);
            // Solo se da por notificado si n8n confirma la recepción (HTTP 2xx)
            $telegramDispatched = $webhookInfo['dispatched'] ? 1 : 0;

            if ($ticket) {
                if ($telegramDispatched === 1) {
                    // Incidencia resuelta: resolved_at real, el tiempo de resolución se calcula con él
                    $this->db->prepare('
                        UPDATE erp_tickets
                        SET status = "RESUELTA", resolution_notes = :notes, resolved_at = :resolved_at
                        WHERE ticket_ref = :ref
                    ')->execute([
                        ':notes'       => "Acción IA aprobada por {$reviewerName}: {$audit['proposed_action']}. n8n confirmó la recepción para Telegram (HTTP {$webhookInfo['http_code']}).",
                        ':resolved_at' => $now,
                        ':ref'         => $audit['ticket_ref'],
                    ]);
                } else {
                    // Aprobada pero sin notificar: no se marca como resuelta
                    $this->db->prepare('
                        UPDATE erp_tickets
                        SET status = "APROBADA_PENDIENTE_NOTIFICACION", resolution_notes = :notes
                        WHERE ticket_ref = :ref
                    ')->execute([
                        ':notes' => "Acción IA aprobada por {$reviewerName}: {$audit['proposed_action']}. La notificación por Telegram NO se ha podido enviar: {$webhookInfo['note']}",
                        ':ref'   => $audit['ticket_ref'],
                    ]);
                }
            }
        } else {
            if ($ticket) {
                $this->db->prepare('
                    UPDATE erp_tickets
                    SET status = "ABIERTA_MANUAL",
                        resolution_notes = :notes
                    WHERE ticket_ref = :ref
                ')->execute([
                    ':notes' => "Propuesta de IA rechazada por {$reviewerName}. Motivo: " . ($comment ?: 'Requiere intervención manual del operador.'),
                    ':ref'   => $audit['ticket_ref'],
                ]);
            }
        }

        // Si el pedido asociado existe y la acción implica incidencia/reemplazo, reflejarlo
        if ($normalizedDecision === 'aprobado' && !empty($audit['order_ref'])) {
            $this->db->prepare('
                UPDATE erp_orders SET order_status = "incidencia", updated_at = :u WHERE order_ref = :r
            ')->execute([':u' => $now, ':r' => $audit['order_ref']]);
        }

        $this->db->prepare('
            UPDATE erp_ai_audits
            SET final_status = :status,
                reviewed_by = :reviewer,
                review_comment = :comment,
                reviewed_at = :reviewed_at,
                telegram_notified = :tg
            WHERE id = :id
        ')->execute([
            ':status'      => $normalizedDecision,
            ':reviewer'    => $reviewerName,
            ':comment'     => $this->stringOrNull($comment),
            ':reviewed_at' => $now,
            ':tg'          => $telegramDispatched,
            ':id'          => $auditId,
        ]);

        $msg = "Supervisión humana registrada: Propuesta #{$auditId} ({$audit['ticket_ref']}) marcada como '" . strtoupper($normalizedDecision) . "' por {$reviewerName}.";
        if ($normalizedDecision === 'aprobado' && $noChatId) {
            $msg .= ' Sin chat_id: no se notifica por Telegram.';
        } elseif ($normalizedDecision === 'aprobado') {
            $msg .= $telegramDispatched === 1
                ? ' Notificación entregada a n8n para Telegram.'
                : ' ATENCIÓN: la notificación por Telegram no se ha podido enviar (queda pendiente).';
        }
        $res = [
            'ok'                => true,
            'audit_id'          => $auditId,
            'ticket_ref'        => $audit['ticket_ref'],
            'final_status'      => $normalizedDecision,
            'reviewed_by'       => $reviewerName,
            'telegram_notified' => (bool) $telegramDispatched,
            'webhook_dispatch'  => $webhookInfo,
            'message'           => $msg,
        ];

        $this->logExecution(
            'exec_rev_' . bin2hex(random_bytes(4)),
            'ESCENARIO_3_SUPERVISION_HUMANA',
            'ai.proposal.' . $normalizedDecision,
            'Supervisor Humano -> BackOffice -> Telegram',
            $audit['ticket_ref'],
            'SUCCESS',
            null,
            $msg,
            ['audit_id' => $auditId, 'decision' => $normalizedDecision, 'reviewer' => $reviewerName, 'comment' => $comment],
            $res,
            $startTime
        );

        // Constancia del fallo de notificación en el log (cuenta en «errores por tipo» del cuadro de mando)
        if ($normalizedDecision === 'aprobado' && $telegramDispatched === 0 && $webhookInfo !== null) {
            $this->logExecution(
                'exec_tg_' . bin2hex(random_bytes(4)),
                'ESCENARIO_3_SUPERVISION_HUMANA',
                'ai.proposal.approved',
                'BackOffice -> n8n -> Telegram',
                $audit['ticket_ref'],
                'ERROR',
                'ERR_NOTIFICACION_TELEGRAM',
                'No se pudo entregar a n8n la notificación de Telegram: ' . $webhookInfo['note'],
                ['audit_id' => $auditId],
                $webhookInfo,
                $startTime
            );
        }

        return $res;
    }

    // =========================================================================
    // MÉTRICAS OPERATIVAS EN VIVO (Apartado 7: Cuadro de Mando Operativo)
    // =========================================================================
    public function getDashboardMetrics(): array
    {
        // a. Número de pedidos e b. Importe total simulado
        $ordTotals = $this->db->query('
            SELECT COUNT(*) AS total_orders,
                   COALESCE(SUM(total_amount), 0) AS total_revenue,
                   COALESCE(AVG(total_amount), 0) AS avg_ticket
            FROM erp_orders
            WHERE order_status != "cancelado"
        ')->fetch();

        // Histórico del número de pedidos por fecha (para diagrama de barras obligatorio)
        $ordersByDay = $this->db->query('
            SELECT SUBSTR(channel_created_at, 1, 10) AS order_date,
                   COUNT(*) AS orders_count,
                   ROUND(SUM(total_amount), 2) AS day_revenue
            FROM erp_orders
            GROUP BY SUBSTR(channel_created_at, 1, 10)
            ORDER BY order_date ASC
        ')->fetchAll();

        // c. Productos más solicitados
        $topProducts = $this->db->query('
            SELECT sku,
                   product_name,
                   variant_size,
                   SUM(quantity) AS total_units,
                   ROUND(SUM(line_total), 2) AS total_sales
            FROM erp_order_items
            GROUP BY sku, product_name, variant_size
            ORDER BY total_units DESC
            LIMIT 6
        ')->fetchAll();

        // d. Pedidos por estado
        $ordersByStatus = $this->db->query('
            SELECT order_status, COUNT(*) AS total
            FROM erp_orders
            GROUP BY order_status
            ORDER BY total DESC
        ')->fetchAll();

        // e. Incidencias abiertas vs cerradas y f. Tiempo medio de resolución REAL (resolved_at − created_at, sin mínimos)
        $ticketStats = $this->db->query('
            SELECT COUNT(*) AS total_tickets,
                   SUM(CASE WHEN status != "RESUELTA" THEN 1 ELSE 0 END) AS open_tickets,
                   SUM(CASE WHEN status = "RESUELTA" THEN 1 ELSE 0 END) AS resolved_tickets,
                   ROUND(AVG(CASE WHEN resolved_at IS NOT NULL
                                  THEN (julianday(resolved_at) - julianday(created_at)) * 1440 END), 1) AS avg_resolution_minutes
            FROM erp_tickets
        ')->fetch();

        // g. Eventos de integración correctos y fallidos
        $logStats = $this->db->query('
            SELECT COUNT(*) AS total_events,
                   SUM(CASE WHEN status = "SUCCESS" THEN 1 ELSE 0 END) AS success_events,
                   SUM(CASE WHEN status = "ERROR" THEN 1 ELSE 0 END) AS failed_events,
                   ROUND(AVG(execution_ms), 1) AS avg_latency_ms
            FROM erp_integration_logs
        ')->fetch();

        // i. Errores por tipo
        $errorsByType = $this->db->query('
            SELECT COALESCE(error_type, "ERR_DESCONOCIDO") AS error_type,
                   COUNT(*) AS total
            FROM erp_integration_logs
            WHERE status = "ERROR"
            GROUP BY error_type
            ORDER BY total DESC
        ')->fetchAll();

        // h. Tasa de conversión del flujo definido. Solo etapas que el Back-Office recibe de verdad
        //    (no recibe product.viewed, cart.item_added ni checkout.started): todo sale de las tablas, sin contadores ni mínimos.
        $funnelRow = $this->db->query('
            SELECT (SELECT COUNT(*) FROM erp_orders) AS orders_integrated,
                   (SELECT COUNT(*) FROM erp_orders WHERE payment_status = "pagado_simulado") AS orders_paid,
                   (SELECT COUNT(DISTINCT t.order_ref) FROM erp_tickets t
                      JOIN erp_orders o ON o.order_ref = t.order_ref) AS orders_with_ticket
        ')->fetch();
        $ordersIntegrated = (int) ($funnelRow['orders_integrated'] ?? 0);
        $ordersPaid = (int) ($funnelRow['orders_paid'] ?? 0);
        $ordersWithTicket = (int) ($funnelRow['orders_with_ticket'] ?? 0);

        $funnel = [
            'orders_integrated'  => $ordersIntegrated,
            'orders_paid'        => $ordersPaid,
            'orders_with_ticket' => $ordersWithTicket,
        ];
        // Sin datos no hay tasa (null), en lugar de inventar un denominador
        $paidRate = $ordersIntegrated > 0 ? round($ordersPaid / $ordersIntegrated * 100, 2) : null;
        $ticketRate = $ordersPaid > 0 ? round($ordersWithTicket / $ordersPaid * 100, 2) : null;

        // j. Acciones propuestas por el agente y acciones aprobadas
        $aiStats = $this->db->query('
            SELECT COUNT(*) AS total_proposals,
                   SUM(CASE WHEN final_status = "aprobado" THEN 1 ELSE 0 END) AS approved_proposals,
                   SUM(CASE WHEN final_status = "rechazado" THEN 1 ELSE 0 END) AS rejected_proposals,
                   SUM(CASE WHEN final_status = "pendiente_de_revision" THEN 1 ELSE 0 END) AS pending_proposals
            FROM erp_ai_audits
        ')->fetch();

        // Stock bajo en almacén
        $lowStockCount = (int) $this->db->query('
            SELECT COUNT(*) FROM erp_inventory WHERE stock_available <= min_stock_threshold
        ')->fetchColumn();

        return [
            'orders' => [
                'total_count'   => (int) ($ordTotals['total_orders'] ?? 0),
                'total_revenue' => round((float) ($ordTotals['total_revenue'] ?? 0), 2),
                'avg_ticket'    => round((float) ($ordTotals['avg_ticket'] ?? 0), 2),
                'by_day'        => $ordersByDay,
                'by_status'     => $ordersByStatus,
            ],
            'top_products' => $topProducts,
            'tickets' => [
                'total'                  => (int) ($ticketStats['total_tickets'] ?? 0),
                'open'                   => (int) ($ticketStats['open_tickets'] ?? 0),
                'resolved'               => (int) ($ticketStats['resolved_tickets'] ?? 0),
                // null si todavía no hay ninguna incidencia resuelta
                'avg_resolution_minutes' => $ticketStats['avg_resolution_minutes'] !== null ? (float) $ticketStats['avg_resolution_minutes'] : null,
            ],
            'integration' => [
                'total_events'   => (int) ($logStats['total_events'] ?? 0),
                'success_events' => (int) ($logStats['success_events'] ?? 0),
                'failed_events'  => (int) ($logStats['failed_events'] ?? 0),
                'avg_latency_ms' => (float) ($logStats['avg_latency_ms'] ?? 0.0),
                'errors_by_type' => $errorsByType,
            ],
            'conversion' => [
                'funnel'                  => $funnel,
                'integrated_to_paid_pct'  => $paidRate,
                'paid_with_ticket_pct'    => $ticketRate,
            ],
            'ai_agent' => [
                'total_proposals'    => (int) ($aiStats['total_proposals'] ?? 0),
                'approved_proposals' => (int) ($aiStats['approved_proposals'] ?? 0),
                'rejected_proposals' => (int) ($aiStats['rejected_proposals'] ?? 0),
                'pending_proposals'  => (int) ($aiStats['pending_proposals'] ?? 0),
            ],
            'inventory' => [
                'low_stock_alerts' => $lowStockCount,
            ],
        ];
    }

    /**
     * Comprueba que todos los SKU del pedido existen en el almacén y tienen stock suficiente
     * (sumando las cantidades si un SKU aparece en varias líneas). Devuelve null si todo está bien.
     */
    private function checkSkusAndStock(array $items): ?array
    {
        $qtyBySku = [];
        foreach ($items as $item) {
            $sku = strtoupper(trim((string) $item['sku']));
            $qtyBySku[$sku] = ($qtyBySku[$sku] ?? 0) + (int) $item['quantity'];
        }

        $stmt = $this->db->prepare('SELECT stock_available FROM erp_inventory WHERE sku = :sku LIMIT 1');
        $unknown = [];
        $insufficient = [];
        foreach ($qtyBySku as $sku => $qty) {
            $stmt->execute([':sku' => $sku]);
            $stock = $stmt->fetchColumn();
            if ($stock === false) {
                $unknown[] = $sku;
            } elseif ((int) $stock < $qty) {
                $insufficient[] = ['sku' => $sku, 'requested' => $qty, 'available' => (int) $stock];
            }
        }

        if (!empty($unknown)) {
            return [
                'error_code' => 'ERR_ENTIDAD_NO_ENCONTRADA',
                'details'    => ['unknown_skus' => $unknown],
                'message'    => 'Pedido rechazado: SKU no registrados en el almacén del Back-Office: ' . implode(', ', $unknown),
            ];
        }
        if (!empty($insufficient)) {
            $parts = array_map(fn ($r) => "{$r['sku']} (pedidas {$r['requested']}, disponibles {$r['available']})", $insufficient);
            return [
                'error_code' => 'ERR_STOCK_INSUFICIENTE',
                'details'    => ['insufficient_stock' => $insufficient],
                'message'    => 'Pedido rechazado: stock insuficiente en ' . implode(', ', $parts),
            ];
        }
        return null;
    }

    /** Número de un payload JSON (int, float o texto numérico), o null si falta o no es numérico. */
    private function numberOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_string($value) && is_numeric(trim($value))) {
            return (float) trim($value);
        }
        return null;
    }

    /** Texto opcional: null si falta o está vacío (nunca un valor inventado). */
    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null || is_array($value)) {
            return null;
        }
        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    /** Estado de pago según el estado del pedido (mismos estados cobrados que Order::PAID_STATUSES de la tienda). */
    private function paymentStatusFor(string $orderStatus): string
    {
        return match ($orderStatus) {
            'creado'    => 'pendiente_pago',
            'cancelado' => 'cancelado',
            default     => 'pagado_simulado',
        };
    }

    private function logExecution(
        string $executionId,
        string $scenario,
        string $eventType,
        string $direction,
        string $entityRef,
        string $status,
        ?string $errorType,
        string $validationSummary,
        array $payload,
        array $response,
        float $startTime
    ): void {
        $elapsedMs = max(4, (int) round((microtime(true) - $startTime) * 1000));
        $stmt = $this->db->prepare('
            INSERT INTO erp_integration_logs (
                execution_id, scenario, event_type, direction, entity_ref,
                status, error_type, validation_summary, payload_json, response_json,
                execution_ms, created_at
            ) VALUES (
                :execution_id, :scenario, :event_type, :direction, :entity_ref,
                :status, :error_type, :validation_summary, :payload_json, :response_json,
                :execution_ms, :created_at
            )
        ');
        $stmt->execute([
            ':execution_id'       => $executionId,
            ':scenario'           => $scenario,
            ':event_type'         => $eventType,
            ':direction'          => $direction,
            ':entity_ref'         => $entityRef,
            ':status'             => $status,
            ':error_type'         => $errorType,
            ':validation_summary' => $validationSummary,
            ':payload_json'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ':response_json'      => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ':execution_ms'       => $elapsedMs,
            ':created_at'         => date('Y-m-d H:i:s'),
        ]);
    }
}
