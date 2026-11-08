# AGENTS.md — Contexto para Agentes de IA y Desarrolladores (Tarea 2 SIE - Piquantum)

Este documento proporciona el contexto arquitectónico, reglas de negocio y contratos técnicos del proyecto para que cualquier desarrollador del equipo o agente de codificación (Antigravity, Claude Code, Cursor, GitHub Copilot) pueda trabajar de forma segura y coherente.

---

## 1. Reglas Críticas del Proyecto

1. **PROHIBIDO modificar la carpeta de la Tarea 1:** El proyecto original del eCommerce (`../Tarea 1` o `../tienda-online`) no debe ser alterado desde este workspace sin permiso expreso.
2. **Separación estricta Front-Office vs. Back-Office:** Según las aclaraciones del profesorado, el Back-Office (`backoffice/`) es un sistema **totalmente independiente** del eCommerce, con su propia base de datos (`piquantum_erp.sqlite` / `piquantum_erp_db`) y su propio servidor/contenedor.
3. **Cero secretos o datos personales reales:** Todo el código, `.env.example`, workflows de n8n y payloads deben usar exclusivamente datos ficticios académicos (`laura@example.com`, `carlos@example.com`, `marta@example.com`) y tokens de prueba.
4. **Salida estructurada y supervisión humana del Agente IA:** Cualquier flujo del Agente de IA debe usar herramientas (*Tools*) de consulta y acción, devolver los **7 campos obligatorios** del Apartado 6 del enunciado y quedar en estado `pendiente_de_revision` hasta que un humano apruebe la acción.

---

## 2. Estructura del Workspace (`Tarea 2/`)

```text
Tarea 2/
├── AGENTS.md                        # Instrucciones para agentes de IA y contexto global
├── PLAN_TAREA_2.md                  # Plan Maestro de la práctica y reparto entre los 3 miembros
├── docs/
│   ├── GUIA_COMPAÑEROS_INTEGRACION.md # Guía directa para Abel (GCP + n8n) y Álvaro (Agente IA + Telegram)
│   └── CONTRATOS_API_Y_AGENTE.md      # Documentación de contratos JSON, validaciones y Tools del Agente
├── payloads/                        # Payloads oficiales de prueba (Apartado 9.g)
│   ├── escenario1_pedido_integrado.json
│   ├── escenario1_error_datos_incompletos.json
│   ├── escenario2_cambio_operativo.json
│   ├── escenario3_incidencia_postventa.json
│   ├── escenario3_salida_estructurada_agente_ia.json
│   └── carga_catalogo_tienda.json
└── backoffice/                      # Aplicación de Back-Office ERP + Cuadro de Mando (Parte de Antonio)
    ├── .env.example                 # Variables de entorno de ejemplo sin secretos
    ├── Dockerfile                   # Imagen Docker PHP 8.3 Alpine lista para Google Cloud VM
    ├── README.md                    # Manual rápido del Back-Office
    ├── bin/retry_webhooks.php       # Reintento de los avisos pendientes a n8n (para un cron)
    ├── config/Database.php          # Conexión PDO (SQLite), lectura de .env y comprobación de secretos obligatorios
    ├── database/
    │   ├── schema.sql               # Esquema de las 9 tablas independientes del Back-Office (SQLite, versión en PRAGMA user_version)
    │   └── Seeder.php               # Catálogo inicial (el de la tienda) y vaciado de la BD solo en desarrollo
    ├── src/
    │   ├── Services/
    │   │   ├── IntegrationService.php # Validación, idempotencia, stock, Tools IA, supervisión y métricas SQL
    │   │   └── WebhookNotifier.php    # Emisor de webhooks firmados HMAC-SHA256 hacia n8n
    │   └── Controllers/
    │       ├── ApiController.php      # Endpoints REST /api/v1/* para n8n y Agente IA
    │       └── WebController.php      # Controlador de las 6 pestañas del Back-Office y Cuadro de Mando
    ├── views/layout.php             # Interfaz web con Tailwind CSS y gráficos Chart.js
    ├── views/login.php              # Inicio de sesión del operador
    ├── public/index.php             # Front-controller y autoloader PSR-4
    └── tests/verify_backoffice.php  # Pruebas automáticas (BD temporal propia; código de salida 1 si falla alguna)
```

---

## 3. Comandos de Desarrollo y Verificación

- **Iniciar el servidor local del Back-Office:**
  ```powershell
  php -S 127.0.0.1:8080 -t backoffice/public backoffice/public/index.php
  ```
- **Ejecutar los tests automáticos** (usan una base de datos temporal propia; no tocan la de la aplicación):
  ```powershell
  php backoffice/tests/verify_backoffice.php
  ```

---

## 4. Resumen de Endpoints del Back-Office (`http://localhost:8080` o `http://backoffice:8080` en Docker)

Todas las rutas salvo `health` exigen la cabecera `X-Integration-Token: <INTEGRATION_API_KEY>`.

- `GET /api/v1/health` → Estado del servidor (sin token).
- `GET /api/v1/metrics` → Métricas operativas en tiempo real.
- `POST /api/v1/webhooks/orders` → **Escenario 1** (Ingesta de pedido desde n8n).
- `POST /api/v1/webhooks/operations` → **Escenario 2** (Sincronización de estado de pedido o stock).
- `POST /api/v1/webhooks/tickets` → **Escenario 3** (Creación de incidencia postventa).
- `GET /api/v1/agent/orders/{order_ref}` → **Tool de Consulta 1 del Agente IA**.
- `GET /api/v1/agent/inventory/{sku}` → **Tool de Consulta 2 del Agente IA**.
- `POST /api/v1/agent/proposals` → **Tool de Acción/Propuesta del Agente IA** (valida los 7 campos obligatorios).
- `POST /api/v1/agent/reviews` → Confirmación de aprobación/rechazo humano desde Telegram o n8n (`decision` y `reviewed_by` obligatorios).
- `POST /api/v1/inventory/import` → Carga del catálogo desde la tienda (alta o actualización por `sku`, idempotente).
- `POST /api/v1/internal/webhooks/retry` → Reintento de los avisos pendientes a n8n.
