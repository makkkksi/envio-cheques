// modal_config_cc.js
let cacheEmpresasMatrizCfg = [];

function abrirModalConfigCC() {
    const modal = document.getElementById('modalConfigCC');
    if (!modal) return;
    modal.style.display = 'flex';
    
    const tbody = document.getElementById('tblAsignacionesDigitadorasCC');
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="4" style="color: #94a3b8; padding: 16px;">Cargando asignaciones...</td></tr>';
    }
    
    // Obtener los datos frescos
    fetch('api/get_gestion_cc.php')
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                if (typeof showToast === 'function') showToast(data.message || 'Error al obtener datos', 'error');
                return;
            }
            const info = data.data;
            const inputHora = document.getElementById('inputHoraDespachoCC');
            if (inputHora) inputHora.value = info.hora_despacho_diario;
            
            const chkAuto = document.getElementById('chkAutoDispatch');
            if (chkAuto) chkAuto.checked = info.despacho_automatico_activado === '1';
            actualizarToggleLabelCfg();

            const dig1 = info.email_digitadora_1 || '';
            const dig2 = info.email_digitadora_2 || '';
            if (document.getElementById('inputDig1')) document.getElementById('inputDig1').value = dig1;
            if (document.getElementById('inputDig2')) document.getElementById('inputDig2').value = dig2;
            if (document.getElementById('inputTesGen')) document.getElementById('inputTesGen').value = info.email_tesoreria_general || '';
            if (document.getElementById('inputCCGen')) document.getElementById('inputCCGen').value = info.email_cuentas_corrientes_general || '';

            cacheEmpresasMatrizCfg = info.empresas || [];
            const tbodyEmp = document.getElementById('tblAsignacionesDigitadorasCC');
            if (!tbodyEmp) return;
            
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
    const modal = document.getElementById('modalConfigCC');
    if (modal) modal.style.display = 'none';
}

function toggleLockSheetId(empId) {
    const input = document.getElementById('input_sheet_id_' + empId);
    const btn = document.getElementById('btn_lock_' + empId);
    if (!input || !btn) return;
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
    if (!chk || !lbl || !inputHora) return;
    
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
        if (typeof horaCorteGlobal !== 'undefined') {
            horaCorteGlobal = inputHora.value;
        }
        actualizarTemporizadorCorte();
    }
}

function actualizarHoraLocalCfg() {
    if (typeof actualizarTemporizadorCorte === 'function') {
        const inputHora = document.getElementById('inputHoraDespachoCC');
        if (inputHora && typeof horaCorteGlobal !== 'undefined') {
            horaCorteGlobal = inputHora.value;
        }
        actualizarTemporizadorCorte();
    }
}

function guardarConfiguracionCC() {
    const inputHora = document.getElementById('inputHoraDespachoCC');
    const horaValor = inputHora && inputHora.value ? inputHora.value.trim() : '';

    if (!horaValor || !/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/.test(horaValor)) {
        if (typeof showToast === 'function') showToast('Por favor, ingrese una hora válida en formato HH:MM (ej. 16:30)', 'error');
        return;
    }

    const emailDig1 = document.getElementById('inputDig1') ? document.getElementById('inputDig1').value.trim() : '';
    const emailDig2 = document.getElementById('inputDig2') ? document.getElementById('inputDig2').value.trim() : '';
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
            hora_despacho_diario: inputHora ? inputHora.value : '16:00',
            despacho_automatico_activado: (document.getElementById('chkAutoDispatch') && document.getElementById('chkAutoDispatch').checked) ? '1' : '0',
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

document.addEventListener('DOMContentLoaded', () => {
    const btnCfg1 = document.getElementById('btnHeaderConfig');
    if (btnCfg1) btnCfg1.addEventListener('click', abrirModalConfigCC);
    const btnCfg2 = document.getElementById('btnHeaderConfigCC');
    if (btnCfg2) btnCfg2.addEventListener('click', abrirModalConfigCC);
});
