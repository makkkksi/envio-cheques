<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/MailService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    // Este endpoint puede ser llamado por cron (sin sesión) o manualmente (con sesión Admin)
    $isManual = isset($_SESSION['admin_user_id']);
    if ($isManual) {
        requireRole(['ADMINISTRADOR', 'SUPERVISORA_CC']);
    }

    $pdo = Database::getCobranzasConnection();

    // Obtener la hora configurada si se llama por cron
    $stmtConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'hora_despacho_diario'");
    $stmtConfig->execute();
    $hora_despacho_diario = $stmtConfig->fetchColumn() ?: '16:00';

    if (!$isManual) {
        $stmtAutoConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'despacho_automatico_activado'");
        $stmtAutoConfig->execute();
        $auto_dispatch_val = $stmtAutoConfig->fetchColumn();
        if ($auto_dispatch_val !== false && $auto_dispatch_val === '0') {
            echo json_encode(['success' => false, 'message' => 'El despacho automático por hora está desactivado en la configuración.']);
            exit;
        }
        $current_time = date('H:i');
        // Tolerancia de 5 minutos
        if ($current_time < $hora_despacho_diario) {
            echo json_encode(['success' => false, 'message' => "Aún no es la hora de despacho ($hora_despacho_diario). Actual: $current_time"]);
            exit;
        }
    }

    // Correo supervisor
    $stmtSup = $pdo->prepare("SELECT email FROM usuarios WHERE rol = 'SUPERVISORA_CC' LIMIT 1");
    $stmtSup->execute();
    $ccEmail = $stmtSup->fetchColumn() ?: 'cuentascorrientes@automarco.cl';

    // Obtener todas las cobranzas aprobadas
    $stmtCobranzas = $pdo->prepare("
        SELECT 
            c.id, c.empresa_id, e.nombre as empresa_nombre, e.email_tesoreria_defecto,
            c.vendedor_nombre, c.rut_cliente, c.razon_social_cliente, c.numero_factura
        FROM cobranzas c
        JOIN empresas e ON c.empresa_id = e.id
        WHERE c.estado = 'RECIBIDO_TESORERIA'
    ");
    $stmtCobranzas->execute();
    $cobranzasHoy = $stmtCobranzas->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cobranzasHoy)) {
        echo json_encode(['success' => true, 'message' => 'No hay cobranzas validadas hoy. No se envía correo (Anti-Spam).']);
        exit;
    }

    // Agrupar por empresa
    $agrupado = [];
    foreach ($cobranzasHoy as $c) {
        $empId = $c['empresa_id'];
        if (!isset($agrupado[$empId])) {
            $agrupado[$empId] = [
                'nombre' => $c['empresa_nombre'],
                'email' => $c['email_tesoreria_defecto'],
                'cobranzas' => []
            ];
        }
        $agrupado[$empId]['cobranzas'][] = $c;
    }

    $resultados = [];

    // Enviar un correo por cada empresa
    foreach ($agrupado as $empId => $data) {
        $destinatario = $data['email'];
        if (empty($destinatario)) continue;

        $totalCobranzas = count($data['cobranzas']);
        $asunto = "[PARA DIGITADORAS] Resumen Diario Cuentas Corrientes - " . $data['nombre'] . " (" . date('d/m/Y') . ")";

        // Enviar usando el layout unificado y ultra-ordenado
        $enviado = MailService::enviarResumenDiarioDigitadora($data['nombre'], $data['cobranzas'], $destinatario, $ccEmail, $pdo);
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
            $stmtHist = $pdo->prepare("INSERT INTO historial_estados (cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario) VALUES (?, ?, 'RECIBIDO_TESORERIA', 'DEPOSITADO', ?)");
            $userAuditId = $_SESSION['admin_user_id'] ?? 1;

            foreach ($data['cobranzas'] as $cobranza) {
                $stmtUpd->execute([$cobranza['id']]);
                $stmtHist->execute([$cobranza['id'], $userAuditId, 'Liberado por Cuentas Corrientes y despachado a digitadora para ingreso en Optimus ERP']);
            }
        }

        $resultados[] = [
            'empresa' => $data['nombre'],
            'estado' => $estado
        ];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Proceso de despacho finalizado.',
        'data' => $resultados
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
