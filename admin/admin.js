/**
 * admin/admin.js
 * 
 * Lógica JavaScript del Portal de Tesorería.
 * Ultra simple, reactiva y directa.
 */

document.addEventListener('DOMContentLoaded', () => {
    // Detectar si estamos en el dashboard o en la vista de detalle
    if (document.getElementById('adminTableBody')) {
        initDashboard();
    } else if (document.getElementById('viewDetalleCobranza')) {
        initDetalle();
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
    setTimeout(() => {
        toast.remove();
    }, 4000);
}

// Global Image Lightbox
function abrirImagenLightbox(url) {
    let lightbox = document.getElementById('modalLightbox');
    if (!lightbox) {
        lightbox = document.createElement('div');
        lightbox.id = 'modalLightbox';
        lightbox.className = 'modal-overlay';
        lightbox.style.display = 'none';
        lightbox.innerHTML = `
            <div style="position: relative; max-width: 90vw; max-height: 90vh;">
                <button type="button" onclick="cerrarImagenLightbox()" style="position: absolute; top: -40px; right: 0; background: white; border: none; font-size: 1.5rem; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-weight: bold;">&times;</button>
                <img id="imgLightboxTarget" src="" style="max-width: 100%; max-height: 85vh; border-radius: 8px; box-shadow: 0 4px 20px rgba(0,0,0,0.5); object-fit: contain;">
            </div>
        `;
        document.body.appendChild(lightbox);
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) cerrarImagenLightbox();
        });
    }
    document.getElementById('imgLightboxTarget').src = url;
    lightbox.style.display = 'flex';
    document.body.classList.add('modal-open');
}

function cerrarImagenLightbox() {
    const lightbox = document.getElementById('modalLightbox');
    if (lightbox) lightbox.style.display = 'none';
    document.body.classList.remove('modal-open');
}

// Helper Mapeo Nombres de Estado
const ESTADOS_MAP = {
    'PENDIENTE_ENVIO': { label: 'Pendiente Envío', class: 'status-PENDIENTE_ENVIO' },
    'EN_TRANSITO': { label: 'En Tránsito (Chilexpress)', class: 'status-EN_TRANSITO' },
    'ENTREGADO_SANTIAGO': { label: 'Entregado (Santiago)', class: 'status-ENTREGADO_SANTIAGO' },
    'RECIBIDO_TESORERIA': { label: 'Recibido en Tesorería', class: 'status-RECIBIDO_TESORERIA' },
    'DEPOSITADO': { label: 'Depositado', class: 'status-DEPOSITADO' },
    'RECHAZADO': { label: 'Rechazado', class: 'status-RECHAZADO' }
};

// ==========================================
// LÓGICA DEL DASHBOARD (INDEX)
// ==========================================
function initDashboard() {
    const inputBuscar = document.getElementById('inputBuscarAdmin');
    const selectEmpresa = document.getElementById('selectEmpresaAdmin');
    const selectEstado = document.getElementById('selectEstadoAdmin');

    const cargarDatos = () => {
        const params = new URLSearchParams();
        if (inputBuscar && inputBuscar.value.trim()) params.append('busqueda', inputBuscar.value.trim());
        if (selectEmpresa && selectEmpresa.value) params.append('empresa_id', selectEmpresa.value);
        if (selectEstado && selectEstado.value) params.append('estado', selectEstado.value);

        fetch(`api/get_cobranzas.php?${params.toString()}`)
            .then(res => res.json())
            .then(data => {
                if (!data.success) {
                    showToast(data.message || 'Error al obtener datos', 'error');
                    return;
                }
                renderMetrics(data.metrics);
                renderTable(data.data);
            })
            .catch(err => {
                console.error(err);
                showToast('Error de conexión con el servidor', 'error');
            });
    };

    if (inputBuscar) inputBuscar.addEventListener('input', debounce(cargarDatos, 300));
    if (selectEmpresa) selectEmpresa.addEventListener('change', cargarDatos);
    if (selectEstado) selectEstado.addEventListener('change', cargarDatos);

    cargarDatos();
}

function renderMetrics(metrics) {
    if (!metrics) return;
    const elPendientes = document.getElementById('metricPendientes');
    const elTransito = document.getElementById('metricTransito');
    const elRecibidos = document.getElementById('metricRecibidos');
    const elDepositados = document.getElementById('metricDepositados');

    if (elPendientes) elPendientes.textContent = metrics.pendientes_envio || 0;
    if (elTransito) elTransito.textContent = metrics.en_transito || 0;
    if (elRecibidos) elRecibidos.textContent = metrics.recibidos || 0;
    if (elDepositados) elDepositados.textContent = metrics.depositados || 0;
}

function renderTable(cobranzas) {
    const tbody = document.getElementById('adminTableBody');
    if (!tbody) return;

    if (!cobranzas || cobranzas.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="8" style="text-align: center; padding: 32px; color: var(--color-text-muted);">
                    No se encontraron cobranzas registradas con los filtros seleccionados.
                </td>
            </tr>
        `;
        return;
    }

    tbody.innerHTML = cobranzas.map(item => {
        const estConfig = ESTADOS_MAP[item.estado] || { label: item.estado, class: '' };
        const fecha = item.created_at ? item.created_at.split(' ')[0] : '-';
        const montoFactura = parseFloat(item.monto_total_factura || 0).toLocaleString('es-CL');
        const montoCheques = parseFloat(item.total_cheques || 0).toLocaleString('es-CL');

        return `
            <tr>
                <td><strong>${item.empresa_nombre || '-'}</strong></td>
                <td><strong style="color: var(--color-primary);">Factura N° ${item.numero_factura}</strong></td>
                <td>
                    <div style="font-weight: 600;">${item.razon_social_cliente || '-'}</div>
                    <div style="font-size: 0.8rem; color: var(--color-text-muted);">RUT: ${item.rut_cliente || '-'}</div>
                </td>
                <td><span style="font-weight: 600; color: #475569;">${item.vendedor_nombre || 'Sistema'}</span></td>
                <td>$${montoFactura}</td>
                <td>
                    <strong>$${montoCheques}</strong>
                    <div style="font-size: 0.78rem; color: var(--color-text-muted);">${item.cantidad_cheques} cheque(s)</div>
                </td>
                <td><span class="status-badge ${estConfig.class}">${estConfig.label}</span></td>
                <td>${fecha}</td>
                <td>
                    <a href="detalle.php?id=${item.id}" class="btn-action btn-primary">
                        Ver Detalle
                    </a>
                </td>
            </tr>
        `;
    }).join('');
}

// ==========================================
// LÓGICA DE LA VISTA DETALLE
// ==========================================
function initDetalle() {
    const urlParams = new URLSearchParams(window.location.search);
    const cobranzaId = urlParams.get('id');

    if (!cobranzaId) {
        showToast('ID de cobranza no especificado', 'error');
        return;
    }

    cargarDetalle(cobranzaId);
}

function cargarDetalle(id) {
    fetch(`api/get_detalle_cobranza.php?id=${id}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success || !data.data) {
                showToast(data.message || 'No se pudo cargar el detalle', 'error');
                return;
            }
            renderDetalleView(data.data);
        })
        .catch(err => {
            console.error(err);
            showToast('Error al conectar con el servidor', 'error');
        });
}

function renderDetalleView(data) {
    const cob = data.cobranza;
    const cheques = data.cheques || [];
    const historial = data.historial || [];

    // Header datos
    document.getElementById('lblEmpresa').textContent = cob.empresa_nombre || '-';
    document.getElementById('lblFactura').textContent = cob.numero_factura || '-';
    document.getElementById('lblCliente').textContent = cob.razon_social_cliente || '-';
    document.getElementById('lblRut').textContent = cob.rut_cliente || '-';
    document.getElementById('lblMontoFactura').textContent = '$' + parseFloat(cob.monto_total_factura || 0).toLocaleString('es-CL');
    document.getElementById('lblTotalCheques').textContent = '$' + parseFloat(cob.total_cheques || 0).toLocaleString('es-CL');
    document.getElementById('lblVendedor').textContent = cob.vendedor_nombre || 'Sistema';

    const estConfig = ESTADOS_MAP[cob.estado] || { label: cob.estado, class: '' };
    const elEstado = document.getElementById('lblEstadoBadge');
    elEstado.textContent = estConfig.label;
    elEstado.className = `status-badge ${estConfig.class}`;

    // Datos envío
    const tipoEntrega = cob.tipo_entrega ? (cob.tipo_entrega === 'CHILEXPRESS' ? 'Chilexpress' : 'Entrega Presencial Santiago') : 'Pendiente';
    document.getElementById('lblTipoEntrega').textContent = tipoEntrega;
    document.getElementById('lblSeguimiento').textContent = cob.numero_seguimiento ? `OT / N°: ${cob.numero_seguimiento}` : '-';

    const boxComprobante = document.getElementById('boxComprobante');
    if (cob.comprobante_url) {
        boxComprobante.innerHTML = `<img src="../${cob.comprobante_url}" class="photo-thumbnail" onclick="abrirImagenLightbox('../${cob.comprobante_url}')" title="Clic para ampliar">`;
    } else {
        boxComprobante.innerHTML = '<span style="color: var(--color-text-muted); font-size: 0.85rem;">Sin comprobante de envío aún</span>';
    }

    // Render Cheques Visuales (Galería de Fotos Grandes)
    const gridChequesVisuales = document.getElementById('gridChequesVisuales');
    if (gridChequesVisuales) {
        gridChequesVisuales.innerHTML = cheques.map(chq => `
            <div class="cheque-card-visual">
                <div class="cheque-card-img-wrapper" onclick="abrirImagenLightbox('../${chq.foto_cheque_url}')">
                    <img src="../${chq.foto_cheque_url}" class="cheque-card-img" alt="Foto Cheque ${chq.numero_cheque}">
                    <span class="cheque-card-badge-zoom">🔍 Clic para ampliar</span>
                </div>
                <div class="cheque-card-info">
                    <div class="cheque-card-header">
                        <span class="cheque-banco-tag">${chq.banco}</span>
                        <span class="cheque-monto-highlight">$${parseFloat(chq.monto).toLocaleString('es-CL')}</span>
                    </div>
                    <div style="font-size: 0.95rem; font-weight: 700; color: var(--color-text);">
                        N° Cheque: <span style="color: var(--color-primary);">${chq.numero_cheque}</span>
                    </div>
                    <div style="font-size: 0.85rem; color: var(--color-text-muted);">
                        <strong>Vencimiento:</strong> ${chq.fecha_vencimiento}
                    </div>
                    ${chq.comentario ? `
                        <div style="font-size: 0.85rem; background-color: #f8fafc; padding: 8px; border-radius: 6px; border-left: 3px solid var(--color-primary); margin-top: 4px;">
                            <strong>Comentario:</strong> ${chq.comentario}
                        </div>
                    ` : ''}
                    ${chq.numero_papeleta_deposito ? `
                        <div style="font-size: 0.85rem; color: #166534; background-color: #f0fdf4; padding: 6px 10px; border-radius: 6px; font-weight: 700; margin-top: 4px;">
                            Papeleta Depósito: N° ${chq.numero_papeleta_deposito}
                        </div>
                    ` : ''}
                </div>
            </div>
        `).join('');
    }

    // Render Cheques Tabla Resumen
    const tbodyCheques = document.getElementById('tbodyChequesDetalle');
    if (tbodyCheques) {
        tbodyCheques.innerHTML = cheques.map(chq => `
            <tr>
                <td><strong>${chq.banco}</strong></td>
                <td>${chq.numero_cheque}</td>
                <td><strong>$${parseFloat(chq.monto).toLocaleString('es-CL')}</strong></td>
                <td>${chq.fecha_vencimiento}</td>
                <td>
                    <img src="../${chq.foto_cheque_url}" class="photo-thumbnail" onclick="abrirImagenLightbox('../${chq.foto_cheque_url}')" title="Clic para ampliar">
                </td>
                <td>${chq.comentario ? chq.comentario : '-'}</td>
                <td>${chq.numero_papeleta_deposito ? `<span style="color: #166534; font-weight:700;">N° ${chq.numero_papeleta_deposito}</span>` : '-'}</td>
            </tr>
        `).join('');
    }

    // Render Historial Timeline
    const tbodyHistorial = document.getElementById('tbodyHistorialDetalle');
    if (tbodyHistorial) {
        tbodyHistorial.innerHTML = historial.map(item => {
            const nuevoConfig = ESTADOS_MAP[item.estado_nuevo] || { label: item.estado_nuevo };
            const fecha = item.created_at ? item.created_at.replace('T', ' ') : '-';
            return `
                <tr>
                    <td>${fecha}</td>
                    <td><strong>${item.usuario_nombre || 'Sistema'}</strong></td>
                    <td><span class="status-badge ${nuevoConfig.class}">${nuevoConfig.label}</span></td>
                    <td>${item.comentario || '-'}</td>
                </tr>
            `;
        }).join('');
    }

    // Render Botones de Acción de Tesorería según el estado actual
    renderBotonesAccion(cob);
}

function renderBotonesAccion(cob) {
    const container = document.getElementById('boxAccionesTesoreria');
    if (!container) return;

    let html = '';

    // Botón Recibir en Tesorería (Si está en tránsito o entregado Santiago)
    if (cob.estado === 'EN_TRANSITO' || cob.estado === 'ENTREGADO_SANTIAGO') {
        html += `
            <button type="button" class="btn-action btn-success" onclick="ejecutarCambioEstado(${cob.id}, 'RECIBIDO_TESORERIA')">
                Marcar como Recibido en Tesorería
            </button>
        `;
    }

    // Botón Registrar Depósito (Si ya fue recibido o está en tránsito)
    if (cob.estado === 'RECIBIDO_TESORERIA' || cob.estado === 'EN_TRANSITO' || cob.estado === 'ENTREGADO_SANTIAGO') {
        html += `
            <button type="button" class="btn-action btn-primary" onclick="abrirModalDeposito(${cob.id})">
                Registrar Depósito
            </button>
        `;
    }

    // Botón Marcar Rechazado (Si está en proceso y no ha sido cerrado)
    if (cob.estado !== 'RECHAZADO' && cob.estado !== 'DEPOSITADO') {
        html += `
            <button type="button" class="btn-action btn-danger" onclick="abrirModalRechazo(${cob.id})">
                Marcar como Rechazado
            </button>
        `;
    }

    if (!html) {
        html = `<span style="font-size: 0.9rem; color: var(--color-text-muted); font-weight: 600;">Esta cobranza ya se encuentra finalizada (${cob.estado}).</span>`;
    }

    container.innerHTML = html;
}

// Modal Depósito
function abrirModalDeposito(id) {
    let modal = document.getElementById('modalDeposito');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalDeposito';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-box">
                <h3>Registrar Depósito en Banco</h3>
                <p style="font-size: 0.88rem; color: var(--color-text-muted); margin-bottom: 16px;">Ingrese el número de la papeleta de depósito bancario para cerrar el cobro.</p>
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
                        <button type="button" class="btn-action btn-secondary" onclick="cerrarModalDeposito()">Cancelar</button>
                        <button type="submit" class="btn-action btn-success">Confirmar Depósito</button>
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

// Modal Rechazo
function abrirModalRechazo(id) {
    let modal = document.getElementById('modalRechazo');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'modalRechazo';
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-box">
                <h3 style="color: #dc2626;">Marcar Cheque Rechazado</h3>
                <p style="font-size: 0.88rem; color: var(--color-text-muted); margin-bottom: 16px;">Ingrese la razón del rechazo o protesto del documento.</p>
                <form id="formRechazoModal">
                    <input type="hidden" id="rechazoCobranzaId">
                    <div class="modal-form-group">
                        <label>Motivo del Rechazo *</label>
                        <textarea id="inputMotivoRechazo" rows="3" placeholder="Ej: Firma conforme, falta de fondos, cheque orden vencido..." required></textarea>
                    </div>
                    <div class="modal-actions">
                        <button type="button" class="btn-action btn-secondary" onclick="cerrarModalRechazo()">Cancelar</button>
                        <button type="submit" class="btn-action btn-danger">Confirmar Rechazo</button>
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
        // Recargar datos de la vista
        if (document.getElementById('viewDetalleCobranza')) {
            cargarDetalle(id);
        } else {
            initDashboard();
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
