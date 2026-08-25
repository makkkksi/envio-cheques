<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/RendicionesService.php';

RendicionesService::requireMethod('GET');

try {
    $pdo = Database::getCobranzasConnection();
    requirePermission($pdo, 'rendiciones.view');
    $renditionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$renditionId) {
        throw new InvalidArgumentException('id de rendición es obligatorio.');
    }

    $stmtHeader = $pdo->prepare(
        'SELECT r.*, e.nombre AS empresa_nombre, p.nombre_gira,
                p.fecha_inicio AS gira_fecha_inicio, p.fecha_fin AS gira_fecha_fin,
                p.monto_asignado AS presupuesto_monto_actual,
                p.monto_utilizado AS presupuesto_monto_utilizado
         FROM rendiciones_gastos r
         INNER JOIN empresas e ON e.id = r.empresa_id
         INNER JOIN presupuestos_vendedores p ON p.id = r.presupuesto_id
         WHERE r.id = :id AND r.activo = :activo
         LIMIT 1'
    );
    $stmtHeader->execute([':id' => $renditionId, ':activo' => 1]);
    $rendition = $stmtHeader->fetch(PDO::FETCH_ASSOC);
    if (!$rendition) {
        RendicionesService::jsonResponse(false, ['message' => 'Rendición no encontrada.'], 404);
    }

    $stmtDocuments = $pdo->prepare(
        'SELECT id, tipo_documento, categoria_gasto, rut_proveedor,
                razon_social_proveedor, numero_documento, fecha_emision,
                monto, monto_validado, descripcion, foto_documento_url,
                cliente_invitado_nombre, cliente_invitado_rut,
                cliente_invitado_empresa, cliente_invitado_cargo,
                proposito_comercial, estado_item, motivo_rechazo, created_at
         FROM rendicion_documentos
         WHERE rendicion_id = :rendicion_id AND activo = :activo
         ORDER BY fecha_emision ASC, id ASC'
    );
    $stmtDocuments->execute([':rendicion_id' => $renditionId, ':activo' => 1]);
    $documents = $stmtDocuments->fetchAll(PDO::FETCH_ASSOC);

    $stmtHistory = $pdo->prepare(
        'SELECT id, documento_id, usuario_id, actor_tipo, actor_nombre,
                actor_email, accion, estado_anterior, estado_nuevo,
                comentario, metadata_json, ip_origen, created_at
         FROM rendicion_historial_estados
         WHERE rendicion_id = :rendicion_id
         ORDER BY created_at ASC, id ASC'
    );
    $stmtHistory->execute([':rendicion_id' => $renditionId]);
    $history = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
    foreach ($documents as &$document) {
        $document['id'] = (int)$document['id'];
        $document['monto'] = (float)$document['monto'];
        $document['monto_validado'] = $document['monto_validado'] !== null ? (float)$document['monto_validado'] : null;
    }
    unset($document);
    foreach ($history as &$entry) {
        $entry['id'] = (int)$entry['id'];
        $entry['documento_id'] = $entry['documento_id'] !== null ? (int)$entry['documento_id'] : null;
        $entry['metadata'] = $entry['metadata_json'] ? json_decode($entry['metadata_json'], true) : null;
        unset($entry['metadata_json']);
    }
    unset($entry);

    RendicionesService::jsonResponse(true, ['data' => [
        'rendicion' => $rendition,
        'documentos' => $documents,
        'historial' => $history,
    ]]);
} catch (InvalidArgumentException $exception) {
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('[admin.rendiciones.get_detalle] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible cargar el detalle.'], 500);
}
