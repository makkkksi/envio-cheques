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
require_once __DIR__ . '/../../../services/MailService.php';

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $admin = requirePermission($pdo, 'rendiciones.manage');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $companyId = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT) ?: 0;
        $sellerId  = filter_input(INPUT_GET, 'vendedor_id', FILTER_VALIDATE_INT) ?: 0;
        $stmt = $pdo->prepare(
            'SELECT p.*, e.nombre AS empresa_nombre,
                    (p.monto_asignado - p.monto_utilizado) AS saldo_disponible,
                    sa.estado AS solicitud_estado, sa.decision AS solicitud_decision,
                    sa.aprobador_nombre_snapshot, sa.correo_enviado_at AS solicitud_enviada_at,
                    sa.token_expira_at AS solicitud_token_expira
             FROM presupuestos_vendedores p
             INNER JOIN empresas e ON e.id = p.empresa_id
             LEFT JOIN solicitudes_aprobacion sa ON sa.id = p.solicitud_aprobacion_id
             WHERE (:todas_empresas = 1 OR p.empresa_id = :empresa_id)
               AND (:todos_vendedores = 1 OR p.vendedor_id = :vendedor_id)
             ORDER BY p.activo DESC, p.periodo_mes DESC, p.vendedor_nombre ASC, p.id DESC'
        );
        $stmt->execute([
            ':todas_empresas'  => $companyId === 0 ? 1 : 0,
            ':empresa_id'      => $companyId,
            ':todos_vendedores' => $sellerId === 0 ? 1 : 0,
            ':vendedor_id'     => $sellerId,
        ]);
        RendicionesService::jsonResponse(true, ['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    }

    RendicionesService::requireMethod('POST');
    requireCsrfToken();
    $input  = RendicionesService::readJsonBody();
    $action = strtoupper(trim((string)($input['accion'] ?? '')));

    $allowedActions = ['CREAR', 'ACTUALIZAR', 'DESACTIVAR', 'REENVIAR_SOLICITUD_GIRA', 'CAMBIAR_RESPONSABLE_GIRA', 'CANCELAR_SOLICITUD_GIRA'];
    if (!in_array($action, $allowedActions, true)) {
        throw new InvalidArgumentException('Acción de presupuesto no válida.');
    }

    $pdo->beginTransaction();

    // === DESACTIVAR ===
    if ($action === 'DESACTIVAR') {
        $budgetId = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$budgetId) {
            throw new InvalidArgumentException('id de presupuesto es obligatorio.');
        }
        $stmtCurrent = $pdo->prepare('SELECT id, monto_utilizado, activo FROM presupuestos_vendedores WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmtCurrent->execute([':id' => $budgetId]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        if (!$current || !(bool)$current['activo']) {
            throw new DomainException('Presupuesto no encontrado o ya desactivado.');
        }
        if ((float)$current['monto_utilizado'] > 0) {
            throw new DomainException('No se puede desactivar un presupuesto con fondos comprometidos.');
        }
        $stmtDeactivate = $pdo->prepare('UPDATE presupuestos_vendedores SET activo = :activo WHERE id = :id AND activo = :activo_actual');
        $stmtDeactivate->execute([':activo' => 0, ':id' => $budgetId, ':activo_actual' => 1]);
        AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_PRESUPUESTO_DESACTIVADO', json_encode(['presupuesto_id' => $budgetId]));
        $pdo->commit();
        RendicionesService::jsonResponse(true, ['message' => 'Presupuesto desactivado correctamente.']);
    }

    // === ACCIONES DE SOLICITUD DE GIRA ===
    if (in_array($action, ['REENVIAR_SOLICITUD_GIRA', 'CAMBIAR_RESPONSABLE_GIRA', 'CANCELAR_SOLICITUD_GIRA'], true)) {
        $budgetId = filter_var($input['presupuesto_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$budgetId) {
            throw new InvalidArgumentException('presupuesto_id es obligatorio para esta acción.');
        }
        $stmtBudget = $pdo->prepare(
            'SELECT p.*, sa.id AS solicitud_id, sa.estado AS solicitud_estado, sa.token_version
             FROM presupuestos_vendedores p
             LEFT JOIN solicitudes_aprobacion sa ON sa.id = p.solicitud_aprobacion_id
             WHERE p.id = :id AND p.activo = :activo AND p.tipo_presupuesto = :tipo
             LIMIT 1
             FOR UPDATE'
        );
        $stmtBudget->execute([':id' => $budgetId, ':activo' => 1, ':tipo' => 'GIRA']);
        $budget = $stmtBudget->fetch(PDO::FETCH_ASSOC);
        if (!$budget) {
            throw new DomainException('Presupuesto de gira no encontrado o inactivo.');
        }

        if ($action === 'CANCELAR_SOLICITUD_GIRA') {
            $cancelReason = mb_substr(trim((string)($input['motivo'] ?? 'Cancelado por Tesorería.')), 0, 500);
            if ($budget['solicitud_id']) {
                $pdo->prepare(
                    'UPDATE solicitudes_aprobacion
                     SET estado = :estado, cancelado_at = NOW(), cancelado_por = :por, motivo_cancelacion = :motivo
                     WHERE id = :id'
                )->execute([':estado' => 'CANCELADA', ':por' => (int)$admin['id'], ':motivo' => $cancelReason, ':id' => $budget['solicitud_id']]);
            }
            // Desactivar si no hay fondos comprometidos, sino marcar rechazada
            if ((float)$budget['monto_utilizado'] <= 0) {
                $pdo->prepare('UPDATE presupuestos_vendedores SET activo = 0, estado_aprobacion = :estado WHERE id = :id')
                    ->execute([':estado' => 'RECHAZADA', ':id' => $budgetId]);
            } else {
                $pdo->prepare('UPDATE presupuestos_vendedores SET estado_aprobacion = :estado WHERE id = :id')
                    ->execute([':estado' => 'RECHAZADA', ':id' => $budgetId]);
            }
            AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_GIRA_CANCELADA', json_encode(['presupuesto_id' => $budgetId, 'motivo' => $cancelReason], JSON_UNESCAPED_UNICODE));
            $pdo->commit();
            RendicionesService::jsonResponse(true, ['message' => 'Solicitud de gira cancelada.']);
        }

        // REENVIAR o CAMBIAR_RESPONSABLE
        $aprobadorId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$aprobadorId) {
            throw new InvalidArgumentException('Seleccione el responsable que recibirá la solicitud.');
        }
        $stmtApprover = $pdo->prepare('SELECT id, nombre, cargo, email FROM aprobadores_rendiciones WHERE id = :id AND activo = :activo LIMIT 1 FOR UPDATE');
        $stmtApprover->execute([':id' => $aprobadorId, ':activo' => 1]);
        $approver = $stmtApprover->fetch(PDO::FETCH_ASSOC);
        if (!$approver || !filter_var($approver['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El responsable seleccionado no está disponible o no tiene email válido.');
        }

        $rawToken   = bin2hex(random_bytes(32));
        $newVersion = ((int)($budget['token_version'] ?? 1)) + 1;
        $expiresAt  = date('Y-m-d H:i:s', time() + (RENDICIONES_TOKEN_TTL_HOURS * 3600));

        if ($budget['solicitud_id']) {
            $pdo->prepare(
                'UPDATE solicitudes_aprobacion
                 SET token_hash = :token_hash, token_expira_at = :expira, token_usado_at = NULL,
                     estado = :estado, decision = NULL, resuelto_at = NULL,
                     aprobador_id = :aprobador_id, aprobador_nombre_snapshot = :nombre,
                     aprobador_cargo_snapshot = :cargo, aprobador_email_snapshot = :email,
                     token_version = :version
                 WHERE id = :id'
            )->execute([
                ':token_hash'  => hash('sha256', $rawToken),
                ':expira'      => $expiresAt,
                ':estado'      => 'PENDIENTE_DECISION',
                ':aprobador_id' => (int)$approver['id'],
                ':nombre'      => $approver['nombre'],
                ':cargo'       => $approver['cargo'],
                ':email'       => $approver['email'],
                ':version'     => $newVersion,
                ':id'          => $budget['solicitud_id'],
            ]);
        } else {
            // Fallback: crear solicitud si no existe (no debería ocurrir)
            $pdo->prepare(
                'INSERT INTO solicitudes_aprobacion (tipo_solicitud, presupuesto_id, aprobador_id, aprobador_nombre_snapshot, aprobador_cargo_snapshot, aprobador_email_snapshot, monto_base_aprobable, monto_solicitado, justificacion, token_hash, token_expira_at, estado, solicitado_por)
                 VALUES (:tipo, :pid, :apid, :nombre, :cargo, :email, :monto_base, :monto_sol, :just, :token, :expira, :estado, :solicitado_por)'
            )->execute([
                ':tipo' => 'GIRA', ':pid' => $budgetId, ':apid' => (int)$approver['id'],
                ':nombre' => $approver['nombre'], ':cargo' => $approver['cargo'], ':email' => $approver['email'],
                ':monto_base' => '0.00', ':monto_sol' => $budget['monto_asignado'],
                ':just' => (string)($budget['justificacion_gira'] ?? 'Sin justificación'),
                ':token' => hash('sha256', $rawToken), ':expira' => $expiresAt,
                ':estado' => 'PENDIENTE_DECISION', ':solicitado_por' => (int)$admin['id'],
            ]);
            $newSolicitudId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE presupuestos_vendedores SET solicitud_aprobacion_id = :sid WHERE id = :id')
                ->execute([':sid' => $newSolicitudId, ':id' => $budgetId]);
        }

        AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_GIRA_REENVIO', json_encode([
            'presupuesto_id' => $budgetId, 'aprobador_id' => $aprobadorId, 'accion' => $action,
        ], JSON_UNESCAPED_UNICODE));
        $pdo->commit();

        // Enviar correo fuera de la transacción
        $mailSent = false;
        try {
            $mailSent = MailService::enviarSolicitudAprobacionGira([
                'id'                 => $budgetId,
                'nombre_gira'        => $budget['nombre_gira'],
                'vendedor_nombre'    => $budget['vendedor_nombre'],
                'vendedor_email'     => $budget['vendedor_email'],
                'periodo_mes'        => $budget['periodo_mes'],
                'monto_asignado'     => (float)$budget['monto_asignado'],
                'justificacion_gira' => (string)($budget['justificacion_gira'] ?? ''),
                'fecha_inicio'       => $budget['fecha_inicio'],
                'fecha_fin'          => $budget['fecha_fin'],
            ], $rawToken, $approver, trim((string)($input['comentario'] ?? '')));
        } catch (Throwable $mailEx) {
            error_log('[admin.rendiciones.gira.reenvio.mail] ' . $mailEx->getMessage());
        }
        RendicionesService::jsonResponse(true, [
            'message' => $mailSent ? 'Solicitud enviada al responsable correctamente.' : 'Solicitud preparada, pero el correo no pudo enviarse.',
            'data'    => ['presupuesto_id' => $budgetId, 'correo_enviado' => $mailSent],
        ]);
    }

    // === CREAR / ACTUALIZAR ===
    $budgetId   = $action === 'ACTUALIZAR' ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) : null;
    $companyId  = filter_var($input['empresa_id'] ?? null, FILTER_VALIDATE_INT);
    $sellerId   = filter_var($input['vendedor_id'] ?? null, FILTER_VALIDATE_INT);
    $type       = strtoupper(trim((string)($input['tipo_presupuesto'] ?? '')));
    $period     = trim((string)($input['periodo_mes'] ?? ''));
    $tourNameInput = trim((string)($input['nombre_gira'] ?? ''));
    $tourName   = $tourNameInput !== '' ? preg_replace('/\s+/u', ' ', $tourNameInput) : null;
    $startDate  = trim((string)($input['fecha_inicio'] ?? '')) ?: null;
    $endDate    = trim((string)($input['fecha_fin'] ?? '')) ?: null;
    $amount     = RendicionesService::normalizeMoney($input['monto_asignado'] ?? null);
    if (!$companyId || !$sellerId || !in_array($type, RendicionesService::TIPOS_PRESUPUESTO, true)) {
        throw new InvalidArgumentException('Empresa, vendedor y tipo de presupuesto son obligatorios.');
    }
    if ($type === 'MENSUAL') {
        $period    = RendicionesService::validatePeriod($period);
        $tourName  = null;
        $startDate = null;
        $endDate   = null;
    } elseif ($startDate !== null && RendicionesService::isValidDate($startDate)) {
        $period = substr($startDate, 0, 7);
    }
    $key = RendicionesService::createBudgetKey($companyId, $sellerId, $type, $period, $tourName, $startDate, $endDate);
    $erpSeller = ErpSellerDirectoryService::findByCompanyAndId($pdo, (int)$companyId, (int)$sellerId);
    if (!$erpSeller) {
        throw new InvalidArgumentException('El vendedor seleccionado no existe en el ERP de esa empresa.');
    }
    $name  = $erpSeller['vendedor_nombre'];
    $email = $erpSeller['vendedor_email'];

    // --- Validaciones adicionales para GIRA ---
    $justificacion     = null;
    $approverParaEnvio = null;
    $rawTokenParaEnvio = null;
    if ($type === 'GIRA' && $action === 'CREAR') {
        $justificacion = mb_substr(trim((string)($input['justificacion_gira'] ?? '')), 0, 500);
        if ($justificacion === '') {
            throw new InvalidArgumentException('La justificación de la gira es obligatoria.');
        }
        $aprobadorId = filter_var($input['aprobador_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$aprobadorId) {
            throw new InvalidArgumentException('Debe seleccionar un responsable para la aprobación de la gira.');
        }
        $stmtApprover = $pdo->prepare('SELECT id, nombre, cargo, email FROM aprobadores_rendiciones WHERE id = :id AND activo = :activo LIMIT 1');
        $stmtApprover->execute([':id' => $aprobadorId, ':activo' => 1]);
        $approverParaEnvio = $stmtApprover->fetch(PDO::FETCH_ASSOC);
        if (!$approverParaEnvio || !filter_var($approverParaEnvio['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El responsable seleccionado no está disponible o no tiene email configurado.');
        }
    }

    if ($action === 'CREAR') {
        $estadoAprobacion = ($type === 'GIRA') ? 'PENDIENTE' : 'NO_APLICA';
        $stmtInsert = $pdo->prepare(
            'INSERT INTO presupuestos_vendedores (
                empresa_id, vendedor_id, vendedor_nombre, vendedor_email,
                tipo_presupuesto, nombre_gira, periodo_mes, fecha_inicio,
                fecha_fin, monto_asignado, monto_utilizado, periodo_clave,
                estado_aprobacion, justificacion_gira,
                activo, creado_por
             ) VALUES (
                :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email,
                :tipo_presupuesto, :nombre_gira, :periodo_mes, :fecha_inicio,
                :fecha_fin, :monto_asignado, :monto_utilizado, :periodo_clave,
                :estado_aprobacion, :justificacion_gira,
                :activo, :creado_por
             )'
        );
        $stmtInsert->execute([
            ':empresa_id'         => $companyId,
            ':vendedor_id'        => $sellerId,
            ':vendedor_nombre'    => substr($name, 0, 150),
            ':vendedor_email'     => $email !== null ? substr($email, 0, 150) : null,
            ':tipo_presupuesto'   => $type,
            ':nombre_gira'        => $tourName,
            ':periodo_mes'        => $period,
            ':fecha_inicio'       => $startDate,
            ':fecha_fin'          => $endDate,
            ':monto_asignado'     => $amount,
            ':monto_utilizado'    => '0.00',
            ':periodo_clave'      => $key,
            ':estado_aprobacion'  => $estadoAprobacion,
            ':justificacion_gira' => $justificacion,
            ':activo'             => 1,
            ':creado_por'         => (int)$admin['id'],
        ]);
        $budgetId = (int)$pdo->lastInsertId();

        // Si es GIRA: crear solicitud_aprobacion y vincularla
        if ($type === 'GIRA' && $approverParaEnvio !== null) {
            $rawTokenParaEnvio = bin2hex(random_bytes(32));
            $stmtSolicitud = $pdo->prepare(
                'INSERT INTO solicitudes_aprobacion (
                    tipo_solicitud, presupuesto_id, aprobador_id,
                    aprobador_nombre_snapshot, aprobador_cargo_snapshot, aprobador_email_snapshot,
                    monto_base_aprobable, monto_solicitado, justificacion,
                    token_hash, token_expira_at, estado, solicitado_por
                 ) VALUES (
                    :tipo, :pid, :apid,
                    :nombre, :cargo, :email,
                    :monto_base, :monto_sol, :just,
                    :token_hash, :expira, :estado, :solicitado_por
                 )'
            );
            $stmtSolicitud->execute([
                ':tipo'           => 'GIRA',
                ':pid'            => $budgetId,
                ':apid'           => (int)$approverParaEnvio['id'],
                ':nombre'         => $approverParaEnvio['nombre'],
                ':cargo'          => $approverParaEnvio['cargo'],
                ':email'          => $approverParaEnvio['email'],
                ':monto_base'     => '0.00',
                ':monto_sol'      => $amount,
                ':just'           => $justificacion,
                ':token_hash'     => hash('sha256', $rawTokenParaEnvio),
                ':expira'         => date('Y-m-d H:i:s', time() + (RENDICIONES_TOKEN_TTL_HOURS * 3600)),
                ':estado'         => 'PENDIENTE_DECISION',
                ':solicitado_por' => (int)$admin['id'],
            ]);
            $solicitudId = (int)$pdo->lastInsertId();
            $pdo->prepare('UPDATE presupuestos_vendedores SET solicitud_aprobacion_id = :sid WHERE id = :id')
                ->execute([':sid' => $solicitudId, ':id' => $budgetId]);
        }

        $auditAction = 'RENDICION_PRESUPUESTO_CREADO';
    } else {
        // === ACTUALIZAR ===
        if (!$budgetId) {
            throw new InvalidArgumentException('id de presupuesto es obligatorio.');
        }
        $stmtCurrent = $pdo->prepare('SELECT * FROM presupuestos_vendedores WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmtCurrent->execute([':id' => $budgetId]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        if (!$current || !(bool)$current['activo']) {
            throw new DomainException('Presupuesto no encontrado o inactivo.');
        }
        if ((float)$amount < (float)$current['monto_utilizado']) {
            throw new DomainException('El monto asignado no puede ser menor al monto comprometido.');
        }
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
        $stmtUpdate = $pdo->prepare(
            'UPDATE presupuestos_vendedores
             SET empresa_id = :empresa_id, vendedor_id = :vendedor_id,
                 vendedor_nombre = :vendedor_nombre, vendedor_email = :vendedor_email,
                 tipo_presupuesto = :tipo_presupuesto, nombre_gira = :nombre_gira,
                 periodo_mes = :periodo_mes, fecha_inicio = :fecha_inicio,
                 fecha_fin = :fecha_fin, monto_asignado = :monto_asignado,
                 periodo_clave = :periodo_clave
             WHERE id = :id AND activo = :activo'
        );
        $stmtUpdate->execute([
            ':empresa_id'      => $companyId,
            ':vendedor_id'     => $sellerId,
            ':vendedor_nombre' => substr($name, 0, 150),
            ':vendedor_email'  => $email !== null ? substr($email, 0, 150) : null,
            ':tipo_presupuesto' => $type,
            ':nombre_gira'     => $tourName,
            ':periodo_mes'     => $period,
            ':fecha_inicio'    => $startDate,
            ':fecha_fin'       => $endDate,
            ':monto_asignado'  => $amount,
            ':periodo_clave'   => $key,
            ':id'              => $budgetId,
            ':activo'          => 1,
        ]);
        $auditAction = 'RENDICION_PRESUPUESTO_ACTUALIZADO';
    }

    AuditService::log($pdo, (int)$admin['id'], $admin['email'], $auditAction, json_encode([
        'presupuesto_id' => $budgetId,
        'tipo_presupuesto' => $type,
        'periodo_mes'    => $period,
        'monto_asignado' => $amount,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $pdo->commit();

    // Enviar correo de solicitud de gira (fuera de transacción)
    if ($action === 'CREAR' && $type === 'GIRA' && $approverParaEnvio !== null && $rawTokenParaEnvio !== null) {
        try {
            MailService::enviarSolicitudAprobacionGira([
                'id'                 => $budgetId,
                'nombre_gira'        => $tourName,
                'vendedor_nombre'    => $name,
                'vendedor_email'     => $email,
                'periodo_mes'        => $period,
                'monto_asignado'     => (float)$amount,
                'justificacion_gira' => $justificacion,
                'fecha_inicio'       => $startDate,
                'fecha_fin'          => $endDate,
            ], $rawTokenParaEnvio, $approverParaEnvio, '');
        } catch (Throwable $mailEx) {
            error_log('[admin.rendiciones.gestion_presupuestos.gira_mail] ' . $mailEx->getMessage());
        }
    }

    RendicionesService::jsonResponse(true, [
        'message' => 'Presupuesto guardado correctamente.',
        'data'    => ['presupuesto_id' => $budgetId],
    ]);
} catch (InvalidArgumentException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if (RendicionesService::isDuplicateKey($exception)) {
        RendicionesService::jsonResponse(false, ['message' => 'Ya existe un presupuesto equivalente para ese vendedor.'], 409);
    }
    error_log('[admin.rendiciones.gestion_presupuestos] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible guardar el presupuesto.'], 500);
}
