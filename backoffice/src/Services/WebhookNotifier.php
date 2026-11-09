<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Services;

use Piquantum\BackOffice\Config\Database;

class WebhookNotifier
{
    /**
     * Construye el sobre de un webhook del Back-Office hacia n8n.
     * El cuerpo JSON resultante se guarda tal cual en la bandeja de salida (erp_outbox_webhooks)
     * para que cada reintento envíe exactamente lo mismo, con el mismo event_id.
     */
    public static function buildBody(string $eventType, array $data): array
    {
        $envelope = [
            'event_id'    => 'evt_bo_' . bin2hex(random_bytes(8)),
            'event_type'  => $eventType,
            'source'      => 'piquantum.backoffice.erp',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data'        => $data,
        ];

        return [
            'event_id' => $envelope['event_id'],
            'body'     => (string) json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Envía a n8n un cuerpo ya construido, firmado con HMAC-SHA256 (X-Piquantum-Signature).
     * Usado en el Escenario 2 (cambio operativo) y en el Escenario 3 (revisión humana aprobada).
     * Si n8n no está disponible, no rompe la ejecución: devuelve el resultado del intento.
     * Solo se considera entregado si n8n responde 2xx.
     */
    public static function send(string $targetUrlEnvKey, string $eventType, string $body): array
    {
        $url = (string) Database::env($targetUrlEnvKey, '');
        // Sin valor por defecto: sin secreto no se firma ni se envía nada
        $secret = (string) Database::secret('WEBHOOK_HMAC_SECRET');

        if ($url === '' || $secret === '' || !function_exists('curl_init')) {
            return [
                'dispatched' => false,
                'target_url' => $url ?: 'no_configurada',
                'http_code'  => 0,
                'note'       => $secret === ''
                    ? 'No enviado: falta WEBHOOK_HMAC_SECRET para firmar el webhook.'
                    : "No enviado: URL de n8n no configurada ({$targetUrlEnvKey}).",
            ];
        }

        $signature = hash_hmac('sha256', $body, $secret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Piquantum-Event: ' . $eventType,
                'X-Piquantum-Signature: sha256=' . $signature,
                'X-Integration-Token: ' . Database::env('INTEGRATION_API_KEY', ''),
            ],
        ]);

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);

        $ok = ($httpCode >= 200 && $httpCode < 300);

        return [
            'dispatched' => $ok,
            'target_url' => $url,
            'http_code'  => $httpCode,
            'note'       => $ok
                ? "Webhook entregado a n8n (HTTP {$httpCode})."
                : "n8n no disponible en {$url} (" . ($curlErr !== '' ? $curlErr : "HTTP {$httpCode}") . '). Queda pendiente de reintento.',
            'response'   => is_string($responseBody) && $responseBody !== '' ? substr($responseBody, 0, 500) : null,
        ];
    }
}
