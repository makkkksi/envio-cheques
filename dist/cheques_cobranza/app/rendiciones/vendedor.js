/**
 * rendiciones/vendedor.js
 * 
 * Lógica Mobile-First para la aplicación de Rendiciones del Vendedor (Estilo Rindegastos).
 * Gestiona: Pestañas de Navegación, Bolsa de Gastos con FAB, Drawers Deslizantes,
 * Carga de Fotos, Validación Dinámica SII (5 campos) y Consolidación de Rendición a Tesorería.
 */

(function () {
    'use strict';

    const API_BASE = '../api/rendiciones';
    const state = {
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
        seller: null,
        activeTab: 'gastos',
        expenseFilter: 'TODOS',
        reportFilter: 'TODOS',
        documents: [],
        budgets: [],
        reports: [],
        selectedForReport: new Set(),
        selectedPhotoFile: null
    };

    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => document.querySelectorAll(selector);

    const clpFormatter = new Intl.NumberFormat('es-CL', {
        style: 'currency',
        currency: 'CLP',
        maximumFractionDigits: 0
    });

    document.addEventListener('DOMContentLoaded', initialize);

    async function initialize() {
        initializeCustomSelects();
        bindEvents();
        $('#expenseDate').value = new Date().toISOString().slice(0, 10);

        try {
            await initializeSellerSession();
            await loadAllData();
            $('#workspace').hidden = false;
        } catch (error) {
            showSessionError(error.message || 'No fue posible iniciar la sesión de rendiciones.');
        } finally {
            $('#sellerApp').setAttribute('aria-busy', 'false');
        }
    }

    function bindEvents() {
        document.addEventListener('seller-session-restored', (event) => {
            const session = event.detail || {};
            state.seller = session.seller || state.seller;
            state.csrfToken = session.csrfToken || state.csrfToken;
        });
        document.addEventListener('seller-session-expired', (event) => {
            showSessionError(event.detail?.message || 'Tu sesión venció y no pudo recuperarse. Vuelve a ingresar desde el portal comercial.');
        });

        // Volver al portal comercial
        $('#btnBackPortal')?.addEventListener('click', () => {
            if (window.history.length > 1) {
                window.history.back();
            } else {
                window.location.href = '../index.html';
            }
        });

        // Botón Refrescar
        $('#refreshButton').addEventListener('click', () => loadAllData(true));

        // Bottom Navigation Bar
        $$('.rg-nav-item').forEach((btn) => {
            btn.addEventListener('click', () => switchTab(btn.dataset.navTarget));
        });

        // Filtros Segmentados de Gastos
        $$('#viewGastos .rg-segment-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                $$('#viewGastos .rg-segment-btn').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                state.expenseFilter = btn.dataset.filter;
                renderExpenseList();
            });
        });

        // Filtros Segmentados de Informes
        $$('#viewInformes .rg-segment-btn').forEach((btn) => {
            btn.addEventListener('click', () => {
                $$('#viewInformes .rg-segment-btn').forEach((b) => b.classList.remove('is-active'));
                btn.classList.add('is-active');
                state.reportFilter = btn.dataset.reportFilter;
                renderReportList();
            });
        });

        // FAB: Nuevo Gasto
        $('#fabNuevoGasto').addEventListener('click', () => openNewExpenseDrawer());
        $('#btnCancelGasto').addEventListener('click', closeNewExpenseDrawer);
        $('#btnSaveGasto').addEventListener('click', submitExpenseForm);
        $('#btnDeleteExpense')?.addEventListener('click', deleteDraftExpense);

        // Control de Carga de Foto
        $('#btnTriggerCamera').addEventListener('click', () => $('#inputExpensePhoto').click());
        $('#inputExpensePhoto').addEventListener('change', handlePhotoSelection);
        $('#btnRemovePhoto').addEventListener('click', removePhotoSelection);

        // Adaptabilidad del Formulario de Gasto
        $('#expenseCategory').addEventListener('change', handleCategoryChange);
        $('#expenseDocType').addEventListener('change', handleDocTypeChange);

        // FAB: Crear Informe
        $('#fabNuevoInforme').addEventListener('click', () => openNewReportDrawer());
        $('#btnCancelInforme').addEventListener('click', closeNewReportDrawer);
        $('#btnOpenConfirmSubmit').addEventListener('click', openConfirmSubmitModal);
        $('#btnCancelSubmit').addEventListener('click', () => $('#modalConfirmSubmit').hidden = true);
        $('#btnConfirmFinalSubmit').addEventListener('click', () => {
            $('#modalConfirmSubmit').hidden = true;
            submitReport();
        });

        // Selección de boletas en informe
        $('#btnSelectAllExpenses').addEventListener('click', () => selectAllExpensesForReport(true));
        $('#btnSelectNoneExpenses').addEventListener('click', () => selectAllExpensesForReport(false));
        $('#reportBudgetSelect').addEventListener('change', updateReportTotalizer);
    }

    // ==========================================
    // SELECTORES PROPIOS ACCESIBLES
    // ==========================================
    function initializeCustomSelects() {
        $$('[data-select-control]').forEach((control) => {
            const select = control.querySelector('select');
            const trigger = control.querySelector('.rg-select-trigger');
            const menu = control.querySelector('.rg-select-menu');
            if (!select || !trigger || !menu) return;

            Array.from(select.options).forEach((option) => {
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'rg-select-option';
                item.dataset.value = option.value;
                item.setAttribute('role', 'option');
                item.textContent = option.textContent.trim();
                item.addEventListener('click', () => {
                    setCustomSelectValue(select, option.value);
                    closeCustomSelect(control, true);
                });
                item.addEventListener('keydown', (event) => {
                    const items = Array.from(menu.querySelectorAll('.rg-select-option'));
                    const index = items.indexOf(item);
                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeCustomSelect(control, true);
                        return;
                    }
                    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
                    event.preventDefault();
                    const nextIndex = event.key === 'Home' ? 0
                        : event.key === 'End' ? items.length - 1
                            : Math.max(0, Math.min(items.length - 1, index + (event.key === 'ArrowDown' ? 1 : -1)));
                    items[nextIndex]?.focus();
                });
                menu.appendChild(item);
            });

            refreshCustomSelect(select);
            trigger.addEventListener('click', () => {
                if (control.classList.contains('is-open')) {
                    closeCustomSelect(control);
                } else {
                    openCustomSelect(control);
                }
            });
            trigger.addEventListener('keydown', (event) => {
                const selectableKeys = ['ArrowDown', 'ArrowUp', 'Home', 'End'];
                if (event.key === 'Escape') {
                    closeCustomSelect(control);
                    return;
                }
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    openCustomSelect(control);
                    return;
                }
                if (!selectableKeys.includes(event.key)) return;

                event.preventDefault();
                if (!control.classList.contains('is-open')) openCustomSelect(control);
                const options = Array.from(select.options);
                const currentIndex = select.selectedIndex;
                const nextIndex = event.key === 'Home' ? 0
                    : event.key === 'End' ? options.length - 1
                        : Math.max(0, Math.min(options.length - 1, currentIndex + (event.key === 'ArrowDown' ? 1 : -1)));
                setCustomSelectValue(select, options[nextIndex].value);
            });
            select.addEventListener('change', () => refreshCustomSelect(select));
        });

        document.addEventListener('click', (event) => {
            $$('.rg-select-control.is-open').forEach((control) => {
                if (!control.contains(event.target)) closeCustomSelect(control);
            });
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                $$('.rg-select-control.is-open').forEach((control) => {
                    closeCustomSelect(control, control.contains(document.activeElement));
                });
            }
        });
    }

    function setCustomSelectValue(select, value) {
        if (select.value === value) {
            refreshCustomSelect(select);
            return;
        }
        select.value = value;
        select.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function refreshCustomSelect(select) {
        const control = select.closest('[data-select-control]');
        if (!control) return;
        const selectedOption = select.options[select.selectedIndex];
        const value = control.querySelector('.rg-select-trigger__value');
        if (value) value.textContent = selectedOption ? selectedOption.textContent.trim() : 'Seleccionar';
        control.querySelectorAll('.rg-select-option').forEach((item) => {
            const isSelected = item.dataset.value === select.value;
            item.classList.toggle('is-selected', isSelected);
            item.setAttribute('aria-selected', String(isSelected));
        });
    }

    function openCustomSelect(control) {
        $$('.rg-select-control.is-open').forEach((openedControl) => {
            if (openedControl !== control) closeCustomSelect(openedControl);
        });

        const menu = control.querySelector('.rg-select-menu');
        const trigger = control.querySelector('.rg-select-trigger');
        if (!menu || !trigger) return;

        menu.hidden = false;
        control.classList.add('is-open');
        control.closest('.rg-form-group')?.classList.add('is-select-open');
        trigger.setAttribute('aria-expanded', 'true');

        const triggerBounds = trigger.getBoundingClientRect();
        const menuHeight = menu.getBoundingClientRect().height;
        control.classList.toggle('is-open-upward', triggerBounds.bottom + menuHeight + 16 > window.innerHeight);

        const selectedItem = menu.querySelector('.rg-select-option.is-selected');
        selectedItem?.focus({ preventScroll: true });
        selectedItem?.scrollIntoView({ block: 'nearest' });
    }

    function closeCustomSelect(control, restoreFocus = false) {
        const menu = control.querySelector('.rg-select-menu');
        const trigger = control.querySelector('.rg-select-trigger');
        if (!menu || !trigger) return;

        menu.hidden = true;
        control.classList.remove('is-open', 'is-open-upward');
        control.closest('.rg-form-group')?.classList.remove('is-select-open');
        trigger.setAttribute('aria-expanded', 'false');
        if (restoreFocus) trigger.focus();
    }

    // ==========================================
    // 1. GESTIÓN DE SESIÓN DEL VENDEDOR
    // ==========================================
    async function initializeSellerSession() {
        const session = await window.SellerSession.initialize();
        state.seller = session.seller || null;
        state.csrfToken = session.csrfToken || state.csrfToken;
    }

    // ==========================================
    // 2. CARGA CENTRAL DE DATOS
    // ==========================================
    async function loadAllData(showToastAlert = false) {
        try {
            const [bagPayload, historyPayload] = await Promise.all([
                apiRequest(`${API_BASE}/get_bolsa_gastos.php`),
                apiRequest(`${API_BASE}/get_mis_rendiciones.php?estado=TODOS&limite=50`)
            ]);

            state.documents = bagPayload.data.documentos || [];
            state.budgets = bagPayload.data.presupuestos || [];
            state.reports = historyPayload.data.rendiciones || [];
            state.csrfToken = bagPayload.data.csrf_token || state.csrfToken;

            // Actualizar vistas
            renderExpenseList();
            renderReportList();
            renderBudgetView();
            updateBadges();

            if (showToastAlert) showToast('Información actualizada', 'success');
        } catch (error) {
            showToast(error.message || 'Error al actualizar información', 'error');
        }
    }

    // ==========================================
    // 3. CAMBIO DE PESTAÑAS (BOTTOM NAV)
    // ==========================================
    function switchTab(tabId) {
        state.activeTab = tabId;

        // Actualizar items de Bottom Nav
        $$('.rg-nav-item').forEach((btn) => {
            btn.classList.toggle('is-active', btn.dataset.navTarget === tabId);
        });

        // Ocultar todas las vistas y mostrar la activa
        $('#viewGastos').hidden = tabId !== 'gastos';
        $('#viewInformes').hidden = tabId !== 'informes';
        $('#viewPresupuestos').hidden = tabId !== 'presupuestos';

        // Actualizar título de la barra superior
        const titles = {
            gastos: 'Gastos',
            informes: 'Informes',
            presupuestos: 'Presupuesto'
        };
        $('#pageTitle').textContent = titles[tabId] || 'Rendiciones';
    }

    function updateBadges() {
        const draftCount = state.documents.filter((d) => !d.rendicion_id).length;
        $('#badgeDraftCount').textContent = draftCount;

        const navBadge = $('#navGastosBadge');
        if (draftCount > 0) {
            navBadge.textContent = draftCount;
            navBadge.hidden = false;
        } else {
            navBadge.hidden = true;
        }

        $('#badgeReportsCount').textContent = state.reports.length;
    }

    // ==========================================
    // 4. RENDERIZADO: LISTA DE GASTOS (PESTAÑA 1)
    // ==========================================
    function renderExpenseList() {
        const container = $('#expenseList');
        const emptyState = $('#emptyGastos');

        let filtered = state.documents;
        if (state.expenseFilter === 'BORRADORES') {
            filtered = state.documents.filter((d) => !d.rendicion_id);
        } else if (state.expenseFilter === 'ENVIADOS') {
            filtered = state.documents.filter((d) => Boolean(d.rendicion_id));
        }

        if (filtered.length === 0) {
            container.innerHTML = '';
            emptyState.hidden = false;
            return;
        }

        emptyState.hidden = true;
        container.innerHTML = filtered.map((doc) => {
            const isDraft = !doc.rendicion_id;
            const categoryLabel = humanize(doc.categoria_gasto);
            const dateStr = formatDateShort(doc.fecha_emision);
            const amountStr = formatMoney(doc.monto);
            const thumbUrl = normalizePhotoUrl(doc.foto_documento_url);

            return `
                <article class="rg-expense-card" data-doc-id="${doc.id}" role="button" tabindex="0" title="${isDraft ? 'Toca para editar este gasto' : 'Gasto ya enviado a Tesorería'}">
                    <div class="rg-expense-thumb">
                        ${thumbUrl
                    ? `<img src="${escapeHtml(thumbUrl)}" alt="Foto comprobante" loading="lazy">`
                    : `<span class="rg-expense-thumb-placeholder">${getCategoryIconSvg(doc.categoria_gasto)}</span>`
                }
                    </div>
                    <div class="rg-expense-info">
                        <div class="rg-expense-merchant">${escapeHtml(doc.razon_social_proveedor || 'Sin Comercio')}</div>
                        <div class="rg-expense-meta">
                            <span class="rg-expense-meta-cat">${escapeHtml(categoryLabel)}</span>
                            <span>·</span>
                            <span>${escapeHtml(dateStr)}</span>
                        </div>
                    </div>
                    <div class="rg-expense-right">
                        <div class="rg-expense-amount">${amountStr}</div>
                        <span class="rg-expense-status-tag ${isDraft ? 'rg-status--draft' : 'rg-status--sent'}">
                            ${isDraft ? 'Borrador · Editar' : 'Enviado'}
                        </span>
                    </div>
                </article>
            `;
        }).join('');

        // Vincular click a cada tarjeta de gasto
        container.querySelectorAll('.rg-expense-card').forEach((card) => {
            const docId = Number(card.dataset.docId);
            const doc = state.documents.find((d) => d.id === docId);
            if (!doc) return;

            const handleCardAction = () => {
                if (doc.rendicion_id) {
                    showToast('Este comprobante ya forma parte de una rendición enviada y no puede modificarse.', 'error');
                    return;
                }
                openEditExpenseDrawer(doc);
            };

            card.addEventListener('click', handleCardAction);
            card.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    handleCardAction();
                }
            });
        });
    }

    // ==========================================
    // 5. RENDERIZADO: LISTA DE INFORMES (PESTAÑA 2)
    // ==========================================
    function renderReportList() {
        const container = $('#reportList');
        const emptyState = $('#emptyInformes');

        let filtered = state.reports;
        if (state.reportFilter === 'EN_REVISION') {
            filtered = state.reports.filter((r) => ['ENVIADA', 'PENDIENTE_APROBACION_EXCESO', 'EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'].includes(r.estado));
        } else if (state.reportFilter === 'APROBADA') {
            filtered = state.reports.filter((r) => ['APROBADA', 'APROBADA_PARCIAL', 'PAGADA'].includes(r.estado));
        } else if (state.reportFilter === 'RECHAZADA') {
            filtered = state.reports.filter((r) => r.estado === 'RECHAZADA');
        }

        if (filtered.length === 0) {
            container.innerHTML = '';
            emptyState.hidden = false;
            return;
        }

        emptyState.hidden = true;
        container.innerHTML = filtered.map((rep) => {
            const statusBadge = getReportStatusBadge(rep.estado);
            const typeLabel = rep.tipo_rendicion === 'GIRA' ? `Gira: ${rep.nombre_gira || 'Comercial'}` : `Mensual · ${rep.periodo_mes}`;
            const totalStr = formatMoney(rep.monto_total_rendido);
            const docsCount = rep.cantidad_documentos || 0;

            return `
                <article class="rg-report-card">
                    <div class="rg-report-card__top">
                        <div>
                            <div class="rg-report-title">${escapeHtml(rep.codigo_rendicion)}</div>
                            <small style="color: var(--rg-text-muted); font-weight:600;">${escapeHtml(typeLabel)}</small>
                        </div>
                        <span class="rg-expense-status-tag ${statusBadge.className}">${escapeHtml(statusBadge.label)}</span>
                    </div>
                    <div class="rg-report-meta">
                        <span>${docsCount} documento${docsCount !== 1 ? 's' : ''}</span>
                        <strong class="rg-report-amount">${totalStr}</strong>
                    </div>
                </article>
            `;
        }).join('');
    }

    // ==========================================
    // 6. RENDERIZADO: PRESUPUESTOS (PESTAÑA 3)
    // ==========================================
    function renderBudgetView() {
        const monthly = state.budgets.find((b) => b.tipo_presupuesto === 'MENSUAL');
        updateMonthlyBudgetCard(monthly);

        const tours = state.budgets.filter((b) => b.tipo_presupuesto === 'GIRA');
        const toursContainer = $('#toursContainer');
        if (tours.length > 0) {
            toursContainer.innerHTML = `
                <div class="rg-section-header rg-budget-section-heading">
                    <span class="rg-section-title">Giras Comerciales Activas</span>
                </div>
                ${tours.map((tour) => renderTourBudgetCard(tour)).join('')}
            `;
        } else {
            toursContainer.innerHTML = '';
        }
    }

    function getBudgetSnapshot(budget) {
        if (!budget) {
            return {
                assigned: 0, committed: 0, approved: 0, pending: 0, balance: 0,
                percentage: 0, rawPercentage: 0, approvedPercentage: 0,
                pendingPercentage: 0, approvedWidth: 0, pendingWidth: 0,
                state: 'is-unassigned', status: 'Sin presupuesto asignado',
                balanceLabel: 'Asignación pendiente', balanceValue: '—'
            };
        }

        const assigned = Number(budget.monto_asignado || 0);
        const committed = Number(budget.monto_utilizado ?? budget.monto_gastado ?? 0);
        const approved = Math.max(0, Number(budget.monto_aprobado || 0));
        const pending = Math.max(0, Number(budget.monto_pendiente ?? (committed - approved)));
        const balance = Number(budget.saldo_disponible ?? (assigned - committed));
        const rawPercentage = assigned > 0 ? Math.round((committed / assigned) * 100) : 0;
        const approvedPercentage = assigned > 0 ? Math.round((approved / assigned) * 100) : 0;
        const pendingPercentage = assigned > 0 ? Math.round((pending / assigned) * 100) : 0;
        const percentage = Math.min(100, Math.max(0, rawPercentage));
        const approvedWidth = Math.min(100, Math.max(0, (approved / assigned) * 100 || 0));
        const pendingWidth = Math.min(100 - approvedWidth, Math.max(0, (pending / assigned) * 100 || 0));
        const snapshot = {
            assigned, committed, approved, pending, balance, percentage, rawPercentage,
            approvedPercentage, pendingPercentage, approvedWidth, pendingWidth
        };

        if (balance < 0) {
            return { ...snapshot, state: 'is-overrun', status: `Exceso comprometido: ${formatMoney(Math.abs(balance))}`, balanceLabel: 'Exceso comprometido', balanceValue: formatMoney(Math.abs(balance)) };
        }
        if (balance === 0) {
            return { ...snapshot, state: 'is-exhausted', status: pending > 0 ? `${formatMoney(pending)} pendiente de revisión` : 'Cupo aprobado por completo', balanceLabel: 'Saldo disponible', balanceValue: formatMoney(0) };
        }
        if (assigned > 0 && balance <= assigned * 0.2) {
            return { ...snapshot, state: 'is-low', status: pending > 0 ? `${formatMoney(pending)} pendiente de revisión` : 'Saldo bajo para nuevas boletas', balanceLabel: 'Saldo disponible', balanceValue: formatMoney(balance) };
        }
        return { ...snapshot, state: 'is-healthy', status: pending > 0 ? `${formatMoney(pending)} pendiente de revisión` : 'Saldo disponible para nuevas boletas', balanceLabel: 'Saldo disponible', balanceValue: formatMoney(balance) };
    }

    function updateMonthlyBudgetCard(monthly) {
        const card = $('.rg-budget-card--monthly');
        const snapshot = getBudgetSnapshot(monthly);
        card.classList.remove('is-unassigned', 'is-overrun', 'is-exhausted', 'is-low', 'is-healthy');
        card.classList.add(snapshot.state);

        $('#lblBudgetPeriod').textContent = monthly ? formatPeriodMonth(monthly.periodo_mes) : 'Sin asignar';
        $('#lblMonthlyBudgetAmount').textContent = formatMoney(snapshot.assigned);
        $('#lblMonthlyApproved').textContent = formatMoney(snapshot.approved);
        $('#lblMonthlyApprovedPercent').textContent = `${snapshot.approvedPercentage}% del fondo`;
        $('#lblMonthlyPending').textContent = formatMoney(snapshot.pending);
        $('#lblMonthlyPendingPercent').textContent = `${snapshot.pendingPercentage}% del fondo`;
        $('#lblMonthlyStatus').textContent = snapshot.status;
        $('#lblMonthlyBalanceLabel').textContent = snapshot.balanceLabel;
        $('#lblMonthlyBalance').textContent = snapshot.balanceValue;
        $('#lblMonthlyProgressPercent').textContent = `${snapshot.rawPercentage}% comprometido`;

        const progress = $('#monthlyBudgetProgress');
        const progressBar = $('#barMonthlyProgress');
        progress.hidden = snapshot.state === 'is-unassigned';
        progressBar.setAttribute('aria-valuenow', String(snapshot.percentage));
        progressBar.setAttribute('aria-valuetext', `${snapshot.approvedPercentage}% aprobado y ${snapshot.pendingPercentage}% pendiente de revisión`);
        progressBar.querySelector('.rg-budget-progress-bar--approved').style.width = `${snapshot.approvedWidth}%`;
        progressBar.querySelector('.rg-budget-progress-bar--pending').style.width = `${snapshot.pendingWidth}%`;
    }

    function renderTourBudgetCard(tour) {
        const snapshot = getBudgetSnapshot(tour);
        return `
            <article class="rg-budget-card rg-budget-card--tour ${snapshot.state}">
                <div class="rg-budget-card__header">
                    <span class="rg-budget-label">${escapeHtml(tour.nombre_gira || 'Gira Comercial')}</span>
                    <span class="rg-budget-period">${formatDateShort(tour.fecha_inicio)} al ${formatDateShort(tour.fecha_fin)}</span>
                </div>
                <div class="rg-budget-summary">
                    <div class="rg-budget-total">
                        <span class="rg-budget-amount-label">Monto asignado</span>
                        <div class="rg-budget-amount">${formatMoney(snapshot.assigned)}</div>
                        <span class="rg-budget-status">${escapeHtml(snapshot.status)}</span>
                    </div>
                    <dl class="rg-budget-breakdown">
                        <div class="rg-budget-breakdown__approved"><dt>Aprobado</dt><dd>${formatMoney(snapshot.approved)}</dd><span>${snapshot.approvedPercentage}% del fondo</span></div>
                        <div class="rg-budget-breakdown__pending"><dt>Pendiente Tesorería</dt><dd>${formatMoney(snapshot.pending)}</dd><span>${snapshot.pendingPercentage}% del fondo</span></div>
                        <div><dt>${escapeHtml(snapshot.balanceLabel)}</dt><dd>${escapeHtml(snapshot.balanceValue)}</dd><span>Para nuevas boletas</span></div>
                    </dl>
                </div>
                <div class="rg-budget-progress" ${snapshot.state === 'is-unassigned' ? 'hidden' : ''}>
                    <div class="rg-budget-progress-header"><span>Estado del fondo</span><strong>${snapshot.rawPercentage}% comprometido</strong></div>
                    <div class="rg-budget-progress-wrap" role="progressbar" aria-label="Estado del presupuesto de gira" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${snapshot.percentage}" aria-valuetext="${snapshot.approvedPercentage}% aprobado y ${snapshot.pendingPercentage}% pendiente de revisión"><div class="rg-budget-progress-bar rg-budget-progress-bar--approved" style="width:${snapshot.approvedWidth}%"></div><div class="rg-budget-progress-bar rg-budget-progress-bar--pending" style="width:${snapshot.pendingWidth}%"></div></div>
                    <div class="rg-budget-legend" aria-hidden="true"><span><i class="is-approved"></i>Aprobado</span><span><i class="is-pending"></i>Pendiente</span><span><i class="is-available"></i>Disponible</span></div>
                </div>
            </article>
        `;
    }

    // ==========================================
    // 7. DRAWER: NUEVO / EDITAR GASTO
    // ==========================================
    function openNewExpenseDrawer() {
        resetExpenseForm();
        $('#drawerGastoTitle').textContent = 'Nuevo Gasto';
        if ($('#boxDeleteExpense')) {
            $('#boxDeleteExpense').hidden = true;
        }
        $('#drawerNuevoGasto').hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function openEditExpenseDrawer(doc) {
        resetExpenseForm();
        $('#drawerGastoTitle').textContent = 'Editar Gasto';
        $('#editDocumentId').value = doc.id;
        $('#expenseMerchant').value = doc.razon_social_proveedor || '';
        $('#expenseRut').value = doc.rut_proveedor || '';
        $('#expenseDate').value = String(doc.fecha_emision || '').slice(0, 10);
        $('#expenseAmount').value = Number(doc.monto || 0);
        $('#expenseCategory').value = doc.categoria_gasto || 'OTROS';
        $('#expenseDocType').value = doc.tipo_documento || 'BOLETA_ELECTRONICA';
        $('#expenseDocNumber').value = doc.numero_documento || '';
        $('#expenseDescription').value = doc.descripcion || '';

        handleCategoryChange();

        if (doc.categoria_gasto === 'CENA_CLIENTE') {
            $('#dinnerGuestName').value = doc.cliente_invitado_nombre || '';
            $('#dinnerGuestRut').value = doc.cliente_invitado_rut || '';
            $('#dinnerGuestCompany').value = doc.cliente_invitado_empresa || '';
            $('#dinnerGuestRole').value = doc.cliente_invitado_cargo || '';
            $('#dinnerPurpose').value = doc.proposito_comercial || '';
        }

        if (doc.foto_documento_url) {
            const photoUrl = normalizePhotoUrl(doc.foto_documento_url);
            $('#imgPhotoPreview').src = photoUrl;
            $('#photoEmptyState').hidden = true;
            $('#photoPreviewState').hidden = false;
        }

        if ($('#boxDeleteExpense')) {
            $('#boxDeleteExpense').hidden = false;
        }

        $('#drawerNuevoGasto').hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeNewExpenseDrawer() {
        $('#drawerNuevoGasto').hidden = true;
        document.body.style.overflow = '';
    }

    function handleCategoryChange() {
        const category = $('#expenseCategory').value;
        const isDinner = category === 'CENA_CLIENTE';
        const isToll = category === 'PEAJES';

        $('#boxDinnerFields').hidden = !isDinner;

        if (isToll) {
            $('#expenseDocType').value = 'PEAJE';
            refreshCustomSelect($('#expenseDocType'));
            $('#rowRutProveedor').hidden = true;
            if (!$('#expenseMerchant').value) $('#expenseMerchant').value = 'Autopista / Peaje';
        } else {
            $('#rowRutProveedor').hidden = false;
        }
    }

    function handleDocTypeChange() {
        const docType = $('#expenseDocType').value;
        if (docType === 'PEAJE') {
            $('#expenseCategory').value = 'PEAJES';
            refreshCustomSelect($('#expenseCategory'));
            handleCategoryChange();
        }
    }

    function handlePhotoSelection(e) {
        const file = e.target.files?.[0];
        if (!file) return;

        state.selectedPhotoFile = file;
        const reader = new FileReader();
        reader.onload = (event) => {
            $('#imgPhotoPreview').src = event.target.result;
            $('#photoEmptyState').hidden = true;
            $('#photoPreviewState').hidden = false;
        };
        reader.readAsDataURL(file);
    }

    function removePhotoSelection() {
        state.selectedPhotoFile = null;
        $('#inputExpensePhoto').value = '';
        $('#imgPhotoPreview').removeAttribute('src');
        $('#photoEmptyState').hidden = false;
        $('#photoPreviewState').hidden = true;
    }

    function resetExpenseForm() {
        $('#formGasto').reset();
        $('#editDocumentId').value = '';
        $('#expenseDate').value = new Date().toISOString().slice(0, 10);
        $('#boxDinnerFields').hidden = true;
        $('#rowRutProveedor').hidden = false;
        removePhotoSelection();
        refreshCustomSelect($('#expenseCategory'));
        refreshCustomSelect($('#expenseDocType'));
    }

    async function deleteDraftExpense() {
        const docId = Number($('#editDocumentId').value);
        if (!docId) return;

        if (!confirm('¿Deseas quitar este comprobante de tu bolsa de gastos?')) {
            return;
        }

        const btnDelete = $('#btnDeleteExpense');
        btnDelete.disabled = true;
        btnDelete.textContent = 'Quitando...';

        try {
            const payload = await apiRequest(`${API_BASE}/eliminar_documento_bolsa.php`, {
                method: 'POST',
                body: JSON.stringify({ documento_id: docId })
            });

            showToast(payload.message || 'Gasto quitado de la bolsa', 'success');
            closeNewExpenseDrawer();
            await loadAllData();
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            btnDelete.disabled = false;
            btnDelete.textContent = 'Quitar este comprobante de la bolsa';
        }
    }

    async function submitExpenseForm() {
        const form = $('#formGasto');
        const merchant = $('#expenseMerchant').value.trim();
        const amount = Number($('#expenseAmount').value);
        const category = $('#expenseCategory').value;
        const docId = Number($('#editDocumentId').value || 0);

        if (!merchant) {
            showToast('Ingresa el nombre del comercio o proveedor', 'error');
            $('#expenseMerchant').focus();
            return;
        }

        if (!amount || amount <= 0) {
            showToast('Ingresa un monto válido mayor a $0', 'error');
            $('#expenseAmount').focus();
            return;
        }

        // Validación estricta de los 5 campos exigidos por SII para CENA CLIENTE
        if (category === 'CENA_CLIENTE') {
            const guestName = $('#dinnerGuestName').value.trim();
            const guestRut = $('#dinnerGuestRut').value.trim();
            const guestCompany = $('#dinnerGuestCompany').value.trim();
            const guestRole = $('#dinnerGuestRole').value.trim();
            const purpose = $('#dinnerPurpose').value.trim();

            if (!guestName || !guestRut || !guestCompany || !guestRole || !purpose) {
                showToast('Completa los 5 campos obligatorios del SII para la Cena Cliente (Invitado, RUT, Empresa, Cargo y Propósito)', 'error');
                return;
            }
        }

        // Si es un gasto nuevo, requerir foto
        if (!docId && !state.selectedPhotoFile) {
            showToast('Debes adjuntar o tomar una fotografía del comprobante', 'error');
            return;
        }

        const formData = new FormData(form);
        if (state.selectedPhotoFile) {
            formData.set('foto_documento', state.selectedPhotoFile);
        }

        const btnSave = $('#btnSaveGasto');
        btnSave.disabled = true;
        btnSave.textContent = 'Guardando...';

        try {
            const response = await fetch(`${API_BASE}/guardar_documento_bolsa.php`, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-CSRF-Token': state.csrfToken
                },
                credentials: 'same-origin'
            });

            const payload = await response.json();
            if (!response.ok || !payload.success) {
                throw new Error(payload.message || 'No fue posible guardar el comprobante.');
            }

            showToast(payload.message || (docId ? 'Gasto actualizado' : 'Gasto agregado a tu bolsa'), 'success');
            closeNewExpenseDrawer();
            await loadAllData();
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            btnSave.disabled = false;
            btnSave.textContent = 'Guardar';
        }
    }

    // ==========================================
    // 8. DRAWER: CONSOLIDAR INFORME DE RENDICIÓN
    // ==========================================
    const NO_BUDGET_MESSAGE = 'No puedes enviar rendiciones porque no tienes un presupuesto asignado. Si necesitas uno, solicítalo a Gerencia.';

    function openNewReportDrawer() {
        if (state.budgets.length === 0) {
            showToast(NO_BUDGET_MESSAGE, 'error');
            return;
        }

        const draftDocs = state.documents.filter((d) => !d.rendicion_id);
        if (draftDocs.length === 0) {
            showToast('No tienes boletas en borrador para armar una rendición', 'error');
            return;
        }

        populateReportBudgetSelector();
        $('#reportSellerNote').value = '';

        // Seleccionar todas por defecto
        state.selectedForReport = new Set(draftDocs.map((d) => d.id));
        renderReportExpenseChecklist();
        updateReportTotalizer();

        $('#drawerInforme').hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeNewReportDrawer() {
        $('#drawerInforme').hidden = true;
        document.body.style.overflow = '';
    }

    function populateReportBudgetSelector() {
        const select = $('#reportBudgetSelect');
        const cardsContainer = $('#reportBudgetCardsList');
        const countBadge = $('#lblBudgetOptionsCount');

        select.innerHTML = '';
        if (cardsContainer) cardsContainer.innerHTML = '';

        if (!state.budgets || state.budgets.length === 0) {
            if (countBadge) countBadge.textContent = '0 disponibles';
            if (cardsContainer) {
                cardsContainer.innerHTML = `
                    <div class="rg-budget-empty-card">
                        <div class="rg-budget-empty-icon"><svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
                        <div class="rg-budget-empty-text">No tienes presupuestos asignados ni giras aprobadas disponibles.</div>
                    </div>
                `;
            }
            return;
        }

        const availableBudgets = state.budgets.filter((b) => Number(b.saldo_disponible || 0) > 0);

        if (countBadge) {
            const count = state.budgets.length;
            const availCount = availableBudgets.length;
            if (availCount === 0) {
                countBadge.textContent = 'Sin fondos disponibles ($0)';
                countBadge.style.color = 'var(--rg-red)';
            } else {
                countBadge.textContent = `${availCount} de ${count} fondos con saldo`;
                countBadge.style.color = '';
            }
        }

        // Si ya había uno seleccionado en el select y sigue teniendo saldo disponible, mantenerlo; sino preseleccionar el primero con saldo
        let activeBudgetId = Number(select.value);
        if (!availableBudgets.some((b) => b.id === activeBudgetId)) {
            activeBudgetId = availableBudgets.length > 0 ? availableBudgets[0].id : null;
        }

        // Llenar select nativo (mantenido sincronizado por debajo)
        state.budgets.forEach((b) => {
            const available = Number(b.saldo_disponible || 0);
            const isExhausted = available <= 0;
            const opt = document.createElement('option');
            opt.value = b.id;
            opt.disabled = isExhausted;
            opt.textContent = b.tipo_presupuesto === 'GIRA'
                ? `Gira: ${b.nombre_gira} (${isExhausted ? 'Saldo agotado $0' : 'Saldo: ' + formatMoney(available)})`
                : `Mensual: ${formatPeriodMonth(b.periodo_mes)} (${isExhausted ? 'Saldo agotado $0' : 'Saldo: ' + formatMoney(available)})`;
            if (b.id === activeBudgetId && !isExhausted) opt.selected = true;
            select.appendChild(opt);
        });

        // Llenar tarjetas interactivas accesibles
        if (cardsContainer) {
            cardsContainer.innerHTML = state.budgets.map((b) => {
                const available = Number(b.saldo_disponible || 0);
                const isExhausted = available <= 0;
                const isSelected = b.id === activeBudgetId && !isExhausted;
                const isTour = b.tipo_presupuesto === 'GIRA';
                const isLow = available > 0 && available <= Number(b.monto_asignado || 0) * 0.2;

                const typeLabel = isTour ? 'Gira Comercial' : 'Presupuesto Mensual';
                const typeClass = isTour ? 'rg-budget-type-badge--gira' : 'rg-budget-type-badge--mensual';

                const title = isTour ? (b.nombre_gira || 'Gira Comercial') : `Presupuesto ${formatPeriodMonth(b.periodo_mes)}`;

                let dateOrPeriodMeta = '';
                if (isTour && (b.fecha_inicio || b.fecha_fin)) {
                    dateOrPeriodMeta = `<span>${formatDateShort(b.fecha_inicio)} al ${formatDateShort(b.fecha_fin)}</span> · `;
                }

                let balanceBadgeClass = 'is-healthy';
                let balanceBadgeText = `Saldo: ${formatMoney(available)}`;
                if (isExhausted) {
                    balanceBadgeClass = 'is-exhausted';
                    balanceBadgeText = `Saldo agotado ($0)`;
                } else if (isLow) {
                    balanceBadgeClass = 'is-low';
                    balanceBadgeText = `Saldo bajo: ${formatMoney(available)}`;
                }

                return `
                    <div class="rg-budget-choice-card ${isSelected ? 'is-selected' : ''} ${isExhausted ? 'is-disabled' : ''}" 
                         role="radio" 
                         aria-checked="${isSelected ? 'true' : 'false'}"
                         aria-disabled="${isExhausted ? 'true' : 'false'}"
                         tabindex="${isExhausted ? '-1' : '0'}"
                         data-budget-id="${b.id}"
                         data-exhausted="${isExhausted ? 'true' : 'false'}">
                        <div class="rg-budget-choice-radio" aria-hidden="true">
                            <span class="rg-budget-choice-dot"></span>
                        </div>
                        <div class="rg-budget-choice-content">
                            <div class="rg-budget-choice-top">
                                <span class="rg-budget-type-badge ${typeClass}">
                                    ${typeLabel}
                                </span>
                                <span class="rg-budget-balance-badge ${balanceBadgeClass}">
                                    ${balanceBadgeText}
                                </span>
                            </div>
                            <div class="rg-budget-choice-title">${escapeHtml(title)}</div>
                            <div class="rg-budget-choice-meta">
                                ${dateOrPeriodMeta}
                                <span>Cupo asignado: <strong>${formatMoney(b.monto_asignado)}</strong></span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            // Vincular click y teclado a cada tarjeta
            cardsContainer.querySelectorAll('.rg-budget-choice-card').forEach((card) => {
                const bId = Number(card.dataset.budgetId);
                const isExhausted = card.dataset.exhausted === 'true';

                if (isExhausted) {
                    card.addEventListener('click', () => {
                        showToast('Este fondo tiene su saldo agotado ($0). No es posible imputarle nuevas rendiciones.', 'warning');
                    });
                    return;
                }

                const selectCard = () => {
                    cardsContainer.querySelectorAll('.rg-budget-choice-card').forEach((c) => {
                        const active = Number(c.dataset.budgetId) === bId;
                        c.classList.toggle('is-selected', active);
                        c.setAttribute('aria-checked', active ? 'true' : 'false');
                    });
                    select.value = bId;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    updateReportTotalizer();
                };

                card.addEventListener('click', selectCard);
                card.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        e.preventDefault();
                        selectCard();
                    }
                });
            });
        }
    }

    function renderReportExpenseChecklist() {
        const container = $('#reportExpenseChecklist');
        const draftDocs = state.documents.filter((d) => !d.rendicion_id);

        container.innerHTML = draftDocs.map((doc) => {
            const isChecked = state.selectedForReport.has(doc.id);
            return `
                <button type="button" class="rg-check-item ${isChecked ? 'is-selected' : ''}" 
                        role="checkbox" 
                        aria-checked="${isChecked ? 'true' : 'false'}"
                        data-item-id="${doc.id}">
                    <div class="rg-check-circle" aria-hidden="true">${isChecked ? '<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>' : ''}</div>
                    <div class="rg-check-info">
                        <div class="rg-check-merchant">${escapeHtml(doc.razon_social_proveedor || 'Sin Comercio')}</div>
                        <div class="rg-check-date">${formatDateShort(doc.fecha_emision)} · ${escapeHtml(humanize(doc.categoria_gasto))}</div>
                    </div>
                    <div class="rg-check-amount">${formatMoney(doc.monto)}</div>
                </button>
            `;
        }).join('');

        container.querySelectorAll('.rg-check-item').forEach((item) => {
            item.addEventListener('click', () => {
                const id = Number(item.dataset.itemId);
                if (state.selectedForReport.has(id)) {
                    state.selectedForReport.delete(id);
                } else {
                    state.selectedForReport.add(id);
                }
                renderReportExpenseChecklist();
                updateReportTotalizer();
            });
        });
    }

    function selectAllExpensesForReport(selectAll) {
        const draftDocs = state.documents.filter((d) => !d.rendicion_id);
        if (selectAll) {
            state.selectedForReport = new Set(draftDocs.map((d) => d.id));
        } else {
            state.selectedForReport.clear();
        }
        renderReportExpenseChecklist();
        updateReportTotalizer();
    }

    function updateReportTotalizer() {
        const selectedDocs = state.documents.filter((d) => state.selectedForReport.has(d.id));
        const total = selectedDocs.reduce((sum, d) => sum + Number(d.monto || 0), 0);

        $('#lblReportTotalSum').textContent = `${formatMoney(total)} CLP`;
        $('#lblReportSelectedCount').textContent = `${selectedDocs.length} boleta${selectedDocs.length !== 1 ? 's' : ''}`;

        const selectedBudgetId = Number($('#reportBudgetSelect').value);
        const budget = state.budgets.find((b) => b.id === selectedBudgetId);
        const submitBtn = $('#btnOpenConfirmSubmit');

        if (budget) {
            const available = Number(budget.saldo_disponible || 0);
            const remaining = available - total;
            if (available <= 0) {
                $('#lblReportBudgetRemaining').textContent = `${budget.tipo_presupuesto === 'GIRA' ? 'Gira agotada' : 'Mensual agotado'} ($0) · No permite envíos`;
                $('#lblReportBudgetRemaining').style.color = 'var(--rg-red)';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.title = 'No puedes enviar rendiciones a un fondo con saldo agotado ($0)';
                }
            } else {
                $('#lblReportBudgetRemaining').textContent = `${budget.tipo_presupuesto === 'GIRA' ? 'Saldo gira' : 'Saldo mensual'}: ${formatMoney(remaining)}`;
                $('#lblReportBudgetRemaining').style.color = remaining < 0 ? 'var(--rg-red)' : 'var(--rg-text-muted)';
                if (submitBtn) {
                    submitBtn.disabled = selectedDocs.length === 0;
                    submitBtn.title = selectedDocs.length === 0 ? 'Selecciona al menos una boleta para enviar' : '';
                }
            }
        } else {
            $('#lblReportBudgetRemaining').textContent = 'Sin fondo con saldo disponible';
            $('#lblReportBudgetRemaining').style.color = 'var(--rg-red)';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.title = 'No hay un fondo con saldo disponible seleccionado';
            }
        }
    }

    function openConfirmSubmitModal() {
        const selectedBudgetId = Number($('#reportBudgetSelect').value);
        const budget = state.budgets.find((b) => b.id === selectedBudgetId);
        if (!budget) {
            showToast(NO_BUDGET_MESSAGE, 'error');
            return;
        }

        const available = Number(budget.saldo_disponible || 0);
        if (available <= 0) {
            showToast('Este fondo tiene su saldo agotado ($0). No es posible enviar nuevas rendiciones a este presupuesto.', 'error');
            return;
        }

        const selectedDocs = state.documents.filter((d) => state.selectedForReport.has(d.id));
        if (selectedDocs.length === 0) {
            showToast('Selecciona al menos una boleta para enviar', 'error');
            return;
        }

        const total = selectedDocs.reduce((sum, d) => sum + Number(d.monto || 0), 0);
        const isTour = budget.tipo_presupuesto === 'GIRA';
        const fundTitle = isTour ? (budget.nombre_gira || 'Gira Comercial') : formatPeriodMonth(budget.periodo_mes);
        const fundLabel = isTour ? `la gira “${fundTitle}”` : `el presupuesto mensual de ${fundTitle}`;
        const excess = Math.max(0, total - available);

        $('#txtConfirmMessage').textContent = `Se imputarán ${selectedDocs.length} boletas por ${formatMoney(total)} CLP a ${fundLabel}.`;

        // Actualizar desglose visual en modal
        const fundDestEl = $('#lblModalFundDest');
        if (fundDestEl) {
            const badgeHtml = `<span class="rg-budget-type-badge ${isTour ? 'rg-budget-type-badge--gira' : 'rg-budget-type-badge--mensual'}">${isTour ? 'Gira Comercial' : 'Presupuesto Mensual'}</span>`;
            fundDestEl.innerHTML = `${badgeHtml} <span>${escapeHtml(fundTitle)}</span>`;
        }
        const docsCountEl = $('#lblModalDocsCount');
        if (docsCountEl) {
            docsCountEl.textContent = `${selectedDocs.length} boleta${selectedDocs.length !== 1 ? 's' : ''}`;
        }
        const totalRendirEl = $('#lblModalTotalRendir');
        if (totalRendirEl) {
            totalRendirEl.textContent = `${formatMoney(total)} CLP`;
        }
        const saldoFondoEl = $('#lblModalSaldoFondo');
        if (saldoFondoEl) {
            saldoFondoEl.textContent = `${formatMoney(available)} CLP`;
            saldoFondoEl.style.color = available <= 0 ? 'var(--rg-red)' : 'var(--rg-text-main)';
        }

        $('#boxExcessWarning').hidden = excess <= 0;
        $('#txtExcessWarning').textContent = isTour
            ? `El informe supera en ${formatMoney(excess)} el saldo de esta gira. Si se aprueba, se reembolsará hasta el tope del saldo disponible (${formatMoney(available)}).`
            : `El informe supera en ${formatMoney(excess)} el saldo mensual. Se pagará hasta el tope disponible (${formatMoney(available)}) y Tesorería podrá solicitar excepción a Gerencia.`;

        $('#modalConfirmSubmit').hidden = false;
    }

    async function submitReport() {
        const selectedDocIds = Array.from(state.selectedForReport);
        if (selectedDocIds.length === 0) {
            showToast('Selecciona al menos un comprobante', 'error');
            return;
        }

        const budgetId = Number($('#reportBudgetSelect').value);
        const budget = state.budgets.find((b) => b.id === budgetId);
        if (!budget || Number(budget.saldo_disponible || 0) <= 0) {
            showToast('El fondo seleccionado tiene su saldo agotado ($0). No es posible enviar la rendición.', 'error');
            return;
        }

        const payloadData = {
            presupuesto_id: budgetId,
            documento_ids: selectedDocIds,
            nota_vendedor: $('#reportSellerNote').value.trim()
        };

        const btnSubmit = $('#btnConfirmFinalSubmit');
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Enviando...';

        try {
            const payload = await apiRequest(`${API_BASE}/guardar_rendicion.php`, {
                method: 'POST',
                body: JSON.stringify(payloadData)
            });

            showToast(payload.message || 'Rendición enviada exitosamente a Tesorería', 'success');
            closeNewReportDrawer();
            await loadAllData();
            switchTab('informes');
        } catch (error) {
            showToast(error.message, 'error');
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Sí, Enviar Rendición';
        }
    }

    // ==========================================
    // 9. UTILIDADES Y HELPERS UI
    // ==========================================
    async function apiRequest(url, options = {}) {
        const headers = {
            Accept: 'application/json',
            'X-CSRF-Token': state.csrfToken,
            ...(options.headers || {})
        };
        if (options.method === 'POST') {
            headers['Content-Type'] = 'application/json';
        }

        let response;
        try {
            response = await fetch(url, { credentials: 'same-origin', ...options, headers });
        } catch (_) {
            throw new Error('No hay conexión con el servidor.');
        }

        let payload;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error('Respuesta inválida del servidor.');
        }

        if (!response.ok || !payload.success) {
            throw new Error(payload.message || 'No fue posible procesar la solicitud.');
        }
        return payload;
    }

    function showToast(message, type = 'success') {
        const container = $('#toastContainer');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.textContent = message;
        container.appendChild(toast);
        setTimeout(() => toast.remove(), 3200);
    }

    function showSessionError(msg) {
        $('#sessionMessage').textContent = msg;
        $('#sessionState').hidden = false;
        $('#workspace').hidden = true;
    }

    function formatMoney(amount) {
        return clpFormatter.format(Number(amount || 0));
    }

    function formatDateShort(dateStr) {
        if (!dateStr) return '';
        const [year, month, day] = dateStr.slice(0, 10).split('-');
        return `${day}/${month}/${year}`;
    }

    function formatPeriodMonth(periodStr) {
        if (!periodStr) return 'Sin período';
        const [year, month] = periodStr.split('-').map(Number);
        return new Intl.DateTimeFormat('es-CL', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
    }

    function humanize(str) {
        return String(str || '').toLowerCase().replaceAll('_', ' ').replace(/^./, (l) => l.toUpperCase());
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = String(value ?? '');
        return div.innerHTML;
    }

    function normalizePhotoUrl(value) {
        if (!value) return '';
        if (/^https?:\/\//i.test(value) || value.startsWith('/')) return value;
        return value.startsWith('uploads/') ? `../${value}` : value;
    }

    function getCategoryIconSvg(category) {
        // Iconos lineales SVG consistentes con la Suite
        const map = {
            BENCINA: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M3 22v-8a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v8"></path><path d="M14 13h2a2 2 0 0 1 2 2v7"></path><path d="M18 5a2 2 0 0 1 2 2v2"></path><path d="M3 6h8"></path><path d="M7 2v4"></path></svg>',
            PEAJES: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>',
            COLACION: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M18 8h1a4 4 0 0 1 0 8h-1"></path><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"></path><line x1="6" y1="1" x2="6" y2="4"></line><line x1="10" y1="1" x2="10" y2="4"></line><line x1="14" y1="1" x2="14" y2="4"></line></svg>',
            HOSPEDAJE: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M2 4v16"></path><path d="M2 8h18a2 2 0 0 1 2 2v10"></path><path d="M2 17h20"></path><path d="M6 8v9"></path></svg>',
            ESTACIONAMIENTO: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><rect x="4" y="4" width="16" height="16" rx="2"></rect><path d="M9 16V8h4a2 2 0 0 1 0 4H9"></path></svg>',
            CENA_CLIENTE: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M8 22h8"></path><path d="M12 11v11"></path><path d="M19 3l-7 8-7-8z"></path></svg>',
            OTROS: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:20px;height:20px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline></svg>'
        };
        return map[category] || map.OTROS;
    }

    function getReportStatusBadge(status) {
        const map = {
            BORRADOR: { label: 'Borrador', className: 'rg-status--draft' },
            PENDIENTE_APROBACION_EXCESO: { label: 'Exceso Pendiente', className: 'rg-status--sent' },
            EN_REVISION_TESORERIA: { label: 'En Revisión', className: 'rg-status--sent' },
            DOCUMENTOS_FISICOS_RECIBIDOS: { label: 'Físicos Recibidos', className: 'rg-status--sent' },
            APROBADA: { label: 'Aprobada', className: 'rg-status--approved' },
            APROBADA_PARCIAL: { label: 'Aprobada Parcial', className: 'rg-status--approved' },
            RECHAZADA: { label: 'Rechazada', className: 'rg-status--rejected' },
            PAGADA: { label: 'Pagada', className: 'rg-status--approved' }
        };
        return map[status] || { label: humanize(status), className: 'rg-status--draft' };
    }

})();
