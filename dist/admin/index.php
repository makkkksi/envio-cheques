<?php
/**
 * admin/index.php
 * 
 * Portal de Tesorería — Gestión de Cheques
 * Arquitectura Split Screen (50% / 50%) — Diseñado según AI_RULES_UX.md (Sin Emojis)
 */

// Configuración de sesión segura
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => false, // Cambiar a true si se configura HTTPS en producción
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Redirección si no está autenticado
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$rolUsuario = $_SESSION['admin_user_rol'] ?? '';
if ($rolUsuario === 'SUPERVISORA_CC') {
    header('Location: cuentas_corrientes.php');
    exit;
}
if (!in_array($rolUsuario, ['ADMINISTRADOR', 'TESORERIA'])) {
    header('Location: login.php');
    exit;
}
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
            <div class="user-badge" style="display: flex; align-items: center; gap: 12px;">
                <span>Usuario: <?php echo htmlspecialchars($_SESSION['admin_user_nombre'] ?? 'Tesorería'); ?></span>
                <?php if ($rolUsuario === 'ADMINISTRADOR'): ?>
                <a href="cuentas_corrientes.php" style="background: rgba(37,99,235,0.15); color: #1e40af; border: 1px solid rgba(37,99,235,0.3); padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 500; text-decoration: none; transition: all 0.2s;" onmouseover="this.style.background='rgba(37,99,235,0.25)';" onmouseout="this.style.background='rgba(37,99,235,0.15)';">
                    &#8646; Ir a C.Corrientes
                </a>
                <?php endif; ?>
                <button type="button" onclick="abrirModalConfigCC()" style="background: #ffffff; color: #475569; border: 1px solid #cbd5e1; padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 500; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='#f1f5f9';" onmouseout="this.style.background='#ffffff';">
                    Configuración
                </button>
                <button type="button" onclick="abrirModalLogout()" style="background: transparent; color: #64748b; border: 1px solid #e2e8f0; padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: 500; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.background='rgba(220,38,38,0.1)';this.style.color='#b91c1c';this.style.borderColor='rgba(220,38,38,0.3)';" onmouseout="this.style.background='transparent';this.style.color='#64748b';this.style.borderColor='#e2e8f0';">
                    Cerrar Sesión
                </button>
            </div>
        </header>

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
                        <button type="button" class="segmented-tab" data-estado="PENDIENTE_ENVIO">
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
                    <div class="empty-detail-icon">📁</div>
                    <h3 style="font-size: 0.95rem; font-weight: 600; color: var(--color-text-secondary); margin-bottom: 4px;">Selecciona una cobranza de la lista para auditar</h3>
                    <p style="font-size: 0.8rem; margin-bottom: 12px;">Haz clic en cualquier fila de la izquierda para inspeccionar sus cheques, comprobante y trazabilidad.</p>
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
                        <div class="panel-section" style="border: none; padding: 0;">
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
                        <div class="panel-section" id="sectionPanelFacturas" style="display: none;">
                            <h4 class="panel-section-title">Facturas Canceladas con este Pago</h4>
                            <div id="listPanelFacturas" class="facturas-breakdown-list" style="display: flex; flex-direction: column; gap: 8px; margin-top: 8px;"></div>
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
                                <div class="kv-item" style="grid-column: span 2;">
                                    <p>Comprobante Físico Adjunto</p>
                                    <div id="boxPanelComprobante" style="margin-top: 6px;">-</div>
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

    <!-- MODAL LOGOUT -->
    <div id="modalLogout" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(15,23,42,0.75); backdrop-filter:blur(4px); align-items:center; justify-content:center;">
        <div style="background:#1e293b; border:1px solid #334155; border-radius:12px; padding:28px; max-width:380px; width:90%; box-shadow:0 20px 40px rgba(0,0,0,0.4);">
            <h3 style="margin:0 0 10px; color:#f1f5f9; font-size:1.1rem;">Cerrar Sesión</h3>
            <p style="margin:0 0 24px; color:#94a3b8; font-size:0.9rem;">¿Está seguro que desea cerrar sesión del Portal de Tesorería?</p>
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
    <script src="admin.js?v=8"></script>
    <?php include __DIR__ . '/components/modal_config_cc.php'; ?>
</body>
</html>
