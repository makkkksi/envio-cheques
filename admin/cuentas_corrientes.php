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
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #f8fafc;
            color: #0f172a;
            margin: 0;
            padding: 0;
        }
        .header-cc {
            background: #0f172a;
            color: white;
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .badge-cc {
            background: #2563eb;
            color: white;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 6px;
            letter-spacing: 0.05em;
        }
        .cutoff-timer {
            font-size: 0.85rem;
            color: #94a3b8;
            background: rgba(255,255,255,0.05);
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        .cutoff-timer strong {
            color: #38bdf8;
        }
        .container-cc {
            max-width: 1200px;
            margin: 32px auto;
            padding: 0 20px;
            display: flex;
            flex-direction: column;
            gap: 28px;
        }
        
        /* KPI Strip Grid */
        .kpi-strip {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        @media (max-width: 768px) {
            .kpi-strip {
                grid-template-columns: 1fr;
            }
        }
        .kpi-card {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        .kpi-card-action {
            border: 1px solid #dcfce7;
            background: #f0fdf4;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .kpi-label {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 600;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 1.75rem;
            font-weight: 800;
            color: #0f172a;
        }
        .kpi-subtext {
            font-size: 0.8rem;
            color: #94a3b8;
            margin-top: 4px;
        }

        .card-cc {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .card-title {
            margin-top: 0;
            margin-bottom: 16px;
            font-size: 1.15rem;
            font-weight: 700;
            color: #0f172a;
            border-bottom: 1px solid #f1f5f9;
            padding-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .btn-action {
            background: #2563eb;
            color: white;
            border: none;
            padding: 10px 18px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .btn-action:hover:not(:disabled) {
            background: #1d4ed8;
        }
        .btn-action:disabled {
            background: #cbd5e1;
            color: #94a3b8;
            cursor: not-allowed;
        }
        .btn-success {
            background: #16a34a;
        }
        .btn-success:hover:not(:disabled) {
            background: #15803d;
        }
        .btn-secondary {
            background: #e2e8f0;
            color: #334155;
        }
        .btn-secondary:hover {
            background: #cbd5e1;
        }
        
        /* Table styles & alignments */
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }
        th, td {
            padding: 12px 14px;
            text-align: left;
        }
        th {
            background: #f8fafc;
            color: #64748b;
            font-weight: 600;
            border-bottom: 2px solid #e2e8f0;
        }
        tr {
            border-bottom: 1px solid #f1f5f9;
        }
        .text-right {
            text-align: right;
        }
        .font-mono {
            font-family: 'JetBrains Mono', monospace;
        }
        .monto-destacado {
            font-weight: 700;
            color: #166534;
        }

        /* Interactive Filter Bar */
        .filter-bar {
            display: flex;
            gap: 16px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .filter-input {
            padding: 8px 12px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 0.85rem;
            outline: none;
            transition: border 0.15s ease;
        }
        .filter-input:focus {
            border-color: #2563eb;
        }
        .flex-grow {
            flex-grow: 1;
        }

        /* Segmented History Tabs */
        .history-tabs {
            display: flex;
            border-bottom: 2px solid #e2e8f0;
            margin-bottom: 16px;
            gap: 8px;
        }
        .history-tab {
            padding: 10px 16px;
            font-weight: 600;
            font-size: 0.85rem;
            color: #64748b;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            margin-bottom: -2px;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .history-tab:hover {
            color: #0f172a;
        }
        .history-tab.active {
            color: #2563eb;
            border-bottom-color: #2563eb;
        }
        .tab-counter {
            font-size: 0.75rem;
            background: #cbd5e1;
            color: #475569;
            padding: 2px 6px;
            border-radius: 999px;
            margin-left: 6px;
        }
        .history-tab.active .tab-counter {
            background: #dbeafe;
            color: #1e40af;
        }

        /* Toast notifications */
        .toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .toast {
            background: #0f172a;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 0.9rem;
            animation: fadeIn 0.3s ease;
        }
        .toast.error { background: #dc2626; }
        .toast.success { background: #16a34a; }

        /* Modal Styles */
        .modal-cc {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(4px);
            align-items: center;
            justify-content: center;
        }
        .modal-content-cc {
            background-color: #f8fafc;
            margin: auto;
            padding: 24px;
            border: 1px solid #cbd5e1;
            width: 90%;
            max-width: 700px;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
        }
        .close-modal {
            color: #64748b;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .close-modal:hover {
            color: #0f172a;
        }
    </style>
</head>
<body>

    <!-- HEADER SUPERIOR EXCLUSIVO PARA CUENTAS CORRIENTES -->
    <header class="header-cc">
        <div class="header-title">
            <span class="badge-cc">CUENTAS CORRIENTES</span>
            <h1 style="margin: 0; font-size: 1.25rem; font-weight: 700;">Portal de Gestión y Distribución de Cheques</h1>
        </div>
        <div style="display: flex; align-items: center; gap: 16px;">
            <div class="cutoff-timer" id="txtCutoffTimer">Corte Hoy: <strong id="lblCutoffHour">--:--</strong> - <strong id="lblCutoffRemaining">Faltan --h --m</strong></div>
            <span style="font-size: 0.875rem; color: #94a3b8;">Usuario: <strong><?php echo htmlspecialchars($_SESSION['admin_user_nombre'] ?? 'Supervisora CC'); ?></strong></span>
            <button type="button" class="btn-action btn-secondary" onclick="abrirModalConfigCC()" style="font-size: 0.85rem; padding: 6px 14px;">Configuración</button>
            <button type="button" onclick="abrirModalLogout()" style="background: rgba(255,255,255,0.06); color: #94a3b8; border: 1px solid rgba(255,255,255,0.1); padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='rgba(220,38,38,0.15)';this.style.color='#fca5a5';" onmouseout="this.style.background='rgba(255,255,255,0.06)';this.style.color='#94a3b8';">
                Cerrar Sesión
            </button>
            <?php if ($rolUsuario === 'ADMINISTRADOR'): ?>
                <!-- Links removed per requirements -->
            <?php endif; ?>
        </div>
    </header>

    <div class="container-cc">
        
        <!-- RESUMEN EJECUTIVO (KPI STRIP - SIN MONTOS) -->
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
                <button type="button" class="btn-action btn-success" id="btnDespacharResumen" onclick="confirmarDespachoModal()" style="width: 100%; height: 50px; font-size: 1rem;" disabled>
                    Despachar Resumen Ahora
                </button>
            </div>
        </div>

        <!-- BLOQUE: CHEQUES PENDIENTES DE DESPACHO (COLA DE SALIDA) -->
        <div class="card-cc">
            <h2 class="card-title">Cheques Pendientes de Despacho (Cola de Salida)</h2>
            
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

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Cliente / RUT</th>
                            <th>Vendedor</th>
                            <th>Factura</th>
                            <th>Banco</th>
                            <th>N° Cheque</th>
                            <th class="text-right">Monto</th>
                            <th>Vencimiento</th>
                        </tr>
                    </thead>
                    <tbody id="tblChequesEnColaCC">
                        <tr><td colspan="8" style="text-align: center; color: #94a3b8;">Cargando cola de cheques...</td></tr>
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

            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Fecha y Hora</th>
                            <th>Empresa Origen / Destinatario</th>
                            <th style="text-align: center;">Total Cheques</th>
                            <th>Estado</th>
                            <th style="text-align: right;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="tblBitacoraEnviosCC">
                        <tr><td colspan="5" style="text-align: center; color: #94a3b8;">Cargando historial...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

    <!-- MODAL DE CONFIGURACIÓN -->
    <div id="modalConfigCC" class="modal-cc">
        <div class="modal-content-cc">
            <span class="close-modal" onclick="cerrarModalConfigCC()">&times;</span>
            <h2 style="margin-top: 0; border-bottom: 1px solid #e2e8f0; padding-bottom: 12px; font-size: 1.25rem;">Configuración del Distribuidor Diario</h2>
            <p style="font-size: 0.85rem; color: #64748b; margin-top: -6px; margin-bottom: 16px;">
                Gestión horaria de liberación y distribución de resúmenes diarios a las respectivas digitadoras.
            </p>
            
                <div style="flex-grow: 1;">
                    <label style="display: block; font-size: 0.85rem; font-weight: 600; color: #475569; margin-bottom: 6px;">Hora de Corte / Despacho Diario:</label>
                    <input type="time" id="inputHoraDespachoCC" style="width: 100%; box-sizing: border-box; font-size: 1rem; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; margin-bottom: 8px;">
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 0.85rem; font-weight: 600; color: #475569; cursor: pointer;">
                        <input type="checkbox" id="chkAutoDispatch" style="width: 16px; height: 16px;"> Habilitar Despacho Automático
                    </label>
                </div>
                <button type="button" class="btn-action" onclick="guardarConfiguracionCC()">Guardar Hora</button>
            </div>

            <h3 style="margin-top: 0; margin-bottom: 4px; font-size: 0.95rem; color: #334155;">Matriz de Asignación a Digitadoras (Gestión de Reemplazos):</h3>
            <p style="font-size: 0.8rem; color: #94a3b8; margin-top: 0; margin-bottom: 12px; font-style: italic;">
                * Estos correos recibirán automáticamente el resumen PDF/Excel al ejecutar el despacho diario.
            </p>
            <div style="max-height: 250px; overflow-y: auto; border: 1px solid #e2e8f0; border-radius: 8px; background: white;">
                <table>
                    <thead>
                        <tr>
                            <th>Empresa</th>
                            <th>Correo Digitadora Asignada</th>
                        </tr>
                    </thead>
                    <tbody id="tblAsignacionesDigitadorasCC">
                        <tr><td colspan="2" style="text-align: center; color: #94a3b8;">Cargando asignaciones...</td></tr>
                    </tbody>
                </table>
            </div>
            
            <div style="margin-top: 24px; text-align: right; display: flex; justify-content: flex-end; gap: 12px;">
                <button type="button" class="btn-action btn-success" onclick="guardarConfiguracionCC()">Aplicar Cambios</button>
                <button type="button" class="btn-action btn-secondary" onclick="cerrarModalConfigCC()">Cerrar</button>
            </div>
        </div>
    </div>

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
        <div class="modal-content-cc" style="max-width: 550px;">
            <span class="close-modal" onclick="cerrarLogDetalle()">&times;</span>
            <h2 style="margin-top:0; color:#1e3a8a; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">Detalle de Despacho</h2>
            
            <div id="logDetalleContent" style="background:#f1f5f9; padding:16px; border-radius:8px; border:1px solid #e2e8f0; margin:16px 0; font-size: 0.95rem;">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:12px; margin-top:20px;">
                <button type="button" class="btn-action btn-secondary" onclick="cerrarLogDetalle()">Cerrar</button>
            </div>
        </div>
    </div>

    <script>
        // Caché de datos locales
        let cacheChequesCola = [];
        let cacheHistorialLog = [];
        let cacheEmpresasMatriz = [];
        let horaCorteGlobal = "16:00";
        let historyFilterSelected = 'Todos';

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

        function abrirModalConfigCC() {
            document.getElementById('modalConfigCC').style.display = 'flex';
        }

        function cerrarModalConfigCC() {
            document.getElementById('modalConfigCC').style.display = 'none';
        }

        function confirmarDespachoModal() {
            if (cacheChequesCola.length === 0) return;
            
            // Construir matriz de destinatarios y cantidad sin montos
            const resumenMap = {};
            cacheChequesCola.forEach(chq => {
                const empMatriz = cacheEmpresasMatriz.find(e => e.nombre === chq.empresa_nombre);
                const email = empMatriz ? empMatriz.email_digitadora : 'No Asignada';
                
                if (!resumenMap[chq.empresa_nombre]) {
                    resumenMap[chq.empresa_nombre] = { email: email, count: 0 };
                }
                resumenMap[chq.empresa_nombre].count++;
            });

            let htmlMatriz = "";
            for (const [emp, det] of Object.entries(resumenMap)) {
                htmlMatriz += `
                    <div style="display:flex; justify-content:space-between; border-bottom:1px solid #cbd5e1; padding-bottom:4px;">
                        <span><strong>${emp}</strong> (${det.count} cheque(s)) ➔ ${det.email}</span>
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
                <p><strong>Fecha y Hora:</strong> ${log.fecha_envio}</p>
                <p><strong>Empresa Origen:</strong> ${log.empresa || 'Consolidado'}</p>
                <p><strong>Destinatario:</strong> ${log.destinatario}</p>
                <p><strong>Cantidad de Cobranzas:</strong> ${log.cantidad_cobranzas}</p>
                <p><strong>Estado:</strong> ${log.estado_envio}</p>
            `;
            if (log.estado_envio === 'FALLIDO' && log.error_mensaje) {
                html += `<p style="color: #dc2626;"><strong>Error:</strong> ${log.error_mensaje}</p>`;
            }
            document.getElementById('logDetalleContent').innerHTML = html;
            document.getElementById('modalLogDetalle').style.display = 'flex';
        }

        function cerrarLogDetalle() {
            document.getElementById('modalLogDetalle').style.display = 'none';
        }

        function actualizarTemporizadorCorte() {
            if (!horaCorteGlobal) return;
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

                    // Renderizar matriz de empresas en el modal
                    cacheEmpresasMatriz = info.empresas;
                    const tbodyEmp = document.getElementById('tblAsignacionesDigitadorasCC');
                    tbodyEmp.innerHTML = info.empresas.map(emp => {
                        return `
                            <tr>
                                <td style="font-weight: 600; font-size: 0.85rem;">${emp.nombre}</td>
                                <td>
                                    <input type="email" id="email_emp_${emp.id}" value="${emp.email_digitadora || ''}" style="width: 100%; box-sizing: border-box; padding: 6px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem;">
                                </td>
                            </tr>
                        `;
                    }).join('');

                    // Cachear cheques en cola y renderizar
                    cacheChequesCola = info.cheques_en_cola || [];
                    cacheHistorialLog = info.log_envios || [];
                    
                    actualizarKPIStrip();
                    filtrarColaDeCheques();
                    renderHistorialTable();
                })
                .catch(err => {
                    console.error(err);
                    showToast('Error de conexión', 'error');
                });
        }

        function actualizarKPIStrip() {
            const count = cacheChequesCola.length;
            const uniqueEmpresas = new Set();
            const uniqueClientes = new Set();

            cacheChequesCola.forEach(chq => {
                uniqueEmpresas.add(chq.empresa_nombre);
                uniqueClientes.add(chq.rut_cliente);
            });

            document.getElementById('kpiCount').textContent = count;
            document.getElementById('kpiEmpresas').textContent = uniqueEmpresas.size;
            document.getElementById('kpiDetails').textContent = `${uniqueClientes.size} Cliente(s) Afectados`;

            const btnDespachar = document.getElementById('btnDespacharResumen');
            if (count > 0) {
                btnDespachar.disabled = false;
                btnDespachar.removeAttribute('title');
            } else {
                btnDespachar.disabled = true;
                btnDespachar.setAttribute('title', 'No hay cheques pendientes para despachar hoy');
            }
        }

        function filtrarColaDeCheques() {
            const searchVal = document.getElementById('filterBuscar').value.trim().toLowerCase();
            const empVal = document.getElementById('filterEmpresa').value;
            const ordVal = document.getElementById('filterOrden').value;

            // Filtrado
            let filtered = cacheChequesCola.filter(chq => {
                const matchSearch = !searchVal || 
                    chq.numero_factura.toLowerCase().includes(searchVal) ||
                    chq.razon_social_cliente.toLowerCase().includes(searchVal) ||
                    chq.rut_cliente.toLowerCase().includes(searchVal) ||
                    chq.vendedor_nombre.toLowerCase().includes(searchVal) ||
                    chq.numero_cheque.toLowerCase().includes(searchVal);
                
                const matchEmpresa = !empVal || chq.empresa_nombre === empVal;

                return matchSearch && matchEmpresa;
            });

            // Ordenamiento
            filtered.sort((a, b) => {
                if (ordVal === 'venc_asc') {
                    return new Date(a.fecha_vencimiento) - new Date(b.fecha_vencimiento);
                } else if (ordVal === 'venc_desc') {
                    return new Date(b.fecha_vencimiento) - new Date(a.fecha_vencimiento);
                } else if (ordVal === 'monto_desc') {
                    return parseFloat(b.monto_cheque) - parseFloat(a.monto_cheque);
                } else if (ordVal === 'monto_asc') {
                    return parseFloat(a.monto_cheque) - parseFloat(b.monto_cheque);
                } else if (ordVal === 'empresa') {
                    return a.empresa_nombre.localeCompare(b.empresa_nombre);
                }
                return 0;
            });

            // Renderizado
            const tbodyChq = document.getElementById('tblChequesEnColaCC');
            if (filtered.length === 0) {
                tbodyChq.innerHTML = `<tr><td colspan="8" style="text-align: center; color: #94a3b8; padding: 20px;">No hay cheques en cola que coincidan con los filtros.</td></tr>`;
            } else {
                tbodyChq.innerHTML = filtered.map(chq => {
                    const montoFmt = '$' + parseInt(chq.monto_cheque).toLocaleString('es-CL');
                    const fechaFmt = new Date(chq.fecha_vencimiento + 'T12:00:00').toLocaleDateString('es-CL');
                    return `
                        <tr>
                            <td style="font-weight: 600;">${chq.empresa_nombre}</td>
                            <td>
                                <div style="font-weight: 600;">${chq.razon_social_cliente}</div>
                                <div style="font-size: 0.8rem; color: #64748b;">${chq.rut_cliente}</div>
                            </td>
                            <td>${chq.vendedor_nombre}</td>
                            <td>${chq.numero_factura}</td>
                            <td>${chq.banco}</td>
                            <td style="font-weight: 600;">${chq.numero_cheque}</td>
                            <td class="text-right font-mono monto-destacado">${montoFmt}</td>
                            <td>${fechaFmt}</td>
                        </tr>
                    `;
                }).join('');
            }
        }

        function renderHistorialTable() {
            // Contadores
            const total = cacheHistorialLog.length;
            const exitosos = cacheHistorialLog.filter(l => l.estado_envio === 'ENVIADO').length;
            const fallidos = cacheHistorialLog.filter(l => l.estado_envio === 'FALLIDO').length;

            document.getElementById('cntHistTodos').textContent = total;
            document.getElementById('cntHistExitosos').textContent = exitosos;
            document.getElementById('cntHistFallidos').textContent = fallidos;

            // Filtrado
            let filtered = cacheHistorialLog;
            if (historyFilterSelected === 'Enviados') {
                filtered = cacheHistorialLog.filter(l => l.estado_envio === 'ENVIADO');
            } else if (historyFilterSelected === 'Fallidos') {
                filtered = cacheHistorialLog.filter(l => l.estado_envio === 'FALLIDO');
            }

            const tbodyBit = document.getElementById('tblBitacoraEnviosCC');
            if (filtered.length === 0) {
                tbodyBit.innerHTML = `<tr><td colspan="5" style="text-align: center; color: #94a3b8; padding: 20px;">No hay registros de envíos para esta categoría.</td></tr>`;
            } else {
                tbodyBit.innerHTML = filtered.map(log => {
                    const esExitoso = log.estado_envio === 'ENVIADO';
                    const badgeStyle = esExitoso 
                        ? 'background: #dcfce7; color: #15803d; border-radius: 9999px; padding: 3px 10px; font-size: 0.8rem; font-weight: 700;' 
                        : 'background: #fee2e2; color: #b91c1c; border-radius: 9999px; padding: 3px 10px; font-size: 0.8rem; font-weight: 700;';
                    
                    return `
                        <tr>
                            <td style="font-weight: 500;">${log.fecha_envio}</td>
                            <td>
                                <div style="font-weight: 600;">${log.empresa || 'Consolidado'}</div>
                                <div style="font-size: 0.8rem; color:#64748b;">Para: ${log.destinatario}</div>
                            </td>
                            <td style="text-align: center; font-weight: 700;">${log.cantidad_cobranzas}</td>
                            <td><span style="${badgeStyle}">${log.estado_envio}</span></td>
                            <td style="text-align: right;">
                                <button type="button" class="btn-action btn-secondary" onclick="abrirLogDetalle(${log.id})" style="padding: 5px 10px; font-size: 0.8rem; margin-right: 4px;">Ver Info</button>
                                <button type="button" class="btn-action btn-secondary" onclick="reenviarBitacoraCC(${log.id})" style="padding: 5px 10px; font-size: 0.8rem;">Re-enviar</button>
                            </td>
                        </tr>
                    `;
                }).join('');
            }
        }

        function filtrarHistorialCC(filterType) {
            historyFilterSelected = filterType;
            
            document.querySelectorAll('.history-tab').forEach(t => t.classList.remove('active'));
            if (filterType === 'Todos') document.getElementById('tabTodos').classList.add('active');
            else if (filterType === 'Enviados') document.getElementById('tabExitosos').classList.add('active');
            else if (filterType === 'Fallidos') document.getElementById('tabFallidos').classList.add('active');

            renderHistorialTable();
        }

        function guardarConfiguracionCC() {
            const inputHora = document.getElementById('inputHoraDespachoCC');
            const asignaciones = [];
            document.querySelectorAll('[id^="email_emp_"]').forEach(inp => {
                asignaciones.push({
                    id: parseInt(inp.id.replace('email_emp_', '')),
                    email: inp.value.trim()
                });
            });

            fetch('api/guardar_configuracion_cc.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    hora_despacho_diario: inputHora.value,
                    despacho_automatico_activado: document.getElementById('chkAutoDispatch').checked ? '1' : '0',
                    asignaciones_empresas: asignaciones
                })
            })
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    showToast(data.message || 'Error al guardar', 'error');
                    return;
                }
                showToast('Configuración guardada correctamente.', 'success');
                cerrarModalConfigCC();
                cargarDatosCC();
            });
        }

        function ejecutarDespachoCC() {
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
