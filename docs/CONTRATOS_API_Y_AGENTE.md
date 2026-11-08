# Especificación de Contratos de Integración, Gestión de Errores y Métricas (Memoria Tarea 2)

Este documento detalla los contratos técnicos implementados en el **Back-Office de Piquantum** (`backoffice/`), sirviendo tanto de referencia técnica para el equipo como de contenido directo para los apartados **3, 4, 5, 6, 7 y 9** de la Memoria de la Tarea 2.

---

## 1. Seguridad y Cabeceras Comunes

- **Autenticación API (`Back-Office`):**
  - Cabecera HTTP: `X-Integration-Token: pq_bo_academic_token_2026_test` (configurable en `.env` como `INTEGRATION_API_KEY`).
  - Si se proporciona un token inválido, el Back-Office rechaza la petición con `HTTP 401 Unauthorized` y código `ERR_AUTH_TOKEN_INVALIDO`.
- **Firma de Webhooks Salientes (`Back-Office -> n8n`):**
  - Cabecera HTTP: `X-Piquantum-Signature: sha256=<HMAC-SHA256>` calculada sobre el cuerpo JSON con `WEBHOOK_HMAC_SECRET`.

---

## 2. Catálogo de Validaciones y Códigos de Error (`erp_integration_logs`)

Cada petición recibida por el Back-Office se somete a validación sintáctica y semántica antes de tocar las tablas de negocio. Tanto los aciertos (`SUCCESS`) como los rechazos (`ERROR`) se persisten en `erp_integration_logs` y alimentan el gráfico de **Errores por Tipo** del Cuadro de Mando:

| Código de Error | HTTP Status | Condición de Disparo | Comportamiento del Sistema |
|---|---|---|---|
| `ERR_AUTH_TOKEN_INVALIDO` | `401` | Cabecera `X-Integration-Token` no coincide con el secreto configurado. | Rechazo inmediato antes de procesar el payload. |
| `ERR_CAMPOS_OBLIGATORIOS` | `422` | Falta `order_ref`, `customer.email` (o formato inválido), `customer.name`, `items[]` vacío, o en tickets falta `subject` / `description`. | Se aborta la transacción y se registra en `erp_integration_logs` detallando qué campos faltan. |
| `ERR_IMPORTE_INVALIDO` | `422` | El campo `total_amount` del pedido es `<= 0`. | Rechazo del pedido y registro en trazabilidad. |
| `ERR_TRANSICION_ESTADO` | `422` | En Escenario 2, el estado solicitado no pertenece al catálogo válido (`creado`, `pagado_simulado`, `pendiente_preparacion`, `enviado`, `cancelado`, `incidencia`). | No se altera el pedido y se registra el fallo. |
| `ERR_ENTIDAD_NO_ENCONTRADA` | `422` / `404` | Se intenta sincronizar un `order_ref` o `sku` que no existe en el Back-Office. | Se informa del identificador inexistente y se guarda evidencia en logs. |
| `ERR_SALIDA_AGENTE_INCOMPLETA` | `422` | La propuesta del Agente IA no incluye alguno de los **7 campos obligatorios** del Apartado 6. | Se bloquea el registro de la propuesta del agente. |

---

## 3. Idempotencia en la Integración de Pedidos (Escenario 1)

Si n8n reintenta el envío de un mismo pedido (por ejemplo, primero al recibir `order.created` y segundos después al recibir `payment.simulated` con el mismo `order_ref`):
1. El Back-Office busca `order_ref` en `erp_orders`.
2. Si **no existe**, crea el pedido, inserta las líneas en `erp_order_items` y descuenta el stock en `erp_inventory`.
3. Si **ya existe**, ejecuta una **actualización idempotente** (`idempotent_update: true`): actualiza únicamente el estado de pago y el estado logístico sin duplicar el pedido ni volver a descontar el stock del almacén.

---

## 4. Justificación Académica y Técnica de las 8 Métricas del Cuadro de Mando (Apartado 7)

El apartado 7 del enunciado exige que las métricas procedan de datos reales generados por el sistema y que se justifique su elección en base a manuales o literatura técnica:

1. **Número de pedidos integrados e histórico diario (Diagrama de barras):** Métrica volumétrica fundamental de actividad transaccional omnicanal (Laudon & Traver, *E-Commerce: Business, Technology, Society*). Permite detectar picos de demanda y caídas en el flujo de captación.
2. **Importe total simulado (€) y Ticket Medio (AOV):** Indicador financiero primario para contrastar la conciliación entre la pasarela de pagos del front-office (Redsys) y el libro mayor del ERP.
3. **Productos y formatos más solicitados (Rotación por SKU):** Basado en principios de gestión de cadena de suministro (SCM / APICS), permite identificar los SKUs de alta rotación (*Lágrima de Habanero 200ml*, *Mango & Habanero 100ml*) para priorizar lotes de fabricación.
4. **Pedidos por estado operativo:** Indicador de flujo de trabajo (*Workflow WIP*) que visibiliza cuellos de botella en preparación de almacén (`pendiente_preparacion` vs. `enviado`) y pedidos bloqueados por `incidencia`.
5. **Incidencias abiertas vs. resueltas:** Métrica de calidad de servicio postventa alineada con **ITIL v4 (Incident & Service Request Management)** para controlar el *backlog* de atención al cliente.
6. **Tiempo medio simulado de resolución (MTTR — *Mean Time To Resolve*):** Métrica estándar de **ITIL v4 / SRE** calculada desde el registro del evento `support.requested` hasta el cierre tras la supervisión humana del Agente de IA.
7. **Eventos de integración correctos vs. fallidos y Errores por tipo:** Basado en los patrones de **Enterprise Integration Patterns (Hohpe & Woolf)** y observabilidad de middlewares, mide la fiabilidad del canal de integración (`n8n <-> Back-Office`) y clasifica la causa raíz de los rechazos de validación.
8. **Acciones propuestas por el Agente de IA vs. Aprobadas (Tasa de aceptación HITL):** Métrica específica de gobernanza de sistemas *Human-in-the-Loop (HITL)* (NIST AI Risk Management Framework, 2023) que mide la precisión operativa del agente y evita la automatización descontrolada de acciones sensibles.
