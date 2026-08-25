<?php
/**
 * admin/cuentas_corrientes.php
 * 
 * Portal Exclusivo de Cuentas Corrientes — Gestión y Distribución de Cheques
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

$adminUser = requireAdminPage('cc.view');
$rolUsuario = $adminUser['rol'];
$canManageCc = userHasPermission($rolUsuario, 'cc.manage');
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>Portal Cuentas Corrientes — Gestión y Distribución</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/cuentas_corrientes.css">
    <link rel="stylesheet" href="css/shell.css?v=1">
    <link rel="stylesheet" href="css/modal_config_cc.css">
</head>
<body data-can-manage-cc="<?php echo $canManageCc ? '1' : '0'; ?>">

    <!-- HEADER MODULAR COMPARTIDO (SAAS SHELL) -->
    <?php 
    $CURRENT_MODULE = 'cuentas_corrientes'; 
    require_once __DIR__ . '/includes/app_header.php'; 
    ?>

    <div class="container-cc">
        <?php if (!$canManageCc): ?>
        <div class="cc-readonly-notice" role="status">
            Modo consulta: puede revisar la cola, los cheques y la trazabilidad, pero no cambiar configuración ni despachar informes.
        </div>
        <?php endif; ?>
        
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
                <button type="button" class="btn-action btn-success" id="btnDespacharResumen" onclick="confirmarDespachoModal()" style="width: 100%; height: 46px; font-size: 0.9rem;" disabled<?php echo $canManageCc ? '' : ' hidden'; ?>>
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

    <?php if ($canManageCc): ?>
    <?php include __DIR__ . '/components/modal_config_cc.php'; ?>
    <?php endif; ?>

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

    <script src="js/shared_ui.js?v=1"></script>
    <script src="js/modal_config_cc.js"></script>
    <script src="js/cuentas_corrientes.js?v=11"></script>
</body>
</html>
