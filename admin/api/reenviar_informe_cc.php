<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/MailService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    requireRole(['ADMINISTRADOR', 'SUPERVISORA_CC']);
    $pdo = Database::getCobranzasConnection();

    $input = json_decode(file_get_contents('php://input'), true);
    $logId = isset($input['log_id']) ? (int)$input['log_id'] : 0;
    $nuevoCorreo = isset($input['nuevo_correo']) ? trim($input['nuevo_correo']) : '';

    if ($logId <= 0) {
        throw new Exception("ID de log inválido.");
    }

    // 1. Obtener datos del log original
    $stmtLog = $pdo->prepare("SELECT * FROM log_envios_informes WHERE id = ?");
    $stmtLog->execute([$logId]);
    $logOriginal = $stmtLog->fetch(PDO::FETCH_ASSOC);

    if (!$logOriginal) {
        throw new Exception("No se encontró el registro original.");
    }

    $empId = $logOriginal['empresa_id'];
    $fechaFiltro = date('Y-m-d', strtotime($logOriginal['fecha_envio']));
    $destinatario = !empty($nuevoCorreo) ? $nuevoCorreo : $logOriginal['destinatario'];
    $ccEmail = $logOriginal['copia_cc'];

    // 2. Obtener la empresa
    $stmtEmp = $pdo->prepare("SELECT nombre FROM empresas WHERE id = ?");
    $stmtEmp->execute([$empId]);
    $empresaNombre = $stmtEmp->fetchColumn();

    // 3. Obtener cobranzas de ESA fecha
    $stmtCobranzas = $pdo->prepare("
        SELECT 
            c.id, c.vendedor_nombre, c.rut_cliente, c.razon_social_cliente, c.numero_factura
        FROM cobranzas c
        WHERE c.empresa_id = ?
        AND c.estado = 'RECIBIDO_TESORERIA'
        AND DATE(c.updated_at) = ?
    ");
    $stmtCobranzas->execute([$empId, $fechaFiltro]);
    $cobranzas = $stmtCobranzas->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cobranzas)) {
        throw new Exception("No hay cobranzas para reconstruir el informe de esa fecha.");
    }

    // 4. Reconstruir el HTML
    $totalCobranzas = count($cobranzas);
    $asunto = "[Re-envío] " . $logOriginal['asunto'];

    $html = "<div style='font-family: Arial, sans-serif; max-width: 800px; margin: 0 auto; color: #333;'>";
    $html .= "<h2 style='background-color: #0f172a; color: #fff; padding: 15px; border-radius: 5px;'>Resumen Diario: " . htmlspecialchars($empresaNombre) . "</h2>";
    $html .= "<p>Estimada Digitadora, a continuación se detallan las <strong>$totalCobranzas cobranzas</strong> validadas el $fechaFiltro para su ingreso en Optimus ERP:</p>";

    foreach ($cobranzas as $cobranza) {
        $html .= "<div style='border: 1px solid #ccc; margin-bottom: 15px; padding: 10px; border-left: 5px solid #2563eb;'>";
        $html .= "<h3>RUT: <strong>" . htmlspecialchars($cobranza['rut_cliente']) . "</strong> - " . htmlspecialchars($cobranza['razon_social_cliente']) . "</h3>";
        $html .= "<p>Vendedor: " . htmlspecialchars($cobranza['vendedor_nombre']) . " | N° Factura/Doc: " . htmlspecialchars($cobranza['numero_factura']) . "</p>";
        
        $stmtChq = $pdo->prepare("SELECT numero_cheque, banco, monto AS monto_cheque, fecha_vencimiento FROM cheques WHERE cobranza_id = ?");
        $stmtChq->execute([$cobranza['id']]);
        $cheques = $stmtChq->fetchAll(PDO::FETCH_ASSOC);
        
        if ($cheques) {
            $html .= "<table style='width: 100%; border-collapse: collapse; margin-top: 10px;'>";
            $html .= "<tr style='background: #f1f5f9;'><th style='padding:5px; border:1px solid #ddd;'>Banco</th><th style='padding:5px; border:1px solid #ddd;'>N° Cheque</th><th style='padding:5px; border:1px solid #ddd;'>Vencimiento</th><th style='padding:5px; border:1px solid #ddd;'>Monto</th></tr>";
            foreach ($cheques as $chq) {
                $montoFmt = '$' . number_format($chq['monto_cheque'], 0, ',', '.');
                $fechaFmt = date('d/m/Y', strtotime($chq['fecha_vencimiento']));
                $html .= "<tr>";
                $html .= "<td style='padding:5px; border:1px solid #ddd;'>" . htmlspecialchars($chq['banco']) . "</td>";
                $html .= "<td style='padding:5px; border:1px solid #ddd;'><strong>" . htmlspecialchars($chq['numero_cheque']) . "</strong></td>";
                $html .= "<td style='padding:5px; border:1px solid #ddd;'>" . $fechaFmt . "</td>";
                $html .= "<td style='padding:5px; border:1px solid #ddd;'>" . $montoFmt . "</td>";
                $html .= "</tr>";
            }
            $html .= "</table>";
        }
        $html .= "</div>";
    }
    $html .= "</div>";

    // 5. Reenviar
    $enviado = MailService::sendSmtp($destinatario, $asunto, $html, [], $ccEmail);
    $estado = $enviado ? 'ENVIADO' : 'FALLIDO';
    $errorMensaje = $enviado ? null : 'Falla en el re-envío SMTP';

    // 6. Registrar nuevo log
    $stmtNuevoLog = $pdo->prepare("
        INSERT INTO log_envios_informes 
        (empresa_id, tipo_informe, destinatario, copia_cc, asunto, estado_envio, error_mensaje, cantidad_cobranzas)
        VALUES (?, 'RESUMEN_DIARIO_16HRS', ?, ?, ?, ?, ?, ?)
    ");
    $stmtNuevoLog->execute([
        $empId, $destinatario, $ccEmail, $asunto, $estado, $errorMensaje, $totalCobranzas
    ]);

    if (!$enviado) {
        throw new Exception("Fallo al enviar correo SMTP.");
    }

    echo json_encode([
        'success' => true,
        'message' => 'Informe re-enviado correctamente a ' . htmlspecialchars($destinatario)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
