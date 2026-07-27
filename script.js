document.addEventListener('DOMContentLoaded', () => {
    // Redirigir consola a pantalla para depurar en tablet
    const debugLogs = document.getElementById('debugLogs');
    function logToScreen(message, type = 'info') {
        if (!debugLogs) return;
        const color = type === 'error' ? '#ff5555' : (type === 'warn' ? '#ffb86c' : '#50fa7b');
        if (debugLogs.innerHTML === '[Esperando eventos...]') {
            debugLogs.innerHTML = '';
        }
        debugLogs.innerHTML += `<div style="color: ${color}; border-bottom: 1px solid #333; padding: 2px 0;">[${new Date().toLocaleTimeString()}] [${type.toUpperCase()}] ${message}</div>`;
        debugLogs.scrollTop = debugLogs.scrollHeight;
    }

    // Capturar errores no manejados de JS
    window.onerror = function (msg, url, lineNo, columnNo, error) {
        logToScreen(`${msg} (Línea: ${lineNo}, Col: ${columnNo})`, 'error');
        return false;
    };

    window.onunhandledrejection = function (event) {
        logToScreen(`Promesa rechazada: ${event.reason}`, 'error');
    };

    // Sobrescribir console para que imprima en pantalla
    const originalLog = console.log;
    const originalError = console.error;
    console.log = function (...args) {
        originalLog.apply(console, args);
        logToScreen(args.map(a => {
            if (a instanceof Error) return `${a.name}: ${a.message}`;
            return typeof a === 'object' ? JSON.stringify(a) : a;
        }).join(' '), 'info');
    };
    console.error = function (...args) {
        originalError.apply(console, args);
        logToScreen(args.map(a => {
            if (a instanceof Error) return `${a.name}: ${a.message}\n${a.stack}`;
            return typeof a === 'object' ? JSON.stringify(a) : a;
        }).join(' '), 'error');
    };

    // Referencias DOM
    const tabRegistrar = document.getElementById('tabRegistrar');
    const tabSeguimiento = document.getElementById('tabSeguimiento');
    const viewNuevoEnvio = document.getElementById('viewNuevoEnvio');
    const viewSeguimiento = document.getElementById('viewSeguimiento');

    const formCobranza = document.getElementById('formCobranza');
    const btnAgregarCheque = document.getElementById('btnAgregarCheque');
    const contenedorCheques = document.getElementById('contenedorCheques');
    const lblTotalCheques = document.getElementById('lblTotalCheques');
    const lblMontoFacturaResumen = document.getElementById('lblMontoFacturaResumen');
    const toastContainer = document.getElementById('toastContainer');

    const infoClienteBox = document.getElementById('infoClienteBox');
    const lblNombreCliente = document.getElementById('lblNombreCliente');
    const lblRutCliente = document.getElementById('lblRutCliente');
    const lblMontoFactura = document.getElementById('lblMontoFactura');
    const rutClienteInput = document.getElementById('rutCliente');
    const razonSocialClienteInput = document.getElementById('razonSocialCliente');
    const numFacturaInput = document.getElementById('numFactura');
    const emailClienteInput = document.getElementById('emailCliente');
    const chkEmailCliente = document.getElementById('chkEmailCliente');
    const wrapperEmailCliente = document.getElementById('wrapperEmailCliente');
    const empresaSelect = document.getElementById('empresaVendedor');
    const montoTotalFacturaInput = document.getElementById('montoTotalFactura');
    const errorClienteBox = document.getElementById('errorClienteBox');

    const inputBuscar = document.getElementById('inputBuscarSeguimiento');
    const filtroEstado = document.getElementById('filtroEstado');

    let contadorCheques = 0;
    let montoFacturaActual = 0;
    let debounceTimer = null;

    // Cargar vendedor_id desde la URL (para WebView de Android)
    const urlParams = new URLSearchParams(window.location.search);
    const vendedorIdParam = urlParams.get('vendedor_id') || urlParams.get('vendedor');
    const vendedorIdInput = document.getElementById('vendedorIdInput');
    if (vendedorIdInput && vendedorIdParam) {
        vendedorIdInput.value = vendedorIdParam;
    }

    // Variables globales para la edición de cheques
    let cobranzasPendientesGlobal = [];
    let eliminadosIdsEdicion = [];
    let contadorChequesEdicion = 0;
    let montoFacturaEdicionActual = 0;
    let formularioConfirmando = null;
    let emailClienteERP = '';

    // ==========================================
    // BADGE DE PENDIENTES EN PESTAÑA
    // ==========================================
    function actualizarBadgePendientes(cantidad) {
        const badge = document.getElementById('badgeCantidadPendientes');
        if (!badge) return;
        if (cantidad > 0) {
            badge.textContent = cantidad;
            badge.style.display = 'inline-flex';
        } else {
            badge.style.display = 'none';
        }
    }

    if (chkEmailCliente && wrapperEmailCliente) {
        chkEmailCliente.addEventListener('change', (e) => {
            if (e.target.checked) {
                wrapperEmailCliente.style.display = 'block';
                emailClienteInput.disabled = false;
                emailClienteInput.value = emailClienteERP;
            } else {
                wrapperEmailCliente.style.display = 'none';
                emailClienteInput.disabled = true;
                emailClienteInput.value = '';
            }
        });
    }

    // ==========================================
    // SISTEMA DE PESTAÑAS (TABS)
    // ==========================================
    tabRegistrar.addEventListener('click', () => {
        tabRegistrar.classList.add('active');
        tabSeguimiento.classList.remove('active');
        viewNuevoEnvio.classList.add('active');
        viewSeguimiento.classList.remove('active');
    });

    tabSeguimiento.addEventListener('click', () => {
        tabSeguimiento.classList.add('active');
        tabRegistrar.classList.remove('active');
        viewSeguimiento.classList.add('active');
        viewNuevoEnvio.classList.remove('active');
        cargarSeguimiento();
    });

    // Actualizar badge al cargar la página (sin mostrar el listado)
    async function actualizarBadgeInicial() {
        try {
            const response = await fetch('api/get_mis_cobranzas.php');
            const data = await response.json();
            if (data.success && data.data && data.data.por_enviar) {
                actualizarBadgePendientes(data.data.por_enviar.length);
            }
        } catch (_) { /* silencioso */ }
    }
    actualizarBadgeInicial();

    // ==========================================
    // NOTIFICACIONES TOAST
    // ==========================================
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `<span>${message}</span>`;
        toastContainer.appendChild(toast);

        setTimeout(() => {
            toast.classList.add('fade-out');
            toast.addEventListener('animationend', () => toast.remove());
        }, 3500);
    }

    // ==========================================
    // RENDERIZADO DEL FLUJO DE ESTADOS Y TARJETAS
    // ==========================================
    const ESTADOS_CONFIG = {
        'PENDIENTE_ENVIO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>Pendiente Envío', class: 'pendiente_envio' },
        'EN_TRANSITO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>En Tránsito', class: 'en_transito' },
        'ENTREGADO_SANTIAGO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>Entregado (Sntg)', class: 'entregado_santiago' },
        'RECIBIDO_TESORERIA': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>Recibido Tesoreria', class: 'recibido_tesoreria' },
        'DEPOSITADO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>Depositado', class: 'depositado' },
        'RECHAZADO': { label: '<svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line></svg>Rechazado', class: 'rechazado' }
    };

    function renderTarjetas(cobranzas, containerEl) {
        if (!containerEl) return;

        if (!cobranzas || cobranzas.length === 0) {
            containerEl.innerHTML = `<div class="empty-state"><p>No se encontraron registros.</p></div>`;
            return;
        }

        containerEl.innerHTML = cobranzas.map(item => {
            const totalMonto = item.cheques.reduce((sum, chk) => sum + parseFloat(chk.monto || 0), 0);
            const configEstado = ESTADOS_CONFIG[item.estado] || { label: item.estado, class: '' };
            const fechaFormateada = item.created_at ? item.created_at.split(' ')[0] : '-';
            const tracking = item.numero_seguimiento || null;
            const tipoEntregaTexto = item.tipo_entrega ? (item.tipo_entrega === 'CHILEXPRESS' ? 'Chilexpress' : 'Santiago') : 'Pendiente';

            // Botón para completar envío o editar cheques sólo si está pendiente
            const btnCompletar = item.estado === 'PENDIENTE_ENVIO' ? `
                <div style="margin-top: 12px; display: flex; justify-content: flex-end; gap: 8px;">
                    <button type="button" class="btn-completar-envio" style="background-color: var(--color-accent-light); color: var(--color-accent); border: 1px solid var(--color-border); display: inline-flex; align-items: center; justify-content: center; padding: 8px 12px;" onclick="abrirModalEditar(${item.id})">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="margin-right: 6px;">
                            <path d="M9.405 1.05c-.413-1.4-2.397-1.4-2.81 0l-.1.34a1.464 1.464 0 0 1-2.105.872l-.31-.17c-1.283-.698-2.686.705-1.987 1.987l.169.311c.446.82.023 1.841-.872 2.105l-.34.1c-1.4.413-1.4 2.397 0 2.81l.34.1a1.464 1.464 0 0 1 .872 2.105l-.17.31c-.698 1.283.705 2.686 1.987 1.987l.311-.169a1.464 1.464 0 0 1 2.105.872l.1.34c.413 1.4 2.397 1.4 2.81 0l.1-.34a1.464 1.464 0 0 1 2.105-.872l.31.17c1.283.698 2.686-.705 1.987-1.987l-.169-.311a1.464 1.464 0 0 1 .872-2.105l.34-.1c1.4-.413 1.4-2.397 0-2.81l-.34-.1a1.464 1.464 0 0 1-.872-2.105l.17-.31c.698-1.283-.705-2.686-1.987-1.987l-.311.169a1.464 1.464 0 0 1-2.105-.872zM8 10.93a2.929 2.929 0 1 1 0-5.86 2.929 2.929 0 0 1 0 5.86z"/>
                        </svg>
                        Editar Cheques
                    </button>
                    <button type="button" class="btn-completar-envio" style="background-color: #2e7d32; color: #ffffff; border: none; display: inline-flex; align-items: center; justify-content: center; padding: 8px 12px;" onclick="abrirModalCompletar(${item.id})">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" fill="currentColor" viewBox="0 0 16 16" style="margin-right: 6px;">
                            <path d="M15.854.146a.5.5 0 0 1 .11.54l-5.819 14.547a.75.75 0 0 1-1.329.124l-3.178-4.995L.643 7.184a.75.75 0 0 1 .124-1.33L15.314.037a.5.5 0 0 1 .54.11ZM6.636 10.07l2.761 4.338L14.13 2.576zm6.787-8.201L1.591 6.602l4.339 2.76z"/>
                        </svg>
                        Completar Envío
                    </button>
                </div>
            ` : '';

            return `
                <div class="envio-card">
                    <div class="envio-card-header">
                        <div>
                            <span class="envio-empresa">${item.empresa_nombre || '-'}</span>
                            <h4 class="envio-factura">Factura N° ${item.numero_factura}</h4>
                        </div>
                        <span class="status-badge ${configEstado.class}">${configEstado.label}</span>
                    </div>

                    <div class="envio-card-body">
                        <p><strong>Cliente:</strong> ${item.razon_social_cliente || '-'}</p>
                        <p><strong>Monto Factura:</strong> $${parseFloat(item.monto_total_factura || 0).toLocaleString('es-CL')}</p>
                        <p><strong>Total Cheques (${item.cheques.length}):</strong> $${totalMonto.toLocaleString('es-CL')}</p>
                        <p><strong>Fecha Registro:</strong> ${fechaFormateada}</p>
                        ${item.updated_at ? `<p style="font-size: 0.78rem; color: #0284c7; font-weight: 600; margin-top: 2px;"><strong>Última Modificación:</strong> ${item.updated_at.replace('T', ' ')}</p>` : ''}
                        <p><strong>Entrega:</strong> ${tipoEntregaTexto} ${tracking ? `(OT: ${tracking})` : ''}</p>
                        ${btnCompletar}
                    </div>

                    <div class="envio-card-cheques">
                        <h5>Detalle de Cheques:</h5>
                        <ul>
                            ${item.cheques.map(c => `
                                <li>
                                    <div class="cheque-li-info">
                                        <div class="cheque-li-main">
                                            <span>${c.banco} - N° ${c.numero_cheque}</span>
                                            <strong>$${parseFloat(c.monto || 0).toLocaleString('es-CL')}</strong>
                                        </div>
                                        ${c.comentario ? `<p class="cheque-comentario">${c.comentario}</p>` : ''}
                                    </div>
                                </li>
                            `).join('')}
                        </ul>
                    </div>
                </div>
            `;
        }).join('');
    }

    // Helper para ordenar colecciones por última modificación
    function ordenarColeccion(coleccion, criterio) {
        if (!coleccion) return [];
        return [...coleccion].sort((a, b) => {
            const fechaA = new Date(a.updated_at || a.created_at);
            const fechaB = new Date(b.updated_at || b.created_at);
            if (criterio === 'fecha_desc') {
                return fechaB - fechaA;
            } else if (criterio === 'fecha_asc') {
                return fechaA - fechaB;
            } else if (criterio === 'estado') {
                return a.estado.localeCompare(b.estado);
            }
            return 0;
        });
    }

    // ==========================================
    // CARGA DEL SEGUIMIENTO (PENDIENTES) DESDE LA API REAL
    // ==========================================
    async function cargarSeguimiento() {
        const listaPorEnviar = document.getElementById('listaPorEnviar');
        if (listaPorEnviar) listaPorEnviar.innerHTML = `<div class="empty-state"><p>Cargando cobranzas...</p></div>`;

        const busqueda = inputBuscar ? inputBuscar.value.trim() : '';

        const params = new URLSearchParams();
        if (busqueda) params.append('busqueda', busqueda);

        try {
            const response = await fetch(`api/get_mis_cobranzas.php?${params.toString()}`);
            const data = await response.json();

            if (!data.success) {
                if (listaPorEnviar) listaPorEnviar.innerHTML = `<div class="empty-state"><p>Error al cargar.</p></div>`;
                showToast(data.message || 'Error al cargar las cobranzas', 'error');
                return;
            }

            cobranzasPendientesGlobal = data.data.por_enviar || [];
            const ordenarSelect = document.getElementById('ordenarPendientes');
            const criterio = ordenarSelect ? ordenarSelect.value : 'fecha_desc';
            const sortedData = ordenarColeccion(cobranzasPendientesGlobal, criterio);

            renderTarjetas(sortedData, listaPorEnviar);

            // Actualizar badge naranja en la pestaña
            actualizarBadgePendientes(data.data.por_enviar ? data.data.por_enviar.length : 0);

        } catch (err) {
            if (listaPorEnviar) listaPorEnviar.innerHTML = `<div class="empty-state"><p>Error de conexión.</p></div>`;
            showToast('Error de conexión. Verifique su red.', 'error');
        }
    }


    // ==========================================
    // CARGA DEL HISTORIAL DESDE LA API REAL (MODAL)
    // ==========================================
    async function cargarHistorial() {
        const listaEnviadosModal = document.getElementById('listaEnviadosModal');
        if (listaEnviadosModal) listaEnviadosModal.innerHTML = `<div class="empty-state"><p>Cargando historial...</p></div>`;

        const inputBuscarHistorial = document.getElementById('inputBuscarHistorial');
        const filtroEstadoHistorial = document.getElementById('filtroEstadoHistorial');
        const ordenarHistorial = document.getElementById('ordenarHistorial');

        const busqueda = inputBuscarHistorial ? inputBuscarHistorial.value.trim() : '';
        const estado = filtroEstadoHistorial ? filtroEstadoHistorial.value : 'TODOS';

        const params = new URLSearchParams();
        if (busqueda) params.append('busqueda', busqueda);
        if (estado && estado !== 'TODOS') params.append('estado', estado);

        try {
            const response = await fetch(`api/get_mis_cobranzas.php?${params.toString()}`);
            const data = await response.json();

            if (!data.success) {
                if (listaEnviadosModal) listaEnviadosModal.innerHTML = `<div class="empty-state"><p>Error al cargar.</p></div>`;
                showToast(data.message || 'Error al cargar las cobranzas', 'error');
                return;
            }

            const criterio = ordenarHistorial ? ordenarHistorial.value : 'fecha_desc';
            const sortedData = ordenarColeccion(data.data.enviados, criterio);

            renderTarjetas(sortedData, listaEnviadosModal);

        } catch (err) {
            if (listaEnviadosModal) listaEnviadosModal.innerHTML = `<div class="empty-state"><p>Error de conexión.</p></div>`;
            showToast('Error de conexión. Verifique su red.', 'error');
        }
    }

    if (inputBuscar) inputBuscar.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(cargarSeguimiento, 400);
    });

    const ordenarPendientes = document.getElementById('ordenarPendientes');
    if (ordenarPendientes) ordenarPendientes.addEventListener('change', cargarSeguimiento);

    const inputBuscarHistorial = document.getElementById('inputBuscarHistorial');
    const filtroEstadoHistorial = document.getElementById('filtroEstadoHistorial');
    const ordenarHistorial = document.getElementById('ordenarHistorial');

    if (inputBuscarHistorial) {
        inputBuscarHistorial.addEventListener('input', () => {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(cargarHistorial, 400);
        });
    }
    if (filtroEstadoHistorial) filtroEstadoHistorial.addEventListener('change', cargarHistorial);
    if (ordenarHistorial) ordenarHistorial.addEventListener('change', cargarHistorial);

    // Modal Historial
    const modalHistorial = document.getElementById('modalHistorial');
    const btnAbrirHistorial = document.getElementById('btnAbrirHistorial');
    const btnCerrarModalHistorial = document.getElementById('btnCerrarModalHistorial');

    if (btnAbrirHistorial) {
        btnAbrirHistorial.addEventListener('click', () => {
            modalHistorial.style.display = 'flex';
            cargarHistorial();
        });
    }
    if (btnCerrarModalHistorial) {
        btnCerrarModalHistorial.addEventListener('click', () => {
            modalHistorial.style.display = 'none';
        });
    }

    // ==========================================
    // BÚSQUEDA REAL DE FACTURA EN API
    // ==========================================
    function limpiarInfoCliente() {
        infoClienteBox.style.display = 'none';
        if (errorClienteBox) errorClienteBox.style.display = 'none';
        montoFacturaActual = 0;
        if (lblMontoFacturaResumen) lblMontoFacturaResumen.textContent = '$0';
        rutClienteInput.value = '';
        if (razonSocialClienteInput) razonSocialClienteInput.value = '';
        if (montoTotalFacturaInput) montoTotalFacturaInput.value = '';

        emailClienteERP = '';
        if (chkEmailCliente) chkEmailCliente.checked = false;
        if (wrapperEmailCliente) wrapperEmailCliente.style.display = 'none';
        emailClienteInput.value = '';
        emailClienteInput.disabled = true;

        lblNombreCliente.textContent = '-';
        lblRutCliente.textContent = '-';
        lblMontoFactura.textContent = '0';
        calcularTotalCheques();
    }

    // Función para formatear RUT (ej: 12345678-9 -> 12.345.678-9)
    function formatRut(rutInput) {
        if (!rutInput) return '-';
        // Limpiar todo excepto números y k/K
        let cleaned = rutInput.toString().replace(/[^0-9kK]/g, '');
        if (cleaned.length < 2) return rutInput;

        let rutStr = cleaned.slice(0, -1);
        let dv = cleaned.slice(-1).toUpperCase();

        // Poner puntos a los miles
        let formatRut = '';
        while (rutStr.length > 3) {
            formatRut = '.' + rutStr.slice(-3) + formatRut;
            rutStr = rutStr.slice(0, -3);
        }
        formatRut = rutStr + formatRut + '-' + dv;
        return formatRut;
    }

    async function buscarFactura(empresaId, numeroFactura) {
        try {
            const response = await fetch(
                `api/get_factura.php?empresa_id=${encodeURIComponent(empresaId)}&numero_factura=${encodeURIComponent(numeroFactura)}`
            );
            const data = await response.json();

            if (!response.ok || !data.success) {
                limpiarInfoCliente();
                if (errorClienteBox && data.message && data.message.includes('no encontrada')) {
                    errorClienteBox.style.display = 'block';
                } else if (data.message) {
                    showToast(data.message, 'error');
                }
                return;
            }

            const factura = data.data;
            montoFacturaActual = parseFloat(factura.monto_total_factura) || 0;

            // Ocultar el recuadro de error si estaba visible
            if (errorClienteBox) errorClienteBox.style.display = 'none';

            lblNombreCliente.textContent = factura.razon_social || '-';
            lblRutCliente.textContent = formatRut(factura.rut_cliente);
            lblMontoFactura.textContent = montoFacturaActual.toLocaleString('es-CL');
            if (lblMontoFacturaResumen) {
                lblMontoFacturaResumen.textContent = '$' + montoFacturaActual.toLocaleString('es-CL');
            }

            rutClienteInput.value = factura.rut_cliente || '';
            if (razonSocialClienteInput) razonSocialClienteInput.value = factura.razon_social || '';
            if (montoTotalFacturaInput) montoTotalFacturaInput.value = montoFacturaActual;
            emailClienteERP = factura.email_cliente || '';
            if (chkEmailCliente && chkEmailCliente.checked) {
                emailClienteInput.value = emailClienteERP;
            } else {
                emailClienteInput.value = '';
            }

            infoClienteBox.style.display = 'flex';
            calcularTotalCheques();

        } catch (err) {
            limpiarInfoCliente();
            showToast('Error de conexión al buscar factura.', 'error');
        }
    }

    numFacturaInput.addEventListener('input', (e) => {
        const val = e.target.value.replace(/\D/g, '');
        e.target.value = val;

        clearTimeout(debounceTimer);

        if (val.length < 4) {
            limpiarInfoCliente();
            return;
        }

        const empresaId = empresaSelect ? empresaSelect.value : '';
        if (!empresaId) {
            showToast('Seleccione una empresa primero.', 'error');
            return;
        }

        debounceTimer = setTimeout(() => {
            buscarFactura(empresaId, val);
        }, 600);
    });

    // Si cambia la empresa, limpiar la info del cliente y re-buscar si hay factura
    if (empresaSelect) {
        empresaSelect.addEventListener('change', () => {
            const val = numFacturaInput.value.replace(/\D/g, '');
            if (val.length >= 4) {
                const empresaId = empresaSelect.value;
                if (empresaId) buscarFactura(empresaId, val);
            } else {
                limpiarInfoCliente();
            }
        });
    }

    // ==========================================
    // REGISTRO Y SUMA DINÁMICA DE CHEQUES
    // ==========================================
    function agregarCheque() {
        let ultimoBanco = '';
        const ultimoCard = contenedorCheques.lastElementChild;
        if (ultimoCard) {
            const selectBanco = ultimoCard.querySelector('select[name="banco[]"]');
            if (selectBanco) ultimoBanco = selectBanco.value;
        }

        contadorCheques++;
        const chequeId = contadorCheques;

        const card = document.createElement('div');
        card.className = 'cheque-card';
        card.id = `cheque_item_${chequeId}`;

        card.innerHTML = `
            <div class="cheque-header">
                <span class="cheque-title">Cheque #${chequeId}</span>
                ${chequeId > 1 ? `
                    <button type="button" class="btn-eliminar-cheque" onclick="eliminarCheque(${chequeId})">Quitar</button>
                ` : ''}
            </div>

            <div class="form-group">
                <label>Foto del Cheque</label>
                <label for="fotoCheque_${chequeId}" class="custom-file-upload">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: middle; display: inline-block;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                    </svg>
                    <span>Tomar Foto Cheque</span>
                </label>
                <input type="file" id="fotoCheque_${chequeId}" name="foto_cheque[]" accept="image/*" capture="environment" class="input-file-hidden" required>
                
                <div id="boxPreviewCheque_${chequeId}" class="preview-wrapper" style="display: none;">
                    <img id="imgCheque_${chequeId}" class="preview-img" alt="Vista previa cheque">
                    <button type="button" class="btn-quitar-foto" onclick="quitarImagen('fotoCheque_${chequeId}', 'imgCheque_${chequeId}', 'boxPreviewCheque_${chequeId}')">Quitar Foto</button>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Banco Emisor</label>
                    <select name="banco[]" required>
                        <option value="">-- Seleccionar --</option>
                        <option value="Banco de Chile">Banco de Chile</option>
                        <option value="Santander">Santander</option>
                        <option value="BCI">BCI</option>
                        <option value="Estado">Banco Estado</option>
                        <option value="Scotiabank">Scotiabank</option>
                        <option value="Itaú">Itaú</option>
                        <option value="Otro">Otro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>N° Cheque</label>
                    <input type="text" name="numero_cheque[]" inputmode="numeric" pattern="[0-9]*" placeholder="N° de serie" required>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Monto ($)</label>
                    <input type="text" inputmode="numeric" class="input-monto-cheque" placeholder="0" required>
                    <input type="hidden" name="monto_cheque[]" class="hidden-monto-cheque">
                </div>
                <div class="form-group">
                    <label>Fecha Vencimiento</label>
                    <input type="date" name="fecha_vencimiento[]" required>
                </div>
            </div>

            <div class="form-group">
                <label class="label-opcional">Comentario <span class="tag-opcional">(Opcional)</span></label>
                <textarea name="comentario_cheque[]" class="textarea-comentario" placeholder="Ej: Pago parcial de factura, acuerdo con cliente..."></textarea>
            </div>
        `;

        contenedorCheques.appendChild(card);

        if (ultimoBanco) {
            const selectBancoNuevo = card.querySelector('select[name="banco[]"]');
            if (selectBancoNuevo) selectBancoNuevo.value = ultimoBanco;
        }

        configurarPreviewConBorrado(`fotoCheque_${chequeId}`, `imgCheque_${chequeId}`, `boxPreviewCheque_${chequeId}`);

        const visibleMonto = card.querySelector('.input-monto-cheque');
        const hiddenMonto = card.querySelector('.hidden-monto-cheque');

        visibleMonto.addEventListener('input', (e) => {
            let cleanVal = e.target.value.replace(/\D/g, '');
            if (cleanVal) {
                visibleMonto.value = parseInt(cleanVal, 10).toLocaleString('es-CL');
                hiddenMonto.value = cleanVal;
            } else {
                visibleMonto.value = '';
                hiddenMonto.value = '';
            }
            calcularTotalCheques();
        });

        calcularTotalCheques();
    }

    window.eliminarCheque = function (id) {
        if (!confirm('¿Está seguro de eliminar este cheque de la cobranza?')) {
            return;
        }
        const tarjeta = document.getElementById(`cheque_item_${id}`);
        if (tarjeta) {
            tarjeta.remove();
            calcularTotalCheques();
        }
    };

    function obtenerTotalCheques() {
        const hiddenInputs = document.querySelectorAll('.hidden-monto-cheque');
        let total = 0;
        hiddenInputs.forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        return total;
    }

    function calcularTotalCheques() {
        const total = obtenerTotalCheques();
        lblTotalCheques.textContent = '$' + total.toLocaleString('es-CL');
    }

    agregarCheque();
    btnAgregarCheque.addEventListener('click', agregarCheque);

    // ==========================================
    // ENVÍO DEL FORMULARIO — FETCH REAL A LA API
    // ==========================================
    formCobranza.addEventListener('submit', async (e) => {
        e.preventDefault();

        if (!formCobranza.checkValidity()) {
            showToast('Complete todos los campos requeridos.', 'error');
            formCobranza.reportValidity();
            return;
        }

        if (!rutClienteInput || !rutClienteInput.value) {
            showToast('Debe ingresar un N° de Factura válido y esperar a que carguen los datos del cliente.', 'error');
            return;
        }

        // --- VALIDACIÓN DE COINCIDENCIA DE MONTOS Y CONFIRMACIÓN ---
        const totalChequesVal = obtenerTotalCheques();
        const totalFacturaVal = parseFloat(montoTotalFacturaInput.value) || 0;

        if (!formCobranza.dataset.bypassDiscrepancia) {
            formularioConfirmando = formCobranza;
            const titleEl = document.getElementById('modalAdvertenciaTitle');
            const text1El = document.getElementById('modalAdvertenciaText1');
            const text2El = document.getElementById('modalAdvertenciaText2');
            const btnCancelEl = document.getElementById('btnCancelarAdvertencia');
            const btnConfirmEl = document.getElementById('btnEnviarIgualmente');

            if (totalChequesVal !== totalFacturaVal) {
                titleEl.textContent = 'Diferencia en Montos';
                text1El.innerHTML = `El monto total de los cheques (<strong>$${totalChequesVal.toLocaleString('es-CL')}</strong>) no coincide con el monto total de la factura (<strong>$${totalFacturaVal.toLocaleString('es-CL')}</strong>).`;
                text2El.textContent = 'Le sugerimos justificar esta diferencia en el campo de comentarios de los cheques antes de proceder. ¿Desea enviar de todas formas?';
                btnCancelEl.textContent = 'Cerrar y Revisar';
                btnConfirmEl.textContent = 'Enviar Igualmente';
            } else {
                titleEl.textContent = 'Confirmar Registro';
                text1El.innerHTML = `El monto total de los cheques coincide perfectamente con el monto total de la factura (<strong>$${totalFacturaVal.toLocaleString('es-CL')}</strong>).`;
                text2El.textContent = '¿Está seguro de registrar esta cobranza?';
                btnCancelEl.textContent = 'Cancelar';
                btnConfirmEl.textContent = 'Confirmar Registro';
            }

            document.getElementById('modalAdvertenciaMontos').style.display = 'flex';
            return;
        }

        const btnSubmit = formCobranza.querySelector('.btn-submit');
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Guardando...';

        let controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 60000);

        try {
            // ── Construir FormData desde cero (evita enviar archivos originales sin comprimir) ──
            const formData = new FormData();

            // Campos de texto y ocultos
            formData.set('empresa_id', empresaSelect.value);
            formData.set('numero_factura', numFacturaInput.value);
            formData.set('rut_cliente', rutClienteInput.value);
            formData.set('razon_social_cliente', razonSocialClienteInput ? razonSocialClienteInput.value : '');
            formData.set('monto_total_factura', montoTotalFacturaInput ? montoTotalFacturaInput.value : '');
            formData.set('email_cliente', emailClienteInput.value);
            formData.set('email_tesoreria', document.getElementById('emailTesoreria').value);
            if (vendedorIdInput && vendedorIdInput.value) {
                formData.set('vendedor_id', vendedorIdInput.value);
            }

            // ── Cheques: campos de texto ──
            const bancos = formCobranza.querySelectorAll('select[name="banco[]"]');
            const numsCheque = formCobranza.querySelectorAll('input[name="numero_cheque[]"]');
            const montosCheque = formCobranza.querySelectorAll('input.hidden-monto-cheque');
            const fechas = formCobranza.querySelectorAll('input[name="fecha_vencimiento[]"]');
            const comentarios = formCobranza.querySelectorAll('textarea[name="comentario_cheque[]"]');

            bancos.forEach(el => formData.append('banco[]', el.value));
            numsCheque.forEach(el => formData.append('numero_cheque[]', el.value));
            montosCheque.forEach(el => formData.append('monto_cheque[]', el.value));
            fechas.forEach(el => formData.append('fecha_vencimiento[]', el.value));
            comentarios.forEach(el => formData.append('comentario_cheque[]', el.value));

            // ── Fotos de cheques (comprimidas) ──
            const inputsFotoCheque = formCobranza.querySelectorAll('input[name="foto_cheque[]"]');
            for (const input of inputsFotoCheque) {
                if (input.files && input.files[0]) {
                    btnSubmit.textContent = 'Comprimiendo fotos...';
                    const compressed = await compressImage(input.files[0]);
                    formData.append('foto_cheque[]', compressed, compressed.name);
                }
            }

            btnSubmit.textContent = 'Subiendo...';

            // ── Fetch con reintentos automáticos (hasta 3 intentos por WiFi inestable) ──
            let response, data, lastError;
            for (let intento = 1; intento <= 3; intento++) {
                try {
                    if (intento > 1) {
                        btnSubmit.textContent = `Reintentando (${intento}/3)...`;
                        await new Promise(r => setTimeout(r, 1500)); // espera 1.5s antes de reintentar
                        controller = new AbortController();
                        setTimeout(() => controller.abort(), 60000);
                    }
                    response = await fetch('api/guardar_cobranza.php', {
                        method: 'POST',
                        body: formData,
                        signal: controller.signal
                    });
                    data = await response.json();
                    // Si el error es "parcialmente" por red, reintentar
                    if (!response.ok && data?.message?.toLowerCase().includes('parcial') && intento < 3) {
                        lastError = data.message;
                        continue;
                    }
                    break; // éxito o error definitivo
                } catch (fetchErr) {
                    lastError = fetchErr.message;
                    if (intento === 3) throw fetchErr;
                }
            }

            if (!response.ok || !data.success) {
                showToast((data.errors ? data.errors.join(' | ') : data.message) || 'Error al guardar la cobranza.', 'error');
                return;
            }

            showToast('Cobranza registrada con éxito.', 'success');

            // Resetear formulario
            formCobranza.reset();
            contenedorCheques.innerHTML = '';
            contadorCheques = 0;
            agregarCheque();
            limpiarInfoCliente();

            // Cambiar automáticamente a la pestaña de seguimiento
            setTimeout(() => {
                tabSeguimiento.click();
            }, 800);

        } catch (err) {
            clearTimeout(timeoutId);
            console.error('[Error de Envío]', err);
            if (err.name === 'AbortError') {
                showToast('El servidor tardó demasiado en responder (timeout 60s). Verifique conexión.', 'error');
            } else {
                showToast('Error de conexión. Intente nuevamente.', 'error');
            }
        } finally {
            formCobranza.removeAttribute('data-bypass-discrepancia');
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Registrar Cobranza';
        }
    });

    // ==========================================
    // MODAL COMPLETAR ENVÍO — EVENTOS
    // ==========================================
    {
        const modalCompletarEnvio = document.getElementById('modalCompletarEnvio');
        const formCompletarEnvio = document.getElementById('formCompletarEnvio');
        const modalTipoEntrega = document.getElementById('modalTipoEntrega');
        const modalSeccionChilexpress = document.getElementById('modalSeccionChilexpress');
        const modalSeccionSantiago = document.getElementById('modalSeccionSantiago');
        const btnCerrarModal = document.getElementById('btnCerrarModal');

        modalTipoEntrega.addEventListener('change', (e) => {
            const valor = e.target.value;
            modalSeccionChilexpress.style.display = valor === 'CHILEXPRESS' ? 'block' : 'none';
            modalSeccionSantiago.style.display = valor === 'PRESENCIAL_SANTIAGO' ? 'block' : 'none';

            const fotoComp = document.getElementById('modalFotoComprobante');
            const fotoFirm = document.getElementById('modalFotoFirma');
            if (valor === 'CHILEXPRESS') {
                if (fotoComp) fotoComp.required = true;
                if (fotoFirm) fotoFirm.required = false;
            } else if (valor === 'PRESENCIAL_SANTIAGO') {
                if (fotoComp) fotoComp.required = false;
                if (fotoFirm) fotoFirm.required = true;
            } else {
                if (fotoComp) fotoComp.required = false;
                if (fotoFirm) fotoFirm.required = false;
            }
        });

        configurarPreviewConBorrado('modalFotoComprobante', 'modalImgComprobante', 'modalBoxPreviewComprobante');
        configurarPreviewConBorrado('modalFotoFirma', 'modalImgFirma', 'modalBoxPreviewFirma');

        btnCerrarModal.addEventListener('click', () => {
            modalCompletarEnvio.style.display = 'none';
            formCompletarEnvio.reset();
            quitarImagen('modalFotoComprobante', 'modalImgComprobante', 'modalBoxPreviewComprobante');
            quitarImagen('modalFotoFirma', 'modalImgFirma', 'modalBoxPreviewFirma');
            modalSeccionChilexpress.style.display = 'none';
            modalSeccionSantiago.style.display = 'none';
        });

        window.abrirModalCompletar = function (cobranzaId) {
            document.getElementById('modalCobranzaId').value = cobranzaId;
            modalCompletarEnvio.style.display = 'flex';
        };

        formCompletarEnvio.addEventListener('submit', (e) => {
            e.preventDefault();

            if (!formCompletarEnvio.checkValidity()) {
                showToast('Complete todos los campos requeridos.', 'error');
                formCompletarEnvio.reportValidity();
                return;
            }

            pedirConfirmacionDespachoVendedor();
        });

        function pedirConfirmacionDespachoVendedor() {
            let modalConfirm = document.getElementById('modalConfirmarDespachoVendedor');
            if (!modalConfirm) {
                modalConfirm = document.createElement('div');
                modalConfirm.id = 'modalConfirmarDespachoVendedor';
                modalConfirm.className = 'modal-overlay';
                modalConfirm.style.cssText = 'display: none; z-index: 1100; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(4px);';
                modalConfirm.innerHTML = `
                    <div class="modal-content" style="max-width: 420px; border-radius: 12px; box-shadow: 0 20px 40px rgba(0,0,0,0.3); z-index: 1101;">
                        <h3 class="modal-title" style="color: #1e3a8a; margin-bottom: 10px; font-size: 1.15rem;">¿Confirmar envío de documentos?</h3>
                        <p style="font-size: 0.9rem; line-height: 1.4; color: #334155; margin-bottom: 20px;">
                            Estás a punto de declarar el despacho de la documentación de esta cobranza. Una vez enviado, Tesorería recibirá la notificación de recepción en su bandeja.
                        </p>
                        <div style="display: flex; gap: 10px; justify-content: flex-end;">
                            <button type="button" id="btnCancelarConfirmVendedor" style="padding: 10px 16px; background: #e2e8f0; color: #334155; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">Volver</button>
                            <button type="button" id="btnEjecutarEnvioVendedor" style="padding: 10px 16px; background: #1e3a8a; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 700;">Sí, Confirmar Envío</button>
                        </div>
                    </div>
                `;
                document.body.appendChild(modalConfirm);

                document.getElementById('btnCancelarConfirmVendedor').onclick = () => {
                    modalConfirm.style.display = 'none';
                };
                document.getElementById('btnEjecutarEnvioVendedor').onclick = () => {
                    modalConfirm.style.display = 'none';
                    ejecutarSubidaEnvioVendedor();
                };
            }
            modalConfirm.style.display = 'flex';
        }

        async function ejecutarSubidaEnvioVendedor() {
            const btnSubmitModal = document.getElementById('btnConfirmarEnvioSubmit');
            btnSubmitModal.disabled = true;
            btnSubmitModal.textContent = 'Enviando...';

            try {
                const formData = new FormData();
                formData.set('cobranza_id', document.getElementById('modalCobranzaId').value);
                formData.set('tipo_entrega', modalTipoEntrega.value);

                if (modalTipoEntrega.value === 'CHILEXPRESS') {
                    formData.set('numero_seguimiento', document.getElementById('modalNumSeguimiento').value);
                    const inputComprobante = document.getElementById('modalFotoComprobante');
                    if (inputComprobante.files && inputComprobante.files[0]) {
                        btnSubmitModal.textContent = 'Comprimiendo...';
                        const compressed = await compressImage(inputComprobante.files[0]);
                        formData.set('foto_comprobante', compressed, compressed.name);
                    }
                } else {
                    const inputFirma = document.getElementById('modalFotoFirma');
                    if (inputFirma.files && inputFirma.files[0]) {
                        btnSubmitModal.textContent = 'Comprimiendo...';
                        const compressed = await compressImage(inputFirma.files[0]);
                        formData.set('foto_firma', compressed, compressed.name);
                    }
                }

                btnSubmitModal.textContent = 'Subiendo...';

                const response = await fetch('api/completar_envio.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    showToast(data.message || 'Error al completar el envío.', 'error');
                    return;
                }

                showToast('Envío completado con éxito.', 'success');
                modalCompletarEnvio.style.display = 'none';
                formCompletarEnvio.reset();
                quitarImagen('modalFotoComprobante', 'modalImgComprobante', 'modalBoxPreviewComprobante');
                quitarImagen('modalFotoFirma', 'modalImgFirma', 'modalBoxPreviewFirma');
                modalSeccionChilexpress.style.display = 'none';
                modalSeccionSantiago.style.display = 'none';
                cargarSeguimiento();

            } catch (err) {
                console.error(err);
                showToast('Error de conexión al completar el envío.', 'error');
            } finally {
                btnSubmitModal.disabled = false;
                btnSubmitModal.textContent = 'Confirmar Envío';
            }
        }
    }

    // ==========================================
    // MODAL ADVERTENCIA DISCREPANCIA MONTOS — EVENTOS
    // ==========================================
    {
        const modalAdvertenciaMontos = document.getElementById('modalAdvertenciaMontos');
        const btnCancelarAdvertencia = document.getElementById('btnCancelarAdvertencia');
        const btnEnviarIgualmente = document.getElementById('btnEnviarIgualmente');

        if (btnCancelarAdvertencia) {
            btnCancelarAdvertencia.addEventListener('click', () => {
                modalAdvertenciaMontos.style.display = 'none';
            });
        }

        if (btnEnviarIgualmente) {
            btnEnviarIgualmente.addEventListener('click', () => {
                modalAdvertenciaMontos.style.display = 'none';
                if (formularioConfirmando) {
                    formularioConfirmando.dataset.bypassDiscrepancia = 'true';
                    formularioConfirmando.dispatchEvent(new Event('submit', { cancelable: true }));
                } else {
                    formCobranza.dataset.bypassDiscrepancia = 'true';
                    formCobranza.dispatchEvent(new Event('submit', { cancelable: true }));
                }
            });
        }
    }

    // ==========================================
    // MODAL EDITAR CHEQUES — LOGICA Y EVENTOS
    // ==========================================
    const modalEditarCheques = document.getElementById('modalEditarCheques');
    const formEditarCheques = document.getElementById('formEditarCheques');
    const contenedorEditarCheques = document.getElementById('contenedorEditarCheques');
    const btnAgregarChequeEdicion = document.getElementById('btnAgregarChequeEdicion');
    const lblTotalChequesEdicion = document.getElementById('lblTotalChequesEdicion');
    const btnCancelarEditarCheques = document.getElementById('btnCancelarEditarCheques');
    const btnCerrarModalEditarCheques = document.getElementById('btnCerrarModalEditarCheques');

    // Función para crear fila de cheque en edición
    function crearChequeRowEdicion(idx, data = null) {
        const div = document.createElement('div');
        div.className = 'cheque-card';
        div.id = `cheque_edicion_item_${idx}`;

        const chqIdVal = data ? data.id : `nuevo_${idx}`;
        const fotoRequerida = data ? '' : 'required';

        // Previsualización de la foto (si existe)
        const displayPreview = data ? 'flex' : 'none';
        const displayUpload = data ? 'none' : 'inline-flex';
        const imgUrl = data ? data.foto_cheque_url : '';

        div.innerHTML = `
            <div class="cheque-header">
                <span class="cheque-title">Cheque #${idx} ${data ? '(Existente)' : '(Nuevo)'}</span>
                <button type="button" class="btn-eliminar-cheque" onclick="eliminarChequeEdicion(${idx}, ${data ? data.id : 'null'})">Quitar</button>
            </div>

            <input type="hidden" name="cheque_id[]" value="${chqIdVal}">

            <div class="form-group">
                <label>Foto del Cheque</label>
                <label for="fotoChequeEdicion_${idx}" class="custom-file-upload" style="display: ${displayUpload};">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="margin-right: 6px; vertical-align: middle; display: inline-block;">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0zM18.75 10.5h.008v.008h-.008V10.5z" />
                    </svg>
                    <span>Tomar Foto Cheque</span>
                </label>
                <input type="file" id="fotoChequeEdicion_${idx}" name="foto_cheque[]" accept="image/*" capture="environment" class="input-file-hidden" ${fotoRequerida}>
                
                <div id="boxPreviewChequeEdicion_${idx}" class="preview-wrapper" style="display: ${displayPreview};">
                    <img id="imgChequeEdicion_${idx}" src="${imgUrl}" class="preview-img" alt="Vista previa cheque">
                    <button type="button" class="btn-quitar-foto" onclick="quitarImagen('fotoChequeEdicion_${idx}', 'imgChequeEdicion_${idx}', 'boxPreviewChequeEdicion_${idx}')">Quitar Foto</button>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Banco Emisor</label>
                    <select name="banco[]" required>
                        <option value="">-- Seleccionar --</option>
                        <option value="Banco de Chile" ${data && data.banco === 'Banco de Chile' ? 'selected' : ''}>Banco de Chile</option>
                        <option value="Santander" ${data && data.banco === 'Santander' ? 'selected' : ''}>Santander</option>
                        <option value="BCI" ${data && data.banco === 'BCI' ? 'selected' : ''}>BCI</option>
                        <option value="Estado" ${data && data.banco === 'Estado' ? 'selected' : ''}>Banco Estado</option>
                        <option value="Scotiabank" ${data && data.banco === 'Scotiabank' ? 'selected' : ''}>Scotiabank</option>
                        <option value="Itaú" ${data && data.banco === 'Itaú' ? 'selected' : ''}>Itaú</option>
                        <option value="Otro" ${data && data.banco === 'Otro' ? 'selected' : ''}>Otro</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>N° Cheque</label>
                    <input type="text" name="numero_cheque[]" value="${data ? data.numero_cheque : ''}" inputmode="numeric" pattern="[0-9]*" placeholder="N° de serie" required>
                </div>
            </div>

            <div class="grid-2">
                <div class="form-group">
                    <label>Monto ($)</label>
                    <input type="text" inputmode="numeric" class="input-monto-cheque-edicion" value="${data ? parseInt(data.monto || 0, 10).toLocaleString('es-CL') : ''}" placeholder="0" required>
                    <input type="hidden" name="monto_cheque[]" class="hidden-monto-cheque-edicion" value="${data ? data.monto : ''}">
                </div>
                <div class="form-group">
                    <label>Fecha Vencimiento</label>
                    <input type="date" name="fecha_vencimiento[]" value="${data ? data.fecha_vencimiento : ''}" required>
                </div>
            </div>

            <div class="form-group">
                <label class="label-opcional">Comentario <span class="tag-opcional">(Opcional)</span></label>
                <textarea name="comentario_cheque[]" class="textarea-comentario" placeholder="Ej: Pago parcial de factura, acuerdo con cliente...">${data && data.comentario ? data.comentario : ''}</textarea>
            </div>
        `;

        // Formateador de monto y recálculo
        const visibleMonto = div.querySelector('.input-monto-cheque-edicion');
        const hiddenMonto = div.querySelector('.hidden-monto-cheque-edicion');

        visibleMonto.addEventListener('input', (e) => {
            let cleanVal = e.target.value.replace(/\D/g, '');
            if (cleanVal) {
                visibleMonto.value = parseInt(cleanVal, 10).toLocaleString('es-CL');
                hiddenMonto.value = cleanVal;
            } else {
                visibleMonto.value = '';
                hiddenMonto.value = '';
            }
            calcularTotalChequesEdicion();
        });

        return div;
    }

    function calcularTotalChequesEdicion() {
        const hiddenInputs = contenedorEditarCheques.querySelectorAll('.hidden-monto-cheque-edicion');
        let total = 0;
        hiddenInputs.forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        lblTotalChequesEdicion.textContent = '$' + total.toLocaleString('es-CL');
    }

    window.eliminarChequeEdicion = function (idx, databaseId = null) {
        // Al menos debe quedar un cheque en el formulario (los activos en el cont)
        const tarjetasActivas = contenedorEditarCheques.querySelectorAll('.cheque-card');
        if (tarjetasActivas.length <= 1) {
            showToast('Debe dejar al menos un cheque en la cobranza.', 'error');
            return;
        }

        const el = document.getElementById(`cheque_edicion_item_${idx}`);
        if (el) {
            el.remove();
            if (databaseId) {
                eliminadosIdsEdicion.push(databaseId);
            }
            calcularTotalChequesEdicion();
        }
    };

    window.abrirModalEditar = function (cobranzaId) {
        const cobranza = cobranzasPendientesGlobal.find(c => c.id === cobranzaId);
        if (!cobranza) {
            showToast('No se encontró la cobranza localmente.', 'error');
            return;
        }

        document.getElementById('modalEditarCobranzaId').value = cobranzaId;
        montoFacturaEdicionActual = parseFloat(cobranza.monto_total_factura) || 0;
        contenedorEditarCheques.innerHTML = '';
        eliminadosIdsEdicion = [];
        contadorChequesEdicion = 0;

        cobranza.cheques.forEach(chk => {
            contadorChequesEdicion++;
            const row = crearChequeRowEdicion(contadorChequesEdicion, chk);
            contenedorEditarCheques.appendChild(row);

            // Configurar preview dinámico después de que el elemento existe en el DOM
            configurarPreviewConBorrado(`fotoChequeEdicion_${contadorChequesEdicion}`, `imgChequeEdicion_${contadorChequesEdicion}`, `boxPreviewChequeEdicion_${contadorChequesEdicion}`);
        });

        calcularTotalChequesEdicion();
        modalEditarCheques.style.display = 'flex';
    };

    if (btnAgregarChequeEdicion) {
        btnAgregarChequeEdicion.addEventListener('click', () => {
            let ultimoBanco = '';
            const ultimoCard = contenedorEditarCheques.lastElementChild;
            if (ultimoCard) {
                const selectBanco = ultimoCard.querySelector('select[name="banco[]"]');
                if (selectBanco) ultimoBanco = selectBanco.value;
            }

            contadorChequesEdicion++;
            const row = crearChequeRowEdicion(contadorChequesEdicion);
            contenedorEditarCheques.appendChild(row);

            // Configurar preview dinámico después de que el elemento existe en el DOM
            configurarPreviewConBorrado(`fotoChequeEdicion_${contadorChequesEdicion}`, `imgChequeEdicion_${contadorChequesEdicion}`, `boxPreviewChequeEdicion_${contadorChequesEdicion}`);

            if (ultimoBanco) {
                const selectBancoNuevo = row.querySelector('select[name="banco[]"]');
                if (selectBancoNuevo) selectBancoNuevo.value = ultimoBanco;
            }
        });
    }

    const cerrarEdicion = () => {
        modalEditarCheques.style.display = 'none';
        formEditarCheques.reset();
        contenedorEditarCheques.innerHTML = '';
        eliminadosIdsEdicion = [];
    };

    if (btnCancelarEditarCheques) btnCancelarEditarCheques.addEventListener('click', cerrarEdicion);
    if (btnCerrarModalEditarCheques) btnCerrarModalEditarCheques.addEventListener('click', cerrarEdicion);

    if (formEditarCheques) {
        formEditarCheques.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (!formEditarCheques.checkValidity()) {
                const invalidFields = [];
                formEditarCheques.querySelectorAll(':invalid').forEach(field => {
                    const labelText = field.closest('.form-group')?.querySelector('label')?.textContent || field.name || field.id || 'Campo';
                    invalidFields.push(labelText.trim().replace(/\s+/g, ' '));
                });
                showToast(`Complete los campos requeridos: ${invalidFields.join(', ')}`, 'error');
                formEditarCheques.reportValidity();
                return;
            }

            // --- VALIDACIÓN DE COINCIDENCIA DE MONTOS Y CONFIRMACIÓN EN EDICIÓN ---
            let totalChequesVal = 0;
            const hiddenInputs = contenedorEditarCheques.querySelectorAll('.hidden-monto-cheque-edicion');
            hiddenInputs.forEach(input => {
                totalChequesVal += parseFloat(input.value) || 0;
            });
            const totalFacturaVal = montoFacturaEdicionActual;

            if (!formEditarCheques.dataset.bypassDiscrepancia) {
                formularioConfirmando = formEditarCheques;
                const titleEl = document.getElementById('modalAdvertenciaTitle');
                const text1El = document.getElementById('modalAdvertenciaText1');
                const text2El = document.getElementById('modalAdvertenciaText2');
                const btnCancelEl = document.getElementById('btnCancelarAdvertencia');
                const btnConfirmEl = document.getElementById('btnEnviarIgualmente');

                if (totalChequesVal !== totalFacturaVal) {
                    titleEl.textContent = 'Diferencia en Montos';
                    text1El.innerHTML = `El monto total de los cheques (<strong>$${totalChequesVal.toLocaleString('es-CL')}</strong>) no coincide con el monto total de la factura (<strong>$${totalFacturaVal.toLocaleString('es-CL')}</strong>).`;
                    text2El.textContent = 'Le sugerimos justificar esta diferencia en el campo de comentarios de los cheques antes de proceder. ¿Desea guardar los cambios de todas formas?';
                    btnCancelEl.textContent = 'Cerrar y Revisar';
                    btnConfirmEl.textContent = 'Guardar Igualmente';
                } else {
                    titleEl.textContent = 'Confirmar Cambios';
                    text1El.innerHTML = `El monto total de los cheques coincide perfectamente con el monto total de la factura (<strong>$${totalFacturaVal.toLocaleString('es-CL')}</strong>).`;
                    text2El.textContent = '¿Está seguro de guardar estos cambios?';
                    btnCancelEl.textContent = 'Cancelar';
                    btnConfirmEl.textContent = 'Confirmar Cambios';
                }

                document.getElementById('modalAdvertenciaMontos').style.display = 'flex';
                return;
            }

            const btnSubmitEdicion = document.getElementById('btnGuardarChequesSubmit');
            btnSubmitEdicion.disabled = true;
            btnSubmitEdicion.textContent = 'Enviando...';

            try {
                const formData = new FormData();
                formData.set('cobranza_id', document.getElementById('modalEditarCobranzaId').value);

                // Agregar los IDs de cheques a eliminar
                eliminadosIdsEdicion.forEach(id => {
                    formData.append('eliminados_ids[]', id);
                });

                // Campos de los cheques usando consultas relativas al contenedor de cada tarjeta
                const cards = formEditarCheques.querySelectorAll('.cheque-card');

                for (let i = 0; i < cards.length; i++) {
                    const card = cards[i];
                    const idVal = card.querySelector('input[name="cheque_id[]"]').value;
                    const bancoVal = card.querySelector('select[name="banco[]"]').value;
                    const numVal = card.querySelector('input[name="numero_cheque[]"]').value;
                    const montoVal = card.querySelector('.hidden-monto-cheque-edicion').value;
                    const fechaVal = card.querySelector('input[name="fecha_vencimiento[]"]').value;
                    const comentarioVal = card.querySelector('textarea[name="comentario_cheque[]"]').value;
                    const fileInput = card.querySelector('input[type="file"]');

                    formData.append('cheque_id[]', idVal);
                    formData.append('banco[]', bancoVal);
                    formData.append('numero_cheque[]', numVal);
                    formData.append('monto_cheque[]', montoVal);
                    formData.append('fecha_vencimiento[]', fechaVal);
                    formData.append('comentario_cheque[]', comentarioVal);

                    // Archivos de imagen (foto del cheque)
                    if (fileInput && fileInput.files && fileInput.files[0]) {
                        btnSubmitEdicion.textContent = `Comprimiendo Cheque ${i + 1}...`;
                        const compressed = await compressImage(fileInput.files[0]);
                        formData.append('foto_cheque[]', compressed, compressed.name);
                    } else {
                        // Enviar placeholder vacío si no hay archivo nuevo para mantener alineación del array de fotos en PHP
                        formData.append('foto_cheque[]', new Blob(), '');
                    }
                }

                btnSubmitEdicion.textContent = 'Guardando cambios...';

                const response = await fetch('api/editar_cheques.php', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (!response.ok || !data.success) {
                    showToast(data.message || 'Error al guardar los cheques.', 'error');
                    return;
                }

                showToast('Cheques actualizados con éxito.', 'success');
                cerrarEdicion();
                cargarSeguimiento();

            } catch (err) {
                console.error(err);
                showToast('Error de conexión al actualizar cheques.', 'error');
            } finally {
                formEditarCheques.removeAttribute('data-bypass-discrepancia');
                btnSubmitEdicion.disabled = false;
                btnSubmitEdicion.textContent = 'Guardar Cambios';
            }
        });
    }

    // --- BLOQUEO DE SCROLL DE FONDO CON OBSERVER ---
    const modalObserver = new MutationObserver(() => {
        const modals = document.querySelectorAll('.modal-overlay');
        let anyOpen = false;
        modals.forEach(m => {
            if (m.style.display === 'flex' || m.style.display === 'block') {
                anyOpen = true;
            }
        });
        document.body.classList.toggle('modal-open', anyOpen);
    });

    document.querySelectorAll('.modal-overlay').forEach(modal => {
        modalObserver.observe(modal, { attributes: true, attributeFilter: ['style'] });
    });

}); // fin DOMContentLoaded

window.quitarImagen = function (idInput, idImg, idContainer) {
    const input = document.getElementById(idInput);
    const img = document.getElementById(idImg);
    const container = document.getElementById(idContainer);
    if (input) input.value = '';
    if (img) img.src = '';
    if (container) container.style.display = 'none';

    // Si hay un label asociado al input, volver a mostrarlo
    const label = document.querySelector(`label[for="${idInput}"]`);
    if (label) label.style.display = 'inline-flex';
};

window.configurarPreviewConBorrado = function (idInput, idImg, idContainer) {
    const input = document.getElementById(idInput);
    const img = document.getElementById(idImg);
    const container = document.getElementById(idContainer);

    if (input) {
        input.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = (event) => {
                    img.src = event.target.result;
                    container.style.display = 'flex';

                    // Ocultar el botón label para no duplicar UI
                    const label = document.querySelector(`label[for="${idInput}"]`);
                    if (label) label.style.display = 'none';
                };
                reader.readAsDataURL(file);
            }
        });
    }
};


// ==========================================
// COMPRESIÓN DE IMAGEN ANTES DE SUBIR
// Reduce fotos de tablet (10-20MB) a ~300KB
// ==========================================
window.compressImage = function (file, maxWidthPx = 1600, qualityJpeg = 0.8) {
    return new Promise((resolve) => {
        // Solo omitir si es pequeño (menos de 2MB)
        if (file.size < 2 * 1024 * 1024) {
            resolve(file);
            return;
        }

        const reader = new FileReader();
        reader.onerror = () => resolve(file); // fallback: enviar original si no se puede leer
        reader.onload = (e) => {
            const img = new Image();
            img.onerror = () => resolve(file); // fallback: formato no decodificable (ej: HEIC)
            img.onload = () => {
                let { width, height } = img;

                // Escalar si es más ancha que maxWidthPx
                if (width > maxWidthPx) {
                    height = Math.round((height * maxWidthPx) / width);
                    width = maxWidthPx;
                }

                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                canvas.toBlob(
                    (blob) => {
                        if (!blob) { resolve(file); return; } // fallback canvas fallo
                        const compressedFile = new File([blob], file.name.replace(/\.[^.]+$/, '.jpg'), {
                            type: 'image/jpeg',
                            lastModified: Date.now()
                        });
                        console.log(`[Compresión] ${file.name}: ${(file.size / 1024).toFixed(0)}KB → ${(compressedFile.size / 1024).toFixed(0)}KB`);
                        resolve(compressedFile);
                    },
                    'image/jpeg',
                    qualityJpeg
                );
            };
            img.src = e.target.result;
        };
        reader.readAsDataURL(file);
    });
};