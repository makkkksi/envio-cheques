/**
 * admin/admin.js
 * 
 * Lógica JavaScript del Portal de Tesorería — AI_RULES_UX.md
 * Ley de Tesler (Mapeo dinámico de botones), Stepper (Zeigarnik), Lightbox Scroll-Lock
 */

let estadoActualFilter = 'BANDEJA_TRABAJO';
let cobranzaSeleccionadaId = null;
let cobranzasCache = [];

document.addEventListener('DOMContentLoaded', () => {
    initSplitView();
});

// ESC Key Listener para cerrar visores y modales de Tesorería
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' || e.keyCode === 27) {
        cerrarImagenLightbox();
        cerrarModalConfirmacionRecepcion();
        cerrarModalDeposito();
        cerrarModalRechazo();
    }
});

// Toast Helper
function showToast(message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), 4000);
}

// Global Image Lightbox con SCROLL LOCK e INSPECCIÓN AVANZADA (AI_RULES_UX.md)
let lightboxRotacion = 0;
let lightboxAltoContraste = false;

function abrirImagenLightbox(url) {
    let lightbox = document.getElementById('modalLightbox');
    if (!lightbox) {
        lightbox = document.createElement('div');
        lightbox.id = 'modalLightbox';
        lightbox.className = 'modal-overlay';
        lightbox.style.display = 'none';
        lightbox.innerHTML = `
            <div style="position: relative; max-width: 90vw; max-height: 90vh; display: flex; flex-direction: column; align-items: center; gap: 10px;">
                <div style="display: flex; gap: 8px; background: rgba(15, 23, 42, 0.85); padding: 8px 14px; border-radius: 8px; z-index: 10;">
                    <button type="button" class="image-tool-btn" onclick="rotarImagenLightbox()">🔄 Rotar 90°</button>
                    <button type="button" class="image-tool-btn" onclick="toggleAltoContrasteLightbox()">☀️ Alto Contraste</button>
                    <button type="button" class="image-tool-btn" onclick="resetImagenLightbox()">Restablecer</button>
                    <button type="button" onclick="cerrarImagenLightbox()" style="background: #dc2626; color: white; border: none; font-size: 1rem; width: 28px; height: 28px; border-radius: 50%; cursor: pointer; font-weight: bold;">&times;</button>
                </div>
                <div style="overflow: hidden; max-width: 90vw; max-height: 80vh; display: flex; align-items: center; justify-content: center;">
                    <img id="imgLightboxTarget" src="" style="max-width: 100%; max-height: 80vh; border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); object-fit: contain; transition: transform 0.2s ease, filter 0.2s ease;">
                </div>
            </div>
        `;
        document.body.appendChild(lightbox);
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) cerrarImagenLightbox();
        });
    }
    
    lightboxRotacion = 0;
    lightboxAltoContraste = false;
    actualizarTransformacionesImagen();

    document.getElementById('imgLightboxTarget').src = url;
    lightbox.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function rotarImagenLightbox() {
    lightboxRotacion = (lightboxRotacion + 90) % 360;
    actualizarTransformacionesImagen();
}

function toggleAltoContrasteLightbox() {
    lightboxAltoContraste = !lightboxAltoContraste;
    actualizarTransformacionesImagen();
}

function resetImagenLightbox() {
    lightboxRotacion = 0;
    lightboxAltoContraste = false;
    actualizarTransformacionesImagen();
}

function actualizarTransformacionesImagen() {
    const img = document.getElementById('imgLightboxTarget');
    if (!img) return;
    img.style.transform = `rotate(${lightboxRotacion}deg)`;
    if (lightboxAltoContraste) {
        img.classList.add('high-contrast-image');
    } else {
        img.classList.remove('high-contrast-image');
    }
}

function cerrarImagenLightbox() {
    const lightbox = document.getElementById('modalLightbox');
    if (lightbox) lightbox.style.display = 'none';
    document.body.classList.remove('modal-open');
}

// Mapeo Nombres de Estado B2B
const ESTADOS_MAP = {
    'PENDIENTE_ENVIO': { label: 'Por Enviar', class: 'badge-PENDIENTE_ENVIO' },
    'EN_TRANSITO': { label: 'En Tránsito', class: 'badge-EN_TRANSITO' },
    'ENTREGADO_SANTIAGO': { label: 'Entregado Stgo', class: 'badge-ENTREGADO_SANTIAGO' },
    'RECIBIDO_TESORERIA': { label: 'Recibido Tesorería', class: 'badge-RECIBIDO_TESORERIA' },
    'DEPOSITADO': { label: 'Depositado', class: 'badge-DEPOSITADO' },
    'RECHAZADO': { label: 'Rechazado', class: 'badge-RECHAZADO' }
};

// ==========================================
// INICIALIZACIÓN Y EVENTOS
// ==========================================
function initSplitView() {
    const segmentedTabs = document.querySelectorAll('#segmentedTabs .segmented-tab');
    const inputBuscar = document.getElementById('inputBuscarAdmin');
    const selectEmpresa = document.getElementById('selectEmpresaAdmin');
    const selectOrden = document.getElementById('selectOrdenAdmin');

    // Eventos Pestañas Segmentadas
    segmentedTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            segmentedTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            estadoActualFilter = tab.dataset.estado;
            cargarCobranzas();
        });
    });

    // Eventos Filtros
    if (inputBuscar) inputBuscar.addEventListener('input', debounce(cargarCobranzas, 300));
    if (selectEmpresa) selectEmpresa.addEventListener('change', cargarCobranzas);
    if (selectOrden) selectOrden.addEventListener('change', aplicarOrdenamientoYRenderizar);

    const activeTab = document.querySelector('#segmentedTabs .segmented-tab.active');
    if (activeTab && activeTab.dataset.estado) {
        estadoActualFilter = activeTab.dataset.estado;
    }

    cargarCobranzas();
}

// ==========================================
// CARGA Y RENDERIZADO DE TABLA MAESTRA
// ==========================================
function cargarCobranzas() {
    const inputBuscar = document.getElementById('inputBuscarAdmin');
    const selectEmpresa = document.getElementById('selectEmpresaAdmin');

    const params = new URLSearchParams();
    if (estadoActualFilter) params.append('estado', estadoActualFilter);
    if (inputBuscar && inputBuscar.value.trim()) params.append('busqueda', inputBuscar.value.trim());
    if (selectEmpresa && selectEmpresa.value) params.append('empresa_id', selectEmpresa.value);

    fetch(`api/get_cobranzas.php?${params.toString()}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error al cargar datos', 'error');
                return;
            }
            actualizarTabCounters(data.metrics);
            cobranzasCache = data.data || [];
            aplicarOrdenamientoYRenderizar();
        })
        .catch(err => {
            console.error(err);
            showToast('Error de conexión con el servidor', 'error');
        });
}

function setElText(id, val) {
    const el = document.getElementById(id);
    if (el) el.textContent = val;
}

function actualizarTabCounters(metrics) {
    if (!metrics) return;
    setElText('cntBandeja', metrics.bandeja_trabajo || 0);
    setElText('cntEnviados', (metrics.en_transito || 0) + (metrics.recibidos || 0) + (metrics.depositados || 0) + (metrics.rechazados || 0));
    setElText('cntTransito', metrics.en_transito || 0);
    setElText('cntRecibidos', metrics.recibidos || 0);
    setElText('cntDepositados', metrics.depositados || 0);
    setElText('cntRechazados', metrics.rechazados || 0);
    setElText('cntPendientes', metrics.pendientes_envio || 0);
    setElText('cntTotal', metrics.total || 0);
}

function aplicarOrdenamientoYRenderizar() {
    const orden = document.getElementById('selectOrdenAdmin')?.value || 'fecha_desc';
    
    let lista = [...cobranzasCache];

    if (orden === 'fecha_desc') {
        lista.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
    } else if (orden === 'fecha_asc') {
        lista.sort((a, b) => new Date(a.created_at) - new Date(b.created_at));
    } else if (orden === 'monto_desc') {
        lista.sort((a, b) => (b.total_cheques || 0) - (a.total_cheques || 0));
    } else if (orden === 'monto_asc') {
        lista.sort((a, b) => (a.total_cheques || 0) - (b.total_cheques || 0));
    }

    renderMasterTable(lista);
}

function renderMasterTable(cobranzas) {
    const tbody = document.getElementById('masterTableBody');
    if (!tbody) return;

    if (!cobranzas || cobranzas.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px 16px; color: var(--color-text-muted);">
                    No se encontraron cobranzas registradas para los filtros seleccionados.
                </td>
            </tr>
        `;
        deseleccionarCobranza();
        return;
    }

    tbody.innerHTML = cobranzas.map(item => {
        const estConfig = ESTADOS_MAP[item.estado] || { label: item.estado, class: '' };
        const montoFactura = parseFloat(item.monto_total_factura || 0);
        const montoChequesNum = parseFloat(item.total_cheques || 0);
        const montoChequesFmt = montoChequesNum.toLocaleString('es-CL');
        const isSelected = item.id == cobranzaSeleccionadaId;
        const delta = montoChequesNum - montoFactura;
        const tieneDiscrepancia = Math.abs(delta) > 0.01;
        const deltaAbsFmt = Math.abs(delta).toLocaleString('es-CL');
        const difText = `${delta > 0 ? '+$' : '-$'}${deltaAbsFmt}`;
        const vendedorRaw = item.vendedor_nombre ? item.vendedor_nombre.trim() : '';
        const esVendedorUnassigned = !vendedorRaw || vendedorRaw === 'Pendiente' || vendedorRaw === 'Sistema' || vendedorRaw.includes('Vendedor no especificado');
        const vendedorDisplay = esVendedorUnassigned 
            ? `<span style="color: #64748b; font-size: 0.78rem; font-weight: 600;">Sin Asignar</span>` 
            : `<span style="font-weight: 600; color: var(--color-text);">${vendedorRaw}</span>`;

        return `
            <tr class="${isSelected ? 'active-row' : ''}" onclick="seleccionarCobranza(${item.id})">
                <td style="font-weight: 600;">${item.empresa_nombre || '-'}</td>
                <td><strong style="color: var(--color-primary);">N° ${item.numero_factura}</strong></td>
                <td>
                    <div style="font-weight: 600; color: var(--color-text);">${item.razon_social_cliente || '-'}</div>
                    <div style="font-size: 0.78rem; color: var(--color-text-muted);">RUT: ${item.rut_cliente || '-'}</div>
                </td>
                <td>${vendedorDisplay}</td>
                <td>
                    <strong style="color: ${tieneDiscrepancia ? '#92400e' : '#166534'};">$${montoChequesFmt}</strong>
                    ${tieneDiscrepancia ? `<div style="margin-top: 2px;"><span style="display: inline-flex; align-items: center; gap: 3px; font-size: 0.72rem; font-weight: 700; color: #92400e; background: #fef3c7; border: 1px solid #f59e0b; border-radius: 4px; padding: 1px 6px;">⚠️ Dif: ${difText}</span></div>` : ''}
                    <div style="font-size: 0.75rem; color: var(--color-text-muted);">${item.cantidad_cheques} cheque(s)</div>
                </td>
                <td><span class="badge ${estConfig.class}">${estConfig.label}</span></td>
            </tr>
        `;
    }).join('');

    if (cobranzaSeleccionadaId) {
        const existe = cobranzas.some(c => c.id == cobranzaSeleccionadaId);
        if (!existe) deseleccionarCobranza();
    }
}

// ==========================================
// SELECCIÓN Y DRAWER INSPECTOR DERECHO
// ==========================================
function seleccionarCobranza(id) {
    cobranzaSeleccionadaId = id;

    const rows = document.querySelectorAll('#masterTableBody tr');
    rows.forEach(r => r.classList.remove('active-row'));
    const activeRow = Array.from(rows).find(r => r.getAttribute('onclick')?.includes(`(${id})`));
    if (activeRow) activeRow.classList.add('active-row');

    document.getElementById('emptyDetailState').style.display = 'none';
    const activeContent = document.getElementById('activeDetailContent');
    activeContent.style.display = 'flex';

    fetch(`api/get_detalle_cobranza.php?id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.data) {
                showToast(data.message || 'Error al cargar el detalle', 'error');
                return;
            }
            renderSidePanelDetail(data.data);
        })
        .catch(err => {
            console.error(err);
            showToast('Error al obtener el detalle de la cobranza', 'error');
        });
}

function deseleccionarCobranza() {
    cobranzaSeleccionadaId = null;
    const rows = document.querySelectorAll('#masterTableBody tr');
    rows.forEach(r => r.classList.remove('active-row'));

    document.getElementById('emptyDetailState').style.display = 'flex';
    document.getElementById('activeDetailContent').style.display = 'none';
}

function renderSidePanelDetail(data) {
    const cob = data.cobranza;
    const cheques = data.cheques || [];
    const historial = data.historial || [];

    // Header
    document.getElementById('lblPanelFacturaTitle').textContent = `Factura N° ${cob.numero_factura}`;
    const estConfig = ESTADOS_MAP[cob.estado] || { label: cob.estado, class: '' };
    const badgeEl = document.getElementById('lblPanelEstadoBadge');
    badgeEl.textContent = estConfig.label;
    badgeEl.className = `badge ${estConfig.class}`;

    // Sección 1: Resumen Factura / Cliente & ALERTA POR DISCREPANCIA (VON RESTORFF)
    const montoFacturaVal = parseFloat(cob.monto_total_factura || 0);
    const montoChequesVal = parseFloat(cob.total_cheques || 0);
    const deltaVal = montoChequesVal - montoFacturaVal;
    const tieneMismatch = Math.abs(deltaVal) > 0.01;

    document.getElementById('lblPanelEmpresa').textContent = cob.empresa_nombre || '-';
    document.getElementById('lblPanelRut').textContent = cob.rut_cliente || '-';
    document.getElementById('lblPanelCliente').textContent = cob.razon_social_cliente || '-';
    document.getElementById('lblPanelMontoFactura').textContent = '$' + montoFacturaVal.toLocaleString('es-CL');
    
    const elTotalCheques = document.getElementById('lblPanelTotalCheques');
    if (tieneMismatch) {
        elTotalCheques.textContent = '$' + montoChequesVal.toLocaleString('es-CL');
        elTotalCheques.style.color = '#92400e';
    } else {
        elTotalCheques.textContent = '$' + montoChequesVal.toLocaleString('es-CL') + ' (Conciliado Exacto)';
        elTotalCheques.style.color = '#166534';
    }

    const vendedorNombreRaw = cob.vendedor_nombre ? cob.vendedor_nombre.trim() : '';
    const esUnassigned = !vendedorNombreRaw || vendedorNombreRaw === 'Pendiente' || vendedorNombreRaw === 'Sistema' || vendedorNombreRaw.includes('Vendedor no especificado');

    const lblVendedorEl = document.getElementById('lblPanelVendedor');
    if (esUnassigned) {
        lblVendedorEl.innerHTML = `<span class="seller-badge-muted">Vendedor: Sin Asignar (Registro Automático)</span>`;
    } else {
        lblVendedorEl.textContent = vendedorNombreRaw;
    }

    // Render Callout de Alerta por Discrepancia si aplica
    let boxDiscrepancia = document.getElementById('boxDiscrepanciaCallout');
    if (tieneMismatch) {
        if (!boxDiscrepancia) {
            boxDiscrepancia = document.createElement('div');
            boxDiscrepancia.id = 'boxDiscrepanciaCallout';
            boxDiscrepancia.className = 'discrepancy-callout';
            const panelSec1 = document.querySelector('.panel-section');
            if (panelSec1) panelSec1.prepend(boxDiscrepancia);
        }

        // Buscar comentario de vendedor para justificación
        const comentarioVendedor = cheques.find(c => c.comentario && c.comentario.trim() !== '')?.comentario || null;

        boxDiscrepancia.style.display = 'block';
        boxDiscrepancyHtml = `
            <div class="discrepancy-callout-header">
                <span>⚠️ ALERTA DE DISCREPANCIA DE MONTOS</span>
                <span>Diferencia: ${deltaVal > 0 ? '+' : ''}$${deltaVal.toLocaleString('es-CL')}</span>
            </div>
            <div class="discrepancy-callout-body">
                <div>El total registrado en cheques (<strong>$${montoChequesVal.toLocaleString('es-CL')}</strong>) no coincide con el monto de la factura ERP (<strong>$${montoFacturaVal.toLocaleString('es-CL')}</strong>).</div>
                ${comentarioVendedor ? `<div style="margin-top: 6px; font-style: italic; background: rgba(255,255,255,0.6); padding: 6px 10px; border-radius: 4px;"><strong>Justificación Vendedor:</strong> "${comentarioVendedor}"</div>` : '<div style="margin-top: 4px; opacity: 0.85;">Sin comentario de justificación registrado por el vendedor.</div>'}
            </div>
        `;
        boxDiscrepancia.innerHTML = boxDiscrepancyHtml;
    } else {
        if (boxDiscrepancia) boxDiscrepancia.style.display = 'none';
    }

    // Sección 2: Despacho
    const tipoEntrega = cob.tipo_entrega ? (cob.tipo_entrega === 'CHILEXPRESS' ? 'Chilexpress' : 'Entrega Presencial Stgo') : 'Pendiente';
    document.getElementById('lblPanelTipoEntrega').textContent = tipoEntrega;
    document.getElementById('lblPanelSeguimiento').textContent = cob.numero_seguimiento ? `OT: ${cob.numero_seguimiento}` : '-';

    const boxComprobante = document.getElementById('boxPanelComprobante');
    if (cob.comprobante_url) {
        boxComprobante.innerHTML = `
            <div class="cheque-16by9-box" style="max-height: 120px;" onclick="abrirImagenLightbox('../${cob.comprobante_url}')">
                <img src="../${cob.comprobante_url}" alt="Comprobante">
                <div class="cheque-16by9-hover">Clic para ampliar comprobante</div>
            </div>
        `;
    } else {
        boxComprobante.innerHTML = '<span style="font-size: 0.78rem; color: var(--color-text-muted);">Sin comprobante adjunto</span>';
    }

    // Sección 3: Tarjetas de Inspección de Cheques (16:9 + Proximidad)
    document.getElementById('lblPanelCntCheques').textContent = cheques.length;
    const boxCheques = document.getElementById('boxPanelChequesList');
    if (cheques.length === 0) {
        boxCheques.innerHTML = '<span style="font-size: 0.8rem; color: var(--color-text-muted);">No hay cheques registrados.</span>';
    } else {
        boxCheques.innerHTML = cheques.map(chq => `
            <div class="cheque-inspection-card">
                <div class="cheque-16by9-box" onclick="abrirImagenLightbox('../${chq.foto_cheque_url}')">
                    <img src="../${chq.foto_cheque_url}" alt="Foto Cheque ${chq.numero_cheque}">
                    <div class="cheque-16by9-hover">Clic para ampliar foto del cheque</div>
                </div>
                <div class="cheque-card-meta">
                    <div class="cheque-card-meta-row">
                        <span class="cheque-banco-name">${chq.banco}</span>
                        <span class="cheque-monto-value">$${parseFloat(chq.monto).toLocaleString('es-CL')}</span>
                    </div>
                    <div style="font-size: 0.8rem; color: var(--color-text-secondary);">
                        N° Cheque: <strong>${chq.numero_cheque}</strong> | Vencimiento: <strong>${chq.fecha_vencimiento}</strong>
                    </div>
                    ${chq.comentario ? `
                        <div class="quote-comment">
                            "${chq.comentario}"
                        </div>
                    ` : ''}
                    ${chq.numero_papeleta_deposito ? `
                        <div style="font-size: 0.78rem; color: #166534; font-weight: 700; margin-top: 2px;">
                            Papeleta Depósito: N° ${chq.numero_papeleta_deposito}
                        </div>
                    ` : ''}
                </div>
            </div>
        `).join('');
    }

    // Sección 4: Vertical Stepper de Trazabilidad (Ley de Zeigarnik)
    renderVerticalStepper(cob, historial);

    // Sección 5: Barra de Acciones Fija Inferior (Ley de Tesler & Von Restorff)
    renderStickyActionButtons(cob);
}

// RENDERIZADOR DE STEPPER VERTICAL TIMELINE (LEY DE ZEIGARNIK)
function renderVerticalStepper(cob, historial) {
    const container = document.getElementById('boxPanelStepper');
    if (!container) return;

    container.className = 'stepper-vertical';

    const pasos = [
        { key: 'PENDIENTE_ENVIO', label: '1. Registrado por Vendedor' },
        { key: 'EN_TRANSITO', label: '2. En Tránsito / Despachado' },
        { key: 'RECIBIDO_TESORERIA', label: '3. Recepción en Tesorería' },
        { key: 'DEPOSITADO', label: '4. Depositado en Banco' }
    ];

    let currentIdx = 0;
    if (cob.estado === 'EN_TRANSITO' || cob.estado === 'ENTREGADO_SANTIAGO') currentIdx = 1;
    if (cob.estado === 'RECIBIDO_TESORERIA') currentIdx = 2;
    if (cob.estado === 'DEPOSITADO') currentIdx = 3;
    if (cob.estado === 'RECHAZADO') currentIdx = 3;

    container.innerHTML = pasos.map((paso, idx) => {
        let isCompleted = idx < currentIdx;
        let isActive = idx === currentIdx;
        let stepClass = isCompleted ? 'completed' : (isActive ? 'active' : '');
        let circleSymbol = isCompleted ? '✓' : (idx + 1);

        if (cob.estado === 'RECHAZADO' && idx === 3) {
            paso.label = '4. Cheque Rechazado / Protestado';
            stepClass = 'active';
            circleSymbol = '✖';
        }

        const hitHist = historial.find(h => h.estado_nuevo === paso.key || (paso.key === 'EN_TRANSITO' && h.estado_nuevo === 'ENTREGADO_SANTIAGO'));
        const timeText = hitHist ? hitHist.created_at.replace('T', ' ') : (isCompleted || isActive ? 'Completado' : 'Pendiente');

        return `
            <div class="stepper-step ${stepClass}">
                <div class="stepper-node">
                    <div class="stepper-circle">${circleSymbol}</div>
                    <div class="stepper-line"></div>
                </div>
                <div class="stepper-content">
                    <div class="stepper-title">${paso.label}</div>
                    <div class="stepper-time">${timeText}</div>
                </div>
            </div>
        `;
    }).join('');
}

// RENDERIZADOR DE BOTONES DINÁMICOS (LEY DE TESLER & VON RESTORFF)
function renderStickyActionButtons(cob) {
    const container = document.getElementById('boxPanelAcciones');
    if (!container) return;

    let html = '';

    // REGLA ESTRICTA TESLER:
    // Si está en EN_TRANSITO o ENTREGADO_SANTIAGO: ÚNICAMENTE CTA Primario "✓ Marcar Recibido". Ocultar Depósito y Rechazo.
    if (cob.estado === 'EN_TRANSITO' || cob.estado === 'ENTREGADO_SANTIAGO') {
        html = `
            <button type="button" class="btn-b2b btn-b2b-success" style="width: 100%;" onclick="pedirConfirmacionRecepcion(${cob.id}, '${cob.numero_factura}')">
                Confirmar Recepción Física en Tesorería
            </button>
        `;
    } 
    // Si ya está RECIBIDO_TESORERIA: Mostrar "Registrar Depósito" y "Rechazar"
    else if (cob.estado === 'RECIBIDO_TESORERIA') {
        html = `
            <button type="button" class="btn-b2b btn-b2b-primary" onclick="abrirModalDeposito(${cob.id})">
                Registrar Depósito
            </button>
            <button type="button" class="btn-b2b btn-b2b-danger" onclick="abrirModalRechazo(${cob.id})">
                Rechazar
            </button>
        `;
    } 
    // Si ya fue DEPOSITADO o RECHAZADO: Deshabilitar acciones (Estado Final Inmutable)
    else if (cob.estado === 'DEPOSITADO') {
        html = `<span style="font-size: 0.85rem; color: #166534; font-weight: 700;">Cobranza Depositada Exitosamente</span>`;
    } else if (cob.estado === 'RECHAZADO') {
        html = `<span style="font-size: 0.85rem; color: #dc2626; font-weight: 700;">Documento Rechazado / Protestado</span>`;
    } else {
        html = `<span style="font-size: 0.82rem; color: var(--color-text-muted); font-weight: 600;">Esperando despacho del vendedor (${cob.estado})</span>`;
    }

    container.innerHTML = html;
}

// Modal Confirmación Recepción Física
let confirmacionRecepcionId = null;
function pedirConfirmacionRecepcion(id, numFactura) {
    let modal = document.getElementById('modalConfirmacionRecepcion');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalConfirmacionRecepcion';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-box">
                <h3>Confirmar Recepción Física</h3>
                <p style="font-size: 0.85rem; color: var(--color-text-secondary); margin-top: 8px;">¿Deseas confirmar la recepción física de los cheques de la <strong id="lblConfirmNumFactura" style="color: var(--color-primary);">Factura N° -</strong> en las oficinas de Tesorería?</p>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn-b2b" style="background: #e2e8f0; color: #334155;" onclick="cerrarModalConfirmacionRecepcion()">Cancelar</button>
                    <button type="button" class="btn-b2b btn-b2b-success" id="btnConfirmarRecepcionSubmit">Confirmar Recepción</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    }

    document.getElementById('lblConfirmNumFactura').textContent = `Factura N° ${numFactura}`;
    const btnSubmit = document.getElementById('btnConfirmarRecepcionSubmit');
    btnSubmit.onclick = () => {
        ejecutarCambioEstado(id, 'RECIBIDO_TESORERIA');
        cerrarModalConfirmacionRecepcion();
    };

    modal.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function cerrarModalConfirmacionRecepcion() {
    const modal = document.getElementById('modalConfirmacionRecepcion');
    if (modal) modal.style.display = 'none';
    document.body.classList.remove('modal-open');
}
function abrirModalDeposito(id) {
    let modal = document.getElementById('modalDeposito');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalDeposito';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-box">
                <h3>Registrar Depósito Bancario</h3>
                <p style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 14px;">Ingrese la información del depósito para cerrar esta cobranza.</p>
                <form id="formDepositoModal">
                    <input type="hidden" id="depositoCobranzaId">
                    <div class="modal-form-group">
                        <label>N° Papeleta de Depósito *</label>
                        <input type="text" id="inputPapeleta" placeholder="Ej: 984521" required>
                    </div>
                    <div class="modal-form-group">
                        <label>Fecha Real de Depósito (Opcional)</label>
                        <input type="date" id="inputFechaDeposito">
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-b2b btn-b2b-secondary" onclick="cerrarModalDeposito()">Cancelar</button>
                        <button type="submit" class="btn-b2b btn-b2b-success">Confirmar Depósito</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);

        document.getElementById('formDepositoModal').addEventListener('submit', (e) => {
            e.preventDefault();
            const idVal = document.getElementById('depositoCobranzaId').value;
            const papeletaVal = document.getElementById('inputPapeleta').value.trim();
            const fechaVal = document.getElementById('inputFechaDeposito').value;

            if (!papeletaVal) {
                showToast('Ingrese el N° de papeleta', 'error');
                return;
            }

            ejecutarCambioEstado(idVal, 'DEPOSITADO', {
                numero_papeleta_deposito: papeletaVal,
                fecha_deposito_real: fechaVal
            });
            cerrarModalDeposito();
        });
    }

    document.getElementById('depositoCobranzaId').value = id;
    document.getElementById('inputPapeleta').value = '';
    document.getElementById('inputFechaDeposito').value = '';
    modal.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function cerrarModalDeposito() {
    const modal = document.getElementById('modalDeposito');
    if (modal) modal.style.display = 'none';
    document.body.classList.remove('modal-open');
}

function abrirModalRechazo(id) {
    let modal = document.getElementById('modalRechazo');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalRechazo';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-box">
                <h3 style="color: #dc2626;">Marcar Cheque Rechazado</h3>
                <p style="font-size: 0.8rem; color: var(--color-text-secondary); margin-bottom: 14px;">Ingrese el motivo o causal del rechazo/protesto del documento.</p>
                <form id="formRechazoModal">
                    <input type="hidden" id="rechazoCobranzaId">
                    <div class="modal-form-group">
                        <label>Motivo del Rechazo *</label>
                        <textarea id="inputMotivoRechazo" rows="3" placeholder="Ej: Firma no conforme, falta de fondos..." required></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-b2b btn-b2b-secondary" onclick="cerrarModalRechazo()">Cancelar</button>
                        <button type="submit" class="btn-b2b btn-b2b-danger">Confirmar Rechazo</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);

        document.getElementById('formRechazoModal').addEventListener('submit', (e) => {
            e.preventDefault();
            const idVal = document.getElementById('rechazoCobranzaId').value;
            const motivoVal = document.getElementById('inputMotivoRechazo').value.trim();

            if (!motivoVal) {
                showToast('Ingrese el motivo del rechazo', 'error');
                return;
            }

            ejecutarCambioEstado(idVal, 'RECHAZADO', {
                comentario: motivoVal
            });
            cerrarModalRechazo();
        });
    }

    document.getElementById('rechazoCobranzaId').value = id;
    document.getElementById('inputMotivoRechazo').value = '';
    modal.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function cerrarModalRechazo() {
    const modal = document.getElementById('modalRechazo');
    if (modal) modal.style.display = 'none';
    document.body.classList.remove('modal-open');
}

// Ejecutar Cambio de Estado AJAX
function ejecutarCambioEstado(id, nuevoEstado, extraData = {}) {
    const formData = new FormData();
    formData.append('cobranza_id', id);
    formData.append('nuevo_estado', nuevoEstado);

    for (const key in extraData) {
        if (extraData[key]) formData.append(key, extraData[key]);
    }

    fetch('api/cambiar_estado.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showToast(data.message || 'Error al actualizar el estado', 'error');
            return;
        }
        showToast(data.message || 'Estado actualizado con éxito', 'success');
        
        // Si la cobranza pasó a DEPOSITADO o RECHAZADO, trasladar al usuario a esa pestaña automáticamente
        if (nuevoEstado === 'DEPOSITADO' || nuevoEstado === 'RECHAZADO') {
            const targetTabState = nuevoEstado;
            const targetTabEl = document.querySelector(`.segmented-tab[data-estado="${targetTabState}"]`);
            if (targetTabEl) {
                document.querySelectorAll('.segmented-tab').forEach(t => t.classList.remove('active'));
                targetTabEl.classList.add('active');
                estadoActualFilter = targetTabState;
            }
        }

        cargarCobranzas();
        if (cobranzaSeleccionadaId == id) {
            seleccionarCobranza(id);
        }
    })
    .catch(err => {
        console.error(err);
        showToast('Error de conexión al guardar el cambio', 'error');
    });
}

// Helper Debounce
function debounce(func, wait) {
    let timeout;
    return function (...args) {
        clearTimeout(timeout);
        timeout = setTimeout(() => func.apply(this, args), wait);
    };
}
