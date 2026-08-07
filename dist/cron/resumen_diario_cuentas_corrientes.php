<?php
/**
 * Cron: Resumen Diario de Cuentas Corrientes (16:00 hrs o configurable)
 * 
 * Se ejecuta vía programador de tareas (cron o task scheduler).
 * Sincroniza timezone dinámicamente y previene envíos duplicados.
 */

// Sincronizar zona horaria de Chile
date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/MailService.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    // 1. Validar si el despacho automático está activo desde la configuración en BD (Panel de Control)
    $stmtAuto = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'despacho_automatico_activado'");
    $stmtAuto->execute();
    $auto_activado = $stmtAuto->fetchColumn();
    
    if ($auto_activado !== '1') {
        echo "[" . date('Y-m-d H:i:s') . "] El despacho automático por hora está DESACTIVADO desde el panel de control.\n";
        exit;
    }
    
    // Sincronizar timezone en la sesión de MySQL de forma dinámica con PHP
    $offset = date('P'); // Retorna ej: -04:00 o -03:00
    $pdo->exec("SET time_zone = '$offset';");

    // 1. Obtener la hora de corte configurada
    $stmtConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'hora_despacho_diario'");
    $stmtConfig->execute();
    $hora_despacho_diario = $stmtConfig->fetchColumn() ?: '16:00';

    $hora_actual = date('H:i');

    // Si la hora actual es menor que la hora de corte configurada, no ejecutar
    if ($hora_actual < $hora_despacho_diario) {
        echo "[" . date('Y-m-d H:i:s') . "] Omitiendo ejecución: Hora actual ($hora_actual) es menor que la hora de corte configurada ($hora_despacho_diario).\n";
        exit;
    }

    // Correo de la supervisora de Cuentas Corrientes (CC)
    $stmtSup = $pdo->prepare("SELECT email FROM usuarios WHERE rol = 'SUPERVISORA_CC' LIMIT 1");
    $stmtSup->execute();
    $ccEmail = $stmtSup->fetchColumn() ?: 'cuentascorrientes@automarco.cl';

    // 2. Obtener todas las empresas
    $stmtEmpresas = $pdo->prepare("SELECT id, nombre, email_tesoreria_defecto FROM empresas");
    $stmtEmpresas->execute();
    $empresas = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);

    foreach ($empresas as $empresa) {
        $empId = $empresa['id'];
        $destinatario = $empresa['email_tesoreria_defecto'];

        if (empty($destinatario)) {
            echo "[" . date('Y-m-d H:i:s') . "] Empresa ID {$empId} ({$empresa['nombre']}) no tiene correo asignado. Omitiendo.\n";
            continue;
        }

        // 3. CONTROL DE IDEMPOTENCIA (Locking): ¿Ya se envió el resumen hoy para esta empresa?
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM log_envios_informes 
            WHERE empresa_id = ? 
            AND tipo_informe = 'RESUMEN_DIARIO_16HRS' 
            AND estado_envio = 'ENVIADO' 
            AND DATE(fecha_envio) = CURDATE()
        ");
        $stmtCheck->execute([$empId]);
        if ($stmtCheck->fetchColumn() > 0) {
            echo "[" . date('Y-m-d H:i:s') . "] Resumen de hoy ya fue enviado con éxito para {$empresa['nombre']}. Omitiendo duplicados.\n";
            continue;
        }

        // 4. Obtener cobranzas aprobadas para esta empresa
        $stmtCobranzas = $pdo->prepare("
            SELECT 
                c.id, c.vendedor_nombre, c.rut_cliente, c.razon_social_cliente, c.numero_factura
            FROM cobranzas c
            WHERE c.empresa_id = ?
            AND c.estado = 'RECIBIDO_TESORERIA'
        ");
        $stmtCobranzas->execute([$empId]);
        $cobranzas = $stmtCobranzas->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cobranzas)) {
            echo "[" . date('Y-m-d H:i:s') . "] Sin movimientos hoy para {$empresa['nombre']} (Regla Anti-Spam). Omitiendo.\n";
            continue;
        }

        $totalCobranzas = count($cobranzas);
        $asunto = "[PARA DIGITADORAS] Resumen Diario Cuentas Corrientes - " . $empresa['nombre'] . " (" . date('d/m/Y') . ")";

        // Enviar usando el layout unificado y ultra-ordenado
        $enviado = MailService::enviarResumenDiarioDigitadora($empresa['nombre'], $cobranzas, $destinatario, $pdo);
        $estado = $enviado ? 'ENVIADO' : 'FALLIDO';
        $errorMensaje = $enviado ? null : 'Falla en el envío SMTP';

        // Registrar en bitácora
        $stmtLog = $pdo->prepare("
            INSERT INTO log_envios_informes 
            (empresa_id, tipo_informe, destinatario, copia_cc, asunto, estado_envio, error_mensaje, cantidad_cobranzas)
            VALUES (?, 'RESUMEN_DIARIO_16HRS', ?, ?, ?, ?, ?, ?)
        ");
        $stmtLog->execute([
            $empId, $destinatario, $ccEmail, $asunto, $estado, $errorMensaje, $totalCobranzas
        ]);

        // Si el envío fue exitoso, actualizar estado final a DEPOSITADO e insertar historial de auditoría
        if ($enviado) {
            $stmtUpd = $pdo->prepare("UPDATE cobranzas SET estado = 'DEPOSITADO', updated_at = NOW() WHERE id = ?");
            $stmtHist = $pdo->prepare("INSERT INTO historial_estados (cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario) VALUES (?, 1, 'RECIBIDO_TESORERIA', 'DEPOSITADO', ?)");

            foreach ($cobranzas as $cobranza) {
                $stmtUpd->execute([$cobranza['id']]);
                $stmtHist->execute([$cobranza['id'], 'Liberado por Cron Cuentas Corrientes y despachado a digitadora para ingreso en Optimus ERP']);
            }
        }

        echo "[" . date('Y-m-d H:i:s') . "] Resumen enviado para {$empresa['nombre']} a {$destinatario}. Estado: {$estado}\n";
    }

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR CRÓNICO: " . $e->getMessage() . "\n";
}
