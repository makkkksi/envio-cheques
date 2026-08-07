<!-- admin/components/modal_config_cc.php -->
<div id="modalConfigCC" class="modal-cc" style="display: none;">
    <div class="modal-content-cc" style="max-width: 900px;">
        <!-- Header del modal -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0;">
            <div>
                <h2 style="margin: 0 0 4px; font-size: 1.2rem; color: #0f172a;">Configuración del Distribuidor</h2>
                <p style="margin: 0; font-size: 0.82rem; color: #64748b;">Gestión horaria y asignación de digitadoras por empresa.</p>
            </div>
            <span class="close-modal" onclick="cerrarModalConfigCC()" style="float: none; font-size: 22px; line-height: 1; margin-top: 2px;">&times;</span>
        </div>

        <!-- Sección 1: Hora de corte -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 14px; font-size: 0.9rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.04em;">
                Hora de Corte / Despacho Diario
            </h3>
            <div style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 140px;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 6px;">Hora de envío automático</label>
                    <select id="inputHoraDespachoCC" onchange="actualizarHoraLocalCfg()"
                        style="width: 100%; box-sizing: border-box; font-size: 1rem; padding: 9px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: 'JetBrains Mono', monospace; background: white; cursor: pointer;">
                        <option value="12:00">12:00</option>
                        <option value="13:00">13:00</option>
                        <option value="14:00">14:00</option>
                        <option value="15:00">15:00</option>
                        <option value="16:00">16:00</option>
                        <option value="17:00">17:00</option>
                        <option value="18:00">18:00</option>
                        <option value="19:00">19:00</option>
                        <option value="20:00">20:00</option>
                    </select>
                </div>
                <!-- Toggle Switch -->
                <div style="display: flex; flex-direction: column; gap: 6px; padding-bottom: 2px;">
                    <span class="toggle-label-text">Despacho Automático</span>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label class="toggle-switch">
                            <input type="checkbox" id="chkAutoDispatch" onchange="actualizarToggleLabelCfg()">
                            <span class="toggle-slider"></span>
                        </label>
                        <span class="toggle-status off" id="lblToggleStatus">DESACTIVADO</span>
                    </div>
                    <span style="font-size: 0.75rem; color: #94a3b8; max-width: 160px; line-height: 1.3;">
                        Al activar, el cron enviará el resumen a la hora configurada.
                    </span>
                </div>
            </div>
        </div>

        <!-- Sección 2: Correos Internos del Sistema -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 12px; font-size: 0.9rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.04em;">
                Correos Internos del Sistema
            </h3>
            <div style="display: flex; gap: 16px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #475569; margin-bottom: 6px;">Tesorería General (Copia / Alertas) <span style="font-weight: normal; color: #64748b; font-size: 0.75rem;">(Separar con coma)</span></label>
                    <input type="text" id="inputTesGen" placeholder="tesoreria@automarco.cl, copia@automarco.cl" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; background: white;">
                </div>
                <div style="flex: 1; min-width: 200px;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #475569; margin-bottom: 6px;">Cuentas Corrientes (Notificaciones) <span style="font-weight: normal; color: #64748b; font-size: 0.75rem;">(Separar con coma)</span></label>
                    <input type="text" id="inputCCGen" placeholder="cuentascorrientes@automarco.cl, otro@automarco.cl" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 0.85rem; background: white;">
                </div>
            </div>
        </div>

        <!-- Sección 3: Asignación Excluyente de Digitadoras -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                <h3 style="margin: 0; font-size: 0.9rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.04em;">
                    Asignación Excluyente de Digitadoras
                </h3>
            </div>
            
            <!-- Definición de Correos Globales -->
            <div style="display: flex; gap: 16px; margin-bottom: 16px;">
                <div style="flex: 1;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #1d4ed8; margin-bottom: 6px;">Correo Digitadora 1</label>
                    <input type="email" id="inputDig1" placeholder="ejemplo1@automarco.cl" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #bfdbfe; border-radius: 6px; font-size: 0.85rem; background: #eff6ff;">
                </div>
                <div style="flex: 1;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #15803d; margin-bottom: 6px;">Correo Digitadora 2</label>
                    <input type="email" id="inputDig2" placeholder="ejemplo2@automarco.cl" style="width: 100%; box-sizing: border-box; padding: 8px 12px; border: 1px solid #bbf7d0; border-radius: 6px; font-size: 0.85rem; background: #f0fdf4;">
                </div>
            </div>

            <p style="margin: 0 0 12px; font-size: 0.8rem; color: #94a3b8; font-style: italic;">
                Seleccione qué digitadora será responsable de los resúmenes diarios de cada empresa (Asignación Excluyente).
            </p>
            <div style="border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background: white;">
                <table style="font-size: 0.85rem; width: 100%; text-align: center; border-collapse: collapse;">
                    <thead>
                        <tr style="background: #f1f5f9;">
                            <th style="padding: 10px 14px; font-weight: 600; color: #475569; text-align: left;">Empresa</th>
                            <th style="padding: 10px 14px; font-weight: 600; color: #1d4ed8;">Asignar a Digitadora 1</th>
                            <th style="padding: 10px 14px; font-weight: 600; color: #15803d;">Asignar a Digitadora 2</th>
                            <th style="display: none;">ID Google Sheet</th>
                        </tr>
                    </thead>
                    <tbody id="tblAsignacionesDigitadorasCC">
                        <tr><td colspan="3" style="color: #94a3b8; padding: 16px;">Cargando asignaciones...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer de acciones -->
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
            <button type="button" class="btn-action btn-secondary" onclick="cerrarModalConfigCC()" style="padding: 9px 20px;">Cancelar</button>
            <button type="button" class="btn-action btn-success" onclick="guardarConfiguracionCC()" style="padding: 9px 20px;">Aplicar Cambios</button>
        </div>
    </div>
</div>

<style>
/* Solo estilos locales necesarios si no se cargan globalmente */
.modal-cc {
    position: fixed;
    z-index: 10000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(15, 23, 42, 0.6);
    backdrop-filter: blur(4px);
    display: none;
    align-items: center;
    justify-content: center;
}
.modal-content-cc {
    background-color: #fff;
    padding: 24px;
    border-radius: 16px;
    width: 90%;
    max-width: 900px;
    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
    position: relative;
    max-height: 90vh;
    overflow-y: auto;
}
/* Toggle Switch extraído de cuentas_corrientes.php para uso standalone */
.toggle-switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.toggle-switch input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #cbd5e1; transition: .3s; border-radius: 24px; }
.toggle-slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background-color: white; transition: .3s; border-radius: 50%; }
.toggle-switch input:checked + .toggle-slider { background-color: #16a34a; }
.toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); }
.toggle-status { font-size: 0.75rem; font-weight: 700; letter-spacing: 0.05em; padding: 2px 6px; border-radius: 4px; }
.toggle-status.off { background: #f1f5f9; color: #64748b; }
.toggle-status.on { background: #dcfce3; color: #16a34a; }
.toggle-label-text { font-size: 0.8rem; font-weight: 600; color: #475569; }
</style>

<script>
let cacheEmpresasMatrizCfg = [];

function abrirModalConfigCC() {
    document.getElementById('modalConfigCC').style.display = 'flex';
    document.getElementById('tblAsignacionesDigitadorasCC').innerHTML = '<tr><td colspan="4" style="color: #94a3b8; padding: 16px;">Cargando asignaciones...</td></tr>';
    
    // Obtener los datos frescos
    fetch('api/get_gestion_cc.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                if (typeof showToast === 'function') showToast(data.message || 'Error al obtener datos', 'error');
                return;
            }
            const info = data.data;
            document.getElementById('inputHoraDespachoCC').value = info.hora_despacho_diario;
            document.getElementById('chkAutoDispatch').checked = info.despacho_automatico_activado === '1';
            actualizarToggleLabelCfg();

            const dig1 = info.email_digitadora_1 || '';
            const dig2 = info.email_digitadora_2 || '';
            document.getElementById('inputDig1').value = dig1;
            document.getElementById('inputDig2').value = dig2;
            if (document.getElementById('inputTesGen')) document.getElementById('inputTesGen').value = info.email_tesoreria_general || '';
            if (document.getElementById('inputCCGen')) document.getElementById('inputCCGen').value = info.email_cuentas_corrientes_general || '';

            cacheEmpresasMatrizCfg = info.empresas || [];
            const tbodyEmp = document.getElementById('tblAsignacionesDigitadorasCC');
            
            if (cacheEmpresasMatrizCfg.length === 0) {
                tbodyEmp.innerHTML = '<tr><td colspan="4" style="color: #94a3b8; padding: 16px;">No hay empresas configuradas.</td></tr>';
                return;
            }

            tbodyEmp.innerHTML = cacheEmpresasMatrizCfg.map(emp => {
                const emailActual = emp.email_digitadora || '';
                const isDig2 = (emailActual === dig2 && dig2 !== '');
                return `
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                        <td style="font-weight: 600; font-size: 0.82rem; padding: 12px 14px; color: #334155; text-align: left;">${emp.nombre}</td>
                        <td>
                            <input type="radio" name="radio_emp_cfg_${emp.id}" value="1" ${!isDig2 ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px; accent-color: #2563eb;">
                        </td>
                        <td>
                            <input type="radio" name="radio_emp_cfg_${emp.id}" value="2" ${isDig2 ? 'checked' : ''} style="cursor: pointer; width: 16px; height: 16px; accent-color: #16a34a;">
                        </td>
                        <td style="display: none;">
                            <div style="display: flex; align-items: center; gap: 4px;">
                                <input type="text" id="input_sheet_id_${emp.id}" value="${emp.google_sheet_id || ''}" placeholder="ID Google Sheet" readonly style="width: 100%; box-sizing: border-box; padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 4px; font-size: 0.8rem; font-family: monospace; background-color: #f1f5f9; color: #64748b; outline: none;">
                                <button type="button" onclick="toggleLockSheetId(${emp.id})" id="btn_lock_${emp.id}" style="background: none; border: none; cursor: pointer; padding: 4px; font-size: 1.1rem; opacity: 0.6; transition: 0.2s;" title="Desbloquear">🔒</button>
                            </div>
                        </td>
                    </tr>
                `;
            }).join('');
        })
        .catch(err => {
            console.error(err);
            if (typeof showToast === 'function') showToast('Error de conexión al cargar config', 'error');
        });
}

function cerrarModalConfigCC() {
    document.getElementById('modalConfigCC').style.display = 'none';
}

function toggleLockSheetId(empId) {
    const input = document.getElementById('input_sheet_id_' + empId);
    const btn = document.getElementById('btn_lock_' + empId);
    if (input.hasAttribute('readonly')) {
        input.removeAttribute('readonly');
        input.style.backgroundColor = '#fff';
        input.style.color = '#000';
        btn.textContent = '🔓';
        btn.title = 'Bloquear';
        btn.style.opacity = '1';
        input.focus();
    } else {
        input.setAttribute('readonly', 'readonly');
        input.style.backgroundColor = '#f1f5f9';
        input.style.color = '#64748b';
        btn.textContent = '🔒';
        btn.title = 'Desbloquear';
        btn.style.opacity = '0.6';
    }
}

function actualizarToggleLabelCfg() {
    const chk = document.getElementById('chkAutoDispatch');
    const lbl = document.getElementById('lblToggleStatus');
    const inputHora = document.getElementById('inputHoraDespachoCC');
    if (chk.checked) {
        lbl.textContent = 'ACTIVADO';
        lbl.className = 'toggle-status on';
        inputHora.style.borderColor = '#16a34a';
    } else {
        lbl.textContent = 'DESACTIVADO';
        lbl.className = 'toggle-status off';
        inputHora.style.borderColor = '#cbd5e1';
    }
    
    // Si estamos en cuentas_corrientes.php, actualiza el timer visual de la pantalla principal
    if (typeof actualizarTemporizadorCorte === 'function') {
        horaCorteGlobal = inputHora.value;
        actualizarTemporizadorCorte();
    }
}

function actualizarHoraLocalCfg() {
    if (typeof actualizarTemporizadorCorte === 'function') {
        const inputHora = document.getElementById('inputHoraDespachoCC');
        horaCorteGlobal = inputHora.value;
        actualizarTemporizadorCorte();
    }
}

function guardarConfiguracionCC() {
    const inputHora = document.getElementById('inputHoraDespachoCC');
    const emailDig1 = document.getElementById('inputDig1').value.trim();
    const emailDig2 = document.getElementById('inputDig2').value.trim();
    const emailTesGen = document.getElementById('inputTesGen') ? document.getElementById('inputTesGen').value.trim() : '';
    const emailCCGen = document.getElementById('inputCCGen') ? document.getElementById('inputCCGen').value.trim() : '';

    if (!emailDig1 || !emailDig2) {
        if (typeof showToast === 'function') showToast('Ambos correos de digitadoras son requeridos.', 'error');
        return;
    }

    const asignaciones = [];
    cacheEmpresasMatrizCfg.forEach(emp => {
        const radioChecked = document.querySelector(`input[name="radio_emp_cfg_${emp.id}"]:checked`);
        let finalEmail = emailDig1; // Por defecto digitadora 1
        if (radioChecked && radioChecked.value === '2') {
            finalEmail = emailDig2;
        }
        
        const inputSheetId = document.getElementById(`input_sheet_id_${emp.id}`);
        const sheetIdValue = inputSheetId ? inputSheetId.value.trim() : '';

        asignaciones.push({
            id: emp.id,
            email: finalEmail,
            google_sheet_id: sheetIdValue
        });
    });

    if (asignaciones.length === 0) {
        if (typeof showToast === 'function') showToast('No hay empresas cargadas para asignar.', 'error');
        return;
    }

    fetch('api/guardar_configuracion_cc.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            hora_despacho_diario: inputHora.value,
            despacho_automatico_activado: document.getElementById('chkAutoDispatch').checked ? '1' : '0',
            email_digitadora_1: emailDig1,
            email_digitadora_2: emailDig2,
            email_tesoreria_general: emailTesGen,
            email_cuentas_corrientes_general: emailCCGen,
            asignaciones_empresas: asignaciones
        })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            if (typeof showToast === 'function') showToast(data.message || 'Error al guardar', 'error');
            return;
        }
        if (typeof showToast === 'function') showToast('Configuración guardada correctamente.', 'success');
        cerrarModalConfigCC();
        
        // Si estamos en la vista de cuentas_corrientes, refrescar la data principal
        if (typeof cargarDatosCC === 'function') {
            cargarDatosCC();
        }
    })
    .catch(err => {
        console.error(err);
        if (typeof showToast === 'function') showToast('Error al guardar configuración', 'error');
    });
}
</script>
