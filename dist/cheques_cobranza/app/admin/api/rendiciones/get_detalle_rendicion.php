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
    // Castear campos de tope y montos en header
    if ($rendition) {
        $rendition['monto_total_rendido']         = (float)($rendition['monto_total_rendido'] ?? 0);
        $rendition['monto_total_aprobado']        = (float)($rendition['monto_total_aprobado'] ?? 0);
        $rendition['monto_exceso']                = (float)($rendition['monto_exceso'] ?? 0);
        $rendition['monto_maximo_aprobable']      = (float)($rendition['monto_maximo_aprobable'] ?? $rendition['monto_total_rendido']);
        $rendition['monto_exceso_no_reembolsable'] = (float)($rendition['monto_exceso_no_reembolsable'] ?? 0);
        $rendition['aplico_tope_presupuestario']  = (bool)($rendition['aplico_tope_presupuestario'] ?? false);
        $rendition['presupuesto_monto_actual']    = (float)($rendition['presupuesto_monto_actual'] ?? 0);
        $rendition['presupuesto_monto_utilizado'] = (float)($rendition['presupuesto_monto_utilizado'] ?? 0);
        $rendition['documentos_fisicos_recibidos'] = (bool)($rendition['documentos_fisicos_recibidos'] ?? false);
    }

    foreach ($documents as &$document) {
        $document['id']           = (int)$document['id'];
        $document['monto']        = (float)$document['monto'];
        $document['monto_validado'] = $document['monto_validado'] !== null ? (float)$document['monto_validado'] : null;
    }
    unset($document);
    foreach ($history as &$entry) {
        $entry['id']           = (int)$entry['id'];
        $entry['documento_id'] = $entry['documento_id'] !== null ? (int)$entry['documento_id'] : null;
        $entry['metadata']     = isset($entry['metadata_json']) && $entry['metadata_json']
            ? json_decode($entry['metadata_json'], true)
            : null;
        unset($entry['metadata_json']);
    }
    unset($entry);

    $stmtException = $pdo->prepare(
        'SELECT id, estado, decision, aprobador_id, aprobador_nombre_snapshot,
                aprobador_cargo_snapshot, aprobador_email_snapshot,
                monto_base_aprobable, monto_solicitado, token_expira_at,
                correo_enviado_at, resuelto_at
         FROM solicitudes_aprobacion
         WHERE id = :id AND tipo_solicitud = :tipo
         LIMIT 1'
    );
    $stmtException->execute([
        ':id' => (int)($rendition['solicitud_excepcion_id'] ?? 0),
        ':tipo' => 'EXCEPCION_MENSUAL',
    ]);
    $genericException = $stmtException->fetch(PDO::FETCH_ASSOC);
    if ($genericException) {
        $solicitudExceso = [
            'tiene_solicitud' => true,
            'id' => (int)$genericException['id'],
            'estado' => $genericException['estado'],
            'decision' => $genericException['decision'],
            'aprobador_id' => $genericException['aprobador_id'] !== null ? (int)$genericException['aprobador_id'] : null,
            'aprobador_nombre' => $genericException['aprobador_nombre_snapshot'],
            'aprobador_cargo' => $genericException['aprobador_cargo_snapshot'],
            'aprobador_email' => $genericException['aprobador_email_snapshot'],
            'monto_base_aprobable' => (float)$genericException['monto_base_aprobable'],
            'monto_solicitado' => (float)$genericException['monto_solicitado'],
            'token_expira' => $genericException['token_expira_at'],
            'solicitud_enviada_at' => $genericException['correo_enviado_at'],
            'resuelto_at' => $genericException['resuelto_at'],
        ];
        if ($genericException['decision'] === 'APROBADA') {
            $rendition['decision_exceso'] = 'APROBADO';
            $rendition['aprobado_exceso_at'] = $genericException['resuelto_at'];
            $rendition['aprobador_solicitado_id'] = $genericException['aprobador_id'];
            $rendition['aprobador_nombre_snapshot'] = $genericException['aprobador_nombre_snapshot'];
            $rendition['aprobador_cargo_snapshot'] = $genericException['aprobador_cargo_snapshot'];
            $rendition['aprobador_email_snapshot'] = $genericException['aprobador_email_snapshot'];
        }
    } elseif ($rendition && !empty($rendition['token_aprobacion_exceso_hash'])) {
        $solicitudExceso = [
            'tiene_solicitud'      => true,
            'aprobador_id'         => $rendition['aprobador_solicitado_id'] ?? null,
            'aprobador_nombre'     => $rendition['aprobador_nombre_snapshot'] ?? null,
            'aprobador_cargo'      => $rendition['aprobador_cargo_snapshot'] ?? null,
            'aprobador_email'      => $rendition['aprobador_email_snapshot'] ?? null,
            'decision'             => $rendition['decision_exceso'] ?? null,
            'token_expira'         => $rendition['token_exceso_expira'] ?? null,
            'token_usado_at'       => $rendition['token_exceso_usado_at'] ?? null,
            'notificacion_estado'  => $rendition['notificacion_exceso_estado'] ?? null,
            'solicitud_enviada_at' => $rendition['solicitud_exceso_enviada_at'] ?? null,
        ];
    } else {
        $solicitudExceso = ['tiene_solicitud' => false];
    }

    $rendition['solicitud_excepcion_estado'] = $solicitudExceso['estado'] ?? null;

    RendicionesService::jsonResponse(true, ['data' => [
        'rendicion'       => $rendition,
        'documentos'      => $documents,
        'historial'       => $history,
        'solicitud_exceso' => $solicitudExceso,
    ]]);
} catch (InvalidArgumentException $exception) {
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('[admin.rendiciones.get_detalle] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible cargar el detalle.'], 500);
}
