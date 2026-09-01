<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/AuditService.php';
require_once __DIR__ . '/../../../services/RendicionesService.php';
require_once __DIR__ . '/../../../services/ErpSellerDirectoryService.php';
require_once __DIR__ . '/../../../services/ApprovalWorkflowService.php';
require_once __DIR__ . '/../../../services/MailService.php';

function tourApprovalActor(array $admin): array
{
    return ['id' => (int)$admin['id'], 'nombre' => $admin['nombre'], 'email' => $admin['email']];
}

function sendTourApprovalAndRecord(PDO $pdo, array $budget, array $workflow): bool
{
    $request = $workflow['solicitud'];
    $approver = [
        'id' => (int)$request['aprobador_id'],
        'nombre' => $request['aprobador_nombre_snapshot'],
        'cargo' => $request['aprobador_cargo_snapshot'],
        'email' => $request['aprobador_email_snapshot'],
    ];
    $sent = false;
    try {
        $sent = MailService::enviarSolicitudAprobacionGira($budget, $workflow['raw_token'], $approver);
    } catch (Throwable $mailException) {
        error_log('[admin.rendiciones.gira.mail] ' . $mailException->getMessage());
    }

    try {
        $pdo->beginTransaction();
        ApprovalWorkflowService::markEmailResult(
            $pdo,
            (int)$request['id'],
            $sent,
            $sent ? null : 'El servidor SMTP no confirmó la entrega del correo.'
        );
        $pdo->commit();
    } catch (Throwable $stateException) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[admin.rendiciones.gira.mail_state] ' . $stateException->getMessage());
        return false;
    }
    return $sent;
}

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $admin = requirePermission($pdo, 'rendiciones.manage');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $companyId = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT) ?: 0;
        $sellerId = filter_input(INPUT_GET, 'vendedor_id', FILTER_VALIDATE_INT) ?: 0;
        $stmt = $pdo->prepare(
            'SELECT p.*, e.nombre AS empresa_nombre,
                    (p.monto_asignado - p.monto_utilizado) AS saldo_disponible,
                    sa.estado AS solicitud_estado, sa.decision AS solicitud_decision,
                    sa.aprobador_id AS solicitud_aprobador_id,
                    sa.aprobador_nombre_snapshot, sa.aprobador_cargo_snapshot,
                    sa.aprobador_email_snapshot, sa.correo_enviado_at AS solicitud_enviada_at,
                    sa.token_expira_at AS solicitud_token_expira
             FROM presupuestos_vendedores p
             INNER JOIN empresas e ON e.id = p.empresa_id
             LEFT JOIN solicitudes_aprobacion sa ON sa.id = p.solicitud_aprobacion_id
             WHERE (:todas_empresas = 1 OR p.empresa_id = :empresa_id)
               AND (:todos_vendedores = 1 OR p.vendedor_id = :vendedor_id)
             ORDER BY p.activo DESC, p.periodo_mes DESC, p.vendedor_nombre ASC, p.id DESC'
        );
        $stmt->execute([
            ':todas_empresas' => $companyId === 0 ? 1 : 0,
            ':empresa_id' => $companyId,
            ':todos_vendedores' => $sellerId === 0 ? 1 : 0,
            ':vendedor_id' => $sellerId,
        ]);
        RendicionesService::jsonResponse(true, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    RendicionesService::requireMethod('POST');
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();
    $action = strtoupper(trim((string)($input['accion'] ?? '')));
    $allowedActions = ['CREAR', 'ACTUALIZAR', 'DESACTIVAR', 'REENVIAR_SOLICITUD_GIRA', 'CAMBIAR_RESPONSABLE_GIRA', 'CANCELAR_SOLICITUD_GIRA'];
    if (!in_array($action, $allowedActions, true)) {
        throw new InvalidArgumentException('Acción de presupuesto no válida.');
    }

    $pdo->beginTransaction();
    if ($action === 'DESACTIVAR') {
        $budgetId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$budgetId) throw new InvalidArgumentException('id de presupuesto es obligatorio.');
        $stmt = $pdo->prepare('SELECT id, monto_utilizado, activo FROM presupuestos_vendedores WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $budgetId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current || !(bool)$current['activo']) throw new DomainException('Presupuesto no encontrado o ya desactivado.');
        if ((float)$current['monto_utilizado'] > 0) throw new DomainException('No se puede desactivar un presupuesto con fondos comprometidos.');
        $stmt = $pdo->prepare('UPDATE presupuestos_vendedores SET activo = :activo WHERE id = :id AND activo = :activo_actual');
        $stmt->execute([':activo' => 0, ':id' => $budgetId, ':activo_actual' => 1]);
        AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_PRESUPUESTO_DESACTIVADO', json_encode(['presupuesto_id' => $budgetId]));
        $pdo->commit();
        RendicionesService::jsonResponse(true, ['message' => 'Presupuesto desactivado correctamente.']);
    }

    if (in_array($action, ['REENVIAR_SOLICITUD_GIRA', 'CAMBIAR_RESPONSABLE_GIRA', 'CANCELAR_SOLICITUD_GIRA'], true)) {
        $budgetId = filter_var($input['presupuesto_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$budgetId) throw new InvalidArgumentException('presupuesto_id es obligatorio para esta acción.');
        $stmt = $pdo->prepare(
            'SELECT p.*, sa.id AS solicitud_id
             FROM presupuestos_vendedores p
             LEFT JOIN solicitudes_aprobacion sa ON sa.id = p.solicitud_aprobacion_id
             WHERE p.id = :id AND p.activo = :activo AND p.tipo_presupuesto = :tipo
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $budgetId, ':activo' => 1, ':tipo' => 'GIRA']);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$budget || !$budget['solicitud_id']) throw new DomainException('La gira no tiene una solicitud vigente.');

        if ($action === 'CANCELAR_SOLICITUD_GIRA') {
            $reason = RendicionesService::truncateText(trim((string)($input['motivo'] ?? '')), 500);
            ApprovalWorkflowService::cancelRequest($pdo, (int)$budget['solicitud_id'], tourApprovalActor($admin), $reason);
            if ((float)$budget['monto_utilizado'] <= 0) {
                $stmt = $pdo->prepare('UPDATE presupuestos_vendedores SET activo = :activo WHERE id = :id');
                $stmt->execute([':activo' => 0, ':id' => $budgetId]);
            }
            AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_GIRA_CANCELADA', json_encode(['presupuesto_id' => $budgetId, 'motivo' => $reason], JSON_UNESCAPED_UNICODE));
            $pdo->commit();
            RendicionesService::jsonResponse(true, ['message' => 'Solicitud de gira cancelada con trazabilidad.']);
        }

        $approverId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$approverId) throw new InvalidArgumentException('Seleccione el responsable que recibirá la solicitud.');
        $workflow = ApprovalWorkflowService::rotateToken($pdo, (int)$budget['solicitud_id'], (int)$approverId, tourApprovalActor($admin));
        AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_GIRA_REENVIO', json_encode(['presupuesto_id' => $budgetId, 'aprobador_id' => $approverId, 'accion' => $action], JSON_UNESCAPED_UNICODE));
        $pdo->commit();
        $mailSent = sendTourApprovalAndRecord($pdo, $budget, $workflow);
        RendicionesService::jsonResponse(true, [
            'message' => $mailSent ? 'Solicitud enviada al responsable correctamente.' : 'La solicitud quedó pendiente, pero el correo no pudo enviarse. Puede reenviarla desde el panel.',
            'data' => ['presupuesto_id' => $budgetId, 'correo_enviado' => $mailSent],
        ]);
    }

    $budgetId = $action === 'ACTUALIZAR' ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) : null;
    $companyId = filter_var($input['empresa_id'] ?? null, FILTER_VALIDATE_INT);
    $sellerId = filter_var($input['vendedor_id'] ?? null, FILTER_VALIDATE_INT);
    $type = strtoupper(trim((string)($input['tipo_presupuesto'] ?? '')));
    $period = trim((string)($input['periodo_mes'] ?? ''));
    $tourNameInput = trim((string)($input['nombre_gira'] ?? ''));
    $tourName = $tourNameInput !== '' ? preg_replace('/\s+/u', ' ', $tourNameInput) : null;
    $startDate = trim((string)($input['fecha_inicio'] ?? '')) ?: null;
    $endDate = trim((string)($input['fecha_fin'] ?? '')) ?: null;
    $amount = RendicionesService::normalizeMoney($input['monto_asignado'] ?? null);
    if (!$companyId || !$sellerId || !in_array($type, RendicionesService::TIPOS_PRESUPUESTO, true)) {
        throw new InvalidArgumentException('Empresa, vendedor y tipo de presupuesto son obligatorios.');
    }
    if ($type === 'MENSUAL') {
        $period = RendicionesService::validatePeriod($period);
        $tourName = null;
        $startDate = null;
        $endDate = null;
    } elseif ($startDate !== null && RendicionesService::isValidDate($startDate)) {
        $period = substr($startDate, 0, 7);
    }
    $key = RendicionesService::createBudgetKey((int)$companyId, (int)$sellerId, $type, $period, $tourName, $startDate, $endDate);
    $erpSeller = ErpSellerDirectoryService::findByCompanyAndId($pdo, (int)$companyId, (int)$sellerId);
    if (!$erpSeller) throw new InvalidArgumentException('El vendedor seleccionado no existe en el ERP de esa empresa.');
    $name = $erpSeller['vendedor_nombre'];
    $email = $erpSeller['vendedor_email'];
    $justification = RendicionesService::truncateText(trim((string)($input['justificacion_gira'] ?? '')), 500);
    $approverId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT);
    $workflow = null;
    $budgetForMail = null;

    if ($action === 'CREAR') {
        if ($type === 'GIRA' && ($justification === '' || !$approverId)) {
            throw new InvalidArgumentException('La gira requiere una justificación y un responsable de aprobación.');
        }
        $stmt = $pdo->prepare(
            'INSERT INTO presupuestos_vendedores (
                empresa_id, vendedor_id, vendedor_nombre, vendedor_email,
                tipo_presupuesto, nombre_gira, periodo_mes, fecha_inicio, fecha_fin,
                monto_asignado, monto_utilizado, periodo_clave, estado_aprobacion,
                justificacion_gira, activo, creado_por
             ) VALUES (
                :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email,
                :tipo, :nombre_gira, :periodo, :fecha_inicio, :fecha_fin,
                :monto_asignado, :monto_utilizado, :periodo_clave, :estado_aprobacion,
                :justificacion, :activo, :creado_por
             )'
        );
        $stmt->execute([
            ':empresa_id' => $companyId, ':vendedor_id' => $sellerId,
            ':vendedor_nombre' => substr($name, 0, 150), ':vendedor_email' => $email !== null ? substr($email, 0, 150) : null,
            ':tipo' => $type, ':nombre_gira' => $tourName, ':periodo' => $period,
            ':fecha_inicio' => $startDate, ':fecha_fin' => $endDate,
            ':monto_asignado' => $amount, ':monto_utilizado' => '0.00', ':periodo_clave' => $key,
            ':estado_aprobacion' => $type === 'GIRA' ? 'PENDIENTE' : 'NO_APLICA',
            ':justificacion' => $type === 'GIRA' ? $justification : null,
            ':activo' => 1, ':creado_por' => (int)$admin['id'],
        ]);
        $budgetId = (int)$pdo->lastInsertId();
        if ($type === 'GIRA') {
            $workflow = ApprovalWorkflowService::createRequest($pdo, [
                'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
                'presupuesto_id' => $budgetId, 'aprobador_id' => $approverId,
                'solicitado_por' => (int)$admin['id'], 'justificacion' => $justification,
                'actor_nombre' => $admin['nombre'], 'actor_email' => $admin['email'],
            ]);
        }
        $auditAction = 'RENDICION_PRESUPUESTO_CREADO';
    } else {
        if (!$budgetId) throw new InvalidArgumentException('id de presupuesto es obligatorio.');
        $stmt = $pdo->prepare(
            'SELECT p.*, sa.id AS solicitud_actual_id, sa.estado AS solicitud_actual_estado
             FROM presupuestos_vendedores p
             LEFT JOIN solicitudes_aprobacion sa ON sa.id = p.solicitud_aprobacion_id
             WHERE p.id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $budgetId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$current || !(bool)$current['activo']) throw new DomainException('Presupuesto no encontrado o inactivo.');
        if ((float)$amount < (float)$current['monto_utilizado']) throw new DomainException('El monto asignado no puede ser menor al monto comprometido.');

        $identityChanged = (int)$current['empresa_id'] !== (int)$companyId
            || (int)$current['vendedor_id'] !== (int)$sellerId
            || $current['tipo_presupuesto'] !== $type
            || $current['periodo_mes'] !== $period
            || (string)$current['nombre_gira'] !== (string)$tourName
            || (string)$current['fecha_inicio'] !== (string)$startDate
            || (string)$current['fecha_fin'] !== (string)$endDate;
        if ($identityChanged && (float)$current['monto_utilizado'] > 0) {
            throw new DomainException('No se puede cambiar la identidad de un presupuesto con fondos comprometidos.');
        }

        $amountIncreased = (float)$amount > (float)$current['monto_asignado'] + 0.001;
        $approvalMaterialChanged = $identityChanged || abs((float)$amount - (float)$current['monto_asignado']) > 0.001;
        $needsApproval = $type === 'GIRA' && (
            $current['tipo_presupuesto'] !== 'GIRA'
            || $current['estado_aprobacion'] !== 'APROBADA'
            || $amountIncreased
            || ($approvalMaterialChanged && in_array((string)$current['solicitud_actual_estado'], ['PENDIENTE_ENVIO', 'PENDIENTE_DECISION', 'ENVIO_FALLIDO', 'VENCIDA'], true))
        );
        if ($needsApproval && ($justification === '' || !$approverId)) {
            throw new InvalidArgumentException('Este cambio requiere una nueva justificación y un responsable de aprobación.');
        }
        if ($needsApproval && $current['solicitud_actual_id'] && in_array((string)$current['solicitud_actual_estado'], ['PENDIENTE_ENVIO', 'PENDIENTE_DECISION', 'ENVIO_FALLIDO', 'VENCIDA'], true)) {
            ApprovalWorkflowService::cancelRequest($pdo, (int)$current['solicitud_actual_id'], tourApprovalActor($admin), 'Solicitud reemplazada por una modificación de la gira.');
        }

        $stmt = $pdo->prepare(
            'UPDATE presupuestos_vendedores
             SET empresa_id = :empresa_id, vendedor_id = :vendedor_id,
                 vendedor_nombre = :vendedor_nombre, vendedor_email = :vendedor_email,
                 tipo_presupuesto = :tipo, nombre_gira = :nombre_gira,
                 periodo_mes = :periodo, fecha_inicio = :fecha_inicio, fecha_fin = :fecha_fin,
                 monto_asignado = :monto_asignado, periodo_clave = :periodo_clave,
                 justificacion_gira = :justificacion
             WHERE id = :id AND activo = :activo'
        );
        $stmt->execute([
            ':empresa_id' => $companyId, ':vendedor_id' => $sellerId,
            ':vendedor_nombre' => substr($name, 0, 150), ':vendedor_email' => $email !== null ? substr($email, 0, 150) : null,
            ':tipo' => $type, ':nombre_gira' => $tourName, ':periodo' => $period,
            ':fecha_inicio' => $startDate, ':fecha_fin' => $endDate,
            ':monto_asignado' => $amount, ':periodo_clave' => $key,
            ':justificacion' => $type === 'GIRA' ? ($justification !== '' ? $justification : $current['justificacion_gira']) : null,
            ':id' => $budgetId, ':activo' => 1,
        ]);
        if ($needsApproval) {
            $workflow = ApprovalWorkflowService::createRequest($pdo, [
                'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
                'presupuesto_id' => $budgetId, 'aprobador_id' => $approverId,
                'solicitado_por' => (int)$admin['id'], 'justificacion' => $justification,
                'actor_nombre' => $admin['nombre'], 'actor_email' => $admin['email'],
            ]);
        }
        $auditAction = 'RENDICION_PRESUPUESTO_ACTUALIZADO';
    }

    AuditService::log($pdo, (int)$admin['id'], $admin['email'], $auditAction, json_encode([
        'presupuesto_id' => $budgetId, 'tipo_presupuesto' => $type,
        'periodo_mes' => $period, 'monto_asignado' => $amount,
        'requiere_nueva_aprobacion' => $workflow !== null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    if ($workflow !== null) {
        $budgetForMail = [
            'id' => $budgetId, 'nombre_gira' => $tourName, 'vendedor_nombre' => $name,
            'vendedor_email' => $email, 'periodo_mes' => $period, 'monto_asignado' => (float)$amount,
            'justificacion_gira' => $justification, 'fecha_inicio' => $startDate, 'fecha_fin' => $endDate,
        ];
    }
    $pdo->commit();

    $mailSent = null;
    if ($workflow !== null && $budgetForMail !== null) {
        $mailSent = sendTourApprovalAndRecord($pdo, $budgetForMail, $workflow);
    }
    RendicionesService::jsonResponse(true, [
        'message' => $workflow === null
            ? 'Presupuesto guardado correctamente.'
            : ($mailSent ? 'Gira guardada y enviada a aprobación.' : 'Gira guardada, pero el correo falló. Puede reenviarlo desde el panel.'),
        'data' => ['presupuesto_id' => $budgetId, 'requiere_aprobacion' => $workflow !== null, 'correo_enviado' => $mailSent],
    ]);
} catch (InvalidArgumentException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 409);
} catch (PDOException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (RendicionesService::isDuplicateKey($exception)) {
        RendicionesService::jsonResponse(false, ['message' => 'Ya existe un presupuesto activo con la misma combinación de empresa, vendedor y período.'], 409);
    }
    error_log('[admin.rendiciones.gestion_presupuestos] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible guardar el presupuesto.'], 500);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin.rendiciones.gestion_presupuestos] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible completar la gestión del presupuesto.'], 500);
}
