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
require_once __DIR__ . '/../../../services/ApprovalWorkflowService.php';

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
    $allowedActions = [
        'VALIDAR_DOCUMENTOS',
        'VERIFICAR_Y_ENVIAR',
        'CANCELAR_SOLICITUD_RESPONSABLE',
        'REENVIAR_RESPONSABLE',
        'RECHAZAR',
        'RECHAZAR_EXCESO_TESORERIA',
        'MARCAR_PAGADA',
        'SOLICITAR_EXCEPCION',
        'REENVIAR_EXCESO',
        'APROBAR_TOTAL',
        'APROBAR_PARCIAL',
        'RECIBIR_FISICOS',
    ];
    if (!$renditionId || !in_array($action, $allowedActions, true)) {
        throw new InvalidArgumentException('Rendición o acción no válida.');
    }

    if (in_array($action, ['APROBAR_TOTAL', 'APROBAR_PARCIAL'], true)) {
        throw new DomainException('Tesorería no puede aprobar rendiciones directamente. Toda rendición debe ser verificada y enviada a aprobación de un responsable.');
    }
    if ($action === 'RECIBIR_FISICOS') {
        throw new DomainException('La recepción física ya no es una acción disponible. Toda rendición debe ser verificada y enviada a aprobación del responsable.');
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

    if ($action === 'VALIDAR_DOCUMENTOS') {
        $decisions = $input['decisiones'] ?? null;
        $result = RendicionesService::validarDocumentos($pdo, $renditionId, is_array($decisions) ? $decisions : [], $admin, $comment);
        $pdo->commit();

        RendicionesService::jsonResponse(true, [
            'message' => 'Validación de comprobantes guardada exitosamente.',
            'data'    => $result,
        ]);
    }

    if ($action === 'VERIFICAR_Y_ENVIAR') {
        $approverId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT);
        $result = RendicionesService::verificarYEnviar($pdo, $renditionId, (int)$approverId, $admin, $comment, $input);
        $pdo->commit();

        RendicionesService::jsonResponse(true, [
            'message' => $result['correo_enviado'] ? 'Rendición verificada y enviada a aprobación del Responsable.' : 'Rendición verificada, pero el correo falló. Puede reintentar el reenvío.',
            'data'    => [
                'rendicion_id'    => $renditionId,
                'solicitud_id'    => $result['solicitud_id'],
                'correo_enviado'  => $result['correo_enviado'],
                'aprobador_nombre'=> $result['aprobador_nombre'],
                'estado'          => $result['estado'],
            ],
        ]);
    }

    if ($action === 'CANCELAR_SOLICITUD_RESPONSABLE') {
        if ($rendition['estado'] !== 'PENDIENTE_APROBACION_RESPONSABLE') {
            throw new DomainException('Solo se pueden cancelar solicitudes en espera de aprobación del responsable.');
        }
        $requestId = (int)($rendition['solicitud_excepcion_id'] ?? 0);
        if ($requestId > 0) {
            ApprovalWorkflowService::cancelRequest($pdo, $requestId, [
                'id' => (int)$admin['id'], 'nombre' => $admin['nombre'], 'email' => $admin['email'],
            ], $comment !== '' ? $comment : 'Solicitud cancelada por Tesorería para nueva revisión');
        }
        $stmtReset = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET estado = "EN_REVISION_TESORERIA",
                 notificacion_exceso_estado = "NO_APLICA"
             WHERE id = :id'
        );
        $stmtReset->execute([':id' => $renditionId]);

        RendicionesService::logHistory($pdo, [
            'rendicion_id' => $renditionId,
            'usuario_id' => (int)$admin['id'],
            'actor_tipo' => 'TESORERIA',
            'actor_nombre' => $admin['nombre'],
            'actor_email' => $admin['email'],
            'accion' => 'CANCELAR_SOLICITUD_RESPONSABLE',
            'estado_anterior' => 'PENDIENTE_APROBACION_RESPONSABLE',
            'estado_nuevo' => 'EN_REVISION_TESORERIA',
            'comentario' => $comment,
        ]);
        $pdo->commit();

        RendicionesService::jsonResponse(true, [
            'message' => 'Solicitud al responsable cancelada. La rendición volvió a revisión de Tesorería.',
            'data' => ['rendicion_id' => $renditionId, 'estado' => 'EN_REVISION_TESORERIA'],
        ]);
    }

    if (in_array($action, ['REENVIAR_RESPONSABLE', 'REENVIAR_EXCESO'], true) && $rendition['estado'] === 'PENDIENTE_APROBACION_RESPONSABLE') {
        $approverId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT) ?: (int)$rendition['aprobador_solicitado_id'];
        if (!$approverId) {
            throw new InvalidArgumentException('Seleccione el responsable para el reenvío.');
        }
        $requestId = (int)($rendition['solicitud_excepcion_id'] ?? 0);
        if ($requestId <= 0) {
            throw new DomainException('No se encontró la solicitud de aprobación vinculada.');
        }

        $workflow = ApprovalWorkflowService::rotateToken($pdo, $requestId, $approverId, [
            'id' => (int)$admin['id'], 'nombre' => $admin['nombre'], 'email' => $admin['email'],
        ]);
        $request = $workflow['solicitud'];

        $stmtMailDocs = $pdo->prepare(
            'SELECT * FROM rendicion_documentos
             WHERE rendicion_id = :rendicion_id AND activo = 1 AND estado_item != "RECHAZADO"
             ORDER BY fecha_emision ASC, id ASC'
        );
        $stmtMailDocs->execute([':rendicion_id' => $renditionId]);
        $mailDocuments = $stmtMailDocs->fetchAll(PDO::FETCH_ASSOC);

        $stmtContext = $pdo->prepare(
            'SELECT e.nombre AS empresa_nombre, p.nombre_gira
             FROM empresas e
             INNER JOIN presupuestos_vendedores p ON p.id = :presupuesto_id
             WHERE e.id = :empresa_id LIMIT 1'
        );
        $stmtContext->execute([':presupuesto_id' => (int)$rendition['presupuesto_id'], ':empresa_id' => (int)$rendition['empresa_id']]);
        $context = $stmtContext->fetch(PDO::FETCH_ASSOC) ?: [];

        RendicionesService::logHistory($pdo, [
            'rendicion_id' => $renditionId,
            'usuario_id' => (int)$admin['id'],
            'actor_tipo' => 'TESORERIA',
            'actor_nombre' => $admin['nombre'],
            'actor_email' => $admin['email'],
            'accion' => 'REENVIAR_SOLICITUD_RESPONSABLE',
            'estado_anterior' => 'PENDIENTE_APROBACION_RESPONSABLE',
            'estado_nuevo' => 'PENDIENTE_APROBACION_RESPONSABLE',
            'comentario' => $comment !== '' ? $comment : 'Token rotado y correo reenviado al responsable.',
        ]);
        $pdo->commit();

        $approver = [
            'id' => (int)$request['aprobador_id'],
            'nombre' => $request['aprobador_nombre_snapshot'],
            'cargo' => $request['aprobador_cargo_snapshot'],
            'email' => $request['aprobador_email_snapshot'],
        ];

        $mailSent = false;
        try {
            $mailSent = MailService::enviarSolicitudAprobacionRendicion(
                array_merge($rendition, $context),
                $mailDocuments,
                $workflow['raw_token'],
                $approver,
                $comment
            );
        } catch (Throwable $mailEx) {
            error_log('[admin.rendiciones.reenviar_resp.mail] ' . $mailEx->getMessage());
        }

        $pdo->beginTransaction();
        ApprovalWorkflowService::markEmailResult($pdo, (int)$request['id'], $mailSent, $mailSent ? null : 'El servidor SMTP no confirmó la entrega del correo.');
        $pdo->commit();

        RendicionesService::jsonResponse(true, [
            'message' => $mailSent ? 'Solicitud reenviada al responsable correctamente.' : 'Token actualizado, pero el correo falló.',
            'data' => [
                'rendicion_id' => $renditionId,
                'correo_enviado' => $mailSent,
                'aprobador_nombre' => $request['aprobador_nombre_snapshot'],
            ],
        ]);
    }

    if (in_array($action, ['SOLICITAR_EXCEPCION', 'REENVIAR_EXCESO'], true) && $rendition['estado'] === 'EN_REVISION_TESORERIA') {
        if ((float)($rendition['monto_exceso_no_reembolsable'] ?? 0) <= 0) {
            throw new DomainException('Esta rendición no tiene un exceso pendiente que pueda solicitarse.');
        }
        $approverId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT);
        $justification = RendicionesService::truncateText(trim((string)($input['comentario'] ?? '')), 500);
        if (!$approverId || $justification === '') {
            throw new InvalidArgumentException('Seleccione un responsable e indique la justificación de la excepción.');
        }

        $workflow = null;
        $requestId = (int)($rendition['solicitud_excepcion_id'] ?? 0);
        if ($requestId > 0) {
            $stmtRequest = $pdo->prepare('SELECT id, estado, activo FROM solicitudes_aprobacion WHERE id = :id AND tipo_solicitud = :tipo LIMIT 1 FOR UPDATE');
            $stmtRequest->execute([':id' => $requestId, ':tipo' => ApprovalWorkflowService::TYPE_MONTHLY_EXCEPTION]);
            $currentRequest = $stmtRequest->fetch(PDO::FETCH_ASSOC);
            if ($currentRequest && (bool)$currentRequest['activo'] && in_array($currentRequest['estado'], ['PENDIENTE_ENVIO', 'PENDIENTE_DECISION', 'ENVIO_FALLIDO', 'VENCIDA'], true)) {
                $workflow = ApprovalWorkflowService::rotateToken($pdo, $requestId, (int)$approverId, [
                    'id' => (int)$admin['id'], 'nombre' => $admin['nombre'], 'email' => $admin['email'],
                ]);
            }
        }
        if ($workflow === null) {
            $workflow = ApprovalWorkflowService::createRequest($pdo, [
                'tipo_solicitud' => ApprovalWorkflowService::TYPE_MONTHLY_EXCEPTION,
                'rendicion_id' => $renditionId, 'aprobador_id' => $approverId,
                'solicitado_por' => (int)$admin['id'],
                'monto_solicitado' => (float)$rendition['monto_exceso_no_reembolsable'],
                'justificacion' => $justification,
                'actor_nombre' => $admin['nombre'], 'actor_email' => $admin['email'],
            ]);
        }
        $request = $workflow['solicitud'];
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
             WHERE e.id = :empresa_id LIMIT 1'
        );
        $stmtContext->execute([':presupuesto_id' => (int)$rendition['presupuesto_id'], ':empresa_id' => (int)$rendition['empresa_id']]);
        $context = $stmtContext->fetch(PDO::FETCH_ASSOC) ?: [];
        RendicionesService::logHistory($pdo, [
            'rendicion_id' => $renditionId, 'usuario_id' => (int)$admin['id'],
            'actor_tipo' => 'TESORERIA', 'actor_nombre' => $admin['nombre'], 'actor_email' => $admin['email'],
            'accion' => 'SOLICITAR_EXCEPCION_MENSUAL', 'estado_anterior' => $rendition['estado'],
            'estado_nuevo' => $rendition['estado'], 'comentario' => $justification,
            'metadata' => ['solicitud_id' => (int)$request['id'], 'monto_solicitado' => (float)$request['monto_solicitado']],
        ]);
        $pdo->commit();

        $approver = [
            'id' => (int)$request['aprobador_id'], 'nombre' => $request['aprobador_nombre_snapshot'],
            'cargo' => $request['aprobador_cargo_snapshot'], 'email' => $request['aprobador_email_snapshot'],
        ];
        $mailSent = false;
        try {
            $mailSent = MailService::enviarSolicitudExcesoRendicion(array_merge($rendition, $context, [
                'monto_exceso' => (float)$request['monto_solicitado'],
            ]), $mailDocuments, $workflow['raw_token'], $approver, $justification);
        } catch (Throwable $mailException) {
            error_log('[admin.rendiciones.excepcion.mail] ' . $mailException->getMessage());
        }
        $pdo->beginTransaction();
        ApprovalWorkflowService::markEmailResult($pdo, (int)$request['id'], $mailSent, $mailSent ? null : 'El servidor SMTP no confirmó la entrega del correo.');
        $pdo->commit();
        RendicionesService::jsonResponse(true, [
            'message' => $mailSent ? 'Solicitud excepcional enviada correctamente.' : 'La excepción quedó pendiente, pero el correo falló. Puede reenviarla.',
            'data' => ['rendicion_id' => $renditionId, 'solicitud_id' => (int)$request['id'], 'correo_enviado' => $mailSent],
        ]);
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
            'comentario' => $comment !== '' ? RendicionesService::truncateText($comment, 1000) : null,
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

    if (in_array($action, ['RECHAZAR', 'RECHAZAR_EXCESO_TESORERIA'], true)) {
        if ($comment === '') {
            throw new InvalidArgumentException('Debe indicar el motivo del rechazo.');
        }
        if ($action === 'RECHAZAR_EXCESO_TESORERIA' && $rendition['estado'] !== 'PENDIENTE_APROBACION_EXCESO') {
            throw new DomainException('Tesorería sólo puede cancelar por esta vía una rendición con exceso pendiente.');
        }

        // 1. Bloquear y liberar presupuesto: siempre exactamente monto_maximo_aprobable
        $budgetId = (int)$rendition['presupuesto_id'];
        $stmtBudgetLock = $pdo->prepare(
            'SELECT id, monto_asignado, monto_utilizado
             FROM presupuestos_vendedores
             WHERE id = :id AND activo = 1
             LIMIT 1 FOR UPDATE'
        );
        $stmtBudgetLock->execute([':id' => $budgetId]);
        $budget = $stmtBudgetLock->fetch(PDO::FETCH_ASSOC);
        if (!$budget) {
            throw new DomainException('Presupuesto vinculado no encontrado.');
        }

        $reservedAmount = (float)$rendition['monto_maximo_aprobable'];
        if ($reservedAmount > 0) {
            $stmtBudget = $pdo->prepare(
                'UPDATE presupuestos_vendedores
                 SET monto_utilizado = GREATEST(0, monto_utilizado - :monto)
                 WHERE id = :id'
            );
            $stmtBudget->execute([
                ':monto' => number_format($reservedAmount, 2, '.', ''),
                ':id'    => $budgetId,
            ]);
        }

        // 2. Rechazar todos los comprobantes activos
        $stmtReject = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET estado_item = "RECHAZADO",
                 monto_validado = "0.00",
                 motivo_rechazo = :motivo
             WHERE rendicion_id = :rendicion_id AND activo = 1'
        );
        $stmtReject->execute([
            ':motivo'       => RendicionesService::truncateText($comment, 500),
            ':rendicion_id' => $renditionId,
        ]);

        // 3. Cancelar solamente la solicitud vigente vinculada mediante solicitud_excepcion_id perteneciente a esta rendición
        $solicitudId = (int)($rendition['solicitud_excepcion_id'] ?? 0);
        if ($solicitudId > 0) {
            $stmtReq = $pdo->prepare(
                'SELECT id, estado, activo, rendicion_id
                 FROM solicitudes_aprobacion
                 WHERE id = :id AND rendicion_id = :rendicion_id AND activo = 1
                 LIMIT 1
                 FOR UPDATE'
            );
            $stmtReq->execute([':id' => $solicitudId, ':rendicion_id' => $renditionId]);
            $openReq = $stmtReq->fetch(PDO::FETCH_ASSOC);
            if ($openReq && in_array($openReq['estado'], ['PENDIENTE_ENVIO', 'PENDIENTE_DECISION', 'ENVIO_FALLIDO', 'VENCIDA'], true)) {
                ApprovalWorkflowService::cancelRequest($pdo, $solicitudId, [
                    'id'     => (int)$admin['id'],
                    'nombre' => $admin['nombre'],
                    'email'  => $admin['email'],
                ], 'Rendición rechazada directamente por Tesorería: ' . RendicionesService::truncateText($comment, 400), false);
            }
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
                 WHERE id = :id'
            );
            $stmtCancelToken->execute([
                ':notificacion_estado' => 'NO_APLICA',
                ':id'                  => $renditionId,
            ]);
        }

        // 4. Actualizar estado de la rendición a RECHAZADA
        $stmtUpdateRendition = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET estado = "RECHAZADA",
                 motivo_rechazo = :motivo_rechazo
             WHERE id = :id AND estado = :estado_actual'
        );
        $stmtUpdateRendition->execute([
            ':motivo_rechazo' => RendicionesService::truncateText($comment, 500),
            ':id'             => $renditionId,
            ':estado_actual'  => $rendition['estado'],
        ]);

        RendicionesService::logHistory($pdo, [
            'rendicion_id'   => $renditionId,
            'usuario_id'     => (int)$admin['id'],
            'actor_tipo'     => 'TESORERIA',
            'actor_nombre'   => $admin['nombre'],
            'actor_email'    => $admin['email'],
            'accion'         => $action,
            'estado_anterior'=> $rendition['estado'],
            'estado_nuevo'   => 'RECHAZADA',
            'comentario'     => $comment,
            'metadata'       => [
                'reserva_liberada' => $reservedAmount,
                'total_rendido'    => (float)$rendition['monto_total_rendido'],
                'monto_exceso'     => (float)$rendition['monto_exceso'],
            ],
        ]);

        AuditService::log(
            $pdo,
            (int)$admin['id'],
            $admin['email'],
            'RENDICION_' . $action,
            json_encode([
                'rendicion_id'     => $renditionId,
                'reserva_liberada' => $reservedAmount,
                'estado_anterior'  => $rendition['estado'],
                'estado_nuevo'     => 'RECHAZADA',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );

        $pdo->commit();

        RendicionesService::jsonResponse(true, [
            'message' => 'Rendición rechazada y fondos liberados exitosamente.',
            'data'    => [
                'rendicion_id'     => $renditionId,
                'estado'           => 'RECHAZADA',
                'reserva_liberada' => $reservedAmount,
            ],
        ]);
    }

    if ($action === 'MARCAR_PAGADA') {
        RendicionesService::assertTransition($rendition['estado'], 'PAGADA');
        $stmtUpdateRendition = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET estado = "PAGADA"
             WHERE id = :id AND estado = :estado_actual'
        );
        $stmtUpdateRendition->execute([
            ':id'            => $renditionId,
            ':estado_actual' => $rendition['estado'],
        ]);
        if ($stmtUpdateRendition->rowCount() !== 1) {
            throw new RuntimeException('No fue posible registrar la transición a PAGADA.');
        }

        RendicionesService::logHistory($pdo, [
            'rendicion_id'   => $renditionId,
            'usuario_id'     => (int)$admin['id'],
            'actor_tipo'     => 'TESORERIA',
            'actor_nombre'   => $admin['nombre'],
            'actor_email'    => $admin['email'],
            'accion'         => 'MARCAR_PAGADA',
            'estado_anterior'=> $rendition['estado'],
            'estado_nuevo'   => 'PAGADA',
            'comentario'     => $comment !== '' ? RendicionesService::truncateText($comment, 1000) : null,
            'metadata'       => ['monto_aprobado' => (float)$rendition['monto_total_aprobado']],
        ]);

        AuditService::log(
            $pdo,
            (int)$admin['id'],
            $admin['email'],
            'RENDICION_MARCAR_PAGADA',
            json_encode(['rendicion_id' => $renditionId, 'estado_anterior' => $rendition['estado'], 'estado_nuevo' => 'PAGADA'])
        );

        $pdo->commit();

        RendicionesService::jsonResponse(true, [
            'message' => 'Rendición marcada como pagada correctamente.',
            'data'    => ['rendicion_id' => $renditionId, 'estado' => 'PAGADA'],
        ]);
    }
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
