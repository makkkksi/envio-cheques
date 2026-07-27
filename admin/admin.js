/**
 * admin/admin.js
 * 
 * Lógica JavaScript del Portal de Tesorería — AI_RULES_UX.md
 * Ley de Tesler (Mapeo dinámico de botones), Stepper (Zeigarnik), Lightbox Scroll-Lock
 */

let estadoActualFilter = 'BANDEJA_TRABAJO';
let cobranzaSeleccionadaId = null;
let cobranzasCache = [];

function reajustarFiltros() {
    const inputBuscar = document.getElementById('inputBuscarAdmin');
    if (inputBuscar) inputBuscar.value = '';
    const selectEmpresa = document.getElementById('selectEmpresaAdmin');
    if (selectEmpresa) selectEmpresa.value = '';
    const selectOrden = document.getElementById('selectOrdenAdmin');
    if (selectOrden) selectOrden.value = 'fecha_desc';
    
    const tabs = document.querySelectorAll('.segmented-tab');
    tabs.forEach(t => t.classList.remove('active'));
    const firstTab = document.querySelector('.segmented-tab[data-estado="BANDEJA_TRABAJO"]');
    if (firstTab) firstTab.classList.add('active');
    
    estadoActualFilter = 'BANDEJA_TRABAJO';
    cargarCobranzas('BANDEJA_TRABAJO');
}

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

// ==========================================
// VISOR DE IMÁGENES (Lightbox) — Zoom, Rotar, Arrastrar
// ==========================================
let lbRotation = 0;
let lbZoom = 1;
let lbContrast = false;
let lbPanX = 0;
let lbPanY = 0;
let lbDragging = false;
let lbDragStartX = 0;
let lbDragStartY = 0;
let lbPanStartX = 0;
let lbPanStartY = 0;

function abrirImagenLightbox(url) {
    let lightbox = document.getElementById('modalLightbox');
    if (!lightbox) {
        lightbox = document.createElement('div');
        lightbox.id = 'modalLightbox';
        lightbox.className = 'modal-overlay';
        lightbox.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(15,23,42,0.75); backdrop-filter:blur(4px); z-index:9999; flex-direction:column; align-items:center; justify-content:center;';
        lightbox.innerHTML = `
            <div style="display:flex; gap:8px; background:rgba(15,23,42,0.9); padding:8px 14px; border-radius:8px; flex-wrap:wrap; justify-content:center; margin-bottom:10px; z-index:10;">
                <button type="button" class="image-tool-btn" onclick="rotarImagenLightbox()">🔄 Rotar 90°</button>
                <button type="button" class="image-tool-btn" onclick="zoomInLightbox()">➕ Zoom</button>
                <button type="button" class="image-tool-btn" onclick="zoomOutLightbox()">➖ Zoom</button>
                <button type="button" class="image-tool-btn" onclick="toggleAltoContrasteLightbox()">☀️ Alto Contraste</button>
                <button type="button" class="image-tool-btn" onclick="resetImagenLightbox()">Restablecer</button>
                <button type="button" class="image-tool-btn" onclick="descargarImagenLightbox()">📥 Descargar</button>
                <button type="button" onclick="cerrarImagenLightbox()" style="background:#dc2626; color:white; border:none; font-size:1rem; width:28px; height:28px; border-radius:50%; cursor:pointer; font-weight:bold;">&times;</button>
            </div>
            <div id="lightboxImageWrapper" style="position:relative; overflow:hidden; width:90vw; height:80vh; border-radius:8px; background:rgba(0,0,0,0.15); cursor:grab;">
                <img id="imgLightboxTarget" src="" style="position:absolute; top:50%; left:50%; transform-origin:center center; border-radius:6px; box-shadow:0 10px 30px rgba(0,0,0,0.5); user-select:none; -webkit-user-drag:none; pointer-events:none;">
            </div>
            <div id="lightboxZoomLabel" style="position:fixed; bottom:16px; right:16px; background:rgba(15,23,42,0.85); color:#94a3b8; padding:4px 12px; border-radius:6px; font-size:0.8rem; font-weight:600; z-index:10;">100%</div>
        `;
        document.body.appendChild(lightbox);

        // Cerrar al hacer click en el fondo
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) cerrarImagenLightbox();
        });

        const wrapper = document.getElementById('lightboxImageWrapper');

        // --- DRAG (Mouse) ---
        wrapper.addEventListener('mousedown', (e) => {
            lbDragging = true;
            wrapper.style.cursor = 'grabbing';
            lbDragStartX = e.clientX;
            lbDragStartY = e.clientY;
            lbPanStartX = lbPanX;
            lbPanStartY = lbPanY;
            e.preventDefault();
        });

        window.addEventListener('mousemove', (e) => {
            if (!lbDragging) return;
            lbPanX = lbPanStartX + (e.clientX - lbDragStartX);
            lbPanY = lbPanStartY + (e.clientY - lbDragStartY);
            aplicarTransformLightbox();
        });

        window.addEventListener('mouseup', () => {
            if (lbDragging) {
                lbDragging = false;
                const wrapper = document.getElementById('lightboxImageWrapper');
                if (wrapper) wrapper.style.cursor = 'grab';
            }
        });

        // --- DRAG (Touch para móviles) ---
        wrapper.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) {
                lbDragging = true;
                lbDragStartX = e.touches[0].clientX;
                lbDragStartY = e.touches[0].clientY;
                lbPanStartX = lbPanX;
                lbPanStartY = lbPanY;
            }
        }, { passive: true });

        wrapper.addEventListener('touchmove', (e) => {
            if (!lbDragging || e.touches.length !== 1) return;
            lbPanX = lbPanStartX + (e.touches[0].clientX - lbDragStartX);
            lbPanY = lbPanStartY + (e.touches[0].clientY - lbDragStartY);
            aplicarTransformLightbox();
            e.preventDefault();
        }, { passive: false });

        wrapper.addEventListener('touchend', () => { lbDragging = false; });

        // --- ZOOM con rueda del mouse ---
        wrapper.addEventListener('wheel', (e) => {
            e.preventDefault();
            if (e.deltaY < 0) {
                lbZoom = Math.min(lbZoom + 0.15, 5);
            } else {
                lbZoom = Math.max(lbZoom - 0.15, 0.3);
            }
            aplicarTransformLightbox();
        }, { passive: false });
    }

    // Reset state
    lbRotation = 0;
    lbZoom = 1;
    lbContrast = false;
    lbPanX = 0;
    lbPanY = 0;

    const img = document.getElementById('imgLightboxTarget');
    img.onload = () => aplicarTransformLightbox();
    img.src = url;
    if (img.complete && img.naturalWidth) aplicarTransformLightbox();

    lightbox.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function aplicarTransformLightbox() {
    const img = document.getElementById('imgLightboxTarget');
    const label = document.getElementById('lightboxZoomLabel');
    if (!img) return;

    const nw = img.naturalWidth;
    const nh = img.naturalHeight;
    if (!nw || !nh) return;

    const wrapper = document.getElementById('lightboxImageWrapper');
    const wW = wrapper.clientWidth;
    const wH = wrapper.clientHeight;

    // Calcular tamaño base que quepa en el contenedor respetando aspect ratio
    const fitScale = Math.min(wW / nw, wH / nh);
    const baseW = nw * fitScale;
    const baseH = nh * fitScale;

    img.style.width = baseW + 'px';
    img.style.height = baseH + 'px';

    // Aplicar transform: translate para centrar + pan, luego scale y rotate
    const tx = -baseW / 2 + lbPanX;
    const ty = -baseH / 2 + lbPanY;
    img.style.transform = `translate(${tx}px, ${ty}px) scale(${lbZoom}) rotate(${lbRotation}deg)`;

    // Alto contraste
    if (lbContrast) {
        img.classList.add('high-contrast-image');
    } else {
        img.classList.remove('high-contrast-image');
    }

    // Indicador de zoom
    if (label) label.textContent = Math.round(lbZoom * 100) + '%';
}

function rotarImagenLightbox() {
    lbRotation = (lbRotation + 90) % 360;
    aplicarTransformLightbox();
}

function zoomInLightbox() {
    lbZoom = Math.min(lbZoom + 0.25, 5);
    aplicarTransformLightbox();
}

function zoomOutLightbox() {
    lbZoom = Math.max(lbZoom - 0.25, 0.3);
    aplicarTransformLightbox();
}

function toggleAltoContrasteLightbox() {
    lbContrast = !lbContrast;
    aplicarTransformLightbox();
}

function resetImagenLightbox() {
    lbRotation = 0;
    lbZoom = 1;
    lbContrast = false;
    lbPanX = 0;
    lbPanY = 0;
    aplicarTransformLightbox();
}

function cerrarImagenLightbox() {
    const lightbox = document.getElementById('modalLightbox');
    if (lightbox) lightbox.style.display = 'none';
    document.body.classList.remove('modal-open');
}

function descargarImagenLightbox() {
    const img = document.getElementById('imgLightboxTarget');
    if (!img || !img.src) return;
    
    const a = document.createElement('a');
    a.href = img.src;
    const filename = img.src.substring(img.src.lastIndexOf('/') + 1);
    a.download = filename || 'imagen_cobranza.jpg';
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// Mapeo Nombres de Estado B2B
const ESTADOS_MAP = {
    'PENDIENTE_ENVIO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>Por Enviar', class: 'badge-PENDIENTE_ENVIO' },
    'EN_TRANSITO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>En Tránsito', class: 'badge-EN_TRANSITO' },
    'ENTREGADO_SANTIAGO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>Entregado Stgo', class: 'badge-ENTREGADO_SANTIAGO' },
    'RECIBIDO_TESORERIA': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>Recibido Fisicamente', class: 'badge-RECIBIDO_TESORERIA' },
    'DEPOSITADO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>Depositado', class: 'badge-DEPOSITADO' },
    'RECHAZADO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>Rechazado', class: 'badge-RECHAZADO' }
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
                <td colspan="5" style="text-align: center; padding: 40px 16px; color: var(--color-text-muted);">
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
            ? `<span class="seller-badge-muted">Sin Asignar (Registro Auto)</span>` 
            : `<span style="font-weight: 600; color: var(--color-text);">${vendedorRaw}</span>`;

        return `
            <tr class="${isSelected ? 'active-row' : ''}" onclick="seleccionarCobranza(${item.id})">
                <td style="font-weight: 600;">${item.empresa_nombre || '-'}</td>
                <td>${vendedorDisplay}</td>
                <td>
                    <div style="font-weight: 600; color: var(--color-text);">${item.razon_social_cliente || '-'}</div>
                    <div style="font-size: 0.78rem; color: var(--color-text-muted);">RUT: ${item.rut_cliente || '-'}</div>
                </td>
                <td>
                    <div style="font-weight: 700; color: #0F172A; white-space: nowrap;">$${montoChequesFmt} <span style="color: #64748B; font-size: 0.8rem; font-weight: normal; margin-left: 4px;">${item.cantidad_cheques} cheque(s)</span></div>
                    ${tieneDiscrepancia ? `<span class="badge-mismatch">⚠️ Dif: ${difText}</span>` : ''}
                </td>
                <td><span class="badge ${estConfig.class}">${estConfig.label}</span></td>
            </tr>
        `;
    }).join('');

    // Auto-seleccionar primer elemento si no hay selección activa
    if (cobranzas && cobranzas.length > 0) {
        if (!cobranzaSeleccionadaId || !cobranzas.some(c => c.id == cobranzaSeleccionadaId)) {
            seleccionarCobranza(cobranzas[0].id);
        }
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
    badgeEl.innerHTML = estConfig.label;
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
            <div class="cheque-card-img-wrapper" onclick="abrirImagenLightbox('../${cob.comprobante_url}')">
                <div class="cheque-controls-overlay">
                    <button type="button" class="btn-cheque-control" onclick="event.stopPropagation(); abrirImagenLightbox('../${cob.comprobante_url}')">🔍 Lightbox</button>
                    <button type="button" class="btn-cheque-control" onclick="event.stopPropagation(); abrirImagenLightbox('../${cob.comprobante_url}'); setTimeout(() => rotarImagenLightbox(), 100)">🔄 Rotar</button>
                </div>
                <img class="cheque-card-img" src="../${cob.comprobante_url}" alt="Comprobante">
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
                <div class="cheque-card-img-wrapper" onclick="abrirImagenLightbox('../${chq.foto_cheque_url}')">
                    <div class="cheque-controls-overlay">
                        <button type="button" class="btn-cheque-control" onclick="event.stopPropagation(); abrirImagenLightbox('../${chq.foto_cheque_url}')">🔍 Lightbox</button>
                        <button type="button" class="btn-cheque-control" onclick="event.stopPropagation(); abrirImagenLightbox('../${chq.foto_cheque_url}'); setTimeout(() => rotarImagenLightbox(), 100)">🔄 Rotar</button>
                    </div>
                    <img class="cheque-card-img" src="../${chq.foto_cheque_url}" alt="Foto Cheque ${chq.numero_cheque}">
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

    // Sección 5: Barra de Acciones Fija Inferior (Ley de Tesler & Von Restorff)
    renderStickyActionButtons(cob);
}

// RENDERIZADOR DE BOTONES DINÁMICOS (LEY DE TESLER & VON RESTORFF)
function renderStickyActionButtons(cob) {
    const container = document.getElementById('boxPanelAcciones');
    if (!container) return;

    // Stepper Horizontal Compacto (Ley de Zeigarnik — sobre CTA)
    const stepperHtml = renderHorizontalStepper(cob);

    let ctaHtml = '';

    // REGLA ESTRICTA TESLER:
    // Si está en EN_TRANSITO o ENTREGADO_SANTIAGO: ÚNICAMENTE CTA Primario "✓ Marcar Recibido". Ocultar Depósito y Rechazo.
    if (cob.estado === 'EN_TRANSITO' || cob.estado === 'ENTREGADO_SANTIAGO') {
        ctaHtml = `
            <button type="button" class="btn-b2b btn-b2b-success" style="width: 100%;" onclick="pedirConfirmacionRecepcion(${cob.id}, '${cob.numero_factura}')">
                Confirmar Recepción Física en Tesorería
            </button>
        `;
    } 
    // Si ya está RECIBIDO_TESORERIA: Mostrar "Registrar Depósito" y "Rechazar"
    else if (cob.estado === 'RECIBIDO_TESORERIA') {
        ctaHtml = `
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
        ctaHtml = `<span style="font-size: 0.85rem; color: #166534; font-weight: 700;">Cobranza Depositada Exitosamente</span>`;
    } else if (cob.estado === 'RECHAZADO') {
        ctaHtml = `<span style="font-size: 0.85rem; color: #dc2626; font-weight: 700;">Documento Rechazado / Protestado</span>`;
    } else {
        ctaHtml = `<span style="font-size: 0.82rem; color: var(--color-text-muted); font-weight: 600;">Esperando despacho del vendedor (${cob.estado})</span>`;
    }

    container.innerHTML = `
        ${stepperHtml}
        <div style="display: flex; gap: 10px; align-items: center;">
            ${ctaHtml}
        </div>
    `;
}

// STEPPER HORIZONTAL COMPACTO (LEY DE ZEIGARNIK)
function renderHorizontalStepper(cob) {
    const pasos = [
        { key: 'PENDIENTE_ENVIO', label: 'Registrado' },
        { key: 'EN_TRANSITO', label: 'En Tránsito' },
        { key: 'RECIBIDO_TESORERIA', label: 'Recepción' },
        { key: 'DEPOSITADO', label: 'Depositado' }
    ];

    let currentIdx = 0;
    if (cob.estado === 'EN_TRANSITO' || cob.estado === 'ENTREGADO_SANTIAGO') currentIdx = 1;
    if (cob.estado === 'RECIBIDO_TESORERIA') currentIdx = 2;
    if (cob.estado === 'DEPOSITADO') currentIdx = 3;
    if (cob.estado === 'RECHAZADO') currentIdx = 3;

    let html = '<div class="stepper-horizontal">';
    pasos.forEach((paso, idx) => {
        const isCompleted = idx < currentIdx;
        const isActive = idx === currentIdx;
        const isRejected = cob.estado === 'RECHAZADO' && idx === 3;
        let stepClass = isCompleted ? 'completed' : (isActive ? 'active' : '');
        if (isActive && cob.estado !== 'DEPOSITADO' && cob.estado !== 'RECHAZADO') {
            stepClass += ' active-intermediate';
        }
        if (isRejected) stepClass = 'rejected';

        let symbol = isCompleted ? '✓' : (isRejected ? '✖' : (isActive ? '●' : (idx + 1)));
        let label = isRejected ? 'Rechazado' : paso.label;

        html += `
            <div class="stepper-h-step ${stepClass}">
                <div class="stepper-h-circle">${symbol}</div>
                <span class="stepper-h-label">${label}</span>
            </div>
        `;
        if (idx < pasos.length - 1) {
            html += `<div class="stepper-h-connector ${isCompleted ? 'completed' : ''}"></div>`;
        }
    });
    html += '</div>';
    return html;
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
