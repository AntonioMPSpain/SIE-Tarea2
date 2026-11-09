# Piquantum Back-Office ERP & Cuadro de Mando Operativo (Tarea 2)

> ⚠️ **Prototipo académico sin actividad comercial real.** Sistema independiente de Back-Office (ERP/CRM + Trazabilidad + Gobernanza de IA Agéntica + Cuadro de Mando Operativo) desarrollado para la **Tarea 2** de *Soluciones Informáticas para la Empresa* (UCAM).

---

## 1. Ejecución rápida en local (Windows / Linux / macOS)

No requiere configurar servidores externos ni tocar el proyecto de la Tarea 1 (`Piquantum`): utiliza su propia base de datos independiente SQLite (`storage/database/piquantum_erp.sqlite`).

```powershell
cd backoffice
cp .env.example .env   # pon valores propios; con "cambia-esto" el Back-Office no arranca
php -S 127.0.0.1:8080 -t public public/index.php
```

Variables obligatorias en `.env` (nunca se lee `.env.example`): `INTEGRATION_API_KEY` y `WEBHOOK_HMAC_SECRET` (`openssl rand -hex 32`). Para entrar en la web, `ERP_ADMIN_EMAIL` y `ERP_ADMIN_PASSWORD_HASH` (hash bcrypt; el comando para generarlo está en `.env.example`). Si cambia el esquema, en desarrollo hay que borrar el `.sqlite` para que se cree de nuevo.

Abre en el navegador: **http://127.0.0.1:8080**

Para ejecutar la batería de pruebas automáticas de integración, validaciones, idempotencia y métricas (usa una base de datos temporal propia y devuelve código 1 si falla alguna):
```powershell
php tests/verify_backoffice.php
```

---

## 2. Ejecución en Docker (VM Google Cloud junto a n8n)

El directorio `backoffice/` incluye su propio `Dockerfile` (PHP 8.3 Alpine + PDO SQLite). El despliegue en la VM (Caddy con HTTPS, n8n y este contenedor, sin puertos públicos) va en `deploy/`. Los secretos se pasan como variables de entorno o con `--env-file`; no se copian en la imagen.

Para los reintentos hacia n8n hace falta un disparador cada minuto: `php bin/retry_webhooks.php` (cron) o `POST /api/v1/internal/webhooks/retry` desde n8n.

---

## 3. Funcionalidades implementadas (Parte de Antonio)

1. **Cuadro de Mando Operativo (`/?tab=dashboard` — Apartado 7):**
   - **8 métricas operativas en tiempo real** obtenidas mediante consultas SQL sobre la base de datos del Back-Office (solo datos reales recibidos por la integración; nada de datos de demostración):
     1. Número de pedidos integrados y ticket medio.
     2. Importe total simulado (€).
     3. Ejecuciones de integración correctas vs. fallidas y latencia media (ms).
     4. Tasa de conversión del flujo: pedidos integrados → pagados → con incidencia (el Back-Office no recibe `product.viewed`, `cart.item_added` ni `checkout.started`).
     5. Incidencias abiertas vs. resueltas.
     6. Tiempo medio de resolución real (`resolved_at − created_at`, en minutos).
     7. Acciones propuestas por el Agente de IA vs. acciones aprobadas/rechazadas por supervisión humana.
     8. Alertas de stock crítico en almacén.
   - **4 representaciones gráficas interactivas (Chart.js):**
     - Diagrama de barras con el histórico diario de pedidos e ingresos.
     - Gráfico de barras horizontal con los productos (salsas picantes) más solicitados.
     - Gráfico Donut de distribución de pedidos por estado.
     - Embudo de conversión y desglose de errores de integración por tipo (`ERR_CAMPOS_OBLIGATORIOS`, `ERR_IMPORTE_INVALIDO`, etc.).

2. **Gestión de Pedidos y Sincronización (`/?tab=orders` — Escenarios 1 y 2):**
   - Recepción estructurada de pedidos con validación de campos obligatorios, total que cuadra, SKU y stock comprobados, e **idempotencia por `event_id`** (los eventos solo hacen avanzar el estado).
   - Formulario operativo para modificar estado (solo las transiciones permitidas, como `Order::TRANSITIONS` de la tienda) y prioridad (`NORMAL`, `ALTA`, `URGENTE`). Primero guarda el cambio y después avisa a n8n; si n8n no confirma, queda `PENDIENTE_SINCRONIZACION` y se reintenta.

3. **Almacén y Stock (`/?tab=inventory` — Escenario 2 + Tool IA):**
   - Control de inventario de las 13 referencias de la tienda (`SAL-001-50ML` … `SAL-009`) por SKU, formato (50 ml, 100 ml, 200 ml o tamaño único), ubicación de pasillo y umbral de stock mínimo. Carga desde la tienda con `POST /api/v1/inventory/import`.

4. **Postventa, Agente de IA Supervisado y Revisión Humana (`/?tab=ai_governance` — Escenario 3 y Apartado 6):**
   - Visualización de los **7 campos obligatorios de la salida estructurada del Agente de IA**:
     - `a. Tipo de caso detectado`
     - `b. Datos consultados` (JSON devuelto por las Tools de consulta)
     - `c. Acción propuesta`
     - `d. Justificación breve`
     - `e. Nivel de confianza o prioridad`
     - `f. Riesgos o advertencias`
     - `g. Estado final` (`pendiente_de_revision`, `aprobado`, `rechazado`, `ejecutado_en_modo_simulado`)
   - Botones de **Supervisión Humana (`✅ Aprobar y Enviar a Telegram` / `❌ Rechazar Propuesta`)**. El revisor es el operador con sesión iniciada y queda su timestamp. `telegram_notified` solo pasa a 1 cuando n8n confirma (2xx).

5. **Trazabilidad y Logs de Ejecución (`/?tab=logs` — Apartado 3.e y 3.f):**
   - Historial auditable con identificador de ejecución, escenario, dirección del flujo, estado (`SUCCESS` / `ERROR` / `DUPLICADO`), código de error y visor del Payload JSON recibido y su respuesta.

6. **Evidencias "Antes / Después" y Simulador (`/?tab=simulator` — Apartado 9.g y 9.i):**
   - No hay datos de demostración: el "antes" es la base de datos recién creada (solo el catálogo) y el "después", lo que llega por la integración real con n8n.
   - Botón **"🧹 Vaciar BD (solo desarrollo)"**, visible solo con `APP_ENV=local`.
   - Lanzador interactivo con los payloads JSON precargados.

7. **Acceso:** inicio de sesión del operador (`/login`), cierre de sesión y token CSRF en todos los formularios.

---

## 4. Endpoints de la API REST para conectar desde n8n

| Método | Ruta | Uso en n8n / Agente IA |
|---|---|---|
| `GET` | `/api/v1/health` | Comprobación de estado del servicio Back-Office (única ruta sin `X-Integration-Token`) |
| `GET` | `/api/v1/metrics` | JSON en vivo con todas las métricas del Cuadro de Mando |
| `POST` | `/api/v1/webhooks/orders` | **Escenario 1:** Crear/actualizar pedido integrado |
| `POST` | `/api/v1/webhooks/operations` | **Escenario 2:** Sincronizar cambio de estado o stock |
| `POST` | `/api/v1/webhooks/tickets` | **Escenario 3:** Crear ticket de incidencia postventa |
| `GET` | `/api/v1/agent/orders/{order_ref}` | **Tool Consulta 1 (Agente IA):** Consultar pedido, cliente, historial e inventario |
| `GET` | `/api/v1/agent/inventory/{sku}` | **Tool Consulta 2 (Agente IA):** Consultar stock y ubicación de almacén |
| `POST` | `/api/v1/agent/proposals` | **Tool Acción (Agente IA):** Registrar propuesta estructurada de 7 campos |
| `POST` | `/api/v1/agent/reviews` | Registrar aprobación/rechazo humano desde callback de Telegram (`decision` y `reviewed_by` obligatorios) |
| `POST` | `/api/v1/inventory/import` | Carga del catálogo desde la tienda (alta o actualización por `sku`, idempotente) |
| `POST` | `/api/v1/internal/webhooks/retry` | Reintentar los avisos pendientes a n8n (alternativa al cron) |
