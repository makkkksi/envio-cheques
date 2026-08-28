// cuentas_corrientes.js

// Caché de datos locales
let cacheCobranzasCola = [];
let cacheHistorialLog = [];
let cacheEmpresasMatriz = [];
let horaCorteGlobal = "16:00";
let historyFilterSelected = 'Todos';
let historialCurrentPage = 1;
let historialTotalPages = 1;
let historialTotal = 0;
const CAN_MANAGE_CC = document.body.dataset.canManageCc === '1';


function resolverEmpresaDesdeTexto(emitidoA, empresaFallback) {
    const fallbackNombre = empresaFallback || '';
    const clean = (emitidoA || fallbackNombre).trim().toUpperCase();

    if (!clean && cacheEmpresasMatriz.length > 0) {
        return cacheEmpresasMatriz[0];
    }

    // 1. Coincidencia exacta
    let emp = cacheEmpresasMatriz.find(e => e.nombre.toUpperCase() === clean);
    if (emp) return emp;

    // 2. Prioridad HD Automarco
    if (clean.includes('HD') || clean.includes('AUTOMARCOHD') || clean.includes('EMP06')) {
        emp = cacheEmpresasMatriz.find(e => e.nombre.toUpperCase().includes('HD') || e.id == 2);
        if (emp) return emp;
    }

    // 3. Gabtec
    if (clean.includes('GABTEC') || clean.includes('EMP10')) {
        emp = cacheEmpresasMatriz.find(e => e.nombre.toUpperCase().includes('GABTEC') || e.id == 4);
        if (emp) return emp;
    }

    // 4. Autotec
    if (clean.includes('AUTOTEC') || clean.includes('EMP03') || clean.includes('EMP24')) {
        emp = cacheEmpresasMatriz.find(e => e.nombre.toUpperCase().includes('AUTOTEC') || e.id == 3);
        if (emp) return emp;
    }

    // 5. Automarco LTDA
    if (clean.includes('AUTOMARCO') || clean.includes('EMP01')) {
        emp = cacheEmpresasMatriz.find(e => !e.nombre.toUpperCase().includes('HD') && (e.nombre.toUpperCase().includes('AUTOMARCO') || e.id == 1));
        if (emp) return emp;
    }

    // Fallback dinámico buscando por empresaFallback en la matriz
    if (fallbackNombre) {
        emp = cacheEmpresasMatriz.find(e => e.nombre.toUpperCase() === fallbackNombre.toUpperCase());
        if (emp) return emp;
    }

    return { nombre: emitidoA || fallbackNombre || 'Sin Asignar', email_digitadora: 'No Asignada' };
}

function confirmarDespachoModal() {
    if (cacheCobranzasCola.length === 0) return;
    
    // Construir matriz de destinatarios y cantidad fragmentando por cheques
    const resumenMap = {};
    cacheCobranzasCola.forEach(cob => {
        const cheques = (cob.cheques && cob.cheques.length > 0) ? cob.cheques : [{ emitido_a: cob.empresa_nombre }];
        cheques.forEach(ch => {
            const empInfo = resolverEmpresaDesdeTexto(ch.emitido_a, cob.empresa_nombre);
            const empKey = empInfo.nombre;
            const email = empInfo.email_digitadora || 'No Asignada';
            
            if (!resumenMap[empKey]) {
                resumenMap[empKey] = { email: email, countChq: 0, cobsSet: new Set() };
            }
            resumenMap[empKey].cobsSet.add(cob.cobranza_id);
            resumenMap[empKey].countChq++;
        });
    });

    let htmlMatriz = "";
    for (const [emp, det] of Object.entries(resumenMap)) {
        htmlMatriz += `
            <div style="display:flex; justify-content:space-between; border-bottom:1px solid #cbd5e1; padding-bottom:6px; margin-bottom:4px;">
                <span><strong>${emp}</strong> (${det.cobsSet.size} cobranza(s) / ${det.countChq} cheque(s)) ➔ ${det.email}</span>
            </div>
        `;
    }

    document.getElementById('lblMatrizConfirmacionDetalle').innerHTML = htmlMatriz;
    document.getElementById('modalConfirmarDespacho').style.display = 'flex';
}

function cerrarConfirmarDespacho() {
    document.getElementById('modalConfirmarDespacho').style.display = 'none';
}

function abrirLogDetalle(logId) {
    const log = cacheHistorialLog.find(l => l.id == logId);
    if (!log) return;
    let html = `
        <div style="display: flex; flex-wrap: wrap; gap: 20px; border-bottom: 1px solid #cbd5e1; padding-bottom: 12px; margin-bottom: 16px;">
            <div><strong>Fecha y Hora:</strong> ${log.fecha_envio}</div>
            <div><strong>Empresa Origen:</strong> ${log.empresa || 'Consolidado'}</div>
            <div><strong>Destinatario:</strong> ${log.destinatario}</div>
            <div><strong>Estado:</strong> ${log.estado_envio}</div>
        </div>
    `;
    if (log.estado_envio === 'FALLIDO' && log.error_mensaje) {
        html += `<p style="color: #dc2626; margin-top: 0;"><strong>Error:</strong> ${log.error_mensaje}</p>`;
    }

    if (log.payload_json) {
        try {
            const cobranzas = JSON.parse(log.payload_json);
            html += `<h3 style="color: #334155; margin-bottom: 12px; font-size: 1.1rem;">Cobranzas Enviadas (${cobranzas.length})</h3>`;
            
            cobranzas.forEach((cob, i) => {
                let sumMonto = 0;
                const listaCheques = cob.cheques_filtrados || cob.cheques || [];
                let chequesHtml = '';
                if (listaCheques && listaCheques.length > 0) {
                    chequesHtml = '<div style="display:flex; flex-direction:column; gap:8px; margin-top:6px;">';
                    listaCheques.forEach(ch => {
                        const mVal = parseFloat(ch.monto || ch.monto_cheque || 0);
                        sumMonto += mVal;
                        const mFmt = '$' + parseInt(mVal).toLocaleString('es-CL');
                        let vFmt = 'Sin Fecha';
                        if (ch.fecha_vencimiento) {
                            try {
                                vFmt = new Date(ch.fecha_vencimiento + 'T12:00:00').toLocaleDateString('es-CL');
                            } catch(e) {}
                        }

                        const rawFoto = ch.foto_cheque_url || '';
                        const fotoUrl = rawFoto ? (rawFoto.startsWith('http') || rawFoto.startsWith('../') ? rawFoto : '../' + rawFoto) : '';

                        const fotoHtml = fotoUrl ? `
                            <div style="width: 80px; height: 50px; flex-shrink: 0; border-radius: 6px; overflow: hidden; border: 1px solid #cbd5e1; cursor: pointer; position: relative; background: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onclick="abrirImagenLightbox('${fotoUrl}')" title="Clic para ampliar cheque digitalizado">
                                <img src="${fotoUrl}" style="width: 100%; height: 100%; object-fit: cover;" alt="Cheque N° ${ch.numero_cheque || ''}">
                                <div style="position: absolute; inset: 0; background: rgba(0,0,0,0.3); opacity: 0; transition: opacity 0.2s; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
                                    <span style="background: rgba(15,23,42,0.85); color: #fff; font-size: 10px; padding: 2px 5px; border-radius: 3px; font-weight: bold;">🔍 Ver</span>
                                </div>
                            </div>
                        ` : `
                            <div style="width: 80px; height: 50px; flex-shrink: 0; border-radius: 6px; border: 1px dashed #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #94a3b8; background: #f8fafc; text-align: center; padding: 2px;">
                                Sin Foto
                            </div>
                        `;

                        const emitidoTexto = ch.emitido_a ? `<span style="display:inline-block; padding: 1px 6px; background: #e0f2fe; color: #0369a1; border-radius: 3px; font-size: 0.72rem; font-weight: 700; border: 1px solid #bae6fd;">🏢 ${ch.emitido_a}</span>` : '';

                        chequesHtml += `
                            <div style="display: flex; gap: 12px; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 10px; border-radius: 6px;">
                                ${fotoHtml}
                                <div style="flex: 1; min-width: 0;">
                                    <div style="display: flex; justify-content: space-between; align-items: center;">
                                        <strong style="font-family: var(--font-mono); color: #1e3a8a; font-size: 0.88rem;">N° ${ch.numero_cheque || 'S/N'}</strong>
                                        <strong style="font-family: var(--font-mono); color: #166534; font-size: 0.88rem;">${mFmt}</strong>
                                    </div>
                                    <div style="color: #475569; font-size: 0.8rem; margin-top: 2px;">
                                        ${ch.banco || 'Banco s/n'} &nbsp;•&nbsp; Vence: <strong>${vFmt}</strong>
                                    </div>
                                    ${emitidoTexto ? `<div style="margin-top: 3px;">${emitidoTexto}</div>` : ''}
                                </div>
                            </div>
                        `;
                    });
                    chequesHtml += '</div>';
                } else {
                    chequesHtml = '<em style="color:#94a3b8; font-size:0.85rem;">Sin cheques adjuntos</em>';
                }
                const montoFmt = '$' + parseInt(sumMonto).toLocaleString('es-CL');

                let facturasHtml = '';
                if (cob.facturas_multiples && cob.facturas_multiples.length > 0) {
                    facturasHtml = '<ul style="margin:4px 0 0; padding-left:20px; font-size:0.85rem; color:#475569;">';
                    cob.facturas_multiples.forEach(fac => {
                        const mCub = '$' + parseInt(fac.monto_cubierto).toLocaleString('es-CL');
                        facturasHtml += `<li><strong>${fac.numero_factura}</strong> (${fac.cuota_label}) - Cubre: ${mCub}</li>`;
                    });
                    facturasHtml += '</ul>';
                } else {
                    facturasHtml = `<span style="font-size:0.85rem; color:#475569;">Doc: ${cob.numero_factura}</span>`;
                }

                html += `
                    <div style="border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 14px 16px; margin-bottom: 14px; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.03);">
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; border-bottom:1px solid var(--color-border-subtle); padding-bottom:8px;">
                            <div>
                                <strong style="color:var(--color-primary); font-size:0.95rem;">${i + 1}. ${cob.razon_social_cliente}</strong>
                                <span style="font-size:0.8rem; color:var(--color-text-muted); margin-left:8px;">RUT: ${cob.rut_cliente}</span>
                            </div>
                            <strong style="color:#166534; font-family:var(--font-mono); font-size:0.95rem;">Monto: ${montoFmt}</strong>
                        </div>
                        <div style="display:flex; gap:16px; flex-wrap:wrap;">
                            <div style="flex:1; min-width:220px;">
                                <strong style="font-size:0.8rem; color:var(--color-text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Facturas Cubiertas:</strong>
                                <div style="margin-top:4px;">${facturasHtml}</div>
                            </div>
                            <div style="flex:2; min-width:280px;">
                                <strong style="font-size:0.8rem; color:var(--color-text-secondary); text-transform:uppercase; letter-spacing:0.05em;">Cheques Digitalizados / Registrados:</strong>
                                <div style="margin-top:4px;">${chequesHtml}</div>
                            </div>
                        </div>
                    </div>
                `;
            });
        } catch (e) {
            html += `<p style="color: #64748b;"><em>Error al leer el detalle del envío o formato antiguo.</em></p>`;
        }
    } else {
        html += `<p style="color: #64748b;"><em>No hay detalle exacto disponible para este envío (versión antigua). Total enviado: ${log.cantidad_cobranzas}</em></p>`;
    }

    document.getElementById('logDetalleContent').innerHTML = html;
    document.getElementById('modalLogDetalle').style.display = 'flex';
}

function cerrarLogDetalle() {
    document.getElementById('modalLogDetalle').style.display = 'none';
}

function abrirDetalleCobranza(cobranzaId) {
    const cob = cacheCobranzasCola.find(c => c.cobranza_id == cobranzaId);
    if (!cob) return;

    let sumMonto = 0;
    let chequesHtml = '';
    if (cob.cheques && cob.cheques.length > 0) {
        chequesHtml = '<div style="display:flex; flex-direction:column; gap:8px; margin-top:6px;">';
        cob.cheques.forEach(ch => {
            const mVal = parseFloat(ch.monto || ch.monto_cheque || 0);
            sumMonto += mVal;
            const mFmt = '$' + parseInt(mVal).toLocaleString('es-CL');
            let vFmt = 'Sin Fecha';
            if (ch.fecha_vencimiento) {
                try {
                    vFmt = new Date(ch.fecha_vencimiento + 'T12:00:00').toLocaleDateString('es-CL');
                } catch(e) {}
            }

            const rawFoto = ch.foto_cheque_url || '';
            const fotoUrl = rawFoto ? (rawFoto.startsWith('http') || rawFoto.startsWith('../') ? rawFoto : '../' + rawFoto) : '';

            const fotoHtml = fotoUrl ? `
                <div style="width: 80px; height: 50px; flex-shrink: 0; border-radius: 6px; overflow: hidden; border: 1px solid #cbd5e1; cursor: pointer; position: relative; background: #0f172a; box-shadow: 0 1px 3px rgba(0,0,0,0.1);" onclick="abrirImagenLightbox('${fotoUrl}')" title="Clic para ampliar cheque digitalizado">
                    <img src="${fotoUrl}" style="width: 100%; height: 100%; object-fit: cover;" alt="Cheque N° ${ch.numero_cheque || ''}">
                    <div style="position: absolute; inset: 0; background: rgba(0,0,0,0.3); opacity: 0; transition: opacity 0.2s; display: flex; align-items: center; justify-content: center;" onmouseover="this.style.opacity='1'" onmouseout="this.style.opacity='0'">
                        <span style="background: rgba(15,23,42,0.85); color: #fff; font-size: 10px; padding: 2px 5px; border-radius: 3px; font-weight: bold;">🔍 Ver</span>
                    </div>
                </div>
            ` : `
                <div style="width: 80px; height: 50px; flex-shrink: 0; border-radius: 6px; border: 1px dashed #cbd5e1; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #94a3b8; background: #f8fafc; text-align: center; padding: 2px;">
                    Sin Foto
                </div>
            `;

            const emitidoTexto = ch.emitido_a ? `<span style="display:inline-block; padding: 1px 6px; background: #e0f2fe; color: #0369a1; border-radius: 3px; font-size: 0.72rem; font-weight: 700; border: 1px solid #bae6fd;">🏢 ${ch.emitido_a}</span>` : '';

            chequesHtml += `
                <div style="display: flex; gap: 12px; align-items: center; background: #f8fafc; border: 1px solid #e2e8f0; padding: 8px 10px; border-radius: 6px;">
                    ${fotoHtml}
                    <div style="flex: 1; min-width: 0;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <strong style="font-family: var(--font-mono); color: #1e3a8a; font-size: 0.88rem;">N° ${ch.numero_cheque || 'S/N'}</strong>
                            <strong style="font-family: var(--font-mono); color: #166534; font-size: 0.88rem;">${mFmt}</strong>
                        </div>
                        <div style="color: #475569; font-size: 0.8rem; margin-top: 2px;">
                            ${ch.banco || 'Banco s/n'} &nbsp;•&nbsp; Vence: <strong>${vFmt}</strong>
                        </div>
                        ${emitidoTexto ? `<div style="margin-top: 3px;">${emitidoTexto}</div>` : ''}
                    </div>
                </div>
            `;
        });
        chequesHtml += '</div>';
    } else {
        chequesHtml = '<em style="color:#94a3b8; font-size:0.9rem;">Sin cheques adjuntos</em>';
    }
    const montoFmt = '$' + parseInt(sumMonto).toLocaleString('es-CL');

    let facturasHtml = '';
    if (cob.facturas_multiples && cob.facturas_multiples.length > 0) {
        facturasHtml = '<ul style="margin:4px 0 0; padding-left:20px; font-size:0.9rem; color:#334155;">';
        cob.facturas_multiples.forEach(fac => {
            const mCub = '$' + parseInt(fac.monto_cubierto).toLocaleString('es-CL');
            facturasHtml += `<li><strong>${fac.numero_factura}</strong> (${fac.cuota_label}) - Cubre: ${mCub}</li>`;
        });
        facturasHtml += '</ul>';
    } else {
        facturasHtml = `<span style="font-size:0.9rem; color:#334155;">Doc Principal: ${cob.numero_factura}</span>`;
    }

    let html = `
        <div style="border-bottom: 1px solid #cbd5e1; padding-bottom: 12px; margin-bottom: 16px;">
            <div style="font-size: 1.1rem; font-weight: bold; color: #1e3a8a;">${cob.razon_social_cliente}</div>
            <div style="color: #64748b; margin-top: 4px;">RUT: ${cob.rut_cliente} &nbsp;•&nbsp; Empresa: ${cob.empresa_nombre}</div>
            <div style="color: #64748b; margin-top: 2px;">Vendedor: ${cob.vendedor_nombre}</div>
        </div>
        
        <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom: 20px;">
            <div style="flex:1; min-width:250px;">
                <strong style="color: #0f172a; font-size: 1.05rem;">Facturas Abonadas</strong>
                <div style="margin-top: 8px; background: #fff; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    ${facturasHtml}
                </div>
            </div>
            
            <div style="flex:1; min-width:250px;">
                <strong style="color: #0f172a; font-size: 1.05rem;">Cheques Físicos</strong>
                <div style="margin-top: 8px; background: #fff; padding: 12px; border: 1px solid #e2e8f0; border-radius: 6px;">
                    ${chequesHtml}
                </div>
            </div>
        </div>

        <div style="text-align: right; font-size: 1.2rem; font-weight: bold; color: #15803d; border-top: 1px solid #cbd5e1; padding-top: 16px;">
            Monto Total a Rendir: ${montoFmt}
        </div>
    `;
    document.getElementById('cobranzaDetalleContent').innerHTML = html;
    document.getElementById('modalDetalleCobranza').style.display = 'flex';
}

function cerrarDetalleCobranza() {
    document.getElementById('modalDetalleCobranza').style.display = 'none';
}

let yaDisparadoAutoHoy = false;

function actualizarTemporizadorCorte() {
    if (!horaCorteGlobal) return;
    
    const timerContainer = document.getElementById('txtCutoffTimer');
    const chkAuto = document.getElementById('chkAutoDispatch');
    const autoActivo = chkAuto && chkAuto.checked;

    if (!autoActivo) {
        if (timerContainer) timerContainer.style.display = 'none';
        return;
    } else {
        if (timerContainer) timerContainer.style.display = '';
    }

    const lblCutoffHour = document.getElementById('lblCutoffHour');
    if (lblCutoffHour) lblCutoffHour.textContent = `${horaCorteGlobal} hrs`;

    const parts = horaCorteGlobal.split(':');
    const target = new Date();
    target.setHours(parseInt(parts[0], 10), parseInt(parts[1], 10), 0, 0);

    const now = new Date();
    let diff = target - now;

    // Disparo automático inteligente si el portal está abierto y llega la hora de corte:
    // Si la hora objetivo ya se cumplió hoy (diff <= 0 y dentro de la ventana de 5 minutos)
    // y hay cobranzas pendientes en cola, se dispara automáticamente
    if (CAN_MANAGE_CC && diff <= 0 && diff > -300000 && !yaDisparadoAutoHoy && cacheCobranzasCola.length > 0 && typeof despachandoCC !== 'undefined' && !despachandoCC) {
        yaDisparadoAutoHoy = true;
        showToast('Hora de corte alcanzada. Despachando resumen automáticamente a digitadoras...', 'success');
        ejecutarDespachoCC();
    }

    if (diff < 0) {
        // Ya pasó la hora de corte hoy, apuntar a mañana
        target.setDate(target.getDate() + 1);
        diff = target - now;
    }

    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff / (1000 * 60)) % 60);
    
    const lblCutoffRemaining = document.getElementById('lblCutoffRemaining');
    if (lblCutoffRemaining) lblCutoffRemaining.textContent = `Faltan ${hours}h ${minutes}m`;
}

function cargarDatosCC() {
    return fetch('api/get_gestion_cc.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error al obtener datos', 'error');
                return;
            }
            const info = data.data;
            horaCorteGlobal = info.hora_despacho_diario;
            actualizarTemporizadorCorte();

            if (document.getElementById('inputHoraDespachoCC')) document.getElementById('inputHoraDespachoCC').value = info.hora_despacho_diario;
            if (document.getElementById('chkAutoDispatch')) document.getElementById('chkAutoDispatch').checked = info.despacho_automatico_activado === '1';
            actualizarToggleLabelCfg();

            const dig1 = info.email_digitadora_1 || '';
            const dig2 = info.email_digitadora_2 || '';
            if (document.getElementById('inputDig1')) document.getElementById('inputDig1').value = dig1;
            if (document.getElementById('inputDig2')) document.getElementById('inputDig2').value = dig2;
            if (document.getElementById('inputTesGen')) document.getElementById('inputTesGen').value = info.email_tesoreria_general || '';
            if (document.getElementById('inputCCGen')) document.getElementById('inputCCGen').value = info.email_cuentas_corrientes_general || '';

            // Renderizar matriz de empresas en el modal (Asignación excluyente radio buttons)
            cacheEmpresasMatriz = info.empresas;
            const tbodyEmp = document.getElementById('tblAsignacionesDigitadorasCC');
            if (tbodyEmp) {
                tbodyEmp.innerHTML = info.empresas.map(emp => {
                    const emailActual = emp.email_digitadora || '';
                    // Si el email actual coincide con dig2 (y no está vacío), seleccionamos 2, si no por defecto 1.
                    const isDig2 = (emailActual === dig2 && dig2 !== '');
                    return `
                        <tr style="border-bottom: 1px solid #f1f5f9;">
                            <td style="font-weight: 600; font-size: 0.82rem; padding: 12px 14px; color: #334155; text-align: left;">${emp.nombre}</td>
                            <td>
                                <input type="radio" name="radio_emp_${emp.id}" value="1" ${!isDig2 ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px; accent-color: #2563eb;">
                            </td>
                            <td>
                                <input type="radio" name="radio_emp_${emp.id}" value="2" ${isDig2 ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px; accent-color: #16a34a;">
                            </td>
                        </tr>
                    `;
                }).join('');
            }

            cacheCobranzasCola = info.cobranzas_en_cola || [];
            cacheHistorialLog = info.log_envios || [];
            historialCurrentPage = info.historial_page || 1;
            historialTotalPages = info.historial_total_pages || 1;
            historialTotal = info.historial_total || 0;
            
            actualizarKPIStrip();
            filtrarColaDeCheques();
            renderHistorialTable();
        })
        .catch(() => {
            showToast('Error de conexión', 'error');
        });
}

// Carga independiente del historial para paginación
function cargarHistorial(page) {
    historialCurrentPage = page;
    const tbody = document.getElementById('tblBitacoraEnviosCC');
    tbody.innerHTML = `<tr><td colspan="6" style="text-align:center; color:#94a3b8; padding:20px;">Cargando historial...</td></tr>`;

    fetch(`api/get_gestion_cc.php?historial_page=${page}`)
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error al cargar historial', 'error');
                return;
            }
            cacheHistorialLog = data.data.log_envios || [];
            historialCurrentPage = data.data.historial_page || 1;
            historialTotalPages = data.data.historial_total_pages || 1;
            historialTotal = data.data.historial_total || 0;
            renderHistorialTable();
        })
        .catch(() => {
            showToast('Error de conexión', 'error');
        });
}

function actualizarKPIStrip() {
    const countCobranzas = cacheCobranzasCola.length;
    const uniqueEmpresas = new Set();
    const uniqueClientes = new Set();
    let countCheques = 0;

    cacheCobranzasCola.forEach(cob => {
        uniqueClientes.add(cob.rut_cliente);
        if (cob.cheques && cob.cheques.length > 0) {
            cob.cheques.forEach(ch => {
                const empInfo = resolverEmpresaDesdeTexto(ch.emitido_a, cob.empresa_nombre);
                uniqueEmpresas.add(empInfo.nombre);
                countCheques++;
            });
        } else {
            uniqueEmpresas.add(cob.empresa_nombre);
        }
    });

    document.getElementById('kpiCount').textContent = countCheques;
    document.getElementById('kpiEmpresas').textContent = uniqueEmpresas.size;
    document.getElementById('kpiDetails').textContent = `${uniqueClientes.size} Cliente(s) / ${countCobranzas} Cobranzas`;

    const btnDespachar = document.getElementById('btnDespacharResumen');
    if (!btnDespachar) return;
    if (CAN_MANAGE_CC && countCobranzas > 0) {
        btnDespachar.disabled = false;
        btnDespachar.removeAttribute('title');
    } else {
        btnDespachar.disabled = true;
        btnDespachar.setAttribute('title', 'No hay cobranzas pendientes para despachar hoy');
    }
}

function filtrarColaDeCheques() {
    const searchVal = document.getElementById('filterBuscar').value.trim().toLowerCase();
    const empVal = document.getElementById('filterEmpresa').value;
    const ordVal = document.getElementById('filterOrden').value;

    // Filtrado
    let filtered = cacheCobranzasCola.filter(cob => {
        const matchSearch = !searchVal || 
            (cob.numero_factura && cob.numero_factura.toLowerCase().includes(searchVal)) ||
            cob.razon_social_cliente.toLowerCase().includes(searchVal) ||
            cob.rut_cliente.toLowerCase().includes(searchVal) ||
            cob.vendedor_nombre.toLowerCase().includes(searchVal) ||
            (cob.cheques && cob.cheques.some(ch => ch.numero_cheque.toLowerCase().includes(searchVal)));
        
        const matchEmpresa = !empVal || cob.empresa_nombre === empVal;

        return matchSearch && matchEmpresa;
    });

    // Ordenamiento (Basado en la fecha de la cobranza o monto total)
    filtered.sort((a, b) => {
        let sumA = a.cheques ? a.cheques.reduce((sum, ch) => sum + parseFloat(ch.monto_cheque), 0) : 0;
        let sumB = b.cheques ? b.cheques.reduce((sum, ch) => sum + parseFloat(ch.monto_cheque), 0) : 0;

        if (ordVal === 'monto_desc') {
            return sumB - sumA;
        } else if (ordVal === 'monto_asc') {
            return sumA - sumB;
        } else if (ordVal === 'empresa') {
            return a.empresa_nombre.localeCompare(b.empresa_nombre);
        }
        // Si es fecha, usamos el updated_at de la cobranza
        return new Date(a.updated_at) - new Date(b.updated_at);
    });

    // Renderizado
    const tbodyChq = document.getElementById('tblChequesEnColaCC');
    if (filtered.length === 0) {
        tbodyChq.innerHTML = `<tr><td colspan="4" style="text-align: center; color: #94a3b8; padding: 20px;">No hay cobranzas en cola que coincidan con los filtros.</td></tr>`;
    } else {
        tbodyChq.innerHTML = filtered.map((cob, index) => {
            let sumMonto = 0;
            if (cob.cheques && cob.cheques.length > 0) {
                cob.cheques.forEach(ch => {
                    sumMonto += parseFloat(ch.monto_cheque);
                });
            }

            const montoFmt = '$' + parseInt(sumMonto).toLocaleString('es-CL');
            const numDocs = cob.facturas_multiples ? cob.facturas_multiples.length : 1;

            return `
                <tr>
                    <td>
                        <div style="font-weight: 600; color: #1e3a8a; margin-bottom:4px;">${cob.empresa_nombre}</div>
                        <div style="font-weight: 600;">${cob.razon_social_cliente}</div>
                        <div style="font-size: 0.8rem; color: #64748b;">RUT: ${cob.rut_cliente}</div>
                    </td>
                    <td style="vertical-align:middle;">${cob.vendedor_nombre}</td>
                    <td style="vertical-align:middle; text-align:center;">
                        <button type="button" class="btn-action" style="background:#e2e8f0; color:#334155; padding: 6px 12px; border-radius: 4px; font-size: 0.85rem;" onclick="abrirDetalleCobranza(${cob.cobranza_id})">
                            Ver Detalle (${numDocs} doc)
                        </button>
                    </td>
                    <td class="text-right font-mono monto-destacado" style="vertical-align:middle;">${montoFmt}</td>
                </tr>
            `;
        }).join('');
    }
}

function renderHistorialTable() {
    const exitosos = cacheHistorialLog.filter(l => l.estado_envio === 'ENVIADO').length;
    const fallidos = cacheHistorialLog.filter(l => l.estado_envio === 'FALLIDO').length;

    document.getElementById('cntHistTodos').textContent = historialTotal;
    document.getElementById('cntHistExitosos').textContent = exitosos;
    document.getElementById('cntHistFallidos').textContent = fallidos;

    let filtered = cacheHistorialLog;
    if (historyFilterSelected === 'Enviados') {
        filtered = cacheHistorialLog.filter(l => l.estado_envio === 'ENVIADO');
    } else if (historyFilterSelected === 'Fallidos') {
        filtered = cacheHistorialLog.filter(l => l.estado_envio === 'FALLIDO');
    }

    const tbodyBit = document.getElementById('tblBitacoraEnviosCC');
    if (filtered.length === 0) {
        tbodyBit.innerHTML = `<tr><td colspan="6" style="text-align: center; color: var(--color-text-muted); padding: 20px;">No hay registros de envíos para esta categoría.</td></tr>`;
    } else {
        // Agrupar por día
        const grupos = {};
        filtered.forEach(log => {
            const fecha = log.fecha_envio.split(' ')[0]; // 'YYYY-MM-DD'
            const [y, m, d] = fecha.split('-');
            const fechaLabel = `${d}/${m}/${y}`;
            if (!grupos[fechaLabel]) grupos[fechaLabel] = [];
            grupos[fechaLabel].push(log);
        });

        let html = '';
        for (const [dia, logs] of Object.entries(grupos)) {
            html += `
                <tr>
                    <td colspan="6" style="background: var(--color-border-subtle); padding: 6px 12px; font-size: 0.8rem; font-weight: 700; color: var(--color-text-secondary); letter-spacing: 0.05em;">
                        ${dia}
                    </td>
                </tr>
            `;
            logs.forEach(log => {
                const esExitoso = log.estado_envio === 'ENVIADO';
                const badgeStyle = esExitoso 
                    ? 'background: #dcfce7; color: #15803d; border-radius: 9999px; padding: 3px 10px; font-size: 0.78rem; font-weight: 700;' 
                    : 'background: #fee2e2; color: #b91c1c; border-radius: 9999px; padding: 3px 10px; font-size: 0.78rem; font-weight: 700;';
                const hora = log.fecha_envio.split(' ')[1] || '';

                let clientesText = '-';
                if (log.payload_json) {
                    try {
                        const cobs = JSON.parse(log.payload_json);
                        if (Array.isArray(cobs) && cobs.length > 0) {
                            const nombres = cobs.map(c => c.razon_social_cliente || 'Cliente').filter(Boolean);
                            if (nombres.length === 1) {
                                clientesText = `<strong>${nombres[0]}</strong>`;
                            } else if (nombres.length > 1) {
                                clientesText = `<strong>${nombres[0]}</strong> <span style="color: var(--color-text-muted); font-size: 0.78rem;">(+${nombres.length - 1} más)</span>`;
                            }
                        }
                    } catch(e) {}
                }

                html += `
                    <tr>
                        <td style="font-weight: 500; font-family: var(--font-mono); font-size: 0.82rem;">${hora}</td>
                        <td>
                            <div style="font-weight: 600; color: var(--color-text);">${log.empresa || 'Consolidado'}</div>
                            <div style="font-size: 0.78rem; color: var(--color-text-muted);">Para: ${log.destinatario}</div>
                        </td>
                        <td>
                            <div style="font-size: 0.83rem;">${clientesText}</div>
                        </td>
                        <td style="text-align: center; font-weight: 700; font-family: var(--font-mono);">${log.cantidad_cobranzas}</td>
                        <td><span style="${badgeStyle}">${log.estado_envio}</span></td>
                        <td style="text-align: right; white-space: nowrap;">
                            <div style="display: inline-flex; gap: 6px; justify-content: flex-end;">
                                <button type="button" class="btn-action btn-secondary" onclick="abrirLogDetalle(${log.id})">Ver Info & Cheques</button>
                                ${CAN_MANAGE_CC ? `<button type="button" class="btn-action btn-secondary" onclick="reenviarBitacoraCC(${log.id})">Re-enviar</button>` : ''}
                            </div>
                        </td>
                    </tr>
                `;
            });
        }
        tbodyBit.innerHTML = html;
    }

    // Renderizar controles de paginación
    const pagerEl = document.getElementById('historialPager');
    if (!pagerEl) return;

    if (historialTotalPages <= 1) {
        pagerEl.innerHTML = '';
        return;
    }

    let pagerHtml = `<div style="display:flex; align-items:center; justify-content:center; gap:8px; margin-top:16px;">`;
    pagerHtml += `
        <button class="btn-action btn-secondary" style="padding:5px 12px; font-size:0.85rem;" 
            ${historialCurrentPage <= 1 ? 'disabled' : ''} 
            onclick="cargarHistorial(${historialCurrentPage - 1})">
            &larr; Anterior
        </button>
    `;

    const startPage = Math.max(1, historialCurrentPage - 2);
    const endPage = Math.min(historialTotalPages, startPage + 4);
    for (let p = startPage; p <= endPage; p++) {
        const active = p === historialCurrentPage;
        pagerHtml += `
            <button class="btn-action" style="padding:5px 12px; font-size:0.85rem; ${active ? 'background:#1e3a8a; color:#fff;' : 'background:#e2e8f0; color:#334155;'}" 
                onclick="cargarHistorial(${p})">${p}</button>
        `;
    }

    pagerHtml += `
        <button class="btn-action btn-secondary" style="padding:5px 12px; font-size:0.85rem;" 
            ${historialCurrentPage >= historialTotalPages ? 'disabled' : ''} 
            onclick="cargarHistorial(${historialCurrentPage + 1})">
            Siguiente &rarr;
        </button>
    `;
    pagerHtml += `<span style="color:#64748b; font-size:0.85rem;">Página ${historialCurrentPage} de ${historialTotalPages} (${historialTotal} registros)</span>`;
    pagerHtml += `</div>`;
    pagerEl.innerHTML = pagerHtml;
}

function filtrarHistorialCC(filterType) {
    historyFilterSelected = filterType;
    
    document.querySelectorAll('.history-tab').forEach(t => t.classList.remove('active'));
    if (filterType === 'Todos') document.getElementById('tabTodos').classList.add('active');
    else if (filterType === 'Enviados') document.getElementById('tabExitosos').classList.add('active');
    else if (filterType === 'Fallidos') document.getElementById('tabFallidos').classList.add('active');

    renderHistorialTable();
}

let despachandoCC = false;
function ejecutarDespachoCC() {
    if (!CAN_MANAGE_CC || despachandoCC) return;
    const btnDespachar = document.getElementById('btnDespacharResumen');
    
    despachandoCC = true;
    if (btnDespachar) {
        btnDespachar.disabled = true;
        btnDespachar.textContent = 'Despachando...';
    }
    
    cerrarConfirmarDespacho();
    
    fetch('api/despachar_resumen_cc.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': getAdminCsrfToken() }
    })
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                showToast(data.message || 'Error al despachar', 'error');
                return;
            }
            showToast(data.message || 'Resumen despachado con éxito', 'success');
            cargarDatosCC();
        })
        .catch(() => {
            showToast('Error al conectar con el despachador', 'error');
        })
        .finally(() => {
            despachandoCC = false;
            if (btnDespachar) {
                btnDespachar.disabled = false;
                btnDespachar.textContent = 'Despachar Resumen Ahora';
            }
        });
}

function reenviarBitacoraCC(logId) {
    if (!CAN_MANAGE_CC) return;

    fetch('api/reenviar_informe_cc.php', {
        method: 'POST',
        headers: getAdminJsonHeaders(),
        body: JSON.stringify({ log_id: logId, nuevo_correo: '' })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            showToast(data.message || 'Error al re-enviar', 'error');
            return;
        }
        showToast(data.message || 'Informe re-enviado con éxito.', 'success');
        cargarDatosCC();
    });
}

document.addEventListener('DOMContentLoaded', () => {
    window.registerSuiteRefresh?.(cargarDatosCC);
    cargarDatosCC();
    setInterval(actualizarTemporizadorCorte, 10000); 
});
