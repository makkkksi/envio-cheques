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
    const selectTipoEntrega = document.getElementById('tipoEntrega');
    const seccionChilexpress = document.getElementById('seccionChilexpress');
    const seccionSantiago = document.getElementById('seccionSantiago');
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
    const empresaSelect = document.getElementById('empresaVendedor');
    const montoTotalFacturaInput = document.getElementById('montoTotalFactura');
    const errorClienteBox = document.getElementById('errorClienteBox');

    const listaEnvios = document.getElementById('listaEnvios');
    const inputBuscar = document.getElementById('inputBuscarSeguimiento');
    const filtroEstado = document.getElementById('filtroEstado');

    let contadorCheques = 0;
    let montoFacturaActual = 0;
    let debounceTimer = null;

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
        'INGRESADO':           { label: 'Ingresado',             class: 'badge-ingresado' },
        'EN_TRANSITO':         { label: 'En Tránsito',           class: 'badge-transito' },
        'RECIBIDO_TESORERIA':  { label: 'Recibido Tesorería',    class: 'badge-recibido' },
        'DEPOSITADO':          { label: 'Depositado',            class: 'badge-depositado' },
        'RECHAZADO':           { label: 'Rechazado',             class: 'badge-rechazado' }
    };

    function renderTarjetas(cobranzas) {
        if (!listaEnvios) return;

        if (!cobranzas || cobranzas.length === 0) {
            listaEnvios.innerHTML = `<div class="empty-state"><p>No se encontraron registros de cobranza.</p></div>`;
            return;
        }

        listaEnvios.innerHTML = cobranzas.map(item => {
            const totalMonto = item.cheques.reduce((sum, chk) => sum + parseFloat(chk.monto || 0), 0);
            const configEstado = ESTADOS_CONFIG[item.estado] || { label: item.estado, class: '' };
            const fechaFormateada = item.created_at ? item.created_at.split(' ')[0] : '-';
            const tracking = item.numero_seguimiento || null;

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
                        <p><strong>Total Cheques (${item.cheques.length}):</strong> $${totalMonto.toLocaleString('es-CL')}</p>
                        <p><strong>Fecha Registro:</strong> ${fechaFormateada}</p>
                        <p><strong>Entrega:</strong> ${item.tipo_entrega} ${tracking ? `(OT: ${tracking})` : ''}</p>
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

    // ==========================================
    // CARGA DEL HISTORIAL DESDE LA API REAL
    // ==========================================
    async function cargarSeguimiento() {
        if (!listaEnvios) return;

        listaEnvios.innerHTML = `<div class="empty-state"><p>Cargando cobranzas...</p></div>`;

        const estado = filtroEstado ? filtroEstado.value : 'TODOS';
        const busqueda = inputBuscar ? inputBuscar.value.trim() : '';

        const params = new URLSearchParams();
        if (estado && estado !== 'TODOS') params.append('estado', estado);
        if (busqueda) params.append('busqueda', busqueda);

        try {
            const response = await fetch(`api/get_mis_cobranzas.php?${params.toString()}`);
            const data = await response.json();

            if (!data.success) {
                listaEnvios.innerHTML = `<div class="empty-state"><p>Error al cargar las cobranzas.</p></div>`;
                showToast(data.message || 'Error al cargar las cobranzas', 'error');
                return;
            }

            renderTarjetas(data.data);

        } catch (err) {
            listaEnvios.innerHTML = `<div class="empty-state"><p>Error de conexión al cargar historial.</p></div>`;
            showToast('Error de conexión. Verifique su red.', 'error');
        }
    }

    if (inputBuscar) inputBuscar.addEventListener('input', () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(cargarSeguimiento, 400);
    });
    if (filtroEstado) filtroEstado.addEventListener('change', cargarSeguimiento);

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
        emailClienteInput.value = '';
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
            emailClienteInput.value = factura.email_cliente || '';

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
        const tarjeta = document.getElementById(`cheque_item_${id}`);
        if (tarjeta) {
            tarjeta.remove();
            calcularTotalCheques();
        }
    };

    function calcularTotalCheques() {
        const hiddenInputs = document.querySelectorAll('.hidden-monto-cheque');
        let total = 0;
        hiddenInputs.forEach(input => {
            total += parseFloat(input.value) || 0;
        });
        lblTotalCheques.textContent = '$' + total.toLocaleString('es-CL');
    }

    agregarCheque();
    btnAgregarCheque.addEventListener('click', agregarCheque);

    selectTipoEntrega.addEventListener('change', (e) => {
        const valor = e.target.value;
        seccionChilexpress.style.display = valor === 'CHILEXPRESS' ? 'block' : 'none';
        seccionSantiago.style.display = valor === 'PRESENCIAL_SANTIAGO' ? 'block' : 'none';
    });

    configurarPreviewConBorrado('fotoComprobante', 'imgComprobante', 'boxPreviewComprobante');
    configurarPreviewConBorrado('fotoFirma', 'imgFirma', 'boxPreviewFirma');

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

        const btnSubmit = formCobranza.querySelector('.btn-submit');
        btnSubmit.disabled = true;
        btnSubmit.textContent = 'Guardando...';

        let controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 60000);

        try {
            // ── Construir FormData desde cero (evita enviar archivos originales sin comprimir) ──
            const formData = new FormData();

            // Campos de texto y ocultos
            formData.set('empresa_id',           empresaSelect.value);
            formData.set('numero_factura',        numFacturaInput.value);
            formData.set('rut_cliente',           rutClienteInput.value);
            formData.set('razon_social_cliente',  razonSocialClienteInput ? razonSocialClienteInput.value : '');
            formData.set('monto_total_factura',   montoTotalFacturaInput ? montoTotalFacturaInput.value : '');
            formData.set('email_cliente',         emailClienteInput.value);
            formData.set('email_tesoreria',       document.getElementById('emailTesoreria').value);
            formData.set('tipo_entrega',          selectTipoEntrega.value);
            formData.set('numero_seguimiento',    document.getElementById('numSeguimiento')?.value || '');

            // ── Cheques: campos de texto ──
            const bancos        = formCobranza.querySelectorAll('select[name="banco[]"]');
            const numsCheque    = formCobranza.querySelectorAll('input[name="numero_cheque[]"]');
            const montosCheque  = formCobranza.querySelectorAll('input.hidden-monto-cheque');
            const fechas        = formCobranza.querySelectorAll('input[name="fecha_vencimiento[]"]');
            const comentarios   = formCobranza.querySelectorAll('textarea[name="comentario_cheque[]"]');

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

            // ── Foto comprobante Chilexpress (comprimida) ──
            const inputComprobante = document.getElementById('fotoComprobante');
            if (inputComprobante && inputComprobante.files && inputComprobante.files[0]) {
                btnSubmit.textContent = 'Comprimiendo comprobante...';
                const compressed = await compressImage(inputComprobante.files[0]);
                formData.set('foto_comprobante', compressed, compressed.name);
            }

            // ── Foto firma presencial (comprimida) ──
            const inputFirma = document.getElementById('fotoFirma');
            if (inputFirma && inputFirma.files && inputFirma.files[0]) {
                btnSubmit.textContent = 'Comprimiendo comprobante...';
                const compressed = await compressImage(inputFirma.files[0]);
                formData.set('foto_firma', compressed, compressed.name);
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
            btnSubmit.disabled = false;
            btnSubmit.textContent = 'Registrar Cobranza';
        }
    });
});

// ==========================================
// UTILIDADES GLOBALES (PREVIEW DE IMÁGENES)
// ==========================================
window.quitarImagen = function (idInput, idImg, idContainer) {
    const input = document.getElementById(idInput);
    const img = document.getElementById(idImg);
    const container = document.getElementById(idContainer);
    if (input) input.value = '';
    if (img) img.src = '';
    if (container) container.style.display = 'none';
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
                        console.log(`[Compresión] ${file.name}: ${(file.size/1024).toFixed(0)}KB → ${(compressedFile.size/1024).toFixed(0)}KB`);
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