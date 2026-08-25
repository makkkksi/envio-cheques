<?php $canManageSheetIds = isset($rolUsuario) && userHasPermission($rolUsuario, 'companies.manage'); ?>
<!-- admin/components/modal_config_cc.php -->
<div id="modalConfigCC" class="modal-cc" data-can-manage-sheets="<?php echo $canManageSheetIds ? '1' : '0'; ?>" hidden>
    <div class="modal-content-cc" style="max-width: 900px;">
        <!-- Header del modal -->
        <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e2e8f0;">
            <div>
                <h2 style="margin: 0 0 4px; font-size: 1.2rem; color: #0f172a;">Configuración del Distribuidor</h2>
                <p style="margin: 0; font-size: 0.82rem; color: #64748b;">Gestión horaria y asignación de digitadoras por empresa.</p>
            </div>
            <button type="button" class="close-modal" id="btnCerrarConfigCC" style="float: none; font-size: 22px; line-height: 1; margin-top: 2px;" aria-label="Cerrar configuración">&times;</button>
        </div>

        <!-- Sección 1: Hora de corte -->
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px; margin-bottom: 20px;">
            <h3 style="margin: 0 0 14px; font-size: 0.9rem; font-weight: 700; color: #334155; text-transform: uppercase; letter-spacing: 0.04em;">
                Hora de Corte / Despacho Diario
            </h3>
            <div style="display: flex; align-items: flex-end; gap: 20px; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 140px;">
                    <label style="display: block; font-size: 0.8rem; font-weight: 600; color: #64748b; margin-bottom: 6px;">Hora de envío automático</label>
                    <input type="time" id="inputHoraDespachoCC" step="60"
                        style="width: 100%; box-sizing: border-box; font-size: 1.05rem; font-weight: 600; padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-family: 'JetBrains Mono', monospace; background: white; color: #0f172a; cursor: text;">
                </div>
                <!-- Toggle Switch -->
                <div style="display: flex; flex-direction: column; gap: 6px; padding-bottom: 2px;">
                    <span class="toggle-label-text">Despacho Automático</span>
                    <div style="display: flex; align-items: center; gap: 10px;">
                        <label class="toggle-switch">
                            <input type="checkbox" id="chkAutoDispatch">
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
                            <?php if ($canManageSheetIds): ?>
                            <th>ID Google Sheet</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="tblAsignacionesDigitadorasCC">
                        <tr><td colspan="<?php echo $canManageSheetIds ? '4' : '3'; ?>" style="color: #94a3b8; padding: 16px;">Cargando asignaciones...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Footer de acciones -->
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 20px; padding-top: 16px; border-top: 1px solid #f1f5f9;">
            <button type="button" class="btn-action btn-secondary" id="btnCancelarConfigCC" style="padding: 9px 20px;">Cancelar</button>
            <button type="button" class="btn-action btn-success" id="btnGuardarConfigCC" style="padding: 9px 20px;">Aplicar Cambios</button>
        </div>
    </div>
</div>
