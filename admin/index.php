<?php
/**
 * admin/index.php
 * 
 * Portal de Tesorería — Gestión de Cheques
 * Arquitectura Split Screen (50% / 50%) — Diseñado según AI_RULES_UX.md (Sin Emojis)
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal de Tesorería — Gestión de Cheques</title>
    <link rel="stylesheet" href="styles.css?v=4">
</head>
<body>

    <div class="app-viewport">

        <!-- 1. HEADER SUPERIOR DELGADO Y LIMPIO -->
        <header class="app-header">
            <div class="app-title-group">
                <span class="app-logo-badge">TESORERÍA</span>
                <h1>Portal de Tesorería — Gestión de Cheques</h1>
            </div>
            <div class="user-badge">
                Usuario: Tesorería / Admin
            </div>
        </header>

        <!-- 2. BARRA DE CONTROL SEGMENTADO Y FILTROS -->
        <div class="control-toolbar">
            
            <!-- CONTROL SEGMENTADO (PESTAÑAS CON MÉTRICAS INTEGRADAS - SIN EMOJIS) -->
            <div class="segmented-tabs" id="segmentedTabs">
                <button type="button" class="segmented-tab active" data-estado="BANDEJA_TRABAJO">
                    Bandeja de Trabajo <span class="tab-count" id="cntBandeja">0</span>
                </button>
                <button type="button" class="segmented-tab" data-estado="EN_TRANSITO">
                    En Tránsito <span class="tab-count" id="cntTransito">0</span>
                </button>
                <button type="button" class="segmented-tab" data-estado="RECIBIDO_TESORERIA">
                    Recibidos <span class="tab-count" id="cntRecibidos">0</span>
                </button>
                <button type="button" class="segmented-tab" data-estado="DEPOSITADO">
                    Depositados <span class="tab-count" id="cntDepositados">0</span>
                </button>
                <button type="button" class="segmented-tab" data-estado="RECHAZADO">
                    Rechazados <span class="tab-count" id="cntRechazados">0</span>
                </button>
                <button type="button" class="segmented-tab" data-estado="PENDIENTE_ENVIO">
                    Por Enviar <span class="tab-count" id="cntPendientes">0</span>
                </button>
                <button type="button" class="segmented-tab" data-estado="TODOS">
                    Todos <span class="tab-count" id="cntTotal">0</span>
                </button>
            </div>

            <!-- FILTROS COMPACTOS -->
            <div class="filter-row">
                <div class="search-field">
                    <input type="text" id="inputBuscarAdmin" placeholder="Buscar Factura, RUT, Cliente o Vendedor..." style="padding-left: 12px;">
                </div>

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

        <!-- 3. SPLIT SCREEN LAYOUT (50% TABLA / 50% DRAWER INSPECTOR) -->
        <div class="split-view-container">

            <!-- LADO IZQUIERDO: TABLA MAESTRA (50% ANCHO EXACTO) -->
            <main class="master-panel">
                <div class="table-scroll-container">
                    <table class="master-table">
                        <thead>
                            <tr>
                                <th>Empresa</th>
                                <th>Factura</th>
                                <th>Cliente / RUT</th>
                                <th>Vendedor</th>
                                <th>Total Cheques</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody id="masterTableBody">
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 32px; color: var(--color-text-muted);">
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
                    <h3 style="font-size: 0.95rem; font-weight: 600; color: var(--color-text-secondary); margin-bottom: 4px;">Selecciona una cobranza</h3>
                    <p style="font-size: 0.8rem;">Haz clic en cualquier fila de la izquierda para inspeccionar sus cheques, comprobante y trazabilidad.</p>
                </div>

                <!-- CONTENIDO DINÁMICO DEL DRAWER -->
                <div id="activeDetailContent" style="display: none; height: 100%; flex-direction: column;">
                    
                    <!-- CABECERA DEL DRAWER -->
                    <div class="detail-header-bar">
                        <div class="detail-header-info">
                            <strong id="lblPanelFacturaTitle" style="font-size: 0.95rem; color: var(--color-primary);">Factura N° -</strong>
                            <span id="lblPanelEstadoBadge" class="badge">ESTADO</span>
                        </div>
                        <button type="button" class="detail-close-btn" onclick="deseleccionarCobranza()" title="Cerrar inspector">&times;</button>
                    </div>

                    <!-- CUERPO SCROLLABLE DEL DRAWER -->
                    <div class="detail-scroll-body">

                        <!-- SECCIÓN 1: RESUMEN FACTURA Y CLIENTE -->
                        <div class="panel-section">
                            <h4 class="panel-section-title">Información General de Factura</h4>
                            <div class="kv-grid">
                                <div class="kv-item" style="grid-column: span 2; background: #eff6ff; padding: 10px; border-radius: 6px; border: 1px solid #bfdbfe; margin-bottom: 4px;">
                                    <p style="text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; color: var(--color-primary); font-size: 0.72rem; margin-bottom: 2px;">Vendedor Responsable de la Gestión</p>
                                    <strong id="lblPanelVendedor" style="font-size: 1.1rem; color: var(--color-primary); font-weight: 800;">-</strong>
                                </div>
                                <div class="kv-item">
                                    <p>Empresa Holding</p>
                                    <strong id="lblPanelEmpresa">-</strong>
                                </div>
                                <div class="kv-item">
                                    <p>RUT Cliente</p>
                                    <strong id="lblPanelRut">-</strong>
                                </div>
                                <div class="kv-item" style="grid-column: span 2;">
                                    <p>Razón Social Cliente</p>
                                    <strong id="lblPanelCliente">-</strong>
                                </div>
                                <div class="kv-item">
                                    <p>Monto Factura ERP</p>
                                    <strong id="lblPanelMontoFactura" style="color: var(--color-primary);">-</strong>
                                </div>
                                <div class="kv-item">
                                    <p>Total Cheques</p>
                                    <strong id="lblPanelTotalCheques" style="color: #166534;">-</strong>
                                </div>
                            </div>
                        </div>

                        <!-- SECCIÓN 2: COMPROBANTE DE DESPACHO -->
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
                                <div class="kv-item" style="grid-column: span 2;">
                                    <p>Comprobante Físico Adjunto</p>
                                    <div id="boxPanelComprobante" style="margin-top: 6px;">-</div>
                                </div>
                            </div>
                        </div>

                        <!-- SECCIÓN 3: CHEQUES ADJUNTOS -->
                        <div class="panel-section">
                            <h4 class="panel-section-title">Cheques Adjuntos (<span id="lblPanelCntCheques">0</span>)</h4>
                            <div id="boxPanelChequesList">
                                <!-- Lista de cheques renderizada dinámicamente -->
                            </div>
                        </div>

                        <!-- SECCIÓN 4: TRAZABILIDAD -->
                        <div class="panel-section">
                            <h4 class="panel-section-title">Trazabilidad del Cheque</h4>
                            <div id="boxPanelStepper" class="stepper-vertical">
                                <!-- Vertical Stepper renderizado dinámicamente -->
                            </div>
                        </div>

                    </div>

                    <!-- SECCIÓN 5: FOOTER DE ACCIÓN DINÁMICO (STICKY BOTTOM - LEY DE FITTS) -->
                    <div class="sticky-bottom-actions" id="boxPanelAcciones">
                        <!-- CTA primario dinámico según estado -->
                    </div>

                </div>
            </aside>

        </div>
    </div>

    <script src="admin.js?v=4"></script>
</body>
</html>
