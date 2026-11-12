# Contrato de integración · Piquantum ↔ back-office propio

> **Borrador v1.0 para la revisión del grupo** (revisado el 10-nov-2026). Lo que está marcado con 🟡 sigue pendiente de acordar; el resumen está en el [apartado 12](#12-decisiones).
> Prototipo académico: todos los datos de ejemplo son ficticios.

## 1. Propósito y alcance

Este documento fija **qué datos se intercambian** entre la tienda Piquantum (front-office) y el back-office del grupo (`backoffice/` de este repositorio), **en qué formato**, **cómo se validan** y **qué se responde en cada caso**. Es la frontera entre los bloques del grupo: la tienda se compromete a enviar exactamente esto, y los flujos de n8n se comprometen a aceptarlo y a transformarlo al formato de la API del back-office ([`docs/CONTRATOS_API_Y_AGENTE.md`](../docs/CONTRATOS_API_Y_AGENTE.md)).

La tienda **nunca llama al back-office directamente**: solo habla con n8n.

Cualquier cambio se hace por pull request aprobado por los tres y sube `schema_version` ([apartado 11](#11-versionado-y-cambios)).

| Sentido | Qué viaja | Mecanismo |
|---|---|---|
| Tienda → n8n → back-office | Eventos de negocio (`order.created`, `payment.simulated`, `order.status_changed`, `support.requested`) | Webhook HTTPS firmado (push) hacia n8n; n8n llama a la API del back-office |
| Back-office → n8n → tienda | Cambios de estado de pedidos y de stock hechos por el operario en el back-office | Aviso firmado del back-office a n8n; n8n llama a `POST /api/integration/sync` de la tienda con token |

Los eventos `product.viewed`, `cart.item_added`, `cart.item_updated`, `cart.item_removed`, `checkout.started` e `invoice.issued` **no se envían**: no crean nada en el back-office y se quedan en el registro de eventos de la tienda.

## 2. Participantes y direcciones

| Sistema | URL | Configuración |
|---|---|---|
| Tienda (DonDominio) | `https://www.piquantum.onl` | — |
| Webhook de n8n (eventos de la tienda) | `https://n8n.piquantum.onl/webhook/piquantum/eventos` (pruebas: `/webhook-test/…`) | Tienda: `N8N_WEBHOOK_URL` y `N8N_WEBHOOK_SECRET`. n8n: `PIQUANTUM_WEBHOOK_SECRET` (el mismo valor) |
| API de la tienda para n8n | `https://www.piquantum.onl/api/integration/sync` | Tienda: `INTEGRATION_API_TOKEN`. n8n: `PIQUANTUM_SYNC_URL` y `PIQUANTUM_SYNC_TOKEN` (el mismo valor) |
| Back-office (web del operario) | `https://erp.piquantum.onl` | Desde fuera, `/api/*` responde 403 |
| API del back-office | `http://backoffice:8080/api/v1` (solo por la red interna de Docker) | n8n: `ERP_API_URL` y `ERP_API_KEY`, que se envía en `X-Integration-Token` |
| Avisos del back-office a n8n | Webhooks de n8n del escenario 2 | n8n: `ERP_WEBHOOK_SECRET` para verificar la firma |

**Un único webhook para todos los eventos de la tienda:** n8n enruta por `type` con un nodo Switch. La firma, la validación del sobre, la idempotencia y el log se hacen una sola vez.

## 3. Transporte y seguridad (tienda → n8n)

- `POST` con cuerpo JSON en UTF-8. Un evento por petición.
- Cabeceras:

| Cabecera | Ejemplo | Para qué |
|---|---|---|
| `Content-Type` | `application/json` | — |
| `X-Piquantum-Event` | `order.created` | Enrutar sin leer el cuerpo |
| `X-Piquantum-Event-Id` | `9b2f6c1e-4a7d-4c2b-9f1e-2d6a8b0c3e51` | Trazabilidad en los logs del servidor |
| `X-Piquantum-Timestamp` | `1794332525` (segundos Unix) | Evitar que se reenvíe una petición vieja capturada |
| `X-Piquantum-Signature` | `sha256=3f1c…` | Autenticidad e integridad |

- **Firma:** `HMAC-SHA256(secreto, timestamp + "." + cuerpo_exacto)` en hexadecimal, con el prefijo `sha256=`. El secreto solo está en el `.env` de la tienda (`N8N_WEBHOOK_SECRET`) y en el de la VM (`PIQUANTUM_WEBHOOK_SECRET`, que n8n lee con `$env`).
- n8n **rechaza** la petición si la firma no coincide (comparación en tiempo constante) o si el timestamp tiene más de **5 minutos** de diferencia con la hora del servidor.
- La firma se calcula sobre el **cuerpo tal cual llega** (por eso el nodo Webhook de n8n necesita «Raw Body»): si se vuelve a serializar el JSON, la firma deja de coincidir.
- En cada reintento la tienda envía **el mismo cuerpo** (mismo `event_id`) con un **timestamp y una firma nuevos**.

> La firma de los avisos del back-office es distinta: `HMAC-SHA256` **solo del cuerpo**, sin timestamp, y en los reintentos llega el mismo cuerpo byte a byte (apartado 3.6 del contrato del back-office).

## 4. Sobre común

Todos los eventos tienen la misma envoltura. Solo cambia `data`.

```json
{
  "event_id": "9b2f6c1e-4a7d-4c2b-9f1e-2d6a8b0c3e51",
  "type": "order.created",
  "occurred_at": "2026-11-10T18:42:05+01:00",
  "source": "piquantum-web",
  "schema_version": "1.0",
  "data": { }
}
```

| Campo | Tipo | Obligatorio | Origen en la tienda | Regla |
|---|---|---|---|---|
| `event_id` | string (UUID v4) | Sí | Nuevo al crear el envío; se guarda en `webhook_deliveries` | Único. Los reintentos reutilizan **el mismo** |
| `type` | string | Sí | `events.type` | Uno de los 4 tipos del apartado 6 |
| `occurred_at` | string (ISO 8601 con zona) | Sí | `events.occurred_at` | No futura (margen de 5 min) |
| `source` | string | Sí | Fijo | `piquantum-web` |
| `schema_version` | string | Sí | Fijo | n8n acepta la versión mayor `1.x` |
| `data` | objeto | Sí | Según el tipo | Apartado 6 |

## 5. Convenciones

- **Importes:** número JSON redondeado a 2 decimales, en euros, **IVA incluido** (como en la tienda). Ej.: `24.66` o `6.5` (los ceros finales no se escriben), nunca `"24,66"`.
- **Fechas:** ISO 8601 con zona horaria (`2026-11-10T18:42:05+01:00`).
- **Estados de pedido:** los de la tienda, sin traducir: `creado`, `pagado_simulado`, `pendiente_preparacion`, `enviado`, `cancelado`, `incidencia`. El back-office acepta estos mismos valores (apartado 3.5 de su contrato), así que n8n los pasa tal cual.
- **Código de producto (`product_code`)**, calculado **por la tienda**:
  - producto con tamaños: `SKU` + `-` + tamaño sin espacios en mayúsculas → `SAL-002` + `100 ml` → `SAL-002-100ML`;
  - producto sin tamaños: el SKU tal cual → `SAL-004`.
  - Es el `sku` del almacén del back-office (`erp_inventory`). Lo usan las líneas de pedido, los avisos de stock y `POST /api/integration/sync`.
- **Valores nulos:** un campo opcional sin valor se envía como `null`, no se omite.
- **Texto libre** (mensajes de soporte, nombres): se envía tal cual; quien lo muestre es responsable de escaparlo.

## 6. Eventos tienda → n8n

Los tres eventos de pedido (`order.created`, `payment.simulated` y `order.status_changed`) llevan **la foto completa del pedido** (`order`, `customer`, `shipping_address` y `lines`) **tal como queda después del cambio**. Así n8n siempre puede llamar a `POST /api/v1/webhooks/orders` sin consultar nada más, y el back-office decide con su idempotencia (`event_id`) y su tabla de transiciones si crea, avanza, ignora o descarta el evento. No importa en qué orden lleguen.

### 6.1 `order.created`

**Cuándo:** al confirmarse la transacción que crea el pedido en el checkout (justo después de que `EventLogger` registre `order.created`).
**Efecto esperado en el back-office:** cliente creado o actualizado (`erp_customers`), pedido creado en estado `creado` con sus líneas (`erp_orders`, `erp_order_items`) y stock descontado en `erp_inventory`. Respuesta `created`.

| Campo de `data` | Tipo | Oblig. | Origen | Validación en n8n |
|---|---|---|---|---|
| `order.reference` | string | Sí | `orders.reference` | Formato `PQ-AAAAMMDD-XXXXXX` |
| `order.status` | string | Sí | `orders.status` | En `order.created`, debe ser `creado` |
| `order.currency` | string | Sí | Fijo | `EUR` |
| `order.subtotal` | number | Sí | `orders.subtotal` | = suma de `lines[].line_total` (±0,01) |
| `order.discount` | number | Sí | `orders.discount` | ≥ 0 |
| `order.discount_code` | string \| null | Sí | `orders.discount_code` | Si no es null, `discount` > 0 |
| `order.shipping` | number | Sí | `orders.shipping` | ≥ 0 |
| `order.tax` | number | Sí | `orders.tax` | Informativo (IVA contenido); no se valida |
| `order.total` | number | Sí | `orders.total` | = subtotal − discount + shipping (±0,01) y > 0 |
| `customer.id` | integer | Sí | `customers.id` | > 0 |
| `customer.name` | string | Sí | `customers.name` | No vacío |
| `customer.email` | string | Sí | `customers.email` | Formato email |
| `shipping_address.name` | string | Sí | `orders.shipping_name` | No vacío |
| `shipping_address.address` | string | Sí | `orders.shipping_address` | No vacío |
| `shipping_address.city` | string | Sí | `orders.shipping_city` | No vacío |
| `shipping_address.postal_code` | string | Sí | `orders.shipping_postal_code` | 5 dígitos |
| `shipping_address.country` | string | Sí | `orders.shipping_country` | — |
| `lines` | array | Sí | `order_items` | Al menos 1 línea |
| `lines[].product_code` | string | Sí | Calculado (apartado 5) | Debe existir en el almacén del back-office (si no, el back-office responde `ERR_ENTIDAD_NO_ENCONTRADA`) |
| `lines[].sku` | string | Sí | `products.sku` | — |
| `lines[].size` | string \| null | Sí | `order_items.variant_size` | — |
| `lines[].name` | string | Sí | `order_items.product_name` | — |
| `lines[].quantity` | integer | Sí | `order_items.quantity` | ≥ 1 |
| `lines[].unit_price` | number | Sí | `order_items.unit_price` | > 0 |
| `lines[].line_total` | number | Sí | `order_items.line_total` | = quantity × unit_price (±0,01) |

No se envían el teléfono ni el `telegram_chat_id`: no hacen falta para crear el pedido (minimización de datos).

Ejemplos: [`payloads/order.created.valido.json`](payloads/order.created.valido.json) · [`payloads/order.created.incompleto.json`](payloads/order.created.incompleto.json)

### 6.2 `payment.simulated`

**Cuándo:** al resolverse un pago con **Redsys en entorno de pruebas** (notificación o retorno firmado, o cancelación del cliente en la página de Redsys).
**Efecto esperado en el back-office:**
- `approved` → el pedido pasa a `pendiente_preparacion` con pago `pagado_simulado` (`updated`). Si el pago llega antes que `order.created`, el back-office crea el pedido ya pagado (`created`), y cuando llegue el `order.created` lo ignora (`ignored`, porque haría retroceder el estado).
- `declined` → el pedido pasa a `cancelado` (`updated`).

`data` lleva **los mismos bloques que `order.created`** (`order`, `customer`, `shipping_address` y `lines`, con las mismas reglas) y, además, el bloque `payment`:

| Campo de `data` | Tipo | Oblig. | Origen | Validación |
|---|---|---|---|---|
| `order.*`, `customer.*`, `shipping_address.*`, `lines[]` | — | Sí | Igual que en 6.1 | Igual que en 6.1, salvo `order.status` |
| `order.status` | string | Sí | `orders.status` **después** del pago | `pendiente_preparacion` con `approved`; `cancelado` con `declined`; `incidencia` si se cobra un pedido que ya no esperaba pago |
| `payment.reference` | string | Sí | `payments.reference` | — |
| `payment.method` | string | Sí | `payments.method` | Solo `redsys` (ver nota) |
| `payment.status` | string | Sí | `payments.status` | `approved` o `declined` |
| `payment.amount` | number | Sí | `payments.amount` | Con `approved`: = `order.total` (±0,01) |
| `payment.origin` | string | Sí | `events.origin` | `redsys` o `cliente` (canceló en la página de Redsys) |

> **Estado final:** la tienda registra `payment.simulated` *antes* de que `markPaid` o `cancelUnpaid` cambien el estado del pedido. El `data` se monta con el pedido **tal como queda al confirmarse la transacción**, para que `order.status` sea el estado final y no `creado`.

No se envían el código de autorización, la respuesta de Redsys ni los 4 últimos dígitos de la tarjeta.

**Transferencia simulada:** el enunciado exige que los servicios externos usen su sandbox oficial. **La tienda no envía `payment.simulated` de transferencias** (`method: "transfer"`), y en la demo se paga siempre con Redsys.

Ejemplos: [`payloads/payment.simulated.aprobado.json`](payloads/payment.simulated.aprobado.json) · [`payloads/payment.simulated.rechazado.json`](payloads/payment.simulated.rechazado.json)

### 6.3 `order.status_changed`

**Cuándo:** **solo** cuando el cambio de estado se hace desde el panel de administración de la tienda (`origin = "backoffice"` en la tienda; no confundir con el back-office del grupo). Es una **lista blanca**: cualquier otro origen no se envía.

| Origen en la tienda | ¿Se envía? | Por qué |
|---|---|---|
| `backoffice` (panel /admin de la tienda) | **Sí** | Es el único cambio manual que el back-office no conoce por otra vía |
| `redsys` (pago aprobado, rechazado o cancelado; incidencia por cobro tardío) | No | Ya lo cubre `payment.simulated`, que lleva el estado final |
| `payment` (transferencia simulada) | No | No se envían transferencias (apartado 6.2) |
| `integracion` (cambio que llega de n8n por `POST /api/integration/sync`) | No | **Evita bucles:** el cambio viene del back-office, y reenviarlo lo devolvería al back-office y otra vez a n8n |
| Cualquier origen nuevo | No | Hasta que el grupo lo acuerde y se añada a este contrato |

**Efecto esperado en el back-office:** el pedido avanza al nuevo estado si la transición está permitida (`updated`); si ya tenía ese estado, `unchanged`; si la transición no está permitida en el back-office, `ignored`.

`data` lleva **los mismos bloques que `order.created`** (con `order.status` = el estado nuevo) y, además, el bloque `change`:

| Campo de `data` | Tipo | Oblig. | Origen | Validación |
|---|---|---|---|---|
| `order.*`, `customer.*`, `shipping_address.*`, `lines[]` | — | Sí | Igual que en 6.1 | Igual que en 6.1, salvo `order.status` |
| `order.status` | string | Sí | `orders.status` después del cambio | = `change.status` |
| `change.previous_status` | string | Sí | `events.previous_status` | Estado válido |
| `change.status` | string | Sí | `events.status` | Estado válido y transición permitida por `Order::TRANSITIONS` |
| `change.origin` | string | Sí | `events.origin` | Siempre `backoffice` (lista blanca) |

Ejemplo: [`payloads/order.status_changed.json`](payloads/order.status_changed.json)

### 6.4 `support.requested` 🟡

**Cuándo:** al guardarse una solicitud del formulario `/soporte`. Puede enviarla un invitado, sin cuenta.
**Efecto esperado en el back-office:** incidencia creada por `POST /api/v1/webhooks/tickets`, asociada al pedido si existe; después, el agente de IA la analiza (escenario 3).

| Campo de `data` | Tipo | Oblig. | Origen | Validación |
|---|---|---|---|---|
| `ticket.id` | integer | Sí | `support_tickets.id` | > 0 |
| `ticket.subject` | string | Sí | `support_tickets.subject` | 1–150 caracteres |
| `ticket.message` | string | Sí | `support_tickets.message` | 10–2000 caracteres |
| `ticket.status` | string | Sí | `support_tickets.status` | `abierta` |
| `contact.name` | string | Sí | `support_tickets.name` | No vacío |
| `contact.email` | string | Sí | `support_tickets.email` | Formato email |
| `customer_id` | integer \| null | Sí | Cliente con ese email, si existe | `null` si es un invitado |
| `order_reference` | string \| null | Sí | Pedido indicado en el formulario | Si no es null, debe existir |

🟡 **Pendiente de acordar con Álvaro** (decisión 12.B):
- **`ticket_ref`:** el back-office lo exige. Propuesta: lo construye n8n como `"PQ-TCK-" + ticket.id`, sin cambios en la tienda. El ejemplo del back-office usa `INC-…`.
- **`telegram_chat_id`:** el back-office lo guarda **al crear la incidencia** y lo necesita al aprobar la propuesta del agente (`ai.proposal.approved`); sin él, no avisa por Telegram. La tienda no lo guarda en ningún sitio. Opciones:
  1. la tienda lo envía en el evento (nueva columna en `customers` o campo opcional en el formulario de soporte);
  2. n8n lo obtiene por otra vía antes de crear la incidencia;
  3. un `chat_id` fijo para la demo, en una variable de n8n.

Ejemplo: [`payloads/support.requested.json`](payloads/support.requested.json)

## 7. Respuestas de n8n y reintentos

n8n responde siempre con un JSON corto. Si ha llamado al back-office, `result` es el que devuelve el back-office:

```json
{ "result": "created", "event_id": "9b2f6c1e-…", "message": "Pedido PQ-20261110-A7K3QZ integrado en el back-office" }
```

| Situación | HTTP | `result` | ¿La tienda reintenta? | Qué más pasa |
|---|---|---|---|---|
| Procesado por el back-office | 200 | `created`, `updated`, `unchanged` o `ignored` | No | Log OK |
| Ya procesado antes (mismo `event_id`) | 200 | `duplicate` | No | No se toca nada |
| Evento que no se integra a propósito | 200 | `ignored` | No | Log |
| Firma inválida o timestamp caducado | 401 | `unauthorized` | No | Alerta al grupo |
| `type` desconocido o `schema_version` no soportada | 400 | `bad_request` | No | Alerta |
| Faltan campos, tipos incorrectos o total incoherente (validación de n8n) | 422 | `invalid` | No | Log con la lista de errores y alerta |
| El back-office rechaza el evento (`ERR_CAMPOS_OBLIGATORIOS`, `ERR_IMPORTE_INVALIDO`, `ERR_ENTIDAD_NO_ENCONTRADA`, `ERR_STOCK_INSUFICIENTE`) | 422 | `rejected` | No | Se devuelve el `error_code` del back-office |
| Flujo de n8n desactivado o en edición (lo responde n8n, no el flujo) | 404 | — | **Sí** | Se recupera al reactivar el flujo |
| Back-office caído o error inesperado | 503 | `error` | **Sí** | Error Trigger + log |
| Sin respuesta en 5 s | — | — | **Sí** | — |

En los 422 se devuelve la lista de errores, que la tienda guarda en `webhook_deliveries.last_error`:

```json
{ "result": "invalid", "event_id": "…", "errors": ["data.customer.email: obligatorio", "data.lines: al menos 1 línea"] }
```

```json
{ "result": "rejected", "event_id": "…", "error_code": "ERR_STOCK_INSUFICIENTE", "details": { } }
```

**Reintentos de la tienda:** solo con 5xx, 404, 408, 429 o sin respuesta. El 404 es lo que responde n8n cuando el flujo está desactivado o en edición («webhook not registered»): es temporal, así que se reintenta. Después del envío inicial, **hasta 5 reintentos** con espera creciente (1, 2, 5, 10 y 30 min), es decir, 6 envíos como máximo. Después, estado `fallido`. Siempre con el mismo `event_id` y el mismo cuerpo, y con un timestamp y una firma nuevos en cada envío.

**Recuperar entregas fallidas:** `php artisan integracion:reintentar --fallidos` vuelve a poner en cola las entregas `fallido` (por ejemplo, después de corregir el flujo de n8n), con el mismo `event_id` y el mismo cuerpo. Si n8n o el back-office ya las tenían, responden `duplicate` y no se duplica nada.

**Orden de llegada:** no está garantizado (un reintento puede adelantar a otro evento). No hace falta esperar: cada evento de pedido lleva el pedido completo, y el back-office solo deja avanzar el estado (`ignored` si haría retroceder).

## 8. API de la tienda para n8n

Una sola ruta: **`POST /api/integration/sync`**. La usa el flujo del escenario 2 para aplicar en la tienda los cambios que el operario hace en el back-office.

- Base: `https://www.piquantum.onl/api`.
- Autenticación: `Authorization: Bearer <INTEGRATION_API_TOKEN>`. Es un **token fijo** en el `.env` de la tienda, comparado en tiempo constante (`hash_equals`), sin Sanctum. Sin token, con uno incorrecto o si la tienda no tiene token configurado → 401.
- Límite: 60 peticiones/min (`throttle`); por encima → 429.
- Sin CSRF ni sesión (rutas `api` de Laravel).
- **`event_id`:** n8n pasa el `event_id` del aviso del back-office (`evt_bo_…`). El back-office repite el mismo aviso en cada reintento, y la tienda usa ese identificador para no aplicar dos veces el mismo cambio.
  - Se guarda en la tabla `integration_inbox` (índice único) **en la misma transacción** que el cambio: si llegan dos avisos iguales a la vez, solo se aplica uno.
  - Se guardan **todos** los avisos con un `event_id` válido, también los rechazados (404 y 422), con la respuesta que se dio. Sirven de rastro de la gestión de errores.
  - Un `event_id` repetido **repite la respuesta original**: 200 si el cambio se aplicó, y el mismo 404 o 422 si se rechazó. Así n8n nunca da por sincronizado algo que la tienda rechazó.

### Cambio de estado de un pedido (`ORDER_STATUS`)

Petición ([`payloads/sync.estado.json`](payloads/sync.estado.json)):

```json
{ "event_id": "evt_bo_3fa85f6457174562", "change_type": "ORDER_STATUS", "order_ref": "PQ-20261110-A7K3QZ", "new_status": "enviado" }
```

- Se busca el pedido por `reference`.
- Pasa por `OrderService::changeStatus($order, $new, 'integracion')` y respeta `Order::TRANSITIONS`: así se repone el stock al cancelar y se envía el correo de «pedido en camino» al pasar a `enviado`.
- El cambio se registra con `origin = integracion` y **no se reenvía a n8n** (apartado 6.3).

### Cambio de stock (`STOCK`)

Petición ([`payloads/sync.stock.json`](payloads/sync.stock.json)):

```json
{ "event_id": "evt_bo_9c1e2d3f4a5b6c7d", "change_type": "STOCK", "product_code": "SAL-002-100ML", "new_stock": 25 }
```

- `product_code` se resuelve a tamaño (`product_variants`) o a producto (`products`) según el apartado 5.
- Se guarda **el valor absoluto** de `new_stock` (entero ≥ 0), no una diferencia: repetir la llamada no cambia nada.

### Respuestas

| Caso | HTTP | Cuerpo |
|---|---|---|
| Cambio aplicado | 200 | `{"result": "updated", "event_id": "…", …}` (con `previous_status` y `status`, o `previous_stock` y `stock`) |
| El pedido ya tenía ese estado o el stock ya tenía ese valor | 200 | `{"result": "unchanged", "event_id": "…", …}` |
| `event_id` ya procesado | **El código original** (200, 404 o 422) | `{"result": "duplicate", "event_id": "…", "original_result": "updated", "original_response": {…}}` |
| Sin token, token incorrecto o la tienda sin token configurado | 401 | `{"message": "Unauthenticated."}` |
| `order_ref` o `product_code` que no existe (también un SKU con tamaños sin indicar el tamaño) | 404 | `{"result": "not_found", "event_id": "…", "message": "…"}` |
| Faltan campos, `change_type` desconocido, estado que no existe o `new_stock` negativo | 422 | `{"result": "invalid", "event_id": "…", "message": "…", "errors": {"campo": ["…"]}}` |
| Transición no permitida | 422 | `{"result": "invalid_transition", "event_id": "…", "current_status": "cancelado", "requested_status": "enviado", "allowed_next": []}` |
| Demasiadas peticiones | 429 | — |

**Qué hace n8n con la respuesta:** responde 2xx al back-office **solo si la tienda respondió 200** (`updated`, `unchanged`, o `duplicate` de un cambio que se aplicó). En cualquier otro caso responde con error, y el back-office reintenta y, tras 5 intentos, marca la entidad como `ERROR_SINCRONIZACION` para que el operario lo vea.

## 9. Correspondencia con la API del back-office

### Tienda → `POST /api/v1/webhooks/orders`

Para `order.created`, `payment.simulated` y `order.status_changed`, n8n hace la misma transformación:

| Tienda | Back-office | Notas |
|---|---|---|
| sobre `event_id` | `event_id` | Idempotencia del back-office (`duplicate`) |
| sobre `type` | `event_type` | — |
| sobre `occurred_at` | `occurred_at` | — |
| `data.order.reference` | `order_ref` | — |
| `data.order.status` | `status` | Tal cual: el back-office acepta los estados de la tienda |
| `data.customer.name`, `data.customer.email` | `customer.name`, `customer.email` | `customer.id` no se envía |
| `data.shipping_address.*` | `shipping_address` | **Texto:** `"{address}, {postal_code} {city} ({country})"` → `"Calle Ficticia 1, 28001 Madrid (España)"` |
| `data.lines[].product_code` | `items[].sku` | — |
| `data.lines[].name` | `items[].product_name` | — |
| `data.lines[].size` | `items[].variant_size` | `null` en productos sin tamaños |
| `data.lines[].quantity`, `unit_price` | `items[].quantity`, `unit_price` | `sku` y `line_total` de la tienda no se envían |
| `data.order.discount_code` | `discount_code` | `null` si no hay |
| `data.order.discount` | `discount_amount` | Obligatorio si hay `discount_code` |
| `data.order.shipping` | `shipping_amount` | — |
| `data.order.total` | `total_amount` | El back-office comprueba: líneas − `discount_amount` + `shipping_amount` (±0,01) |
| (fijo) | `payment_method` | `"redsys_sandbox"` |
| `data.payment`, `data.change` | — | No se envían; solo sirven para el log y la rama de errores de n8n |

Estados (apartado 3.5 del contrato del back-office):

| `order.status` de la tienda | Estado guardado en el back-office | Estado de pago |
|---|---|---|
| `creado` | `creado` | `pendiente_pago` |
| `pagado_simulado` o `pendiente_preparacion` | `pendiente_preparacion` | `pagado_simulado` |
| `enviado` | `enviado` | `pagado_simulado` |
| `incidencia` | `incidencia` | `pagado_simulado` |
| `cancelado` | `cancelado` | `cancelado` |

### Tienda → `POST /api/v1/webhooks/tickets` 🟡

| Tienda (`support.requested`) | Back-office |
|---|---|
| sobre `event_id` | `event_id` (opcional; `duplicate` si se repite) |
| `"PQ-TCK-" + data.ticket.id` 🟡 | `ticket_ref` |
| `data.order_reference` | `order_ref` (`null` si no hay pedido) |
| `data.contact.name`, `data.contact.email` | `customer_name`, `customer_email` |
| `data.ticket.subject` | `subject` |
| `data.ticket.message` | `description` |
| 🟡 (decisión 12.B) | `telegram_chat_id` (opcional, numérico) |

### Avisos del back-office → `POST /api/integration/sync` de la tienda

| Aviso del back-office | Petición a la tienda |
|---|---|
| sobre `event_id` (`evt_bo_…`) | `event_id` |
| `order.status_changed`: `data.order_ref`, `data.new_status` | `change_type: "ORDER_STATUS"`, `order_ref`, `new_status` |
| `inventory.stock_updated`: `data.sku`, `data.new_stock` | `change_type: "STOCK"`, `product_code`, `new_stock` |
| `ai.proposal.approved` | No se envía a la tienda: n8n manda el mensaje por Telegram (escenario 3) |

## 10. Datos personales

- Solo viajan los datos necesarios para crear el pedido y la incidencia: nombre, email y dirección de envío.
- No viajan contraseñas, teléfonos ni datos de tarjeta. El `telegram_chat_id` está pendiente de la decisión 12.B: si la tienda lo envía, viajará solo en `support.requested`, y solo si el cliente lo ha dado.
- Todos los datos son de clientes de prueba (`@example.com` y cuentas propias del grupo).
- Todo el tráfico que sale de la VM o de la tienda va cifrado (HTTPS) y autenticado (firma o token). La API del back-office solo se usa por la red interna de Docker.

## 11. Versionado y cambios

- `schema_version` sigue `MAYOR.MENOR`:
  - **menor** (1.0 → 1.1): añadir un campo opcional. Los consumidores siguen funcionando;
  - **mayor** (1.x → 2.0): quitar o renombrar campos, cambiar tipos o hacer obligatorio uno opcional. Requiere cambiar los flujos a la vez.
- Todo cambio va por **pull request** con el contrato, los payloads de ejemplo y el código afectado, aprobado por los tres.
- Los payloads de `payloads/` se mantienen siempre válidos para la versión vigente: son los datos de prueba de la entrega.

## 12. Decisiones

### Tomadas (Abel, 9 y 10-nov-2026; pendientes del visto bueno del grupo)

| # | Decisión | Detalle |
|---|---|---|
| 1 | Un webhook para todos los eventos | Enrutado por `type` con un Switch en n8n (apartado 2) |
| 2 | Formato de `product_code` | `SKU-TAMAÑO` sin espacios (`SAL-002-100ML`); el SKU solo si no hay tamaños (apartado 5) |
| 3 | Transferencias simuladas | No se envían; la demo usa Redsys de pruebas (apartado 6.2) |
| 4 | Los eventos de pedido llevan el pedido completo | `order.created`, `payment.simulated` y `order.status_changed`, porque `/webhooks/orders` lo exige (apartado 6) |
| 5 | Qué `order.status_changed` se envían | Lista blanca: solo el origen `backoffice` (panel de la tienda) (apartado 6.3) |
| 6 | Endpoint de vuelta | Solo `POST /api/integration/sync`, con `change_type` `ORDER_STATUS` o `STOCK`; stock en valor absoluto (apartado 8) |
| 7 | La tienda no ofrece endpoints de consulta | El agente usa las herramientas del back-office. `GET /api/catalogo` queda opcional, solo si hace falta cargar en el back-office el stock real actual de la tienda |
| 8 | Autenticación de la API de la tienda | Token fijo (`INTEGRATION_API_TOKEN`) con `hash_equals`, sin Sanctum (apartado 8) |
| 9 | Timestamp en la firma y ventana de 5 min | Sí (apartado 3) |
| 10 | Reintentos de la tienda | Envío inicial + 5 reintentos: 1, 2, 5, 10 y 30 min (apartado 7) |
| 11 | Idempotencia de `POST /api/integration/sync` | El `event_id` se guarda en la misma transacción que el cambio; se guardan también los rechazos, y un duplicado repite la respuesta original (apartado 8) |

### Abiertas

| # | Decisión | Con quién | Propuesta |
|---|---|---|---|
| A | ¿JSON Schema por evento para validar en n8n? | Grupo | Sí, si da tiempo |
| B | `ticket_ref` y `telegram_chat_id` en `support.requested` | Álvaro | Apartado 6.4 |
| C | **Stock del back-office en las cancelaciones** | Antonio | Hoy `/webhooks/orders` descuenta stock al crear el pedido aunque llegue `cancelado`, y no lo repone cuando un pedido pasa a `cancelado`. La tienda sí lo repone, así que tras un pago rechazado el stock de los dos sistemas deja de coincidir. Propuesta: no descontar al crear un pedido `cancelado` y reponer al pasar a `cancelado` |

## 13. Cómo probar a mano

### Eventos de la tienda → n8n

Firmar y enviar un payload de ejemplo desde la terminal (sustituid el secreto por el vuestro, que nunca va al repo):

```bash
SECRET='secreto-de-prueba'
BODY=$(cat payloads/order.created.valido.json)
TS=$(date +%s)
SIG=$(printf '%s.%s' "$TS" "$BODY" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -i https://n8n.piquantum.onl/webhook-test/piquantum/eventos \
  -H 'Content-Type: application/json' \
  -H 'X-Piquantum-Event: order.created' \
  -H "X-Piquantum-Event-Id: $(jq -r .event_id payloads/order.created.valido.json)" \
  -H "X-Piquantum-Timestamp: $TS" \
  -H "X-Piquantum-Signature: sha256=$SIG" \
  --data-binary "$BODY"
```

- `/webhook-test/` es la URL de pruebas de n8n (funciona con el workflow abierto y «Listen for test event»); en producción es `/webhook/`.
- Enviar dos veces el mismo payload debe devolver `duplicate` la segunda vez. Para repetir la prueba desde cero, cambiad el `event_id` y la referencia del pedido.
- `order.created.incompleto.json` debe devolver 422 `invalid` con estos cuatro errores (y no llegar al back-office):
  - `data.customer.email`: obligatorio;
  - `data.lines[0].quantity`: debe ser ≥ 1;
  - `data.lines[0].line_total`: no coincide con quantity × unit_price;
  - `data.order.total`: 15.00 no coincide con subtotal − discount + shipping (17.95).

| Payload (en este orden) | Resultado esperado |
|---|---|
| `order.created.valido.json` | 200 `created`: pedido PQ-20261110-A7K3QZ de Laura Prueba en el back-office, 2 líneas, 24,66 €, stock descontado |
| `order.created.valido.json` (otra vez) | 200 `duplicate` |
| `order.created.incompleto.json` | 422 `invalid` con los 4 errores |
| `payment.simulated.aprobado.json` | 200 `updated`: pedido en `pendiente_preparacion`, pago `pagado_simulado` |
| `order.status_changed.json` | 200 `updated`: pedido en `incidencia` |
| `payment.simulated.rechazado.json` (pedido nuevo) | 200 `created`: pedido PQ-20261110-T5N4RW en `cancelado` (ver decisión 12.C sobre el stock) |
| `support.requested.json` | 200: incidencia creada en el back-office ligada al pedido de Laura; arranca el agente |

Prueba de orden: con una base de datos limpia, enviar primero `payment.simulated.aprobado.json` (200 `created`, ya pagado) y después `order.created.valido.json` (200 `ignored`, porque haría retroceder el estado).

### n8n → `POST /api/integration/sync` de la tienda

```bash
TOKEN='token-de-prueba'
curl -i -X POST https://www.piquantum.onl/api/integration/sync \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  --data-binary @payloads/sync.estado.json
```

| Prueba | Resultado esperado |
|---|---|
| Sin `Authorization` o con un token incorrecto | 401 |
| `sync.estado.json` con el pedido en `pendiente_preparacion` | 200 `updated`; correo de «pedido en camino»; ningún `order.status_changed` hacia n8n |
| `sync.estado.json` otra vez | 200 `duplicate`, sin segundo correo |
| Un aviso rechazado (404 o 422), otra vez | El mismo 404 o 422, con `result: "duplicate"` y `original_result` |
| El mismo cambio con otro `event_id` | 200 `unchanged` |
| `new_status: "enviado"` con el pedido `cancelado` | 422 `invalid_transition` con `allowed_next: []` |
| `sync.stock.json` | 200 `updated`: `SAL-002-100ML` con stock 25 en la tienda |
| `order_ref` o `product_code` inexistente | 404 `not_found` |
