<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/AuditService.php';
require_once __DIR__ . '/../../../services/RendicionesService.php';
require_once __DIR__ . '/../../../services/MailService.php';

RendicionesService::requireMethod('POST');

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $admin = requirePermission($pdo, 'rendiciones.manage');
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();
    $renditionId = filter_var($input['rendicion_id'] ?? null, FILTER_VALIDATE_INT);
    $action = strtoupper(trim((string)($input['accion'] ?? '')));
    $comment = trim((string)($input['comentario'] ?? ''));
    $allowedActions = ['RECIBIR_FISICOS', 'APROBAR_TOTAL', 'APROBAR_PARCIAL', 'RECHAZAR', 'RECHAZAR_EXCESO_TESORERIA', 'MARCAR_PAGADA', 'REENVIAR_EXCESO'];
    if (!$renditionId || !in_array($action, $allowedActions, true)) {
        throw new InvalidArgumentException('Rendición o acción no válida.');
    }

    $pdo->beginTransaction();
    $stmtRendition = $pdo->prepare(
        'SELECT * FROM rendiciones_gastos
         WHERE id = :id AND activo = :activo
         LIMIT 1
         FOR UPDATE'
    );
    $stmtRendition->execute([':id' => $renditionId, ':activo' => 1]);
    $rendition = $stmtRendition->fetch(PDO::FETCH_ASSOC);
    if (!$rendition) {
        throw new DomainException('Rendición no encontrada.');
    }

    if ($action === 'REENVIAR_EXCESO') {
        if ($rendition['estado'] !== 'PENDIENTE_APROBACION_EXCESO') {
            throw new DomainException('Sólo se puede reenviar una rendición pendiente de aprobación de exceso.');
        }
        $approverId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$approverId) {
            throw new InvalidArgumentException('Seleccione el responsable que recibirá la solicitud.');
        }
        $stmtApprover = $pdo->prepare(
            'SELECT id, orden, nombre, cargo, email
             FROM aprobadores_rendiciones
             WHERE id = :id AND activo = :activo
             LIMIT 1
             FOR UPDATE'
        );
        $stmtApprover->execute([':id' => $approverId, ':activo' => 1]);
        $approver = $stmtApprover->fetch(PDO::FETCH_ASSOC);
        if (!$approver || !filter_var($approver['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El responsable seleccionado ya no está disponible. Actualice la configuración.');
        }
        $rawToken = bin2hex(random_bytes(32));
        $stmtRotate = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET token_aprobacion_exceso_hash = :token_hash,
                 token_exceso_expira = :token_expira,
                 token_exceso_usado_at = NULL,
                 decision_exceso = NULL,
                 notificacion_exceso_estado = :notificacion_estado,
                 aprobador_solicitado_id = :aprobador_id,
                 aprobador_nombre_snapshot = :aprobador_nombre,
                 aprobador_cargo_snapshot = :aprobador_cargo,
                 aprobador_email_snapshot = :aprobador_email,
                 solicitud_exceso_enviada_at = NULL,
                 solicitud_exceso_enviada_por = :enviada_por
             WHERE id = :id AND estado = :estado'
        );
        $stmtRotate->execute([
            ':token_hash' => hash('sha256', $rawToken),
            ':token_expira' => date('Y-m-d H:i:s', time() + (RENDICIONES_TOKEN_TTL_HOURS * 3600)),
            ':notificacion_estado' => 'PENDIENTE',
            ':aprobador_id' => (int)$approver['id'],
            ':aprobador_nombre' => $approver['nombre'],
            ':aprobador_cargo' => $approver['cargo'],
            ':aprobador_email' => $approver['email'],
            ':enviada_por' => (int)$admin['id'],
            ':id' => $renditionId,
            ':estado' => 'PENDIENTE_APROBACION_EXCESO',
        ]);
        $stmtMailDocuments = $pdo->prepare(
            'SELECT id, monto, tipo_documento, categoria_gasto, fecha_emision,
                    razon_social_proveedor, rut_proveedor, numero_documento,
                    descripcion, foto_documento_url, cliente_invitado_nombre,
                    cliente_invitado_rut, cliente_invitado_empresa,
                    cliente_invitado_cargo, proposito_comercial
             FROM rendicion_documentos
             WHERE rendicion_id = :rendicion_id AND activo = :activo
             ORDER BY fecha_emision ASC, id ASC'
        );
        $stmtMailDocuments->execute([':rendicion_id' => $renditionId, ':activo' => 1]);
        $mailDocuments = $stmtMailDocuments->fetchAll(PDO::FETCH_ASSOC);
        $stmtContext = $pdo->prepare(
            'SELECT e.nombre AS empresa_nombre, p.nombre_gira
             FROM empresas e
             INNER JOIN presupuestos_vendedores p ON p.id = :presupuesto_id
             WHERE e.id = :empresa_id
             LIMIT 1'
        );
        $stmtContext->execute([
            ':presupuesto_id' => (int)$rendition['presupuesto_id'],
            ':empresa_id' => (int)$rendition['empresa_id'],
        ]);
        $renditionContext = $stmtContext->fetch(PDO::FETCH_ASSOC) ?: [];
        $companyName = (string)($renditionContext['empresa_nombre'] ?? 'Empresa no identificada');
        RendicionesService::logHistory($pdo, [
            'rendicion_id' => $renditionId,
            'usuario_id' => (int)$admin['id'],
            'actor_tipo' => 'TESORERIA',
            'actor_nombre' => $admin['nombre'],
            'actor_email' => $admin['email'],
            'accion' => 'ENVIAR_SOLICITUD_EXCESO',
            'estado_anterior' => $rendition['estado'],
            'estado_nuevo' => $rendition['estado'],
            'comentario' => $comment !== '' ? mb_substr($comment, 0, 1000) : null,
            'metadata' => [
                'aprobador_id' => (int)$approver['id'],
                'aprobador_nombre' => $approver['nombre'],
                'aprobador_cargo' => $approver['cargo'],
                'aprobador_email' => $approver['email'],
            ],
        ]);
        AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_ENVIAR_EXCESO', json_encode([
            'rendicion_id' => $renditionId,
            'aprobador_id' => (int)$approver['id'],
            'aprobador_email' => $approver['email'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $pdo->commit();

        $mailSent = false;
        try {
            $mailSent = MailService::enviarSolicitudExcesoRendicion([
                'id' => $renditionId,
                'codigo_rendicion' => $rendition['codigo_rendicion'],
                'empresa_nombre' => $companyName,
                'vendedor_id' => (int)$rendition['vendedor_id'],
                'vendedor_nombre' => $rendition['vendedor_nombre'],
                'nota_vendedor' => $rendition['nota_vendedor'],
                'periodo_mes' => $rendition['periodo_mes'],
                'tipo_rendicion' => $rendition['tipo_rendicion'],
                'nombre_gira' => $rendition['tipo_rendicion'] === 'GIRA' ? ($renditionContext['nombre_gira'] ?? null) : null,
                'monto_total_rendido' => (float)$rendition['monto_total_rendido'],
                'monto_presupuesto_asignado' => (float)$rendition['monto_presupuesto_asignado'],
                'monto_exceso' => (float)$rendition['monto_exceso'],
                'saldo_disponible_al_enviar' => (float)$rendition['saldo_disponible_al_enviar'],
            ], $mailDocuments, $rawToken, $approver, $comment);
            $stmtMailStatus = $pdo->prepare(
                'UPDATE rendiciones_gastos
                 SET notificacion_exceso_estado = :notificacion_estado,
                     solicitud_exceso_enviada_at = CASE WHEN :correo_enviado = :verdadero THEN NOW() ELSE NULL END
                 WHERE id = :id AND estado = :estado'
            );
            $stmtMailStatus->execute([
                ':notificacion_estado' => $mailSent ? 'ENVIADA' : 'FALLIDA',
                ':correo_enviado' => $mailSent ? 1 : 0,
                ':verdadero' => 1,
                ':id' => $renditionId,
                ':estado' => 'PENDIENTE_APROBACION_EXCESO',
            ]);
        } catch (Throwable $mailException) {
            error_log('[admin.rendiciones.reenviar_exceso.mail] ' . $mailException->getMessage());
        }
        RendicionesService::jsonResponse(true, [
            'message' => $mailSent ? 'Solicitud de aprobación enviada correctamente.' : 'La solicitud quedó preparada, pero el correo no pudo enviarse.',
            'data' => ['rendicion_id' => $renditionId, 'correo_enviado' => $mailSent, 'aprobador_id' => (int)$approver['id']],
        ]);
    }

    $currentState = $rendition['estado'];
    $nextState = '';
    if ($action === 'RECIBIR_FISICOS') $nextState = 'DOCUMENTOS_FISICOS_RECIBIDOS';
    if ($action === 'APROBAR_TOTAL') $nextState = 'APROBADA';
    if ($action === 'APROBAR_PARCIAL') $nextState = 'APROBADA_PARCIAL';
    if ($action === 'RECHAZAR') $nextState = 'RECHAZADA';
    if ($action === 'RECHAZAR_EXCESO_TESORERIA') $nextState = 'RECHAZADA';
    if ($action === 'MARCAR_PAGADA') $nextState = 'PAGADA';
    RendicionesService::assertTransition($currentState, $nextState);
    if (in_array($action, ['RECHAZAR', 'RECHAZAR_EXCESO_TESORERIA'], true) && $comment === '') {
        throw new InvalidArgumentException('Debe indicar el motivo del rechazo.');
    }
    if ($action === 'RECHAZAR_EXCESO_TESORERIA' && $currentState !== 'PENDIENTE_APROBACION_EXCESO') {
        throw new DomainException('Tesorería sólo puede cancelar por esta vía una rendición con exceso pendiente.');
    }

    $stmtDocuments = $pdo->prepare(
        'SELECT id, monto, monto_validado, estado_item, fecha_emision
         FROM rendicion_documentos
         WHERE rendicion_id = :rendicion_id AND activo = :activo
         ORDER BY fecha_emision ASC, id ASC
         FOR UPDATE'
    );
    $stmtDocuments->execute([':rendicion_id' => $renditionId, ':activo' => 1]);
    $documents = $stmtDocuments->fetchAll(PDO::FETCH_ASSOC);
    if (!$documents) {
        throw new DomainException('La rendición no contiene documentos activos.');
    }

    // Snapshot del tope presupuestario registrado al momento del envío
    $aplicoTope = (bool)($rendition['aplico_tope_presupuestario'] ?? false);
    $capAmount   = $aplicoTope
        ? (float)($rendition['monto_maximo_aprobable'] ?? $rendition['monto_total_rendido'])
        : PHP_INT_MAX;

    $approvedTotal = (float)$rendition['monto_total_aprobado'];
    if ($action === 'APROBAR_TOTAL') {
        // Liquidación FIFO: distribuir cap sobre documentos ordenados por fecha_emision ASC
        $approvedTotal = 0.0;
        $remaining     = $capAmount;
        $stmtApprove = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET estado_item = :estado_nuevo, monto_validado = :monto_validado, motivo_rechazo = NULL
             WHERE id = :id AND rendicion_id = :rendicion_id AND estado_item = :estado_actual'
        );
        foreach ($documents as $document) {
            if ($document['estado_item'] !== 'PENDIENTE') {
                throw new DomainException('Todos los documentos deben estar pendientes antes de la aprobación total.');
            }
            // FIFO: cada comprobante obtiene lo que queda del cap (puede ser parcial o cero)
            $validatedAmount = min((float)$document['monto'], max(0.0, $remaining));
            $remaining      -= $validatedAmount;
            $approvedTotal  += $validatedAmount;
            $stmtApprove->execute([
                ':estado_nuevo'   => 'APROBADO',
                ':monto_validado' => number_format($validatedAmount, 2, '.', ''),
                ':id'             => (int)$document['id'],
                ':rendicion_id'   => $renditionId,
                ':estado_actual'  => 'PENDIENTE',
            ]);
            RendicionesService::logHistory($pdo, [
                'rendicion_id'  => $renditionId,
                'documento_id'  => (int)$document['id'],
                'usuario_id'    => (int)$admin['id'],
                'actor_tipo'    => 'TESORERIA',
                'actor_nombre'  => $admin['nombre'],
                'actor_email'   => $admin['email'],
                'accion'        => 'APROBAR_DOCUMENTO',
                'estado_anterior' => 'PENDIENTE',
                'estado_nuevo'  => 'APROBADO',
                'metadata'      => [
                    'monto_validado'    => $validatedAmount,
                    'aplico_fifo_tope'  => $aplicoTope && $validatedAmount < (float)$document['monto'],
                ],
            ]);
        }
    }

    if ($action === 'APROBAR_PARCIAL') {
        $decisions = $input['decisiones'] ?? null;
        if (!is_array($decisions) || count($decisions) !== count($documents)) {
            throw new InvalidArgumentException('Debe resolver todos los documentos de la rendición.');
        }
        $documentMap = [];
        foreach ($documents as $document) {
            if ($document['estado_item'] !== 'PENDIENTE') {
                throw new DomainException('Todos los documentos deben estar pendientes antes de la revisión parcial.');
            }
            $documentMap[(int)$document['id']] = $document;
        }
        // Primera pasada: recoger decisiones y construir mapa de montos aprobados por Tesorería
        $processed       = [];
        $rejectedCount   = 0;
        $decisionAmounts = []; // doc_id => [itemDecision, validatedAmount, itemReason]
        foreach ($decisions as $decisionData) {
            $documentId   = filter_var($decisionData['documento_id'] ?? null, FILTER_VALIDATE_INT);
            $itemDecision = strtoupper(trim((string)($decisionData['decision'] ?? '')));
            if (!$documentId || isset($processed[$documentId]) || !isset($documentMap[$documentId]) || !in_array($itemDecision, ['APROBAR', 'RECHAZAR'], true)) {
                throw new InvalidArgumentException('La resolución de documentos contiene datos inválidos o duplicados.');
            }
            $processed[$documentId] = true;
            $document   = $documentMap[$documentId];
            $itemReason = trim((string)($decisionData['motivo'] ?? ''));
            if ($itemDecision === 'RECHAZAR' && $itemReason === '') {
                throw new InvalidArgumentException('Cada documento rechazado requiere un motivo.');
            }
            $validatedAmount = null;
            if ($itemDecision === 'APROBAR') {
                $validatedAmount = isset($decisionData['monto_validado'])
                    ? (float)RendicionesService::normalizeMoney($decisionData['monto_validado'])
                    : (float)$document['monto'];
                if ($validatedAmount > (float)$document['monto']) {
                    throw new InvalidArgumentException('El monto validado no puede superar el monto rendido sin una nueva aprobación de exceso.');
                }
            } else {
                $rejectedCount++;
            }
            $decisionAmounts[$documentId] = [$itemDecision, $validatedAmount, $itemReason];
        }
        if ($rejectedCount < 1) {
            throw new InvalidArgumentException('Use aprobación total cuando ningún documento sea rechazado.');
        }

        // Segunda pasada: aplicar FIFO cap (misma ordenación fecha_emision ASC del $documents)
        $remaining     = $capAmount;
        $approvedTotal = 0.0;
        $stmtDecision  = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET estado_item = :estado_nuevo, monto_validado = :monto_validado,
                 motivo_rechazo = :motivo_rechazo
             WHERE id = :id AND rendicion_id = :rendicion_id AND estado_item = :estado_actual'
        );
        foreach ($documents as $document) {
            $documentId = (int)$document['id'];
            [$itemDecision, $validatedAmount, $itemReason] = $decisionAmounts[$documentId];
            $itemState = 'RECHAZADO';
            if ($itemDecision === 'APROBAR') {
                // Aplicar FIFO sobre el monto aprobado por Tesorería
                $validatedAmount = min($validatedAmount, max(0.0, $remaining));
                $remaining      -= $validatedAmount;
                $approvedTotal  += $validatedAmount;
                $itemState       = 'APROBADO';
            }
            $stmtDecision->execute([
                ':estado_nuevo'   => $itemState,
                ':monto_validado' => $validatedAmount !== null ? number_format($validatedAmount, 2, '.', '') : null,
                ':motivo_rechazo' => $itemReason !== '' ? mb_substr($itemReason, 0, 500) : null,
                ':id'             => $documentId,
                ':rendicion_id'   => $renditionId,
                ':estado_actual'  => 'PENDIENTE',
            ]);
            RendicionesService::logHistory($pdo, [
                'rendicion_id'    => $renditionId,
                'documento_id'    => $documentId,
                'usuario_id'      => (int)$admin['id'],
                'actor_tipo'      => 'TESORERIA',
                'actor_nombre'    => $admin['nombre'],
                'actor_email'     => $admin['email'],
                'accion'          => $itemDecision === 'APROBAR' ? 'APROBAR_DOCUMENTO' : 'RECHAZAR_DOCUMENTO',
                'estado_anterior' => 'PENDIENTE',
                'estado_nuevo'    => $itemState,
                'comentario'      => $itemReason !== '' ? mb_substr($itemReason, 0, 1000) : null,
                'metadata'        => [
                    'monto_validado'   => $validatedAmount,
                    'aplico_fifo_tope' => $aplicoTope && $itemDecision === 'APROBAR' && $validatedAmount < ($decisionAmounts[$documentId][1] ?? 0),
                ],
            ]);
        }
    }

    if (in_array($action, ['RECHAZAR', 'RECHAZAR_EXCESO_TESORERIA'], true)) {
        $approvedTotal = 0.0;
        $stmtReject = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET estado_item = :estado_nuevo, monto_validado = NULL, motivo_rechazo = :motivo
             WHERE rendicion_id = :rendicion_id AND estado_item = :estado_actual'
        );
        $stmtReject->execute([
            ':estado_nuevo' => 'RECHAZADO',
            ':motivo' => mb_substr($comment, 0, 500),
            ':rendicion_id' => $renditionId,
            ':estado_actual' => 'PENDIENTE',
        ]);
    }

    if ($action === 'RECHAZAR_EXCESO_TESORERIA') {
        $stmtCancelToken = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET token_exceso_usado_at = CASE
                     WHEN token_aprobacion_exceso_hash IS NOT NULL THEN COALESCE(token_exceso_usado_at, NOW())
                     ELSE token_exceso_usado_at
                 END,
                 token_exceso_expira = NULL,
                 notificacion_exceso_estado = :notificacion_estado
             WHERE id = :id AND estado = :estado'
        );
        $stmtCancelToken->execute([
            ':notificacion_estado' => 'NO_APLICA',
            ':id' => $renditionId,
            ':estado' => 'PENDIENTE_APROBACION_EXCESO',
        ]);
    }

    if (in_array($action, ['APROBAR_TOTAL', 'APROBAR_PARCIAL', 'RECHAZAR', 'RECHAZAR_EXCESO_TESORERIA'], true)) {
        // La base reservada es monto_maximo_aprobable (cuando aplico tope) o monto_total_rendido
        $reservedBase   = $aplicoTope
            ? (float)($rendition['monto_maximo_aprobable'] ?? $rendition['monto_total_rendido'])
            : (float)$rendition['monto_total_rendido'];
        $releasedAmount = max(0.0, $reservedBase - $approvedTotal);
        if ($releasedAmount > 0) {
            $stmtBudget = $pdo->prepare(
                'UPDATE presupuestos_vendedores
                 SET monto_utilizado = GREATEST(0, monto_utilizado - :monto)
                 WHERE id = :id'
            );
            $stmtBudget->execute([':monto' => number_format($releasedAmount, 2, '.', ''), ':id' => (int)$rendition['presupuesto_id']]);
        }
    }

    $stmtUpdateRendition = $pdo->prepare(
        'UPDATE rendiciones_gastos
         SET estado = :estado,
             monto_total_aprobado = :monto_aprobado,
             documentos_fisicos_recibidos = :documentos_recibidos,
             fecha_recepcion_fisica = :fecha_recepcion,
             recibido_fisico_por = :recibido_por,
             motivo_rechazo = :motivo_rechazo
         WHERE id = :id AND estado = :estado_actual'
    );
    $received = $action === 'RECIBIR_FISICOS' ? 1 : (int)$rendition['documentos_fisicos_recibidos'];
    $receivedAt = $action === 'RECIBIR_FISICOS' ? date('Y-m-d H:i:s') : $rendition['fecha_recepcion_fisica'];
    $receivedBy = $action === 'RECIBIR_FISICOS' ? (int)$admin['id'] : $rendition['recibido_fisico_por'];
    $stmtUpdateRendition->execute([
        ':estado' => $nextState,
        ':monto_aprobado' => number_format($approvedTotal, 2, '.', ''),
        ':documentos_recibidos' => $received,
        ':fecha_recepcion' => $receivedAt,
        ':recibido_por' => $receivedBy,
        ':motivo_rechazo' => in_array($action, ['RECHAZAR', 'RECHAZAR_EXCESO_TESORERIA'], true) ? mb_substr($comment, 0, 500) : $rendition['motivo_rechazo'],
        ':id' => $renditionId,
        ':estado_actual' => $currentState,
    ]);
    if ($stmtUpdateRendition->rowCount() !== 1) {
        throw new RuntimeException('No fue posible registrar la transición.');
    }

    RendicionesService::logHistory($pdo, [
        'rendicion_id' => $renditionId,
        'usuario_id' => (int)$admin['id'],
        'actor_tipo' => 'TESORERIA',
        'actor_nombre' => $admin['nombre'],
        'actor_email' => $admin['email'],
        'accion' => $action,
        'estado_anterior' => $currentState,
        'estado_nuevo' => $nextState,
        'comentario' => $comment !== '' ? mb_substr($comment, 0, 1000) : null,
        'metadata' => ['monto_aprobado' => $approvedTotal],
    ]);
    AuditService::log(
        $pdo,
        (int)$admin['id'],
        $admin['email'],
        'RENDICION_' . $action,
        json_encode(['rendicion_id' => $renditionId, 'estado_anterior' => $currentState, 'estado_nuevo' => $nextState])
    );
    $pdo->commit();

    RendicionesService::jsonResponse(true, [
        'message' => 'Estado de la rendición actualizado correctamente.',
        'data' => ['rendicion_id' => $renditionId, 'estado' => $nextState, 'monto_aprobado' => $approvedTotal],
    ]);
} catch (InvalidArgumentException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin.rendiciones.cambiar_estado] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible actualizar la rendición.'], 500);
}
