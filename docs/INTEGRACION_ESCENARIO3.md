# Escenario 3 — cómo funciona y cómo ponerlo en marcha

```
Tienda ──support.requested──► n8n (flujo 1) ──► back-office: crea ticket (EN_REVISION_IA)
                                   │ responde 200 a la tienda
                                   └─► n8n (flujo 3): AGENTE (Gemini)
                                          ├ tool consultar_pedido_e_historial_cliente  (GET BO)
                                          ├ tool consultar_disponibilidad_almacen       (GET BO)
                                          └ tool proponer_resolucion_ticket             (POST BO, borrador)
                                       ► Telegram al supervisor con botones Aprobar / Rechazar
                                       ► POST /agent/reviews (decisión registrada en erp_ai_audits)
Aprobada ► back-office avisa a n8n (ai.proposal.approved, firmado) ► Telegram al cliente
```
El agente nunca escribe al cliente: el único nodo que lo hace solo se alcanza con un aviso firmado del back-office, que solo se genera tras una aprobación humana registrada.

Ficheros: `n8n/workflows/escenario1_eventos_tienda.json` (ahora enruta `support.requested`) y `n8n/workflows/escenario3_agente_postventa_telegram.json` (agente + aprobación + aviso al cliente).

## Pasos
1. **Bot de Telegram:** en Telegram, habla con `@BotFather` → `/newbot` → guarda el token. Escribe un mensaje al bot desde tu cuenta (supervisor) y desde la cuenta que hará de cliente.
2. **chat_id:** abre `https://api.telegram.org/bot<TOKEN>/getUpdates` y copia `message.chat.id` de cada cuenta (numérico).
3. **Clave de Gemini:** crea una API key gratuita en Google AI Studio.
4. **VM:** en `deploy/.env` rellena `SUPERVISOR_TELEGRAM_CHAT_ID` y `DEMO_CUSTOMER_TELEGRAM_CHAT_ID`, y recrea n8n: `git pull && cd deploy && docker compose up -d`.
5. **Credenciales en n8n** (Credentials → Add): *Telegram API* (token del bot) y *Google Gemini (PaLM) API* (la clave).
6. **Importar** los dos JSON (Workflows → Import from file). En el flujo 3, abre cada nodo con triángulo rojo (los 4 de Telegram y *Gemini 2.5 Flash*) y elige la credencial. Si el flujo 1 ya existía, importa el nuevo y desactiva el viejo.
7. **Activar** los dos flujos (el 3 tiene que estar activo: el flujo 1 llama a su webhook de producción).
8. **Probar** (sustituye pedido y secreto): envía `integracion/payloads/support.requested.json` firmado al webhook de n8n, o crea la incidencia desde `/soporte` de la tienda cuando tenga el emisor. Debes ver: ticket nuevo en el back-office → mensaje con botones en Telegram → pulsar *Aprobar* → mensaje al cliente → incidencia `RESUELTA` y `telegram_notified = 1`.
9. **Rama de error:** envía el payload con `ticket.message` de 3 letras (→ 422, log) y apaga la clave de Gemini (→ alerta de fallo en Telegram, nada llega al cliente).
10. **Evidencias:** capturas de *Executions* de n8n (incluida la que usa las 3 tools), de la pestaña *IA* y *Trazabilidad* del back-office, y de los dos chats de Telegram.

## Honestidad técnica: lo que NO está probado
Los JSON se han generado y revisado (conexiones, código de los nodos de validación y extracción ejecutado con datos de ejemplo), pero **no se han ejecutado en una instancia real de n8n**. Los nodos del agente (`toolHttpRequest`, parser estructurado, `sendAndWait` de Telegram) son los más sensibles a la versión: si al importar alguno aparece como «desconocido» o con parámetros vacíos, rehacedlo a mano con los mismos valores (están en el JSON). Con Gemini puede hacer falta ajustar el prompt si no llama a las herramientas en el orden esperado: anotad esos errores y su corrección en el anexo de IA (apartado 10.i y 10.j del enunciado).

## Decisiones y limitaciones
- `telegram_chat_id` (decisión 12.B del contrato): opción 3, un chat de prueba fijo en una variable de n8n. Hay que cerrarla en `integracion/contrato.md`.
- Si se aprueba desde la web del back-office mientras el mensaje de Telegram espera, el botón de Telegram queda pendiente; al pulsarlo, el back-office responderá que ya estaba revisada (se ve en el log).
- Si Telegram falla tras enviar, el reintento puede duplicar el mensaje al cliente (entrega «al menos una vez»).
