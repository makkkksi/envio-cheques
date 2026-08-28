<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';

RendicionesService::requireMethod('GET');

try {
    $pdo = Database::getCobranzasConnection();
    $seller = requireSellerContext($pdo);

    $stmtDocuments = $pdo->prepare(
        'SELECT id, tipo_documento, categoria_gasto, rut_proveedor,
                razon_social_proveedor, numero_documento, fecha_emision, monto,
                descripcion, foto_documento_url, cliente_invitado_nombre,
                cliente_invitado_rut, cliente_invitado_empresa,
                cliente_invitado_cargo, proposito_comercial, created_at
         FROM rendicion_documentos
         WHERE empresa_id = :empresa_id
           AND vendedor_id = :vendedor_id
           AND rendicion_id IS NULL
           AND estado_item = :estado_item
           AND activo = :activo
         ORDER BY fecha_emision DESC, id DESC'
    );
    $stmtDocuments->execute([
        ':empresa_id' => $seller['empresa_id'],
        ':vendedor_id' => $seller['vendedor_id'],
        ':estado_item' => 'BORRADOR',
        ':activo' => 1,
    ]);

    $stmtBudgets = $pdo->prepare(
        'SELECT p.id, p.tipo_presupuesto, p.nombre_gira, p.periodo_mes,
                p.fecha_inicio, p.fecha_fin, p.monto_asignado, p.monto_utilizado,
                p.estado_aprobacion,
                (p.monto_asignado - p.monto_utilizado) AS saldo_disponible,
                COALESCE((
                    SELECT SUM(r.monto_total_aprobado)
                    FROM rendiciones_gastos r
                    WHERE r.presupuesto_id = p.id
                      AND r.estado IN (:estado_aprobada, :estado_parcial, :estado_pagada)
                ), 0) AS monto_aprobado
         FROM presupuestos_vendedores p
         WHERE p.empresa_id = :empresa_id
           AND p.vendedor_id = :vendedor_id
           AND p.activo = :activo
           AND (p.tipo_presupuesto = :tipo_mensual OR p.estado_aprobacion = :estado_aprobado)
         ORDER BY p.periodo_mes DESC, p.tipo_presupuesto ASC, p.id DESC'
    );
    $stmtBudgets->execute([
        ':estado_aprobada' => 'APROBADA',
        ':estado_parcial' => 'APROBADA_PARCIAL',
        ':estado_pagada' => 'PAGADA',
        ':tipo_mensual' => 'MENSUAL',
        ':estado_aprobado' => 'APROBADO',
        ':empresa_id' => $seller['empresa_id'],
        ':vendedor_id' => $seller['vendedor_id'],
        ':activo' => 1,
    ]);

    $documents = $stmtDocuments->fetchAll(PDO::FETCH_ASSOC);
    $budgets = $stmtBudgets->fetchAll(PDO::FETCH_ASSOC);
    foreach ($documents as &$document) {
        $document['id'] = (int)$document['id'];
        $document['monto'] = (float)$document['monto'];
    }
    unset($document);
    foreach ($budgets as &$budget) {
        $budget['id'] = (int)$budget['id'];
        $budget['monto_asignado'] = (float)$budget['monto_asignado'];
        $budget['monto_utilizado'] = (float)$budget['monto_utilizado'];
        $budget['monto_aprobado'] = (float)$budget['monto_aprobado'];
        $budget['monto_pendiente'] = max(0.0, $budget['monto_utilizado'] - $budget['monto_aprobado']);
        $budget['saldo_disponible'] = (float)$budget['saldo_disponible'];
    }
    unset($budget);

    RendicionesService::jsonResponse(true, ['data' => [
        'documentos' => $documents,
        'presupuestos' => $budgets,
        'csrf_token' => getCsrfToken(),
    ]]);
} catch (Throwable $exception) {
    error_log('[rendiciones.get_bolsa_gastos] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible cargar la bolsa de gastos.'], 500);
}
