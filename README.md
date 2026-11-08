# Piquantum — Tarea 2: Integración, Automatización de Procesos e IA Agéntica Supervisada

> ⚠️ **Prototipo académico sin actividad comercial real.** Asignatura: *Soluciones Informáticas para la Empresa* (Grado en Ingeniería Informática · UCAM).  
> **Integrantes del grupo:** Álvaro Acedo Sánchez · Abel Martínez Candela · Antonio Martínez Pallarés.

---

## Estructura del Repositorio (`SIE-Tarea2`)

| Carpeta / Archivo | Contenido |
|---|---|
| [`PLAN_TAREA_2.md`](PLAN_TAREA_2.md) | Plan Maestro de arquitectura, escenarios obligatorios y reparto de responsabilidades. |
| [`AGENTS.md`](AGENTS.md) | Contexto técnico, reglas de negocio y guía rápida para desarrolladores y agentes de IA. |
| [`backoffice/`](backoffice/) | **Sistema Independiente de Back-Office (ERP/CRM + Gobernanza IA + Cuadro de Mando Operativo)** con su propia base de datos aislada y API REST (`/api/v1/*`). |
| [`payloads/`](payloads/) | **Payloads JSON oficiales de prueba** para los Escenarios 1, 2, 3, Rama de Error y Salida Estructurada del Agente IA (Apartado 9.g). |
| [`docs/GUIA_COMPAÑEROS_INTEGRACION.md`](docs/GUIA_COMPAÑEROS_INTEGRACION.md) | Guía paso a paso para conectar `n8n` (en la VM de Google Cloud) y el Bot de Telegram con el Back-Office. |
| [`docs/CONTRATOS_API_Y_AGENTE.md`](docs/CONTRATOS_API_Y_AGENTE.md) | Especificación de contratos de integración, validaciones, códigos de error y justificación de métricas operativas. |

---

## Arranque rápido del Back-Office

```powershell
cd backoffice
cp .env.example .env   # y pon valores propios: INTEGRATION_API_KEY, WEBHOOK_HMAC_SECRET y ERP_ADMIN_PASSWORD_HASH
php -S 127.0.0.1:8080 -t public public/index.php
```
Acceder en el navegador a: **http://127.0.0.1:8080**

Para ejecutar los tests automáticos de integración y validación (usan una base de datos temporal propia):
```powershell
php backoffice/tests/verify_backoffice.php
```
