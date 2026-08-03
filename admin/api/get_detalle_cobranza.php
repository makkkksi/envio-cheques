<?php
/**
 * admin/api/get_detalle_cobranza.php
 * 
 * Obtiene todos los detalles de una cobranza específica para el Portal de Tesorería.
 * Devuelve información de la cobranza, cheques adjuntos e historial de auditoría.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
}

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'ID de cobranza inválido']);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();

    // 1. Obtener cobranza
    $stmt = $pdo->prepare("SELECT 
                c.id,
                c.empresa_id,
                COALESCE(e.nombre, 'Multi-Empresa') AS empresa_nombre,
                c.numero_factura,
                c.razon_social_cliente,
                c.rut_cliente,
                c.email_cliente,
                c.email_tesoreria,
                c.monto_total_factura,
                c.tipo_entrega,
                c.numero_seguimiento,
                c.comprobante_url,
                c.estado,
                c.justificacion_descuadre,
                c.created_at,
                COALESCE(u.nombre, NULLIF(c.vendedor_nombre, ''), 'Vendedor no especificado (Registro del Sistema)') AS vendedor_nombre
            FROM cobranzas c
            LEFT JOIN empresas e ON c.empresa_id = e.id
            LEFT JOIN usuarios u ON c.vendedor_id = u.id
            WHERE c.id = :id");
    $stmt->execute([':id' => $id]);
    $cobranza = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cobranza) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cobranza no encontrada']);
        exit;
    }

    // 2. Obtener facturas desglosadas (cobranza_facturas)
    $stmtFacturas = $pdo->prepare("SELECT 
                                    id,
                                    empresa_id,
                                    codigo_empresa,
                                    numero_factura,
                                    cuota_label,
                                    total_cuota,
                                    saldo_cuota,
                                    monto_cubierto,
                                    created_at
                                FROM cobranza_facturas 
                                WHERE cobranza_id = :id
                                ORDER BY CAST(numero_factura AS UNSIGNED) ASC, cuota_label ASC, id ASC");
    $stmtFacturas->execute([':id' => $id]);
    $facturas = $stmtFacturas->fetchAll(PDO::FETCH_ASSOC);

    // 3. Obtener cheques
    $stmtCheques = $pdo->prepare("SELECT 
                                    id,
                                    banco,
                                    numero_cheque,
                                    monto,
                                    fecha_vencimiento,
                                    foto_cheque_url,
                                    comentario,
                                    numero_papeleta_deposito,
                                    fecha_deposito_real
                                FROM cheques 
                                WHERE cobranza_id = :id
                                ORDER BY id ASC");
    $stmtCheques->execute([':id' => $id]);
    $cheques = $stmtCheques->fetchAll(PDO::FETCH_ASSOC);

    // 4. Obtener historial de auditoría
    $stmtHistorial = $pdo->prepare("SELECT 
                                        h.id,
                                        h.estado_anterior,
                                        h.estado_nuevo,
                                        h.comentario,
                                        h.created_at,
                                        u.nombre AS usuario_nombre
                                    FROM historial_estados h
                                    LEFT JOIN usuarios u ON h.usuario_id = u.id
                                    WHERE h.cobranza_id = :id
                                    ORDER BY h.created_at ASC");
    $stmtHistorial->execute([':id' => $id]);
    $historial = $stmtHistorial->fetchAll(PDO::FETCH_ASSOC);

    // Calcular suma total cheques
    $totalMontoCheques = 0;
    foreach ($cheques as $chk) {
        $totalMontoCheques += (float)($chk['monto'] ?? 0);
    }
    $cobranza['total_cheques'] = $totalMontoCheques;
    $cobranza['cantidad_cheques'] = count($cheques);

    echo json_encode([
        'success' => true,
        'data' => [
            'cobranza' => $cobranza,
            'facturas' => $facturas,
            'cheques' => $cheques,
            'historial' => $historial
        ]
    ]);

} catch (Exception $e) {
    error_log('[admin/api/get_detalle_cobranza.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar el detalle de la cobranza']);
}
