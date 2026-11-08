<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Database;

use PDO;
use Piquantum\BackOffice\Config\Database;

class Seeder
{
    /**
     * Catálogo inicial = catálogo real de la tienda (Tarea 1, www.piquantum.onl).
     * Código: SKU-TAMAÑO sin espacios en mayúsculas, o solo el SKU si el producto no tiene tamaños.
     * Precios con IVA incluido y stock inicial del seeder de la Tarea 1 (comprobar el stock real en el /admin de la tienda;
     * lo correcto es cargarlo desde la tienda con POST /api/v1/inventory/import).
     * heat_level, min_stock_threshold y warehouse_location NO existen en la tienda: son datos propios del almacén del Back-Office.
     *
     * Columnas: [sku, product_name, category, variant_size, heat_level, unit_price, stock, min_stock_threshold, warehouse_location]
     */
    public static function getCatalogItems(): array
    {
        return [
            ['SAL-001-50ML',  'Salsa Carolina Reaper Inferno', 'extremas', '50 ml',  5,  8.90, 15, 5, 'PASILLO-C1'],
            ['SAL-001-100ML', 'Salsa Carolina Reaper Inferno', 'extremas', '100 ml', 5, 12.90, 10, 5, 'PASILLO-C1'],
            ['SAL-001-200ML', 'Salsa Carolina Reaper Inferno', 'extremas', '200 ml', 5, 18.50,  5, 3, 'PASILLO-C1'],
            ['SAL-002-50ML',  'Salsa Chipotle Ahumado',        'suaves',   '50 ml',  2,  4.50, 30, 5, 'PASILLO-A1'],
            ['SAL-002-100ML', 'Salsa Chipotle Ahumado',        'suaves',   '100 ml', 2,  6.50, 20, 5, 'PASILLO-A1'],
            ['SAL-002-200ML', 'Salsa Chipotle Ahumado',        'suaves',   '200 ml', 2,  9.90, 10, 5, 'PASILLO-A1'],
            ['SAL-003',       'Salsa Verde Jalapeño & Lima',   'suaves',   null,     1,  5.90, 80, 10, 'PASILLO-A2'],
            ['SAL-004',       'Sriracha Artesana',             'picantes', null,     2,  7.20, 70, 10, 'PASILLO-B1'],
            ['SAL-005',       'Salsa Habanero Mango',          'picantes', null,     4,  8.90, 45, 8, 'PASILLO-B2'],
            ['SAL-006',       'Salsa Scotch Bonnet Caribe',    'picantes', null,     4,  9.50, 35, 8, 'PASILLO-B3'],
            ['SAL-007',       'Salsa Ghost Pepper Bhut',       'extremas', null,     5, 11.90, 25, 5, 'PASILLO-C2'],
            ['SAL-008',       'Pack Iniciación al Picante',    'packs',    null,     3, 19.90, 20, 5, 'PASILLO-D1'],
            ['SAL-009',       'Pack Desafío Extrem',           'packs',    null,     5, 22.90, 15, 5, 'PASILLO-D1'],
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
        $db->exec('DELETE FROM erp_processed_events;');
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
    }
}
