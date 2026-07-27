<?php
/**
 * admin/index.php
 * 
 * Vista Principal / Dashboard del Portal de Tesorería.
 * Muestra el resumen métrico superior, filtros y la tabla de todas las cobranzas.
 */
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal Tesorería — Gestión de Cheques</title>
    <link rel="stylesheet" href="styles.css?v=1">
</head>
<body>

    <!-- HEADER -->
    <header class="admin-header">
        <div class="admin-header-content">
            <h1>Portal de Tesorería — Gestión de Cheques</h1>
            <span class="admin-user-badge">Tesorería / Administración</span>
        </div>
    </header>

    <main class="admin-container">

        <!-- 1. TARJETAS MÉTRICAS (RESUMEN SUPERIOR) -->
        <section class="metrics-grid">
            <div class="metric-card" style="border-left: 4px solid #f59e0b;">
                <div class="metric-card-title">Por Enviar (Vendedor)</div>
                <div id="metricPendientes" class="metric-card-value">0</div>
            </div>
            <div class="metric-card" style="border-left: 4px solid #0284c7;">
                <div class="metric-card-title">En Tránsito / Despachados</div>
                <div id="metricTransito" class="metric-card-value">0</div>
            </div>
            <div class="metric-card" style="border-left: 4px solid #2563eb;">
                <div class="metric-card-title">Recibidos en Tesorería</div>
                <div id="metricRecibidos" class="metric-card-value">0</div>
            </div>
            <div class="metric-card" style="border-left: 4px solid #16a34a;">
                <div class="metric-card-title">Depositados</div>
                <div id="metricDepositados" class="metric-card-value">0</div>
            </div>
        </section>

        <!-- 2. BARRA DE HERRAMIENTAS Y FILTROS -->
        <section class="filters-bar">
            <input type="text" id="inputBuscarAdmin" class="search-input" placeholder="Buscar por N° Factura, RUT o Razón Social de Cliente...">
            
            <select id="selectEmpresaAdmin" class="filter-select">
                <option value="">Todas las Empresas</option>
                <option value="1">Automarco LTDA</option>
                <option value="2">HD Automarco S.A</option>
                <option value="3">Autotec S.A</option>
                <option value="4">Gabtec S.A</option>
            </select>

            <select id="selectEstadoAdmin" class="filter-select">
                <option value="ENVIADOS" selected>Solo Enviados / Despachados (Defecto)</option>
                <option value="EN_TRANSITO">En Tránsito (Chilexpress)</option>
                <option value="ENTREGADO_SANTIAGO">Entregado Presencial Santiago</option>
                <option value="RECIBIDO_TESORERIA">Recibido en Tesorería</option>
                <option value="DEPOSITADO">Depositado</option>
                <option value="RECHAZADO">Rechazado / Protestado</option>
                <option value="PENDIENTE_ENVIO">Pendiente Envío (Por Vendedor)</option>
                <option value="TODOS">Todos (Incluye Pendientes de Envío)</option>
            </select>
        </section>

        <!-- 3. TABLA DE COBRANZAS -->
        <section class="table-wrapper">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Empresa</th>
                        <th>Factura</th>
                        <th>Cliente / RUT</th>
                        <th>Vendedor</th>
                        <th>Monto Factura</th>
                        <th>Total Cheques</th>
                        <th>Estado</th>
                        <th>Fecha Reg.</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="adminTableBody">
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 24px; color: var(--color-text-muted);">
                            Cargando registros...
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

    </main>

    <script src="admin.js?v=1"></script>
</body>
</html>
