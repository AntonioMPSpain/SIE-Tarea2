# Despliegue en Google Cloud

Caddy (HTTPS), n8n y el back-office en una VM de Compute Engine con Docker Compose.

| Servicio | URL pública | Dirección interna |
|---|---|---|
| n8n | https://n8n.piquantum.onl | `http://n8n:5678` |
| Back-office | https://erp.piquantum.onl (la API devuelve 403 desde fuera) | `http://backoffice:8080` |

- VM: `e2-medium`, Madrid (`europe-southwest1`), Ubuntu 24.04, IP estática `34.175.156.116`.
- Firewall: solo 22, 80 y 443.
- Solo Caddy publica puertos. n8n llama a la API del back-office por la red interna, y el back-office avisa a n8n también por dentro.

## Requisitos

1. **DNS en DonDominio:** registros A de `n8n` y `erp` apuntando a `34.175.156.116`. Comprueba con `dig +short erp.piquantum.onl`.
2. **Docker y el plugin Compose** en la VM (`docker compose version`).

## Primer arranque

```bash
git clone https://github.com/AntonioMPSpain/SIE-Tarea2.git
cd SIE-Tarea2
git switch <rama>
cd deploy
cp .env.example .env
nano .env          # valores propios: ver los comentarios de .env.example
docker compose up -d --build
docker compose logs -f caddy   # esperar "certificate obtained successfully" para los dos dominios
```

> **En cuanto arranque, abre https://n8n.piquantum.onl y crea la cuenta de propietario.** Mientras no exista, la primera persona que entre se la queda. Después invita al resto del grupo desde *Settings → Users*. Sin correo configurado, n8n muestra el enlace de invitación para copiarlo.

## Comprobaciones

| Prueba | Esperado |
|---|---|
| `curl -I https://n8n.piquantum.onl` | `200`, certificado válido |
| Abrir https://erp.piquantum.onl | Pantalla de login del back-office |
| `curl -i https://erp.piquantum.onl/api/v1/health` | `403` (API cerrada desde fuera) |
| `docker compose exec n8n wget -qO- http://backoffice:8080/api/v1/health` | `ok`: n8n llega al back-office por dentro |
| `sudo reboot` y después `docker compose ps` | Los tres contenedores en marcha |

## Cargar el catálogo de la tienda

La API no está publicada, así que se llama desde la red interna con un contenedor temporal de `curl`. Hay que lanzarlo desde la raíz del repositorio y sustituir `<INTEGRATION_API_KEY>` por el valor de `deploy/.env`:

```bash
docker run --rm --network piquantum_default -v "$PWD/payloads:/p:ro" curlimages/curl \
  -s -X POST http://backoffice:8080/api/v1/inventory/import \
  -H "Content-Type: application/json" -H "X-Integration-Token: <INTEGRATION_API_KEY>" \
  --data @/p/carga_catalogo_tienda.json
```

## Variables que n8n tiene disponibles

En los flujos se leen con `$env.NOMBRE`. Así los secretos no quedan dentro de los workflows exportados a `n8n/workflows/`.

| Variable | Uso |
|---|---|
| `ERP_API_URL` | `http://backoffice:8080/api/v1` |
| `ERP_API_KEY` | Cabecera `X-Integration-Token` al llamar al back-office |
| `ERP_WEBHOOK_SECRET` | Verificar la firma HMAC de los avisos del back-office |
| `PIQUANTUM_WEBHOOK_SECRET` | Verificar la firma HMAC de los webhooks de la tienda |
| `PIQUANTUM_SYNC_URL`, `PIQUANTUM_SYNC_TOKEN` | Aplicar cambios en la tienda (`POST /api/integration/sync`, `Authorization: Bearer`) |

Webhooks de n8n que llama el back-office:

- `piquantum-operational-change` (escenario 2)
- `piquantum-human-review` (escenario 3)

Los flujos tienen que usar exactamente esas rutas.

**Reintentos del back-office hacia n8n:** se necesita un flujo con un *Schedule Trigger* cada minuto que haga `POST {{$env.ERP_API_URL}}/internal/webhooks/retry` con la cabecera `X-Integration-Token`.

## Operación

```bash
docker compose ps                              # estado
docker compose logs -f n8n                     # logs de un servicio
git pull && docker compose up -d --build       # actualizar tras cambios en el repo
docker compose down                            # parar (los volúmenes y los datos se conservan)
```

**Copia de seguridad** de los datos de n8n y del back-office, que viven en volúmenes de Docker:

```bash
docker run --rm -v piquantum_n8n_data:/d -v "$PWD:/b" alpine tar czf /b/n8n_data.tgz -C /d .
docker run --rm -v piquantum_erp_db:/d -v "$PWD:/b" alpine tar czf /b/erp_db.tgz -C /d .
```

> **No borres los volúmenes** (`docker compose down -v`): se perderían los usuarios y flujos de n8n, el historial de ejecuciones (evidencias) y la base de datos del back-office.
