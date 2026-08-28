<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

startSellerSession();

$csrfToken = (string)($_SESSION['csrf_token'] ?? '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="theme-color" content="#0b3a5b">
    <title>Rendiciones de Gastos | Grupo Automarco</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="vendedor.css?v=20260825-budget-1">
</head>
<body>
    <div class="mobile-canvas" id="sellerApp" aria-busy="true">
        
        <!-- HEADER MÓVIL SUPERIOR -->
        <header class="rg-header">
            <button class="rg-header__icon-btn" id="btnBackPortal" type="button" aria-label="Volver a Vendedores" title="Volver al portal comercial">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="19" y1="12" x2="5" y2="12"></line>
                    <polyline points="12 19 5 12 12 5"></polyline>
                </svg>
            </button>
            <div class="rg-header__title-box">
                <h1 class="rg-header__title" id="pageTitle">Gastos</h1>
            </div>
            <div class="rg-header__right">
                <button class="rg-header__icon-btn" id="refreshButton" type="button" aria-label="Actualizar información" title="Actualizar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 11a8 8 0 1 0 2 5h-2a6 6 0 1 1-1.76-4.24L15 15h7V8l-2 3Z"/>
                    </svg>
                </button>
            </div>
        </header>

        <!-- PANTALLA PRINCIPAL / CONTENIDO -->
        <main class="rg-main-content">
            
            <!-- ESTADO DE SESIÓN NO INICIADA -->
            <section class="rg-session-state" id="sessionState" hidden>
                <div class="rg-session-state__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <h2>No pudimos iniciar tu sesión</h2>
                <p id="sessionMessage">Vuelve al portal de vendedores e ingresa nuevamente.</p>
            </section>

            <!-- WORKSPACE CONECTADO -->
            <div id="workspace" class="rg-workspace" hidden>
                
                <!-- VISTA 1: PESTAÑA GASTOS (BOLSA DE GASTOS) -->
                <section class="rg-view" id="viewGastos">
                    <!-- Segmented Control Pills -->
                    <div class="rg-segmented-control" role="tablist" aria-label="Filtro de gastos">
                        <button class="rg-segment-btn is-active" type="button" data-filter="TODOS">Todos</button>
                        <button class="rg-segment-btn" type="button" data-filter="BORRADORES">Borradores</button>
                        <button class="rg-segment-btn" type="button" data-filter="ENVIADOS">Enviados</button>
                    </div>

                    <!-- Encabezado de Sección con Contador -->
                    <div class="rg-section-header">
                        <span class="rg-section-title">Bolsa de Gastos</span>
                        <span class="rg-badge-count" id="badgeDraftCount">0</span>
                    </div>

                    <!-- Lista de Tarjetas de Gastos -->
                    <div class="rg-expense-list" id="expenseList">
                        <div class="rg-skeleton-card"></div>
                        <div class="rg-skeleton-card"></div>
                    </div>

                    <!-- Empty State para Gastos -->
                    <div class="rg-empty-state" id="emptyGastos" hidden>
                        <div class="rg-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="16" y1="13" x2="8" y2="13"></line>
                                <line x1="16" y1="17" x2="8" y2="17"></line>
                            </svg>
                        </div>
                        <h3>Sin gastos en borrador</h3>
                        <p>Presiona el botón <strong>+</strong> para fotografiar y agregar tu primera boleta o peaje.</p>
                    </div>

                    <!-- Floating Action Button (FAB) -->
                    <button class="rg-fab" id="fabNuevoGasto" type="button" aria-label="Agregar nuevo gasto" title="Agregar gasto">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </button>
                </section>

                <!-- VISTA 2: PESTAÑA INFORMES DE RENDICIÓN -->
                <section class="rg-view" id="viewInformes" hidden>
                    <!-- Segmented Control Pills -->
                    <div class="rg-segmented-control" role="tablist" aria-label="Filtro de informes">
                        <button class="rg-segment-btn is-active" type="button" data-report-filter="TODOS">Todos</button>
                        <button class="rg-segment-btn" type="button" data-report-filter="EN_REVISION">En Revisión</button>
                        <button class="rg-segment-btn" type="button" data-report-filter="APROBADA">Aprobadas</button>
                        <button class="rg-segment-btn" type="button" data-report-filter="RECHAZADA">Rechazadas</button>
                    </div>

                    <!-- Encabezado de Sección -->
                    <div class="rg-section-header">
                        <span class="rg-section-title">Mis Rendiciones</span>
                        <span class="rg-badge-count" id="badgeReportsCount">0</span>
                    </div>

                    <!-- Lista de Informes Consolidados -->
                    <div class="rg-report-list" id="reportList">
                        <div class="rg-skeleton-card"></div>
                    </div>

                    <!-- Empty State para Informes -->
                    <div class="rg-empty-state" id="emptyInformes" hidden>
                        <div class="rg-empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                                <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                            </svg>
                        </div>
                        <h3>Sin informes de gastos</h3>
                        <p>No tienes rendiciones enviadas en este filtro. Pulsa <strong>+</strong> para consolidar y enviar un informe.</p>
                    </div>

                    <!-- Floating Action Button (FAB) para crear informe -->
                    <button class="rg-fab" id="fabNuevoInforme" type="button" aria-label="Crear nuevo informe" title="Crear informe">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="12" y1="5" x2="12" y2="19"></line>
                            <line x1="5" y1="12" x2="19" y2="12"></line>
                        </svg>
                    </button>
                </section>

                <!-- VISTA 3: PESTAÑA PRESUPUESTOS Y FONDOS -->
                <section class="rg-view" id="viewPresupuestos" hidden>
                    <div class="rg-section-header">
                        <span class="rg-section-title">Fondos Asignados</span>
                    </div>

                    <!-- Tarjetas de Resumen de Presupuesto -->
                    <div class="rg-budget-grid">
                        <div class="rg-budget-card rg-budget-card--monthly">
                            <div class="rg-budget-card__header">
                                <span class="rg-budget-label">Presupuesto Mensual</span>
                                <span class="rg-budget-period" id="lblBudgetPeriod">--</span>
                            </div>
                            <div class="rg-budget-summary">
                                <div class="rg-budget-total">
                                    <span class="rg-budget-amount-label">Monto asignado</span>
                                    <div class="rg-budget-amount" id="lblMonthlyBudgetAmount">$0</div>
                                    <span class="rg-budget-status" id="lblMonthlyStatus" aria-live="polite">Sin presupuesto asignado</span>
                                </div>
                                <dl class="rg-budget-breakdown">
                                    <div class="rg-budget-breakdown__approved">
                                        <dt>Aprobado</dt>
                                        <dd id="lblMonthlyApproved">$0</dd>
                                        <span id="lblMonthlyApprovedPercent">0% del fondo</span>
                                    </div>
                                    <div class="rg-budget-breakdown__pending">
                                        <dt>Pendiente Tesorería</dt>
                                        <dd id="lblMonthlyPending">$0</dd>
                                        <span id="lblMonthlyPendingPercent">0% del fondo</span>
                                    </div>
                                    <div>
                                        <dt id="lblMonthlyBalanceLabel">Saldo disponible</dt>
                                        <dd id="lblMonthlyBalance">$0</dd>
                                        <span>Para nuevas boletas</span>
                                    </div>
                                </dl>
                            </div>
                            <div class="rg-budget-progress" id="monthlyBudgetProgress">
                                <div class="rg-budget-progress-header">
                                    <span>Estado del fondo</span>
                                    <strong id="lblMonthlyProgressPercent">0% comprometido</strong>
                                </div>
                                <div class="rg-budget-progress-wrap" id="barMonthlyProgress" role="progressbar" aria-label="Estado del presupuesto mensual" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-valuetext="0% aprobado y 0% pendiente">
                                    <div class="rg-budget-progress-bar rg-budget-progress-bar--approved" style="width: 0%"></div>
                                    <div class="rg-budget-progress-bar rg-budget-progress-bar--pending" style="width: 0%"></div>
                                </div>
                                <div class="rg-budget-legend" aria-hidden="true">
                                    <span><i class="is-approved"></i>Aprobado</span>
                                    <span><i class="is-pending"></i>Pendiente</span>
                                    <span><i class="is-available"></i>Disponible</span>
                                </div>
                            </div>
                        </div>

                        <!-- Lista de Giras Comerciales Activas -->
                        <div id="toursContainer" class="rg-tours-list">
                            <!-- Se renderizan dinámicamente -->
                        </div>
                    </div>
                </section>

            </div>
        </main>

        <!-- DRAWER FULLSCREEN: NUEVO GASTO -->
        <div class="rg-drawer" id="drawerNuevoGasto" hidden role="dialog" aria-modal="true" aria-labelledby="drawerGastoTitle">
            <div class="rg-drawer__content">
                
                <header class="rg-drawer__header">
                    <button class="rg-drawer__btn-nav" id="btnCancelGasto" type="button">Cancelar</button>
                    <h2 class="rg-drawer__title" id="drawerGastoTitle">Nuevo Gasto</h2>
                    <button class="rg-drawer__btn-action" id="btnSaveGasto" type="button">Guardar</button>
                </header>

                <form id="formGasto" class="rg-form-body" novalidate>
                    <input type="hidden" id="editDocumentId" name="document_id" value="">

                    <!-- Zona de Carga de Imagen / Cámara -->
                    <div class="rg-photo-upload-box" id="photoUploadBox">
                        <input type="file" id="inputExpensePhoto" name="foto_documento" accept="image/jpeg,image/png,image/webp" capture="environment" hidden>
                        <div class="rg-photo-empty" id="photoEmptyState">
                            <button type="button" class="rg-btn-camera" id="btnTriggerCamera">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"></path>
                                    <circle cx="12" cy="13" r="4"></circle>
                                </svg>
                                <span>Adjuntar Imagen</span>
                            </button>
                            <small>Toma una foto clara del comprobante</small>
                        </div>
                        <div class="rg-photo-preview" id="photoPreviewState" hidden>
                            <img id="imgPhotoPreview" alt="Previsualización de la boleta">
                            <button type="button" class="rg-btn-remove-photo" id="btnRemovePhoto" aria-label="Cambiar foto">✕</button>
                        </div>
                    </div>

                    <!-- Campos del Formulario estilo Lista -->
                    <div class="rg-form-group">
                        <label class="rg-field-row">
                            <span class="rg-field-label">Comercio / Proveedor</span>
                            <input type="text" id="expenseMerchant" name="razon_social_proveedor" class="rg-field-input" placeholder="Ej: Copec, Farmex, Hotel..." required maxlength="150">
                        </label>

                        <label class="rg-field-row" id="rowRutProveedor">
                            <span class="rg-field-label">RUT Proveedor</span>
                            <input type="text" id="expenseRut" name="rut_proveedor" class="rg-field-input" placeholder="Ej: 76.123.456-7" maxlength="20">
                        </label>

                        <label class="rg-field-row">
                            <span class="rg-field-label">Fecha</span>
                            <input type="date" id="expenseDate" name="fecha_emision" class="rg-field-input" required>
                        </label>

                        <label class="rg-field-row">
                            <span class="rg-field-label">Moneda</span>
                            <span class="rg-field-static">CLP</span>
                        </label>

                        <label class="rg-field-row">
                            <span class="rg-field-label">Total ($)</span>
                            <input type="number" id="expenseAmount" name="monto" class="rg-field-input rg-field-amount" placeholder="0" min="1" step="1" required>
                        </label>

                        <div class="rg-field-row">
                            <span class="rg-field-label" id="expenseCategoryLabel">Categoría</span>
                            <div class="rg-select-control" data-select-control>
                                <select id="expenseCategory" name="categoria_gasto" class="rg-field-input rg-native-select" required tabindex="-1" aria-hidden="true">
                                    <option value="BENCINA">Combustible / Bencina</option>
                                    <option value="PEAJES">Peajes</option>
                                    <option value="COLACION">Colación (Comidas)</option>
                                    <option value="HOSPEDAJE">Hospedaje</option>
                                    <option value="ESTACIONAMIENTO">Estacionamiento</option>
                                    <option value="CENA_CLIENTE">Cena Cliente (Restaurante SII)</option>
                                    <option value="OTROS">Otros</option>
                                </select>
                                <button type="button" class="rg-select-trigger" id="expenseCategoryTrigger" aria-labelledby="expenseCategoryLabel expenseCategoryValue" aria-haspopup="listbox" aria-expanded="false" aria-controls="expenseCategoryOptions">
                                    <span class="rg-select-trigger__value" id="expenseCategoryValue"></span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
                                </button>
                                <div class="rg-select-menu" id="expenseCategoryOptions" role="listbox" aria-labelledby="expenseCategoryLabel" hidden></div>
                            </div>
                        </div>

                        <div class="rg-field-row" id="rowTipoDoc">
                            <span class="rg-field-label" id="expenseDocTypeLabel">Tipo Documento</span>
                            <div class="rg-select-control" data-select-control>
                                <select id="expenseDocType" name="tipo_documento" class="rg-field-input rg-native-select" required tabindex="-1" aria-hidden="true">
                                    <option value="BOLETA_ELECTRONICA">Boleta Electrónica</option>
                                    <option value="FACTURA_ELECTRONICA">Factura Electrónica</option>
                                    <option value="PEAJE">Peaje</option>
                                    <option value="PASAJES">Pasajes</option>
                                    <option value="OTRO">Otro Comprobante</option>
                                </select>
                                <button type="button" class="rg-select-trigger" id="expenseDocTypeTrigger" aria-labelledby="expenseDocTypeLabel expenseDocTypeValue" aria-haspopup="listbox" aria-expanded="false" aria-controls="expenseDocTypeOptions">
                                    <span class="rg-select-trigger__value" id="expenseDocTypeValue"></span>
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"></path></svg>
                                </button>
                                <div class="rg-select-menu" id="expenseDocTypeOptions" role="listbox" aria-labelledby="expenseDocTypeLabel" hidden></div>
                            </div>
                        </div>

                        <label class="rg-field-row" id="rowFolio">
                            <span class="rg-field-label">N° Documento / Folio</span>
                            <input type="text" id="expenseDocNumber" name="numero_documento" class="rg-field-input" placeholder="Ej: 104859" maxlength="50">
                        </label>
                    </div>

                    <!-- Campos Condicionales para CENA CLIENTE (Exigencia SII: 5 Campos) -->
                    <div class="rg-dinner-box" id="boxDinnerFields" hidden>
                        <div class="rg-dinner-box__header">
                            <span>Respaldo Tributario SII (Cena con Cliente)</span>
                        </div>
                        <div class="rg-form-group">
                            <label class="rg-field-row">
                                <span class="rg-field-label">Nombre Invitado</span>
                                <input type="text" id="dinnerGuestName" name="cliente_invitado_nombre" class="rg-field-input" placeholder="Nombre completo del cliente" maxlength="150">
                            </label>
                            <label class="rg-field-row">
                                <span class="rg-field-label">RUT Invitado / Empresa</span>
                                <input type="text" id="dinnerGuestRut" name="cliente_invitado_rut" class="rg-field-input" placeholder="RUT del cliente o empresa" maxlength="20">
                            </label>
                            <label class="rg-field-row">
                                <span class="rg-field-label">Empresa</span>
                                <input type="text" id="dinnerGuestCompany" name="cliente_invitado_empresa" class="rg-field-input" placeholder="Empresa del cliente" maxlength="150">
                            </label>
                            <label class="rg-field-row">
                                <span class="rg-field-label">Cargo</span>
                                <input type="text" id="dinnerGuestRole" name="cliente_invitado_cargo" class="rg-field-input" placeholder="Ej: Gerente de Compras" maxlength="100">
                            </label>
                            <label class="rg-field-row rg-field-row--textarea">
                                <span class="rg-field-label">Propósito Comercial</span>
                                <textarea id="dinnerPurpose" name="proposito_comercial" class="rg-field-input rg-textarea" placeholder="Motivo concreto de la reunión y oportunidad de negocio" rows="2" maxlength="255"></textarea>
                            </label>
                        </div>
                    </div>

                    <!-- Comentario o Nota del Comprobante -->
                    <div class="rg-form-group">
                        <label class="rg-field-row rg-field-row--textarea">
                            <span class="rg-field-label">Nota o Comentario</span>
                            <textarea id="expenseDescription" name="descripcion" class="rg-field-input rg-textarea" placeholder="Observaciones sobre este gasto..." rows="2" maxlength="255"></textarea>
                        </label>
                    </div>

                    <!-- Botón para Quitar Gasto (Solo en modo edición de borrador) -->
                    <div class="rg-drawer-actions" id="boxDeleteExpense" hidden>
                        <button type="button" class="rg-btn-delete-ghost" id="btnDeleteExpense">
                            Quitar este comprobante de la bolsa
                        </button>
                    </div>

                </form>
            </div>
        </div>

        <!-- DRAWER FULLSCREEN: CONSOLIDAR INFORME DE RENDICIÓN -->
        <div class="rg-drawer" id="drawerInforme" hidden role="dialog" aria-modal="true" aria-labelledby="drawerInformeTitle">
            <div class="rg-drawer__content">
                
                <header class="rg-drawer__header">
                    <button class="rg-drawer__btn-nav" id="btnCancelInforme" type="button">Cerrar</button>
                    <h2 class="rg-drawer__title" id="drawerInformeTitle">Consolidar Rendición</h2>
                    <div style="width: 44px;"></div> <!-- Balance para centrado -->
                </header>

                <div class="rg-form-body">
                    
                    <div class="rg-form-group">
                        <label class="rg-field-row">
                            <span class="rg-field-label">Presupuesto a Imputar</span>
                            <select id="reportBudgetSelect" class="rg-field-input">
                                <option value="">Cargando presupuestos...</option>
                            </select>
                        </label>
                    </div>

                    <div class="rg-form-group">
                        <label class="rg-field-row rg-field-row--textarea">
                            <span class="rg-field-label">Nota para Tesorería <small>(opcional)</small></span>
                            <textarea id="reportSellerNote" class="rg-field-input rg-textarea" placeholder="Indica antecedentes generales para la revisión de esta rendición..." rows="3" maxlength="500"></textarea>
                        </label>
                    </div>

                    <!-- Totalizador Dinámico -->
                    <div class="rg-totalizer-strip">
                        <div>
                            <span class="rg-totalizer-label">Total a Rendir</span>
                            <strong class="rg-totalizer-amount" id="lblReportTotalSum">$0 CLP</strong>
                        </div>
                        <div class="rg-totalizer-meta">
                            <span id="lblReportSelectedCount">0 boletas</span>
                            <span id="lblReportBudgetRemaining">Saldo: $0</span>
                        </div>
                    </div>

                    <!-- Checklist de Boletas Disponibles -->
                    <div class="rg-checklist-header">
                        <span class="rg-checklist-title">Boletas en Borrador</span>
                        <div class="rg-checklist-actions">
                            <button type="button" class="rg-link-btn" id="btnSelectAllExpenses">Todas</button>
                            <span class="rg-link-divider">·</span>
                            <button type="button" class="rg-link-btn" id="btnSelectNoneExpenses">Ninguna</button>
                        </div>
                    </div>

                    <div class="rg-checklist-container" id="reportExpenseChecklist" role="group" aria-label="Lista de selección de gastos">
                        <!-- Se puebla dinámicamente con botones accesibles role=checkbox -->
                    </div>

                    <!-- Botón Principal Enviar a Tesorería -->
                    <div class="rg-drawer-actions">
                        <button type="button" class="rg-btn-submit-green" id="btnOpenConfirmSubmit">
                            Enviar Rendición a Tesorería
                        </button>
                    </div>

                </div>

            </div>
        </div>

        <!-- MODAL CONFIRMACIÓN DE ENVÍO -->
        <div class="rg-modal-overlay" id="modalConfirmSubmit" hidden>
            <div class="rg-modal-card">
                <div class="rg-modal-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="var(--rg-accent)" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:40px;height:40px;">
                        <line x1="22" y1="2" x2="11" y2="13"></line>
                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                    </svg>
                </div>
                <h3 class="rg-modal-title">¿Enviar Rendición?</h3>
                <p class="rg-modal-text" id="txtConfirmMessage">Se enviará el informe a Tesorería para revisión y validación.</p>
                <div class="rg-modal-alert" id="boxExcessWarning" hidden>
                    <strong>Aviso de exceso:</strong> <span id="txtExcessWarning">El monto total sobrepasa el fondo seleccionado. Se enviará una solicitud de aprobación a Gerencia.</span>
                </div>
                <div class="rg-modal-buttons">
                    <button type="button" class="rg-modal-btn-cancel" id="btnCancelSubmit">Cancelar</button>
                    <button type="button" class="rg-modal-btn-confirm" id="btnConfirmFinalSubmit">Sí, Enviar Rendición</button>
                </div>
            </div>
        </div>

        <!-- BARRA INFERIOR DE NAVEGACIÓN (BOTTOM BAR 62PX) -->
        <nav class="rg-bottom-nav" id="bottomNav" aria-label="Navegación principal">
            <button class="rg-nav-item is-active" type="button" data-nav-target="gastos">
                <div class="rg-nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                        <polyline points="14 2 14 8 20 8"></polyline>
                        <line x1="16" y1="13" x2="8" y2="13"></line>
                        <line x1="16" y1="17" x2="8" y2="17"></line>
                    </svg>
                    <span class="rg-nav-badge" id="navGastosBadge" hidden>0</span>
                </div>
                <span class="rg-nav-label">Gastos</span>
            </button>

            <button class="rg-nav-item" type="button" data-nav-target="informes">
                <div class="rg-nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"></path>
                    </svg>
                </div>
                <span class="rg-nav-label">Informes</span>
            </button>

            <button class="rg-nav-item" type="button" data-nav-target="presupuestos">
                <div class="rg-nav-icon-wrap">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                </div>
                <span class="rg-nav-label">Presupuesto</span>
            </button>
        </nav>

    </div>

    <!-- Toast container & scripts -->
    <div id="toastContainer" class="toast-container"></div>
    <script src="../seller_session.js?v=20260828-session-1" defer></script>
    <script src="vendedor.js?v=20260828-session-1" defer></script>
</body>
</html>
