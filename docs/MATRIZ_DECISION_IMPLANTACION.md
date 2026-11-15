# Matriz de decisión de implantación (Apartado 8 del enunciado)

> Borrador para la memoria. **Las puntuaciones son una valoración razonada del grupo, no datos medidos**: antes de entregar, revisadlas entre los tres y ajustad los pesos si no os convencen. Los costes son estimaciones de orden de magnitud para una pyme con unos cientos de pedidos al mes.

## Caso
Piquantum es una tienda online pequeña (salsas picantes) que necesita: recibir pedidos e incidencias en un back-office propio, mantener coherentes estados y stock en los dos sentidos, atender postventa con apoyo de IA **supervisada** y medir el proceso.

## Alternativas comparadas
| | Descripción |
|---|---|
| **A. Híbrida (la implantada)** | Back-office propio ligero (PHP + SQLite) + n8n autoalojado como middleware + agente de IA (Gemini) y Telegram. |
| **B. ERP/CRM parametrizable** | Odoo (o similar) con módulos de ventas, inventario y helpdesk, conectado a la tienda por API. |
| **C. SaaS (low-code)** | Zapier/Make + CRM y helpdesk SaaS (p. ej. HubSpot, Zendesk) con su IA integrada. |
| **D. Desarrollo propio completo** | Integración directa tienda ↔ ERP programada a medida, sin plataforma de flujos. |

## Criterios y ponderación
Puntuación 1 (muy mala) a 5 (muy buena). Total = Σ peso × puntuación (máximo 5,00).

| Criterio | Peso | A. Híbrida | B. ERP | C. SaaS | D. A medida |
|---|---:|:--:|:--:|:--:|:--:|
| Funcionalidad para el caso | 10 % | 4 | 5 | 4 | 4 |
| Coste estimado (menos = mejor) | 10 % | 5 | 3 | 2 | 4 |
| Esfuerzo de implantación | 8 % | 3 | 2 | 5 | 1 |
| Escalabilidad | 7 % | 3 | 5 | 5 | 3 |
| Facilidad de integración | 10 % | 5 | 3 | 4 | 3 |
| Seguridad | 8 % | 4 | 4 | 4 | 3 |
| Independencia del proveedor | 8 % | 5 | 4 | 1 | 5 |
| Mantenimiento | 7 % | 3 | 3 | 5 | 2 |
| Cumplimiento normativo (RGPD, IA) | 8 % | 4 | 4 | 3 | 4 |
| Trazabilidad y auditoría | 10 % | 5 | 4 | 3 | 3 |
| Facilidad de evolución | 7 % | 5 | 3 | 3 | 2 |
| Adecuación al tamaño de la empresa | 7 % | 4 | 2 | 5 | 2 |
| **Total ponderado** | **100 %** | **4,23** | **3,53** | **3,60** | **3,07** |

(Pesos que suman 100: 10+10+8+7+10+8+8+7+8+10+7+7 = 100.)

## Recomendación
**Alternativa A (híbrida).** Mejor encaje con las exigencias del caso: trazabilidad completa (logs en n8n y en `erp_integration_logs`), supervisión humana explícita del agente, datos en infraestructura propia y bajo coste. Sus puntos débiles son el esfuerzo inicial y el mantenimiento, que asume el equipo.

**Cuándo cambiaría la decisión:** con volúmenes altos o necesidades de contabilidad, compras y facturación, el ERP parametrizable (B) pasa a ser mejor opción, y el back-office propio sería sustituido manteniendo n8n y los contratos. Si no hubiera nadie con perfil técnico, ganaría el SaaS (C), aceptando dependencia del proveedor y que los datos de clientes salgan a un tercero.

## Limitaciones de esta matriz
- Puntuaciones subjetivas; con otros pesos puede cambiar el orden entre B y C (3,53 y 3,60).
- Costes y esfuerzos no medidos con presupuestos reales.
