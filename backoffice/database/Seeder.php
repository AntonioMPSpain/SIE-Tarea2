<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Database;

use PDO;
use Piquantum\BackOffice\Config\Database;

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
     * Vacía la base de datos y deja solo el catálogo inicial del almacén.
     * Solo para desarrollo (botón visible con APP_ENV=local); en producción no se usa.
     * No hay datos de demostración: todo lo demás tiene que llegar por la integración real (n8n).
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
}
