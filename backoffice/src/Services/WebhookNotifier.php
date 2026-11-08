<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Services;

use Piquantum\BackOffice\Config\Database;

class WebhookNotifier
{
    /**
     * Envía un webhook estructurado desde el Back-Office hacia n8n
     * (Usado en Escenario 2: Cambio operativo, y en Escenario 3: Revisión humana aprobada/rechazada).
     * Si n8n no está levantado en ese instante, no rompe la ejecución y devuelve el estado de entrega.
     */
    public static function dispatch(string $targetUrlEnvKey, string $eventType, array $data): array
    {
        $url = (string) Database::env($targetUrlEnvKey, '');
        // Sin valor por defecto: sin secreto no se firma ni se envía nada
        $secret = (string) Database::secret('WEBHOOK_HMAC_SECRET');

        $envelope = [
            'event_id'    => 'evt_bo_' . bin2hex(random_bytes(6)),
            'event_type'  => $eventType,
            'source'      => 'piquantum.backoffice.erp',
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'data'        => $data,
        ];

        $jsonPayload = json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', (string) $jsonPayload, $secret);

        if ($url === '' || $secret === '' || !function_exists('curl_init')) {
            return [
                'dispatched' => false,
                'target_url' => $url ?: 'no_configurada',
                'http_code'  => 0,
                'note'       => $secret === ''
                    ? 'No enviado: falta WEBHOOK_HMAC_SECRET para firmar el webhook.'
                    : 'Webhook registrado como evento pendiente de sincronización (URL n8n no activa).',
                'envelope'   => $envelope,
            ];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonPayload,
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
        curl_close($ch);

        $ok = ($httpCode >= 200 && $httpCode < 300);

        return [
            'dispatched' => $ok,
            'target_url' => $url,
            'http_code'  => $httpCode,
            'note'       => $ok
                ? "Webhook entregado a n8n (HTTP {$httpCode})."
                : "n8n no disponible en {$url} ({$curlErr}). Queda registrado en Back-Office como PENDIENTE_SINCRONIZACION.",
            'envelope'   => $envelope,
            'response'   => $responseBody ?: null,
        ];
    }
}
