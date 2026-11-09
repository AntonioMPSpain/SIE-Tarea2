<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Controllers;

use Piquantum\BackOffice\Config\Database;
use Piquantum\BackOffice\Services\IntegrationService;

class ApiController
{
    private IntegrationService $service;

    public function __construct()
    {
        $this->service = new IntegrationService();
    }

    public function handle(string $method, string $uri): void
    {
        header('Content-Type: application/json; charset=utf-8');

        // Health check público (sin token): lo usan Docker/Caddy para saber si el servicio está vivo.
        if ($method === 'GET' && $uri === '/api/v1/health') {
            $this->jsonResponse(200, [
                'ok'        => true,
                'service'   => 'Piquantum Back-Office ERP & AI Governance API',
                'version'   => '1.0.0-academic',
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            return;
        }

        // El resto de rutas exige el token de integración, solo por cabecera X-Integration-Token.
        // Sin valor por defecto: si el servidor no lo tiene configurado, la API no se abre.
        $expectedToken = (string) Database::secret('INTEGRATION_API_KEY');
        if ($expectedToken === '') {
            $this->jsonResponse(500, [
                'ok'         => false,
                'error_code' => 'ERR_CONFIGURACION',
                'message'    => 'El servidor no tiene configurada la variable INTEGRATION_API_KEY.',
            ]);
            return;
        }

        $providedToken = (string) ($_SERVER['HTTP_X_INTEGRATION_TOKEN'] ?? '');
        if ($providedToken === '') {
            $this->jsonResponse(401, [
                'ok'         => false,
                'error_code' => 'ERR_AUTH_TOKEN_AUSENTE',
                'message'    => 'Falta la cabecera X-Integration-Token.',
            ]);
            return;
        }

        // hash_equals compara en tiempo constante para no filtrar el token por el tiempo de respuesta.
        if (!hash_equals($expectedToken, $providedToken)) {
            $this->jsonResponse(401, [
                'ok'         => false,
                'error_code' => 'ERR_AUTH_TOKEN_INVALIDO',
                'message'    => 'Token de integración X-Integration-Token inválido.',
            ]);
            return;
        }

        // Rutas API REST v1 (autenticadas)

        if ($method === 'GET' && $uri === '/api/v1/metrics') {
            $this->jsonResponse(200, [
                'ok'      => true,
                'metrics' => $this->service->getDashboardMetrics(),
            ]);
            return;
        }

        // Carga inicial del catálogo desde la tienda (alta o actualización por sku, idempotente)
        if ($method === 'POST' && $uri === '/api/v1/inventory/import') {
            $result = $this->service->importInventory($this->readJsonBody());
            $this->jsonResponse($result['ok'] ? 200 : 422, $result);
            return;
        }

        // Reintento de los avisos pendientes hacia n8n (lo puede llamar un Schedule Trigger de n8n o un cron)
        if ($method === 'POST' && $uri === '/api/v1/internal/webhooks/retry') {
            $this->jsonResponse(200, ['ok' => true] + $this->service->retryPendingWebhooks());
            return;
        }

        // Escenario 1: Recepción de pedido desde n8n
        if ($method === 'POST' && $uri === '/api/v1/webhooks/orders') {
            $payload = $this->readJsonBody();
            $result = $this->service->processOrderEvent($payload, 'eCommerce -> n8n -> BackOffice (API)');
            $this->jsonResponse($result['ok'] ? 200 : 422, $result);
            return;
        }

        // Escenario 2: Cambio operativo / sincronización de estado o stock
        if ($method === 'POST' && $uri === '/api/v1/webhooks/operations') {
            $payload = $this->readJsonBody();
            $result = $this->service->processOperationalChange($payload, false);
            $this->jsonResponse($result['ok'] ? 200 : 422, $result);
            return;
        }

        // Escenario 3: Recepción de incidencia o actividad postventa
        if ($method === 'POST' && $uri === '/api/v1/webhooks/tickets') {
            $payload = $this->readJsonBody();
            $result = $this->service->processSupportTicket($payload, 'eCommerce -> n8n -> BackOffice (API)');
            $this->jsonResponse($result['ok'] ? 200 : 422, $result);
            return;
        }

        // Tool 1 del Agente IA (Consulta de pedido, cliente, historial e inventario de líneas)
        if ($method === 'GET' && preg_match('#^/api/v1/agent/orders/([A-Za-z0-9\-_]+)$#', $uri, $m)) {
            $result = $this->service->getAgentOrderContext($m[1]);
            $this->jsonResponse($result['found'] ? 200 : 404, $result);
            return;
        }

        // Tool 2 del Agente IA (Consulta de disponibilidad/stock de un SKU)
        if ($method === 'GET' && preg_match('#^/api/v1/agent/inventory/([A-Za-z0-9\-_]+)$#', $uri, $m)) {
            $result = $this->service->getAgentStockContext($m[1]);
            $this->jsonResponse($result['found'] ? 200 : 404, $result);
            return;
        }

        // Tool 3 del Agente IA (Acción/Propuesta estructurada con los 7 campos obligatorios)
        if ($method === 'POST' && $uri === '/api/v1/agent/proposals') {
            $payload = $this->readJsonBody();
            $result = $this->service->registerAgentProposal($payload);
            $this->jsonResponse($result['ok'] ? 201 : 422, $result);
            return;
        }

        // Endpoint para registrar la aprobación/rechazo humano desde Telegram o n8n
        if ($method === 'POST' && $uri === '/api/v1/agent/reviews') {
            $payload = $this->readJsonBody();
            $auditId = (int) ($payload['audit_id'] ?? 0);
            // Sin valores por defecto: sin decision o sin reviewed_by, la revisión se rechaza (422)
            $decision = (string) ($payload['decision'] ?? '');
            $reviewer = (string) ($payload['reviewed_by'] ?? '');
            $comment  = (string) ($payload['comment'] ?? '');
            $result = $this->service->reviewAgentProposal($auditId, $decision, $reviewer, $comment);
            $status = $result['ok'] ? 200 : (($result['error_code'] ?? '') === 'ERR_ENTIDAD_NO_ENCONTRADA' ? 404 : 422);
            $this->jsonResponse($status, $result);
            return;
        }

        $this->jsonResponse(404, [
            'ok'      => false,
            'message' => "Ruta API no encontrada: {$method} {$uri}",
        ]);
    }

    private function readJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return $_POST ?: [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function jsonResponse(int $status, array $data): void
    {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
