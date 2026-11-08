<?php

// n8n simulado para las pruebas: responde 200 y apunta lo recibido (cabecera de evento + cuerpo) en FAKE_N8N_LOG.
$log = getenv('FAKE_N8N_LOG');
if ($log) {
    file_put_contents($log, json_encode([
        'event'     => $_SERVER['HTTP_X_PIQUANTUM_EVENT'] ?? null,
        'signature' => $_SERVER['HTTP_X_PIQUANTUM_SIGNATURE'] ?? null,
        'body'      => file_get_contents('php://input'),
    ]) . "\n", FILE_APPEND);
}
header('Content-Type: application/json');
echo '{"ok":true}';
