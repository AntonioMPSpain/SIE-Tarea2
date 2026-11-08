<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Database;

use PDO;
use Piquantum\BackOffice\Config\Database;
use Piquantum\BackOffice\Services\IntegrationService;

class Seeder
{
    public static function getCatalogItems(): array
    {
        return [
            ['PQ-HAB-050', 'Lágrima de Habanero Rojo', 'Picante Alto', '50ml', 4, 6.50, 28, 5, 'PASILLO-A1'],
            ['PQ-HAB-100', 'Lágrima de Habanero Rojo', 'Picante Alto', '100ml', 4, 10.90, 22, 5, 'PASILLO-A1'],
            ['PQ-HAB-200', 'Lágrima de Habanero Rojo', 'Picante Alto', '200ml', 4, 17.50, 16, 5, 'PASILLO-A2'],
            ['PQ-JAL-100', 'Brisa Verde de Jalapeño y Lima', 'Suave', '100ml', 2, 8.90, 35, 8, 'PASILLO-B1'],
            ['PQ-JAL-200', 'Brisa Verde de Jalapeño y Lima', 'Suave', '200ml', 2, 14.50, 19, 6, 'PASILLO-B1'],
            ['PQ-CHP-100', 'Chipotle Ahumado al Roble', 'Medio-Ahumado', '100ml', 3, 9.90, 24, 5, 'PASILLO-B2'],
            ['PQ-MNG-100', 'Néctar Tropical Mango & Habanero', 'Frutal-Picante', '100ml', 3, 11.50, 30, 6, 'PASILLO-C1'],
            ['PQ-CAR-050', 'Elixir Carolina Reaper Extremo', 'Extremo', '50ml', 5, 14.90, 4, 5, 'PASILLO-Z9'],
            ['PQ-CAR-100', 'Elixir Carolina Reaper Extremo', 'Extremo', '100ml', 5, 22.90, 9, 5, 'PASILLO-Z9'],
            ['PQ-GHO-100', 'Fuego Fantasma Bhut Jolokia', 'Extremo', '100ml', 5, 19.50, 12, 5, 'PASILLO-Z8'],
            ['PQ-SRIR-200', 'Sriracha Artesanal Fermentada', 'Medio', '200ml', 3, 12.90, 26, 6, 'PASILLO-C2'],
            ['PQ-PACK-DEG', 'Trilogía Degustación Piquantum', 'Packs', '3x50ml', 4, 21.00, 14, 4, 'PASILLO-D1'],
        ];
    }

    /**
     * Modo 1: "ANTES DE LA INTEGRACIÓN" (Para captura de evidencia inicial Apartado 9.i).
     * Deja el almacén con su stock inicial y limpia pedidos, incidencias, logs y auditorías IA.
     */
    public static function resetToBeforeIntegration(?PDO $pdo = null): void
    {
        $db = $pdo ?? Database::getConnection();

        $db->exec('DELETE FROM erp_order_items;');
        $db->exec('DELETE FROM erp_orders;');
        $db->exec('DELETE FROM erp_customers;');
        $db->exec('DELETE FROM erp_tickets;');
        $db->exec('DELETE FROM erp_ai_audits;');
        $db->exec('DELETE FROM erp_integration_logs;');
        $db->exec('DELETE FROM erp_funnel_counters;');
        $db->exec('DELETE FROM erp_inventory;');

        self::seedInventoryOnly($db);
    }

    public static function seedInventoryOnly(PDO $db): void
    {
        $now = date('Y-m-d H:i:s');
        $stmt = $db->prepare('
            INSERT OR REPLACE INTO erp_inventory (
                sku, product_name, category, variant_size, heat_level,
                unit_price, stock_available, stock_reserved, min_stock_threshold,
                warehouse_location, sync_status, updated_at
            ) VALUES (
                :sku, :name, :cat, :size, :heat,
                :price, :stock, 0, :min_stock,
                :loc, "SINCRONIZADO", :updated
            )
        ');

        foreach (self::getCatalogItems() as $row) {
            $stmt->execute([
                ':sku'       => $row[0],
                ':name'      => $row[1],
                ':cat'       => $row[2],
                ':size'      => $row[3],
                ':heat'      => $row[4],
                ':price'     => $row[5],
                ':stock'     => $row[6],
                ':min_stock' => $row[7],
                ':loc'       => $row[8],
                ':updated'   => $now,
            ]);
        }

        // Inicializar contadores de embudo base a 0
        $events = [
            'product.viewed'    => 'Fichas de producto vistas (product.viewed)',
            'cart.item_added'   => 'Productos añadidos al carrito (cart.item_added)',
            'checkout.started'  => 'Checkouts iniciados (checkout.started)',
            'order.created'     => 'Pedidos creados e integrados (order.created)',
            'payment.simulated' => 'Pagos confirmados Redsys/Simulado (payment.simulated)',
            'support.requested' => 'Incidencias postventa (support.requested)',
        ];
        $insFunnel = $db->prepare('INSERT OR REPLACE INTO erp_funnel_counters (event_name, event_label, total_count, updated_at) VALUES (:n, :l, 0, :u)');
        foreach ($events as $k => $label) {
            $insFunnel->execute([':n' => $k, ':l' => $label, ':u' => $now]);
        }
    }

    /**
     * Modo 2: "DESPUÉS DE LA INTEGRACIÓN" (Carga y ejecuta todos los escenarios de prueba reales).
     */
    public static function seedAfterIntegrationDemo(?PDO $pdo = null): void
    {
        $db = $pdo ?? Database::getConnection();
        self::resetToBeforeIntegration($db);

        $service = new IntegrationService($db);

        // Poblar eventos de embudo previos del canal digital (product.viewed, cart.item_added, checkout.started)
        $service->incrementFunnelCounter('product.viewed', 148);
        $service->incrementFunnelCounter('cart.item_added', 52);
        $service->incrementFunnelCounter('checkout.started', 19);

        // 1. Integrar serie de pedidos reales de los clientes de prueba de Piquantum (Laura, Carlos, Marta)
        $demoOrders = [
            [
                'execution_id'     => 'exec_n8n_ord_001',
                'event_type'       => 'order.created',
                'order_ref'        => 'PQ-20261104-A101BC',
                'occurred_at'      => '2026-11-04T10:15:00Z',
                'status'           => 'enviado',
                'payment_method'   => 'redsys_sandbox',
                'shipping_address' => 'Gran Vía Escultor Salzillo 18, 30004 Murcia',
                'customer'         => [
                    'id'    => 'USR-LAURA-01',
                    'name'  => 'Laura Gómez (Cliente Prueba)',
                    'email' => 'laura@example.com',
                    'phone' => '+34 600 111 222',
                ],
                'items' => [
                    ['sku' => 'PQ-HAB-200', 'product_name' => 'Lágrima de Habanero Rojo', 'variant_size' => '200ml', 'quantity' => 2, 'unit_price' => 17.50],
                    ['sku' => 'PQ-MNG-100', 'product_name' => 'Néctar Tropical Mango & Habanero', 'variant_size' => '100ml', 'quantity' => 1, 'unit_price' => 11.50],
                ],
                'total_amount' => 51.45,
            ],
            [
                'execution_id'     => 'exec_n8n_ord_002',
                'event_type'       => 'payment.simulated',
                'order_ref'        => 'PQ-20261105-B204DE',
                'occurred_at'      => '2026-11-05T14:40:00Z',
                'status'           => 'enviado',
                'payment_method'   => 'redsys_sandbox',
                'shipping_address' => 'Av. de la Libertad 8, 30009 Murcia',
                'customer'         => [
                    'id'    => 'USR-CARLOS-02',
                    'name'  => 'Carlos Ruiz (Cliente Prueba)',
                    'email' => 'carlos@example.com',
                    'phone' => '+34 600 333 444',
                ],
                'items' => [
                    ['sku' => 'PQ-CAR-100', 'product_name' => 'Elixir Carolina Reaper Extremo', 'variant_size' => '100ml', 'quantity' => 1, 'unit_price' => 22.90],
                    ['sku' => 'PQ-CHP-100', 'product_name' => 'Chipotle Ahumado al Roble', 'variant_size' => '100ml', 'quantity' => 2, 'unit_price' => 9.90],
                ],
                'total_amount' => 47.65,
            ],
            [
                'execution_id'     => 'exec_n8n_ord_003',
                'event_type'       => 'order.created',
                'order_ref'        => 'PQ-20261106-C309FG',
                'occurred_at'      => '2026-11-06T11:20:00Z',
                'status'           => 'enviado',
                'payment_method'   => 'transferencia_simulada',
                'shipping_address' => 'Calle Platería 25, 30001 Murcia',
                'customer'         => [
                    'id'    => 'USR-MARTA-03',
                    'name'  => 'Marta Hernández (Cliente Prueba)',
                    'email' => 'marta@example.com',
                    'phone' => '+34 600 555 666',
                ],
                'items' => [
                    ['sku' => 'PQ-PACK-DEG', 'product_name' => 'Trilogía Degustación Piquantum', 'variant_size' => '3x50ml', 'quantity' => 2, 'unit_price' => 21.00],
                    ['sku' => 'PQ-JAL-100', 'product_name' => 'Brisa Verde de Jalapeño y Lima', 'variant_size' => '100ml', 'quantity' => 2, 'unit_price' => 8.90],
                ],
                'total_amount' => 59.80,
            ],
            [
                'execution_id'     => 'exec_n8n_ord_004',
                'event_type'       => 'payment.simulated',
                'order_ref'        => 'PQ-20261107-D412HI',
                'occurred_at'      => '2026-11-07T09:50:00Z',
                'status'           => 'pendiente_preparacion',
                'payment_method'   => 'redsys_sandbox',
                'shipping_address' => 'Gran Vía Escultor Salzillo 18, 30004 Murcia',
                'customer'         => [
                    'id'    => 'USR-LAURA-01',
                    'name'  => 'Laura Gómez (Cliente Prueba)',
                    'email' => 'laura@example.com',
                ],
                'items' => [
                    ['sku' => 'PQ-SRIR-200', 'product_name' => 'Sriracha Artesanal Fermentada', 'variant_size' => '200ml', 'quantity' => 3, 'unit_price' => 12.90],
                ],
                'total_amount' => 43.65,
            ],
            [
                'execution_id'     => 'exec_n8n_ord_005',
                'event_type'       => 'order.created',
                'order_ref'        => 'PQ-20261107-E518JK',
                'occurred_at'      => '2026-11-07T17:30:00Z',
                'status'           => 'pendiente_preparacion',
                'payment_method'   => 'redsys_sandbox',
                'shipping_address' => 'Av. de la Libertad 8, 30009 Murcia',
                'customer'         => [
                    'id'    => 'USR-CARLOS-02',
                    'name'  => 'Carlos Ruiz (Cliente Prueba)',
                    'email' => 'carlos@example.com',
                ],
                'items' => [
                    ['sku' => 'PQ-GHO-100', 'product_name' => 'Fuego Fantasma Bhut Jolokia', 'variant_size' => '100ml', 'quantity' => 2, 'unit_price' => 19.50],
                    ['sku' => 'PQ-HAB-100', 'product_name' => 'Lágrima de Habanero Rojo', 'variant_size' => '100ml', 'quantity' => 1, 'unit_price' => 10.90],
                ],
                'total_amount' => 54.85,
            ],
            [
                'execution_id'     => 'exec_n8n_ord_006',
                'event_type'       => 'payment.simulated',
                'order_ref'        => 'PQ-20261108-F622LM',
                'occurred_at'      => '2026-11-08T12:10:00Z',
                'status'           => 'pendiente_preparacion',
                'payment_method'   => 'redsys_sandbox',
                'discount_code'    => 'BIENVENIDA10',
                'shipping_address' => 'Calle Platería 25, 30001 Murcia',
                'customer'         => [
                    'id'    => 'USR-MARTA-03',
                    'name'  => 'Marta Hernández (Cliente Prueba)',
                    'email' => 'marta@example.com',
                ],
                'items' => [
                    ['sku' => 'PQ-MNG-100', 'product_name' => 'Néctar Tropical Mango & Habanero', 'variant_size' => '100ml', 'quantity' => 3, 'unit_price' => 11.50],
                    ['sku' => 'PQ-CAR-050', 'product_name' => 'Elixir Carolina Reaper Extremo', 'variant_size' => '50ml', 'quantity' => 1, 'unit_price' => 14.90],
                ],
                'total_amount' => 49.40,
            ],
        ];

        foreach ($demoOrders as $ord) {
            $service->processOrderEvent($ord);
        }

        // 2. Provocar 2 ejecuciones fallidas controladas (para evidenciar Rama de Error y gráfico de errores por tipo)
        $service->processOrderEvent([
            'execution_id' => 'exec_n8n_err_001',
            'event_type'   => 'order.created',
            'order_ref'    => '',
            'customer'     => ['name' => 'Usuario Prueba Incompleto', 'email' => 'correo-invalido'],
            'items'        => [],
            'total_amount' => 15.00,
        ]);

        $service->processOrderEvent([
            'execution_id' => 'exec_n8n_err_002',
            'event_type'   => 'payment.simulated',
            'order_ref'    => 'PQ-20261108-ERR999',
            'customer'     => ['name' => 'Laura Gómez', 'email' => 'laura@example.com'],
            'items'        => [['sku' => 'PQ-HAB-100', 'quantity' => 1, 'unit_price' => 10.90]],
            'total_amount' => -10.90,
        ]);

        // 3. Ejecutar Escenario 2: Cambio operativo de estado y stock
        $service->processOperationalChange([
            'execution_id' => 'exec_n8n_sync_001',
            'change_type'  => 'ORDER_STATUS',
            'order_ref'    => 'PQ-20261107-D412HI',
            'new_status'   => 'enviado',
            'priority'     => 'ALTA',
            'direction'    => 'BackOffice -> n8n -> eCommerce',
        ], false);

        // 4. Ejecutar Escenario 3: Incidencias postventa + Agente IA Supervisado
        // Incidencia 1 (Ya revisada y aprobada por humano)
        $service->processSupportTicket([
            'execution_id'     => 'exec_n8n_inc_001',
            'event_type'       => 'support.requested',
            'ticket_ref'       => 'INC-20261106-1042',
            'order_ref'        => 'PQ-20261104-A101BC',
            'customer_name'    => 'Laura Gómez (Cliente Prueba)',
            'customer_email'   => 'laura@example.com',
            'telegram_chat_id' => '@laura_piquantum_test',
            'subject'          => 'Bote de Habanero 200ml roto durante el transporte',
            'description'      => 'Hola equipo de Piquantum, me acaba de llegar el pedido PQ-20261104-A101BC pero uno de los dos botes de Lágrima de Habanero Rojo 200ml ha llegado con el cristal roto en la caja. Solicito reposición de esa unidad.',
            'priority'         => 'ALTA',
        ]);

        $proposal1 = $service->registerAgentProposal([
            'execution_id'                => 'exec_n8n_ai_001',
            'ticket_ref'                  => 'INC-20261106-1042',
            'order_ref'                   => 'PQ-20261104-A101BC',
            'agent_model'                 => 'gemini-2.5-flash (n8n AI Agent)',
            'herramientas_utilizadas'     => ['consultar_pedido_e_historial_cliente', 'consultar_disponibilidad_almacen', 'proponer_resolucion_ticket'],
            'tipo_caso_detectado'         => 'ROTURA_TRANSPORTE_REPOSICION',
            'datos_consultados'           => [
                'order_ref'             => 'PQ-20261104-A101BC',
                'estado_pedido'         => 'enviado',
                'importe_pedido'        => 51.45,
                'sku_afectado'          => 'PQ-HAB-200',
                'stock_disponible_sku'  => 14,
                'incidencias_previas'   => 0,
                'segmento_cliente'      => 'VIP_PICANTE',
            ],
            'accion_propuesta'            => 'GENERAR_ENVIO_REPOSICION_1_UD_PQ-HAB-200_Y_NOTIFICAR_TELEGRAM',
            'mensaje_propuesto_telegram'  => '🌶️ Hola Laura: Hemos verificado tu pedido PQ-20261104-A101BC en nuestro Back-Office. Confirmamos disponibilidad en almacén de Lágrima de Habanero Rojo (200ml) y nuestro supervisor ha aprobado el envío sin coste de 1 unidad de reemplazo.',
            'justificacion_breve'         => 'El pedido consta pagado y entregado hace menos de 48h, la clienta pertenece al segmento VIP sin historial de reclamaciones previas y existen 14 unidades disponibles en el pasillo A2.',
            'nivel_confianza_o_prioridad' => 'Confianza: 0.95 (95%) | Prioridad: ALTA',
            'riesgos_o_advertencias'      => 'Implica salida de inventario sin cargo y comunicación externa al cliente vía Telegram. Requiere aprobación expresa de operador humano.',
            'estado_final'                => 'pendiente_de_revision',
        ]);

        if (!empty($proposal1['audit_id'])) {
            $service->reviewAgentProposal(
                (int) $proposal1['audit_id'],
                'aprobado',
                'Antonio Martínez (Operador Back-Office)',
                'Verificado albarán de transporte y stock en PASILLO-A2. Se aprueba reposición y notificación por Telegram.'
            );
            // Ajustar tiempo simulado de resolución en minutos para la métrica de MTTR
            $db->prepare('UPDATE erp_tickets SET simulated_resolution_minutes = 24 WHERE ticket_ref = "INC-20261106-1042"')->execute();
        }

        // Incidencia 2 (En estado PENDIENTE DE REVISIÓN HUMANA para demostrar en vivo en el Screencast)
        $service->processSupportTicket([
            'execution_id'     => 'exec_n8n_inc_002',
            'event_type'       => 'support.requested',
            'ticket_ref'       => 'INC-20261108-2089',
            'order_ref'        => 'PQ-20261107-E518JK',
            'customer_name'    => 'Carlos Ruiz (Cliente Prueba)',
            'customer_email'   => 'carlos@example.com',
            'telegram_chat_id' => '@carlos_piquantum_test',
            'subject'          => 'Solicitud de cambio de formato o agilización de envío PQ-20261107-E518JK',
            'description'      => 'Hola, hice ayer el pedido PQ-20261107-E518JK con dos botes de Fuego Fantasma Bhut Jolokia. Necesito saber si tenéis stock para que salga hoy mismo con prioridad urgente porque es para un evento.',
            'priority'         => 'ALTA',
        ]);

        $service->registerAgentProposal([
            'execution_id'                => 'exec_n8n_ai_002',
            'ticket_ref'                  => 'INC-20261108-2089',
            'order_ref'                   => 'PQ-20261107-E518JK',
            'agent_model'                 => 'gemini-2.5-flash (n8n AI Agent)',
            'herramientas_utilizadas'     => ['consultar_pedido_e_historial_cliente', 'consultar_disponibilidad_almacen', 'proponer_resolucion_ticket'],
            'tipo_caso_detectado'         => 'CAMBIO_PRIORIDAD_LOGISTICA_URGENTE',
            'datos_consultados'           => [
                'order_ref'            => 'PQ-20261107-E518JK',
                'estado_pedido'        => 'pendiente_preparacion',
                'estado_pago'          => 'pagado_simulado (Redsys OK)',
                'sku_consultado'       => 'PQ-GHO-100',
                'stock_disponible_sku' => 10,
                'ubicacion_almacen'    => 'PASILLO-Z8',
            ],
            'accion_propuesta'            => 'ELEVAR_PRIORIDAD_A_URGENTE_Y_CONFIRMAR_SALIDA_HOY_TELEGRAM',
            'mensaje_propuesto_telegram'  => '🌶️ Hola Carlos: Hemos consultado tu pedido PQ-20261107-E518JK. El pago está confirmado y contamos con stock inmediato en PASILLO-Z8. Hemos elevado tu pedido a prioridad URGENTE para su expedición hoy mismo.',
            'justificacion_breve'         => 'El pedido aún se encuentra en estado pendiente_preparacion (no ha salido de almacén), el pago Redsys está confirmado y hay 10 unidades en stock.',
            'nivel_confianza_o_prioridad' => 'Confianza: 0.91 (91%) | Prioridad: ALTA',
            'riesgos_o_advertencias'      => 'Compromete fecha de salida prioritaria en almacén y envía compromiso de entrega al cliente por Telegram. Requiere validación humana antes de ejecutar.',
            'estado_final'                => 'pendiente_de_revision',
        ]);
    }
}
