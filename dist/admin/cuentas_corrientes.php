<?php
/**
 * admin/cuentas_corrientes.php
 * 
 * Portal Exclusivo de Cuentas Corrientes — Gestión y Distribución de Cheques
 * Diseñado para la Gerente / Supervisora de Cuentas Corrientes.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/form/admin/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Redirección si no está autenticado o no tiene rol autorizado
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$rolUsuario = $_SESSION['admin_user_rol'] ?? '';
if (!in_array($rolUsuario, ['ADMINISTRADOR', 'SUPERVISORA_CC'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Cuentas Corrientes — Gestión y Distribución</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-primary: #1e3a8a;
            --color-primary-hover: #1e40af;
            --color-secondary: #64748b;
            --color-bg: #f8fafc;
            --color-surface: #ffffff;
            --color-border: #e2e8f0;
            --color-border-subtle: #f1f5f9;
            --color-text: #0f172a;
            --color-text-secondary: #475569;
            --color-text-muted: #64748b;

            --status-pendiente-bg: #fef3c7;   --status-pendiente-color: #92400e;
            --status-recibido-bg: #dbeafe;    --status-recibido-color: #1e40af;
            --status-depositado-bg: #dcfce7;  --status-depositado-color: #166534;
            --status-rechazado-bg: #fee2e2;   --status-rechazado-color: #991b1b;

            --radius-sm: 6px;
            --radius-md: 10px;
            --radius-lg: 16px;
            --font-mono: 'JetBrains Mono', ui-monospace, monospace;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--color-bg);
            color: var(--color-text);
            margin: 0;
            padding: 0;
            line-height: 1.45;
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
        }

        /* HEADER SUPERIOR ARMONIZADO CON TESORERÍA */
        .header-cc {
            height: 52px;
            background: var(--color-surface);
            border-bottom: 1px solid var(--color-border);
            padding: 0 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .badge-cc {
            background: var(--color-primary);
            color: white;
            font-weight: 700;
            font-size: 0.75rem;
            padding: 3px 8px;
            border-radius: var(--radius-sm);
            letter-spacing: 0.5px;
            text-transform: uppercase;
        }
        .header-cc h1 {
            font-size: 1rem;
            font-weight: 600;
            color: var(--color-text);
            margin: 0;
        }
        .cutoff-timer {
            font-size: 0.8rem;
            color: var(--color-text-secondary);
            background: var(--color-bg);
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--color-border);
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .cutoff-timer strong {
            color: var(--color-primary);
            font-family: var(--font-mono);
            font-weight: 600;
        }

        /* CONTAINER GENERAL */
        .container-cc {
            max-width: 1280px;
            margin: 24px auto;
            padding: 0 24px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        /* KPI STRIP GRID */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        @media (max-width: 768px) {
            .kpi-strip {
                grid-template-columns: 1fr;
            }
        }
        .kpi-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 18px 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .kpi-card-action {
            border-color: #bbf7d0;
            background: #f0fdf4;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .kpi-label {
            font-size: 0.78rem;
            color: var(--color-text-muted);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--color-text);
            font-family: var(--font-mono);
            line-height: 1.1;
        }
        .kpi-subtext {
            font-size: 0.8rem;
            color: var(--color-text-muted);
            margin-top: 4px;
        }

        /* TARJETAS CONTENEDORAS */
        .card-cc {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-md);
            padding: 20px 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.04);
        }
        .card-title {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 1.05rem;
            font-weight: 600;
            color: var(--color-text);
            border-bottom: 1px solid var(--color-border-subtle);
            padding-bottom: 12px;
        }

        /* BOTONES DE ACCIÓN */
        .btn-action {
            background: var(--color-primary);
            color: white;
            border: 1px solid var(--color-primary);
            padding: 6px 14px;
            border-radius: var(--radius-sm);
            font-weight: 500;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.15s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            font-family: inherit;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            white-space: nowrap;
        }
        .btn-action:hover:not(:disabled) {
            background: var(--color-primary-hover);
            border-color: var(--color-primary-hover);
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        .btn-action:disabled {
            background: var(--color-border-subtle);
            border-color: var(--color-border);
            color: var(--color-text-muted);
            cursor: not-allowed;
            box-shadow: none;
        }
        .btn-success {
            background: #16a34a;
            border-color: #16a34a;
            color: white;
        }
        .btn-success:hover:not(:disabled) {
            background: #15803d;
            border-color: #15803d;
        }
        .btn-secondary {
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            font-weight: 500;
            box-shadow: 0 1px 2px rgba(0,0,0,0.03);
        }
        .btn-secondary:hover {
            background: #f8fafc;
            color: #0f172a;
            border-color: #94a3b8;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }
        
        /* ESTILOS DE TABLAS DE TESORERÍA */
        .table-responsive {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
        }
        .table-responsive th {
            background: var(--color-bg);
            color: var(--color-text-secondary);
            font-weight: 600;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 10px 14px;
            border-bottom: 1px solid var(--color-border);
            text-align: left;
        }
        .table-responsive td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--color-border-subtle);
            color: var(--color-text);
        }
        .table-responsive tr:hover td {
            background-color: var(--color-bg);
        }
        .text-right {
            text-align: right;
        }
        .font-mono {
            font-family: var(--font-mono);
        }
        .monto-destacado {
            font-weight: 700;
            color: #166534;
        }

        /* BARRA DE FILTROS */
        .filter-bar {
            display: flex;
            gap: 10px;
            margin-bottom: 16px;
            flex-wrap: wrap;
        }
        .filter-input {
            height: 36px;
            padding: 0 12px;
            border: 1px solid var(--color-border);
            border-radius: var(--radius-sm);
            font-size: 0.85rem;
            outline: none;
            background: var(--color-surface);
            color: var(--color-text);
            transition: border-color 0.15s ease;
            font-family: inherit;
        }
        .filter-input:focus {
            border-color: var(--color-primary);
        }
        .flex-grow {
            flex-grow: 1;
        }

        /* CONTROL SEGMENTADO (PESTAÑAS TESORERÍA) */
        .history-tabs {
            display: flex;
            background: var(--color-bg);
            padding: 3px;
            border-radius: var(--radius-md);
            border: 1px solid var(--color-border);
            width: fit-content;
            gap: 2px;
            margin-bottom: 16px;
        }
        .history-tab {
            background: transparent;
            border: none;
            padding: 6px 14px;
            font-size: 0.82rem;
            font-weight: 500;
            color: var(--color-text-secondary);
            border-radius: var(--radius-sm);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.15s ease;
            font-family: inherit;
        }
        .history-tab:hover {
            color: var(--color-text);
        }
        .history-tab.active {
            background: var(--color-surface);
            color: var(--color-primary);
            font-weight: 600;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        .tab-counter {
            background: var(--color-border);
            color: var(--color-text-secondary);
            padding: 1px 6px;
            border-radius: 10px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .history-tab.active .tab-counter {
            background: #e2e8f0;
            color: var(--color-primary);
        }

        /* TOAST NOTIFICATIONS */
        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toast {
            background: #0f172a;
            color: white;
            padding: 10px 16px;
            border-radius: var(--radius-sm);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 0.85rem;
            font-weight: 500;
        }
        .toast.error { background: #dc2626; }
        .toast.success { background: #16a34a; }

        /* MODAL Y OVERLAYS */
        .modal-cc {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(15, 23, 42, 0.5);
            align-items: center;
            justify-content: center;
        }
        .modal-content-cc {
            background-color: var(--color-surface);
            margin: auto;
            padding: 24px;
            border: 1px solid var(--color-border);
            width: 90%;
            max-width: 680px;
            border-radius: var(--radius-md);
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
        }
        .close-modal {
            color: var(--color-text-muted);
            float: right;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal:hover {
            color: var(--color-text);
        }

        /* TOGGLE SWITCH */
        .toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer; inset: 0;
            background: #cbd5e1; border-radius: 24px;
            transition: background 0.2s ease;
        }
        .toggle-slider:before {
            content: ''; position: absolute;
            width: 18px; height: 18px; left: 3px; bottom: 3px;
            background: white; border-radius: 50%;
            transition: transform 0.2s ease;
        }
        .toggle-switch input:checked + .toggle-slider { background: #16a34a; }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); }
        .toggle-label-text { font-size: 0.8rem; font-weight: 600; color: var(--color-text-secondary); }
        .toggle-status { font-size: 0.75rem; font-weight: 700; }
        .toggle-status.on  { color: #16a34a; }
        .toggle-status.off { color: var(--color-text-muted); }

        /* RESPONSIVE DESIGN */
        @media (max-width: 992px) {
            .header-cc {
                height: auto;
                padding: 12px 16px;
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            .header-cc > div:last-child {
                width: 100%;
                flex-wrap: wrap;
                justify-content: flex-start;
            }
            .container-cc {
                padding: 0 12px;
            }
            .table-responsive-wrapper {
                overflow-x: auto;
            }
            .table-responsive {
                min-width: 680px;
            }
        }
    </style>
</head>
<body>

    <!-- HEADER SUPERIOR ARMONIZADO CON TESORERÍA -->
    <header class="header-cc">
        <div class="header-title">
            <span class="badge-cc">CUENTAS CORRIENTES</span>
            <h1>Portal de Cuentas Corrientes — Gestión y Distribución</h1>
        </div>
        <div style="display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
            <div class="cutoff-timer" id="txtCutoffTimer">Corte Hoy: <strong id="lblCutoffHour">--:--</strong> - <strong id="lblCutoffRemaining">Faltan --h --m</strong></div>
            <span style="font-size: 0.8rem; color: var(--color-text-secondary);">Usuario: <strong><?php echo htmlspecialchars($_SESSION['admin_user_nombre'] ?? 'Supervisora CC'); ?></strong></span>
            <button type="button" class="btn-action btn-secondary" onclick="abrirModalConfigCC()" style="font-size: 0.8rem; padding: 5px 12px;">Configuración</button>
            <button type="button" onclick="abrirModalLogout()" style="background: rgba(255,255,255,0.08); color: var(--color-text-muted); border: 1px solid var(--color-border); padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='rgba(220,38,38,0.15)';this.style.color='#fca5a5';" onmouseout="this.style.background='rgba(255,255,255,0.08)';this.style.color='var(--color-text-muted)';">
                Cerrar Sesión
            </button>
            <?php if ($rolUsuario === 'ADMINISTRADOR'): ?>
                <a href="index.php" style="background: rgba(37,99,235,0.15); color: #1e40af; border: 1px solid rgba(37,99,235,0.3); padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 500; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='rgba(37,99,235,0.25)';" onmouseout="this.style.background='rgba(37,99,235,0.15)';">
                    &#8646; Ir a Tesorería
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div class="container-cc">
        
        <!-- RESUMEN EJECUTIVO (KPI STRIP ALINEADO) -->
        <div class="kpi-strip">
            <div class="kpi-card">
                <span class="kpi-label">Cheques en Cola</span>
                <span class="kpi-value" id="kpiCount">0</span>
                <span class="kpi-subtext">Estado: Pendiente por Liberar</span>
            </div>
            <div class="kpi-card">
                <span class="kpi-label">Empresas en Cola</span>
                <span class="kpi-value" id="kpiEmpresas">0</span>
                <span class="kpi-subtext" id="kpiDetails">0 Clientes Afectados</span>
            </div>
            <div class="kpi-card kpi-card-action">
                <button type="button" class="btn-action btn-success" id="btnDespacharResumen" onclick="confirmarDespachoModal()" style="width: 100%; height: 46px; font-size: 0.9rem;" disabled>
                    Despachar Resumen Ahora
                </button>
            </div>
        </div>

        <!-- BLOQUE: COBRANZAS PENDIENTES DE DESPACHO (COLA DE SALIDA) -->
        <div class="card-cc">
            <h2 class="card-title">Cobranzas Pendientes de Despacho (Cola de Salida)</h2>
            
            <!-- Barra de Filtros Dinámicos -->
            <div class="filter-bar">
                <input type="text" id="filterBuscar" class="filter-input flex-grow" placeholder="Buscar por Factura, Cliente, RUT o Vendedor..." oninput="filtrarColaDeCheques()">
                <select id="filterEmpresa" class="filter-input" onchange="filtrarColaDeCheques()">
                    <option value="">Todas las Empresas</option>
                    <option value="Automarco LTDA">Automarco LTDA</option>
                    <option value="HD Automarco S.A">HD Automarco S.A</option>
                    <option value="Autotec S.A">Autotec S.A</option>
                    <option value="Gabtec S.A">Gabtec S.A</option>
                </select>
                <select id="filterOrden" class="filter-input" onchange="filtrarColaDeCheques()">
                    <option value="venc_asc">Vencimiento (Más próximo)</option>
                    <option value="venc_desc">Vencimiento (Más lejano)</option>
                    <option value="monto_desc">Monto (Mayor primero)</option>
                    <option value="monto_asc">Monto (Menor primero)</option>
                    <option value="empresa">Empresa</option>
                </select>
            </div>

            <div class="table-responsive-wrapper">
                <table class="table-responsive">
                    <thead>
                        <tr>
                            <th>Empresa / Cliente</th>
                            <th>Vendedor</th>
                            <th style="text-align: center;">Documentos</th>
                            <th class="text-right">Monto Total</th>
                        </tr>
                    </thead>
                    <tbody id="tblChequesEnColaCC">
                        <tr><td colspan="4" style="text-align: center; color: var(--color-text-muted); padding: 24px;">Cargando cola de cobranzas...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- BLOQUE: HISTORIAL DE DESPACHOS Y TRAZABILIDAD -->
        <div class="card-cc">
            <h2 class="card-title">Historial de Despachos y Trazabilidad</h2>
            
            <div class="history-tabs">
                <button type="button" class="history-tab active" id="tabTodos" onclick="filtrarHistorialCC('Todos')">
                    Todos <span class="tab-counter" id="cntHistTodos">0</span>
                </button>
                <button type="button" class="history-tab" id="tabExitosos" onclick="filtrarHistorialCC('Enviados')">
                    Exitosos <span class="tab-counter" id="cntHistExitosos">0</span>
                </button>
                <button type="button" class="history-tab" id="tabFallidos" onclick="filtrarHistorialCC('Fallidos')">
                    Fallidos <span class="tab-counter" id="cntHistFallidos">0</span>
                </button>
            </div>

            <div class="table-responsive-wrapper">
                <table class="table-responsive">
                    <thead>
                        <tr>
                            <th>Fecha y Hora</th>
                            <th>Empresa Origen / Destinatario</th>
                            <th>Clientes Afectados</th>
                            <th style="text-align: center;">Total Cheques</th>
                            <th>Estado</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tblBitacoraEnviosCC">
                        <tr><td colspan="6" style="text-align: center; color: var(--color-text-muted); padding: 24px;">Cargando historial...</td></tr>
                    </tbody>
                </table>
            </div>
            <div id="historialPager"></div>
        </div>

    </div>

    <!-- MODAL DE CONFIGURACIÓN -->
    <style>
        /* Toggle Switch */
        .toggle-switch { position: relative; display: inline-block; width: 48px; height: 26px; flex-shrink: 0; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer; inset: 0;
            background: #cbd5e1; border-radius: 26px;
            transition: background 0.25s ease;
        }
        .toggle-slider:before {
            content: ''; position: absolute;
            width: 20px; height: 20px; left: 3px; bottom: 3px;
            background: white; border-radius: 50%;
            transition: transform 0.25s ease;
            box-shadow: 0 1px 4px rgba(0,0,0,0.2);
        }
        .toggle-switch input:checked + .toggle-slider { background: #16a34a; }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(22px); }
        .toggle-label-text { font-size: 0.85rem; font-weight: 600; color: #475569; }
        .toggle-status { font-size: 0.78rem; font-weight: 700; transition: color 0.2s; }
        .toggle-status.on  { color: #16a34a; }
        .toggle-status.off { color: #94a3b8; }
    </style>

    <?php include __DIR__ . '/components/modal_config_cc.php'; ?>

    <!-- MODAL DE CONFIRMACIÓN DE DESPACHO SEGURO -->
    <div id="modalConfirmarDespacho" class="modal-cc">
        <div class="modal-content-cc" style="max-width: 550px;">
            <span class="close-modal" onclick="cerrarConfirmarDespacho()">&times;</span>
            <h2 style="margin-top:0; color:#1e3a8a; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">Confirmación de Despacho Seguro</h2>
            
            <p style="font-size:0.95rem; line-height:1.5; color:#334155;">
                ¿Está segura que desea despachar el resumen consolidado a las digitadoras ahora? Los cheques transicionarán al estado final de cobro en el sistema.
            </p>

            <div style="background:#f1f5f9; padding:16px; border-radius:8px; border:1px solid #e2e8f0; margin:16px 0;">
                <h4 style="margin-top:0; margin-bottom:8px; color:#475569;">Matriz de Despacho Estimada:</h4>
                <div id="lblMatrizConfirmacionDetalle" style="font-size:0.85rem; display:flex; flex-direction:column; gap:6px;">
                    <!-- Cargado dinámicamente -->
                </div>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                <button type="button" class="btn-action btn-secondary" onclick="cerrarConfirmarDespacho()">Cancelar</button>
                <button type="button" class="btn-action btn-success" onclick="ejecutarDespachoCC()">Confirmar y Despachar</button>
            </div>
        </div>
    </div>

    <!-- MODAL DETALLES LOG -->
    <div id="modalLogDetalle" class="modal-cc">
        <div class="modal-content-cc" style="max-width: 800px; width: 95%;">
            <span class="close-modal" onclick="cerrarLogDetalle()">&times;</span>
            <h2 style="margin-top:0; color:#1e3a8a; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">Detalle de Despacho e Información de Cobranzas</h2>
            
            <div id="logDetalleContent" style="background:#f1f5f9; padding:16px; border-radius:8px; border:1px solid #e2e8f0; margin:16px 0; font-size: 0.95rem; max-height: 60vh; overflow-y: auto;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                <button type="button" class="btn-action btn-secondary" onclick="cerrarLogDetalle()">Cerrar</button>
            </div>
        </div>
    </div>

    <!-- MODAL DETALLES DE UNA SOLA COBRANZA -->
    <div id="modalDetalleCobranza" class="modal-cc">
        <div class="modal-content-cc" style="max-width: 700px;">
            <span class="close-modal" onclick="cerrarDetalleCobranza()">&times;</span>
            <h2 style="margin-top:0; color:#1e3a8a; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">Detalle de la Cobranza</h2>
            
            <div id="cobranzaDetalleContent" style="background:#f1f5f9; padding:16px; border-radius:8px; border:1px solid #e2e8f0; margin:16px 0; font-size: 0.95rem; max-height: 60vh; overflow-y: auto;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                <button type="button" class="btn-action btn-secondary" onclick="cerrarDetalleCobranza()">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        // Caché de datos locales
        let cacheCobranzasCola = [];
        let cacheHistorialLog = [];
        let cacheEmpresasMatriz = [];
        let horaCorteGlobal = "16:00";
        let historyFilterSelected = 'Todos';
        let historialCurrentPage = 1;
        let historialTotalPages = 1;
        let historialTotal = 0;

        function showToast(message, type = 'success') {
            let container = document.getElementById('toastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toastContainer';
                container.className = 'toast-container';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.textContent = message;
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 4000);
        }



        function confirmarDespachoModal() {
            if (cacheCobranzasCola.length === 0) return;
            
            // Construir matriz de destinatarios y cantidad
            const resumenMap = {};
            cacheCobranzasCola.forEach(cob => {
                const empMatriz = cacheEmpresasMatriz.find(e => e.nombre === cob.empresa_nombre);
                const email = empMatriz ? empMatriz.email_digitadora : 'No Asignada';
                
                if (!resumenMap[cob.empresa_nombre]) {
                    resumenMap[cob.empresa_nombre] = { email: email, countCob: 0, countChq: 0 };
                }
                resumenMap[cob.empresa_nombre].countCob++;
                resumenMap[cob.empresa_nombre].countChq += cob.cheques ? cob.cheques.length : 0;
            });

            let htmlMatriz = "";
            for (const [emp, det] of Object.entries(resumenMap)) {
                htmlMatriz += `
                    <div style="display:flex; justify-content:space-between; border-bottom:1px solid #cbd5e1; padding-bottom:4px;">
                        <span><strong>${emp}</strong> (${det.countCob} cobranza(s) / ${det.countChq} cheque(s)) ➔ ${det.email}</span>
                    </div>
                `;
            }

            document.getElementById('lblMatrizConfirmacionDetalle').innerHTML = htmlMatriz;
            document.getElementById('modalConfirmarDespacho').style.display = 'flex';
        }

        function cerrarConfirmarDespacho() {
            document.getElementById('modalConfirmarDespacho').style.display = 'none';
        }

        function abrirLogDetalle(logId) {
            const log = cacheHistorialLog.find(l => l.id == logId);
            if (!log) return;
            let html = `
                <div style="display: flex; flex-wrap: wrap; gap: 20px; border-bottom: 1px solid #cbd5e1; padding-bottom: 12px; margin-bottom: 16px;">
                    <div><strong>Fecha y Hora:</strong> ${log.fecha_envio}</div>
                    <div><strong>Empresa Origen:</strong> ${log.empresa || 'Consolidado'}</div>
                    <div><strong>Destinatario:</strong> ${log.destinatario}</div>
                    <div><strong>Estado:</strong> ${log.estado_envio}</div>
                </div>
            `;
            if (log.estado_envio === 'FALLIDO' && log.error_mensaje) {
                html += `<p style="color: #dc2626; margin-top: 0;"><strong>Error:</strong> ${log.error_mensaje}</p>`;
            }

            if (log.payload_json) {
                try {
                    const cobranzas = JSON.parse(log.payload_json);
                    html += `<h3 style="color: #334155; margin-bottom: 12px; font-size: 1.1rem;">Cobranzas Enviadas (${cobranzas.length})</h3>`;
                    
                    cobranzas.forEach((cob, i) => {
                        let sumMonto = 0;
                        let chequesHtml = '';
                        if (cob.cheques && cob.cheques.length > 0) {
                            chequesHtml = '<table style="width:100%; border-collapse:collapse; margin-top:6px; font-size:0.83rem;">' +
                                '<tr style="background:#f8fafc; border-bottom:1px solid #e2e8f0; color:#64748b;"><th style="padding:4px 8px; text-align:left;">N° Cheque</th><th style="padding:4px 8px; text-align:left;">Banco</th><th style="padding:4px 8px; text-align:left;">Vencimiento</th><th style="padding:4px 8px; text-align:right;">Monto</th></tr>';
                            cob.cheques.forEach(ch => {
                                sumMonto += parseFloat(ch.monto_cheque);
                                const mFmt = '$' + parseInt(ch.monto_cheque).toLocaleString('es-CL');
                                let vFmt = 'Sin Fecha';
                                if (ch.fecha_vencimiento) {
                                    try {
                                        vFmt = new Date(ch.fecha_vencimiento + 'T12:00:00').toLocaleDateString('es-CL');
                                    } catch(e) {}
                                }
                                chequesHtml += `<tr style="border-bottom:1px solid #f1f5f9;">
                                    <td style="padding:5px 8px; font-family:var(--font-mono); font-weight:700; color:#1e3a8a;">N° ${ch.numero_cheque}</td>
                                    <td style="padding:5px 8px; color:#334155;">${ch.banco}</td>
                                    <td style="padding:5px 8px; color:#475569;">${vFmt}</td>
                                    <td style="padding:5px 8px; text-align:right; font-family:var(--font-mono); font-weight:700; color:#166534;">${mFmt}</td>
                                </tr>`;
                            });
                            chequesHtml += '</table>';
                        } else {
                            chequesHtml = '<em style="color:#94a3b8; font-size:0.85rem;">Sin cheques adjuntos</em>';
                        }
                        const montoFmt = '$' + parseInt(sumMonto).toLocaleString('es-CL');

                        let facturasHtml = '';
                        if (cob.facturas_multiples && cob.facturas_multiples.length > 0) {
                            facturasHtml = '<ul style="margin:4px 0 0; padding-left:20px; font-size:0.85rem; color:#475569;">';
                            cob.facturas_multiples.forEach(fac => {
                                const mCub = '$' + parseInt(fac.monto_cubierto).toLocaleString('es-CL');
                                facturasHtml += `<li><strong>${fac.numero_factura}</strong> (${fac.cuota_label}) - Cubre: ${mCub}</li>`;
                            });
                            facturasHtml += '</ul>';
                        } else {
                            facturasHtml = `<span style="font-size:0.85rem; color:#475569;">Doc: ${cob.numero_factura}</span>`;
                        }

                        html += `
                            <div style="border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 14px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1px solid var(--color-border-subtle); padding-bottom:8px;">
                                    <div>
                                        <strong style="color:var(--color-primary); font-size:0.95rem;">${i + 1}. ${cob.razon_social_cliente}</strong>
                                        <span style="font-size:0.8rem; color:var(--color-text-muted); margin-left:8px;">RUT: ${cob.rut_cliente}</span>
                                    </div>
                                    <strong style="color:#166534; font-family:var(--font-mono); font-size:0.95rem;">Monto: ${montoFmt}</strong>
                                </div>
                                <div style="display:flex; gap:16px; flex-wrap:wrap;">
                                    <div style="flex:1; min-width:220px;">
                                        <strong style="font-size:0.8rem; color:var(--color-text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Facturas Cubiertas:</strong>
                                        <div style="margin-top:4px;">${facturasHtml}</div>
                                    </div>
                                    <div style="flex:2; min-width:280px;">
                                        <strong style="font-size:0.8rem; color:var(--color-text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Cheques Digitalizados / Registrados:</strong>
                                        <div style="margin-top:4px;">${chequesHtml}</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                } catch (e) {
                    html += `<p style="color: #64748b;"><em>Error al leer el detalle del envío o formato antiguo.</em></p>`;
                }
            } else {
                html += `<p style="color: #64748b;"><em>No hay detalle exacto disponible para este envío (versión antigua). Total enviado: ${log.cantidad_cobranzas}</em></p>`;
            }

            document.getElementById('logDetalleContent').innerHTML = html;
            document.getElementById('modalLogDetalle').style.display = 'flex';
        }

        function cerrarLogDetalle() {
            document.getElementById('modalLogDetalle').style.display = 'none';
        }

        function abrirDetalleCobranza(cobranzaId) {
            const cob = cacheCobranzasCola.find(c => c.cobranza_id == cobranzaId);
            if (!cob) return;

            let sumMonto = 0;
            let chequesHtml = '';
            if (cob.cheques && cob.cheques.length > 0) {
                chequesHtml = '<ul style="margin:4px 0 0; padding-left:20px; font-size:0.9rem; color:#334155;">';
                cob.cheques.forEach(ch => {
                    sumMonto += parseFloat(ch.monto_cheque);
                    const mFmt = '$' + parseInt(ch.monto_cheque).toLocaleString('es-CL');
                    let vFmt = 'Sin Fecha';
                    if (ch.fecha_vencimiento) {
                        try {
                            vFmt = new Date(ch.fecha_vencimiento + 'T12:00:00').toLocaleDateString('es-CL');
                        } catch(e) {}
                    }
                    chequesHtml += `<li><strong>N° ${ch.numero_cheque}</strong> (${ch.banco}) - ${mFmt} [Venc: ${vFmt}]</li>`;
                });
                chequesHtml += '</ul>';
            } else {
                chequesHtml = '<em style="color:#94a3b8; font-size:0.9rem;">Sin cheques adjuntos</em>';
            }
            const montoFmt = '$' + parseInt(sumMonto).toLocaleString('es-CL');

            let facturasHtml = '';
            if (cob.facturas_multiples && cob.facturas_multiples.length > 0) {
                facturasHtml = '<ul style="margin:4px 0 0; padding-left:20px; font-size:0.9rem; color:#334155;">';
                cob.facturas_multiples.forEach(fac => {
                    const mCub = '$' + parseInt(fac.monto_cubierto).toLocaleString('es-CL');
                    facturasHtml += `<li><strong>${fac.numero_factura}</strong> (${fac.cuota_label}) - Cubre: ${mCub}</li>`;
                });
                facturasHtml += '</ul>';
            } else {
                facturasHtml = `<span style="font-size:0.9rem; color:#334155;">Doc Principal: ${cob.numero_factura}</span>`;
            }

            let html = `
                <div style="border-bottom: 1px solid #cbd5e1; padding-bottom: 12px; margin-bottom: 16px;">
                    <div style="font-size: 1.1rem; font-weight: bold; color: #1e3a8a;">${cob.razon_social_cliente}</div>
                    <div style="color: #64748b; margin-top: 4px;">RUT: ${cob.rut_cliente} &nbsp;•&nbsp; Empresa: ${cob.empresa_nombre}</div>
                    <div style="color: #64748b; margin-top: 2px;">Vendedor: ${cob.vendedor_nombre}</div>
                </div>
                
                <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom: 20px;">
                    <div style="flex:1; min-width:250px;">
                        <strong style="color: #0f172a; font-size: 1.05rem;">Facturas Abonadas</strong>
                        <div style="margin-top: 8px; background: #fff; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                            ${facturasHtml}
                        </div>
                    </div>
                    
                    <div style="flex:1; min-width:250px;">
                        <strong style="color: #0f172a; font-size: 1.05rem;">Cheques Físicos</strong>
                        <div style="margin-top: 8px; background: #fff; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                            ${chequesHtml}
                        </div>
                    </div>
                </div>

                <div style="text-align: right; font-size: 1.2rem; font-weight: bold; color: #15803d; border-top: 1px solid #cbd5e1; padding-top: 16px;">
                    Monto Total a Rendir: ${montoFmt}
                </div>
            `;
            document.getElementById('cobranzaDetalleContent').innerHTML = html;
            document.getElementById('modalDetalleCobranza').style.display = 'flex';
        }

        function cerrarDetalleCobranza() {
            document.getElementById('modalDetalleCobranza').style.display = 'none';
        }

        function actualizarTemporizadorCorte() {
            if (!horaCorteGlobal) return;
            
            const timerContainer = document.getElementById('txtCutoffTimer');
            const chkAuto = document.getElementById('chkAutoDispatch');
            if (chkAuto && !chkAuto.checked) {
                timerContainer.style.display = 'none';
                return;
            } else {
                timerContainer.style.display = '';
            }

            document.getElementById('lblCutoffHour').textContent = `${horaCorteGlobal} hrs`;

            const parts = horaCorteGlobal.split(':');
            const target = new Date();
            target.setHours(parseInt(parts[0]), parseInt(parts[1]), 0, 0);

            const now = new Date();
            let diff = target - now;

            if (diff < 0) {
                // Ya pasó la hora de corte hoy, apuntar a mañana
                target.setDate(target.getDate() + 1);
                diff = target - now;
            }

            const hours = Math.floor(diff / (1000 * 60 * 60));
            const minutes = Math.floor((diff / (1000 * 60)) % 60);
            
            document.getElementById('lblCutoffRemaining').textContent = `Faltan ${hours}h ${minutes}m`;
        }

        function cargarDatosCC() {
            fetch('api/get_gestion_cc.php')
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        showToast(data.message || 'Error al obtener datos', 'error');
                        return;
                    }
                    const info = data.data;
                    horaCorteGlobal = info.hora_despacho_diario;
                    actualizarTemporizadorCorte();

                    document.getElementById('inputHoraDespachoCC').value = info.hora_despacho_diario;
                    document.getElementById('chkAutoDispatch').checked = info.despacho_automatico_activado === '1';
                    actualizarToggleLabelCfg();

                    const dig1 = info.email_digitadora_1 || '';
                    const dig2 = info.email_digitadora_2 || '';
                    document.getElementById('inputDig1').value = dig1;
                    document.getElementById('inputDig2').value = dig2;
                    if (document.getElementById('inputTesGen')) document.getElementById('inputTesGen').value = info.email_tesoreria_general || '';
                    if (document.getElementById('inputCCGen')) document.getElementById('inputCCGen').value = info.email_cuentas_corrientes_general || '';

                    // Renderizar matriz de empresas en el modal (Asignación excluyente radio buttons)
                    cacheEmpresasMatriz = info.empresas;
                    const tbodyEmp = document.getElementById('tblAsignacionesDigitadorasCC');
                    tbodyEmp.innerHTML = info.empresas.map(emp => {
                        const emailActual = emp.email_digitadora || '';
                        // Si el email actual coincide con dig2 (y no está vacío), seleccionamos 2, si no por defecto 1.
                        const isDig2 = (emailActual === dig2 && dig2 !== '');
                        return `
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="font-weight: 600; font-size: 0.82rem; padding: 12px 14px; color: #334155; text-align: left;">${emp.nombre}</td>
                                <td>
                                    <input type="radio" name="radio_emp_${emp.id}" value="1" ${!isDig2 ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px; accent-color: #2563eb;">
                                </td>
                                <td>
                                    <input type="radio" name="radio_emp_${emp.id}" value="2" ${isDig2 ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px; accent-color: #16a34a;">
                                </td>
                            </tr>
                        `;
                    }).join('');

                    cacheCobranzasCola = info.cobranzas_en_cola || [];
                    cacheHistorialLog = info.log_envios || [];
                    historialCurrentPage = info.historial_page || 1;
                    historialTotalPages = info.historial_total_pages || 1;
                    historialTotal = info.historial_total || 0;
                    
                    actualizarKPIStrip();
                    filtrarColaDeCheques();
                    renderHistorialTable();
                })
                .catch(err => {
                    console.error(err);
                    showToast('Error de conexión', 'error');
                });
        }

        // Carga independiente del historial para paginación
        function cargarHistorial(page) {
            historialCurrentPage = page;
            const tbody = document.getElementById('tblBitacoraEnviosCC');
            tbody.innerHTML = `<tr><td colspan="5" style="text-align:center; color:#94a3b8; padding:20px;">Cargando historial...</td></tr>`;

            fetch(`api/get_gestion_cc.php?historial_page=${page}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        showToast(data.message || 'Error al cargar historial', 'error');
                        return;
                    }
                    cacheHistorialLog = data.data.log_envios || [];
                    historialCurrentPage = data.data.historial_page || 1;
                    historialTotalPages = data.data.historial_total_pages || 1;
                    historialTotal = data.data.historial_total || 0;
                    renderHistorialTable();
                })
                .catch(err => {
                    console.error(err);
                    showToast('Error de conexión', 'error');
                });
        }

        function actualizarKPIStrip() {
            const countCobranzas = cacheCobranzasCola.length;
            const uniqueEmpresas = new Set();
            const uniqueClientes = new Set();
            let countCheques = 0;

            cacheCobranzasCola.forEach(cob => {
                uniqueEmpresas.add(cob.empresa_nombre);
                uniqueClientes.add(cob.rut_cliente);
                countCheques += cob.cheques ? cob.cheques.length : 0;
            });

            document.getElementById('kpiCount').textContent = countCheques; // Or could be countCobranzas
            document.getElementById('kpiEmpresas').textContent = uniqueEmpresas.size;
            document.getElementById('kpiDetails').textContent = `${uniqueClientes.size} Cliente(s) / ${countCobranzas} Cobranzas`;

            const btnDespachar = document.getElementById('btnDespacharResumen');
            if (countCobranzas > 0) {
                btnDespachar.disabled = false;
                btnDespachar.removeAttribute('title');
            } else {
                btnDespachar.disabled = true;
                btnDespachar.setAttribute('title', 'No hay cobranzas pendientes para despachar hoy');
            }
        }

        function filtrarColaDeCheques() {
            const searchVal = document.getElementById('filterBuscar').value.trim().toLowerCase();
            const empVal = document.getElementById('filterEmpresa').value;
            const ordVal = document.getElementById('filterOrden').value;

            // Filtrado
            let filtered = cacheCobranzasCola.filter(cob => {
                const matchSearch = !searchVal || 
                    (cob.numero_factura && cob.numero_factura.toLowerCase().includes(searchVal)) ||
                    cob.razon_social_cliente.toLowerCase().includes(searchVal) ||
                    cob.rut_cliente.toLowerCase().includes(searchVal) ||
                    cob.vendedor_nombre.toLowerCase().includes(searchVal) ||
                    (cob.cheques && cob.cheques.some(ch => ch.numero_cheque.toLowerCase().includes(searchVal)));
                
                const matchEmpresa = !empVal || cob.empresa_nombre === empVal;

                return matchSearch && matchEmpresa;
            });

            // Ordenamiento (Basado en la fecha de la cobranza o monto total)
            filtered.sort((a, b) => {
                let sumA = a.cheques ? a.cheques.reduce((sum, ch) => sum + parseFloat(ch.monto_cheque), 0) : 0;
                let sumB = b.cheques ? b.cheques.reduce((sum, ch) => sum + parseFloat(ch.monto_cheque), 0) : 0;

                if (ordVal === 'monto_desc') {
                    return sumB - sumA;
                } else if (ordVal === 'monto_asc') {
                    return sumA - sumB;
                } else if (ordVal === 'empresa') {
                    return a.empresa_nombre.localeCompare(b.empresa_nombre);
                }
                // Si es fecha, usamos el updated_at de la cobranza
                return new Date(a.updated_at) - new Date(b.updated_at);
            });

            // Renderizado
            const tbodyChq = document.getElementById('tblChequesEnColaCC');
            if (filtered.length === 0) {
                tbodyChq.innerHTML = `<tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px;">No hay cobranzas en cola que coincidan con los filtros.</td></tr>`;
            } else {
                tbodyChq.innerHTML = filtered.map((cob, index) => {
                    let sumMonto = 0;
                    if (cob.cheques && cob.cheques.length > 0) {
                        cob.cheques.forEach(ch => {
                            sumMonto += parseFloat(ch.monto_cheque);
                        });
                    }

                    const montoFmt = '$' + parseInt(sumMonto).toLocaleString('es-CL');
                    const numDocs = cob.facturas_multiples ? cob.facturas_multiples.length : 1;

                    return `
                        <tr>
                            <td>
                                <div style="font-weight: 600; color: #1e3a8a; margin-bottom:4px;">${cob.empresa_nombre}</div>
                                <div style="font-weight: 600;">${cob.razon_social_cliente}</div>
                                <div style="font-size: 0.8rem; color: #64748b;">RUT: ${cob.rut_cliente}</div>
                            </td>
                            <td style="vertical-align:middle;">${cob.vendedor_nombre}</td>
                            <td style="vertical-align:middle; text-align:center;">
                                <button type="button" class="btn-action" style="background:#e2e8f0; color:#334155; padding: 6px 12px; border-radius: 4px; font-size: 0.85rem;" onclick="abrirDetalleCobranza(${cob.cobranza_id})">
                                    Ver Detalle (${numDocs} doc)
                                </button>
                            </td>
                            <td class="text-right font-mono monto-destacado" style="vertical-align:middle;">${montoFmt}</td>
                        </tr>
                    `;
                }).join('');
            }
        }

        function renderHistorialTable() {
            // Filtrado solo para los contadores del tab (usa todos en la página actual)
            const exitosos = cacheHistorialLog.filter(l => l.estado_envio === 'ENVIADO').length;
            const fallidos = cacheHistorialLog.filter(l => l.estado_envio === 'FALLIDO').length;

            document.getElementById('cntHistTodos').textContent = historialTotal;
            document.getElementById('cntHistExitosos').textContent = exitosos;
            document.getElementById('cntHistFallidos').textContent = fallidos;

            // Filtrado local en la página
            let filtered = cacheHistorialLog;
            if (historyFilterSelected === 'Enviados') {
                filtered = cacheHistorialLog.filter(l => l.estado_envio === 'ENVIADO');
            } else if (historyFilterSelected === 'Fallidos') {
                filtered = cacheHistorialLog.filter(l => l.estado_envio === 'FALLIDO');
            }

            const tbodyBit = document.getElementById('tblBitacoraEnviosCC');
            if (filtered.length === 0) {
                tbodyBit.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--color-text-muted); padding: 20px;">No hay registros de envíos para esta categoría.</td></tr>`;
            } else {
                // Agrupar por día
                const grupos = {};
                filtered.forEach(log => {
                    const fecha = log.fecha_envio.split(' ')[0]; // 'YYYY-MM-DD'
                    const [y, m, d] = fecha.split('-');
                    const fechaLabel = `${d}/${m}/${y}`;
                    if (!grupos[fechaLabel]) grupos[fechaLabel] = [];
                    grupos[fechaLabel].push(log);
                });

                let html = '';
                for (const [dia, logs] of Object.entries(grupos)) {
                    // Fila separadora por día
                    html += `
                        <tr>
                            <td colspan="6" style="background: var(--color-border-subtle); padding: 6px 12px; font-size: 0.8rem; font-weight: 700; color: var(--color-text-secondary); letter-spacing: 0.05em;">
                                ${dia}
                            </td>
                        </tr>
                    `;
                    logs.forEach(log => {
                        const esExitoso = log.estado_envio === 'ENVIADO';
                        const badgeStyle = esExitoso 
                            ? 'background: #dcfce7; color: #15803d; border-radius: 9999px; padding: 3px 10px; font-size: 0.78rem; font-weight: 700;' 
                            : 'background: #fee2e2; color: #b91c1c; border-radius: 9999px; padding: 3px 10px; font-size: 0.78rem; font-weight: 700;';
                        const hora = log.fecha_envio.split(' ')[1] || '';

                        // Extraer nombres de clientes del payload_json
                        let clientesText = '-';
                        if (log.payload_json) {
                            try {
                                const cobs = JSON.parse(log.payload_json);
                                if (Array.isArray(cobs) && cobs.length > 0) {
                                    const nombres = cobs.map(c => c.razon_social_cliente || 'Cliente').filter(Boolean);
                                    if (nombres.length === 1) {
                                        clientesText = `<strong>${nombres[0]}</strong>`;
                                    } else if (nombres.length > 1) {
                                        clientesText = `<strong>${nombres[0]}</strong> <span style="color: var(--color-text-muted); font-size: 0.78rem;">(+${nombres.length - 1} más)</span>`;
                                    }
                                }
                            } catch(e) {}
                        }

                        html += `
                            <tr>
                                <td style="font-weight: 500; font-family: var(--font-mono); font-size: 0.82rem;">${hora}</td>
                                <td>
                                    <div style="font-weight: 600; color: var(--color-text);">${log.empresa || 'Consolidado'}</div>
                                    <div style="font-size: 0.78rem; color: var(--color-text-muted);">Para: ${log.destinatario}</div>
                                </td>
                                <td>
                                    <div style="font-size: 0.83rem;">${clientesText}</div>
                                </td>
                                <td style="text-align: center; font-weight: 700; font-family: var(--font-mono);">${log.cantidad_cobranzas}</td>
                                <td><span style="${badgeStyle}">${log.estado_envio}</span></td>
                                <td style="text-align: right; white-space: nowrap;">
                                    <div style="display: inline-flex; gap: 6px; justify-content: flex-end;">
                                        <button type="button" class="btn-action btn-secondary" onclick="abrirLogDetalle(${log.id})">Ver Info & Cheques</button>
                                        <button type="button" class="btn-action btn-secondary" onclick="reenviarBitacoraCC(${log.id})">Re-enviar</button>
                                    </div>
                                </td>
                            </tr>
                        `;
                    });
                }
                tbodyBit.innerHTML = html;
            }

            // Renderizar controles de paginación
            const pagerEl = document.getElementById('historialPager');
            if (!pagerEl) return;

            if (historialTotalPages <= 1) {
                pagerEl.innerHTML = '';
                return;
            }

            let pagerHtml = `<div style="display:flex; align-items:center; justify-content:center; gap:8px; margin-top:16px;">`;
            pagerHtml += `
                <button class="btn-action btn-secondary" style="padding:5px 12px; font-size:0.85rem;" 
                    ${historialCurrentPage <= 1 ? 'disabled' : ''} 
                    onclick="cargarHistorial(${historialCurrentPage - 1})">
                    &larr; Anterior
                </button>
            `;

            // Mostrar máximo 5 páginas centradas en la actual
            const startPage = Math.max(1, historialCurrentPage - 2);
            const endPage = Math.min(historialTotalPages, startPage + 4);
            for (let p = startPage; p <= endPage; p++) {
                const active = p === historialCurrentPage;
                pagerHtml += `
                    <button class="btn-action" style="padding:5px 12px; font-size:0.85rem; ${active ? 'background:#1e3a8a; color:#fff;' : 'background:#e2e8f0; color:#334155;'}" 
                        onclick="cargarHistorial(${p})">${p}</button>
                `;
            }

            pagerHtml += `
                <button class="btn-action btn-secondary" style="padding:5px 12px; font-size:0.85rem;" 
                    ${historialCurrentPage >= historialTotalPages ? 'disabled' : ''} 
                    onclick="cargarHistorial(${historialCurrentPage + 1})">
                    Siguiente &rarr;
                </button>
            `;
            pagerHtml += `<span style="color:#64748b; font-size:0.85rem;">Página ${historialCurrentPage} de ${historialTotalPages} (${historialTotal} registros)</span>`;
            pagerHtml += `</div>`;
            pagerEl.innerHTML = pagerHtml;
        }

        function filtrarHistorialCC(filterType) {
            historyFilterSelected = filterType;
            
            document.querySelectorAll('.history-tab').forEach(t => t.classList.remove('active'));
            if (filterType === 'Todos') document.getElementById('tabTodos').classList.add('active');
            else if (filterType === 'Enviados') document.getElementById('tabExitosos').classList.add('active');
            else if (filterType === 'Fallidos') document.getElementById('tabFallidos').classList.add('active');

            renderHistorialTable();
        }


        let despachandoCC = false;
        function ejecutarDespachoCC() {
            if (despachandoCC) return;
            const btnDespachar = document.getElementById('btnDespacharResumen');
            
            despachandoCC = true;
            if (btnDespachar) {
                btnDespachar.disabled = true;
                btnDespachar.textContent = 'Despachando...';
            }
            
            cerrarConfirmarDespacho();
            
            fetch('api/despachar_resumen_cc.php', { method: 'POST' })
                .then(res => res.json())
                .then(data => {
                    if (!data.success) {
                        showToast(data.message || 'Error al despachar', 'error');
                        return;
                    }
                    showToast(data.message || 'Resumen despachado con éxito', 'success');
                    cargarDatosCC();
                })
                .catch(err => {
                    console.error(err);
                    showToast('Error al conectar con el despachador', 'error');
                })
                .finally(() => {
                    despachandoCC = false;
                    if (btnDespachar) {
                        btnDespachar.disabled = false;
                        btnDespachar.textContent = '⚡ Despachar Resumen Ahora';
                    }
                });
        }

        function reenviarBitacoraCC(logId) {
            const nuevoCorreo = prompt('Re-enviar a correo alternativo (dejar vacío para usar el original):');
            if (nuevoCorreo === null) return;

            fetch('api/reenviar_informe_cc.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ log_id: logId, nuevo_correo: nuevoCorreo.trim() })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    showToast(data.message || 'Error al re-enviar', 'error');
                    return;
                }
                showToast(data.message || 'Informe re-enviado con éxito.', 'success');
                cargarDatosCC();
            });
        }

        document.addEventListener('DOMContentLoaded', () => {
            cargarDatosCC();
            setInterval(actualizarTemporizadorCorte, 60000); // Actualizar cuenta regresiva cada 1 minuto
        });
    </script>
    <!-- MODAL LOGOUT CC -->
    <div id="modalLogout" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(15,23,42,0.75); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
        <div style="background:#1e293b; border:1px solid #334155; border-radius:12px; padding:28px; max-width:380px; width:90%; box-shadow:0 20px 40px rgba(0,0,0,0.4);">
            <h3 style="margin:0 0 10px; color:#f1f5f9; font-size:1.1rem;">Cerrar Sesión</h3>
            <p style="margin:0 0 24px; color:#94a3b8; font-size:0.9rem;">¿Está seguro que desea cerrar sesión del Portal de Cuentas Corrientes?</p>
            <div style="display:flex; gap:10px; justify-content:flex-end;">
                <button type="button" onclick="cerrarModalLogout()" style="padding:8px 16px; border-radius:6px; border:1px solid #475569; background:transparent; color:#94a3b8; cursor:pointer; font-size:0.9rem;">Cancelar</button>
                <a href="api/auth/logout.php" style="padding:8px 16px; border-radius:6px; background:#dc2626; color:#fff; text-decoration:none; font-size:0.9rem; font-weight:600;">Sí, cerrar sesión</a>
            </div>
        </div>
    </div>
    <script>
        function abrirModalLogout() { document.getElementById('modalLogout').style.display = 'flex'; }
        function cerrarModalLogout() { document.getElementById('modalLogout').style.display = 'none'; }
    </script>
</body>
</html>
