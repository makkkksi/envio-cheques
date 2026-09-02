<?php
/**
 * Módulo 3: Rendiciones de Gastos y Viáticos — Tesorería.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

$adminUser = requireAdminPage('rendiciones.view');
$rolUsuario = $adminUser['rol'];
$csrfToken = getCsrfToken();
$canManageRenditions = userHasPermission($rolUsuario, 'rendiciones.manage');
$canConfigureApprovers = userHasPermission($rolUsuario, 'users.manage');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Rendiciones de Gastos — Gestión Financiera Suite</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=4">
    <link rel="stylesheet" href="css/shell.css?v=20260828-session-1">
    <link rel="stylesheet" href="css/modal_config_cc.css?v=20260826-4">
    <link rel="stylesheet" href="css/rendiciones.css?v=<?= filemtime(__DIR__ . '/css/rendiciones.css') ?>">
</head>
<body data-can-manage-rendiciones="<?= $canManageRenditions ? '1' : '0' ?>" data-can-configure-approvers="<?= $canConfigureApprovers ? '1' : '0' ?>">
    <!--
    THESIS: Rendiciones se opera con la misma memoria muscular de Cheques, sumando navegación secundaria sin dispersar la revisión.
    OWN-WORLD: superficies blancas, azul Automarco, tabla financiera densa, controles compactos y estados semánticos.
    STORY: el operador elige submódulo, filtra, selecciona una fila y resuelve evidencia y trazabilidad sin abandonar el contexto.
    FIRST VIEWPORT: App Switcher arriba; sidebar de 184 px; píldoras y filtros; tabla maestra a la izquierda e inspector sticky a la derecha.
    FORM: extensión del patrón maestro–detalle de Cheques, dirección fijada por el brief del usuario; seed brief-pinned-cheques-shell.
    FINISH: unreviewed and undocumented is unfinished; this build ends with the finish review, the verdict, DESIGN.md, and every shipping raster carrying its provenance
    -->
    <div class="app-viewport">
        <?php
        $CURRENT_MODULE = 'rendiciones';
        require_once __DIR__ . '/includes/app_header.php';
        ?>

        <div class="rd-shell">
            <aside class="rd-sidebar" aria-label="Submódulos de Rendiciones">
                <div class="rd-sidebar__heading"><div class="rd-sidebar__heading-copy"><span>Rendiciones</span><strong>Submódulos</strong></div><button class="rd-sidebar-toggle" id="toggleRendicionesSidebar" type="button" aria-label="Contraer submódulos" aria-expanded="true" title="Contraer submódulos"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6"/><path d="M5 5h14v14H5z"/></svg></button></div>
                <nav class="rd-sidebar__nav" aria-label="Navegación de Rendiciones">
                    <button class="rd-sidebar__item is-active" type="button" data-submodule-target="bandeja" aria-current="page" title="Bandeja">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 4h16l2 9v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6l2-9Z"/><path d="M2 13h5l2 3h6l2-3h5"/></svg>
                        <span><strong>Bandeja</strong><small>Revisión y aprobación</small></span>
                    </button>
                    <button class="rd-sidebar__item" type="button" data-submodule-target="dashboard" title="Dashboard">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 19V9m6 10V5m6 14v-7m4 7H2"/></svg>
                        <span><strong>Dashboard</strong><small>Control presupuestario</small></span>
                    </button>
                    <?php if ($canManageRenditions): ?>
                    <button class="rd-sidebar__item" type="button" data-submodule-target="vendedores" title="Vendedores">
                        <svg aria-hidden="true" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M19 8v6m3-3h-6"/></svg>
                        <span><strong>Vendedores</strong><small>Presupuestos y giras</small></span>
                    </button>
                    <?php endif; ?>
                </nav>
                <div class="rd-sidebar__foot"><span class="rd-sidebar__signal" aria-hidden="true"></span><span>Operación sincronizada</span></div>
            </aside>

            <main class="rd-main">
                <section class="rd-submodule is-active" id="tab-bandeja" data-submodule-panel="bandeja" aria-labelledby="titleBandeja">
                    <h1 class="rd-visually-hidden" id="titleBandeja">Bandeja de rendiciones</h1>
                    <div class="rd-control-toolbar">
                        <div class="rd-segmented-tabs" id="renditionStateTabs" aria-label="Filtrar por estado">
                            <button class="rd-segmented-tab is-active" type="button" data-state-filter="REVIEW" aria-pressed="true" title="Incluye exceso pendiente y documentos físicos recibidos"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M4 4h16l2 9v6a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2v-6l2-9Z"/><path d="M2 13h5l2 3h6l2-3h5"/></svg>Bandeja por revisar <span class="rd-tab-count" id="countReview">0</span></button>
                            <button class="rd-segmented-tab" type="button" data-state-filter="APPROVED" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M22 11.1V12a10 10 0 1 1-5.9-9.1"/><path d="m22 4-10 10-3-3"/></svg>Aprobadas <span class="rd-tab-count" id="countApproved">0</span></button>
                            <button class="rd-segmented-tab" type="button" data-state-filter="REJECTED" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6m0-6 6 6"/></svg>Rechazadas <span class="rd-tab-count" id="countRejected">0</span></button>
                            <button class="rd-segmented-tab" type="button" data-state-filter="ALL" aria-pressed="false"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m12 2 10 5-10 5L2 7l10-5Z"/><path d="m2 12 10 5 10-5M2 17l10 5 10-5"/></svg>Todas <span class="rd-tab-count" id="countAll">0</span></button>
                        </div>
                        <div class="rd-filter-row">
                            <label class="rd-search-field"><span class="rd-visually-hidden">Buscar rendiciones</span><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.4-4.4"/></svg><input type="search" id="filterSearch" placeholder="Buscar código, vendedor o RUT…" autocomplete="off"></label>
                            <label><span class="rd-visually-hidden">Empresa</span><select id="filterCompany" class="rd-select-compact"><option value="">Todas las empresas</option><option value="1">Automarco LTDA</option><option value="2">HD Automarco S.A</option><option value="3">Autotec S.A</option><option value="4">Gabtec S.A</option></select></label>
                            <label><span class="rd-visually-hidden">Tipo</span><select id="filterType" class="rd-select-compact"><option value="TODOS">Todos los tipos</option><option value="MENSUAL">Mensual</option><option value="GIRA">Gira</option></select></label>
                            <label class="rd-month-field"><span class="rd-visually-hidden">Período</span><input type="month" id="filterMonth" class="rd-select-compact"></label>
                            <button class="rd-icon-btn" id="refreshRenditions" type="button" title="Actualizar bandeja" aria-label="Actualizar bandeja"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M20 11a8 8 0 1 0 2 5h-2a6 6 0 1 1-1.8-4.2L15 15h7V8l-2 3Z"/></svg></button>
                        </div>
                    </div>
                    <div class="rd-split-view">
                        <section class="rd-master-panel" aria-label="Tabla de rendiciones">
                            <div class="rd-table-summary"><span id="inboxSummary">Cargando información…</span><span id="inboxAmount">$0 visible</span></div>
                            <div class="rd-table-scroll"><table class="rd-master-table"><thead><tr><th>Empresa</th><th>Vendedor</th><th>Tipo / período</th><th>Total rendido</th><th>Estado</th><th>Fecha</th></tr></thead><tbody id="renditionsTableBody"><tr><td colspan="6" class="rd-table-message">Cargando rendiciones…</td></tr></tbody></table></div>
                        </section>
                        <aside class="rd-detail-panel" id="renditionDetail" aria-label="Detalle de rendición">
                            <div class="rd-detail-empty" id="detailEmpty"><span class="rd-detail-empty__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6V2Z"/><path d="M14 2v6h6M9 13h8m-8 4h5"/></svg></span><h2>Selecciona una rendición para auditar</h2><p>Inspecciona comprobantes, datos SII, exceso presupuestario y trazabilidad sin abandonar la bandeja.</p></div>
                            <div class="rd-detail-content" id="detailContent" hidden></div>
                        </aside>
                    </div>
                </section>

                <section class="rd-submodule" id="tab-dashboard" data-submodule-panel="dashboard" aria-labelledby="dashboardTitle" hidden>
                    <header class="rd-section-header"><div><h1 id="dashboardTitle">Dashboard presupuestario</h1><p>Lectura ejecutiva del holding para el período seleccionado.</p></div><label class="rd-period-control"><span>Período</span><input type="month" id="dashboardMonth"></label></header>
                    <div class="rd-dashboard-scroll">
                        <section class="rd-dashboard-kpis" aria-label="Indicadores presupuestarios">
                            <article class="rd-dashboard-kpi"><span>Presupuesto global holding</span><strong id="dashboardBudget">$0</strong><small id="dashboardBudgetNote">0 presupuestos activos</small></article>
                            <article class="rd-dashboard-kpi"><span>Monto aprobado total</span><strong id="dashboardRendered">$0</strong><small id="dashboardRenderedNote">0 rendiciones aprobadas</small></article>
                            <article class="rd-dashboard-kpi"><span>Ejecución presupuestaria</span><strong id="dashboardExecution">0%</strong><small>Aprobado sobre presupuesto asignado</small></article>
                            <article class="rd-dashboard-kpi rd-dashboard-kpi--warning"><span>Tasa de excesos</span><strong id="dashboardExcessRate">0%</strong><small id="dashboardExcessNote">0 casos con exceso</small></article>
                        </section>
                        <p class="rd-dashboard-status" id="dashboardStatus" role="status"></p>
                        <div class="rd-dashboard-grid"><section class="rd-analytics-panel"><header><h2>Gasto aprobado por categoría</h2><p>Participación de comprobantes aprobados.</p></header><div class="rd-bar-list" id="dashboardCategories"></div></section><section class="rd-analytics-panel"><header><h2>Consumo aprobado por empresa</h2><p>Comparativa de montos aprobados entre razones sociales.</p></header><div class="rd-bar-list" id="dashboardCompanies"></div></section></div>

                        <section class="rd-seller-analytics" aria-labelledby="sellerAnalyticsTitle">
                            <header class="rd-seller-analytics__header">
                                <div><h2 id="sellerAnalyticsTitle">Uso de presupuesto por vendedor</h2><p>Compara ejecución aprobada, fondos pendientes y fricción operativa a través del tiempo.</p></div>
                                <label class="rd-window-control"><span>Horizonte</span><select id="dashboardWindow"><option value="6">Últimos 6 meses</option><option value="12">Últimos 12 meses</option></select></label>
                            </header>
                            <div class="rd-decision-strip" id="dashboardDecisionStrip" aria-label="Señales para la toma de decisiones">
                                <div class="rd-decision-metric"><span>Saldo no ejecutado</span><strong>—</strong><small>Dentro del período analizado</small></div>
                                <div class="rd-decision-metric rd-decision-metric--pending"><span>Pendiente de decisión</span><strong>—</strong><small>Aún no forma parte del gasto aprobado</small></div>
                                <div class="rd-decision-metric"><span>Concentración principal</span><strong>—</strong><small>Participación del vendedor con mayor gasto</small></div>
                            </div>
                            <section class="rd-fund-comparison" aria-labelledby="fundComparisonTitle">
                                <header><div><h3 id="fundComparisonTitle">Composición de fondos</h3><p>Comparación estandarizada entre operación mensual y giras comerciales; no utiliza nombres ingresados por usuarios.</p></div></header>
                                <div class="rd-fund-comparison__rows" id="dashboardFundTypes"><div class="rd-bar-empty">Consolidando tipos de fondo…</div></div>
                            </section>
                            <section class="rd-approval-analytics" aria-labelledby="approvalAnalyticsTitle">
                                <header><div><h3 id="approvalAnalyticsTitle">Aprobaciones de Gerencia</h3><p>Salud operativa de giras y excepciones mensuales en el horizonte seleccionado.</p></div><span id="dashboardApprovalTotal">0 solicitudes</span></header>
                                <div class="rd-approval-analytics__metrics" id="dashboardApprovalMetrics">
                                    <div><span>Esperando decisión</span><strong>0</strong><small>Solicitudes activas</small></div>
                                    <div class="is-warning"><span>Correos fallidos</span><strong>0</strong><small>Requieren reenvío</small></div>
                                    <div><span>Respuesta promedio</span><strong>—</strong><small>Desde envío hasta decisión</small></div>
                                    <div><span>Tasa de aprobación</span><strong>—</strong><small>Sobre decisiones resueltas</small></div>
                                </div>
                                <div class="rd-approval-analytics__types" id="dashboardApprovalTypes"><div class="rd-bar-empty">Consolidando aprobaciones…</div></div>
                            </section>
                            <div class="rd-seller-analytics__body">
                                <section class="rd-seller-ranking" aria-labelledby="sellerRankingTitle">
                                    <header><div><h3 id="sellerRankingTitle">Comparativa de vendedores</h3><p id="sellerAnalyticsStatus" role="status">Preparando análisis histórico…</p></div><label class="rd-seller-filter"><span class="rd-visually-hidden">Buscar vendedor</span><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg><input id="dashboardSellerSearch" type="search" placeholder="Buscar vendedor o empresa…" autocomplete="off"></label></header>
                                    <div class="rd-seller-table-wrap">
                                        <table class="rd-seller-table">
                                            <thead><tr><th>Vendedor</th><th>Presupuesto</th><th>Aprobado</th><th>Ejecución</th><th>Pendiente</th><th>Fricción</th></tr></thead>
                                            <tbody id="dashboardSellerRows"><tr><td colspan="6" class="rd-table-message">Cargando vendedores…</td></tr></tbody>
                                        </table>
                                    </div>
                                </section>
                                <aside class="rd-seller-detail" id="dashboardSellerDetail" aria-live="polite">
                                    <div class="rd-seller-detail__empty"><h3>Selecciona un vendedor</h3><p>Verás su trayectoria mensual, presupuesto asignado, gasto aprobado y montos todavía pendientes.</p></div>
                                </aside>
                            </div>
                            <section class="rd-business-signals" aria-labelledby="businessSignalsTitle">
                                <header><h3 id="businessSignalsTitle">Señales para actuar</h3><p>Reglas transparentes que destacan concentración, baja ejecución, excesos recurrentes y rechazos.</p></header>
                                <div class="rd-business-signals__list" id="dashboardBusinessSignals"><div class="rd-bar-empty">Analizando patrones del período…</div></div>
                            </section>
                        </section>
                    </div>
                </section>

                <?php if ($canManageRenditions): ?>
                <section class="rd-submodule" id="tab-vendedores" data-submodule-panel="vendedores" aria-labelledby="vendorsTitle" hidden>
                    <header class="rd-section-header rd-section-header--vendors"><div><h1 id="vendorsTitle">Vendedores y presupuestos</h1><p>Identidad ERP verificada, cupos mensuales y giras comerciales.</p></div><div class="rd-vendor-actions"><label class="rd-search-field"><span class="rd-visually-hidden">Buscar vendedor</span><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.4-4.4"/></svg><input type="search" id="budgetSearch" placeholder="Buscar vendedor, correo o código ERP…"></label><?php if ($canConfigureApprovers): ?><button class="rd-btn rd-btn--secondary" id="openApproverConfig" type="button"><svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0M19 4v6m3-3h-6"/></svg>Responsables de aprobación</button><?php endif; ?><button class="rd-btn rd-btn--primary" id="openBudgetModal" type="button"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M12 5v14m-7-7h14"/></svg>Asignar presupuesto</button></div></header>
                    <div class="rd-budget-directory">
                        <div class="rd-budget-toolbar">
                            <div class="rd-segmented-tabs" role="tablist" id="budgetTypeTabs" aria-label="Filtrar por tipo de presupuesto">
                                <button class="rd-segmented-tab is-active" type="button" role="tab" aria-selected="true" data-budget-filter="MENSUAL">
                                    Presupuestos mensuales <span class="rd-tab-count" id="countBudgetMonthly">0</span>
                                </button>
                                <button class="rd-segmented-tab" type="button" role="tab" aria-selected="false" data-budget-filter="GIRA">
                                    Giras comerciales <span class="rd-tab-count" id="countBudgetTour">0</span>
                                </button>
                            </div>
                            <div class="rd-table-summary">
                                <span id="budgetSummary">Cargando presupuestos…</span>
                                <span id="budgetAmountSummary">$0 asignado</span>
                            </div>
                        </div>
                        <div class="rd-table-scroll" id="budgetScrollContainer">
                            <div id="budgetTablesContainer">
                                <table class="rd-master-table rd-budget-table">
                                    <thead><tr><th>Vendedor</th><th>Empresa del cupo</th><th>Presencia ERP</th><th>Tipo</th><th>Período / fechas</th><th>Asignado</th><th>Gastado</th><th>Saldo</th><th>Estado</th><th>Acciones</th></tr></thead>
                                    <tbody id="budgetTableBody"><tr><td colspan="10" class="rd-table-message">Cargando presupuestos…</td></tr></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <?php if ($canManageRenditions): ?>
    <div class="rd-modal" id="budgetModal" hidden role="dialog" aria-modal="true" aria-labelledby="budgetModalTitle">
        <div class="rd-modal__card rd-modal__card--form">
            <header class="rd-modal__header"><div><h2 id="budgetModalTitle">Asignar presupuesto</h2><p id="budgetModalDescription">Selecciona una empresa y un vendedor verificado en su ERP.</p></div><button class="rd-modal__close" type="button" data-close-modal="budgetModal" aria-label="Cerrar"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header>
            <form class="rd-budget-form" id="budgetForm" novalidate><input type="hidden" id="budgetId"><h3 id="budgetFormTitle">Nuevo presupuesto</h3>
                <div class="rd-form-grid">
                    <label class="rd-span-2"><span>Empresa del presupuesto</span><select id="budgetCompany" required><option value="">Cargando empresas…</option></select></label>
                    <div class="rd-seller-field rd-span-2" id="budgetSellerField">
                        <label for="budgetSellerSearch">Vendedor del ERP</label>
                        <div class="rd-seller-combobox">
                            <svg aria-hidden="true" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.4-4.4"/></svg>
                            <input type="search" id="budgetSellerSearch" role="combobox" aria-autocomplete="list" aria-controls="budgetSellerOptions" aria-expanded="false" autocomplete="off" placeholder="Primero selecciona una empresa" disabled>
                            <span class="rd-seller-spinner" id="budgetSellerSpinner" hidden aria-hidden="true"></span>
                        </div>
                        <div class="rd-seller-options" id="budgetSellerOptions" role="listbox" hidden></div>
                        <p class="rd-field-help" id="budgetSellerHelp">La identidad se obtiene directamente desde la tabla de vendedores del ERP.</p>
                        <div class="rd-seller-selected" id="budgetSellerSelected" hidden>
                            <span class="rd-seller-selected__avatar" aria-hidden="true"></span>
                            <span><strong id="budgetSellerSelectedName"></strong><small id="budgetSellerSelectedMeta"></small></span>
                            <span class="rd-verified-badge"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg>ERP verificado</span>
                        </div>
                        <input type="hidden" id="budgetSellerId">
                        <input type="hidden" id="budgetSellerName">
                        <input type="hidden" id="budgetSellerEmail">
                    </div>
                    <label><span>Tipo</span><select id="budgetType" required><option value="MENSUAL">Mensual</option><option value="GIRA">Gira comercial</option></select></label>
                    <label id="budgetPeriodField"><span>Período</span><input type="month" id="budgetPeriod" required></label>
                    <label class="rd-span-2"><span>Monto asignado</span><input type="number" id="budgetAmount" min="1" step="1" required></label>
                </div>
                <fieldset id="tourFields" hidden><legend>Datos y aprobación de la gira</legend><div class="rd-form-grid"><label class="rd-span-2"><span>Nombre de gira</span><input type="text" id="budgetTourName" minlength="3" maxlength="100"><small class="rd-field-help">Uso operativo; el Dashboard consolida las giras por tipo y no utiliza este nombre.</small></label><label><span>Inicio</span><input type="date" id="budgetStartDate"></label><label><span>Término</span><input type="date" id="budgetEndDate"></label><label class="rd-span-2"><span>Justificación comercial</span><textarea id="budgetTourJustification" rows="3" maxlength="500" placeholder="Objetivo, zona y antecedentes necesarios para autorizar el fondo."></textarea></label><label class="rd-span-2"><span>Responsable de aprobación</span><select id="budgetTourApprover"><option value="">Selecciona un responsable</option></select><small class="rd-field-help">La solicitud se enviará sólo a la persona seleccionada.</small></label></div></fieldset>
                <div class="rd-modal__actions"><button class="rd-btn rd-btn--secondary" id="clearBudgetForm" type="button">Limpiar</button><button class="rd-btn rd-btn--primary" id="saveBudgetButton" type="submit">Guardar presupuesto</button></div>
            </form>
        </div>
    </div>
    <div class="rd-modal" id="actionModal" hidden role="dialog" aria-modal="true" aria-labelledby="actionModalTitle"><div class="rd-modal__card"><header class="rd-modal__header"><div><h2 id="actionModalTitle">Actualizar rendición</h2></div><button class="rd-modal__close" type="button" data-close-modal="actionModal" aria-label="Cerrar"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><p id="actionModalDescription" class="rd-modal__description"></p><label class="rd-modal__comment"><span id="actionCommentLabel">Comentario</span><textarea id="actionComment" rows="4" maxlength="1000"></textarea></label><div class="rd-modal__actions"><button class="rd-btn rd-btn--secondary" type="button" data-close-modal="actionModal">Cancelar</button><button class="rd-btn rd-btn--primary" id="confirmActionButton" type="button">Confirmar</button></div></div></div>
    <div class="rd-modal" id="excessApprovalModal" hidden role="dialog" aria-modal="true" aria-labelledby="excessApprovalTitle"><div class="rd-modal__card rd-modal__card--approval"><header class="rd-modal__header"><div><h2 id="excessApprovalTitle">Enviar aprobación de exceso</h2><p>Elige quién revisará la solicitud. El enlace anterior, si existe, quedará invalidado.</p></div><button class="rd-modal__close" type="button" data-close-modal="excessApprovalModal" aria-label="Cerrar"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><fieldset class="rd-approver-choice"><legend>Responsable de la decisión</legend><div id="approverChoices" class="rd-approver-choice__list"><p class="rd-modal__description">Cargando responsables…</p></div></fieldset><label class="rd-modal__comment"><span>Comentario para Gerencia (opcional)</span><textarea id="excessApprovalComment" rows="4" maxlength="1000" placeholder="Contexto que acompañará el resumen financiero y los comprobantes."></textarea></label><p id="approverChoiceStatus" class="rd-form-status" role="status"></p><div class="rd-modal__actions"><button class="rd-btn rd-btn--secondary" type="button" data-close-modal="excessApprovalModal">Cancelar</button><button class="rd-btn rd-btn--primary" id="sendExcessApproval" type="button">Enviar solicitud</button></div></div></div>
    <?php if ($canConfigureApprovers): ?>
    <div class="rd-modal" id="approverConfigModal" hidden role="dialog" aria-modal="true" aria-labelledby="approverConfigTitle"><div class="rd-modal__card rd-modal__card--wide"><header class="rd-modal__header"><div><h2 id="approverConfigTitle">Responsables de aprobación</h2><p>Configura las dos personas que pueden resolver excesos. Los cambios futuros no alteran la auditoría histórica.</p></div><button class="rd-modal__close" type="button" data-close-modal="approverConfigModal" aria-label="Cerrar"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><form id="approverConfigForm" novalidate><div class="rd-approver-config"><fieldset><legend>Responsable 1</legend><label><span>Nombre completo</span><input id="approver1Name" type="text" maxlength="150" required></label><label><span>Cargo</span><input id="approver1Title" type="text" maxlength="120" required></label><label><span>Correo</span><input id="approver1Email" type="email" maxlength="190" required></label></fieldset><fieldset><legend>Responsable 2</legend><label><span>Nombre completo</span><input id="approver2Name" type="text" maxlength="150" required></label><label><span>Cargo</span><input id="approver2Title" type="text" maxlength="120" required></label><label><span>Correo</span><input id="approver2Email" type="email" maxlength="190" required></label></fieldset></div><p id="approverConfigStatus" class="rd-form-status" role="status"></p><div class="rd-modal__actions"><button class="rd-btn rd-btn--secondary" type="button" data-close-modal="approverConfigModal">Cancelar</button><button class="rd-btn rd-btn--primary" id="saveApproverConfig" type="submit">Guardar responsables</button></div></form></div></div>
    <?php endif; ?>
    <div class="rd-modal" id="partialModal" hidden role="dialog" aria-modal="true" aria-labelledby="partialModalTitle"><div class="rd-modal__card rd-modal__card--wide"><header class="rd-modal__header"><div><h2 id="partialModalTitle">Aprobación parcial</h2></div><button class="rd-modal__close" type="button" data-close-modal="partialModal" aria-label="Cerrar"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button></header><p class="rd-modal__description">Resuelve todos los comprobantes. Cada rechazo debe incluir un motivo.</p><div class="rd-partial-list" id="partialDecisionList"></div><div class="rd-modal__actions"><strong id="partialApprovedTotal">Aprobado: $0</strong><button class="rd-btn rd-btn--secondary" type="button" data-close-modal="partialModal">Cancelar</button><button class="rd-btn rd-btn--primary" id="savePartialButton" type="button">Guardar revisión parcial</button></div></div></div>
    <div class="rd-modal" id="editDocumentModal" hidden role="dialog" aria-modal="true" aria-labelledby="editDocTitle">
        <div class="rd-modal__card rd-modal__card--edit-doc">
            <header class="rd-modal__header">
                <div>
                    <h2 id="editDocTitle">Corregir comprobante</h2>
                    <p class="rd-modal__description">Corrige los datos si el vendedor digitó algo diferente a la foto.</p>
                </div>
                <button class="rd-modal__close" type="button" data-close-modal="editDocumentModal" aria-label="Cerrar"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
            </header>
            <form id="editDocumentForm" novalidate>
                <input type="hidden" id="editDocId">
                <!-- Contexto del comprobante -->
                <div class="ed-context-card">
                    <svg class="ed-context-card__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                    <div>
                        <span class="ed-context-card__label">Comprobante</span>
                        <strong class="ed-context-card__value" id="editDocProvider">—</strong>
                    </div>
                </div>
                <!-- Campos de corrección -->
                <div class="ed-fields">
                    <div class="ed-field-group">
                        <label class="ed-label" for="editDocNewNumber">
                            <span class="ed-label__icon">🔢</span>
                            N° de Boleta / Folio
                        </label>
                        <div class="ed-field-row">
                            <input class="ed-input ed-input--readonly" type="text" id="editDocOldNumber" readonly aria-label="Folio actual" placeholder="—">
                            <span class="ed-arrow" aria-hidden="true">→</span>
                            <input class="ed-input ed-input--editable" type="text" id="editDocNewNumber" maxlength="50" placeholder="Folio correcto" autocomplete="off">
                        </div>
                        <p class="ed-field-hint">Deja igual al actual si el folio está bien.</p>
                    </div>
                    <div class="ed-field-group">
                        <label class="ed-label" for="editDocNewAmount">
                            <span class="ed-label__icon">💰</span>
                            Monto ($)
                        </label>
                        <div class="ed-field-row">
                            <input class="ed-input ed-input--readonly" type="text" id="editDocOldAmount" readonly aria-label="Monto actual">
                            <span class="ed-arrow" aria-hidden="true">→</span>
                            <input class="ed-input ed-input--editable" type="number" id="editDocNewAmount" min="1" step="1" required placeholder="Monto real">
                        </div>
                    </div>
                    <div class="ed-field-group ed-field-group--full">
                        <label class="ed-label" for="editDocReason">
                            <span class="ed-label__icon">📝</span>
                            Motivo de la corrección
                        </label>
                        <input class="ed-input ed-input--editable" type="text" id="editDocReason" maxlength="255" value="Corrección por error de digitación verificada en foto">
                    </div>
                </div>
                <p id="editDocStatus" class="rd-form-status" role="status"></p>
                <div class="rd-modal__actions">
                    <button class="rd-btn rd-btn--secondary" type="button" data-close-modal="editDocumentModal">Cancelar</button>
                    <button class="rd-btn rd-btn--primary" id="saveEditDocBtn" type="submit">
                        <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17,21 17,13 7,13 7,21"/><polyline points="7,3 7,8 15,8"/></svg>
                        Guardar corrección
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php endif; ?>

    <script src="js/shared_ui.js?v=20260828-session-1"></script>
    <script src="js/modal_config_cc.js?v=20260826-3"></script>
    <script src="js/rendiciones.js?v=<?= filemtime(__DIR__ . '/js/rendiciones.js') ?>" defer></script>
    <?php if (userHasPermission($rolUsuario, 'cc.manage') || userHasPermission($rolUsuario, 'companies.manage')): ?>
    <?php include __DIR__ . '/components/modal_config_cc.php'; ?>
    <?php endif; ?>
</body>
</html>
