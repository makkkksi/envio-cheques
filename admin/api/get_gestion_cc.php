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

    $stmtDig1 = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'email_digitadora_1'");
    $stmtDig1->execute();
    $email_digitadora_1 = $stmtDig1->fetchColumn() ?: '';

    $stmtDig2 = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'email_digitadora_2'");
    $stmtDig2->execute();
    $email_digitadora_2 = $stmtDig2->fetchColumn() ?: '';

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

    // 3. Obtener el historial de la bitácora paginado (10 por página)
    $historialPage = max(1, (int)($_GET['historial_page'] ?? 1));
    $historialLimit = 10;
    $historialOffset = ($historialPage - 1) * $historialLimit;

    // Contar total de registros para paginación
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM log_envios_informes");
    $stmtCount->execute();
    $totalHistorial = (int)$stmtCount->fetchColumn();
    $totalPages = (int)ceil($totalHistorial / $historialLimit);

    $stmtLog = $pdo->prepare("
        SELECT 
            l.id,
            e.nombre AS empresa,
            l.destinatario,
            l.cantidad_cobranzas,
            l.estado_envio,
            l.error_mensaje,
            l.fecha_envio,
            l.payload_json
        FROM log_envios_informes l
        LEFT JOIN empresas e ON l.empresa_id = e.id
        ORDER BY l.fecha_envio DESC
        LIMIT :limit OFFSET :offset
    ");
    $stmtLog->bindValue(':limit', $historialLimit, PDO::PARAM_INT);
    $stmtLog->bindValue(':offset', $historialOffset, PDO::PARAM_INT);
    $stmtLog->execute();
    $log_envios = $stmtLog->fetchAll(PDO::FETCH_ASSOC);

    // 4. Obtener listado de cobranzas en cola (pendientes de liberación)
    $stmtCobranzasCola = $pdo->prepare("
        SELECT 
            c.id as cobranza_id,
            c.rut_cliente,
            c.razon_social_cliente,
            c.vendedor_nombre,
            c.numero_factura,
            e.nombre as empresa_nombre,
            c.updated_at
        FROM cobranzas c
        JOIN empresas e ON c.empresa_id = e.id
        WHERE c.estado = 'RECIBIDO_TESORERIA'
        ORDER BY c.updated_at ASC
    ");
    $stmtCobranzasCola->execute();
    $cobranzas_en_cola = $stmtCobranzasCola->fetchAll(PDO::FETCH_ASSOC);

    // Adjuntar cheques y facturas a cada cobranza
    foreach ($cobranzas_en_cola as &$cob) {
        $cobId = $cob['cobranza_id'];
        
        $stmtChq = $pdo->prepare("SELECT numero_cheque, banco, monto as monto_cheque, fecha_vencimiento FROM cheques WHERE cobranza_id = ?");
        $stmtChq->execute([$cobId]);
        $cob['cheques'] = $stmtChq->fetchAll(PDO::FETCH_ASSOC);

        $stmtFac = $pdo->prepare("SELECT numero_factura, cuota_label, monto_cubierto FROM cobranza_facturas WHERE cobranza_id = ?");
        $stmtFac->execute([$cobId]);
        $cob['facturas_multiples'] = $stmtFac->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($cob);

    echo json_encode([
        'success' => true,
        'data' => [
            'hora_despacho_diario' => $hora_despacho_diario,
            'despacho_automatico_activado' => $despacho_automatico_activado,
            'email_digitadora_1' => $email_digitadora_1,
            'email_digitadora_2' => $email_digitadora_2,
            'empresas' => $empresas,
            'log_envios' => $log_envios,
            'historial_page' => $historialPage,
            'historial_total_pages' => $totalPages,
            'historial_total' => $totalHistorial,
            'cobranzas_en_cola' => $cobranzas_en_cola
        ],
        'message' => 'Datos obtenidos correctamente'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
