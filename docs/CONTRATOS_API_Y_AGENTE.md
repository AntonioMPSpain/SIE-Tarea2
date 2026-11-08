# Especificación de Contratos de Integración, Gestión de Errores y Métricas (Memoria Tarea 2)

Este documento detalla los contratos técnicos implementados en el **Back-Office de Piquantum** (`backoffice/`), sirviendo tanto de referencia técnica para el equipo como de contenido directo para los apartados **3, 4, 5, 6, 7 y 9** de la Memoria de la Tarea 2.

---

## 1. Seguridad y Cabeceras Comunes

- **Autenticación API (`Back-Office`):**
  - Cabecera HTTP **obligatoria** en todas las rutas `/api/v1/*` salvo `GET /api/v1/health`: `X-Integration-Token: <INTEGRATION_API_KEY>`. El valor está en el `.env` del servidor y nunca se escribe en el repositorio.
  - Solo se acepta por cabecera (nunca por la URL) y se compara en tiempo constante (`hash_equals`).
  - Sin cabecera → `401 ERR_AUTH_TOKEN_AUSENTE`; token incorrecto → `401 ERR_AUTH_TOKEN_INVALIDO`.
  - Si el servidor no tiene configurados `INTEGRATION_API_KEY` o `WEBHOOK_HMAC_SECRET` (o siguen con el valor de ejemplo `cambia-esto`), la aplicación no arranca: `500 ERR_CONFIGURACION`.
  - Sin cabeceras CORS: la API la llama n8n desde el servidor, no un navegador.
- **Firma de Webhooks Salientes (`Back-Office -> n8n`):**
  - Cabecera HTTP: `X-Piquantum-Signature: sha256=<HMAC-SHA256>` calculada sobre el cuerpo JSON con `WEBHOOK_HMAC_SECRET`.
- **Web del Back-Office:** inicio de sesión propio (`ERP_ADMIN_EMAIL` y `ERP_ADMIN_PASSWORD_HASH`, la contraseña solo como hash bcrypt) y token CSRF en todos los formularios.

---

## 2. Catálogo de Validaciones y Códigos de Error (`erp_integration_logs`)

Cada petición recibida por el Back-Office se somete a validación sintáctica y semántica antes de tocar las tablas de negocio. **Ningún dato que falte se rellena con un valor inventado:** si es obligatorio se rechaza y si es opcional se guarda como `NULL`. Tanto los aciertos (`SUCCESS`) como los rechazos (`ERROR`) y los duplicados (`DUPLICADO`) se guardan en `erp_integration_logs`, y los errores alimentan el gráfico de **Errores por Tipo** del Cuadro de Mando:

| Código de Error | HTTP Status | Condición de Disparo | Comportamiento del Sistema |
|---|---|---|---|
| `ERR_AUTH_TOKEN_AUSENTE` | `401` | Falta la cabecera `X-Integration-Token`. | Rechazo inmediato antes de procesar el payload. |
| `ERR_AUTH_TOKEN_INVALIDO` | `401` | Cabecera `X-Integration-Token` no coincide con el secreto configurado. | Rechazo inmediato antes de procesar el payload. |
| `ERR_CONFIGURACION` | `500` | Faltan `INTEGRATION_API_KEY` o `WEBHOOK_HMAC_SECRET` en el servidor. | La aplicación no arranca: nunca funciona con valores conocidos. |
| `ERR_CAMPOS_OBLIGATORIOS` | `422` | **Pedido:** falta `event_id`, `order_ref`, `status` (o no está en el catálogo), `occurred_at`, `customer.name`, `customer.email` (o formato inválido), `items[]`, `sku`/`product_name`/`quantity`/`unit_price` de una línea, `shipping_amount`, o `discount_amount` habiendo `discount_code`. **Incidencia:** falta `ticket_ref`, `customer_name`, `customer_email`, `subject`, `description`, o `telegram_chat_id` no es numérico. **Revisión humana:** falta `decision` (`aprobado` \| `rechazado`) o `reviewed_by`. | Se rechaza sin guardar nada y la respuesta incluye `invalid_fields` con la lista de campos. |
| `ERR_IMPORTE_INVALIDO` | `422` | `total_amount <= 0` o el total no cuadra: `total_amount ≠ suma de líneas − discount_amount + shipping_amount` (±0,01). | Rechazo del pedido y registro en trazabilidad (la respuesta incluye `expected_total`). |
| `ERR_ENTIDAD_NO_ENCONTRADA` | `422` / `404` | El pedido incluye algún `sku` que no existe en el almacén (`details.unknown_skus`), o se intenta sincronizar un `order_ref`, `sku` o propuesta que no existe. | No se guarda nada (transacción completa) y se informa de los identificadores inexistentes. |
| `ERR_STOCK_INSUFICIENTE` | `422` | Alguna línea pide más unidades de las disponibles (sumando las líneas del mismo SKU). | No se guarda nada; `details.insufficient_stock` indica pedidas y disponibles. |
| `ERR_TRANSICION_ESTADO` | `422` | En el Escenario 2, el estado no está en el catálogo o la transición no está permitida (tabla del apartado 3.2). | No se altera el pedido; la respuesta incluye `allowed_next`. |
| `ERR_SALIDA_AGENTE_INCOMPLETA` | `422` | La propuesta del Agente IA no incluye alguno de los **7 campos obligatorios** del Apartado 6, `ticket_ref`, `mensaje_propuesto_telegram` o `herramientas_utilizadas`. | Se bloquea el registro de la propuesta del agente. |
| `ERR_NOTIFICACION_TELEGRAM` | — | n8n no confirma (2xx) el aviso de una propuesta aprobada. | `telegram_notified` sigue a 0, la incidencia queda `APROBADA_PENDIENTE_NOTIFICACION` y el aviso se reintenta. |
| `ERR_SINCRONIZACION_N8N` | — | n8n no confirma (2xx) un aviso de cambio de estado o de stock. | Queda `PENDIENTE_SINCRONIZACION` y se reintenta; tras 5 intentos, `ERROR_SINCRONIZACION`. |

---

## 3. Idempotencia, Transiciones y Reintentos

### 3.1. Idempotencia en la Integración de Pedidos (Escenario 1)

1. Cada evento lleva un **`event_id`** único (el que genera la tienda). El Back-Office guarda los `event_id` procesados en `erp_processed_events`.
2. Si llega un `event_id` **ya procesado** (por ejemplo, un reintento de n8n o de la tienda), responde `200` con `"result": "duplicate"` y no toca nada.
3. Si el `event_id` es nuevo y el `order_ref` **no existe**, crea el pedido, inserta las líneas en `erp_order_items` y descuenta el stock en `erp_inventory`, todo en una transacción (`"result": "created"`).
4. Si el `order_ref` **ya existe** (por ejemplo, `payment.simulated` después de `order.created`), el evento solo puede **hacer avanzar** el estado según la tabla de transiciones (`"result": "updated"`). Nunca lo hace retroceder: un `order.created` que llega tarde a un pedido ya `enviado` se ignora (`"result": "ignored"`). El total no se sobrescribe y el stock no se vuelve a descontar.

En las incidencias, `event_id` es opcional; si llega repetido, la respuesta también es `duplicate`.

### 3.2. Transiciones de estado (misma tabla que `Order::TRANSITIONS` de la tienda)

| Desde | Puede pasar a |
|---|---|
| `creado` | `cancelado`, `incidencia` |
| `pagado_simulado` | `pendiente_preparacion`, `cancelado`, `incidencia` |
| `pendiente_preparacion` | `enviado`, `cancelado`, `incidencia` |
| `enviado` | `incidencia` |
| `incidencia` | `pendiente_preparacion`, `cancelado` |
| `cancelado` | — (estado final) |

El mismo estado responde `200` sin cambios. Los eventos de la tienda, además, pueden pasar de `creado` a pagado (`pagado_simulado` / `pendiente_preparacion`), porque el cobro lo hace la tienda.

### 3.3. Avisos del Back-Office a n8n: bandeja de salida y reintentos (Escenarios 2 y 3)

1. El Back-Office **primero guarda** el cambio y, en la misma transacción, el aviso en `erp_outbox_webhooks`; **después** lo envía a n8n.
2. Solo se considera entregado si n8n responde **2xx**: entonces el pedido o el producto pasa a `SINCRONIZADO`, o, en una propuesta aprobada, `telegram_notified = 1` y la incidencia queda `RESUELTA`.
3. Si no, queda `PENDIENTE` y se reintenta con **el mismo cuerpo y el mismo `event_id`** (y por tanto la misma firma), hasta 5 intentos. Después queda `FALLIDO` y la entidad, `ERROR_SINCRONIZACION`.
4. Disparador de los reintentos: `php bin/retry_webhooks.php` desde un cron, o `POST /api/v1/internal/webhooks/retry` (con token) desde un *Schedule Trigger* de n8n.

### 3.4. Carga del catálogo desde la tienda

`POST /api/v1/inventory/import` recibe `{"products": [{sku, product_name, category, variant_size, unit_price, stock_available}]}` (ejemplo: `payloads/carga_catalogo_tienda.json`) y hace alta o actualización por `sku`. Es idempotente: importar dos veces la misma lista no duplica nada. Es todo o nada: si un producto no es válido, no se guarda ninguno. Cada ejecución queda en el log (`CARGA_CATALOGO`). Códigos de producto: `SKU-TAMAÑO` sin espacios en mayúsculas (`SAL-002-100ML`), o solo el SKU si el producto no tiene tamaños (`SAL-004`).

---

## 4. Justificación Académica y Técnica de las 9 Métricas del Cuadro de Mando (Apartado 7)

El apartado 7 del enunciado exige que las métricas procedan de datos reales generados por el sistema y que se justifique su elección en base a manuales o literatura técnica:

1. **Número de pedidos integrados e histórico diario (Diagrama de barras):** Métrica volumétrica fundamental de actividad transaccional omnicanal (Laudon & Traver, *E-Commerce: Business, Technology, Society*). Permite detectar picos de demanda y caídas en el flujo de captación.
2. **Importe total simulado (€) y Ticket Medio (AOV):** Indicador financiero primario para contrastar la conciliación entre la pasarela de pagos del front-office (Redsys) y el libro mayor del ERP.
3. **Productos y formatos más solicitados (Rotación por SKU):** Basado en principios de gestión de cadena de suministro (SCM / APICS), permite identificar los SKUs de alta rotación (por ejemplo, *Salsa Chipotle Ahumado 100 ml* o *Salsa Habanero Mango*) para priorizar lotes de fabricación.
4. **Pedidos por estado operativo:** Indicador de flujo de trabajo (*Workflow WIP*) que visibiliza cuellos de botella en preparación de almacén (`pendiente_preparacion` vs. `enviado`) y pedidos bloqueados por `incidencia`.
5. **Incidencias abiertas vs. resueltas:** Métrica de calidad de servicio postventa alineada con **ITIL v4 (Incident & Service Request Management)** para controlar el *backlog* de atención al cliente.
6. **Tiempo medio de resolución (MTTR — *Mean Time To Resolve*):** Métrica estándar de **ITIL v4 / SRE**: media real de `resolved_at − created_at` (sin mínimos ni valores simulados), desde el registro del evento `support.requested` hasta el cierre confirmado tras la supervisión humana del Agente de IA. Sin incidencias resueltas no se muestra ninguna cifra.
7. **Eventos de integración correctos vs. fallidos y Errores por tipo:** Basado en los patrones de **Enterprise Integration Patterns (Hohpe & Woolf)** y observabilidad de middlewares, mide la fiabilidad del canal de integración (`n8n <-> Back-Office`) y clasifica la causa raíz de los rechazos de validación.
8. **Tasa de conversión del flujo (embudo):** solo con etapas que el Back-Office recibe de verdad: *pedidos integrados → pagados → con incidencia postventa*, calculadas con consultas sobre `erp_orders` y `erp_tickets`. El Back-Office no recibe `product.viewed`, `cart.item_added` ni `checkout.started`, así que esas etapas no se muestran (mostrarlas sería inventar datos).
9. **Acciones propuestas por el Agente de IA vs. Aprobadas (Tasa de aceptación HITL):** Métrica específica de gobernanza de sistemas *Human-in-the-Loop (HITL)* (NIST AI Risk Management Framework, 2023) que mide la precisión operativa del agente y evita la automatización descontrolada de acciones sensibles.
