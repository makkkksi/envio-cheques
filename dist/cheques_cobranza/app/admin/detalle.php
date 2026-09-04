<?php
/**
 * admin/detalle.php
 * 
 * Ficha detallada de una cobranza para Tesorería.
 * Permite visualizar comprobantes, fotos de cheques, auditoría y cambiar estados.
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
    <title>Detalle de Cobranza — Tesorería</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=1">
    <link rel="stylesheet" href="css/shell.css?v=20260828-session-1">
</head>
<body data-can-manage-cheques="<?php echo userHasPermission($rolUsuario, 'cheques.manage') ? '1' : '0'; ?>">

    <div id="viewDetalleCobranza">
        <!-- HEADER -->
        <header class="admin-header">
            <div class="admin-header-content">
                <div style="display: flex; align-items: center; gap: 16px;">
                    <a href="index.php" style="color: white; text-decoration: none; font-weight: 700; font-size: 0.95rem; background: rgba(255,255,255,0.2); padding: 6px 14px; border-radius: 6px;">
                        &larr; Volver al Listado
                    </a>
                    <h1 style="margin: 0;">Detalle de Cobranza</h1>
                </div>
                <span id="lblEstadoBadge" class="status-badge">Cargando...</span>
            </div>
        </header>

        <main class="admin-container">

            <!-- SECCIÓN 1: DATOS CLIENTE Y FACTURACIÓN -->
            <section class="detail-card">
                <h3 class="detail-section-title">Información de la Factura y Cliente</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <p>Empresa del Holding</p>
                        <strong id="lblEmpresa">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>N° Factura ERP</p>
                        <strong id="lblFactura" style="color: var(--color-primary);">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>Razón Social Cliente</p>
                        <strong id="lblCliente">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>RUT Cliente</p>
                        <strong id="lblRut">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>Monto Factura (ERP)</p>
                        <strong id="lblMontoFactura" style="color: var(--color-primary);">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>Total en Cheques</p>
                        <strong id="lblTotalCheques" style="color: #166534;">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>Vendedor que Registró</p>
                        <strong id="lblVendedor">-</strong>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 2: DATOS DEL ENVÍO LOGÍSTICO -->
            <section class="detail-card">
                <h3 class="detail-section-title">Gestión de Despacho / Envío Físico</h3>
                <div class="detail-grid">
                    <div class="detail-item">
                        <p>Modalidad de Entrega</p>
                        <strong id="lblTipoEntrega">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>N° Seguimiento / OT</p>
                        <strong id="lblSeguimiento">-</strong>
                    </div>
                    <div class="detail-item">
                        <p>Comprobante Adjunto</p>
                        <div id="boxComprobante" style="margin-top: 4px;">-</div>
                    </div>
                </div>
            </section>

            <!-- SECCIÓN 3: ACCIONES DE TESORERÍA -->
            <section class="detail-card" style="background: #f8fafc; border-left: 4px solid var(--color-primary);">
                <h3 class="detail-section-title">Gestión y Cambio de Estado de Tesorería</h3>
                <div id="boxAccionesTesoreria" style="display: flex; gap: 12px; flex-wrap: wrap; align-items: center;">
                    <span style="font-size: 0.9rem; color: var(--color-text-muted);">Cargando acciones disponibles...</span>
                </div>
            </section>

            <!-- SECCIÓN 4: VISTA PRINCIPAL DE CHEQUES Y FOTOS -->
            <section class="detail-card">
                <h3 class="detail-section-title">Fotos y Detalle de Cheques (Foco Principal)</h3>
                <div id="gridChequesVisuales" class="cheque-gallery-grid">
                    <span style="color: var(--color-text-muted);">Cargando fotos de cheques...</span>
                </div>
            </section>

            <!-- SECCIÓN 5: RESUMEN TABULAR DE CHEQUES -->
            <section class="detail-card">
                <h3 class="detail-section-title">Resumen Tabular de Cheques</h3>
                <div class="table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Banco</th>
                                <th>N° Cheque</th>
                                <th>Monto ($)</th>
                                <th>Vencimiento</th>
                                <th>Foto Cheque</th>
                                <th>Comentario Vendedor</th>
                                <th>Papeleta Depósito</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyChequesDetalle">
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px;">Cargando cheques...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- SECCIÓN 5: HISTORIAL Y BITÁCORA DE AUDITORÍA -->
            <section class="detail-card">
                <h3 class="detail-section-title">Historial de Auditoría (Trazabilidad)</h3>
                <div class="table-wrapper">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Fecha y Hora</th>
                                <th>Usuario</th>
                                <th>Estado Resultante</th>
                                <th>Detalle / Comentario</th>
                            </tr>
                        </thead>
                        <tbody id="tbodyHistorialDetalle">
                            <tr>
                                <td colspan="4" style="text-align: center; padding: 20px;">Cargando historial...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

        </main>
    </div>

    <script src="js/shared_ui.js?v=20260828-session-1"></script>
    <script src="admin.js?v=20260826-1"></script>
</body>
</html>
