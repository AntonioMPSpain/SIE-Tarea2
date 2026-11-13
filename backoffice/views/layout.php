<?php
/**
 * Vista principal del Sistema de Back-Office ERP & Cuadro de Mando Operativo — Piquantum (Tarea 2)
 * Variables disponibles: $tab, $flash, $metrics, $orders, $orderItemsByOrder, $inventory, $customers, $tickets, $audits, $logs, $currentUser, $csrfToken, $isLocalEnv
 */
$pendingAuditsCount = (int) ($metrics['ai_agent']['pending_proposals'] ?? 0);
$failedLogsCount    = (int) ($metrics['integration']['failed_events'] ?? 0);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Piquantum ERP Back-Office & Cuadro de Mando Operativo — Tarea 2 SIE</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Inter', system-ui, -apple-system, sans-serif; background-color: #0f172a; color: #f1f5f9; }
        .card-glass { background: rgba(30, 41, 59, 0.85); border: 1px solid rgba(148, 163, 184, 0.16); }
        pre { white-space: pre-wrap; word-break: break-word; }
    </style>
</head>
<body class="min-h-screen flex flex-col">

    <!-- AVISO OBLIGATORIO DE PROTOTIPO ACADÉMICO (Apartado 1 del Enunciado) -->
    <div class="bg-amber-500/20 border-b border-amber-500/40 text-amber-200 px-4 py-2 text-xs md:text-sm flex flex-wrap items-center justify-between gap-2">
        <div class="flex items-center gap-2">
            <span class="px-2 py-0.5 rounded bg-amber-500 text-slate-950 font-bold uppercase tracking-wider text-xs">Prototipo Académico</span>
            <span><strong>Sistema Independiente de Back-Office (ERP/CRM + Gobernanza IA)</strong> — Sin actividad comercial real. Datos ficticios de prueba (UCAM · Soluciones Informáticas para la Empresa · Tarea 2).</span>
        </div>
        <?php if ($isLocalEnv): ?>
        <div class="flex items-center gap-2">
            <!-- Solo en desarrollo (APP_ENV=local): vaciar la base de datos para empezar de cero -->
            <form method="POST" action="/" class="inline" onsubmit="return confirm('¿Vaciar la base de datos de desarrollo? Se borran pedidos, clientes, incidencias, propuestas y logs.');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="action" value="reset_before_integration">
                <input type="hidden" name="return_tab" value="<?= htmlspecialchars($tab) ?>">
                <button type="submit" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 text-xs font-medium transition" title="Solo desarrollo: borra pedidos, clientes, incidencias, propuestas y logs">
                    🧹 Vaciar BD (solo desarrollo)
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>

    <!-- CABECERA DEL BACK-OFFICE -->
    <header class="bg-slate-900 border-b border-slate-800 px-6 py-4">
        <div class="max-w-7xl mx-auto flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-11 h-11 rounded-xl bg-gradient-to-br from-rose-600 to-orange-500 flex items-center justify-center text-2xl shadow-lg shadow-rose-600/30">
                    🌶️
                </div>
                <div>
                    <div class="flex items-center gap-2">
                        <h1 class="text-xl font-bold tracking-tight text-white">PIQUANTUM · Back-Office Empresarial</h1>
                        <span class="text-xs px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/30 font-mono">BD Aislada Activa</span>
                    </div>
                    <p class="text-xs text-slate-400">Integrado vía Webhooks / API REST con Canal Digital (<code class="text-rose-400">www.piquantum.onl</code>) + Orquestador <code class="text-amber-300">n8n</code> + Agente IA Supervisado</p>
                </div>
            </div>

            <!-- NAVEGACIÓN PRINCIPAL -->
            <nav class="flex flex-wrap gap-1.5">
                <?php
                $navItems = [
                    'dashboard'     => ['📊 Cuadro de Mando (Métricas)', null],
                    'orders'        => ['📦 Pedidos (Esc. 1 y 2)', count($orders)],
                    'inventory'     => ['🏭 Almacén y Stock (Esc. 2)', count($inventory)],
                    'customers'     => ['👥 Clientes (CRM)', count($customers)],
                    'ai_governance' => ['🤖 Postventa + Agente IA (Esc. 3)', $pendingAuditsCount > 0 ? "{$pendingAuditsCount} pend." : null],
                    'logs'          => ['🛡️ Trazabilidad y Logs', count($logs)],
                    'simulator'     => ['🧪 Simulador / API Contracts', null],
                ];
                foreach ($navItems as $key => [$label, $badge]):
                    $active = ($tab === $key);
                ?>
                    <a href="/?tab=<?= $key ?>"
                       class="px-3.5 py-2 rounded-lg text-xs md:text-sm font-medium transition flex items-center gap-2 <?= $active ? 'bg-rose-600 text-white shadow-md shadow-rose-600/30' : 'bg-slate-800/80 text-slate-300 hover:bg-slate-700' ?>">
                        <span><?= $label ?></span>
                        <?php if ($badge !== null): ?>
                            <span class="px-1.5 py-0.5 text-[11px] rounded-full <?= $active ? 'bg-white/20 text-white' : 'bg-slate-700 text-amber-300' ?>">
                                <?= htmlspecialchars((string) $badge) ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <!-- SESIÓN DEL OPERADOR -->
            <div class="flex items-center gap-2 text-xs text-slate-400">
                <span>👤 <?= htmlspecialchars((string) ($currentUser['email'] ?? '')) ?></span>
                <form method="POST" action="/logout" class="inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <button type="submit" class="px-2.5 py-1 rounded bg-slate-800 hover:bg-slate-700 border border-slate-600 text-slate-200 font-medium transition">
                        Cerrar sesión
                    </button>
                </form>
            </div>
        </div>
    </header>

    <!-- CONTENIDO PRINCIPAL -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-6 py-6 space-y-6">

        <?php if ($flash): ?>
            <?php
            $alertColors = [
                'success' => 'bg-emerald-500/15 border-emerald-500/40 text-emerald-200',
                'warning' => 'bg-amber-500/15 border-amber-500/40 text-amber-200',
                'danger'  => 'bg-rose-500/15 border-rose-500/40 text-rose-200',
            ];
            $cls = $alertColors[$flash['type'] ?? 'success'] ?? $alertColors['success'];
            ?>
            <div class="p-4 rounded-xl border <?= $cls ?> flex items-start justify-between gap-4">
                <div>
                    <div class="font-bold text-sm"><?= htmlspecialchars($flash['title']) ?></div>
                    <div class="text-xs md:text-sm mt-0.5 opacity-90"><?= htmlspecialchars($flash['message']) ?></div>
                </div>
                <span class="text-xs font-mono opacity-75"><?= date('H:i:s') ?></span>
            </div>
        <?php endif; ?>

        <?php if ($tab === 'dashboard'): ?>
            <!-- =========================================================================
                 PESTAÑA 1: CUADRO DE MANDO OPERATIVO Y MÉTRICAS (APARTADO 7)
                 ========================================================================= -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-white">Cuadro de Mando Operativo en Tiempo Real (Apartado 7)</h2>
                    <p class="text-xs text-slate-400">Indicadores calculados dinámicamente desde las tablas operativas del Back-Office, logs de n8n y auditorías del Agente de IA (0% CSV estáticos).</p>
                </div>

                <div class="flex flex-wrap items-center gap-2 self-start">
                    <a href="/?export=metrics_json" class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/40 text-emerald-300 font-medium transition">
                        📥 Exportar Métricas (JSON)
                    </a>
                    <a href="/?export=logs_csv" class="text-xs px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 border border-slate-700 text-sky-300 font-medium transition">
                        📥 Exportar Trazabilidad (CSV)
                    </a>
                </div>
            </div>

            <!-- 8 TARJETAS DE MÉTRICAS (El enunciado exige mínimo 5) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <!-- Métrica 1: Número de pedidos -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">1. Pedidos Integrados</div>
                    <div class="text-3xl font-extrabold text-white mt-1"><?= $metrics['orders']['total_count'] ?></div>
                    <div class="text-xs text-emerald-400 mt-1">Ticket medio: <?= number_format($metrics['orders']['avg_ticket'], 2, ',', '.') ?> €</div>
                </div>

                <!-- Métrica 2: Importe total simulado -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">2. Importe Total Simulado</div>
                    <div class="text-3xl font-extrabold text-rose-400 mt-1"><?= number_format($metrics['orders']['total_revenue'], 2, ',', '.') ?> €</div>
                    <div class="text-xs text-slate-400 mt-1">IVA 21% incluido · Redsys / Transf.</div>
                </div>

                <!-- Métrica 3: Eventos correctos vs fallidos -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">3. Ejecuciones Integración</div>
                    <div class="flex items-baseline gap-2 mt-1">
                        <span class="text-2xl font-extrabold text-emerald-400"><?= $metrics['integration']['success_events'] ?> OK</span>
                        <span class="text-sm text-slate-500">/</span>
                        <span class="text-xl font-bold text-rose-400"><?= $metrics['integration']['failed_events'] ?> Fallos</span>
                    </div>
                    <div class="text-xs text-slate-400 mt-1">Latencia media: <?= $metrics['integration']['avg_latency_ms'] ?> ms</div>
                </div>

                <!-- Métrica 4: Tasa de conversión del flujo -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">4. Tasa Conversión Flujo</div>
                    <?php $paidPct = $metrics['conversion']['integrated_to_paid_pct']; $ticketPct = $metrics['conversion']['paid_with_ticket_pct']; ?>
                    <div class="text-3xl font-extrabold text-sky-400 mt-1"><?= $paidPct === null ? '—' : number_format($paidPct, 1, ',', '.') . ' %' ?></div>
                    <div class="text-xs text-slate-400 mt-1">Pedido integrado → pagado (con incidencia: <?= $ticketPct === null ? '—' : number_format($ticketPct, 1, ',', '.') . ' %' ?>)</div>
                </div>

                <!-- Métrica 5: Incidencias abiertas vs cerradas -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">5. Incidencias Postventa</div>
                    <div class="flex items-baseline gap-2 mt-1">
                        <span class="text-2xl font-extrabold text-amber-400"><?= $metrics['tickets']['open'] ?> Abiertas</span>
                        <span class="text-xs text-emerald-400">(<?= $metrics['tickets']['resolved'] ?> resueltas)</span>
                    </div>
                    <div class="text-xs text-slate-400 mt-1">Integradas con Bot de Telegram</div>
                </div>

                <!-- Métrica 6: Tiempo medio real de resolución (resolved_at − created_at) -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">6. Tiempo Medio Resolución</div>
                    <?php $mttr = $metrics['tickets']['avg_resolution_minutes']; ?>
                    <div class="text-3xl font-extrabold text-purple-400 mt-1"><?= $mttr === null ? '—' : number_format($mttr, 1, ',', '.') . ' min' ?></div>
                    <div class="text-xs text-slate-400 mt-1">Desde la apertura hasta la notificación confirmada<?= $mttr === null ? ' (sin incidencias resueltas)' : '' ?></div>
                </div>

                <!-- Métrica 7: Acciones propuestas por el Agente IA vs Aprobadas -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">7. Gobernanza Agente IA</div>
                    <div class="flex items-baseline gap-2 mt-1">
                        <span class="text-2xl font-extrabold text-white"><?= $metrics['ai_agent']['total_proposals'] ?> Prop.</span>
                        <span class="text-sm font-bold text-emerald-400">→ <?= $metrics['ai_agent']['approved_proposals'] ?> Aprob.</span>
                    </div>
                    <div class="text-xs text-amber-300 mt-1"><?= $metrics['ai_agent']['pending_proposals'] ?> pendientes de supervisión humana</div>
                </div>

                <!-- Métrica 8: Alertas de Stock Bajo en Almacén -->
                <div class="card-glass rounded-xl p-4">
                    <div class="text-xs text-slate-400 font-medium uppercase">8. Alertas Stock Crítico</div>
                    <div class="text-3xl font-extrabold <?= $metrics['inventory']['low_stock_alerts'] > 0 ? 'text-amber-400' : 'text-emerald-400' ?> mt-1">
                        <?= $metrics['inventory']['low_stock_alerts'] ?> SKUs
                    </div>
                    <div class="text-xs text-slate-400 mt-1">Por debajo del umbral de seguridad</div>
                </div>
            </div>

            <!-- REPRESENTACIONES GRÁFICAS (Mínimo 1 exigida, incluimos 4 interactivas) -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Gráfico 1: Diagrama de barras con el histórico del número de pedidos (Explícitamente pedido en Apartado 7) -->
                <div class="card-glass rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-bold text-white">📊 Histórico del Número de Pedidos e Importe por Día</h3>
                        <span class="text-[11px] text-slate-400 font-mono">Apartado 7.a y 7.b</span>
                    </div>
                    <div class="h-64">
                        <canvas id="chartOrdersByDay"></canvas>
                    </div>
                </div>

                <!-- Gráfico 2: Productos más solicitados (Apartado 7.c) -->
                <div class="card-glass rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-bold text-white">🌶️ Salsas Picantes más Solicitadas (Unidades sincronizadas)</h3>
                        <span class="text-[11px] text-slate-400 font-mono">Apartado 7.c</span>
                    </div>
                    <div class="h-64">
                        <canvas id="chartTopProducts"></canvas>
                    </div>
                </div>

                <!-- Gráfico 3: Pedidos por estado (Apartado 7.d) -->
                <div class="card-glass rounded-xl p-5">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-bold text-white">📦 Distribución de Pedidos por Estado Operativo</h3>
                        <span class="text-[11px] text-slate-400 font-mono">Apartado 7.d</span>
                    </div>
                    <div class="h-60 flex items-center justify-center">
                        <canvas id="chartOrdersByStatus"></canvas>
                    </div>
                </div>

                <!-- Gráfico 4: Errores de Integración por Tipo y Embudo (Apartado 7.g, 7.h, 7.i) -->
                <div class="card-glass rounded-xl p-5 flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="text-sm font-bold text-white">🛡️ Desglose de Errores por Tipo y Embudo de Conversión</h3>
                            <span class="text-[11px] text-slate-400 font-mono">Apartado 7.g / 7.h / 7.i</span>
                        </div>
                        <div class="space-y-2.5 mt-2">
                            <?php
                            // Solo etapas con datos reales del Back-Office (pedidos integrados por n8n y sus incidencias)
                            $funnel = $metrics['conversion']['funnel'];
                            $base = (int) $funnel['orders_integrated'];
                            $funnelSteps = [
                                ['orders_integrated', '1. Pedidos integrados en Back-Office', 'bg-sky-500'],
                                ['orders_paid', '2. Pedidos pagados (Redsys sandbox)', 'bg-emerald-500'],
                                ['orders_with_ticket', '3. Pedidos con incidencia postventa', 'bg-amber-500'],
                            ];
                            foreach ($funnelSteps as [$fKey, $fLabel, $barColor]):
                                $val = (int) ($funnel[$fKey] ?? 0);
                                $pct = $base > 0 ? min(100, round(($val / $base) * 100, 1)) : 0;
                            ?>
                                <div>
                                    <div class="flex justify-between text-xs mb-1">
                                        <span class="text-slate-300"><?= $fLabel ?></span>
                                        <span class="font-mono font-bold text-white"><?= $val ?> pedidos<?= $base > 0 ? " ({$pct}%)" : '' ?></span>
                                    </div>
                                    <div class="w-full h-2 bg-slate-800 rounded-full overflow-hidden">
                                        <div class="h-full <?= $barColor ?>" style="width: <?= $pct ?>%"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-slate-800">
                        <div class="text-xs font-semibold text-slate-300 mb-2">Errores de Validación / Integración Capturados por Tipo:</div>
                        <?php if (empty($metrics['integration']['errors_by_type'])): ?>
                            <div class="text-xs text-slate-500">Sin errores registrados en este momento.</div>
                        <?php else: ?>
                            <div class="flex flex-wrap gap-2">
                                <?php foreach ($metrics['integration']['errors_by_type'] as $errRow): ?>
                                    <span class="px-2.5 py-1 rounded-lg bg-rose-500/15 border border-rose-500/30 text-rose-300 text-xs font-mono">
                                        <?= htmlspecialchars($errRow['error_type']) ?>: <strong><?= (int) $errRow['total'] ?></strong>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <script>
                const ordersByDay = <?= json_encode($metrics['orders']['by_day']) ?>;
                const topProducts = <?= json_encode($metrics['top_products']) ?>;
                const ordersByStatus = <?= json_encode($metrics['orders']['by_status']) ?>;

                // 1. Gráfico de barras Histórico de Pedidos
                new Chart(document.getElementById('chartOrdersByDay'), {
                    type: 'bar',
                    data: {
                        labels: ordersByDay.length ? ordersByDay.map(r => r.order_date) : ['Sin datos'],
                        datasets: [
                            {
                                label: 'Nº de Pedidos',
                                data: ordersByDay.length ? ordersByDay.map(r => Number(r.orders_count)) : [0],
                                backgroundColor: 'rgba(244, 63, 94, 0.75)',
                                borderColor: '#f43f5e',
                                borderWidth: 1,
                                borderRadius: 6,
                                yAxisID: 'y'
                            },
                            {
                                label: 'Importe (€)',
                                data: ordersByDay.length ? ordersByDay.map(r => Number(r.day_revenue)) : [0],
                                type: 'line',
                                borderColor: '#38bdf8',
                                backgroundColor: 'rgba(56, 189, 248, 0.2)',
                                tension: 0.3,
                                yAxisID: 'y1'
                            }
                        ]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { labels: { color: '#cbd5e1' } } },
                        scales: {
                            x: { ticks: { color: '#94a3b8' }, grid: { color: 'rgba(148,163,184,0.1)' } },
                            y: { beginAtZero: true, position: 'left', ticks: { color: '#f43f5e', stepSize: 1 }, grid: { color: 'rgba(148,163,184,0.1)' } },
                            y1: { beginAtZero: true, position: 'right', ticks: { color: '#38bdf8' }, grid: { drawOnChartArea: false } }
                        }
                    }
                });

                // 2. Gráfico Top Productos
                new Chart(document.getElementById('chartTopProducts'), {
                    type: 'bar',
                    data: {
                        labels: topProducts.length ? topProducts.map(p => p.sku) : ['Sin ventas'],
                        datasets: [{
                            label: 'Unidades solicitadas',
                            data: topProducts.length ? topProducts.map(p => Number(p.total_units)) : [0],
                            backgroundColor: ['rgba(249,115,22,0.8)', 'rgba(239,68,68,0.8)', 'rgba(234,179,8,0.8)', 'rgba(16,185,129,0.8)', 'rgba(59,130,246,0.8)', 'rgba(168,85,247,0.8)'],
                            borderRadius: 6
                        }]
                    },
                    options: {
                        indexAxis: 'y',
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { beginAtZero: true, ticks: { color: '#94a3b8', stepSize: 1 }, grid: { color: 'rgba(148,163,184,0.1)' } },
                            y: { ticks: { color: '#e2e8f0', font: { size: 11 } }, grid: { display: false } }
                        }
                    }
                });

                // 3. Gráfico Donut Pedidos por Estado
                new Chart(document.getElementById('chartOrdersByStatus'), {
                    type: 'doughnut',
                    data: {
                        labels: ordersByStatus.length ? ordersByStatus.map(s => s.order_status.toUpperCase()) : ['SIN PEDIDOS'],
                        datasets: [{
                            data: ordersByStatus.length ? ordersByStatus.map(s => Number(s.total)) : [1],
                            backgroundColor: ['#10b981', '#f59e0b', '#f43f5e', '#38bdf8', '#a855f7', '#64748b'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { position: 'right', labels: { color: '#cbd5e1', font: { size: 11 } } } }
                    }
                });
            </script>

        <?php elseif ($tab === 'orders'): ?>
            <!-- =========================================================================
                 PESTAÑA 2: PEDIDOS INTEGRADOS Y ESCENARIO 2 (CAMBIO OPERATIVO)
                 ========================================================================= -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-white">Pedidos Registrados en el Back-Office (Escenarios 1 y 2)</h2>
                    <p class="text-xs text-slate-400">Cada pedido incluye referencia única, cliente de prueba, líneas de pedido, importe, estado y fecha. Puedes modificar el estado o la prioridad para disparar la sincronización hacia n8n/eCommerce (Escenario 2).</p>
                </div>
                <span class="text-xs font-mono text-emerald-400 bg-emerald-500/10 px-3 py-1.5 rounded-lg border border-emerald-500/30">
                    Endpoint recepción: POST /api/v1/webhooks/orders
                </span>
            </div>

            <!-- Buscador y Filtro de Pedidos -->
            <form method="GET" action="/" class="card-glass rounded-xl p-3.5 flex flex-wrap items-center justify-between gap-3">
                <input type="hidden" name="tab" value="orders">
                <div class="flex flex-wrap items-center gap-2 flex-1">
                    <input type="text" name="q" value="<?= htmlspecialchars((string) ($searchFilter ?? '')) ?>"
                           placeholder="🔎 Buscar por referencia (PQ-...), cliente o email..."
                           class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white w-full sm:w-72">
                    <select name="order_status" class="bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white">
                        <option value="">Todos los estados</option>
                        <?php foreach (['pendiente_preparacion', 'en_preparacion', 'enviado', 'entregado', 'incidencia', 'cancelado'] as $stOpt): ?>
                            <option value="<?= $stOpt ?>" <?= ($orderStatusFilter ?? '') === $stOpt ? 'selected' : '' ?>>Estado: <?= $stOpt ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="order_priority" class="bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white">
                        <option value="">Todas las prioridades</option>
                        <?php foreach (['NORMAL', 'ALTA', 'URGENTE'] as $prOpt): ?>
                            <option value="<?= $prOpt ?>" <?= ($orderPriorityFilter ?? '') === $prOpt ? 'selected' : '' ?>>Prioridad: <?= $prOpt ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold transition">
                        Filtrar pedidos
                    </button>
                    <?php if (!empty($searchFilter) || !empty($orderStatusFilter) || !empty($orderPriorityFilter)): ?>
                        <a href="/?tab=orders" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition">
                            Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($orders)): ?>
                <div class="card-glass rounded-xl p-10 text-center space-y-3">
                    <div class="text-4xl">📭</div>
                    <h3 class="text-base font-bold text-white">Back-Office sin pedidos integrados (Estado "Antes de la Integración")</h3>
                    <p class="text-xs text-slate-400 max-w-lg mx-auto">Esta vista sirve como evidencia previa a la ejecución del flujo de n8n. Los pedidos aparecen aquí cuando llegan desde la tienda a través de n8n (no hay datos de demostración).</p>
                </div>
            <?php else: ?>
                <div class="space-y-4">
                    <?php foreach ($orders as $ord):
                        $items = $orderItemsByOrder[(int) $ord['id']] ?? [];
                        $statusBadge = match ($ord['order_status']) {
                            'enviado'               => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
                            'pendiente_preparacion' => 'bg-amber-500/20 text-amber-300 border-amber-500/40',
                            'incidencia'            => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                            'cancelado'             => 'bg-slate-600/30 text-slate-300 border-slate-500/40',
                            default                 => 'bg-sky-500/20 text-sky-300 border-sky-500/40',
                        };
                    ?>
                        <div class="card-glass rounded-xl p-5 space-y-4">
                            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-slate-800 pb-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-mono font-bold text-base text-rose-400"><?= htmlspecialchars($ord['order_ref']) ?></span>
                                        <span class="px-2.5 py-0.5 text-xs rounded-full border font-semibold uppercase <?= $statusBadge ?>">
                                            <?= htmlspecialchars($ord['order_status']) ?>
                                        </span>
                                        <span class="px-2 py-0.5 text-[11px] rounded bg-slate-800 text-slate-300 font-mono">
                                            Prioridad: <?= htmlspecialchars($ord['priority']) ?>
                                        </span>
                                        <span class="px-2 py-0.5 text-[11px] rounded bg-sky-500/10 text-sky-300 font-mono">
                                            Sync: <?= htmlspecialchars($ord['sync_status']) ?>
                                        </span>
                                    </div>
                                    <div class="text-xs text-slate-400 mt-1">
                                        👤 <strong><?= htmlspecialchars($ord['customer_name']) ?></strong> (<code><?= htmlspecialchars($ord['customer_email']) ?></code>) ·
                                        📍 <?= htmlspecialchars((string) $ord['shipping_address']) ?> ·
                                        🕒 Fecha pedido: <?= htmlspecialchars($ord['channel_created_at']) ?>
                                    </div>
                                </div>

                                <div class="text-right">
                                    <div class="text-xl font-extrabold text-white"><?= number_format((float) $ord['total_amount'], 2, ',', '.') ?> €</div>
                                    <div class="text-[11px] text-slate-400 font-mono">Pago: <?= htmlspecialchars($ord['payment_method']) ?> (<?= htmlspecialchars($ord['payment_status']) ?>)</div>
                                </div>
                            </div>

                            <!-- Líneas del pedido -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-xs text-left">
                                    <thead class="text-slate-400 border-b border-slate-800">
                                        <tr>
                                            <th class="py-1.5">SKU</th>
                                            <th class="py-1.5">Producto (Salsa Picante)</th>
                                            <th class="py-1.5">Formato</th>
                                            <th class="py-1.5 text-right">Cantidad</th>
                                            <th class="py-1.5 text-right">Precio Ud.</th>
                                            <th class="py-1.5 text-right">Subtotal Línea</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-800/60">
                                        <?php foreach ($items as $line): ?>
                                            <tr>
                                                <td class="py-2 font-mono text-amber-300"><?= htmlspecialchars($line['sku']) ?></td>
                                                <td class="py-2 text-slate-200 font-medium"><?= htmlspecialchars($line['product_name']) ?></td>
                                                <td class="py-2 text-slate-400"><?= htmlspecialchars((string) ($line['variant_size'] ?? '—')) ?></td>
                                                <td class="py-2 text-right font-mono"><?= (int) $line['quantity'] ?></td>
                                                <td class="py-2 text-right font-mono"><?= number_format((float) $line['unit_price'], 2, ',', '.') ?> €</td>
                                                <td class="py-2 text-right font-mono font-semibold text-white"><?= number_format((float) $line['line_total'], 2, ',', '.') ?> €</td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Formulario de Cambio Operativo / Sincronización (Escenario 2) -->
                            <form method="POST" action="/" class="bg-slate-900/90 rounded-lg p-3 border border-slate-800 flex flex-wrap items-center justify-between gap-3">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="action" value="update_order_status">
                                <input type="hidden" name="order_ref" value="<?= htmlspecialchars($ord['order_ref']) ?>">
                                <div class="text-xs text-slate-300 font-medium">
                                    🔄 <strong>Escenario 2 (Cambio Operativo y Sincronización):</strong> Modificar estado o prioridad desde Back-Office:
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <select name="new_status" class="bg-slate-800 border border-slate-700 rounded px-2.5 py-1 text-xs text-white">
                                        <?php foreach (array_merge([$ord['order_status']], \Piquantum\BackOffice\Services\IntegrationService::ORDER_TRANSITIONS[$ord['order_status']] ?? []) as $st): ?>
                                            <option value="<?= $st ?>" <?= $ord['order_status'] === $st ? 'selected' : '' ?>>Estado: <?= $st ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select name="priority" class="bg-slate-800 border border-slate-700 rounded px-2.5 py-1 text-xs text-white">
                                        <?php foreach (['NORMAL', 'ALTA', 'URGENTE'] as $pr): ?>
                                            <option value="<?= $pr ?>" <?= $ord['priority'] === $pr ? 'selected' : '' ?>>Prioridad: <?= $pr ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="px-3 py-1 rounded bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold transition">
                                        Aplicar y Sincronizar con n8n
                                    </button>
                                </div>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'inventory'): ?>
            <!-- =========================================================================
                 PESTAÑA 3: ALMACÉN Y STOCK SINCRONIZADO (ESCENARIO 2)
                 ========================================================================= -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-white">Control de Stock y Disponibilidad en Almacén (Escenario 2 + Tool Agente IA)</h2>
                    <p class="text-xs text-slate-400">El Agente de IA consulta esta tabla mediante la herramienta <code class="text-amber-300">GET /api/v1/agent/inventory/{sku}</code> antes de proponer una reposición.</p>
                </div>
            </div>

                <?php
                $lowStockItems = array_filter($inventory, static fn($i) => ((int) $i['stock_available']) <= ((int) $i['min_stock_threshold']));
                if (!empty($lowStockItems)):
                ?>
                    <div class="p-4 rounded-xl bg-amber-500/15 border border-amber-500/40 text-amber-200 text-xs flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <strong>⚠️ Alerta de Reposición de Almacén:</strong> Hay <?= count($lowStockItems) ?> referencia(s) en nivel crítico o bajo umbral de seguridad:
                            <?php foreach ($lowStockItems as $ls): ?>
                                <span class="inline-block ml-1.5 px-2 py-0.5 rounded bg-amber-500/20 font-mono font-bold text-amber-300">
                                    <?= htmlspecialchars($ls['sku']) ?> (<?= (int) $ls['stock_available'] ?>/<?= (int) $ls['min_stock_threshold'] ?> uds)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

            <div class="card-glass rounded-xl overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-xs text-left">
                        <thead class="bg-slate-900/90 text-slate-400 uppercase border-b border-slate-800">
                            <tr>
                                <th class="p-3">SKU</th>
                                <th class="p-3">Salsa Picante</th>
                                <th class="p-3">Categoría / Formato</th>
                                <th class="p-3">Ubicación</th>
                                <th class="p-3 text-right">Precio</th>
                                <th class="p-3 text-center">Stock Actual</th>
                                <th class="p-3 text-center">Estado Sincronización</th>
                                <th class="p-3 text-right">Ajuste Operativo (Escenario 2)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-800">
                            <?php foreach ($inventory as $item):
                                $low = ((int) $item['stock_available']) <= ((int) $item['min_stock_threshold']);
                            ?>
                                <tr class="hover:bg-slate-800/40">
                                    <td class="p-3 font-mono font-bold text-amber-300"><?= htmlspecialchars($item['sku']) ?></td>
                                    <td class="p-3 font-medium text-white">
                                        <?= htmlspecialchars($item['product_name']) ?>
                                        <span class="ml-1 text-rose-400"><?= str_repeat('🌶️', (int) $item['heat_level']) ?></span>
                                    </td>
                                    <td class="p-3 text-slate-300"><?= htmlspecialchars($item['category']) ?> · <span class="font-mono"><?= htmlspecialchars((string) ($item['variant_size'] ?? 'tamaño único')) ?></span></td>
                                    <td class="p-3 font-mono text-slate-400"><?= htmlspecialchars($item['warehouse_location']) ?></td>
                                    <td class="p-3 text-right font-mono"><?= number_format((float) $item['unit_price'], 2, ',', '.') ?> €</td>
                                    <td class="p-3 text-center">
                                        <span class="px-2.5 py-1 rounded-full font-mono font-bold <?= $low ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-emerald-500/20 text-emerald-300' ?>">
                                            <?= (int) $item['stock_available'] ?> uds <?= $low ? '⚠️ BAJO' : '' ?>
                                        </span>
                                    </td>
                                    <td class="p-3 text-center font-mono text-[11px] text-sky-300"><?= htmlspecialchars($item['sync_status']) ?></td>
                                    <td class="p-3 text-right">
                                        <form method="POST" action="/" class="inline-flex items-center gap-1.5">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                            <input type="hidden" name="action" value="update_inventory_stock">
                                            <input type="hidden" name="sku" value="<?= htmlspecialchars($item['sku']) ?>">
                                            <input type="number" name="new_stock" min="0" max="500" value="<?= (int) $item['stock_available'] ?>"
                                                   class="w-16 bg-slate-900 border border-slate-700 rounded px-2 py-1 text-right font-mono text-white">
                                            <button type="submit" class="px-2.5 py-1 rounded bg-slate-700 hover:bg-sky-600 text-white font-medium transition">
                                                Sincronizar
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        <?php elseif ($tab === 'customers'): ?>
            <!-- =========================================================================
                 PESTAÑA 3B: FICHA DE CLIENTES SINCRONIZADOS (CRM / erp_customers)
                 ========================================================================= -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-white">Directorio de Clientes Sincronizados y Segmentación CRM (`erp_customers`)</h2>
                    <p class="text-xs text-slate-400">Clientes creados o actualizados automáticamente al integrar pedidos (Escenario 1). El Agente de IA consulta el segmento (<code class="text-amber-300">VIP_PICANTE</code> / <code class="text-sky-300">ESTANDAR</code>) y el historial de incidencias para priorizar casos postventa.</p>
                </div>
            </div>

            <!-- Buscador y Filtro de Clientes -->
            <form method="GET" action="/" class="card-glass rounded-xl p-3.5 flex flex-wrap items-center justify-between gap-3">
                <input type="hidden" name="tab" value="customers">
                <div class="flex flex-wrap items-center gap-2 flex-1">
                    <input type="text" name="q" value="<?= htmlspecialchars((string) ($searchFilter ?? '')) ?>"
                           placeholder="🔎 Buscar cliente por nombre, email o ID externo..."
                           class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white w-full sm:w-72">
                    <select name="customer_segment" class="bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white">
                        <option value="">Todos los segmentos</option>
                        <option value="VIP_PICANTE" <?= ($customerSegFilter ?? '') === 'VIP_PICANTE' ? 'selected' : '' ?>>Segmento: VIP_PICANTE (≥ 60 €)</option>
                        <option value="ESTANDAR" <?= ($customerSegFilter ?? '') === 'ESTANDAR' ? 'selected' : '' ?>>Segmento: ESTANDAR</option>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold transition">
                        Filtrar clientes
                    </button>
                    <?php if (!empty($searchFilter) || !empty($customerSegFilter)): ?>
                        <a href="/?tab=customers" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition">
                            Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <?php if (empty($customers)): ?>
                <div class="card-glass rounded-xl p-10 text-center space-y-2">
                    <div class="text-4xl">👥</div>
                    <h3 class="text-base font-bold text-white">Sin clientes sincronizados con los criterios actuales</h3>
                    <p class="text-xs text-slate-400">Cuando n8n integra pedidos desde Piquantum, la ficha del cliente se crea o actualiza automáticamente en el Back-Office.</p>
                </div>
            <?php else: ?>
                <div class="card-glass rounded-xl overflow-hidden">
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-900/90 text-slate-400 uppercase border-b border-slate-800">
                                <tr>
                                    <th class="p-3">ID Externo</th>
                                    <th class="p-3">Cliente</th>
                                    <th class="p-3">Contacto / Dirección</th>
                                    <th class="p-3 text-center">Segmento CRM</th>
                                    <th class="p-3 text-center">Pedidos</th>
                                    <th class="p-3 text-right">Gasto Acumulado</th>
                                    <th class="p-3 text-center">Incidencias</th>
                                    <th class="p-3 text-right">Último Pedido</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-800">
                                <?php foreach ($customers as $cust):
                                    $isVip = ($cust['segment'] === 'VIP_PICANTE');
                                ?>
                                    <tr class="hover:bg-slate-800/40">
                                        <td class="p-3 font-mono text-amber-300"><?= htmlspecialchars((string) (($cust['external_customer_id'] ?? '') !== '' ? $cust['external_customer_id'] : '—')) ?></td>
                                        <td class="p-3">
                                            <div class="font-bold text-white"><?= htmlspecialchars((string) ($cust['name'] ?? '—')) ?></div>
                                            <div class="font-mono text-slate-400 text-[11px]"><?= htmlspecialchars((string) $cust['email']) ?></div>
                                        </td>
                                        <td class="p-3 text-slate-300">
                                            <div><?= htmlspecialchars((string) ($cust['phone'] ?: '—')) ?></div>
                                            <div class="text-[11px] text-slate-400"><?= htmlspecialchars((string) (($cust['shipping_address'] ?? '') !== '' ? $cust['shipping_address'] : '—')) ?></div>
                                        </td>
                                        <td class="p-3 text-center">
                                            <span class="px-2.5 py-0.5 rounded-full font-mono font-bold text-[11px] <?= $isVip ? 'bg-rose-500/20 text-rose-300 border border-rose-500/40' : 'bg-slate-800 text-slate-300' ?>">
                                                <?= $isVip ? '🔥 VIP_PICANTE' : 'ESTANDAR' ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-center font-mono font-bold text-white"><?= (int) $cust['orders_count'] ?></td>
                                        <td class="p-3 text-right font-mono font-bold text-emerald-400"><?= number_format((float) $cust['total_spent'], 2, ',', '.') ?> €</td>
                                        <td class="p-3 text-center">
                                            <span class="px-2 py-0.5 rounded font-mono <?= ((int) ($cust['tickets_count'] ?? 0)) > 0 ? 'bg-amber-500/20 text-amber-300' : 'text-slate-500' ?>">
                                                <?= (int) ($cust['tickets_count'] ?? 0) ?>
                                            </span>
                                        </td>
                                        <td class="p-3 text-right font-mono text-slate-400"><?= htmlspecialchars((string) ($cust['last_order_at'] ?? '—')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'ai_governance'): ?>
            <!-- =========================================================================
                 PESTAÑA 4: ESCENARIO 3 + AGENTE DE IA SUPERVISADO Y REVISIÓN HUMANA (APARTADO 6)
                 ========================================================================= -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-white">Incidencias Postventa, Salida Estructurada del Agente IA y Supervisión Humana (Apartados 5 y 6)</h2>
                    <p class="text-xs text-slate-400">El Agente de IA consulta herramientas operativas, genera los <strong>7 campos obligatorios de salida estructurada</strong> y queda bloqueado en <code class="text-amber-300">pendiente_de_revision</code> hasta que un humano aprueba o rechaza el envío por Telegram.</p>
                </div>
                <a href="/?export=audits_csv" class="text-xs px-3 py-1.5 rounded-lg bg-purple-600/20 hover:bg-purple-600/30 border border-purple-500/40 text-purple-300 font-medium self-start transition">
                    📥 Exportar Auditoría IA (CSV)
                </a>
            </div>

            <?php if (empty($audits)): ?>
                <div class="card-glass rounded-xl p-10 text-center space-y-2">
                    <div class="text-4xl">🤖</div>
                    <h3 class="text-base font-bold text-white">Sin propuestas del Agente de IA registradas</h3>
                    <p class="text-xs text-slate-400">Cuando n8n procesa una incidencia postventa, el Agente de IA deposita aquí su diagnóstico estructurado para revisión humana.</p>
                </div>
            <?php else: ?>
                <div class="space-y-6">
                    <?php foreach ($audits as $audit):
                        $isPending  = ($audit['final_status'] === 'pendiente_de_revision');
                        $isApproved = ($audit['final_status'] === 'aprobado');
                        $statusStyle = match ($audit['final_status']) {
                            'aprobado'              => 'bg-emerald-500/20 text-emerald-300 border-emerald-500/40',
                            'rechazado'             => 'bg-rose-500/20 text-rose-300 border-rose-500/40',
                            default                 => 'bg-amber-500/20 text-amber-300 border-amber-500/40 animate-pulse',
                        };
                    ?>
                        <div class="card-glass rounded-xl p-5 border-l-4 <?= $isPending ? 'border-l-amber-400' : ($isApproved ? 'border-l-emerald-500' : 'border-l-rose-500') ?> space-y-4">
                            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-3 border-b border-slate-800 pb-3">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs font-mono px-2 py-0.5 rounded bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                            Auditoría IA #<?= (int) $audit['id'] ?>
                                        </span>
                                        <span class="font-mono font-bold text-sm text-white">Ticket: <?= htmlspecialchars($audit['ticket_ref']) ?></span>
                                        <?php if ($audit['order_ref']): ?>
                                            <span class="font-mono text-xs text-rose-400">(Pedido: <?= htmlspecialchars($audit['order_ref']) ?>)</span>
                                        <?php endif; ?>
                                        <span class="text-xs text-slate-400">· Modelo: <code><?= htmlspecialchars($audit['agent_model']) ?></code></span>
                                    </div>
                                    <div class="text-xs text-slate-400 mt-1">
                                        🔧 <strong>Herramientas invocadas por el Agente:</strong> <code class="text-sky-300"><?= htmlspecialchars($audit['tools_invoked']) ?></code>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="px-3 py-1 rounded-full text-xs font-bold uppercase border <?= $statusStyle ?>">
                                        g. Estado final: <?= htmlspecialchars($audit['final_status']) ?>
                                    </span>
                                </div>
                            </div>

                            <!-- LOS 7 CAMPOS OBLIGATORIOS DE LA SALIDA ESTRUCTURADA DEL AGENTE (Apartado 6) -->
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 text-xs">
                                <div class="space-y-3 bg-slate-900/70 p-4 rounded-lg border border-slate-800">
                                    <div>
                                        <span class="text-slate-400 font-semibold uppercase block">a. Tipo de caso detectado:</span>
                                        <span class="font-mono text-sm font-bold text-amber-300"><?= htmlspecialchars($audit['detected_case_type']) ?></span>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 font-semibold uppercase block">c. Acción propuesta (Herramienta de acción):</span>
                                        <span class="font-mono text-sm font-bold text-emerald-400"><?= htmlspecialchars($audit['proposed_action']) ?></span>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 font-semibold uppercase block">d. Justificación breve:</span>
                                        <p class="text-slate-200 mt-0.5"><?= htmlspecialchars($audit['justification']) ?></p>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 font-semibold uppercase block">e. Nivel de confianza o prioridad:</span>
                                        <span class="font-mono text-sky-300"><?= htmlspecialchars($audit['confidence_and_priority']) ?></span>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 font-semibold uppercase block">f. Riesgos o advertencias:</span>
                                        <p class="text-rose-300 mt-0.5">⚠️ <?= htmlspecialchars($audit['risks_and_warnings']) ?></p>
                                    </div>
                                </div>

                                <div class="space-y-3 bg-slate-900/70 p-4 rounded-lg border border-slate-800">
                                    <div>
                                        <span class="text-slate-400 font-semibold uppercase block mb-1">b. Datos consultados por el Agente (Tools Output):</span>
                                        <pre class="bg-slate-950 p-2.5 rounded border border-slate-800 font-mono text-[11px] text-emerald-300 overflow-x-auto"><?= htmlspecialchars($audit['consulted_data_json']) ?></pre>
                                    </div>
                                    <div>
                                        <span class="text-slate-400 font-semibold uppercase block mb-1">💬 Borrador de notificación para el cliente en Telegram:</span>
                                        <div class="bg-sky-950/40 border border-sky-500/30 rounded p-2.5 text-sky-100 italic">
                                            "<?= htmlspecialchars($audit['proposed_customer_message']) ?>"
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- PANEL DE REVISIÓN HUMANA (Human-in-the-loop) -->
                            <?php if ($isPending): ?>
                                <form method="POST" action="/" class="bg-amber-500/10 border border-amber-500/30 rounded-lg p-4 flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                    <input type="hidden" name="action" value="review_ai_proposal">
                                    <input type="hidden" name="audit_id" value="<?= (int) $audit['id'] ?>">
                                    <div class="text-xs text-amber-200">
                                        🛑 <strong>Supervisión Humana Obligatoria:</strong> El agente tiene prohibido enviar la notificación a Telegram o comprometer reposición de stock sin tu aprobación explícita.
                                    </div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-xs text-slate-300" title="El revisor es el operador con sesión iniciada">
                                            👤 Revisa: <strong><?= htmlspecialchars((string) ($currentUser['email'] ?? '')) ?></strong>
                                        </span>
                                        <input type="text" name="review_comment" value="Revisado stock y pedido; se autoriza envío a Telegram."
                                               class="bg-slate-900 border border-slate-700 rounded px-2.5 py-1.5 text-xs text-white w-64" placeholder="Comentario de auditoría">
                                        <button type="submit" name="decision" value="aprobado"
                                                class="px-3.5 py-1.5 rounded bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition">
                                            ✅ Aprobar y Enviar a Telegram
                                        </button>
                                        <button type="submit" name="decision" value="rechazado"
                                                class="px-3.5 py-1.5 rounded bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold transition">
                                            ❌ Rechazar Propuesta
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <div class="bg-slate-900/90 border border-slate-800 rounded-lg p-3 text-xs flex flex-wrap items-center justify-between gap-2 text-slate-300">
                                    <div>
                                        ✔️ <strong>Decisión humana registrada:</strong> Revisado por <strong><?= htmlspecialchars((string) $audit['reviewed_by']) ?></strong> el <?= htmlspecialchars((string) $audit['reviewed_at']) ?> —
                                        <em>"<?= htmlspecialchars((string) $audit['review_comment']) ?>"</em>
                                    </div>
                                    <?php $tgPending = !$audit['telegram_notified'] && $audit['final_status'] === 'aprobado'; ?>
                                    <span class="font-mono text-[11px] <?= $audit['telegram_notified'] ? 'text-sky-400' : ($tgPending ? 'text-amber-400' : 'text-slate-500') ?>">
                                        <?= $audit['telegram_notified'] ? '📲 n8n confirmó la notificación a Telegram' : ($tgPending ? '⚠️ Aprobada: notificación a Telegram no enviada' : '🚫 Sin notificación a Telegram') ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php elseif ($tab === 'logs'): ?>
            <!-- =========================================================================
                 PESTAÑA 5: TRAZABILIDAD TÉCNICA Y FUNCIONAL DE EJECUCIONES (APARTADO 3.e y 3.f)
                 ========================================================================= -->
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2 border-b border-slate-800 pb-4">
                <div>
                    <h2 class="text-lg font-bold text-white">Registro de Trazabilidad y Auditoría de Ejecuciones (Apartado 3.e y 3.f)</h2>
                    <p class="text-xs text-slate-400">Cada intercambio entre Piquantum, n8n, el Back-Office y el Agente de IA queda registrado con su ID de ejecución, payload recibido, validación y resultado (<code class="text-emerald-400">SUCCESS</code> / <code class="text-rose-400">ERROR</code>).</p>
                </div>
                <div class="flex flex-wrap items-center gap-2 self-start">
                    <a href="/?export=logs_csv&amp;log_status=<?= urlencode((string) ($logStatusFilter ?? '')) ?>&amp;log_scenario=<?= urlencode((string) ($logScenarioFilter ?? '')) ?>"
                       class="text-xs px-3 py-1.5 rounded-lg bg-emerald-600/20 hover:bg-emerald-600/30 border border-emerald-500/40 text-emerald-300 font-medium transition">
                        📥 Exportar Logs (CSV)
                    </a>
                    <a href="/?export=logs_json&amp;log_status=<?= urlencode((string) ($logStatusFilter ?? '')) ?>&amp;log_scenario=<?= urlencode((string) ($logScenarioFilter ?? '')) ?>&amp;q=<?= urlencode((string) ($searchFilter ?? '')) ?>"
                       class="text-xs px-3 py-1.5 rounded-lg bg-sky-600/20 hover:bg-sky-600/30 border border-sky-500/40 text-sky-300 font-medium transition">
                        📥 Exportar Logs (JSON)
                    </a>
                </div>
            </div>

            <!-- Buscador y Filtro de Logs de Trazabilidad -->
            <form method="GET" action="/" class="card-glass rounded-xl p-3.5 flex flex-wrap items-center justify-between gap-3">
                <input type="hidden" name="tab" value="logs">
                <div class="flex flex-wrap items-center gap-2 flex-1">
                    <input type="text" name="q" value="<?= htmlspecialchars((string) ($searchFilter ?? '')) ?>"
                           placeholder="🔎 Buscar por ID de ejecución, evento, tipo de error o contenido..."
                           class="bg-slate-900 border border-slate-700 rounded-lg px-3 py-1.5 text-xs text-white w-full sm:w-80">
                    <select name="log_status" class="bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white">
                        <option value="">Todos los resultados</option>
                        <?php foreach (['SUCCESS', 'ERROR', 'DUPLICADO'] as $lSt): ?>
                            <option value="<?= $lSt ?>" <?= ($logStatusFilter ?? '') === $lSt ? 'selected' : '' ?>>Resultado: <?= $lSt ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="log_scenario" class="bg-slate-900 border border-slate-700 rounded-lg px-2.5 py-1.5 text-xs text-white">
                        <option value="">Todos los escenarios</option>
                        <?php foreach (['ESCENARIO_1', 'ESCENARIO_2', 'ESCENARIO_3', 'AGENTE_IA', 'SISTEMA'] as $lSc): ?>
                            <option value="<?= $lSc ?>" <?= ($logScenarioFilter ?? '') === $lSc ? 'selected' : '' ?>>Escenario: <?= $lSc ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <button type="submit" class="px-3 py-1.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-semibold transition">
                        Filtrar logs
                    </button>
                    <?php if (!empty($searchFilter) || !empty($logStatusFilter) || !empty($logScenarioFilter)): ?>
                        <a href="/?tab=logs" class="px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 text-xs transition">
                            Limpiar
                        </a>
                    <?php endif; ?>
                </div>
            </form>

            <div class="space-y-3">
                <?php foreach ($logs as $log):
                    $isOk = in_array($log['status'], ['SUCCESS', 'DUPLICADO'], true); // un duplicado no es un error
                ?>
                    <details class="card-glass rounded-xl p-4 group">
                        <summary class="cursor-pointer list-none flex flex-wrap items-center justify-between gap-3">
                            <div class="flex flex-wrap items-center gap-2.5 text-xs">
                                <span class="px-2.5 py-0.5 rounded-full font-mono font-bold <?= $isOk ? 'bg-emerald-500/20 text-emerald-300 border border-emerald-500/40' : 'bg-rose-500/20 text-rose-300 border border-rose-500/40' ?>">
                                    <?= htmlspecialchars($log['status']) ?>
                                </span>
                                <span class="font-mono text-slate-400"><?= htmlspecialchars($log['execution_id']) ?></span>
                                <span class="px-2 py-0.5 rounded bg-slate-800 text-sky-300 font-mono"><?= htmlspecialchars($log['scenario']) ?></span>
                                <span class="font-mono font-semibold text-white"><?= htmlspecialchars($log['event_type']) ?></span>
                                <?php if ($log['error_type']): ?>
                                    <span class="px-2 py-0.5 rounded bg-rose-500/20 text-rose-300 font-mono"><?= htmlspecialchars($log['error_type']) ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="flex items-center gap-3 text-xs text-slate-400">
                                <span class="font-mono"><?= (int) $log['execution_ms'] ?> ms</span>
                                <span class="font-mono"><?= htmlspecialchars($log['created_at']) ?></span>
                                <span class="text-sky-400 underline">Ver Payload JSON</span>
                            </div>
                        </summary>
                        <div class="mt-3 pt-3 border-t border-slate-800 space-y-3 text-xs">
                            <div class="text-slate-200">
                                <strong>Resumen de validación / acción:</strong> <?= htmlspecialchars($log['validation_summary']) ?>
                                <span class="text-slate-400 ml-2">(Dirección: <?= htmlspecialchars($log['direction']) ?>)</span>
                            </div>
                            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
                                <div>
                                    <div class="text-slate-400 font-semibold mb-1">📥 Payload Recibido:</div>
                                    <pre class="bg-slate-950 p-3 rounded border border-slate-800 font-mono text-[11px] text-amber-200 overflow-x-auto"><?= htmlspecialchars($log['payload_json']) ?></pre>
                                </div>
                                <div>
                                    <div class="text-slate-400 font-semibold mb-1">📤 Respuesta del Back-Office:</div>
                                    <pre class="bg-slate-950 p-3 rounded border border-slate-800 font-mono text-[11px] text-emerald-300 overflow-x-auto"><?= htmlspecialchars((string) $log['response_json']) ?></pre>
                                </div>
                            </div>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

        <?php elseif ($tab === 'simulator'): ?>
            <!-- =========================================================================
                 PESTAÑA 6: SIMULADOR INTERACTIVO DE PAYLOADS Y DOCUMENTACIÓN DE CONTRATOS
                 ========================================================================= -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <div class="card-glass rounded-xl p-5 space-y-4">
                    <h2 class="text-base font-bold text-white">🧪 Lanzador de Payloads de Prueba (Escenarios 1, 2, 3 y Rama de Error)</h2>
                    <p class="text-xs text-slate-400">Permite inyectar cualquiera de los contratos JSON directamente al motor del Back-Office para demostrar en el Screencast tanto la rama de ejecución correcta como la rama de error ante datos incompletos.</p>

                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="loadSamplePayload('order_ok')" class="px-2.5 py-1.5 rounded bg-emerald-600/20 border border-emerald-500/40 text-emerald-300 text-xs font-medium hover:bg-emerald-600/30">
                            1. Cargar Escenario 1 (Pedido Válido)
                        </button>
                        <button type="button" onclick="loadSamplePayload('order_error')" class="px-2.5 py-1.5 rounded bg-rose-600/20 border border-rose-500/40 text-rose-300 text-xs font-medium hover:bg-rose-600/30">
                            2. Cargar Rama de Error (Datos Incompletos)
                        </button>
                        <button type="button" onclick="loadSamplePayload('operation_sync')" class="px-2.5 py-1.5 rounded bg-sky-600/20 border border-sky-500/40 text-sky-300 text-xs font-medium hover:bg-sky-600/30">
                            3. Cargar Escenario 2 (Cambio Operativo)
                        </button>
                        <button type="button" onclick="loadSamplePayload('ticket_postventa')" class="px-2.5 py-1.5 rounded bg-amber-600/20 border border-amber-500/40 text-amber-300 text-xs font-medium hover:bg-amber-600/30">
                            4. Cargar Escenario 3 (Incidencia Postventa)
                        </button>
                    </div>

                    <form method="POST" action="/" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="action" value="simulate_payload">
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Endpoint / Contrato Destino:</label>
                            <select id="scenario_type" name="scenario_type" class="w-full bg-slate-900 border border-slate-700 rounded-lg px-3 py-2 text-xs text-white font-mono">
                                <option value="order">POST /api/v1/webhooks/orders (Escenario 1: Pedido)</option>
                                <option value="operation">POST /api/v1/webhooks/operations (Escenario 2: Cambio Estado/Stock)</option>
                                <option value="ticket">POST /api/v1/webhooks/tickets (Escenario 3: Incidencia)</option>
                                <option value="ai_proposal">POST /api/v1/agent/proposals (Escenario 3: Salida Estructurada Agente IA)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs text-slate-400 mb-1">Cuerpo del Payload JSON:</label>
                            <textarea id="payload_json" name="payload_json" rows="13" class="w-full bg-slate-950 border border-slate-700 rounded-lg p-3 text-xs font-mono text-emerald-300"></textarea>
                        </div>
                        <button type="submit" class="w-full py-2.5 rounded-lg bg-rose-600 hover:bg-rose-500 text-white text-xs font-bold uppercase tracking-wider transition">
                            🚀 Ejecutar Payload y Registrar en Trazabilidad
                        </button>
                    </form>
                </div>

                <!-- Especificación de Endpoints API para n8n y Agente IA -->
                <div class="card-glass rounded-xl p-5 space-y-4">
                    <h2 class="text-base font-bold text-white">📑 Contratos de Integración API REST (Para n8n y Tools del Agente IA)</h2>
                    <p class="text-xs text-slate-400">Cabecera obligatoria en todas las rutas salvo <code>/api/v1/health</code>: <code class="text-amber-300">X-Integration-Token: &lt;INTEGRATION_API_KEY del .env&gt;</code></p>

                    <div class="space-y-3 text-xs">
                        <div class="p-3 rounded-lg bg-slate-900 border border-slate-800">
                            <div class="font-mono font-bold text-emerald-400">POST /api/v1/webhooks/orders</div>
                            <div class="text-slate-300 mt-1">Recibe eventos <code>order.created</code> o <code>payment.simulated</code> desde n8n. Valida campos obligatorios, aplica idempotencia por <code>order_ref</code>, descuenta stock y registra trazabilidad.</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-900 border border-slate-800">
                            <div class="font-mono font-bold text-sky-400">POST /api/v1/webhooks/operations</div>
                            <div class="text-slate-300 mt-1">Sincroniza cambios de estado de pedido (<code>order.status_changed</code>) o ajustes de stock de almacén (<code>inventory.stock_updated</code>).</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-900 border border-slate-800">
                            <div class="font-mono font-bold text-amber-400">POST /api/v1/webhooks/tickets</div>
                            <div class="text-slate-300 mt-1">Registra una solicitud postventa o incidencia (<code>support.requested</code>) asociada a un pedido y cliente.</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-900 border border-purple-500/30">
                            <div class="font-mono font-bold text-purple-300">GET /api/v1/agent/orders/{order_ref}</div>
                            <div class="text-slate-300 mt-1"><strong>Tool de Consulta 1 del Agente IA:</strong> Devuelve estado del pedido, pago, líneas, segmento del cliente, historial de tickets previos y stock actual.</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-900 border border-purple-500/30">
                            <div class="font-mono font-bold text-purple-300">GET /api/v1/agent/inventory/{sku}</div>
                            <div class="text-slate-300 mt-1"><strong>Tool de Consulta 2 del Agente IA:</strong> Consulta unidades disponibles y pasillo de almacén de un SKU concreto.</div>
                        </div>
                        <div class="p-3 rounded-lg bg-slate-900 border border-purple-500/30">
                            <div class="font-mono font-bold text-purple-300">POST /api/v1/agent/proposals</div>
                            <div class="text-slate-300 mt-1"><strong>Tool de Acción/Propuesta del Agente IA:</strong> Valida y registra la salida estructurada de 7 campos en estado <code>pendiente_de_revision</code>.</div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
                const samples = {
                    order_ok: {
                        type: 'order',
                        body: {
                            event_id: "c4e8b2d1-6f3a-4d9c-8b7e-2a1f5d0c9e47",
                            execution_id: "exec_live_demo_01",
                            event_type: "order.created",
                            order_ref: "PQ-20261108-LIVE99",
                            occurred_at: "2026-11-08T19:30:00Z",
                            status: "pagado_simulado",
                            payment_method: "redsys_sandbox",
                            shipping_address: "Avenida de Prueba 22, 41001 Sevilla",
                            customer: {
                                name: "Carlos Demo",
                                email: "carlos@example.com"
                            },
                            items: [
                                { sku: "SAL-001-100ML", product_name: "Salsa Carolina Reaper Inferno", variant_size: "100 ml", quantity: 1, unit_price: 12.90 },
                                { sku: "SAL-003", product_name: "Salsa Verde Jalapeño & Lima", variant_size: null, quantity: 2, unit_price: 5.90 }
                            ],
                            shipping_amount: 4.95,
                            total_amount: 29.65
                        }
                    },
                    order_error: {
                        type: 'order',
                        body: {
                            execution_id: "exec_live_err_99",
                            event_type: "order.created",
                            order_ref: "",
                            customer: { name: "", email: "email_sin_arroba" },
                            items: [],
                            total_amount: -5.00
                        }
                    },
                    operation_sync: {
                        type: 'operation',
                        body: {
                            execution_id: "exec_live_sync_02",
                            change_type: "ORDER_STATUS",
                            order_ref: "PQ-20261108-LIVE99",
                            new_status: "enviado",
                            priority: "URGENTE",
                            direction: "n8n Workflow -> BackOffice"
                        }
                    },
                    ticket_postventa: {
                        type: 'ticket',
                        body: {
                            execution_id: "exec_live_inc_03",
                            event_type: "support.requested",
                            ticket_ref: "INC-20261108-9901",
                            order_ref: "PQ-20261108-LIVE99",
                            customer_name: "Carlos Demo",
                            customer_email: "carlos@example.com",
                            subject: "Duda sobre la conservación de la Salsa Verde Jalapeño & Lima",
                            description: "Hola, acabo de recibir el pedido PQ-20261108-LIVE99 y quería saber si la Salsa Verde Jalapeño & Lima hay que conservarla en frío una vez abierta.",
                            priority: "MEDIA"
                        }
                    }
                };

                function loadSamplePayload(key) {
                    const s = samples[key];
                    if (!s) return;
                    document.getElementById('scenario_type').value = s.type;
                    document.getElementById('payload_json').value = JSON.stringify(s.body, null, 2);
                }
                loadSamplePayload('order_ok');
            </script>
        <?php endif; ?>

    </main>

    <!-- PIE DE PÁGINA -->
    <footer class="bg-slate-950 border-t border-slate-800 px-6 py-4 text-xs text-slate-500 mt-10">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between gap-2">
            <div>Piquantum Back-Office ERP v1.0 · Desarrollado para la Tarea 2 de Soluciones Informáticas para la Empresa (UCAM).</div>
            <div>Integrantes: Álvaro Acedo Sánchez · Abel Martínez Candela · Antonio Martínez Pallarés</div>
        </div>
    </footer>

</body>
</html>
