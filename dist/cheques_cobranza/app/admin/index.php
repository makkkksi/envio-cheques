<?php
/**
 * admin/index.php
 * 
 * Portal de Tesorería — Gestión de Cheques
 * Arquitectura Split Screen (50% / 50%) — Diseñado según AI_RULES_UX.md (Sin Emojis)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

$adminUser = requireAdminPage('cheques.view');
$rolUsuario = $adminUser['rol'];
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>Portal de Tesorería — Gestión de Cheques</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=4">
    <link rel="stylesheet" href="css/shell.css?v=20260828-session-1">
    <link rel="stylesheet" href="css/modal_config_cc.css?v=20260826-4">
</head>
<body data-can-manage-cheques="<?php echo userHasPermission($rolUsuario, 'cheques.manage') ? '1' : '0'; ?>">

    <div class="app-viewport">

        <!-- 1. HEADER MODULAR COMPARTIDO (SAAS SHELL) -->
        <?php 
        $CURRENT_MODULE = 'cheques'; 
        require_once __DIR__ . '/includes/app_header.php'; 
        ?>

        <!-- 3. SPLIT SCREEN LAYOUT (50% TABLA / 50% DRAWER INSPECTOR) -->
        <div class="split-view-container">

            <!-- LADO IZQUIERDO: TABLA MAESTRA (50% ANCHO EXACTO) -->
            <main class="master-panel">
                <!-- 2. BARRA DE CONTROL SEGMENTADO Y FILTROS -->
                <div class="control-toolbar">
                    
                    <!-- CONTROL SEGMENTADO (PESTAÑAS CON MÉTRICAS INTEGRADAS - CON ICONOS VECTORIALES) -->
                    <div class="segmented-tabs" id="segmentedTabs">
                        <button type="button" class="segmented-tab active" data-estado="BANDEJA_TRABAJO">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 16 12 14 15 10 15 8 12 2 12"></polyline><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"></path></svg>
                            Bandeja de Entrada <span class="tab-count" id="cntBandeja">0</span>
                        </button>
                        <button type="button" class="segmented-tab" data-estado="RECIBIDO_TESORERIA">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
                            Enviados a C.Corrientes <span class="tab-count" id="cntRecibidos">0</span>
                        </button>
                        <button type="button" class="segmented-tab" data-estado="DEPOSITADO">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg>
                            Enviados a Optimus <span class="tab-count" id="cntDepositados">0</span>
                        </button>
                        <button type="button" class="segmented-tab" data-estado="RECHAZADO">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>
                            Rechazados <span class="tab-count" id="cntRechazados">0</span>
                        </button>
                        <button type="button" class="segmented-tab" data-estado="PENDIENTE_ENVIO" style="display: none;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                            Por Enviar <span class="tab-count" id="cntPendientes">0</span>
                        </button>
                        <button type="button" class="segmented-tab" data-estado="TODOS">
                            <svg xmlns="http://www/w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="12 2 2 7 12 12 22 7 12 2"></polygon><polyline points="2 17 12 22 22 17"></polyline><polyline points="2 12 12 17 22 12"></polyline></svg>
                            Todos <span class="tab-count" id="cntTotal">0</span>
                        </button>
                        
                    </div>

                    <!-- FILTROS COMPACTOS -->
                    <div class="filter-row" id="filterRowAdmin">
                        <div class="search-field">
                            <input type="text" id="inputBuscarAdmin" placeholder="Buscar Factura, RUT, Cliente o Vendedor..." style="padding-left: 12px;">
                        </div>

                        <select id="selectFiltroBandeja" class="select-compact">
                            <option value="TODOS_BANDEJA">Ver Todos (Bandeja)</option>
                            <option value="EN_TRANSITO">En Tránsito</option>
                            <option value="ENTREGADO_SANTIAGO">Entregado Stgo</option>
                        </select>

                        <select id="selectEmpresaAdmin" class="select-compact">
                            <option value="">Todas las Empresas</option>
                            <option value="1">Automarco LTDA</option>
                            <option value="2">HD Automarco S.A</option>
                            <option value="3">Autotec S.A</option>
                            <option value="4">Gabtec S.A</option>
                        </select>

                        <select id="selectOrdenAdmin" class="select-compact">
                            <option value="fecha_desc">Más recientes primero</option>
                            <option value="fecha_asc">Más antiguos primero</option>
                            <option value="monto_desc">Mayor monto cheques</option>
                            <option value="monto_asc">Menor monto cheques</option>
                        </select>
                    </div>
                </div>

                <div class="table-scroll-container">
                    <table class="master-table">
                        <thead>
                            <tr>
                                <th>Empresa</th>
                                <th>Vendedor</th>
                                <th>Cliente / RUT</th>
                                <th>Total Cheques</th>
                                <th>Estado</th>
                                <th>Fecha</th>
                            </tr>
                        </thead>
                        <tbody id="masterTableBody">
                            <tr>
                                <td colspan="6" class="td-loading">
                                    Cargando cobranzas...
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </main>

            <!-- LADO DERECHO: DRAWER INSPECTOR DE DETALLE (50% ANCHO EXACTO) -->
            <aside class="detail-panel" id="detailPanel">
                <!-- EMPTY STATE -->
                <div class="empty-detail-state" id="emptyDetailState">
                    <div class="empty-detail-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"></path></svg></div>
                    <h3 class="empty-detail-state-h3">Selecciona una cobranza de la lista para auditar</h3>
                    <p class="empty-detail-state-p">Haz clic en cualquier fila de la izquierda para inspeccionar sus cheques, comprobante y trazabilidad.</p>
                </div>

                <!-- CONTENIDO DINÁMICO DEL DRAWER -->
                <div id="activeDetailContent" class="active-detail-content">
                    
                    <!-- CABECERA DEL DRAWER -->
                    <div class="detail-header-bar">
                        <div class="detail-header-info">
                            <strong id="lblPanelFacturaTitle" class="lbl-panel-factura-title">Factura N° -</strong>
                            <span id="lblPanelEstadoBadge" class="badge">ESTADO</span>
                        </div>
                        <button type="button" class="detail-close-btn" id="btnCerrarPanelDetalle" title="Cerrar inspector">&times;</button>
                    </div>

                    <!-- CUERPO SCROLLABLE DEL DRAWER -->
                    <div class="detail-scroll-body">

                        <!-- SECCIÓN 1: RESUMEN FACTURA Y CLIENTE -->
                        <div class="panel-section panel-section-no-border">
                            <div class="detail-info-grid">
                                <div class="detail-info-item"><span class="label">Razón Social Cliente</span><strong class="value" id="lblPanelCliente">-</strong></div>
                                <div class="detail-info-item"><span class="label">RUT Cliente</span><strong class="value" id="lblPanelRut">-</strong></div>
                                <div class="detail-info-item"><span class="label">Empresa</span><strong class="value" id="lblPanelEmpresa">-</strong></div>
                                <div class="detail-info-item"><span class="label">Vendedor</span><span id="lblPanelVendedor" class="value">-</span></div>
                                <div class="detail-info-item"><span class="label">Monto Total Cobrado</span><strong class="value" id="lblPanelMontoFactura">-</strong></div>
                                <div class="detail-info-item"><span class="label">Total Cheques</span><strong class="value" id="lblPanelTotalCheques">-</strong></div>
                            </div>
                        </div>

                        <!-- SECCIÓN 1.5: DESGLOSE DE FACTURAS CUBIERTAS (MULTI-FACTURA) -->
                        <div class="panel-section panel-section-facturas" id="sectionPanelFacturas">
                            <h4 class="panel-section-title">Facturas Canceladas con este Pago</h4>
                            <div id="listPanelFacturas" class="facturas-breakdown-list list-panel-facturas"></div>
                        </div>

                        <!-- SECCIÓN 2: CHEQUES ADJUNTOS -->
                        <div class="panel-section">
                            <h4 class="panel-section-title">Cheques Adjuntos (<span id="lblPanelCntCheques">0</span>)</h4>
                            <div id="boxPanelChequesList">
                                <!-- Lista de cheques renderizada dinámicamente -->
                            </div>
                        </div>

                        <!-- SECCIÓN 3: COMPROBANTE DE DESPACHO -->
                        <div class="panel-section">
                            <h4 class="panel-section-title">Comprobante de Despacho</h4>
                            <div class="kv-grid">
                                <div class="kv-item">
                                    <p>Modalidad Entrega</p>
                                    <strong id="lblPanelTipoEntrega">-</strong>
                                </div>
                                <div class="kv-item">
                                    <p>N° Seguimiento / OT</p>
                                    <strong id="lblPanelSeguimiento">-</strong>
                                </div>
                                <div class="kv-item kv-item-full">
                                    <p>Comprobante Físico Adjunto</p>
                                    <div id="boxPanelComprobante" class="box-panel-comprobante">-</div>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- SECCIÓN 5: FOOTER DE ACCIÓN DINÁMICO (STICKY BOTTOM - LEY DE FITTS) -->
                    <div class="admin-detail-footer" id="boxPanelAcciones">
                        <!-- CTA primario dinámico según estado -->
                    </div>

                </div>
            </aside>

        </div>

    </div>

    <script src="js/shared_ui.js?v=20260828-session-1"></script>
    <script src="admin.js?v=20260826-1"></script>
    <script src="js/modal_config_cc.js?v=20260826-3"></script>
    <?php if (userHasPermission($rolUsuario, 'cc.manage') || userHasPermission($rolUsuario, 'companies.manage')): ?>
    <?php include __DIR__ . '/components/modal_config_cc.php'; ?>
    <?php endif; ?>
</body>
</html>
