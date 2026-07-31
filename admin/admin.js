/**
 * admin/admin.js
 * 
 * Lógica JavaScript del Portal de Tesorería — AI_RULES_UX.md
 * Ley de Tesler (Mapeo dinámico de botones), Stepper (Zeigarnik), Lightbox Scroll-Lock
 */

let estadoActualFilter = 'BANDEJA_TRABAJO';
let cobranzaSeleccionadaId = null;
let cobranzasCache = [];

const EMPRESAS_NOMBRES = {
    'EMP01': 'Automarco LTDA',
    'EMP03': 'Autotec S.A',
    'EMP06': 'HD Automarco S.A',
    'EMP10': 'Gabtec S.A'
};


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
    'RECIBIDO_TESORERIA': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>En Cola C.Corrientes', class: 'badge-RECIBIDO_TESORERIA' },
    'DEPOSITADO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>Enviado a C.Corrientes', class: 'badge-DEPOSITADO' },
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
    const selectFiltroBandeja = document.getElementById('selectFiltroBandeja');

    // Eventos Pestañas Segmentadas
    segmentedTabs.forEach(tab => {
        tab.addEventListener('click', () => {
            segmentedTabs.forEach(t => t.classList.remove('active'));
            tab.classList.add('active');
            estadoActualFilter = tab.dataset.estado;

            const splitView = document.getElementById('splitViewContainer');
            const filterRow = document.getElementById('filterRowAdmin');
            const panelCC = document.getElementById('panelGestionCC');

            if (estadoActualFilter === 'GESTION_CC') {
                if (splitView) splitView.style.display = 'none';
                if (filterRow) filterRow.style.display = 'none';
                if (panelCC) panelCC.style.display = 'block';
                cargarDatosGestionCC();
                return;
            } else {
                if (splitView) splitView.style.display = 'flex';
                if (filterRow) filterRow.style.display = 'flex';
                if (panelCC) panelCC.style.display = 'none';
            }

            if (selectFiltroBandeja) {
                if (estadoActualFilter === 'BANDEJA_TRABAJO') {
                    selectFiltroBandeja.style.display = 'inline-block';
                } else {
                    selectFiltroBandeja.style.display = 'none';
                }
            }

            cargarCobranzas();
        });
    });

    // Eventos Filtros
    if (inputBuscar) inputBuscar.addEventListener('input', debounce(cargarCobranzas, 300));
    if (selectEmpresa) selectEmpresa.addEventListener('change', cargarCobranzas);
    if (selectOrden) selectOrden.addEventListener('change', aplicarOrdenamientoYRenderizar);
    if (selectFiltroBandeja) selectFiltroBandeja.addEventListener('change', cargarCobranzas);

    const activeTab = document.querySelector('#segmentedTabs .segmented-tab.active');
    if (activeTab && activeTab.dataset.estado) {
        estadoActualFilter = activeTab.dataset.estado;
    }

    if (selectFiltroBandeja) {
        if (estadoActualFilter === 'BANDEJA_TRABAJO') {
            selectFiltroBandeja.style.display = 'inline-block';
        } else {
            selectFiltroBandeja.style.display = 'none';
        }
    }

    cargarCobranzas();
}

// ==========================================
// CARGA Y RENDERIZADO DE TABLA MAESTRA
// ==========================================
function cargarCobranzas() {
    const inputBuscar = document.getElementById('inputBuscarAdmin');
    const selectEmpresa = document.getElementById('selectEmpresaAdmin');
    const selectFiltroBandeja = document.getElementById('selectFiltroBandeja');

    const params = new URLSearchParams();
    
    if (estadoActualFilter === 'BANDEJA_TRABAJO') {
        if (selectFiltroBandeja && selectFiltroBandeja.value !== 'TODOS_BANDEJA') {
            params.append('estado', selectFiltroBandeja.value);
        } else {
            params.append('estado', 'BANDEJA_TRABAJO');
        }
    } else if (estadoActualFilter) {
        params.append('estado', estadoActualFilter);
    }
    
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

function formatRelativeTime(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString.replace(/-/g, '/'));
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Ahora';
    if (diffMins < 60) return `Hace ${diffMins} min`;
    if (diffHours < 24) return `Hace ${diffHours} h`;
    if (diffDays === 1) return 'Ayer';
    if (diffDays < 7) return `Hace ${diffDays} d`;
    
    const months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    return `${date.getDate()} ${months[date.getMonth()]}`;
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
            ? `<span class="seller-badge-muted">Sin Asignar (Registro Auto)</span>` 
            : `<span style="font-weight: 600; color: var(--color-text);">${vendedorRaw}</span>`;

        let empresaDisplay = item.empresa_nombre || '-';
        if (item.facturas && item.facturas.length > 0) {
            const codigosUnicos = [...new Set(item.facturas.map(f => f.codigo_empresa))];
            empresaDisplay = codigosUnicos.map(cod => `<span class="badge-empresa ${cod}" style="font-size:0.75rem; padding:2px 6px; border-radius:4px; font-weight:600; margin-right:4px;">${EMPRESAS_NOMBRES[cod] || cod}</span>`).join('');
        }

        return `
            <tr class="${isSelected ? 'active-row' : ''}" onclick="seleccionarCobranza(${item.id})">
                <td style="font-weight: 600;">${empresaDisplay}</td>
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
                <td style="color: var(--color-text-secondary); white-space: nowrap; font-weight: 500;">${formatRelativeTime(item.created_at)}</td>
            </tr>
        `;
    }).join('');


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
    const facturas = data.facturas || [];
    const cheques = data.cheques || [];
    const historial = data.historial || [];

    // Header
    const titleText = facturas.length > 1 ? `Cobranza Multi-Factura (${facturas.length} docs)` : `Factura N° ${cob.numero_factura || '-'}`;
    document.getElementById('lblPanelFacturaTitle').textContent = titleText;
    const estConfig = ESTADOS_MAP[cob.estado] || { label: cob.estado, class: '' };
    const badgeEl = document.getElementById('lblPanelEstadoBadge');
    badgeEl.innerHTML = estConfig.label;
    badgeEl.className = `badge ${estConfig.class}`;

    // Renderizar Sección Facturas Cubiertas
    const secFacturas = document.getElementById('sectionPanelFacturas');
    const listFacturas = document.getElementById('listPanelFacturas');
    
    if (secFacturas && listFacturas) {
        if (facturas.length > 0) {
            secFacturas.style.display = 'block';
            
            const totalFacturasSuma = facturas.reduce((sum, f) => sum + parseFloat(f.monto_cubierto || f.saldo_cuota || 0), 0);
            const totalFacturasFmt = totalFacturasSuma.toLocaleString('es-CL');
            const facturasString = facturas.map(f => `${f.codigo_empresa} Factura #${f.numero_factura}`).join(', ');

            let htmlFacturas = facturas.map(f => {
                const montoCubiertoNum = parseFloat(f.monto_cubierto || f.saldo_cuota || 0);
                const saldoCuotaNum = parseFloat(f.saldo_cuota || 0);
                const tieneDescuento = (saldoCuotaNum > 0 && Math.abs(montoCubiertoNum - saldoCuotaNum) > 0.01);
                
                return `
                    <div style="display: flex; justify-content: space-between; align-items: center; background: #F8FAFC; border: 1px solid #E2E8F0; padding: 10px 14px; border-radius: 8px;">
                        <div>
                            <div style="display: flex; align-items: center; gap: 6px;">
                                <span class="badge-empresa ${f.codigo_empresa}" style="font-size: 0.72rem; padding: 2px 6px; border-radius: 4px; font-weight: 700;">${EMPRESAS_NOMBRES[f.codigo_empresa] || f.codigo_empresa}</span>
                                <strong style="font-size: 0.9rem; color: #0F172A;">Factura N° ${f.numero_factura} ${f.cuota_label ? `<span style="color: #475569; font-weight: normal; font-size: 0.82rem; margin-left: 4px;">(Cuota ${f.cuota_label})</span>` : ''}</strong>
                            </div>
                            ${tieneDescuento ? `<div style="font-size: 0.75rem; color: #64748B; margin-top: 2px;">Saldo ERP: $${saldoCuotaNum.toLocaleString('es-CL')}</div>` : ''}
                        </div>
                        <div style="text-align: right;">
                            <span style="font-size: 0.92rem; font-weight: 700; color: #1E3A8A;">$${montoCubiertoNum.toLocaleString('es-CL')}</span>
                            <span style="display: block; font-size: 0.7rem; color: #166534; font-weight: 600;">Cubierto</span>
                        </div>
                    </div>
                `;
            }).join('');

            htmlFacturas += `
                <div style="display: flex; justify-content: space-between; align-items: center; background: #EFF6FF; border: 1px solid #BFDBFE; padding: 10px 14px; border-radius: 8px; margin-top: 4px;">
                    <span style="font-size: 0.82rem; font-weight: 600; color: #1E40AF;">Total Facturas (${facturas.length})</span>
                    <strong style="font-size: 0.95rem; font-weight: 800; color: #1E3A8A;">$${totalFacturasFmt}</strong>
                </div>
            `;

            listFacturas.innerHTML = htmlFacturas;
        } else {
            secFacturas.style.display = 'none';
        }
    }

    // Sección 1: Resumen Factura / Cliente & ALERTA POR DISCREPANCIA (VON RESTORFF)
    const montoFacturaVal = parseFloat(cob.monto_total_factura || 0);
    const montoChequesVal = parseFloat(cob.total_cheques || 0);
    const deltaVal = montoChequesVal - montoFacturaVal;
    const tieneMismatch = Math.abs(deltaVal) > 0.01;

    const empNombreMapped = (facturas && facturas.length > 0)
        ? [...new Set(facturas.map(f => EMPRESAS_NOMBRES[f.codigo_empresa] || f.codigo_empresa))].join(', ')
        : (EMPRESAS_NOMBRES[cob.codigo_empresa] || cob.empresa_nombre || '-');
    document.getElementById('lblPanelEmpresa').textContent = empNombreMapped;
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
        // Botón editar datos (solo estados no finales)
        const estadoFinal = ['DEPOSITADO', 'RECHAZADO'].includes(cob.estado);
        const btnEditar = !estadoFinal
            ? `<button type="button" onclick="activarModoEdicion(${cob.id})" style="font-size:0.78rem; padding:4px 10px; border-radius:5px; border:1px solid #e2e8f0; background:#f8fafc; color:#475569; cursor:pointer; margin-bottom:10px;">Corregir datos de cheques</button>`
            : '';

        boxCheques.innerHTML = btnEditar + cheques.map(chq => `
            <div class="cheque-inspection-card" id="chqView_${chq.id}">
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

    // Si está en EN_TRANSITO, ENTREGADO_SANTIAGO o PENDIENTE_ENVIO: Mostrar "Validar y Enviar a Cuentas Corrientes" + "Rechazar"
    if (cob.estado === 'EN_TRANSITO' || cob.estado === 'ENTREGADO_SANTIAGO' || cob.estado === 'PENDIENTE_ENVIO') {
        ctaHtml = `
            <button type="button" class="btn-b2b btn-b2b-success" style="flex: 1;" onclick="pedirConfirmacionRecepcion(${cob.id}, '${cob.numero_factura}')">
                VALIDAR / MANDAR A C.CORRIENTES
            </button>
            <button type="button" class="btn-b2b btn-b2b-danger" onclick="abrirModalRechazo(${cob.id})">
                Rechazar
            </button>
        `;
    } 
    // Si ya está RECIBIDO_TESORERIA: Mostrar mensaje de que está en cola
    else if (cob.estado === 'RECIBIDO_TESORERIA') {
        ctaHtml = `
            <span style="font-size: 0.85rem; color: #2563eb; font-weight: 700; background: #eff6ff; padding: 8px 12px; border-radius: 6px; border: 1px solid #bfdbfe; flex: 1; text-align: center;">
                ✓ Validado físicamente. En cola de despacho por C. Corrientes.
            </span>
        `;
    }
    // Si ya fue DEPOSITADO o RECHAZADO: Deshabilitar acciones (Estado Final Inmutable)
    else if (cob.estado === 'DEPOSITADO') {
        ctaHtml = `<span style="font-size: 0.85rem; color: #166534; font-weight: 700;">✓ Cobranza Despachada a Cuentas Corrientes</span>`;
    } else if (cob.estado === 'RECHAZADO') {
        ctaHtml = `<span style="font-size: 0.85rem; color: #dc2626; font-weight: 700;">✕ Documento Rechazado / Protestado</span>`;
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
        { key: 'RECIBIDO_TESORERIA', label: 'En Cola C.C.' },
        { key: 'DEPOSITADO', label: 'Despachado C.C.' }
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
                <h3>Validar - Enviar Cuentas Corrientes</h3>
                <p style="font-size: 0.85rem; color: var(--color-text-secondary); margin-top: 8px;">¿Confirmas que los cheques físicos de la <strong id="lblConfirmNumFactura" style="color: var(--color-primary);">Factura N° -</strong> fueron recibidos y están validados para ser enviados a Cuentas Corrientes?</p>
                <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                    <button type="button" class="btn-b2b" style="background: #e2e8f0; color: #334155;" onclick="cerrarModalConfirmacionRecepcion()">Cancelar</button>
                    <button type="button" class="btn-b2b btn-b2b-success" id="btnConfirmarRecepcionSubmit">Validar y Enviar</button>
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

// ==========================================
// MÓDULO GERENCIAL CUENTAS CORRIENTES (GESTION_CC)
// ==========================================

function cargarDatosGestionCC() {
    fetch('api/get_gestion_cc.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error al cargar gestión C.Corr', 'error');
                return;
            }
            
            const info = data.data;

            // 1. Hora de despacho
            const inputHora = document.getElementById('inputHoraDespacho');
            if (inputHora) {
                inputHora.value = info.hora_despacho_diario;
            }

            // 2. Renderizar matriz de empresas
            const tbodyAsignaciones = document.getElementById('tblAsignacionesDigitadoras');
            if (tbodyAsignaciones) {
                let totalPendientes = 0;
                tbodyAsignaciones.innerHTML = info.empresas.map(emp => {
                    totalPendientes += parseInt(emp.cheques_pendientes_hoy || 0);
                    return `
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="padding: 12px; font-weight: 600; color: #0f172a;">${emp.nombre}</td>
                            <td style="padding: 12px;">
                                <input type="email" id="email_emp_${emp.id}" value="${emp.email_digitadora || ''}" class="select-compact" style="width: 100%; max-width: 320px; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.9rem;">
                            </td>
                            <td style="padding: 12px; text-align: right;">
                                <span style="font-size: 0.8rem; color: #64748b; margin-right: 12px;">${emp.cheques_pendientes_hoy || 0} pendientes hoy</span>
                            </td>
                        </tr>
                    `;
                }).join('');

                // 3. Métricas
                const lblMetrics = document.getElementById('lblMétricasConsola');
                if (lblMetrics) {
                    lblMetrics.textContent = `${totalPendientes} cheques / cobranzas validados hoy (En estado RECIBIDO_TESORERIA)`;
                }
            }

            // 4. Bitácora de Envíos
            const tbodyBitacora = document.getElementById('tblBitacoraEnvios');
            if (tbodyBitacora) {
                if (!info.log_envios || info.log_envios.length === 0) {
                    tbodyBitacora.innerHTML = `
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 24px; color: #64748b;">No hay registros de envíos en la bitácora.</td>
                        </tr>
                    `;
                } else {
                    tbodyBitacora.innerHTML = info.log_envios.map(log => {
                        const esExitoso = log.estado_envio === 'ENVIADO';
                        const badgeStyle = esExitoso 
                            ? 'background: #dcfce7; color: #15803d; border-radius: 9999px; padding: 2px 8px; font-size: 0.8rem; font-weight: 600;' 
                            : 'background: #fee2e2; color: #b91c1c; border-radius: 9999px; padding: 2px 8px; font-size: 0.8rem; font-weight: 600; cursor: help;';
                        
                        const titleError = log.error_mensaje ? `title="${log.error_mensaje}"` : '';

                        return `
                            <tr style="border-bottom: 1px solid #f1f5f9;">
                                <td style="padding: 12px; color: #0f172a; font-weight: 500;">${log.fecha_envio}</td>
                                <td style="padding: 12px; font-weight: 600;">${log.empresa || 'Consolidado'}</td>
                                <td style="padding: 12px; color: #475569;">${log.destinatario}</td>
                                <td style="padding: 12px; text-align: center; font-weight: 700;">${log.cantidad_cobranzas}</td>
                                <td style="padding: 12px;"><span style="${badgeStyle}" ${titleError}>${log.estado_envio}</span></td>
                                <td style="padding: 12px; text-align: right;">
                                    <button type="button" class="btn-b2b btn-b2b-secondary" onclick="reenviarBitacoraCC(${log.id})" style="padding: 4px 8px; font-size: 0.8rem;">🔄 Re-enviar</button>
                                </td>
                            </tr>
                        `;
                    }).join('');
                }
            }

        })
        .catch(err => {
            console.error(err);
            showToast('Error al conectar con la API de gestión C.Corr', 'error');
        });
}

function guardarConfiguracionGlobalCC() {
    const inputHora = document.getElementById('inputHoraDespacho');
    if (!inputHora || !inputHora.value) {
        showToast('Seleccione una hora válida', 'error');
        return;
    }

    const asignaciones = [];
    const inputs = document.querySelectorAll('[id^="email_emp_"]');
    inputs.forEach(inp => {
        const id = parseInt(inp.id.replace('email_emp_', ''));
        const email = inp.value.trim();
        asignaciones.push({ id, email });
    });

    const payload = {
        hora_despacho_diario: inputHora.value,
        asignaciones_empresas: asignaciones
    };

    fetch('api/guardar_configuracion_cc.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showToast(data.message || 'Error al guardar la configuración', 'error');
            return;
        }
        showToast('Configuración guardada correctamente.', 'success');
        cargarDatosGestionCC();
    })
    .catch(err => {
        console.error(err);
        showToast('Error al guardar configuración global', 'error');
    });
}

let despachandoResumenManual = false;
function despacharResumenManualCC(btnEl) {
    if (despachandoResumenManual) return;
    if (!confirm('¿Está seguro de despachar el resumen diario consolidado ahora manualmente? Se enviará correo a las digitadoras respectivas.')) {
        return;
    }

    despachandoResumenManual = true;
    let oldText = '⚡ Despachar Resumen Ahora';
    if (btnEl) {
        btnEl.disabled = true;
        oldText = btnEl.textContent;
        btnEl.textContent = 'Despachando...';
    }

    fetch('api/despachar_resumen_cc.php', {
        method: 'POST'
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showToast(data.message || 'Error al despachar el resumen', 'error');
            return;
        }
        showToast(data.message || 'Resumen despachado con éxito.', 'success');
        cargarDatosGestionCC();
    })
    .catch(err => {
        console.error(err);
        showToast('Error al conectar con el despachador', 'error');
    })
    .finally(() => {
        despachandoResumenManual = false;
        if (btnEl) {
            btnEl.disabled = false;
            btnEl.textContent = oldText;
        }
    });
}

function reenviarBitacoraCC(logId) {
    const nuevoCorreo = prompt('¿Desea reenviar a un correo alternativo? Dejar en blanco para usar el correo original registrado:');
    if (nuevoCorreo === null) return; // Clic en cancelar

    const payload = {
        log_id: logId,
        nuevo_correo: nuevoCorreo.trim()
    };

    fetch('api/reenviar_informe_cc.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showToast(data.message || 'Error al reenviar informe', 'error');
            return;
        }
        showToast(data.message || 'Informe reenviado correctamente.', 'success');
        cargarDatosGestionCC();
    })
    .catch(err => {
        console.error(err);
        showToast('Error de red al reenviar informe', 'error');
    });
}


// ============================================================
// MODO EDICIÓN INLINE DE CHEQUES — TESORERÍA
// ============================================================
let _chequesEdicionCache = [];

function activarModoEdicion(cobranzaId) {
    const boxCheques = document.getElementById('boxPanelChequesList');
    if (!boxCheques) return;

    const cards = boxCheques.querySelectorAll('[id^="chqView_"]');
    if (cards.length === 0) { showToast('No hay cheques para editar', 'error'); return; }

    _chequesEdicionCache = Array.from(cards).map(card => {
        const id = parseInt(card.id.replace('chqView_', ''));
        const banco = card.querySelector('.cheque-banco-name')?.textContent.trim() || '';
        const montoText = card.querySelector('.cheque-monto-value')?.textContent.replace(/[^0-9]/g, '') || '0';
        const infoLine = card.querySelector('[style*="font-size: 0.8rem"]')?.textContent || '';
        const numMatch = infoLine.match(/N[°º] Cheque:\s*([^\|]+)/);
        const vencMatch = infoLine.match(/Vencimiento:\s*(.+)/);
        return {
            id,
            banco,
            numero_cheque: numMatch ? numMatch[1].trim() : '',
            monto: parseInt(montoText) || 0,
            fecha_vencimiento: vencMatch ? vencMatch[1].trim() : '',
        };
    });

    const bancos = ['Banco de Chile', 'Santander', 'BCI', 'Banco Estado', 'Scotiabank', 'Itaú', 'Otro'];

    const formRows = _chequesEdicionCache.map((chq, i) => `
        <div style="border:1px solid #e2e8f0; border-radius:8px; padding:12px; margin-bottom:10px; background:#f8fafc;">
            <div style="font-size:0.78rem; font-weight:700; color:#64748b; margin-bottom:8px;">Cheque #${i + 1}</div>
            <input type="hidden" id="editChqId_${i}" value="${chq.id}">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:6px;">
                <div>
                    <label style="font-size:0.78rem; font-weight:600; color:#475569;">Banco</label>
                    <select id="editBanco_${i}" style="width:100%; padding:6px 8px; border:1px solid #cbd5e1; border-radius:5px; font-size:0.85rem;">
                        ${bancos.map(b => `<option value="${b}" ${chq.banco === b ? 'selected' : ''}>${b}</option>`).join('')}
                    </select>
                </div>
                <div>
                    <label style="font-size:0.78rem; font-weight:600; color:#475569;">N° Cheque</label>
                    <input type="text" id="editNumero_${i}" value="${chq.numero_cheque}" style="width:100%; padding:6px 8px; border:1px solid #cbd5e1; border-radius:5px; font-size:0.85rem; box-sizing:border-box;">
                </div>
            </div>
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <div>
                    <label style="font-size:0.78rem; font-weight:600; color:#475569;">Monto ($)</label>
                    <input type="number" id="editMonto_${i}" value="${chq.monto}" min="1" style="width:100%; padding:6px 8px; border:1px solid #cbd5e1; border-radius:5px; font-size:0.85rem; box-sizing:border-box;">
                </div>
                <div>
                    <label style="font-size:0.78rem; font-weight:600; color:#475569;">Vencimiento</label>
                    <input type="date" id="editFecha_${i}" value="${chq.fecha_vencimiento}" style="width:100%; padding:6px 8px; border:1px solid #cbd5e1; border-radius:5px; font-size:0.85rem; box-sizing:border-box;">
                </div>
            </div>
        </div>
    `).join('');

    boxCheques.innerHTML = `
        <div style="background:#fffbeb; border:1px solid #fcd34d; border-radius:8px; padding:10px 14px; margin-bottom:12px; font-size:0.82rem; color:#92400e; font-weight:600;">
            Modo corrección activo — Corrija los datos y presione Guardar.
        </div>
        ${formRows}
        <div style="display:flex; gap:10px; margin-top:8px;">
            <button type="button" onclick="guardarEdicionTesoreria(${cobranzaId}, ${_chequesEdicionCache.length})" style="flex:1; padding:9px; border-radius:6px; background:#166534; color:#fff; border:none; font-weight:700; font-size:0.9rem; cursor:pointer;">
                Guardar Cambios
            </button>
            <button type="button" onclick="seleccionarCobranza(${cobranzaId})" style="padding:9px 14px; border-radius:6px; border:1px solid #e2e8f0; background:#fff; color:#475569; cursor:pointer; font-size:0.9rem;">
                Cancelar
            </button>
        </div>
    `;
}

async function guardarEdicionTesoreria(cobranzaId, count) {
    const cheques = [];
    for (let i = 0; i < count; i++) {
        cheques.push({
            id: parseInt(document.getElementById(`editChqId_${i}`)?.value || 0),
            banco: document.getElementById(`editBanco_${i}`)?.value?.trim(),
            numero_cheque: document.getElementById(`editNumero_${i}`)?.value?.trim(),
            monto: parseFloat(document.getElementById(`editMonto_${i}`)?.value || 0),
            fecha_vencimiento: document.getElementById(`editFecha_${i}`)?.value?.trim(),
        });
    }

    try {
        const res = await fetch('api/editar_cobranza_tesoreria.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cobranza_id: cobranzaId, cheques })
        });
        const data = await res.json();
        if (!data.success) {
            showToast(data.message || 'Error al guardar', 'error');
            return;
        }
        showToast(data.message || 'Datos actualizados correctamente.', 'success');
        seleccionarCobranza(cobranzaId);
    } catch (err) {
        console.error(err);
        showToast('Error de conexión al guardar cambios', 'error');
    }
}
