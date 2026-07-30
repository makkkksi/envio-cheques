<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    requireRole(['ADMINISTRADOR', 'SUPERVISORA_CC']);
    $pdo = Database::getCobranzasConnection();

    // 1. Obtener la hora de despacho configurada
    $stmtConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'hora_despacho_diario'");
    $stmtConfig->execute();
    $hora_despacho_diario = $stmtConfig->fetchColumn() ?: '16:00';

    $stmtAutoConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'despacho_automatico_activado'");
    $stmtAutoConfig->execute();
    $despacho_automatico_activado = $stmtAutoConfig->fetchColumn();
    if ($despacho_automatico_activado === false) $despacho_automatico_activado = '1';

    // 2. Obtener matriz de empresas y sus digitadoras asignadas + cheques pendientes de hoy
    $stmtEmpresas = $pdo->prepare("
        SELECT 
            e.id, 
            e.nombre, 
            e.email_tesoreria_defecto AS email_digitadora,
            (
                SELECT COUNT(c.id) 
                FROM cobranzas c 
                WHERE c.empresa_id = e.id 
                AND c.estado = 'RECIBIDO_TESORERIA'
            ) as cheques_pendientes_hoy
        FROM empresas e
        ORDER BY e.id ASC
    ");
    $stmtEmpresas->execute();
    $empresas = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener el historial de la bitácora (últimos 50 envíos)
    $stmtLog = $pdo->prepare("
        SELECT 
            l.id,
            e.nombre AS empresa,
            l.destinatario,
            l.cantidad_cobranzas,
            l.estado_envio,
            l.error_mensaje,
            l.fecha_envio
        FROM log_envios_informes l
        LEFT JOIN empresas e ON l.empresa_id = e.id
        ORDER BY l.fecha_envio DESC
        LIMIT 50
    ");
    $stmtLog->execute();
    $log_envios = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

    // 4. Obtener listado de cheques en cola (pendientes de liberación)
    $stmtChequesEnCola = $pdo->prepare("
        SELECT 
            c.id as cobranza_id,
            c.rut_cliente,
            c.razon_social_cliente,
            c.vendedor_nombre,
            c.numero_factura,
            e.nombre as empresa_nombre,
            ch.numero_cheque,
            ch.banco,
            ch.monto as monto_cheque,
            ch.fecha_vencimiento
        FROM cobranzas c
        JOIN empresas e ON c.empresa_id = e.id
        JOIN cheques ch ON ch.cobranza_id = c.id
        WHERE c.estado = 'RECIBIDO_TESORERIA'
        ORDER BY c.updated_at ASC
    ");
    $stmtChequesEnCola->execute();
    $cheques_en_cola = $stmtChequesEnCola->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => [
            'hora_despacho_diario' => $hora_despacho_diario,
            'despacho_automatico_activado' => $despacho_automatico_activado,
            'empresas' => $empresas,
            'log_envios' => $log_envios,
            'cheques_en_cola' => $cheques_en_cola
        ],
        'message' => 'Datos obtenidos correctamente'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
