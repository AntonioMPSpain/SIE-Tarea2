<?php

declare(strict_types=1);

namespace Piquantum\BackOffice\Controllers;

use PDO;
use Piquantum\BackOffice\Config\Database;
use Piquantum\BackOffice\Database\Seeder;
use Piquantum\BackOffice\Services\IntegrationService;

class WebController
{
    private PDO $db;
    private IntegrationService $service;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            // Cookie de sesión no accesible desde JavaScript, no enviada desde otros sitios en POST
            // y solo por HTTPS cuando la web va detrás de HTTPS (Caddy en el servidor).
            $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
            ini_set('session.use_strict_mode', '1');
            session_name('PQBO_SESSION');
            session_set_cookie_params([
                'path'     => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure'   => $isHttps,
            ]);
            session_start();
        }
        $this->db = Database::getConnection();
        $this->service = new IntegrationService($this->db);
        // Sin datos de demostración: la base de datos solo contiene lo que llega por la integración real (n8n).
    }

    /** El vaciado de la base de datos solo existe en desarrollo (APP_ENV=local), nunca en producción. */
    private function isLocalEnv(): bool
    {
        return strtolower((string) Database::env('APP_ENV', 'production')) === 'local';
    }

    public function handle(string $method, string $uri): void
    {
        // Todo formulario POST (también el login y el cierre de sesión) debe llevar el token CSRF de la sesión.
        if ($method === 'POST' && !$this->hasValidCsrfToken()) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Formulario rechazado: falta el token CSRF o no es válido. Vuelve a cargar la página e inténtalo de nuevo.';
            return;
        }

        // Rutas públicas: solo el inicio de sesión
        if ($uri === '/login') {
            if ($method === 'POST') {
                $this->login();
            } else {
                $this->showLogin();
            }
            return;
        }

        // Todo lo demás exige sesión iniciada
        if ($this->currentUser() === null) {
            $this->redirect('/login');
        }

        if ($method === 'POST' && $uri === '/logout') {
            $this->logout();
        }

        // Procesar acciones POST desde formularios del Back-Office
        if ($method === 'POST') {
            $action = (string) ($_POST['action'] ?? '');
            $this->handlePostAction($action);
            return;
        }

        // Exportaciones directas de auditoría, trazabilidad y métricas (sesión autenticada)
        if ($method === 'GET' && isset($_GET['export'])) {
            $this->handleExport((string) $_GET['export']);
            return;
        }

        $tab = (string) ($_GET['tab'] ?? 'dashboard');
        $searchFilter         = isset($_GET['q']) ? trim((string) $_GET['q']) : null;
        $orderStatusFilter    = isset($_GET['order_status']) ? trim((string) $_GET['order_status']) : null;
        $orderPriorityFilter  = isset($_GET['order_priority']) ? trim((string) $_GET['order_priority']) : null;
        $customerSegFilter    = isset($_GET['customer_segment']) ? trim((string) $_GET['customer_segment']) : null;
        $logStatusFilter      = isset($_GET['log_status']) ? trim((string) $_GET['log_status']) : null;
        $logScenarioFilter    = isset($_GET['log_scenario']) ? trim((string) $_GET['log_scenario']) : null;
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);

        $metrics = $this->service->getDashboardMetrics();

        // Consultas para las vistas
        $orders = $this->service->getFilteredOrders($orderStatusFilter, $orderPriorityFilter, $searchFilter);
        $orderItemsByOrder = [];
        $allItems = $this->db->query('SELECT * FROM erp_order_items ORDER BY id ASC')->fetchAll();
        foreach ($allItems as $it) {
            $orderItemsByOrder[(int) $it['order_id']][] = $it;
        }

        $inventory = $this->db->query('SELECT * FROM erp_inventory ORDER BY sku ASC')->fetchAll();
        $customers = $this->service->getCustomersWithTicketCounts($customerSegFilter, $searchFilter);
        $tickets   = $this->db->query('SELECT * FROM erp_tickets ORDER BY id DESC')->fetchAll();
        $audits    = $this->db->query('SELECT * FROM erp_ai_audits ORDER BY id DESC')->fetchAll();
        $logs      = $this->service->getFilteredLogs($logStatusFilter, $logScenarioFilter, $searchFilter, 100);

        $currentUser = $this->currentUser();
        $csrfToken   = $this->csrfToken();
        $isLocalEnv  = $this->isLocalEnv();

        require dirname(__DIR__, 2) . '/views/layout.php';
    }

    private function handleExport(string $exportType): void
    {
        $logStatus   = isset($_GET['log_status']) ? trim((string) $_GET['log_status']) : null;
        $logScenario = isset($_GET['log_scenario']) ? trim((string) $_GET['log_scenario']) : null;
        $search      = isset($_GET['q']) ? trim((string) $_GET['q']) : null;

        switch ($exportType) {
            case 'logs_csv':
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="piquantum_logs_trazabilidad.csv"');
                echo $this->service->exportLogsToCsv($logStatus, $logScenario);
                exit;

            case 'logs_json':
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="piquantum_logs_trazabilidad.json"');
                $rows = $this->service->getFilteredLogs($logStatus, $logScenario, $search, 500);
                echo json_encode(['status' => 'ok', 'count' => count($rows), 'logs' => $rows], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;

            case 'audits_csv':
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="piquantum_auditoria_agente_ia.csv"');
                echo $this->service->exportAuditsToCsv();
                exit;

            case 'metrics_json':
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename="piquantum_metricas_operativas.json"');
                echo json_encode(['status' => 'ok', 'metrics' => $this->service->getDashboardMetrics()], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                exit;

            default:
                $this->redirect('/');
        }
    }

    private function handlePostAction(string $action): void
    {
        switch ($action) {
            case 'reset_before_integration':
                if (!$this->isLocalEnv()) {
                    http_response_code(403);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'El vaciado de la base de datos solo está disponible en desarrollo (APP_ENV=local).';
                    exit;
                }
                Seeder::resetToBeforeIntegration($this->db);
                $_SESSION['flash'] = [
                    'type' => 'warning',
                    'title' => 'Base de datos vaciada (solo desarrollo)',
                    'message' => 'Se han borrado pedidos, clientes, incidencias, propuestas del agente y logs. El almacén vuelve al catálogo inicial.',
                ];
                $this->redirect('/?tab=' . ($_POST['return_tab'] ?? 'dashboard'));
                break;

            case 'update_order_status':
                $res = $this->service->processOperationalChange([
                    'change_type' => 'ORDER_STATUS',
                    'order_ref'   => (string) ($_POST['order_ref'] ?? ''),
                    'new_status'  => (string) ($_POST['new_status'] ?? ''),
                    'priority'    => (string) ($_POST['priority'] ?? 'NORMAL'),
                    'direction'   => 'Operador BackOffice -> n8n -> eCommerce',
                ], true);
                $_SESSION['flash'] = [
                    'type'    => $res['ok'] ? 'success' : 'danger',
                    'title'   => $res['ok'] ? 'Escenario 2: Cambio operativo ejecutado' : 'Error en cambio operativo',
                    'message' => $res['message'],
                ];
                $this->redirect('/?tab=orders');
                break;

            case 'update_inventory_stock':
                $res = $this->service->processOperationalChange([
                    'change_type' => 'STOCK_UPDATE',
                    'sku'         => (string) ($_POST['sku'] ?? ''),
                    'new_stock'   => (int) ($_POST['new_stock'] ?? 0),
                    'direction'   => 'Operador BackOffice -> n8n -> eCommerce',
                ], true);
                $_SESSION['flash'] = [
                    'type'    => $res['ok'] ? 'success' : 'danger',
                    'title'   => $res['ok'] ? 'Escenario 2: Stock sincronizado' : 'Error al actualizar stock',
                    'message' => $res['message'],
                ];
                $this->redirect('/?tab=inventory');
                break;

            case 'review_ai_proposal':
                $auditId  = (int) ($_POST['audit_id'] ?? 0);
                $decision = (string) ($_POST['decision'] ?? '');
                // El revisor es siempre el operador con sesión iniciada, nunca un dato que llegue del formulario.
                $reviewer = (string) ($this->currentUser()['email'] ?? '');
                $comment  = trim((string) ($_POST['review_comment'] ?? ''));

                $res = $this->service->reviewAgentProposal($auditId, $decision, $reviewer, $comment);
                $_SESSION['flash'] = [
                    'type'    => $res['ok'] ? ($decision === 'aprobado' ? 'success' : 'warning') : 'danger',
                    'title'   => 'Supervisión Humana Registrada (Escenario 3 / Apartado 6)',
                    'message' => $res['message'],
                ];
                $this->redirect('/?tab=ai_governance');
                break;

            case 'simulate_payload':
                $scenarioType = (string) ($_POST['scenario_type'] ?? 'order');
                $rawJson = (string) ($_POST['payload_json'] ?? '{}');
                $decoded = json_decode($rawJson, true);

                if (!is_array($decoded)) {
                    $_SESSION['flash'] = [
                        'type'    => 'danger',
                        'title'   => 'JSON mal formado',
                        'message' => 'El cuerpo del payload no es un JSON válido.',
                    ];
                    $this->redirect('/?tab=simulator');
                    return;
                }

                if ($scenarioType === 'order') {
                    $res = $this->service->processOrderEvent($decoded, 'Simulador BackOffice / n8n Webhook');
                } elseif ($scenarioType === 'operation') {
                    $res = $this->service->processOperationalChange($decoded, true);
                } elseif ($scenarioType === 'ticket') {
                    $res = $this->service->processSupportTicket($decoded, 'Simulador BackOffice / n8n Webhook');
                } else {
                    $res = $this->service->registerAgentProposal($decoded);
                }

                $_SESSION['flash'] = [
                    'type'    => $res['ok'] ? 'success' : 'danger',
                    'title'   => $res['ok'] ? 'Ejecución Correcta (Rama OK)' : 'Ejecución Rechazada (Rama de Error / Validación)',
                    'message' => $res['message'],
                ];
                $this->redirect('/?tab=logs');
                break;

            default:
                $this->redirect('/');
        }
    }

    // =========================================================================
    // ACCESO DEL OPERADOR (inicio y cierre de sesión)
    // =========================================================================

    /** Usuario con sesión iniciada, o null si no hay sesión. */
    private function currentUser(): ?array
    {
        $user = $_SESSION['user'] ?? null;
        return is_array($user) ? $user : null;
    }

    private function showLogin(?string $error = null, string $email = '', int $status = 200): void
    {
        if ($this->currentUser() !== null) {
            $this->redirect('/');
        }
        http_response_code($status);
        $csrfToken = $this->csrfToken();
        require dirname(__DIR__, 2) . '/views/login.php';
    }

    private function login(): void
    {
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        $adminEmail = trim((string) Database::env('ERP_ADMIN_EMAIL', ''));
        $adminHash  = (string) Database::env('ERP_ADMIN_PASSWORD_HASH', '');

        // Sin credenciales configuradas no se puede entrar (nunca hay una contraseña por defecto).
        if ($adminEmail === '' || $adminHash === '') {
            $this->showLogin('El acceso no está configurado en el servidor (ERP_ADMIN_EMAIL / ERP_ADMIN_PASSWORD_HASH).', $email, 500);
            return;
        }

        // Se comprueban las dos cosas siempre, para no revelar si el email existe por el tiempo de respuesta.
        $emailOk    = hash_equals(strtolower($adminEmail), strtolower($email));
        $passwordOk = password_verify($password, $adminHash);

        if (!$emailOk || !$passwordOk) {
            $this->showLogin('Email o contraseña incorrectos.', $email, 401);
            return;
        }

        // Nuevo identificador de sesión al entrar: evita la fijación de sesión.
        session_regenerate_id(true);
        $_SESSION['user'] = ['email' => $adminEmail];
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); // token CSRF nuevo para la sesión iniciada

        $this->redirect('/');
    }

    private function logout(): void
    {
        $_SESSION = [];
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $params['path'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();

        $this->redirect('/login');
    }

    // =========================================================================
    // PROTECCIÓN CSRF
    // =========================================================================

    /** Token CSRF de la sesión; se crea la primera vez que se pide. */
    private function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    private function hasValidCsrfToken(): bool
    {
        $expected = $_SESSION['csrf_token'] ?? '';
        $sent     = $_POST['csrf_token'] ?? '';
        return is_string($expected) && is_string($sent) && $expected !== '' && hash_equals($expected, $sent);
    }

    private function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
