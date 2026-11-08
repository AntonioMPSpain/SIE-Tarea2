# Guía de Integración para los Compañeros (Abel y Álvaro)

> **Autor de este módulo (`backoffice/` y `payloads/`):** Antonio Martínez Pallarés  
> **Estado del Back-Office:** 100% funcional, probado con tests automáticos (`backoffice/tests/verify_backoffice.php`) y listo para conectar con **n8n** y **Telegram**.

---

## Parte 1: Para Abel (VM de Google Cloud + Docker Compose + Escenarios 1 y 2 en n8n)

### 1.1. Despliegue en la VM de Google Cloud

El despliegue (`docker-compose` con Caddy, n8n y el Back-Office) va en la carpeta **`deploy/`** del repositorio, que prepara Abel en la Fase 2. **No uséis un `docker-compose` con puertos publicados sin HTTPS.** Mientras `deploy/` no esté subido, tened en cuenta:

- **Puertos:** el Back-Office y n8n no publican puertos hacia internet; solo Caddy (HTTPS) expone el 443. Dentro de Docker, los nodos `HTTP Request` de n8n llaman a la API por la red interna: `http://backoffice:8080/api/v1/...`.
- **Secretos:** `INTEGRATION_API_KEY`, `WEBHOOK_HMAC_SECRET` y `ERP_ADMIN_PASSWORD_HASH` van en un `.env` del servidor que **no se sube a Git** (plantilla: `backoffice/.env.example`, con valores `cambia-esto`). Sin `INTEGRATION_API_KEY` o `WEBHOOK_HMAC_SECRET`, el Back-Office no arranca y responde `500 ERR_CONFIGURACION`. El hash de la contraseña contiene `$`: con `env_file` se escribe tal cual; si se pone en `environment:` del compose, cada `$` se escribe `$$`.
- **Reintentos hacia n8n:** los avisos que n8n no confirma con 2xx se reintentan con el mismo `event_id`. Hace falta un disparador cada minuto: un cron con `php /app/bin/retry_webhooks.php` o un *Schedule Trigger* de n8n que llame a `POST /api/v1/internal/webhooks/retry` (con token).
- **Catálogo inicial:** se carga desde la tienda con `POST /api/v1/inventory/import` (ejemplo en `payloads/carga_catalogo_tienda.json`). Mientras tanto, el Back-Office arranca con el catálogo del seeder de la Tarea 1 (`SAL-001` … `SAL-009`).
- **Web del Back-Office:** tiene inicio de sesión propio (`ERP_ADMIN_EMAIL` y `ERP_ADMIN_PASSWORD_HASH`).

---

### 1.2. Cómo conectar el Escenario 1 en n8n (Pedido Generado e Integrado)

1. **Trigger Webhook en n8n:** Recibe el evento `order.created` o `payment.simulated` desde Piquantum (tienes el ejemplo exacto en `payloads/escenario1_pedido_integrado.json`).
2. **Nodo IF (Validación en n8n):** Comprueba la firma de la tienda, que `{{$json.order_ref}}` no esté vacío y que `{{$json.total_amount}} > 0`. El Back-Office vuelve a validarlo todo.
3. **Nodo HTTP Request (Rama True y también si quieres que el Back-Office registre el error en `erp_integration_logs`):**
   - **Method:** `POST`
   - **URL:** `http://backoffice:8080/api/v1/webhooks/orders`
   - **Headers:** `X-Integration-Token: <INTEGRATION_API_KEY del .env>` (obligatoria en todas las rutas salvo `/api/v1/health`; solo por cabecera, nunca en la URL).
   - **Body:** JSON con el payload del pedido. Obligatorios: `event_id` (el de la tienda), `order_ref`, `status`, `occurred_at`, `customer.name`, `customer.email`, `items[]` (cada línea con `sku`, `product_name`, `quantity` y `unit_price`), `shipping_amount` y `total_amount`. Si hay `discount_code`, también `discount_amount`. Los opcionales que falten (teléfono, dirección, `variant_size`…) se guardan como `NULL`: nunca se inventan.
   - **¿Qué hace el Back-Office automáticamente?**
     - Rechaza con **422** si falta un obligatorio (`ERR_CAMPOS_OBLIGATORIOS`) o si el total no cuadra: `total = líneas − descuento + envío` (`ERR_IMPORTE_INVALIDO`).
     - Comprueba que **todos** los SKU existen (`ERR_ENTIDAD_NO_ENCONTRADA`) y que hay stock (`ERR_STOCK_INSUFICIENTE`). Si falla una línea, no guarda nada.
     - **Idempotencia:** un `event_id` repetido responde `200` con `"result": "duplicate"` sin tocar nada. Un evento nuevo de un pedido que ya existe solo puede **hacer avanzar** su estado, nunca retrocederlo, y no cambia el total.
     - Crea o actualiza el cliente, descuenta el stock y guarda el log en la pestaña **🛡️ Trazabilidad y Logs**.

---

### 1.3. Cómo funciona el Escenario 2 (Cambio Operativo o Sincronización)

Tienes **dos direcciones** ya programadas:
- **Dirección A (Del Back-Office hacia n8n → eCommerce):**
  Cuando entras en la pestaña **📦 Pedidos** o **🏭 Almacén** del Back-Office y cambias el estado de un pedido a `enviado` (o cambias el stock de un SKU), el Back-Office **primero guarda el cambio** y después envía un `POST` firmado con HMAC (`X-Piquantum-Signature: sha256=...`) a `N8N_WEBHOOK_OPERATIONAL_CHANGE_URL` (`http://n8n:5678/webhook/piquantum-operational-change`). Si n8n no responde 2xx, el cambio queda `PENDIENTE_SINCRONIZACION` y se reintenta con **el mismo cuerpo y el mismo `event_id`**, hasta 5 intentos; después queda `ERROR_SINCRONIZACION`. Usad el `event_id` para no aplicar dos veces el mismo aviso. Formato:
  ```json
  {
    "event_id": "evt_bo_9f8a7b6c5d4e",
    "event_type": "order.status_changed",
    "source": "piquantum.backoffice.erp",
    "occurred_at": "2026-11-08T19:00:00Z",
    "data": {
      "order_ref": "PQ-20261108-A901BC",
      "previous_status": "pendiente_preparacion",
      "new_status": "enviado",
      "priority": "URGENTE",
      "customer_email": "laura@example.com",
      "origin": "backoffice_erp"
    }
  }
  ```
- **Dirección B (De n8n hacia el Back-Office):**
  Si n8n quiere actualizar el estado de un pedido o el stock en el Back-Office, solo tiene que hacer `POST http://backoffice:8080/api/v1/webhooks/operations` usando el JSON de `payloads/escenario2_cambio_operativo.json`. Se aplican las mismas transiciones que `Order::TRANSITIONS` de la tienda: una transición no permitida (p. ej. `cancelado → enviado`) da `422 ERR_TRANSICION_ESTADO`, y el mismo estado da `200` sin cambios.

---

## Parte 2: Para Álvaro (Escenario 3 + Agente de IA Supervisado + Telegram)

El Back-Office ya tiene programadas las **3 Herramientas (Tools)** que exige el **Apartado 6** y el validador de los **7 campos obligatorios de salida estructurada**.

### 2.1. Paso 1 del Workflow 3: Registrar la Incidencia en el Back-Office
Cuando llega un evento `support.requested` (ver `payloads/escenario3_incidencia_postventa.json`), el primer nodo HTTP de n8n llama a:
- `POST http://backoffice:8080/api/v1/webhooks/tickets`
- Esto crea el ticket en estado `EN_REVISION_IA` en el Back-Office. Obligatorios: `ticket_ref`, `customer_name`, `customer_email`, `subject` y `description`.
- **`telegram_chat_id`** es opcional y **numérico** (negativo en grupos). Un bot de Telegram no puede escribir a un `@usuario`: necesita el `chat_id` de alguien que le haya escrito antes. Por eso un `@usuario` se rechaza. Sin `chat_id`, la incidencia se crea igualmente, pero al aprobarla no se notifica por Telegram y queda registrado en el log. Pendiente de acordar con Álvaro de dónde sale (probablemente una columna en la tienda, con las cuentas de prueba del grupo).

### 2.2. Paso 2: Configuración de las Tools en el nodo `AI Agent` de n8n

Conecta estos **3 nodos `HTTP Request Tool`** al nodo **AI Agent** de n8n:

1. **Tool de Consulta 1 — `consultar_pedido_e_historial_cliente`:**
   - **Descripción para el Agente:** *"Consulta en el Back-Office los detalles de un pedido por su referencia (ej. PQ-20261108-A901BC), incluyendo estado de pago, líneas compradas, historial de incidencias previas del cliente y stock disponible de cada producto."*
   - **Método y URL:** `GET http://backoffice:8080/api/v1/agent/orders/{order_ref}`
2. **Tool de Consulta 2 — `consultar_disponibilidad_almacen`:**
   - **Descripción para el Agente:** *"Consulta las unidades disponibles en el almacén y ubicación de pasillo para un SKU de salsa picante (ej. SAL-002-100ML, SAL-007). Códigos: SKU-TAMAÑO sin espacios en mayúsculas, o solo el SKU si el producto no tiene tamaños."*
   - **Método y URL:** `GET http://backoffice:8080/api/v1/agent/inventory/{sku}`
3. **Tool de Acción/Propuesta — `proponer_resolucion_ticket`:**
   - **Descripción para el Agente:** *"Registra en el Back-Office la propuesta estructurada de resolución del ticket en estado pendiente_de_revision para que un supervisor humano la apruebe antes de notificar al cliente por Telegram."*
   - **Método y URL:** `POST http://backoffice:8080/api/v1/agent/proposals`
   - **Body JSON:** El esquema de 7 campos (ejemplo en `payloads/escenario3_salida_estructurada_agente_ia.json`), más `ticket_ref`, `mensaje_propuesto_telegram` y `herramientas_utilizadas`, que también son obligatorios: el Back-Office no inventa ni el mensaje para el cliente ni las herramientas usadas.

### 2.3. System Prompt listo para copiar y pegar en el nodo `AI Agent` de n8n

```text
Eres el Agente Operativo de Postventa de Piquantum (tienda especializada en salsas picantes artesanales).
Tu misión es analizar las incidencias postventa recibidas desde el canal digital y asistir al equipo del Back-Office de forma controlada, trazable y supervisada.

REGLAS OBLIGATORIAS DE ACTUACIÓN:
1. ANTES de proponer cualquier acción, DEBES utilizar la herramienta `consultar_pedido_e_historial_cliente` pasando el `order_ref` afectado, y si el cliente reclama un producto dañado o cambio, consulta su stock con `consultar_disponibilidad_almacen`.
2. LÍMITES ESTRICTOS: Tienes PROHIBIDO ejecutar reembolsos bancarios definitivos, descontar stock en firme o enviar mensajes directos al cliente sin supervisión humana.
3. Toda tu actuación debe quedar en modo propuesta (`estado_final: "pendiente_de_revision"`) y registrarse mediante la herramienta `proponer_resolucion_ticket`.
4. Debes devolver SIEMPRE un objeto JSON estructurado con los siguientes campos exactos:
   - herramientas_utilizadas (lista de las herramientas que has usado)
   - tipo_caso_detectado
   - datos_consultados
   - accion_propuesta
   - mensaje_propuesto_telegram
   - justificacion_breve
   - nivel_confianza_o_prioridad
   - riesgos_o_advertencias
   - estado_final ("pendiente_de_revision")
```

### 2.4. Paso 3: Supervisión Humana (En el Panel del Back-Office o en Telegram)

Una vez que el Agente registra la propuesta, tenéis **dos formas simultáneas** de aprobarla (ambas funcionan):
1. **Desde la web del Back-Office (`/?tab=ai_governance`, con sesión iniciada):** El supervisor ve la tarjeta con los 7 campos del Agente y pulsa el botón verde **`✅ Aprobar y Enviar a Telegram`**. El revisor que queda registrado es el operador con sesión iniciada. El Back-Office guarda la decisión y después envía un Webhook a `N8N_WEBHOOK_HUMAN_REVIEW_URL` (`http://n8n:5678/webhook/piquantum-human-review`) con el `customer_telegram_chat_id` y el `customer_message` aprobado, para que n8n dispare el nodo de Telegram. **`telegram_notified` solo pasa a 1 cuando n8n responde 2xx**; hasta entonces la incidencia queda `APROBADA_PENDIENTE_NOTIFICACION` y se reintenta como en el Escenario 2. Configurad el Webhook de n8n para que responda **después** de enviar el mensaje de Telegram (*Respond: When Last Node Finishes*), así el 2xx confirma el envío.
2. **Desde Telegram hacia el Back-Office:** Si el supervisor aprueba desde un botón en Telegram, n8n hace `POST http://backoffice:8080/api/v1/agent/reviews` pasando `{"audit_id": 1, "decision": "aprobado", "reviewed_by": "Álvaro Acedo (Telegram Supervisor)"}`. `decision` (`aprobado` | `rechazado`) y `reviewed_by` son obligatorios: sin ellos se responde `422` y **nunca se aprueba por defecto**.
