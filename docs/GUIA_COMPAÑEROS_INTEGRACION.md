# Guía de Integración para los Compañeros (Abel y Álvaro)

> **Autor de este módulo (`backoffice/` y `payloads/`):** Antonio Martínez Pallarés  
> **Estado del Back-Office:** 100% funcional, probado con tests automáticos (`backoffice/tests/verify_backoffice.php`) y listo para conectar con **n8n** y **Telegram**.

---

## Parte 1: Para Abel (VM de Google Cloud + Docker Compose + Escenarios 1 y 2 en n8n)

### 1.1. Cómo levantar este Back-Office junto a n8n en la VM de Google Cloud

La carpeta `backoffice/` ya lleva su propio `Dockerfile` y su base de datos SQLite autoinicializable (o MySQL si quieres pasarle variables). En el `docker-compose.yml` de tu VM de Google Cloud solo tienes que añadir el servicio `backoffice` en la misma red que `n8n`:

```yaml
version: '3.8'

services:
  n8n:
    image: n8nio/n8n:latest
    container_name: piquantum_n8n
    restart: unless-stopped
    ports:
      - "5678:5678"
    environment:
      - N8N_HOST=${N8N_HOST:-localhost}
      - N8N_PORT=5678
      - N8N_PROTOCOL=${N8N_PROTOCOL:-http}
      - WEBHOOK_URL=${N8N_WEBHOOK_PUBLIC_URL:-http://localhost:5678/}
      - GENERIC_TIMEZONE=Europe/Madrid
    volumes:
      - n8n_data:/home/node/.n8n
    networks:
      - piquantum_net

  backoffice:
    build:
      context: ./backoffice
      dockerfile: Dockerfile
    container_name: piquantum_backoffice
    restart: unless-stopped
    ports:
      - "8080:8080"
    environment:
      - APP_ENV=production
      - INTEGRATION_API_KEY=pq_bo_academic_token_2026_test
      - WEBHOOK_HMAC_SECRET=pq_hmac_academic_secret_2026
      # Cuando el operador cambie un estado en el Back-Office (Escenario 2) o apruebe una acción IA (Escenario 3),
      # el Back-Office llamará automáticamente a estos dos webhooks de tu n8n:
      - N8N_WEBHOOK_OPERATIONAL_CHANGE_URL=http://n8n:5678/webhook/piquantum-operational-change
      - N8N_WEBHOOK_HUMAN_REVIEW_URL=http://n8n:5678/webhook/piquantum-human-review
    volumes:
      - backoffice_db:/app/storage/database
    networks:
      - piquantum_net

volumes:
  n8n_data:
  backoffice_db:

networks:
  piquantum_net:
    driver: bridge
```

> **Truco importante dentro de Docker:** Como `n8n` y `backoffice` están en la red `piquantum_net`, desde dentro de los nodos `HTTP Request` de n8n puedes llamar directamente a `http://backoffice:8080/api/v1/...` sin salir a internet. Y desde el navegador podéis entrar por `http://<IP_VM_GCP>:8080` (o `https://erp.piquantum.onl`).

---

### 1.2. Cómo conectar el Escenario 1 en n8n (Pedido Generado e Integrado)

1. **Trigger Webhook en n8n:** Recibe el evento `order.created` o `payment.simulated` desde Piquantum (tienes el ejemplo exacto en `payloads/escenario1_pedido_integrado.json`).
2. **Nodo IF (Validación en n8n):** Comprueba que `{{$json.order_ref}}` no esté vacío y `{{$json.total_amount}} > 0`.
3. **Nodo HTTP Request (Rama True y también si quieres que el Back-Office registre el error en `erp_integration_logs`):**
   - **Method:** `POST`
   - **URL:** `http://backoffice:8080/api/v1/webhooks/orders`
   - **Headers:** `X-Integration-Token: pq_bo_academic_token_2026_test`
   - **Body:** JSON con el payload del pedido.
   - **¿Qué hace el Back-Office automáticamente?** Valida todos los campos obligatorios, crea/actualiza al cliente, aplica **idempotencia** por `order_ref` (si se envía dos veces no duplica líneas), descuenta el stock de las salsas en el almacén y guarda el log en la pestaña **🛡️ Trazabilidad y Logs**.

---

### 1.3. Cómo funciona el Escenario 2 (Cambio Operativo o Sincronización)

Tienes **dos direcciones** ya programadas:
- **Dirección A (Del Back-Office hacia n8n → eCommerce):**
  Cuando entras en la pestaña **📦 Pedidos** o **🏭 Almacén** del Back-Office y cambias el estado de un pedido a `enviado` (o cambias el stock de un SKU), el Back-Office envía automáticamente un `POST` firmado con HMAC a `N8N_WEBHOOK_OPERATIONAL_CHANGE_URL` (`http://n8n:5678/webhook/piquantum-operational-change`) con este formato:
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
  Si n8n quiere actualizar el estado de un pedido o el stock en el Back-Office, solo tiene que hacer `POST http://backoffice:8080/api/v1/webhooks/operations` usando el JSON de `payloads/escenario2_cambio_operativo.json`.

---

## Parte 2: Para Álvaro (Escenario 3 + Agente de IA Supervisado + Telegram)

El Back-Office ya tiene programadas las **3 Herramientas (Tools)** que exige el **Apartado 6** y el validador de los **7 campos obligatorios de salida estructurada**.

### 2.1. Paso 1 del Workflow 3: Registrar la Incidencia en el Back-Office
Cuando llega un evento `support.requested` (ver `payloads/escenario3_incidencia_postventa.json`), el primer nodo HTTP de n8n llama a:
- `POST http://backoffice:8080/api/v1/webhooks/tickets`
- Esto crea el ticket en estado `EN_REVISION_IA` en el Back-Office.

### 2.2. Paso 2: Configuración de las Tools en el nodo `AI Agent` de n8n

Conecta estos **3 nodos `HTTP Request Tool`** al nodo **AI Agent** de n8n:

1. **Tool de Consulta 1 — `consultar_pedido_e_historial_cliente`:**
   - **Descripción para el Agente:** *"Consulta en el Back-Office los detalles de un pedido por su referencia (ej. PQ-20261108-A901BC), incluyendo estado de pago, líneas compradas, historial de incidencias previas del cliente y stock disponible de cada producto."*
   - **Método y URL:** `GET http://backoffice:8080/api/v1/agent/orders/{order_ref}`
2. **Tool de Consulta 2 — `consultar_disponibilidad_almacen`:**
   - **Descripción para el Agente:** *"Consulta las unidades disponibles en el almacén y ubicación de pasillo para un SKU de salsa picante (ej. PQ-HAB-200, PQ-GHO-100)."*
   - **Método y URL:** `GET http://backoffice:8080/api/v1/agent/inventory/{sku}`
3. **Tool de Acción/Propuesta — `proponer_resolucion_ticket`:**
   - **Descripción para el Agente:** *"Registra en el Back-Office la propuesta estructurada de resolución del ticket en estado pendiente_de_revision para que un supervisor humano la apruebe antes de notificar al cliente por Telegram."*
   - **Método y URL:** `POST http://backoffice:8080/api/v1/agent/proposals`
   - **Body JSON:** El esquema de 7 campos (ejemplo en `payloads/escenario3_salida_estructurada_agente_ia.json`).

### 2.3. System Prompt listo para copiar y pegar en el nodo `AI Agent` de n8n

```text
Eres el Agente Operativo de Postventa de Piquantum (tienda especializada en salsas picantes artesanales).
Tu misión es analizar las incidencias postventa recibidas desde el canal digital y asistir al equipo del Back-Office de forma controlada, trazable y supervisada.

REGLAS OBLIGATORIAS DE ACTUACIÓN:
1. ANTES de proponer cualquier acción, DEBES utilizar la herramienta `consultar_pedido_e_historial_cliente` pasando el `order_ref` afectado, y si el cliente reclama un producto dañado o cambio, consulta su stock con `consultar_disponibilidad_almacen`.
2. LÍMITES ESTRICTOS: Tienes PROHIBIDO ejecutar reembolsos bancarios definitivos, descontar stock en firme o enviar mensajes directos al cliente sin supervisión humana.
3. Toda tu actuación debe quedar en modo propuesta (`estado_final: "pendiente_de_revision"`) y registrarse mediante la herramienta `proponer_resolucion_ticket`.
4. Debes devolver SIEMPRE un objeto JSON estructurado con los siguientes campos exactos:
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
1. **Desde la web del Back-Office (`/?tab=ai_governance`):** El supervisor ve la tarjeta con los 7 campos del Agente y pulsa el botón verde **`✅ Aprobar y Enviar a Telegram`**. Al pulsarlo, el Back-Office envía un Webhook automático a `N8N_WEBHOOK_HUMAN_REVIEW_URL` (`http://n8n:5678/webhook/piquantum-human-review`) con el `customer_telegram_chat_id` y el `customer_message` aprobado para que n8n dispare el nodo de Telegram al cliente.
2. **Desde Telegram hacia el Back-Office:** Si el supervisor aprueba desde un botón en Telegram, n8n hace `POST http://backoffice:8080/api/v1/agent/reviews` pasando `{"audit_id": 1, "decision": "aprobado", "reviewed_by": "Álvaro Acedo (Telegram Supervisor)"}` y el estado queda actualizado y auditado en el Back-Office.
