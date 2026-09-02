(function () {
    'use strict';

    const API_BASE = 'api/rendiciones';
    const APPROVED_STATES = Object.freeze(['APROBADA', 'APROBADA_PARCIAL', 'PAGADA']);
    const canManage = document.body.dataset.canManageRendiciones === '1';
    const canConfigureApprovers = document.body.dataset.canConfigureApprovers === '1';
    const state = {
        renditions: [],
        selectedId: null,
        detail: null,
        detailCache: new Map(),
        budgets: [],
        budgetsLoaded: false,
        budgetsAvailable: false,
        budgetFilter: 'MENSUAL',
        sellerDirectory: [],
        sellerDirectoryLoaded: false,
        sellerOptions: [],
        sellerOptionIndex: -1,
        approvers: [],
        statusFilter: 'REVIEW',
        activeSubmodule: 'bandeja',
        action: null,
        dashboardKey: '',
        dashboardLoading: false,
        sellerAnalyticsKey: '',
        sellerAnalyticsLoading: false,
        sellerAnalytics: null,
        selectedAnalyticsSeller: null,
        approvalContext: null,
        lastFocused: new Map()
    };
    const $ = (selector) => document.querySelector(selector);
    const $$ = (selector) => [...document.querySelectorAll(selector)];
    const money = new Intl.NumberFormat('es-CL', { style: 'currency', currency: 'CLP', maximumFractionDigits: 0 });
    const dateFormatter = new Intl.DateTimeFormat('es-CL', { day: '2-digit', month: 'short', year: 'numeric' });
    const currentPeriod = new Date().toISOString().slice(0, 7);
    let sellerSearchTimer = null;

    document.addEventListener('DOMContentLoaded', initialize);

    function initialize() {
        if ($('#dashboardMonth')) $('#dashboardMonth').value = currentPeriod;
        if ($('#budgetPeriod')) $('#budgetPeriod').value = currentPeriod;
        bindEvents();
        window.registerSuiteRefresh?.(refreshActiveSubmodule);
        restoreSidebarState();
        const requestedSubmodule = window.location.hash.replace('#', '').toLowerCase();
        activateSubmodule(requestedSubmodule || 'bandeja', false);
        loadRenditions();
    }

    function bindEvents() {
        $('#toggleRendicionesSidebar')?.addEventListener('click', toggleSidebar);
        $$('[data-submodule-target]').forEach((button) => button.addEventListener('click', () => {
            const target = button.dataset.submoduleTarget;
            if (window.location.hash === `#${target}`) activateSubmodule(target, false);
            else window.location.hash = target;
        }));
        window.addEventListener('hashchange', () => activateSubmodule(window.location.hash.replace('#', ''), false));
        $('#renditionStateTabs')?.addEventListener('click', onStateFilterClick);
        ['#filterMonth', '#filterType', '#filterCompany'].forEach((selector) => $(selector)?.addEventListener('change', () => loadRenditions()));
        $('#filterSearch')?.addEventListener('input', renderRenditions);
        $('#refreshRenditions')?.addEventListener('click', () => loadRenditions(true));
        $('#renditionsTableBody')?.addEventListener('click', onRenditionClick);
        $('#renditionsTableBody')?.addEventListener('keydown', onRenditionKeydown);
        $('#detailContent')?.addEventListener('click', onDetailAction);
        $('#dashboardMonth')?.addEventListener('change', () => loadDashboard(true));
        $('#dashboardWindow')?.addEventListener('change', () => loadSellerAnalytics(true));
        $('#dashboardSellerSearch')?.addEventListener('input', renderSellerRanking);
        $('#dashboardSellerRows')?.addEventListener('click', onAnalyticsSellerSelect);
        $('#dashboardSellerRows')?.addEventListener('keydown', onAnalyticsSellerKeydown);

        if (canManage) {
            $('#openBudgetModal')?.addEventListener('click', openBudgetModal);
            $('#budgetType')?.addEventListener('change', syncTourFields);
            $('#budgetStartDate')?.addEventListener('change', syncTourPeriod);
            $('#budgetEndDate')?.addEventListener('change', validateTourDateRange);
            $('#budgetCompany')?.addEventListener('change', onBudgetCompanyChange);
            $('#budgetSellerSearch')?.addEventListener('input', onBudgetSellerSearch);
            $('#budgetSellerSearch')?.addEventListener('keydown', onBudgetSellerKeydown);
            $('#budgetSellerOptions')?.addEventListener('click', onBudgetSellerOptionClick);
            $('#budgetForm')?.addEventListener('submit', saveBudget);
            $('#clearBudgetForm')?.addEventListener('click', resetBudgetForm);
            $('#budgetSearch')?.addEventListener('input', renderBudgets);
            $('#budgetTypeTabs')?.addEventListener('click', onBudgetTypeFilterClick);
            $('#budgetTablesContainer')?.addEventListener('click', onBudgetAction);
            $('#budgetTableBody')?.addEventListener('click', onBudgetAction);
            $('#confirmActionButton')?.addEventListener('click', confirmAction);
            $('#partialDecisionList')?.addEventListener('change', updatePartialTotal);
            $('#partialDecisionList')?.addEventListener('input', updatePartialTotal);
            $('#savePartialButton')?.addEventListener('click', savePartialReview);
            $('#sendExcessApproval')?.addEventListener('click', sendExcessApproval);
            $('#openApproverConfig')?.addEventListener('click', openApproverConfig);
            $('#btnHeaderApprovers')?.addEventListener('click', openApproverConfig);
            $('#approverConfigForm')?.addEventListener('submit', saveApproverConfig);
            $('#editDocumentForm')?.addEventListener('submit', saveEditDocument);
            $$('[data-close-modal]').forEach((button) => button.addEventListener('click', () => closeModal(button.dataset.closeModal)));
            $$('.rd-modal').forEach((modal) => modal.addEventListener('click', (event) => {
                if (event.target === modal) closeModal(modal.id);
            }));
            document.addEventListener('click', (event) => {
                if (!event.target.closest('#budgetSellerField')) closeSellerOptions();
            });
        }

        document.addEventListener('keydown', (event) => {
            const openModalElement = $('.rd-modal:not([hidden])');
            if (event.key === 'Tab' && openModalElement) {
                trapModalFocus(event, openModalElement);
                return;
            }
            if (event.key !== 'Escape') return;
            if (openModalElement) closeModal(openModalElement.id);
            else if (state.selectedId) clearDetail();
        });
    }

    function activateSubmodule(requested, updateHash) {
        const available = $$('[data-submodule-panel]').map((panel) => panel.dataset.submodulePanel);
        const target = available.includes(requested) ? requested : 'bandeja';
        state.activeSubmodule = target;
        $$('[data-submodule-target]').forEach((button) => {
            const active = button.dataset.submoduleTarget === target;
            button.classList.toggle('is-active', active);
            if (active) button.setAttribute('aria-current', 'page');
            else button.removeAttribute('aria-current');
        });
        $$('[data-submodule-panel]').forEach((panel) => {
            const active = panel.dataset.submodulePanel === target;
            panel.hidden = !active;
            panel.classList.toggle('is-active', active);
        });
        if (updateHash && window.location.hash !== `#${target}`) window.location.hash = target;
        if (target === 'dashboard') loadDashboard();
        if (target === 'vendedores' && canManage) loadBudgets();
    }

    function onStateFilterClick(event) {
        const button = event.target.closest('[data-state-filter]');
        if (!button) return;
        state.statusFilter = button.dataset.stateFilter;
        $$('[data-state-filter]').forEach((tab) => {
            const active = tab === button;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
        renderRenditions();
    }

    async function loadRenditions(showNotice) {
        const refreshButton = $('#refreshRenditions');
        if (refreshButton) refreshButton.classList.add('is-loading');
        $('#renditionsTableBody').innerHTML = tableMessage('Cargando rendiciones…', 6);
        const query = new URLSearchParams({
            estado: 'TODOS',
            tipo: $('#filterType').value,
            mes: $('#filterMonth').value,
            empresa_id: $('#filterCompany').value,
            limite: '100'
        });
        try {
            const payload = await apiRequest(`${API_BASE}/get_rendiciones.php?${query.toString()}`);
            state.renditions = payload.data.rendiciones || [];
            if (state.selectedId && !state.renditions.some((item) => Number(item.id) === Number(state.selectedId))) clearDetail();
            renderStateCounters();
            renderRenditions();
            state.dashboardKey = '';
            if (showNotice) notify('Bandeja actualizada.', 'success');
        } catch (error) {
            state.renditions = [];
            renderStateCounters();
            $('#renditionsTableBody').innerHTML = tableMessage(`No fue posible cargar la bandeja. ${error.message}`, 6);
            $('#inboxSummary').textContent = 'Error de conexión';
            $('#inboxAmount').textContent = '$0 visible';
            notify(error.message, 'error');
        } finally {
            if (refreshButton) refreshButton.classList.remove('is-loading');
        }
    }

    function renderStateCounters() {
        const rows = state.renditions;
        setText('countReview', rows.filter((item) => matchesStatusFilter(item.estado, 'REVIEW')).length);
        setText('countApproved', rows.filter((item) => APPROVED_STATES.includes(item.estado)).length);
        setText('countRejected', rows.filter((item) => item.estado === 'RECHAZADA').length);
        setText('countAll', rows.length);
    }

    function filteredRenditions() {
        const search = normalize($('#filterSearch').value);
        return state.renditions.filter((item) => {
            if (!matchesStatusFilter(item.estado, state.statusFilter)) return false;
            if (!search) return true;
            const cached = state.detailCache.get(Number(item.id));
            const documentRuts = cached?.documentos?.map((documentData) => documentData.rut_proveedor).join(' ') || '';
            return normalize(`${item.codigo_rendicion} ${item.vendedor_nombre} ${item.vendedor_id} ${item.vendedor_email || ''} ${item.empresa_nombre} ${documentRuts}`).includes(search);
        });
    }

    function matchesStatusFilter(status, filter) {
        const map = {
            REVIEW: ['EN_REVISION_TESORERIA', 'PENDIENTE_APROBACION_EXCESO', 'PENDIENTE_APROBACION_RESPONSABLE', 'DOCUMENTOS_FISICOS_RECIBIDOS'],
            APPROVED: APPROVED_STATES,
            REJECTED: ['RECHAZADA'],
            ALL: null
        };
        return !map[filter] || map[filter].includes(status);
    }

    function renderRenditions() {
        const rows = filteredRenditions();
        const visibleAmount = rows.reduce((sum, item) => sum + Number(item.monto_total_rendido || 0), 0);
        $('#inboxSummary').textContent = rows.length === 1 ? '1 rendición visible' : `${rows.length} rendiciones visibles`;
        $('#inboxAmount').textContent = `${money.format(visibleAmount)} visible`;
        if (!rows.length) {
            $('#renditionsTableBody').innerHTML = tableMessage('No hay rendiciones que coincidan con los filtros actuales.', 6);
            return;
        }

        $('#renditionsTableBody').innerHTML = rows.map((item) => {
            const status = statusInfo(item.estado);
            const selected = Number(state.selectedId) === Number(item.id);
            const typeLabel = item.tipo_rendicion === 'GIRA' && item.nombre_gira ? item.nombre_gira : humanize(item.tipo_rendicion);
            return `<tr data-rendition-id="${Number(item.id)}" tabindex="0" class="${selected ? 'is-active' : ''}" aria-selected="${selected ? 'true' : 'false'}">
                <td><span class="rd-cell-primary">${escapeHtml(item.empresa_nombre || 'Sin empresa')}</span></td>
                <td><span class="rd-cell-primary">${escapeHtml(item.vendedor_nombre || 'Sin nombre')}</span><span class="rd-cell-secondary">Código ERP #${escapeHtml(item.vendedor_id)}</span></td>
                <td><span class="rd-cell-primary">${escapeHtml(typeLabel)}</span><span class="rd-cell-secondary">${formatMonthShort(item.periodo_mes)}</span></td>
                <td><span class="rd-cell-money">${money.format(item.monto_total_rendido)}</span><span class="rd-cell-secondary">${Number(item.cantidad_documentos)} boleta(s)</span>${Number(item.monto_exceso) > 0 ? `<span class="rd-excess-badge"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M10.3 3.7 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/></svg>Con exceso: +${money.format(item.monto_exceso)}</span>` : ''}</td>
                <td><span class="rd-status ${status.className}">${escapeHtml(status.label)}</span></td>
                <td><span class="rd-cell-primary">${formatRelativeTime(item.enviada_at)}</span></td>
            </tr>`;
        }).join('');
    }

    function onRenditionClick(event) {
        const row = event.target.closest('[data-rendition-id]');
        if (row) selectRendition(Number(row.dataset.renditionId));
    }

    function onRenditionKeydown(event) {
        const row = event.target.closest('[data-rendition-id]');
        if (!row || !['Enter', ' '].includes(event.key)) return;
        event.preventDefault();
        selectRendition(Number(row.dataset.renditionId));
    }

    function restoreSidebarState() {
        try {
            const collapsed = window.localStorage.getItem('rendicionesSidebarCollapsed') === '1';
            setSidebarCollapsed(collapsed);
        } catch (_) {
            setSidebarCollapsed(false);
        }
    }

    function toggleSidebar() {
        const shell = $('.rd-shell');
        if (!shell) return;
        const collapsed = !shell.classList.contains('is-sidebar-collapsed');
        setSidebarCollapsed(collapsed);
        try { window.localStorage.setItem('rendicionesSidebarCollapsed', collapsed ? '1' : '0'); } catch (_) { /* El control sigue funcionando aunque el navegador bloquee almacenamiento. */ }
    }

    function setSidebarCollapsed(collapsed) {
        const shell = $('.rd-shell');
        const toggle = $('#toggleRendicionesSidebar');
        if (!shell || !toggle) return;
        shell.classList.toggle('is-sidebar-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Expandir submódulos' : 'Contraer submódulos');
        toggle.setAttribute('title', collapsed ? 'Expandir submódulos' : 'Contraer submódulos');
    }

    async function selectRendition(id, force) {
        state.selectedId = id;
        renderRenditions();
        $('#detailEmpty').hidden = true;
        $('#detailContent').hidden = false;
        $('#detailContent').innerHTML = detailLoadingMarkup();
        try {
            let detail = !force ? state.detailCache.get(id) : null;
            if (!detail) {
                const payload = await apiRequest(`${API_BASE}/get_detalle_rendicion.php?id=${id}`);
                detail = payload.data;
                state.detailCache.set(id, detail);
            }
            if (Number(state.selectedId) !== id) return;
            state.detail = detail;
            renderDetail();
        } catch (error) {
            $('#detailContent').innerHTML = `<div class="rd-detail-empty"><h2>No fue posible abrir la rendición</h2><p>${escapeHtml(error.message)}</p></div>`;
            notify(error.message, 'error');
        }
    }

    const loadDetail = selectRendition;

    function renderDetail() {
        const data = state.detail;
        const rendition = data.rendicion;
        const documents = data.documentos || [];
        const history = data.historial || [];
        const status = statusInfo(rendition.estado);
        const assigned = Number(rendition.monto_presupuesto_asignado || 0);
        const rendered = Number(rendition.monto_total_rendido || 0);
        const resultingBalance = assigned - rendered;
        const excess = Number(rendition.monto_exceso || 0);
        const excessFund = rendition.tipo_rendicion === 'GIRA'
            ? `la gira “${rendition.nombre_gira || 'Gira comercial'}”`
            : 'el presupuesto mensual';
        const excessNotification = rendition.notificacion_exceso_estado === 'ENVIADA'
            ? 'Notificación enviada a Gerencia / Administración.'
            : rendition.notificacion_exceso_estado === 'FALLIDA'
                ? 'La notificación requiere reenvío.'
                : 'Aprobación pendiente de notificación.';
        const excessApproved = rendition.decision_exceso === 'APROBADO' && rendition.aprobado_exceso_at;
        const approvalProof = excessApproved ? `<aside class="rd-approval-proof">
            <span class="rd-approval-proof__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span>
            <div class="rd-approval-proof__content"><strong>Aprobación gerencial registrada</strong><span>${escapeHtml(rendition.aprobador_nombre_snapshot || rendition.aprobado_exceso_por || 'Responsable')} · ${escapeHtml(rendition.aprobador_cargo_snapshot || 'Cargo no informado')} · ${escapeHtml(formatDateTime(rendition.aprobado_exceso_at))}</span><small>El exceso fue autorizado; la rendición continúa su revisión en Tesorería.</small></div>
            <a class="rd-btn rd-btn--success rd-approval-proof__button" href="reportes/comprobante_aprobacion_exceso.php?id=${Number(rendition.id)}" target="_blank" rel="noopener noreferrer"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6V2Zm8 0v6h6M9 13h8m-8 4h5"/></svg>Ver / imprimir PDF</a>
        </aside>` : '';
        $('#detailContent').innerHTML = `
            <header class="rd-detail-bar">
                <div class="rd-detail-title"><div class="rd-detail-title__line"><h2>Rendición ${escapeHtml(rendition.codigo_rendicion)}</h2><span class="rd-status ${status.className}">${escapeHtml(status.label)}</span></div><p>${documents.length} comprobante(s) · ${escapeHtml(rendition.vendedor_nombre || 'Vendedor sin nombre')}</p></div>
                <button class="rd-detail-close" type="button" data-close-detail aria-label="Cerrar detalle"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="m6 6 12 12M18 6 6 18"/></svg></button>
            </header>
            <div class="rd-detail-scroll"><div class="rd-detail-stack">
                ${excess > 0 && !excessApproved ? `<aside class="rd-excess-alert"><div class="rd-excess-alert__title"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M10.3 3.7 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.7a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/></svg>Alerta de exceso de presupuesto (+${money.format(excess)})</div><p>El exceso pertenece a ${escapeHtml(excessFund)}. El total rendido (${money.format(rendered)}) supera su saldo disponible. ${escapeHtml(excessNotification)}</p></aside>` : ''}
                ${approvalProof}
                <section class="rd-detail-section"><div class="rd-detail-section__heading"><h3>Resumen de la rendición</h3><span>${formatMonth(rendition.periodo_mes)}</span></div><div class="rd-meta-grid">
                    ${metaMarkup('Vendedor', `${rendition.vendedor_nombre || 'Sin nombre'} · #${rendition.vendedor_id}`)}
                    ${metaMarkup('Empresa', rendition.empresa_nombre || 'Sin empresa')}
                    ${metaMarkup('Presupuesto asignado', money.format(assigned))}
                    ${metaMarkup('Total rendido', money.format(rendered))}
                    ${metaMarkup('Saldo resultante', money.format(resultingBalance))}
                    ${metaMarkup('Tipo', rendition.tipo_rendicion === 'GIRA' ? `Gira · ${rendition.nombre_gira || 'Sin nombre'}` : 'Mensual')}
                </div></section>
                ${rendition.nota_vendedor ? `<section class="rd-detail-section"><div class="rd-detail-section__heading"><h3>Nota del vendedor para Tesorería</h3></div><p class="rd-vendor-note">${escapeHtml(rendition.nota_vendedor)}</p></section>` : ''}
                <section class="rd-detail-section"><div class="rd-detail-section__heading"><h3>Comprobantes y boletas</h3><span>${documents.length} documento(s)</span></div><div class="rd-document-list">${documents.length ? documents.map(documentMarkup).join('') : '<div class="rd-readonly-note">No hay comprobantes activos.</div>'}</div></section>
                <section class="rd-detail-section"><div class="rd-detail-section__heading"><h3>Trazabilidad de auditoría</h3><span>${history.length} movimiento(s)</span></div><div class="rd-audit-list">${history.length ? history.map(historyMarkup).join('') : '<div class="rd-readonly-note">Aún no hay movimientos registrados.</div>'}</div></section>
            </div></div>
            <footer class="rd-detail-footer">${renderStepper(rendition.estado)}${actionsMarkup(rendition)}</footer>`;
    }

    function metaMarkup(label, value) {
        return `<div class="rd-meta-item"><span>${escapeHtml(label)}</span><strong title="${escapeHtml(value)}">${escapeHtml(value)}</strong></div>`;
    }

    function documentMarkup(documentData) {
        const photoUrl = normalizePhotoUrl(documentData.foto_documento_url);
        const category = categoryInfo(documentData.categoria_gasto);
        const provider = documentData.razon_social_proveedor || humanize(documentData.tipo_documento);
        const siiMarkup = documentData.categoria_gasto === 'CENA_CLIENTE' ? `<div class="rd-sii-box">
            <div><span>Invitado</span><strong>${escapeHtml(documentData.cliente_invitado_nombre || 'Sin dato')}</strong></div>
            <div><span>RUT invitado</span><strong>${escapeHtml(documentData.cliente_invitado_rut || 'Sin dato')}</strong></div>
            <div><span>Empresa / Cargo</span><strong>${escapeHtml(`${documentData.cliente_invitado_empresa || 'Sin dato'} · ${documentData.cliente_invitado_cargo || 'Sin cargo'}`)}</strong></div>
            <div class="rd-sii-box__purpose"><span>Propósito comercial</span><strong>${escapeHtml(documentData.proposito_comercial || 'Sin dato')}</strong></div>
        </div>` : '';

        const currentRenditionState = state.detail?.rendicion?.estado || '';
        const canEditDoc = canManage && ['EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'].includes(currentRenditionState);
        const isEdited = documentData.monto_original !== null && Number(documentData.monto_original) !== Number(documentData.monto);

        const editBtn = canEditDoc ? `<button class="rd-btn-edit-amount" type="button" data-edit-doc-id="${documentData.id}" data-doc-provider="${escapeHtml(provider)}" data-doc-amount="${documentData.monto}" data-doc-number="${escapeHtml(documentData.numero_documento || '')}" title="Corregir datos del comprobante">
            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg> Editar
        </button>` : '';

        const origBadge = isEdited ? `<span class="rd-orig-badge" title="Digitado originalmente: ${money.format(documentData.monto_original)} · Corregido por Tesorería">Digitado: ${money.format(documentData.monto_original)}</span>` : '';

        return `<article class="rd-document">
            <span class="rd-document__icon" aria-hidden="true">${category.icon}</span>
            <div class="rd-document__main">
                <div class="rd-document__topline"><strong>${escapeHtml(provider)}</strong><span class="rd-document__category">${escapeHtml(category.label)}</span></div>
                <p class="rd-document__meta">${escapeHtml(humanize(documentData.tipo_documento))} · RUT ${escapeHtml(documentData.rut_proveedor || 'sin dato')} · Folio ${escapeHtml(documentData.numero_documento || 's/n')} · ${formatDate(documentData.fecha_emision)}</p>
                ${photoUrl ? `<button class="rd-document__photo" type="button" data-photo-url="${escapeHtml(photoUrl)}"><svg aria-hidden="true" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg>Abrir comprobante</button>` : ''}
            </div>
            <div class="rd-document__amount-col">
                <strong class="rd-document__amount">${money.format(documentData.monto)}</strong>
                ${origBadge}
                ${editBtn}
            </div>
            ${siiMarkup}
        </article>`;
    }

    function categoryInfo(category) {
        const iconMap = {
            BENCINA: '<svg viewBox="0 0 24 24"><path d="M3 22V4a2 2 0 0 1 2-2h8a2 2 0 0 1 2 2v18M3 10h12M7 6h4"/><path d="M15 7h2l3 3v8a2 2 0 0 1-4 0v-3"/></svg>',
            PEAJES: '<svg viewBox="0 0 24 24"><path d="M4 21 9 3m6 0 5 18M12 3v4m0 4v4m0 4v2"/></svg>',
            HOSPEDAJE: '<svg viewBox="0 0 24 24"><path d="M3 21V3h18v18M7 7h2m6 0h2M7 12h2m6 0h2M9 21v-4h6v4"/></svg>',
            COLACION: '<svg viewBox="0 0 24 24"><path d="M4 3v7a3 3 0 0 0 3 3V3m0 10v8M14 3v18m0-11c4 0 6-2 6-7v18"/></svg>',
            CENA_CLIENTE: '<svg viewBox="0 0 24 24"><path d="M8 3h8l-1 7a3 3 0 0 1-6 0L8 3Zm4 10v8m-4 0h8"/></svg>',
            ESTACIONAMIENTO: '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9 17V7h4a3 3 0 0 1 0 6H9"/></svg>',
            OTROS: '<svg viewBox="0 0 24 24"><path d="M6 2h9l5 5v15H6V2Zm8 0v6h6M9 13h8m-8 4h5"/></svg>'
        };
        const labels = { BENCINA: 'Bencina', PEAJES: 'Peaje', HOSPEDAJE: 'Hospedaje', COLACION: 'Colación', CENA_CLIENTE: 'Cena cliente', ESTACIONAMIENTO: 'Estacionamiento', OTROS: 'Otros' };
        return { label: labels[category] || humanize(category), icon: iconMap[category] || iconMap.OTROS };
    }

    function historyMarkup(entry) {
        return `<div class="rd-audit-item"><strong>${escapeHtml(humanize(entry.accion))}</strong> · ${escapeHtml(entry.actor_nombre || entry.actor_tipo)}<br>${formatDateTime(entry.created_at)}${entry.comentario ? ` · ${escapeHtml(entry.comentario)}` : ''}</div>`;
    }

    function renderStepper(status) {
        const steps = ['Enviada', 'En revisión', 'Aprobada', 'Pagada'];
        const indexMap = {
            ENVIADA: 0,
            PENDIENTE_APROBACION_EXCESO: 1,
            PENDIENTE_APROBACION_RESPONSABLE: 1,
            EN_REVISION_TESORERIA: 1,
            DOCUMENTOS_FISICOS_RECIBIDOS: 1,
            APROBADA: 2,
            APROBADA_PARCIAL: 2,
            PAGADA: 3
        };
        const current = indexMap[status] ?? 0;
        const rejected = status === 'RECHAZADA';
        return `<div class="rd-stepper" aria-label="Progreso de la rendición">${steps.map((label, index) => {
            const complete = !rejected && index < current;
            const active = !rejected && index === current;
            const isRejected = rejected && index === 3;
            const className = complete ? 'is-complete' : active ? 'is-active' : isRejected ? 'is-rejected' : '';
            const node = complete ? '✓' : isRejected ? '×' : active ? '•' : String(index + 1);
            return `<div class="rd-step ${className}"><span class="rd-step__node">${node}</span><span class="rd-step__label">${isRejected ? 'Rechazada' : label}</span>${index < steps.length - 1 ? '<span class="rd-step__line"></span>' : ''}</div>`;
        }).join('')}</div>`;
    }

    function actionsMarkup(rendition) {
        const status = rendition.estado;
        if (!canManage) return '<div class="rd-readonly-note">Tu rol permite consultar la rendición sin ejecutar transiciones.</div>';
        const buttons = [];
        if (status === 'PENDIENTE_APROBACION_EXCESO') {
            const sent = rendition.notificacion_exceso_estado === 'ENVIADA';
            buttons.push(actionButton('REENVIAR_EXCESO', sent ? 'Reenviar aprobación' : 'Enviar aprobación', 'rd-btn--warning'));
            buttons.push(actionButton('RECHAZAR_EXCESO_TESORERIA', sent ? 'Cancelar solicitud y rechazar' : 'Rechazar sin enviar', 'rd-btn--danger'));
        }
        if (status === 'PENDIENTE_APROBACION_RESPONSABLE') {
            const approverName = rendition.aprobador_nombre_snapshot || 'Responsable';
            buttons.push(`<div class="rd-approval-waiting-note">
                <strong>⏳ En espera de aprobación gerencial</strong><br>
                Solicitud enviada a <strong>${escapeHtml(approverName)}</strong>. Una vez autorizada con su Magic Link, se emitirá automáticamente la Planilla Oficial en PDF y pasará a Tesorería para pago.
            </div>`);
            buttons.push(actionButton('REENVIAR_RESPONSABLE', 'Reenviar a Responsable', 'rd-btn--warning'));
            buttons.push(actionButton('CANCELAR_SOLICITUD_RESPONSABLE', 'Cancelar solicitud y reabrir revisión', 'rd-btn--danger'));
        }
        if (['EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'].includes(status)) {
            buttons.push(actionButton('VERIFICAR_Y_ENVIAR', '✓ Verificar y Enviar a Responsable', 'rd-btn--success'));
            buttons.push('<button class="rd-btn rd-btn--warning" type="button" data-open-partial>Aprobación parcial de boletas</button>');
            buttons.push(actionButton('RECHAZAR', 'Rechazar rendición', 'rd-btn--danger'));
        }
        if (['APROBADA', 'APROBADA_PARCIAL'].includes(status)) {
            buttons.push(`<a class="rd-btn rd-btn--primary" href="api/rendiciones/descargar_planilla.php?id=${rendition.id}" target="_blank" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                Descargar Planilla PDF (Excel)
            </a>`);
            buttons.push(actionButton('MARCAR_PAGADA', '💰 Marcar como pagada', 'rd-btn--success'));
        }
        if (status === 'PAGADA') {
            buttons.push(`<a class="rd-btn rd-btn--secondary" href="api/rendiciones/descargar_planilla.php?id=${rendition.id}" target="_blank" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                Descargar Planilla PDF (Excel)
            </a>`);
        }
        return buttons.length ? `<div class="rd-actions">${buttons.join('')}</div>` : '<div class="rd-readonly-note">Esta rendición no tiene acciones pendientes.</div>';
    }

    function actionButton(action, label, className) {
        return `<button class="rd-btn ${className}" type="button" data-rendition-action="${action}">${label}</button>`;
    }

    function onDetailAction(event) {
        if (event.target.closest('[data-close-detail]')) { clearDetail(); return; }
        const photo = event.target.closest('[data-photo-url]');
        if (photo) { window.abrirImagenLightbox(photo.dataset.photoUrl); return; }
        const editBtn = event.target.closest('[data-edit-doc-id]');
        if (editBtn) {
            openEditDocumentModal(editBtn.dataset.editDocId, editBtn.dataset.docProvider, editBtn.dataset.docAmount, editBtn.dataset.docNumber);
            return;
        }
        const actionButtonElement = event.target.closest('[data-rendition-action]');
        if (actionButtonElement) { openActionModal(actionButtonElement.dataset.renditionAction); return; }
        if (event.target.closest('[data-open-partial]')) openPartialModal();
    }

    function clearDetail() {
        state.selectedId = null;
        state.detail = null;
        $('#detailContent').hidden = true;
        $('#detailContent').innerHTML = '';
        $('#detailEmpty').hidden = false;
        renderRenditions();
    }

    async function loadDashboard(force) {
        if (state.dashboardLoading) return;
        const period = $('#dashboardMonth')?.value || currentPeriod;
        const key = period;
        if (!force && state.dashboardKey === key) return;
        state.dashboardLoading = true;
        $('#dashboardStatus').textContent = 'Consolidando rendiciones y comprobantes…';
        $('#dashboardCategories').innerHTML = '<div class="rd-bar-empty">Calculando categorías…</div>';
        $('#dashboardCompanies').innerHTML = '<div class="rd-bar-empty">Calculando empresas…</div>';
        try {
            const [renditions] = await Promise.all([
                loadDashboardRenditions(period),
                canManage && (force || !state.budgetsLoaded) ? loadBudgets(true) : Promise.resolve()
            ]);
            const approvedRenditions = renditions.filter((item) => APPROVED_STATES.includes(item.estado));
            const details = await loadDetailsWithConcurrency(approvedRenditions, 5);
            renderDashboard(approvedRenditions, details, period);
            state.dashboardKey = key;
            $('#dashboardStatus').textContent = details.length < approvedRenditions.length ? 'Algunos comprobantes aprobados no pudieron incorporarse al desglose.' : `Actualizado con ${approvedRenditions.length} rendición(es) aprobada(s).`;
        } catch (error) {
            $('#dashboardStatus').textContent = `No fue posible consolidar el período. ${error.message}`;
            $('#dashboardCategories').innerHTML = '<div class="rd-bar-empty">Sin información disponible.</div>';
            $('#dashboardCompanies').innerHTML = '<div class="rd-bar-empty">Sin información disponible.</div>';
            notify(error.message, 'error');
        } finally {
            state.dashboardLoading = false;
        }
        await loadSellerAnalytics(force);
    }

    async function loadSellerAnalytics(force) {
        if (state.sellerAnalyticsLoading) return;
        const period = $('#dashboardMonth')?.value || currentPeriod;
        const windowSize = Number($('#dashboardWindow')?.value || 6);
        const key = `${period}|${windowSize}`;
        if (!force && state.sellerAnalyticsKey === key) return;
        state.sellerAnalyticsLoading = true;
        setText('sellerAnalyticsStatus', 'Consolidando historial de presupuestos y rendiciones…');
        $('#dashboardSellerRows').innerHTML = '<tr><td colspan="6" class="rd-table-message">Cargando vendedores…</td></tr>';
        try {
            const query = new URLSearchParams({ mes: period, ventana: String(windowSize) });
            const payload = await apiRequest(`${API_BASE}/get_dashboard_analitico.php?${query.toString()}`);
            state.sellerAnalytics = payload.data;
            state.sellerAnalyticsKey = key;
            const sellers = payload.data.vendedores || [];
            if (!sellers.some((seller) => seller.clave === state.selectedAnalyticsSeller)) {
                state.selectedAnalyticsSeller = sellers[0]?.clave || null;
            }
            renderSellerAnalytics();
        } catch (error) {
            state.sellerAnalytics = null;
            state.selectedAnalyticsSeller = null;
            setText('sellerAnalyticsStatus', `No fue posible cargar el análisis. ${error.message}`);
            $('#dashboardSellerRows').innerHTML = '<tr><td colspan="6" class="rd-table-message">Sin información histórica disponible.</td></tr>';
            $('#dashboardSellerDetail').innerHTML = '<div class="rd-seller-detail__empty"><h3>Análisis no disponible</h3><p>Actualiza nuevamente o revisa la conexión con la base de datos.</p></div>';
            $('#dashboardBusinessSignals').innerHTML = '<div class="rd-bar-empty">No fue posible calcular señales.</div>';
            $('#dashboardApprovalTypes').innerHTML = '<div class="rd-bar-empty">No fue posible calcular aprobaciones.</div>';
        } finally {
            state.sellerAnalyticsLoading = false;
        }
    }

    function renderSellerAnalytics() {
        const analytics = state.sellerAnalytics;
        if (!analytics) return;
        const summary = analytics.resumen || {};
        const metrics = $$('#dashboardDecisionStrip .rd-decision-metric');
        if (metrics[0]) metrics[0].querySelector('strong').textContent = money.format(Number(summary.saldo_no_ejecutado || 0));
        if (metrics[1]) metrics[1].querySelector('strong').textContent = money.format(Number(summary.pendiente_total || 0));
        if (metrics[2]) metrics[2].querySelector('strong').textContent = `${formatPercent(summary.concentracion_principal_pct)}%`;
        setText('sellerAnalyticsStatus', `${summary.vendedores_analizados || 0} vendedor(es) · ${formatMonthShort(analytics.periodo_inicio)} a ${formatMonthShort(analytics.periodo_fin)}`);
        renderFundTypeComparison();
        renderApprovalWorkflow();
        renderSellerRanking();
        renderAnalyticsSellerDetail();
        renderBusinessSignals();
    }

    function renderFundTypeComparison() {
        const container = $('#dashboardFundTypes');
        if (!container || !state.sellerAnalytics) return;
        const rows = state.sellerAnalytics.fondos_por_tipo || [];
        container.innerHTML = rows.map((fund) => {
            const isTour = fund.tipo === 'GIRA';
            const execution = Math.max(0, Number(fund.ejecucion_pct || 0));
            return `<article class="rd-fund-row ${isTour ? 'rd-fund-row--tour' : ''}">
                <div class="rd-fund-row__type"><span>${isTour ? 'Giras comerciales' : 'Presupuestos mensuales'}</span><small>${fund.fondos_activos || 0} fondo(s) · promedio ${money.format(Number(fund.promedio_fondo || 0))}</small></div>
                <dl><div><dt>Asignado</dt><dd>${money.format(Number(fund.presupuesto || 0))}</dd></div><div><dt>Aprobado</dt><dd>${money.format(Number(fund.aprobado || 0))}</dd></div><div><dt>Pendiente</dt><dd>${money.format(Number(fund.pendiente || 0))}</dd></div><div><dt>Excesos</dt><dd>${fund.excesos || 0}</dd></div></dl>
                <div class="rd-fund-row__execution"><span><strong>${formatPercent(execution)}%</strong> ejecutado</span><i><b style="width:${Math.min(execution, 100)}%"></b></i></div>
            </article>`;
        }).join('') || '<div class="rd-bar-empty">No hay fondos en el horizonte seleccionado.</div>';
    }

    function renderApprovalWorkflow() {
        const approval = state.sellerAnalytics?.aprobaciones || {};
        const summary = approval.resumen || {};
        const metrics = $$('#dashboardApprovalMetrics > div');
        const responseHours = Number(summary.horas_respuesta_promedio || 0);
        const resolved = Number(summary.aprobadas || 0) + Number(summary.rechazadas || 0);
        setText('dashboardApprovalTotal', `${Number(summary.solicitudes_total || 0)} solicitud(es)`);
        if (metrics[0]) metrics[0].querySelector('strong').textContent = String(Number(summary.pendientes || 0));
        if (metrics[1]) metrics[1].querySelector('strong').textContent = String(Number(summary.correos_fallidos || 0));
        if (metrics[2]) metrics[2].querySelector('strong').textContent = responseHours > 0 ? `${formatPercent(responseHours)} h` : '—';
        if (metrics[3]) metrics[3].querySelector('strong').textContent = resolved > 0 ? `${formatPercent(summary.tasa_aprobacion_pct)}%` : '—';
        const container = $('#dashboardApprovalTypes');
        if (!container) return;
        const labels = { GIRA: 'Fondos de gira', EXCEPCION_MENSUAL: 'Excepciones mensuales' };
        const rows = approval.por_tipo || [];
        container.innerHTML = rows.map((row) => {
            const oldest = Number(row.horas_pendiente_mas_antigua || 0);
            const attention = Number(row.correos_fallidos || 0) > 0 || oldest >= 48;
            return `<article class="rd-approval-type ${attention ? 'needs-attention' : ''}">
                <div><strong>${escapeHtml(labels[row.tipo] || humanize(row.tipo))}</strong><small>${row.solicitudes_total || 0} solicitud(es) en el horizonte</small></div>
                <dl><div><dt>Pendientes</dt><dd>${row.pendientes || 0}</dd></div><div><dt>Aprobadas</dt><dd>${row.aprobadas || 0}</dd></div><div><dt>Rechazadas</dt><dd>${row.rechazadas || 0}</dd></div><div><dt>Fallidas</dt><dd>${row.correos_fallidos || 0}</dd></div></dl>
                <span>${oldest > 0 ? `Más antigua: ${oldest} h` : 'Sin espera activa'}</span>
            </article>`;
        }).join('') || '<div class="rd-bar-empty">No existen solicitudes de aprobación en este horizonte.</div>';
    }

    function renderSellerRanking() {
        const tbody = $('#dashboardSellerRows');
        if (!tbody || !state.sellerAnalytics) return;
        const search = ($('#dashboardSellerSearch')?.value || '').trim().toLocaleLowerCase('es');
        const sellers = (state.sellerAnalytics.vendedores || []).filter((seller) => {
            const haystack = `${seller.vendedor_nombre} ${seller.empresa_nombre} ${seller.vendedor_id}`.toLocaleLowerCase('es');
            return !search || haystack.includes(search);
        });
        if (!sellers.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="rd-table-message">${search ? 'No hay vendedores que coincidan con la búsqueda.' : 'No existen presupuestos ni rendiciones en este horizonte.'}</td></tr>`;
            return;
        }
        tbody.innerHTML = sellers.map((seller) => {
            const execution = Math.max(0, Number(seller.ejecucion_pct || 0));
            const executionWidth = Math.min(execution, 100);
            const active = seller.clave === state.selectedAnalyticsSeller;
            const frictionParts = [];
            if (Number(seller.casos_exceso || 0)) frictionParts.push(`${seller.casos_exceso} exceso(s)`);
            if (Number(seller.rendiciones_rechazadas || 0)) frictionParts.push(`${seller.rendiciones_rechazadas} rechazo(s)`);
            return `<tr class="${active ? 'is-active' : ''}" tabindex="0" role="button" aria-pressed="${active}" data-analytics-seller="${escapeHtml(seller.clave)}">
                <td><strong>${escapeHtml(seller.vendedor_nombre)}</strong><small>${escapeHtml(seller.empresa_nombre)} · ERP #${seller.vendedor_id}</small></td>
                <td class="rd-number">${money.format(Number(seller.presupuesto_total || 0))}</td>
                <td class="rd-number rd-number--approved">${money.format(Number(seller.aprobado_total || 0))}<small>${seller.rendiciones_aprobadas || 0} aprobada(s)</small></td>
                <td><div class="rd-execution-cell"><strong>${formatPercent(execution)}%</strong><span><i style="width:${executionWidth}%"></i></span></div></td>
                <td class="rd-number rd-number--pending">${money.format(Number(seller.pendiente_total || 0))}<small>${seller.rendiciones_pendientes || 0} por resolver</small></td>
                <td>${frictionParts.length ? `<span class="rd-friction">${escapeHtml(frictionParts.join(' · '))}</span>` : '<span class="rd-friction rd-friction--clear">Sin alertas</span>'}</td>
            </tr>`;
        }).join('');
    }

    function onAnalyticsSellerSelect(event) {
        const row = event.target.closest('[data-analytics-seller]');
        if (!row) return;
        selectAnalyticsSeller(row.dataset.analyticsSeller);
    }

    function onAnalyticsSellerKeydown(event) {
        if (!['Enter', ' '].includes(event.key)) return;
        const row = event.target.closest('[data-analytics-seller]');
        if (!row) return;
        event.preventDefault();
        selectAnalyticsSeller(row.dataset.analyticsSeller);
    }

    function selectAnalyticsSeller(key) {
        state.selectedAnalyticsSeller = key;
        renderSellerRanking();
        renderAnalyticsSellerDetail();
    }

    function renderAnalyticsSellerDetail() {
        const container = $('#dashboardSellerDetail');
        if (!container || !state.sellerAnalytics) return;
        const seller = (state.sellerAnalytics.vendedores || []).find((item) => item.clave === state.selectedAnalyticsSeller);
        if (!seller) {
            container.innerHTML = '<div class="rd-seller-detail__empty"><h3>Selecciona un vendedor</h3><p>Verás su trayectoria mensual, presupuesto asignado, gasto aprobado y montos todavía pendientes.</p></div>';
            return;
        }
        const trend = seller.tendencia || [];
        const maximum = Math.max(1, ...trend.map((month) => Math.max(Number(month.presupuesto || 0), Number(month.aprobado || 0) + Number(month.pendiente || 0))));
        const rows = trend.map((month) => {
            const approvedWidth = Math.min(100, (Number(month.aprobado || 0) / maximum) * 100);
            const pendingWidth = Math.min(100 - approvedWidth, (Number(month.pendiente || 0) / maximum) * 100);
            const budgetPosition = Math.min(100, (Number(month.presupuesto || 0) / maximum) * 100);
            return `<div class="rd-trend-row">
                <span>${escapeHtml(formatMonthShort(month.periodo))}</span>
                <div class="rd-trend-row__visual"><div class="rd-trend-track" aria-label="${money.format(Number(month.aprobado || 0))} aprobado, ${money.format(Number(month.pendiente || 0))} pendiente, ${money.format(Number(month.presupuesto || 0))} asignado"><i class="rd-trend-approved" style="width:${approvedWidth}%"></i><i class="rd-trend-pending" style="left:${approvedWidth}%;width:${pendingWidth}%"></i><b style="left:${budgetPosition}%" title="Tope presupuestario"></b></div><small>${money.format(Number(month.aprobado || 0))}</small></div>
            </div>`;
        }).join('');
        container.innerHTML = `<header class="rd-seller-detail__header"><div><h3>${escapeHtml(seller.vendedor_nombre)}</h3><p>${escapeHtml(seller.empresa_nombre)} · Código ERP #${seller.vendedor_id}</p></div><strong>${formatPercent(seller.ejecucion_pct)}% ejecutado</strong></header>
            <div class="rd-seller-detail__metrics"><div><span>Asignado</span><strong>${money.format(Number(seller.presupuesto_total || 0))}</strong></div><div><span>Aprobado real</span><strong>${money.format(Number(seller.aprobado_total || 0))}</strong></div><div><span>Pendiente</span><strong>${money.format(Number(seller.pendiente_total || 0))}</strong></div><div><span>Ticket promedio</span><strong>${money.format(Number(seller.ticket_promedio || 0))}</strong></div></div>
            <div class="rd-trend-legend"><span><i class="is-approved"></i>Aprobado</span><span><i class="is-pending"></i>Pendiente</span><span><i class="is-budget"></i>Tope asignado</span></div>
            <div class="rd-trend-list">${rows || '<div class="rd-bar-empty">Sin movimientos en el horizonte.</div>'}</div>
            <p class="rd-seller-detail__foot">Último movimiento: ${escapeHtml(formatDateTime(seller.ultimo_movimiento))}</p>`;
    }

    function renderBusinessSignals() {
        const container = $('#dashboardBusinessSignals');
        if (!container || !state.sellerAnalytics) return;
        const sellers = state.sellerAnalytics.vendedores || [];
        const summary = state.sellerAnalytics.resumen || {};
        const approvalSummary = state.sellerAnalytics.aprobaciones?.resumen || {};
        const signals = [];
        if (Number(approvalSummary.correos_fallidos || 0) > 0) {
            signals.push({ tone: 'danger', title: 'Solicitudes sin entregar', detail: `${approvalSummary.correos_fallidos} correo(s) de aprobación fallaron y requieren reenvío o cambio de responsable.` });
        }
        if (Number(approvalSummary.horas_pendiente_mas_antigua || 0) >= 48) {
            signals.push({ tone: 'warning', title: 'Aprobación fuera de plazo', detail: `La solicitud pendiente más antigua lleva ${approvalSummary.horas_pendiente_mas_antigua} horas; conviene reenviar o contactar al responsable.` });
        }
        if (Number(summary.pendiente_total || 0) > 0) {
            signals.push({ tone: 'pending', title: 'Monto pendiente de decisión', detail: `${money.format(Number(summary.pendiente_total))} permanece fuera del gasto aprobado hasta que Tesorería resuelva las rendiciones.` });
        }
        sellers.filter((seller) => Number(seller.casos_exceso || 0) >= 2).forEach((seller) => {
            signals.push({ tone: 'warning', title: 'Excesos recurrentes', detail: `${seller.vendedor_nombre} registra ${seller.casos_exceso} casos con exceso en ${seller.empresa_nombre}; conviene revisar cupo o política de gasto.` });
        });
        sellers.forEach((seller) => {
            const decided = Number(seller.rendiciones_aprobadas || 0) + Number(seller.rendiciones_rechazadas || 0);
            const rejectionRate = decided > 0 ? Number(seller.rendiciones_rechazadas || 0) / decided : 0;
            if (decided >= 2 && rejectionRate >= .4) {
                signals.push({ tone: 'danger', title: 'Alta tasa de rechazo', detail: `${seller.vendedor_nombre} tiene ${formatPercent(rejectionRate * 100)}% de rendiciones decididas rechazadas; revisar calidad documental o capacitación.` });
            }
        });
        sellers.filter((seller) => Number(seller.presupuesto_total || 0) > 0 && Number(seller.aprobado_total || 0) === 0).forEach((seller) => {
            signals.push({ tone: 'neutral', title: 'Presupuesto sin ejecución aprobada', detail: `${seller.vendedor_nombre} mantiene ${money.format(Number(seller.presupuesto_total))} asignados sin gasto aprobado en el horizonte.` });
        });
        sellers.filter((seller) => Number(seller.presupuesto_total || 0) > 0 && Number(seller.ejecucion_pct || 0) >= 90).forEach((seller) => {
            signals.push({ tone: 'info', title: 'Cupo próximo al límite', detail: `${seller.vendedor_nombre} lleva ${formatPercent(seller.ejecucion_pct)}% de ejecución aprobada; revisar continuidad operativa antes del próximo período.` });
        });
        if (Number(summary.concentracion_principal_pct || 0) >= 50 && sellers[0]) {
            signals.push({ tone: 'info', title: 'Gasto concentrado', detail: `${sellers[0].vendedor_nombre} representa ${formatPercent(summary.concentracion_principal_pct)}% del gasto aprobado del horizonte.` });
        }
        if (!signals.length) {
            container.innerHTML = '<div class="rd-signal rd-signal--clear"><span></span><div><strong>Sin señales críticas</strong><p>La ejecución no presenta patrones que superen los umbrales de revisión definidos.</p></div></div>';
            return;
        }
        container.innerHTML = signals.slice(0, 6).map((signal) => `<div class="rd-signal rd-signal--${signal.tone}"><span aria-hidden="true"></span><div><strong>${escapeHtml(signal.title)}</strong><p>${escapeHtml(signal.detail)}</p></div></div>`).join('');
    }

    async function loadDashboardRenditions(period) {
        const rows = [];
        let page = 1;
        while (true) {
            const query = new URLSearchParams({ estado: 'TODOS', tipo: 'TODOS', mes: period, empresa_id: '', limite: '100', pagina: String(page) });
            const payload = await apiRequest(`${API_BASE}/get_rendiciones.php?${query.toString()}`);
            const batch = payload.data.rendiciones || [];
            rows.push(...batch);
            if (batch.length < 100) return rows;
            page += 1;
        }
    }

    async function loadDetailsWithConcurrency(renditions, concurrency) {
        const results = [];
        let cursor = 0;
        async function worker() {
            while (cursor < renditions.length) {
                const item = renditions[cursor++];
                const id = Number(item.id);
                try {
                    let detail = state.detailCache.get(id);
                    if (!detail) {
                        const payload = await apiRequest(`${API_BASE}/get_detalle_rendicion.php?id=${id}`);
                        detail = payload.data;
                        state.detailCache.set(id, detail);
                    }
                    results.push(detail);
                } catch (_) {
                    // El dashboard continúa con las rendiciones que sí pudieron consolidarse.
                }
            }
        }
        await Promise.all(Array.from({ length: Math.min(concurrency, renditions.length || 1) }, worker));
        return results;
    }

    function renderDashboard(renditions, details, period) {
        const budgets = state.budgets.filter((budget) => Number(budget.activo) === 1 && budget.periodo_mes === period);
        const budgetTotal = budgets.reduce((sum, budget) => sum + Number(budget.monto_asignado || 0), 0);
        const approvedTotal = renditions.reduce((sum, item) => sum + Number(item.monto_total_aprobado || 0), 0);
        const excessCount = renditions.filter((item) => Number(item.monto_exceso || 0) > 0).length;
        const execution = budgetTotal > 0 ? (approvedTotal / budgetTotal) * 100 : 0;
        const excessRate = renditions.length ? (excessCount / renditions.length) * 100 : 0;
        const budgetMetricsAvailable = canManage && state.budgetsAvailable;
        setText('dashboardBudget', budgetMetricsAvailable ? money.format(budgetTotal) : 'No disponible');
        setText('dashboardBudgetNote', budgetMetricsAvailable ? (budgets.length === 1 ? '1 presupuesto activo' : `${budgets.length} presupuestos activos`) : 'No fue posible consultar presupuestos');
        setText('dashboardRendered', money.format(approvedTotal));
        setText('dashboardRenderedNote', renditions.length === 1 ? '1 rendición aprobada' : `${renditions.length} rendiciones aprobadas`);
        setText('dashboardExecution', budgetMetricsAvailable ? `${formatPercent(execution)}%` : '—');
        setText('dashboardExcessRate', `${formatPercent(excessRate)}%`);
        setText('dashboardExcessNote', excessCount === 1 ? '1 caso con exceso' : `${excessCount} casos con exceso`);

        const categories = new Map();
        details.forEach((detail) => (detail.documentos || []).filter((documentData) => documentData.estado_item === 'APROBADO').forEach((documentData) => {
            const key = documentData.categoria_gasto || 'OTROS';
            categories.set(key, (categories.get(key) || 0) + Number(documentData.monto_validado ?? documentData.monto ?? 0));
        }));
        const categoryRows = [...categories.entries()].map(([key, amount]) => ({ label: categoryInfo(key).label, amount }));
        renderBarList($('#dashboardCategories'), categoryRows);

        const companies = new Map();
        renditions.forEach((item) => companies.set(item.empresa_nombre || 'Sin empresa', (companies.get(item.empresa_nombre || 'Sin empresa') || 0) + Number(item.monto_total_aprobado || 0)));
        renderBarList($('#dashboardCompanies'), [...companies.entries()].map(([label, amount]) => ({ label, amount })));
    }

    async function refreshActiveSubmodule() {
        state.dashboardKey = '';
        state.sellerAnalyticsKey = '';
        state.detailCache.clear();
        if (state.activeSubmodule === 'dashboard') {
            await loadDashboard(true);
            return;
        }
        if (state.activeSubmodule === 'vendedores' && canManage) {
            await Promise.all([loadBudgets(true), loadSellerDirectory(true)]);
            return;
        }
        const selectedId = state.selectedId;
        await loadRenditions(false);
        if (selectedId && state.renditions.some((item) => Number(item.id) === Number(selectedId))) {
            await selectRendition(selectedId, true);
        }
    }

    function renderBarList(container, rows) {
        if (!rows.length) { container.innerHTML = '<div class="rd-bar-empty">No hay movimientos para este período.</div>'; return; }
        const sorted = rows.sort((a, b) => b.amount - a.amount);
        const total = sorted.reduce((sum, row) => sum + row.amount, 0);
        container.innerHTML = sorted.map((row) => {
            const percent = total > 0 ? (row.amount / total) * 100 : 0;
            return `<div class="rd-bar-row"><div class="rd-bar-row__header"><span>${escapeHtml(row.label)}</span><strong>${money.format(row.amount)} · ${formatPercent(percent)}%</strong></div><div class="rd-bar-row__track"><div class="rd-bar-row__fill" style="width:${Math.max(percent, 1)}%"></div></div></div>`;
        }).join('');
    }

    async function loadSellerDirectory(silent) {
        if (!canManage) return;
        try {
            const payload = await apiRequest(`${API_BASE}/buscar_vendedores.php`);
            state.sellerDirectory = payload.data || [];
            state.sellerDirectoryLoaded = true;
            populateBudgetCompanies(payload.empresas || []);
            if (state.budgetsLoaded) renderBudgets();
        } catch (error) {
            const companySelect = $('#budgetCompany');
            if (companySelect && companySelect.options.length <= 1) {
                companySelect.innerHTML = '<option value="">No fue posible cargar empresas</option>';
            }
            if (!silent) notify(error.message, 'error');
        }
    }

    function populateBudgetCompanies(companies) {
        const select = $('#budgetCompany');
        if (!select) return;
        const selected = select.value;
        select.innerHTML = `<option value="">Seleccionar empresa</option>${companies.map((company) => `<option value="${Number(company.empresa_id)}">${escapeHtml(company.empresa_nombre)}</option>`).join('')}`;
        if ([...select.options].some((option) => option.value === selected)) select.value = selected;
    }

    async function onBudgetCompanyChange() {
        clearBudgetSeller();
        const companyId = Number($('#budgetCompany').value);
        const input = $('#budgetSellerSearch');
        if (!companyId) {
            input.disabled = true;
            input.placeholder = 'Primero selecciona una empresa';
            setText('budgetSellerHelp', 'La identidad se obtiene directamente desde la tabla de vendedores del ERP.');
            return;
        }
        input.disabled = false;
        input.placeholder = 'Buscar por nombre, correo o código ERP';
        await searchBudgetSellers('');
        input.focus();
    }

    function onBudgetSellerSearch(event) {
        clearTimeout(sellerSearchTimer);
        clearBudgetSeller(false);
        sellerSearchTimer = setTimeout(() => searchBudgetSellers(event.target.value), 260);
    }

    async function searchBudgetSellers(search) {
        const companyId = Number($('#budgetCompany').value);
        if (!companyId) return;
        const input = $('#budgetSellerSearch');
        const spinner = $('#budgetSellerSpinner');
        input.setAttribute('aria-busy', 'true');
        spinner.hidden = false;
        setText('budgetSellerHelp', 'Consultando el catálogo oficial del ERP…');
        try {
            const query = new URLSearchParams({ empresa_id: String(companyId), busqueda: String(search || '') });
            const payload = await apiRequest(`${API_BASE}/buscar_vendedores.php?${query}`);
            state.sellerOptions = payload.data || [];
            state.sellerOptionIndex = state.sellerOptions.length ? 0 : -1;
            renderSellerOptions();
            setText('budgetSellerHelp', state.sellerOptions.length
                ? `${state.sellerOptions.length} vendedor(es) encontrados en el ERP.`
                : 'No hay vendedores que coincidan. Revisa la empresa o la búsqueda.');
        } catch (error) {
            state.sellerOptions = [];
            renderSellerOptions();
            setText('budgetSellerHelp', error.message);
            notify(error.message, 'error');
        } finally {
            input.removeAttribute('aria-busy');
            spinner.hidden = true;
        }
    }

    function renderSellerOptions() {
        const container = $('#budgetSellerOptions');
        const input = $('#budgetSellerSearch');
        if (!state.sellerOptions.length) {
            container.hidden = true;
            container.innerHTML = '';
            input.setAttribute('aria-expanded', 'false');
            return;
        }
        container.innerHTML = state.sellerOptions.map((seller, index) => `<button class="rd-seller-option ${index === state.sellerOptionIndex ? 'is-active' : ''}" type="button" role="option" aria-selected="${index === state.sellerOptionIndex ? 'true' : 'false'}" data-seller-option="${index}">
            <span class="rd-seller-option__avatar" aria-hidden="true">${escapeHtml(sellerInitials(seller.vendedor_nombre))}</span>
            <span><strong>${escapeHtml(seller.vendedor_nombre)}</strong><small>${escapeHtml(seller.vendedor_email || 'Sin correo válido en ERP')}</small></span>
            <span class="rd-seller-option__code">#${escapeHtml(seller.vendedor_id)}</span>
        </button>`).join('');
        container.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function onBudgetSellerOptionClick(event) {
        const option = event.target.closest('[data-seller-option]');
        if (!option) return;
        selectBudgetSeller(state.sellerOptions[Number(option.dataset.sellerOption)]);
    }

    function onBudgetSellerKeydown(event) {
        if (event.key === 'Escape') { closeSellerOptions(); return; }
        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || !state.sellerOptions.length) return;
        event.preventDefault();
        if (event.key === 'Enter') {
            selectBudgetSeller(state.sellerOptions[Math.max(0, state.sellerOptionIndex)]);
            return;
        }
        const delta = event.key === 'ArrowDown' ? 1 : -1;
        state.sellerOptionIndex = (state.sellerOptionIndex + delta + state.sellerOptions.length) % state.sellerOptions.length;
        renderSellerOptions();
        $('#budgetSellerOptions [aria-selected="true"]')?.scrollIntoView({ block: 'nearest' });
    }

    function selectBudgetSeller(seller) {
        if (!seller) return;
        $('#budgetSellerId').value = seller.vendedor_id;
        $('#budgetSellerName').value = seller.vendedor_nombre || '';
        $('#budgetSellerEmail').value = seller.vendedor_email || '';
        $('#budgetSellerSearch').value = seller.vendedor_nombre || '';
        setText('budgetSellerSelectedName', seller.vendedor_nombre || 'Vendedor ERP');
        setText('budgetSellerSelectedMeta', `${seller.empresa_nombre || selectedCompanyName()} · Código #${seller.vendedor_id}${seller.vendedor_email ? ` · ${seller.vendedor_email}` : ' · Sin correo válido'}`);
        $('#budgetSellerSelected').hidden = false;
        $('#budgetSellerSelected .rd-seller-selected__avatar').textContent = sellerInitials(seller.vendedor_nombre);
        setText('budgetSellerHelp', 'Identidad seleccionada. Nombre, código y correo se validarán nuevamente al guardar.');
        closeSellerOptions();
    }

    function clearBudgetSeller(clearSearch = true) {
        ['#budgetSellerId', '#budgetSellerName', '#budgetSellerEmail'].forEach((selector) => { if ($(selector)) $(selector).value = ''; });
        if (clearSearch && $('#budgetSellerSearch')) $('#budgetSellerSearch').value = '';
        if ($('#budgetSellerSelected')) $('#budgetSellerSelected').hidden = true;
        closeSellerOptions();
    }

    function closeSellerOptions() {
        if ($('#budgetSellerOptions')) $('#budgetSellerOptions').hidden = true;
        $('#budgetSellerSearch')?.setAttribute('aria-expanded', 'false');
    }

    function setBudgetIdentityLocked(locked, lockType) {
        const company = $('#budgetCompany');
        const search = $('#budgetSellerSearch');
        const type = $('#budgetType');
        company.disabled = locked;
        search.disabled = locked || !company.value;
        type.disabled = Boolean(lockType);
        $('#budgetSellerField')?.classList.toggle('is-locked', locked);
        if (locked) closeSellerOptions();
    }

    function getSellerPresence(budget) {
        const email = normalize(budget.vendedor_email || '');
        const group = state.sellerDirectory.find((item) => {
            if (email && normalize(item.vendedor_email || '') === email) return true;
            return (item.empresas || []).some((company) => Number(company.empresa_id) === Number(budget.empresa_id) && Number(company.vendedor_id) === Number(budget.vendedor_id));
        });
        return group?.empresas || [];
    }

    function shortCompanyName(name) {
        return String(name || '').replace(/\s+(LTDA|S\.A)$/i, '').replace('HD Automarco', 'HD');
    }

    function selectedCompanyName() {
        return $('#budgetCompany')?.selectedOptions?.[0]?.textContent || 'Empresa ERP';
    }

    function sellerInitials(name) {
        return String(name || 'VE').trim().split(/\s+/).slice(0, 2).map((word) => word.charAt(0)).join('').toUpperCase();
    }

    async function loadBudgets(silent) {
        if (!canManage) return;
        if (!silent && $('#budgetTableBody')) $('#budgetTableBody').innerHTML = tableMessage('Cargando presupuestos…', 10);
        try {
            const directoryPromise = state.sellerDirectoryLoaded ? Promise.resolve() : loadSellerDirectory(true);
            const payload = await apiRequest(`${API_BASE}/gestion_presupuestos.php`);
            await directoryPromise;
            state.budgets = payload.data || [];
            state.budgetsLoaded = true;
            state.budgetsAvailable = true;
            renderBudgets();
        } catch (error) {
            state.budgetsAvailable = false;
            if ($('#budgetTableBody')) $('#budgetTableBody').innerHTML = tableMessage(`No fue posible cargar presupuestos. ${error.message}`, 10);
            if (!silent) notify(error.message, 'error');
        }
    }

    function onBudgetTypeFilterClick(event) {
        const button = event.target.closest('[data-budget-filter]');
        if (!button) return;
        const filter = button.dataset.budgetFilter;
        if (state.budgetFilter === filter) return;
        state.budgetFilter = filter;
        $$('#budgetTypeTabs [data-budget-filter]').forEach((tab) => {
            const active = tab.dataset.budgetFilter === filter;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', String(active));
        });
        renderBudgets();
    }

    function renderMonthlyBudgetRow(budget) {
        const active = Number(budget.activo) === 1;
        const presence = getSellerPresence(budget);
        const presenceMarkup = presence.length
            ? `<div class="rd-erp-presence">${presence.map((item) => `<span title="${escapeHtml(item.empresa_nombre)} · código ${escapeHtml(item.vendedor_id)}">${escapeHtml(shortCompanyName(item.empresa_nombre))} <b>#${escapeHtml(item.vendedor_id)}</b></span>`).join('')}</div>`
            : '<span class="rd-cell-secondary">Sin homologación por correo</span>';
        return `<tr class="${active ? '' : 'is-inactive'}">
            <td><span class="rd-cell-primary">${escapeHtml(budget.vendedor_nombre || 'Sin nombre')}</span><span class="rd-cell-secondary">${escapeHtml(budget.vendedor_email || `Código ERP #${budget.vendedor_id}`)}</span></td>
            <td><span class="rd-cell-primary">${escapeHtml(budget.empresa_nombre || 'Sin empresa')}</span></td>
            <td>${presenceMarkup}</td>
            <td><span class="rd-budget-type">Mensual</span></td>
            <td><span class="rd-cell-primary">${escapeHtml(formatMonthShort(budget.periodo_mes))}</span><span class="rd-cell-secondary">${escapeHtml(formatMonth(budget.periodo_mes))}</span></td>
            <td><span class="rd-cell-money">${money.format(budget.monto_asignado)}</span></td>
            <td><span class="rd-cell-money">${money.format(budget.monto_utilizado)}</span></td>
            <td><span class="rd-cell-money">${money.format(budget.saldo_disponible)}</span></td>
            <td><span class="rd-status ${active ? 'rd-status--success' : ''}">${active ? 'Activo' : 'Inactivo'}</span></td>
            <td>${active ? `<div class="rd-budget-actions"><button class="rd-btn rd-btn--tour rd-btn--small" type="button" data-add-tour="${Number(budget.id)}">+ Agregar gira</button><button class="rd-btn rd-btn--secondary rd-btn--small" type="button" data-edit-budget="${Number(budget.id)}">Editar</button><button class="rd-btn rd-btn--danger rd-btn--small" type="button" data-deactivate-budget="${Number(budget.id)}">Desactivar</button></div>` : '—'}</td>
        </tr>`;
    }

    function renderTourBudgetRow(budget) {
        const active = Number(budget.activo) === 1;
        const dates = budget.fecha_inicio && budget.fecha_fin ? `${formatDate(budget.fecha_inicio)} — ${formatDate(budget.fecha_fin)}` : formatMonth(budget.periodo_mes);
        const presence = getSellerPresence(budget);
        const presenceMarkup = presence.length
            ? `<div class="rd-erp-presence">${presence.map((item) => `<span title="${escapeHtml(item.empresa_nombre)} · código ${escapeHtml(item.vendedor_id)}">${escapeHtml(shortCompanyName(item.empresa_nombre))} <b>#${escapeHtml(item.vendedor_id)}</b></span>`).join('')}</div>`
            : '<span class="rd-cell-secondary">Sin homologación por correo</span>';
        const approvalState = String(budget.solicitud_estado || budget.estado_aprobacion || 'PENDIENTE');
        const approvalLabel = { PENDIENTE_ENVIO: 'Pendiente de envío', PENDIENTE_DECISION: 'Esperando decisión', ENVIO_FALLIDO: 'Correo fallido', VENCIDA: 'Enlace vencido', APROBADA: 'Aprobada', RECHAZADA: 'Rechazada', CANCELADA: 'Cancelada', PENDIENTE: 'Pendiente' }[approvalState] || approvalState;
        const tourWorkflowActions = ['PENDIENTE_ENVIO', 'PENDIENTE_DECISION', 'ENVIO_FALLIDO', 'VENCIDA'].includes(approvalState)
            ? `<button class="rd-btn rd-btn--warning rd-btn--small" type="button" data-resend-tour="${Number(budget.id)}">Reenviar aprobación</button><button class="rd-btn rd-btn--danger rd-btn--small" type="button" data-cancel-tour="${Number(budget.id)}">Cancelar solicitud</button>`
            : '';
        const tourPdfAction = approvalState === 'APROBADA'
            ? `<a class="rd-btn rd-btn--success rd-btn--small" href="reportes/comprobante_aprobacion_gira.php?id=${Number(budget.id)}" target="_blank" rel="noopener noreferrer" title="Ver / imprimir comprobante PDF de la gira"><svg aria-hidden="true" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2h9l5 5v15H6V2Zm8 0v6h6M9 13h8m-8 4h5"/></svg>Certificado PDF</a>`
            : '';
        return `<tr class="${active ? '' : 'is-inactive'}">
            <td><span class="rd-cell-primary">${escapeHtml(budget.vendedor_nombre || 'Sin nombre')}</span><span class="rd-cell-secondary">${escapeHtml(budget.vendedor_email || `Código ERP #${budget.vendedor_id}`)}</span></td>
            <td><span class="rd-cell-primary">${escapeHtml(budget.empresa_nombre || 'Sin empresa')}</span></td>
            <td>${presenceMarkup}</td>
            <td><span class="rd-budget-type rd-budget-type--tour">Gira</span></td>
            <td><span class="rd-cell-primary">${escapeHtml(budget.nombre_gira || 'Gira comercial')}</span><span class="rd-cell-secondary">${escapeHtml(dates)}</span></td>
            <td><span class="rd-cell-money">${money.format(budget.monto_asignado)}</span></td>
            <td><span class="rd-cell-money">${money.format(budget.monto_utilizado)}</span></td>
            <td><span class="rd-cell-money">${money.format(budget.saldo_disponible)}</span></td>
            <td><span class="rd-status ${active && approvalState === 'APROBADA' ? 'rd-status--success' : ''}">${active ? escapeHtml(approvalLabel) : 'Inactivo'}</span></td>
            <td>${active ? `<div class="rd-budget-actions">${tourPdfAction}${tourWorkflowActions}<button class="rd-btn rd-btn--secondary rd-btn--small" type="button" data-edit-budget="${Number(budget.id)}">Editar</button><button class="rd-btn rd-btn--danger rd-btn--small" type="button" data-deactivate-budget="${Number(budget.id)}">Desactivar</button></div>` : (tourPdfAction ? `<div class="rd-budget-actions">${tourPdfAction}</div>` : '—')}</td>
        </tr>`;
    }

    function renderBudgets() {
        const container = $('#budgetTablesContainer') || $('#budgetTableBody');
        if (!container) return;

        const allMonthly = state.budgets.filter((b) => b.tipo_presupuesto === 'MENSUAL');
        const allTours = state.budgets.filter((b) => b.tipo_presupuesto === 'GIRA');

        setText('countBudgetMonthly', String(allMonthly.length));
        setText('countBudgetTour', String(allTours.length));

        const search = normalize($('#budgetSearch')?.value || '');
        const matchesSearch = (budget) => {
            const presence = getSellerPresence(budget).map((item) => `${item.empresa_nombre} ${item.vendedor_id}`).join(' ');
            return !search || normalize(`${budget.vendedor_nombre} ${budget.vendedor_id} ${budget.vendedor_email || ''} ${budget.empresa_nombre} ${presence}`).includes(search);
        };

        const isTour = state.budgetFilter === 'GIRA';
        const filteredList = (isTour ? allTours : allMonthly).filter(matchesSearch);
        const totalAssigned = filteredList.reduce((sum, budget) => sum + Number(budget.monto_asignado || 0), 0);

        const filterLabel = isTour ? 'giras visibles' : 'presupuestos mensuales visibles';
        setText('budgetSummary', filteredList.length === 1 ? `1 ${filterLabel.replace('visibles', 'visible')}` : `${filteredList.length} ${filterLabel}`);
        setText('budgetAmountSummary', `${money.format(totalAssigned)} asignado`);

        const monthlyHead = `<thead><tr><th>Vendedor</th><th>Empresa del cupo</th><th>Presencia ERP</th><th>Tipo</th><th>Período</th><th>Asignado</th><th>Gastado</th><th>Saldo</th><th>Estado</th><th>Acciones</th></tr></thead>`;
        const tourHead = `<thead><tr><th>Vendedor</th><th>Empresa del cupo</th><th>Presencia ERP</th><th>Tipo</th><th>Gira comercial / Fechas</th><th>Asignado</th><th>Gastado</th><th>Saldo</th><th>Estado</th><th>Acciones</th></tr></thead>`;

        if (!filteredList.length) {
            const emptyMsg = search ? 'No hay presupuestos que coincidan con la búsqueda.' : (isTour ? 'No hay giras comerciales registradas.' : 'No hay presupuestos mensuales registrados.');
            container.innerHTML = `<table class="rd-master-table rd-budget-table">${isTour ? tourHead : monthlyHead}<tbody><tr><td colspan="10" class="rd-table-message">${emptyMsg}</td></tr></tbody></table>`;
            return;
        }

        if (isTour) {
            container.innerHTML = `<table class="rd-master-table rd-budget-table">${tourHead}<tbody>${filteredList.map(renderTourBudgetRow).join('')}</tbody></table>`;
        } else {
            container.innerHTML = `<table class="rd-master-table rd-budget-table">${monthlyHead}<tbody>${filteredList.map(renderMonthlyBudgetRow).join('')}</tbody></table>`;
        }
    }

    function onBudgetAction(event) {
        const resendTourButton = event.target.closest('[data-resend-tour]');
        if (resendTourButton) { openTourApprovalModal(Number(resendTourButton.dataset.resendTour)); return; }
        const cancelTourButton = event.target.closest('[data-cancel-tour]');
        if (cancelTourButton) { openActionModal('CANCELAR_SOLICITUD_GIRA', { budgetId: Number(cancelTourButton.dataset.cancelTour) }); return; }
        const tourButton = event.target.closest('[data-add-tour]');
        if (tourButton) { addTourFromBudget(Number(tourButton.dataset.addTour)); return; }
        const editButton = event.target.closest('[data-edit-budget]');
        if (editButton) { editBudget(Number(editButton.dataset.editBudget)); return; }
        const deactivateButton = event.target.closest('[data-deactivate-budget]');
        if (deactivateButton) openActionModal('DESACTIVAR_PRESUPUESTO', { budgetId: Number(deactivateButton.dataset.deactivateBudget) });
    }

    async function openBudgetModal() {
        resetBudgetForm();
        openModal('budgetModal');
        if (!state.sellerDirectoryLoaded) await loadSellerDirectory();
        if (!state.budgetsLoaded) await loadBudgets(true);
    }

    function editBudget(id) {
        const budget = state.budgets.find((item) => Number(item.id) === id);
        if (!budget) return;
        openModal('budgetModal');
        $('#budgetId').value = budget.id;
        $('#budgetCompany').value = budget.empresa_id;
        selectBudgetSeller({
            vendedor_id: budget.vendedor_id,
            vendedor_nombre: budget.vendedor_nombre,
            vendedor_email: budget.vendedor_email,
            empresa_nombre: budget.empresa_nombre
        });
        $('#budgetType').value = budget.tipo_presupuesto;
        $('#budgetPeriod').value = budget.periodo_mes;
        $('#budgetAmount').value = Number(budget.monto_asignado);
        $('#budgetTourName').value = budget.nombre_gira || '';
        $('#budgetStartDate').value = budget.fecha_inicio || '';
        $('#budgetEndDate').value = budget.fecha_fin || '';
        $('#budgetTourJustification').value = budget.justificacion_gira || '';
        $('#budgetFormTitle').textContent = 'Editar presupuesto';
        $('#budgetModalTitle').textContent = 'Editar presupuesto';
        $('#budgetModalDescription').textContent = 'La identidad del vendedor queda vinculada al ERP; puedes ajustar los datos del cupo.';
        setBudgetIdentityLocked(true, false);
        syncTourFields();
        ensureBudgetApprovers(budget.solicitud_aprobador_id || '');
    }

    function addTourFromBudget(id) {
        const budget = state.budgets.find((item) => Number(item.id) === id);
        if (!budget) return;
        resetBudgetForm();
        $('#budgetCompany').value = budget.empresa_id;
        selectBudgetSeller({
            vendedor_id: budget.vendedor_id,
            vendedor_nombre: budget.vendedor_nombre,
            vendedor_email: budget.vendedor_email,
            empresa_nombre: budget.empresa_nombre
        });
        $('#budgetType').value = 'GIRA';
        $('#budgetFormTitle').textContent = 'Nueva gira comercial';
        $('#budgetModalTitle').textContent = `Agregar gira a ${budget.vendedor_nombre || 'vendedor'}`;
        $('#budgetModalDescription').textContent = 'La empresa y el vendedor ya están definidos. Completa únicamente la gira, sus fechas y el monto.';
        setBudgetIdentityLocked(true, true);
        syncTourFields();
        ensureBudgetApprovers();
        openModal('budgetModal');
        $('#budgetTourName')?.focus();
    }

    function resetBudgetForm() {
        $('#budgetForm')?.reset();
        if (!$('#budgetId')) return;
        $('#budgetId').value = '';
        $('#budgetPeriod').value = currentPeriod;
        setBudgetIdentityLocked(false, false);
        clearBudgetSeller();
        $('#budgetFormTitle').textContent = 'Nuevo presupuesto';
        $('#budgetModalTitle').textContent = 'Asignar presupuesto';
        $('#budgetModalDescription').textContent = 'Selecciona una empresa y un vendedor verificado en su ERP.';
        $('#budgetTourJustification').value = '';
        $('#budgetTourApprover').value = '';
        syncTourFields();
    }

    function syncTourFields() {
        if (!$('#budgetType')) return;
        const isTour = $('#budgetType').value === 'GIRA';
        $('#tourFields').hidden = !isTour;
        $('#budgetPeriodField').hidden = isTour;
        ['#budgetTourName', '#budgetStartDate', '#budgetEndDate', '#budgetTourJustification', '#budgetTourApprover'].forEach((selector) => { $(selector).required = isTour; });
        if (isTour) { syncTourPeriod(); ensureBudgetApprovers($('#budgetTourApprover').value); }
        else {
            $('#budgetEndDate').min = '';
            $('#budgetEndDate').setCustomValidity('');
        }
    }

    function syncTourPeriod() {
        const startDate = $('#budgetStartDate')?.value || '';
        if (startDate) {
            $('#budgetPeriod').value = startDate.slice(0, 7);
            $('#budgetEndDate').min = startDate;
        }
        validateTourDateRange();
    }

    function validateTourDateRange() {
        const startDate = $('#budgetStartDate')?.value || '';
        const endDate = $('#budgetEndDate')?.value || '';
        const invalidRange = Boolean(startDate && endDate && endDate < startDate);
        $('#budgetEndDate')?.setCustomValidity(invalidRange ? 'La fecha de término no puede ser anterior al inicio de la gira.' : '');
        return !invalidRange;
    }

    async function ensureBudgetApprovers(selectedId = '') {
        const select = $('#budgetTourApprover');
        if (!select || $('#budgetType')?.value !== 'GIRA') return;
        try {
            const desiredId = String(selectedId || select.value || '');
            const approvers = state.approvers.length ? state.approvers : await loadApprovers();
            select.innerHTML = '<option value="">Selecciona un responsable</option>' + approvers.map((approver) => `<option value="${Number(approver.id)}">${escapeHtml(approver.nombre)} · ${escapeHtml(approver.cargo)}</option>`).join('');
            if (desiredId) select.value = desiredId;
        } catch (error) {
            select.innerHTML = '<option value="">No fue posible cargar responsables</option>';
            notify(error.message, 'error');
        }
    }

    async function saveBudget(event) {
        event.preventDefault();
        if (!Number($('#budgetSellerId').value)) {
            notify('Selecciona un vendedor de la lista oficial del ERP.', 'error');
            $('#budgetSellerSearch')?.focus();
            return;
        }
        if (!validateTourDateRange() || !event.currentTarget.reportValidity()) return;
        const id = Number($('#budgetId').value || 0);
        const input = {
            accion: id ? 'ACTUALIZAR' : 'CREAR', id: id || undefined,
            empresa_id: Number($('#budgetCompany').value), vendedor_id: Number($('#budgetSellerId').value),
            tipo_presupuesto: $('#budgetType').value, periodo_mes: $('#budgetPeriod').value,
            monto_asignado: Number($('#budgetAmount').value), nombre_gira: $('#budgetTourName').value.trim(),
            fecha_inicio: $('#budgetStartDate').value, fecha_fin: $('#budgetEndDate').value,
            justificacion_gira: $('#budgetTourJustification').value.trim(), aprobador_id: Number($('#budgetTourApprover').value || 0)
        };
        const button = $('#saveBudgetButton');
        setBusy(button, true, 'Guardando…');
        try {
            const payload = await apiRequest(`${API_BASE}/gestion_presupuestos.php`, { method: 'POST', body: JSON.stringify(input) });
            notify(payload.message, 'success');
            closeModal('budgetModal');
            await loadBudgets();
            state.dashboardKey = '';
        } catch (error) {
            notify(error.message, 'error');
        } finally {
            setBusy(button, false, 'Guardar presupuesto');
        }
    }

    function openActionModal(action, context = {}) {
        if (['REENVIAR_EXCESO', 'SOLICITAR_EXCEPCION', 'VERIFICAR_Y_ENVIAR', 'REENVIAR_RESPONSABLE'].includes(action)) {
            openExcessApprovalModal({ kind: 'RENDICION', action });
            return;
        }
        const actionDetails = {
            RECIBIR_FISICOS: ['Registrar recepción física', 'Se dejará constancia de que Tesorería recibió los documentos originales.', false, 'Registrar recepción'],
            APROBAR_TOTAL: ['Aprobar rendición completa', 'Todos los comprobantes quedarán aprobados por su monto rendido.', false, 'Aprobar rendición'],
            RECHAZAR: ['Rechazar rendición', 'La rendición y sus documentos quedarán rechazados. Esta acción requiere un motivo.', true, 'Rechazar'],
            RECHAZAR_EXCESO_TESORERIA: ['Cancelar rendición con exceso', 'Tesorería rechazará la rendición y liberará todo el monto comprometido. No se enviará una nueva solicitud a Jefatura; si ya existía un enlace, quedará invalidado.', true, 'Rechazar y liberar fondos'],
            CANCELAR_SOLICITUD_RESPONSABLE: ['Cancelar solicitud al Responsable', 'La solicitud actual quedará cancelada y la rendición volverá al estado de revisión de Tesorería para corregir lo necesario.', false, 'Cancelar y reabrir'],
            MARCAR_PAGADA: ['Marcar rendición pagada', 'Se cerrará el flujo financiero de esta rendición.', false, 'Marcar pagada'],
            DESACTIVAR_PRESUPUESTO: ['Desactivar presupuesto', 'El presupuesto dejará de estar disponible para nuevas rendiciones.', false, 'Desactivar'],
            CANCELAR_SOLICITUD_GIRA: ['Cancelar solicitud de gira', 'El enlace quedará invalidado y la gira dejará de estar disponible. La acción conservará su auditoría.', true, 'Cancelar solicitud']
        };
        const info = actionDetails[action];
        if (!info) return;
        state.action = { action, ...context };

        let modalTitle = info[0];
        let modalDescription = info[1];
        let confirmLabel = info[3];

        if (action === 'APROBAR_TOTAL' && state.detail?.rendicion) {
            const r = state.detail.rendicion;
            const excess = Number(r.monto_exceso_no_reembolsable || 0);
            const hasActiveExcess = excess > 0 && r.decision_exceso !== 'APROBADO';
            if (hasActiveExcess) {
                modalTitle = 'Aprobar hasta el tope del presupuesto';
                modalDescription = `Se aprobará la rendición pagando únicamente hasta el saldo disponible de ${money.format(r.monto_maximo_aprobable)}. El exceso de ${money.format(excess)} no será reembolsado al vendedor.`;
                confirmLabel = `Aprobar por ${money.format(r.monto_maximo_aprobable)}`;
            }
        }

        $('#actionModalTitle').textContent = modalTitle;
        $('#actionModalDescription').textContent = modalDescription;
        $('#actionCommentLabel').textContent = info[2] ? 'Motivo obligatorio' : 'Comentario opcional';
        $('#actionComment').required = info[2];
        $('#actionComment').value = '';
        $('#confirmActionButton').textContent = confirmLabel;
        $('#confirmActionButton').className = `rd-btn ${['RECHAZAR', 'RECHAZAR_EXCESO_TESORERIA', 'DESACTIVAR_PRESUPUESTO', 'CANCELAR_SOLICITUD_GIRA'].includes(action) ? 'rd-btn--danger' : (action === 'APROBAR_TOTAL' ? 'rd-btn--success' : 'rd-btn--primary')}`;
        openModal('actionModal');
    }

    async function loadApprovers() {
        const payload = await apiRequest(`${API_BASE}/gestion_aprobadores.php`);
        state.approvers = Array.isArray(payload.data) ? payload.data : [];
        return state.approvers;
    }

    async function openExcessApprovalModal(context = { kind: 'EXCEPCION', action: 'SOLICITAR_EXCEPCION' }) {
        state.approvalContext = context;
        const isTour = state.approvalContext.kind === 'GIRA';
        const isRendition = state.approvalContext.kind === 'RENDICION';
        let modalTitle = 'Solicitar aprobación';
        if (isTour) modalTitle = 'Reenviar aprobación de gira';
        else if (isRendition) modalTitle = context.action === 'REENVIAR_RESPONSABLE' ? 'Reenviar solicitud a Responsable' : 'Verificar y Enviar a Responsable';
        else modalTitle = 'Solicitar excepción mensual';

        $('#excessApprovalTitle').textContent = modalTitle;
        $('#excessApprovalComment').closest('label').querySelector('span').textContent = (isTour || isRendition) ? 'Observación para el Responsable (opcional)' : 'Justificación para Gerencia (obligatoria)';
        $('#excessApprovalComment').required = (!isTour && !isRendition);
        $('#approverChoices').innerHTML = '<p class="rd-modal__description">Cargando responsables…</p>';
        $('#approverChoiceStatus').textContent = '';
        $('#excessApprovalComment').value = '';
        $('#sendExcessApproval').disabled = true;
        openModal('excessApprovalModal');
        try {
            const approvers = await loadApprovers();
            if (approvers.length !== 2) {
                $('#approverChoices').innerHTML = '<p class="rd-modal__description rd-form-status--error">La solicitud no puede enviarse hasta configurar dos responsables activos.</p>';
                $('#approverChoiceStatus').textContent = canConfigureApprovers ? 'Cierra este diálogo y usa “Responsables de aprobación”.' : 'Solicita a un Administrador que complete la configuración.';
                return;
            }
            $('#approverChoices').innerHTML = approvers.map((approver, index) => `<label class="rd-approver-option"><input type="radio" name="excessApprover" value="${Number(approver.id)}" ${index === 0 ? 'checked' : ''}><span><strong>${escapeHtml(approver.nombre)}</strong><small>${escapeHtml(approver.cargo)} · ${escapeHtml(approver.email)}</small></span></label>`).join('');
            $('#sendExcessApproval').disabled = false;
        } catch (error) {
            $('#approverChoices').innerHTML = `<p class="rd-modal__description rd-form-status--error">${escapeHtml(error.message)}</p>`;
        }
    }

    function openTourApprovalModal(budgetId) {
        openExcessApprovalModal({ kind: 'GIRA', budgetId, action: 'REENVIAR_SOLICITUD_GIRA' });
    }

    async function sendExcessApproval() {
        const selected = $('input[name="excessApprover"]:checked');
        if (!selected) { $('#approverChoiceStatus').textContent = 'Selecciona un responsable.'; return; }
        const context = state.approvalContext || { kind: 'EXCEPCION', action: 'SOLICITAR_EXCEPCION' };
        const comment = $('#excessApprovalComment').value.trim();
        if (context.kind !== 'GIRA' && context.kind !== 'RENDICION' && !comment) {
            $('#approverChoiceStatus').textContent = 'Indica por qué se solicita aprobar este exceso.';
            $('#excessApprovalComment').focus();
            return;
        }
        const button = $('#sendExcessApproval');
        setBusy(button, true, 'Enviando…');
        try {
            const payload = context.kind === 'GIRA'
                ? await apiRequest(`${API_BASE}/gestion_presupuestos.php`, { method: 'POST', body: JSON.stringify({ accion: context.action, presupuesto_id: context.budgetId, aprobador_id: Number(selected.value), comentario: comment }) })
                : await apiRequest(`${API_BASE}/cambiar_estado.php`, { method: 'POST', body: JSON.stringify({ rendicion_id: state.selectedId, accion: context.action, aprobador_id: Number(selected.value), comentario: comment }) });
            closeModal('excessApprovalModal');
            notify(payload.message, payload.data?.correo_enviado ? 'success' : 'warning');
            if (context.kind === 'GIRA') await loadBudgets(true);
            else {
                state.detailCache.delete(Number(state.selectedId));
                await selectRendition(Number(state.selectedId), true);
                await loadRenditions();
            }
        } catch (error) {
            $('#approverChoiceStatus').textContent = error.message;
        } finally {
            setBusy(button, false, 'Enviar solicitud');
        }
    }

    async function openApproverConfig() {
        if (!canConfigureApprovers) return;
        $('#approverConfigStatus').textContent = 'Cargando configuración…';
        openModal('approverConfigModal');
        try {
            const approvers = await loadApprovers();
            [1, 2].forEach((order) => {
                const approver = approvers.find((item) => Number(item.orden) === order) || {};
                $(`#approver${order}Name`).value = approver.nombre || '';
                $(`#approver${order}Title`).value = approver.cargo || '';
                $(`#approver${order}Email`).value = approver.email || '';
            });
            $('#approverConfigStatus').textContent = approvers.length === 2 ? 'Los dos responsables están activos.' : 'Completa ambos responsables para habilitar el envío.';
        } catch (error) {
            $('#approverConfigStatus').textContent = error.message;
        }
    }

    async function saveApproverConfig(event) {
        event.preventDefault();
        const form = $('#approverConfigForm');
        if (!form.reportValidity()) return;
        const approvers = [1, 2].map((order) => ({
            orden: order,
            nombre: $(`#approver${order}Name`).value.trim(),
            cargo: $(`#approver${order}Title`).value.trim(),
            email: $(`#approver${order}Email`).value.trim()
        }));
        if (approvers[0].email.toLowerCase() === approvers[1].email.toLowerCase()) {
            $('#approverConfigStatus').textContent = 'Los responsables deben tener correos diferentes.';
            return;
        }
        const button = $('#saveApproverConfig');
        setBusy(button, true, 'Guardando…');
        try {
            const payload = await apiRequest(`${API_BASE}/gestion_aprobadores.php`, { method: 'POST', body: JSON.stringify({ aprobadores: approvers }) });
            state.approvers = payload.data || [];
            $('#approverConfigStatus').textContent = payload.message;
            notify(payload.message, 'success');
        } catch (error) {
            $('#approverConfigStatus').textContent = error.message;
        } finally {
            setBusy(button, false, 'Guardar responsables');
        }
    }

    async function confirmAction() {
        if (!state.action) return;
        if ($('#actionComment').required && !$('#actionComment').value.trim()) {
            notify('Debe indicar el motivo del rechazo.', 'error');
            $('#actionComment').focus();
            return;
        }
        const button = $('#confirmActionButton');
        const originalLabel = button.textContent;
        setBusy(button, true, 'Procesando…');
        try {
            let payload;
            if (state.action.action === 'DESACTIVAR_PRESUPUESTO') {
                payload = await apiRequest(`${API_BASE}/gestion_presupuestos.php`, { method: 'POST', body: JSON.stringify({ accion: 'DESACTIVAR', id: state.action.budgetId }) });
                closeModal('actionModal');
                await loadBudgets();
                state.dashboardKey = '';
            } else if (state.action.action === 'CANCELAR_SOLICITUD_GIRA') {
                payload = await apiRequest(`${API_BASE}/gestion_presupuestos.php`, { method: 'POST', body: JSON.stringify({ accion: 'CANCELAR_SOLICITUD_GIRA', presupuesto_id: state.action.budgetId, motivo: $('#actionComment').value.trim() }) });
                closeModal('actionModal');
                await loadBudgets(true);
                state.dashboardKey = '';
            } else {
                payload = await apiRequest(`${API_BASE}/cambiar_estado.php`, { method: 'POST', body: JSON.stringify({ rendicion_id: state.selectedId, accion: state.action.action, comentario: $('#actionComment').value.trim() }) });
                closeModal('actionModal');
                state.detailCache.delete(Number(state.selectedId));
                await selectRendition(Number(state.selectedId), true);
                await loadRenditions();
            }
            notify(payload.message, 'success');
        } catch (error) {
            notify(error.message, 'error');
        } finally {
            setBusy(button, false, originalLabel);
        }
    }

    function openPartialModal() {
        if (!state.detail?.documentos?.length) return;
        $('#partialDecisionList').innerHTML = state.detail.documentos.map((documentData) => `<div class="rd-partial-item" data-partial-id="${Number(documentData.id)}">
            <div><strong>${escapeHtml(documentData.razon_social_proveedor || humanize(documentData.categoria_gasto))}</strong><small>Rendido: ${money.format(documentData.monto)}</small></div>
            <label>Decisión<select data-partial-decision><option value="APROBAR">Aprobar</option><option value="RECHAZAR">Rechazar</option></select></label>
            <label>Monto validado<input data-partial-amount type="number" min="0" max="${Number(documentData.monto)}" step="1" value="${Number(documentData.monto)}"></label>
            <label>Motivo de rechazo<input data-partial-reason type="text" maxlength="500" disabled></label>
        </div>`).join('');
        updatePartialTotal();
        openModal('partialModal');
    }

    function updatePartialTotal(event) {
        if (event?.target?.matches('[data-partial-decision]')) {
            const row = event.target.closest('[data-partial-id]');
            const rejected = event.target.value === 'RECHAZAR';
            row.querySelector('[data-partial-amount]').disabled = rejected;
            row.querySelector('[data-partial-reason]').disabled = !rejected;
        }
        let total = 0;
        $$('[data-partial-id]').forEach((row) => {
            if (row.querySelector('[data-partial-decision]').value === 'APROBAR') total += Number(row.querySelector('[data-partial-amount]').value || 0);
        });
        $('#partialApprovedTotal').textContent = `Aprobado: ${money.format(total)}`;
    }

    async function savePartialReview() {
        const decisions = [];
        let rejected = 0;
        for (const row of $$('[data-partial-id]')) {
            const decision = row.querySelector('[data-partial-decision]').value;
            const reason = row.querySelector('[data-partial-reason]').value.trim();
            const amount = Number(row.querySelector('[data-partial-amount]').value || 0);
            if (decision === 'RECHAZAR' && !reason) { notify('Cada comprobante rechazado necesita un motivo.', 'error'); row.querySelector('[data-partial-reason]').focus(); return; }
            if (decision === 'RECHAZAR') rejected++;
            decisions.push({ documento_id: Number(row.dataset.partialId), decision, monto_validado: amount, motivo: reason });
        }
        if (!rejected) { notify('Use “Aprobar rendición” cuando ningún comprobante sea rechazado.', 'error'); return; }
        const button = $('#savePartialButton');
        setBusy(button, true, 'Guardando…');
        try {
            const payload = await apiRequest(`${API_BASE}/cambiar_estado.php`, { method: 'POST', body: JSON.stringify({ rendicion_id: state.selectedId, accion: 'APROBAR_PARCIAL', decisiones }) });
            closeModal('partialModal');
            notify(payload.message, 'success');
            state.detailCache.delete(Number(state.selectedId));
            await selectRendition(Number(state.selectedId), true);
            await loadRenditions();
        } catch (error) {
            notify(error.message, 'error');
        } finally {
            setBusy(button, false, 'Guardar revisión parcial');
        }
    }

    async function apiRequest(url, options = {}) {
        const headers = { Accept: 'application/json', ...(options.headers || {}) };
        if (options.method === 'POST') Object.assign(headers, window.getAdminJsonHeaders());
        let response;
        try { response = await fetch(url, { credentials: 'same-origin', ...options, headers }); }
        catch (_) { throw new Error('No hay conexión con el servidor.'); }
        let payload;
        try { payload = await response.json(); }
        catch (_) { throw new Error('El servidor entregó una respuesta no válida.'); }
        if (!response.ok || !payload.success) {
            if (response.status === 401) throw new Error('La sesión administrativa venció.');
            if (response.status === 403) throw new Error('Tu rol no permite realizar esta acción.');
            throw new Error(payload.message || 'No fue posible completar la operación.');
        }
        return payload;
    }

    function openModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        state.lastFocused.set(id, document.activeElement);
        modal.hidden = false;
        document.body.classList.add('modal-open');
        modal.querySelector('button, input, select, textarea')?.focus();
    }

    function trapModalFocus(event, modal) {
        const focusable = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])')]
            .filter((element) => !element.hidden && element.offsetParent !== null);
        if (!focusable.length) { event.preventDefault(); modal.focus(); return; }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    }

    function closeModal(id) {
        const modal = document.getElementById(id);
        if (!modal) return;
        modal.hidden = true;
        if (!$('.rd-modal:not([hidden])')) document.body.classList.remove('modal-open');
        const previous = state.lastFocused.get(id);
        if (previous?.isConnected) previous.focus();
    }

    function setBusy(button, busy, label) { if (!button) return; button.disabled = busy; button.textContent = label; }
    function notify(message, type) { if (typeof window.showToast === 'function') window.showToast(message, type); }
    function setText(id, value) { const element = document.getElementById(id); if (element) element.textContent = String(value); }
    function tableMessage(message, columns) { return `<tr><td colspan="${columns}" class="rd-table-message">${escapeHtml(message)}</td></tr>`; }
    function detailLoadingMarkup() { return '<div class="rd-detail-empty"><span class="rd-detail-empty__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 2v4m0 12v4M4.9 4.9l2.8 2.8m8.6 8.6 2.8 2.8M2 12h4m12 0h4M4.9 19.1l2.8-2.8m8.6-8.6 2.8-2.8"/></svg></span><h2>Cargando detalle…</h2><p>Consultando comprobantes y trazabilidad.</p></div>'; }
    function normalizePhotoUrl(value) { if (!value) return ''; if (/^https?:\/\//i.test(value) || value.startsWith('/')) return value; return value.startsWith('uploads/') ? `../${value}` : value; }
    function normalize(value) { return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
    function humanize(value) { return String(value || '').toLowerCase().replaceAll('_', ' ').replace(/^./, (letter) => letter.toUpperCase()); }
    function formatDate(value) { if (!value) return 'Sin fecha'; const parsed = new Date(`${String(value).slice(0, 10)}T12:00:00`); return Number.isNaN(parsed.getTime()) ? String(value) : dateFormatter.format(parsed); }
    function formatDateTime(value) { if (!value) return 'Sin fecha'; const parsed = new Date(String(value).replace(' ', 'T')); return Number.isNaN(parsed.getTime()) ? String(value) : new Intl.DateTimeFormat('es-CL', { dateStyle: 'short', timeStyle: 'short' }).format(parsed); }
    function formatMonth(value) { if (!value) return 'Sin período'; const [year, month] = value.split('-').map(Number); return new Intl.DateTimeFormat('es-CL', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1)); }
    function formatMonthShort(value) { if (!value) return 'Sin período'; const [year, month] = value.split('-').map(Number); const label = new Intl.DateTimeFormat('es-CL', { month: 'short', year: 'numeric' }).format(new Date(year, month - 1, 1)); return label.replace(/^./, (letter) => letter.toUpperCase()); }
    function formatPercent(value) { return new Intl.NumberFormat('es-CL', { maximumFractionDigits: 1 }).format(Number(value || 0)); }
    function formatRelativeTime(value) {
        if (!value) return 'Sin fecha';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return formatDate(value);
        const diff = Date.now() - parsed.getTime();
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(diff / 3600000);
        const days = Math.floor(diff / 86400000);
        if (minutes < 1) return 'Ahora';
        if (minutes < 60) return `Hace ${minutes} min`;
        if (hours < 24) return `Hace ${hours} h`;
        if (days === 1) return 'Ayer';
        if (days < 7) return `Hace ${days} días`;
        return dateFormatter.format(parsed);
    }
    function statusInfo(status) {
        const map = {
            ENVIADA: ['Enviada', 'rd-status--info'],
            PENDIENTE_APROBACION_EXCESO: ['Exceso pendiente', 'rd-status--warning'],
            PENDIENTE_APROBACION_RESPONSABLE: ['En Responsable', 'rd-status--warning'],
            EN_REVISION_TESORERIA: ['En revisión', 'rd-status--info'],
            DOCUMENTOS_FISICOS_RECIBIDOS: ['Físicos recibidos', 'rd-status--warning'],
            APROBADA: ['Aprobada', 'rd-status--success'],
            APROBADA_PARCIAL: ['Aprobada parcial', 'rd-status--success'],
            RECHAZADA: ['Rechazada', 'rd-status--danger'],
            PAGADA: ['Pagada', 'rd-status--success']
        };
        const found = map[status] || [humanize(status), ''];
        return { label: found[0], className: found[1] };
    }
    function escapeHtml(value) { const div = document.createElement('div'); div.textContent = String(value ?? ''); return div.innerHTML; }

    function openEditDocumentModal(docId, provider, amount, number) {
        $('#editDocId').value = docId;
        $('#editDocProvider').textContent = provider || 'Comprobante';
        // Folio
        const numStr = number || '';
        $('#editDocOldNumber').value = numStr;
        $('#editDocNewNumber').value = numStr;
        // Monto
        $('#editDocOldAmount').value = money.format(amount);
        $('#editDocNewAmount').value = Math.round(Number(amount));
        // Motivo
        $('#editDocReason').value = 'Corrección por error de digitación verificada en foto';
        $('#editDocStatus').textContent = '';
        openModal('editDocumentModal');
        // Foco en el campo de folio (primer campo editable)
        setTimeout(() => $('#editDocNewNumber')?.focus(), 80);
    }

    async function saveEditDocument(event) {
        event.preventDefault();
        const docId     = Number($('#editDocId').value);
        const newAmount = Number($('#editDocNewAmount').value);
        const newNumber = ($('#editDocNewNumber')?.value ?? '').trim();
        const oldNumber = ($('#editDocOldNumber')?.value ?? '').trim();
        const reason    = $('#editDocReason').value.trim();
        if (!docId || newAmount <= 0) {
            $('#editDocStatus').textContent = 'El monto debe ser superior a cero.';
            return;
        }
        const requestPayload = { documento_id: docId, nuevo_monto: newAmount, motivo: reason };
        // Enviar nuevo_numero solo si fue modificado
        if (newNumber && newNumber !== oldNumber) {
            requestPayload.nuevo_numero = newNumber;
        }
        const btn = $('#saveEditDocBtn');
        setBusy(btn, true, 'Guardando…');
        try {
            const res = await apiRequest(`${API_BASE}/corregir_documento.php`, {
                method: 'POST',
                body: JSON.stringify(requestPayload)
            });
            closeModal('editDocumentModal');
            notify(res.message, 'success');
            state.detailCache.delete(Number(state.selectedId));
            await selectRendition(Number(state.selectedId), true);
            await loadRenditions();
        } catch (err) {
            $('#editDocStatus').textContent = err.message;
        } finally {
            setBusy(btn, false, 'Guardar corrección');
        }
    }
})();
