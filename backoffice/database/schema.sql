-- =========================================================================
-- ESQUEMA DE BASE DE DATOS INDEPENDIENTE DEL BACK-OFFICE (PIQUANTUM ERP)
-- SQLite 3 (usa AUTOINCREMENT, INSERT OR REPLACE y julianday(): no es compatible con MySQL tal cual)
-- =========================================================================

CREATE TABLE IF NOT EXISTS erp_customers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    external_customer_id VARCHAR(64) NULL,
    name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(40) NULL,
    shipping_address TEXT NULL,
    segment VARCHAR(40) NOT NULL DEFAULT 'ESTANDAR',
    orders_count INTEGER NOT NULL DEFAULT 0,
    total_spent DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS erp_orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_ref VARCHAR(64) NOT NULL UNIQUE,
    customer_id INTEGER NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_email VARCHAR(150) NOT NULL,
    shipping_address TEXT NULL,
    subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    tax_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    shipping_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    discount_code VARCHAR(50) NULL,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(10,2) NOT NULL,
    payment_method VARCHAR(50) NULL,
    payment_status VARCHAR(40) NOT NULL DEFAULT 'pagado_simulado',
    order_status VARCHAR(40) NOT NULL DEFAULT 'pendiente_preparacion',
    priority VARCHAR(30) NOT NULL DEFAULT 'NORMAL',
    sync_status VARCHAR(40) NOT NULL DEFAULT 'SINCRONIZADO',
    channel_created_at DATETIME NOT NULL,
    integrated_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    FOREIGN KEY (customer_id) REFERENCES erp_customers(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS erp_order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    sku VARCHAR(64) NOT NULL,
    product_name VARCHAR(160) NOT NULL,
    variant_size VARCHAR(30) NULL,
    quantity INTEGER NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    line_total DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (order_id) REFERENCES erp_orders(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS erp_inventory (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sku VARCHAR(64) NOT NULL UNIQUE,
    product_name VARCHAR(160) NOT NULL,
    category VARCHAR(80) NOT NULL,
    variant_size VARCHAR(30) NULL,
    heat_level INTEGER NOT NULL DEFAULT 3,
    unit_price DECIMAL(10,2) NOT NULL,
    stock_available INTEGER NOT NULL DEFAULT 0,
    stock_reserved INTEGER NOT NULL DEFAULT 0,
    min_stock_threshold INTEGER NOT NULL DEFAULT 5,
    warehouse_location VARCHAR(40) NOT NULL DEFAULT 'PASILLO-A1',
    sync_status VARCHAR(40) NOT NULL DEFAULT 'SINCRONIZADO',
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS erp_tickets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_ref VARCHAR(64) NOT NULL UNIQUE,
    order_ref VARCHAR(64) NULL,
    customer_email VARCHAR(150) NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    telegram_chat_id VARCHAR(64) NULL,
    subject VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'POSTVENTA_GENERAL',
    priority VARCHAR(30) NOT NULL DEFAULT 'MEDIA',
    status VARCHAR(40) NOT NULL DEFAULT 'ABIERTA',
    resolution_notes TEXT NULL,
    created_at DATETIME NOT NULL,
    resolved_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS erp_ai_audits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ticket_ref VARCHAR(64) NOT NULL,
    order_ref VARCHAR(64) NULL,
    agent_model VARCHAR(80) NULL,
    tools_invoked TEXT NOT NULL,
    -- Los 7 campos obligatorios de la salida estructurada (Apartado 6)
    detected_case_type VARCHAR(100) NOT NULL,
    consulted_data_json TEXT NOT NULL,
    proposed_action VARCHAR(160) NOT NULL,
    proposed_customer_message TEXT NOT NULL,
    justification TEXT NOT NULL,
    confidence_and_priority VARCHAR(80) NOT NULL,
    risks_and_warnings TEXT NOT NULL,
    final_status VARCHAR(50) NOT NULL DEFAULT 'pendiente_de_revision',
    -- Trazabilidad de supervisión humana
    reviewed_by VARCHAR(120) NULL,
    review_comment TEXT NULL,
    reviewed_at DATETIME NULL,
    telegram_notified INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS erp_integration_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    execution_id VARCHAR(80) NOT NULL,
    scenario VARCHAR(60) NOT NULL,
    event_type VARCHAR(80) NOT NULL,
    direction VARCHAR(60) NOT NULL DEFAULT 'eCommerce -> n8n -> BackOffice',
    entity_ref VARCHAR(80) NULL,
    status VARCHAR(30) NOT NULL,
    error_type VARCHAR(60) NULL,
    validation_summary TEXT NOT NULL,
    payload_json TEXT NOT NULL,
    response_json TEXT NULL,
    execution_ms INTEGER NOT NULL DEFAULT 18,
    created_at DATETIME NOT NULL
);

-- Idempotencia: eventos ya procesados (event_id de la tienda). Un evento repetido se responde como «duplicate».
CREATE TABLE IF NOT EXISTS erp_processed_events (
    event_id VARCHAR(100) PRIMARY KEY,
    event_type VARCHAR(80) NOT NULL,
    entity_ref VARCHAR(80) NOT NULL,
    processed_at DATETIME NOT NULL
);

-- Bandeja de salida hacia n8n (outbox): primero se guarda el cambio y su aviso, después se envía.
-- Los avisos que n8n no confirma (2xx) quedan PENDIENTE y se reintentan con el mismo cuerpo y event_id.
CREATE TABLE IF NOT EXISTS erp_outbox_webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    event_id VARCHAR(100) NOT NULL UNIQUE,
    event_type VARCHAR(80) NOT NULL,
    target_env_key VARCHAR(80) NOT NULL,
    body_json TEXT NOT NULL,
    entity_type VARCHAR(30) NOT NULL,
    entity_ref VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'PENDIENTE',
    attempts INTEGER NOT NULL DEFAULT 0,
    last_http_code INTEGER NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    last_attempt_at DATETIME NULL,
    sent_at DATETIME NULL
);

-- Versión del esquema: Database::SCHEMA_VERSION debe coincidir (si no, hay que recrear la base de datos)
PRAGMA user_version = 6;
