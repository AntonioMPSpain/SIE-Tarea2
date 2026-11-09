# Contrato de integración · Piquantum ↔ back-office (Odoo)

> **Borrador v1.0 para la reunión del grupo.** Las decisiones marcadas con 🟡 están pendientes de acordar (resumen en el [apartado 12](#12-decisiones-pendientes-para-la-reunión)).
> Prototipo académico: todos los datos de ejemplo son ficticios.

## 1. Propósito y alcance

Este documento fija **qué datos se intercambian** entre la tienda Piquantum (front-office) y el back-office (Odoo), **en qué formato**, **cómo se validan** y **qué se responde en cada caso**. Es la frontera entre los bloques del grupo: la tienda (A) se compromete a enviar exactamente esto, y los flujos de n8n (B y C) se comprometen a aceptarlo.

Cualquier cambio se hace por pull request aprobado por los tres y sube `schema_version` ([apartado 11](#11-versionado-y-cambios)).

| Sentido | Qué viaja | Mecanismo |
|---|---|---|
| Tienda → n8n | Eventos de negocio (`order.created`, `payment.simulated`, `order.status_changed`, `support.requested`) | Webhook HTTPS firmado (push) |
| n8n → tienda | Stock de Odoo, cambio de estado de pedidos | API REST de la tienda con token |
| n8n / agente → tienda | Consultas: catálogo, pedidos, clientes, eventos | API REST de la tienda con token |

Los eventos `product.viewed`, `cart.item_added`, `cart.item_updated`, `cart.item_removed`, `checkout.started` e `invoice.issued` **no se envían por webhook**: no crean nada en el back-office. Se leen en bloque con `GET /api/eventos` para el cuadro de mando.

## 2. Participantes y direcciones

| Sistema | URL | Configuración |
|---|---|---|
| Tienda (DonDominio) | `https://www.piquantum.onl` | — |
| Webhook de n8n | `https://n8n.piquantum.onl/webhook/piquantum/eventos` | En la tienda: `N8N_WEBHOOK_URL` |
| API de la tienda | `https://www.piquantum.onl/api` | En n8n: variable `TIENDA_URL` |
| Odoo | `https://odoo.piquantum.onl` (n8n lo llama por red interna: `http://odoo:8069/jsonrpc`) | Credencial de n8n |

🟡 **Un único webhook para todos los eventos** (n8n enruta por `type` con un nodo Switch) o **uno por evento**. Propuesta: uno solo, porque la firma, la validación del sobre, la idempotencia y el log se hacen una vez.

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

- **Firma:** `HMAC-SHA256(secreto, timestamp + "." + cuerpo_exacto)` en hexadecimal, con el prefijo `sha256=`. El secreto (`N8N_WEBHOOK_SECRET`) solo está en el `.env` de la tienda y en las credenciales de n8n.
- n8n **rechaza** la petición si la firma no coincide (comparación en tiempo constante) o si el timestamp tiene más de **5 minutos** de diferencia con la hora del servidor.
- La firma se calcula sobre el **cuerpo tal cual llega** (por eso el nodo Webhook de n8n necesita «Raw Body»): si se vuelve a serializar el JSON, la firma deja de coincidir.

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

- **Importes:** número JSON con 2 decimales, en euros, **IVA incluido** (como en la tienda). Ej.: `24.66`, nunca `"24,66"`.
- **Fechas:** ISO 8601 con zona horaria (`2026-11-10T18:42:05+01:00`).
- **Estados de pedido:** los de la tienda, sin traducir: `creado`, `pagado_simulado`, `pendiente_preparacion`, `enviado`, `cancelado`, `incidencia`. n8n los traduce a Odoo ([apartado 9](#9-correspondencia-con-odoo)).
- **Código de producto para Odoo (`product_code`)**, calculado **por la tienda**:
  - producto con tamaños: `SKU` + `-` + tamaño sin espacios en mayúsculas → `SAL-002` + `100 ml` → `SAL-002-100ML`;
  - producto sin tamaños: el SKU tal cual → `SAL-004`.
  - Es el `default_code` (Referencia interna) del producto en Odoo. Lo usan `order.created`, la carga inicial y `PUT /api/stock`.
- **Valores nulos:** un campo opcional sin valor se envía como `null`, no se omite.
- **Texto libre** (mensajes de soporte, nombres): se envía tal cual; quien lo muestre es responsable de escaparlo.

## 6. Eventos tienda → n8n

### 6.1 `order.created`

**Cuándo:** al confirmarse la transacción que crea el pedido en el checkout (justo después de que `EventLogger` registre `order.created`).
**Efecto esperado:** cliente creado o actualizado (`res.partner`) y pedido de venta en estado presupuesto (`sale.order`, `draft`) con sus líneas.

| Campo de `data` | Tipo | Oblig. | Origen | Validación en n8n |
|---|---|---|---|---|
| `order.reference` | string | Sí | `orders.reference` | Formato `PQ-AAAAMMDD-XXXXXX` |
| `order.status` | string | Sí | `orders.status` | Debe ser `creado` |
| `order.currency` | string | Sí | Fijo | `EUR` |
| `order.subtotal` | number | Sí | `orders.subtotal` | = suma de `lines[].line_total` (±0,01) |
| `order.discount` | number | Sí | `orders.discount` | ≥ 0 |
| `order.discount_code` | string \| null | Sí | `orders.discount_code` | — |
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
| `lines[].product_code` | string | Sí | Calculado (apartado 5) | Debe existir en Odoo |
| `lines[].sku` | string | Sí | `products.sku` | — |
| `lines[].size` | string \| null | Sí | `order_items.variant_size` | — |
| `lines[].name` | string | Sí | `order_items.product_name` | — |
| `lines[].quantity` | integer | Sí | `order_items.quantity` | ≥ 1 |
| `lines[].unit_price` | number | Sí | `order_items.unit_price` | > 0 |
| `lines[].line_total` | number | Sí | `order_items.line_total` | = quantity × unit_price (±0,01) |

No se envían el teléfono, el `telegram_chat_id` ni el método de pago: no hacen falta para crear el pedido (minimización de datos).

Ejemplos: [`payloads/order.created.valido.json`](payloads/order.created.valido.json) · [`payloads/order.created.incompleto.json`](payloads/order.created.incompleto.json)

### 6.2 `payment.simulated`

**Cuándo:** al resolverse un pago con **Redsys en entorno de pruebas** (notificación o retorno firmado), en la misma transacción que actualiza el pedido.
**Efecto esperado:** `approved` → confirmar el pedido en Odoo (`action_confirm`: reserva stock y crea el albarán). `declined` → cancelarlo (`action_cancel`).

| Campo de `data` | Tipo | Oblig. | Origen | Validación |
|---|---|---|---|---|
| `order_reference` | string | Sí | `orders.reference` | El pedido debe existir en Odoo (si no, 409) |
| `payment.reference` | string | Sí | `payments.reference` | — |
| `payment.method` | string | Sí | `payments.method` | Solo `card` (ver nota) |
| `payment.gateway` | string | Sí | Fijo | `redsys-test` |
| `payment.status` | string | Sí | `payments.status` | `approved` o `declined` |
| `payment.amount` | number | Sí | `payments.amount` | Con `approved`: = total del pedido en Odoo |
| `payment.origin` | string | Sí | `events.origin` | `redsys` o `cliente` (canceló en la página de Redsys) |
| `order_status` | string | Sí | `orders.status` tras el pago | — |

No se envían el código de autorización, la respuesta de Redsys ni los 4 últimos dígitos de la tarjeta.

🟡 **Transferencia simulada:** el enunciado exige que los servicios externos usen su sandbox oficial, nunca simulaciones propias. Propuesta: **la tienda no envía `payment.simulated` de transferencias** (y en la demo se paga siempre con Redsys). Alternativa: enviarlos con `method: "transfer"` y que n8n los registre como «ignorado».

Ejemplos: [`payloads/payment.simulated.aprobado.json`](payloads/payment.simulated.aprobado.json) · [`payloads/payment.simulated.rechazado.json`](payloads/payment.simulated.rechazado.json)

### 6.3 `order.status_changed`

**Cuándo:** cuando cambia el estado de un pedido **por una causa de la tienda** (cancelación desde el /admin, paso a incidencia…).
**Efecto esperado:** `cancelado` → `action_cancel` en Odoo; `incidencia` → nota y etiqueta en el pedido de Odoo; resto → solo se registra.

| Campo de `data` | Tipo | Oblig. | Origen | Validación |
|---|---|---|---|---|
| `order_reference` | string | Sí | `orders.reference` | El pedido debe existir en Odoo |
| `previous_status` | string | Sí | `events.previous_status` | Estado válido |
| `status` | string | Sí | `events.status` | Estado válido y transición permitida por `Order::TRANSITIONS` |
| `origin` | string | Sí | `events.origin` | `backoffice`, `payment`, `redsys`, `cliente` |

> **Evitar bucles:** cuando el cambio lo provoca el propio back-office (`PATCH /api/pedidos/{ref}/estado`, `origin = odoo`), **la tienda no envía** `order.status_changed`. Si no, Odoo marcaría «enviado» → la tienda avisaría a n8n → n8n volvería a tocar Odoo.
>
> Los cambios que ya cubre `payment.simulated` (pagado, cancelado por pago rechazado) tampoco se reenvían aquí.

Ejemplo: [`payloads/order.status_changed.json`](payloads/order.status_changed.json)

### 6.4 `support.requested`

**Cuándo:** al guardarse una solicitud del formulario `/soporte`. Puede enviarla un invitado, sin cuenta.
**Efecto esperado:** incidencia en Odoo (`crm.lead`) asociada al cliente y al pedido si existen; después, el agente la analiza (Escenario 3).

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

El agente **no recibe más datos en el evento**: si necesita el pedido, el historial o el `telegram_chat_id`, los pide con sus herramientas (apartado 8).

Ejemplo: [`payloads/support.requested.json`](payloads/support.requested.json)

## 7. Respuestas de n8n y reintentos

n8n responde siempre con un JSON corto:

```json
{ "result": "ok", "event_id": "9b2f6c1e-…", "message": "Pedido creado en Odoo", "odoo_model": "sale.order", "odoo_id": 42 }
```

| Situación | HTTP | `result` | ¿La tienda reintenta? | Qué más pasa |
|---|---|---|---|---|
| Procesado correctamente | 200 | `ok` | No | Log OK |
| Ya procesado antes (mismo `event_id`) | 200 | `duplicate` | No | Log «duplicado»; no se toca Odoo |
| Evento ignorado a propósito (p. ej. transferencia) | 200 | `ignored` | No | Log |
| Firma inválida o timestamp caducado | 401 | `unauthorized` | No | Alerta al staff |
| `type` desconocido o `schema_version` no soportada | 400 | `bad_request` | No | Alerta |
| Faltan campos, tipos incorrectos, total incoherente | 422 | `invalid` | No | Log con la lista de errores y alerta |
| El pedido aún no existe en Odoo (llegó el pago antes que el pedido) | 409 | `not_ready` | **Sí** | — |
| Odoo caído o error inesperado | 503 | `error` | **Sí** | Error Trigger + log |
| Sin respuesta en 5 s | — | — | **Sí** | — |

En el 422 se devuelve la lista de errores, que la tienda guarda en `webhook_deliveries.last_error`:

```json
{ "result": "invalid", "event_id": "…", "errors": ["data.customer.email: obligatorio", "data.lines: al menos 1 línea"] }
```

**Reintentos de la tienda:** hasta 5 intentos con espera creciente (1, 2, 5, 10 y 30 min). Después, estado `fallido` y aviso en el /admin. Siempre con el mismo `event_id`.

**Orden de llegada:** no está garantizado (un reintento puede adelantar a otro evento). Por eso `payment.simulated` responde 409 si su pedido aún no está en Odoo, y la tienda lo vuelve a intentar.

## 8. API de la tienda para n8n y el agente

- Base: `https://www.piquantum.onl/api`
- Autenticación: `Authorization: Bearer <token>` (token de Sanctum del usuario técnico `n8n`). Sin token o con uno inválido → 401.
- Respuestas en JSON; errores con `{ "message": "...", "errors": {...} }` (formato estándar de Laravel). Límite: 60 peticiones/min.

| Método y ruta | Lo usa | Para qué |
|---|---|---|
| `GET /catalogo` | B (carga inicial) | Productos y tamaños con `product_code`, nombre, precio y stock |
| `PUT /stock` | B (Escenario 2) | Actualizar el stock con el de Odoo |
| `PATCH /pedidos/{ref}/estado` | C (vuelta de «enviado») | Cambiar el estado de un pedido desde el back-office |
| `GET /pedidos/{ref}` | C (agente: `consultar_pedido`) | Pedido con líneas, estado y `telegram_chat_id` del cliente |
| `GET /clientes/{id}/pedidos` | C (agente) | Historial de pedidos del cliente |
| `GET /eventos?desde=&tipo=` | A (cuadro de mando), C (agente) | Eventos de la tienda en bloque |

### `PUT /api/stock`

Petición ([`payloads/stock.put.json`](payloads/stock.put.json)):

```json
{
  "source": "odoo",
  "synced_at": "2026-11-10T18:45:00+01:00",
  "items": [
    { "product_code": "SAL-002-100ML", "free_qty": 18 },
    { "product_code": "SAL-004", "free_qty": 40 }
  ]
}
```

- `free_qty`: cantidad libre en Odoo (entero ≥ 0).
- La tienda guarda en cada producto o tamaño: **`free_qty` − unidades de pedidos cuyo `order.created` siga pendiente de enviar** (nunca menos de 0).
- Respuesta 200: `{ "updated": 2, "unknown_codes": [] }`. Los códigos que la tienda no conoce se devuelven en `unknown_codes`, no dan error.

### `PATCH /api/pedidos/{ref}/estado`

Petición ([`payloads/pedido.estado.patch.json`](payloads/pedido.estado.patch.json)):

```json
{ "status": "enviado", "origin": "odoo", "odoo_picking": "WH/OUT/00012", "occurred_at": "2026-11-11T10:05:00+01:00" }
```

- Pasa por `OrderService` y respeta `Order::TRANSITIONS`: una transición no permitida → 422.
- Registra `order.status_changed` con `origin = odoo` y **no lo reenvía a n8n** (apartado 6.3).
- Con `enviado`, la tienda manda su correo de «pedido en camino».
- Idempotente: si el pedido ya está en ese estado → 200 sin cambios.

## 9. Correspondencia con Odoo

| Piquantum | Odoo | Cómo se enlaza |
|---|---|---|
| `customer.id` | `res.partner` | `res.partner.ref` = `"PQ-CLI-" + id` |
| `customer.email`, `name` | `res.partner.email`, `name` | Se actualizan si cambian |
| `shipping_address` | `res.partner` hijo de tipo `delivery` | Dirección de entrega del pedido |
| `order.reference` | `sale.order.client_order_ref` | Búsqueda del pedido en los eventos siguientes |
| `lines[].product_code` | `product.product.default_code` | Debe existir (lo crea la carga inicial) |
| `lines[].quantity`, `unit_price` | `sale.order.line.product_uom_qty`, `price_unit` | El precio de la tienda manda (IVA incluido) |
| `order.discount` 🟡 | Línea de descuento o `discount` en las líneas | Pendiente de decidir |
| `order.shipping` 🟡 | Línea con el producto de servicio «Envío» | Pendiente de decidir |
| `payment.simulated` approved / declined | `action_confirm` / `action_cancel` | — |
| `ticket` | `crm.lead` (etiqueta «Soporte web») | `crm.lead.ref` o descripción con `"PQ-TCK-" + id` |

Estados:

| Tienda | Odoo |
|---|---|
| `creado` | `sale.order` en `draft` (presupuesto) |
| `pagado_simulado` / `pendiente_preparacion` | `sale.order` en `sale` + albarán en `assigned` |
| `enviado` | Albarán en `done` |
| `cancelado` | `sale.order` en `cancel` |
| `incidencia` | Pedido con etiqueta «Incidencia» y nota |

## 10. Datos personales

- Solo viajan los datos necesarios para crear el pedido y la incidencia: nombre, email y dirección de envío.
- No viajan contraseñas, teléfonos, datos de tarjeta ni el `telegram_chat_id` (este se consulta por API solo cuando el agente propone un mensaje y un humano lo aprueba).
- Todos los datos son de clientes de prueba (`@example.com` y cuentas propias del grupo).
- Todo el tráfico va cifrado (HTTPS) y autenticado (firma o token).

## 11. Versionado y cambios

- `schema_version` sigue `MAYOR.MENOR`:
  - **menor** (1.0 → 1.1): añadir un campo opcional. Los consumidores siguen funcionando;
  - **mayor** (1.x → 2.0): quitar o renombrar campos, cambiar tipos o hacer obligatorio uno opcional. Requiere cambiar los flujos a la vez.
- Todo cambio va por **pull request** con el contrato, los payloads de ejemplo y el código afectado, aprobado por los tres.
- Los payloads de `payloads/` se mantienen siempre válidos para la versión vigente: son los datos de prueba de la entrega.

## 12. Decisiones pendientes para la reunión

| # | Decisión | Propuesta |
|---|---|---|
| 1 | Un webhook para todos los eventos o uno por evento | Uno solo, enrutado por `type` |
| 2 | Formato de `product_code` | `SKU-TAMAÑO` sin espacios (`SAL-002-100ML`); SKU solo si no hay tamaños |
| 3 | Pagos por transferencia simulada | No se envían; la demo usa Redsys |
| 4 | Descuento y envío en Odoo | Envío como línea de servicio «Envío»; descuento como línea negativa «Descuento BIENVENIDA10» |
| 5 | Tickets de invitados sin cliente en Odoo | Crear `res.partner` con el email del formulario |
| 6 | ¿Incluir `X-Piquantum-Timestamp` y la ventana de 5 min? | Sí: es poco código y evita reenvíos |
| 7 | Reintentos: número y esperas | 5 intentos: 1, 2, 5, 10, 30 min |
| 8 | ¿JSON Schema por evento para validar en n8n? | Sí, si da tiempo (suma en la rúbrica) |

## 13. Cómo probar a mano

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
- Enviar dos veces el mismo payload debe devolver `duplicate` la segunda vez. Para repetir la prueba, cambiad el `event_id`.
- `order.created.incompleto.json` debe devolver 422 con estos cuatro errores (y no crear nada en Odoo):
  - `data.customer.email`: obligatorio;
  - `data.lines[0].quantity`: debe ser ≥ 1;
  - `data.lines[0].line_total`: no coincide con quantity × unit_price;
  - `data.order.total`: 15.00 no coincide con subtotal − discount + shipping (17.95).

| Payload | Resultado esperado |
|---|---|
| `order.created.valido.json` | 200 `ok`: cliente Laura Prueba y presupuesto con 2 líneas (24,66 €) en Odoo |
| `order.created.incompleto.json` | 422 `invalid` con los 4 errores |
| `payment.simulated.aprobado.json` (después del válido) | 200 `ok`: pedido confirmado, stock reservado, albarán creado |
| `payment.simulated.aprobado.json` (sin enviar antes el pedido) | 409 `not_ready` |
| `payment.simulated.rechazado.json` | 409 si el pedido no existe en Odoo; si existe, 200 y pedido cancelado |
| `order.status_changed.json` | 200 `ok`: etiqueta «Incidencia» y nota en el pedido de Odoo |
| `support.requested.json` | 200 `ok`: incidencia en el CRM ligada a Laura y a su pedido; arranca el agente |
| `stock.put.json` (contra la API de la tienda) | 200 con `updated: 5` |
| `pedido.estado.patch.json` (contra la API de la tienda) | 200: pedido «enviado» y correo al cliente |
