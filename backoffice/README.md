# Piquantum Back-Office ERP & Cuadro de Mando Operativo (Tarea 2)

> ⚠️ **Prototipo académico sin actividad comercial real.** Sistema independiente de Back-Office (ERP/CRM + Trazabilidad + Gobernanza de IA Agéntica + Cuadro de Mando Operativo) desarrollado para la **Tarea 2** de *Soluciones Informáticas para la Empresa* (UCAM).

---

## 1. Ejecución rápida en local (Windows / Linux / macOS)

No requiere configurar servidores externos ni tocar el proyecto de la Tarea 1 (`Piquantum`): utiliza su propia base de datos independiente (`storage/database/piquantum_erp.sqlite` por defecto, o MySQL/MariaDB en producción/Docker).

```powershell
cd "c:\Users\AntonioMP\Desktop\UCAM\SIE\Prácticas\Tarea 2\backoffice"
php -S 127.0.0.1:8080 -t public public/index.php
```

Abre en el navegador: **http://127.0.0.1:8080**

Para ejecutar la batería de pruebas automáticas de integración, validaciones, idempotencia y métricas:
```powershell
php tests/verify_backoffice.php
```

---

## 2. Ejecución en Docker (VM Google Cloud junto a n8n)

El directorio `backoffice/` incluye su propio `Dockerfile` (PHP 8.3 Alpine + PDO SQLite/MySQL):

```bash
docker build -t piquantum-backoffice ./backoffice
docker run -d -p 8080:8080 --name piquantum-backoffice piquantum-backoffice
```

---

## 3. Funcionalidades implementadas (Parte de Antonio)

1. **Cuadro de Mando Operativo (`/?tab=dashboard` — Apartado 7):**
   - **8 métricas operativas en tiempo real** obtenidas mediante consultas SQL sobre la base de datos del Back-Office:
     1. Número de pedidos integrados y ticket medio.
     2. Importe total simulado (€).
     3. Ejecuciones de integración correctas vs. fallidas y latencia media (ms).
     4. Tasa de conversión del flujo (`product.viewed` → `cart.item_added` → `checkout.started` → `order.created`).
     5. Incidencias abiertas vs. resueltas.
     6. Tiempo medio simulado de resolución (MTTR en minutos).
     7. Acciones propuestas por el Agente de IA vs. acciones aprobadas/rechazadas por supervisión humana.
     8. Alertas de stock crítico en almacén.
   - **4 representaciones gráficas interactivas (Chart.js):**
     - Diagrama de barras con el histórico diario de pedidos e ingresos.
     - Gráfico de barras horizontal con los productos (salsas picantes) más solicitados.
     - Gráfico Donut de distribución de pedidos por estado.
     - Embudo de conversión y desglose de errores de integración por tipo (`ERR_CAMPOS_OBLIGATORIOS`, `ERR_IMPORTE_INVALIDO`, etc.).

2. **Gestión de Pedidos y Sincronización (`/?tab=orders` — Escenarios 1 y 2):**
   - Recepción estructurada de pedidos con validación de campos obligatorios e **idempotencia básica por `order_ref`**.
   - Formulario operativo para modificar estado (`pendiente_preparacion`, `enviado`, `incidencia`, `cancelado`) y prioridad (`NORMAL`, `ALTA`, `URGENTE`), disparando el webhook hacia n8n o registrándolo como `PENDIENTE_SINCRONIZACION`.

3. **Almacén y Stock (`/?tab=inventory` — Escenario 2 + Tool IA):**
   - Control de inventario de las 12 referencias de Piquantum por SKU, formato (50ml, 100ml, 200ml), ubicación de pasillo y umbral de stock mínimo.

4. **Postventa, Agente de IA Supervisado y Revisión Humana (`/?tab=ai_governance` — Escenario 3 y Apartado 6):**
   - Visualización de los **7 campos obligatorios de la salida estructurada del Agente de IA**:
     - `a. Tipo de caso detectado`
     - `b. Datos consultados` (JSON devuelto por las Tools de consulta)
     - `c. Acción propuesta`
     - `d. Justificación breve`
     - `e. Nivel de confianza o prioridad`
     - `f. Riesgos o advertencias`
     - `g. Estado final` (`pendiente_de_revision`, `aprobado`, `rechazado`, `ejecutado_en_modo_simulado`)
   - Botones de **Supervisión Humana (`✅ Aprobar y Enviar a Telegram` / `❌ Rechazar Propuesta`)** con trazabilidad del operador revisor y timestamp.

5. **Trazabilidad y Logs de Ejecución (`/?tab=logs` — Apartado 3.e y 3.f):**
   - Historial auditable con identificador de ejecución, escenario, dirección del flujo, estado (`SUCCESS` / `ERROR`), código de error y visor del Payload JSON recibido y su respuesta.

6. **Modo Evidencia "Antes / Después" y Simulador (`/?tab=simulator` — Apartado 9.g y 9.i):**
   - Botones superiores para alternar en un clic entre **"📸 Estado ANTES de Integración"** y **"⚡ Estado DESPUÉS de Integración"**.
   - Lanzador interactivo con los payloads JSON precargados.

---

## 4. Endpoints de la API REST para conectar desde n8n

| Método | Ruta | Uso en n8n / Agente IA |
|---|---|---|
| `GET` | `/api/v1/health` | Comprobación de estado del servicio Back-Office |
| `GET` | `/api/v1/metrics` | JSON en vivo con todas las métricas del Cuadro de Mando |
| `POST` | `/api/v1/webhooks/orders` | **Escenario 1:** Crear/actualizar pedido integrado |
| `POST` | `/api/v1/webhooks/operations` | **Escenario 2:** Sincronizar cambio de estado o stock |
| `POST` | `/api/v1/webhooks/tickets` | **Escenario 3:** Crear ticket de incidencia postventa |
| `GET` | `/api/v1/agent/orders/{order_ref}` | **Tool Consulta 1 (Agente IA):** Consultar pedido, cliente, historial e inventario |
| `GET` | `/api/v1/agent/inventory/{sku}` | **Tool Consulta 2 (Agente IA):** Consultar stock y ubicación de almacén |
| `POST` | `/api/v1/agent/proposals` | **Tool Acción (Agente IA):** Registrar propuesta estructurada de 7 campos |
| `POST` | `/api/v1/agent/reviews` | Registrar aprobación/rechazo humano desde callback de Telegram |
