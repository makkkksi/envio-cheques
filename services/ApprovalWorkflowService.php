<?php
/**
 * Orquestador transaccional de aprobaciones de giras y excepciones mensuales.
 *
 * El token crudo se devuelve una sola vez al llamador para el envío de correo;
 * únicamente su SHA-256 queda persistido. Todos los métodos mutadores exigen
 * una transacción activa para que el endpoint pueda coordinar dominio y correo.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

final class ApprovalWorkflowService
{
    public const TYPE_TOUR = 'GIRA';
    public const TYPE_MONTHLY_EXCEPTION = 'EXCEPCION_MENSUAL';
    public const TYPE_RENDITION_APPROVAL = 'APROBACION_RENDICION';

    public const STATE_PENDING_SEND = 'PENDIENTE_ENVIO';
    public const STATE_PENDING_DECISION = 'PENDIENTE_DECISION';
    public const STATE_SEND_FAILED = 'ENVIO_FALLIDO';
    public const STATE_EXPIRED = 'VENCIDA';
    public const STATE_APPROVED = 'APROBADA';
    public const STATE_REJECTED = 'RECHAZADA';
    public const STATE_CANCELLED = 'CANCELADA';

    public const DECISION_APPROVED        = 'APROBADA';
    public const DECISION_REJECTED        = 'RECHAZADA';
    public const DECISION_APPROVED_CAPPED = 'APROBADA_TOPE'; // Aprueba solo hasta el tope presupuestario; el exceso queda como no reembolsable

    private const FINAL_STATES = [self::STATE_APPROVED, self::STATE_REJECTED, self::STATE_CANCELLED];
    private const RESENDABLE_STATES = [self::STATE_PENDING_SEND, self::STATE_PENDING_DECISION, self::STATE_SEND_FAILED, self::STATE_EXPIRED];

    /**
     * @return array{solicitud: array, raw_token: string}
     */
    public static function createRequest(PDO $pdo, array $input): array
    {
        self::assertTransaction($pdo);

        $type = strtoupper(trim((string)($input['tipo_solicitud'] ?? '')));
        if (!in_array($type, [self::TYPE_TOUR, self::TYPE_MONTHLY_EXCEPTION, self::TYPE_RENDITION_APPROVAL], true)) {
            throw new InvalidArgumentException('Tipo de solicitud de aprobación no válido.');
        }

        $approver = self::loadApprover($pdo, (int)($input['aprobador_id'] ?? 0));
        $requesterId = self::positiveId($input['solicitado_por'] ?? 0, 'El solicitante no es válido.');
        $justification = self::validateText((string)($input['justificacion'] ?? ''), 500, 'Ingrese una justificación de hasta 500 caracteres.');
        $budgetId = null;
        $renditionId = null;
        $baseAmount = '0.00';
        $requestedAmount = '0.00';

        if ($type === self::TYPE_TOUR) {
            $budgetId = self::positiveId($input['presupuesto_id'] ?? 0, 'La gira no es válida.');
            $budget = self::loadTourBudget($pdo, $budgetId);
            self::assertNoOpenRequest($pdo, $type, $budgetId, null);
            $requestedAmount = self::money($budget['monto_asignado']);
        } elseif ($type === self::TYPE_RENDITION_APPROVAL) {
            $renditionId = self::positiveId($input['rendicion_id'] ?? 0, 'La rendición no es válida.');
            $rendition = self::loadRendition($pdo, $renditionId);
            self::assertNoOpenRequest($pdo, $type, null, $renditionId);
            $baseAmount = self::moneyAllowZero($rendition['monto_presupuesto_asignado'] ?? 0);
            $requestedAmount = self::money($input['monto_solicitado'] ?? $rendition['monto_total_rendido']);
        } else {
            $renditionId = self::positiveId($input['rendicion_id'] ?? 0, 'La rendición no es válida.');
            $rendition = self::loadRendition($pdo, $renditionId);
            self::assertNoOpenRequest($pdo, $type, null, $renditionId);
            $baseAmount = self::moneyAllowZero($rendition['monto_maximo_aprobable']);
            $availableException = max(0.0, (float)$rendition['monto_total_rendido'] - (float)$baseAmount);
            $requestedAmount = self::money($input['monto_solicitado'] ?? $availableException);
            if ((float)$requestedAmount > $availableException + 0.001) {
                throw new DomainException('El monto solicitado supera el exceso pendiente de la rendición.');
            }
        }

        $version = self::nextVersion($pdo, $type, $budgetId, $renditionId);
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (RENDICIONES_TOKEN_TTL_HOURS * 3600));

        $stmt = $pdo->prepare(
            'INSERT INTO solicitudes_aprobacion (
                tipo_solicitud, presupuesto_id, rendicion_id, solicitud_version,
                token_version, aprobador_id, aprobador_nombre_snapshot,
                aprobador_cargo_snapshot, aprobador_email_snapshot,
                monto_base_aprobable, monto_solicitado, justificacion,
                token_hash, token_expira_at, estado, solicitado_por
             ) VALUES (
                :tipo_solicitud, :presupuesto_id, :rendicion_id, :solicitud_version,
                :token_version, :aprobador_id, :aprobador_nombre,
                :aprobador_cargo, :aprobador_email,
                :monto_base, :monto_solicitado, :justificacion,
                :token_hash, :token_expira_at, :estado, :solicitado_por
             )'
        );
        $stmt->execute([
            ':tipo_solicitud' => $type,
            ':presupuesto_id' => $budgetId,
            ':rendicion_id' => $renditionId,
            ':solicitud_version' => $version,
            ':token_version' => 1,
            ':aprobador_id' => (int)$approver['id'],
            ':aprobador_nombre' => $approver['nombre'],
            ':aprobador_cargo' => $approver['cargo'],
            ':aprobador_email' => $approver['email'],
            ':monto_base' => $baseAmount,
            ':monto_solicitado' => $requestedAmount,
            ':justificacion' => $justification,
            ':token_hash' => hash('sha256', $rawToken),
            ':token_expira_at' => $expiresAt,
            ':estado' => self::STATE_PENDING_SEND,
            ':solicitado_por' => $requesterId,
        ]);
        $requestId = (int)$pdo->lastInsertId();

        if ($type === self::TYPE_TOUR) {
            $update = $pdo->prepare(
                'UPDATE presupuestos_vendedores
                 SET estado_aprobacion = :estado, justificacion_gira = :justificacion,
                     solicitud_aprobacion_id = :solicitud_id, aprobado_at = NULL
                 WHERE id = :presupuesto_id'
            );
            $update->execute([
                ':estado' => 'PENDIENTE',
                ':justificacion' => $justification,
                ':solicitud_id' => $requestId,
                ':presupuesto_id' => $budgetId,
            ]);
        } else {
            $update = $pdo->prepare(
                'UPDATE rendiciones_gastos
                 SET solicitud_excepcion_id = :solicitud_id
                 WHERE id = :rendicion_id'
            );
            $update->execute([':solicitud_id' => $requestId, ':rendicion_id' => $renditionId]);
        }

        self::log($pdo, $requestId, [
            'actor_tipo' => 'TESORERIA',
            'actor_id' => $requesterId,
            'actor_nombre' => (string)($input['actor_nombre'] ?? 'Tesorería'),
            'actor_email' => $input['actor_email'] ?? null,
            'accion' => 'CREAR_SOLICITUD',
            'estado_nuevo' => self::STATE_PENDING_SEND,
            'comentario' => $justification,
            'metadata' => ['tipo' => $type, 'version' => $version, 'monto_solicitado' => $requestedAmount],
        ]);

        return ['solicitud' => self::loadById($pdo, $requestId), 'raw_token' => $rawToken];
    }

    public static function markEmailResult(PDO $pdo, int $requestId, bool $sent, ?string $failureReason = null): array
    {
        self::assertTransaction($pdo);
        $request = self::loadByIdForUpdate($pdo, $requestId);
        if ($request['estado'] !== self::STATE_PENDING_SEND) {
            throw new DomainException('El resultado de correo sólo puede registrarse después de crear o reenviar la solicitud.');
        }

        $newState = $sent ? self::STATE_PENDING_DECISION : self::STATE_SEND_FAILED;
        $reason = $sent ? null : self::optionalText((string)$failureReason, 500);
        $stmt = $pdo->prepare(
            'UPDATE solicitudes_aprobacion
             SET estado = :estado, correo_enviado_at = :correo_enviado_at,
                 motivo_envio_fallido = :motivo_envio_fallido
             WHERE id = :id'
        );
        $stmt->execute([
            ':estado' => $newState,
            ':correo_enviado_at' => $sent ? date('Y-m-d H:i:s') : null,
            ':motivo_envio_fallido' => $reason,
            ':id' => $requestId,
        ]);
        self::log($pdo, $requestId, [
            'actor_tipo' => 'SISTEMA', 'actor_nombre' => 'Sistema',
            'accion' => $sent ? 'CORREO_ENVIADO' : 'CORREO_FALLIDO',
            'estado_anterior' => $request['estado'], 'estado_nuevo' => $newState,
            'comentario' => $reason,
        ]);
        return self::loadById($pdo, $requestId);
    }

    /** @return array{solicitud: array, raw_token: string} */
    public static function rotateToken(PDO $pdo, int $requestId, int $approverId, array $actor): array
    {
        self::assertTransaction($pdo);
        $request = self::loadByIdForUpdate($pdo, $requestId);
        if (!in_array($request['estado'], self::RESENDABLE_STATES, true)) {
            throw new DomainException('La solicitud no admite reenvío.');
        }
        $approver = self::loadApprover($pdo, $approverId);
        $rawToken = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + (RENDICIONES_TOKEN_TTL_HOURS * 3600));
        $stmt = $pdo->prepare(
            'UPDATE solicitudes_aprobacion
             SET aprobador_id = :aprobador_id,
                 aprobador_nombre_snapshot = :nombre,
                 aprobador_cargo_snapshot = :cargo,
                 aprobador_email_snapshot = :email,
                 token_hash = :token_hash, token_expira_at = :token_expira_at,
                 token_usado_at = NULL, token_version = token_version + 1,
                 estado = :estado, decision = NULL, comentario_decision = NULL,
                 correo_enviado_at = NULL, motivo_envio_fallido = NULL,
                 resuelto_at = NULL, activo = 1
             WHERE id = :id'
        );
        $stmt->execute([
            ':aprobador_id' => (int)$approver['id'], ':nombre' => $approver['nombre'],
            ':cargo' => $approver['cargo'], ':email' => $approver['email'],
            ':token_hash' => hash('sha256', $rawToken), ':token_expira_at' => $expiresAt,
            ':estado' => self::STATE_PENDING_SEND, ':id' => $requestId,
        ]);
        self::log($pdo, $requestId, [
            'actor_tipo' => 'TESORERIA', 'actor_id' => $actor['id'] ?? null,
            'actor_nombre' => (string)($actor['nombre'] ?? 'Tesorería'),
            'actor_email' => $actor['email'] ?? null, 'accion' => 'REENVIAR_SOLICITUD',
            'estado_anterior' => $request['estado'], 'estado_nuevo' => self::STATE_PENDING_SEND,
            'metadata' => ['aprobador_id' => (int)$approver['id'], 'token_version' => (int)$request['token_version'] + 1],
        ]);
        return ['solicitud' => self::loadById($pdo, $requestId), 'raw_token' => $rawToken];
    }

    /**
     * Resuelve con bloqueo de fila. Si el token venció, persiste VENCIDA y
     * devuelve expired=true para permitir al endpoint confirmar la transacción.
     */
    public static function resolveByToken(PDO $pdo, string $rawToken, string $decision, string $comment = ''): array
    {
        self::assertTransaction($pdo);
        $request = self::loadByTokenForUpdate($pdo, $rawToken);
        if ($request['estado'] !== self::STATE_PENDING_DECISION || !(bool)$request['activo']) {
            throw new DomainException('La solicitud no está disponible para decisión.');
        }
        if (strtotime((string)$request['token_expira_at']) < time()) {
            $stmt = $pdo->prepare('UPDATE solicitudes_aprobacion SET estado = :estado WHERE id = :id');
            $stmt->execute([':estado' => self::STATE_EXPIRED, ':id' => (int)$request['id']]);
            self::log($pdo, (int)$request['id'], [
                'actor_tipo' => 'SISTEMA', 'actor_nombre' => 'Sistema',
                'accion' => 'TOKEN_VENCIDO', 'estado_anterior' => $request['estado'],
                'estado_nuevo' => self::STATE_EXPIRED,
            ]);
            return ['expired' => true, 'solicitud' => self::loadById($pdo, (int)$request['id'])];
        }

        $decision = strtoupper(trim($decision));
        if (!in_array($decision, [self::DECISION_APPROVED, self::DECISION_REJECTED, self::DECISION_APPROVED_CAPPED], true)) {
            throw new InvalidArgumentException('Decisión no válida.');
        }
        $comment = self::optionalText($comment, 500);
        if ($decision === self::DECISION_REJECTED && $comment === null) {
            throw new InvalidArgumentException('Indique el motivo del rechazo.');
        }
        // APROBADA_TOPE se persiste en la solicitud como APROBADA (resolución positiva); el matiz queda en la rendición.
        $newState = ($decision === self::DECISION_REJECTED) ? self::STATE_REJECTED : self::STATE_APPROVED;
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE solicitudes_aprobacion
             SET estado = :estado, decision = :decision, comentario_decision = :comentario,
                 token_usado_at = :token_usado_at, resuelto_at = :resuelto_at
             WHERE id = :id'
        );
        $stmt->execute([
            ':estado' => $newState, ':decision' => $decision, ':comentario' => $comment,
            ':token_usado_at' => $now, ':resuelto_at' => $now, ':id' => (int)$request['id'],
        ]);

        if ($request['tipo_solicitud'] === self::TYPE_TOUR) {
            $state = $decision === self::DECISION_APPROVED ? 'APROBADA' : 'RECHAZADA';
            $update = $pdo->prepare(
                'UPDATE presupuestos_vendedores
                 SET estado_aprobacion = :estado, aprobado_at = :aprobado_at
                 WHERE id = :presupuesto_id AND solicitud_aprobacion_id = :solicitud_id'
            );
            $update->execute([
                ':estado' => $state,
                ':aprobado_at' => $decision === self::DECISION_APPROVED ? $now : null,
                ':presupuesto_id' => (int)$request['presupuesto_id'],
                ':solicitud_id' => (int)$request['id'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new DomainException('La solicitud ya no es la versión vigente de la gira.');
            }
        } elseif ($request['tipo_solicitud'] === self::TYPE_RENDITION_APPROVAL) {
            self::applyRenditionApproval($pdo, $request, $decision, $comment);
        } elseif ($decision === self::DECISION_APPROVED) {
            self::applyMonthlyException($pdo, $request);
        }

        self::log($pdo, (int)$request['id'], [
            'actor_tipo' => 'RESPONSABLE', 'actor_id' => null,
            'actor_nombre' => $request['aprobador_nombre_snapshot'],
            'actor_email' => $request['aprobador_email_snapshot'],
            'accion' => $decision === self::DECISION_APPROVED ? 'APROBAR_SOLICITUD' : 'RECHAZAR_SOLICITUD',
            'estado_anterior' => $request['estado'], 'estado_nuevo' => $newState,
            'comentario' => $comment,
        ]);
        return ['expired' => false, 'solicitud' => self::loadById($pdo, (int)$request['id'])];
    }

    public static function cancelRequest(PDO $pdo, int $requestId, array $actor, string $reason): array
    {
        self::assertTransaction($pdo);
        $request = self::loadByIdForUpdate($pdo, $requestId);
        if (in_array($request['estado'], self::FINAL_STATES, true)) {
            throw new DomainException('La solicitud ya tiene una resolución final.');
        }
        $reason = self::validateText($reason, 500, 'Indique un motivo de cancelación válido.');
        $actorId = self::positiveId($actor['id'] ?? 0, 'El usuario que cancela no es válido.');
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'UPDATE solicitudes_aprobacion
             SET estado = :estado, cancelado_at = :cancelado_at,
                 cancelado_por = :cancelado_por, motivo_cancelacion = :motivo,
                 activo = 0
             WHERE id = :id'
        );
        $stmt->execute([
            ':estado' => self::STATE_CANCELLED, ':cancelado_at' => $now,
            ':cancelado_por' => $actorId, ':motivo' => $reason, ':id' => $requestId,
        ]);
        if ($request['tipo_solicitud'] === self::TYPE_TOUR) {
            $update = $pdo->prepare(
                'UPDATE presupuestos_vendedores
                 SET estado_aprobacion = :estado, aprobado_at = NULL
                 WHERE id = :presupuesto_id AND solicitud_aprobacion_id = :solicitud_id'
            );
            $update->execute([
                ':estado' => 'RECHAZADA', ':presupuesto_id' => (int)$request['presupuesto_id'],
                ':solicitud_id' => $requestId,
            ]);
        } elseif ($request['tipo_solicitud'] === self::TYPE_RENDITION_APPROVAL) {
            $update = $pdo->prepare(
                'UPDATE rendiciones_gastos
                 SET estado = "EN_REVISION_TESORERIA",
                     notificacion_exceso_estado = "NO_APLICA"
                 WHERE id = :id AND estado = "PENDIENTE_APROBACION_RESPONSABLE"'
            );
            $update->execute([':id' => (int)$request['rendicion_id']]);
        }
        self::log($pdo, $requestId, [
            'actor_tipo' => 'TESORERIA', 'actor_id' => $actorId,
            'actor_nombre' => (string)($actor['nombre'] ?? 'Tesorería'),
            'actor_email' => $actor['email'] ?? null, 'accion' => 'CANCELAR_SOLICITUD',
            'estado_anterior' => $request['estado'], 'estado_nuevo' => self::STATE_CANCELLED,
            'comentario' => $reason,
        ]);
        return self::loadById($pdo, $requestId);
    }

    public static function getByToken(PDO $pdo, string $rawToken): array
    {
        $token = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('Token de aprobación no válido.');
        }
        $stmt = $pdo->prepare('SELECT * FROM solicitudes_aprobacion WHERE token_hash = :token_hash LIMIT 1');
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new InvalidArgumentException('Token de aprobación no válido.');
        }
        $request['token_vencido'] = strtotime((string)$request['token_expira_at']) < time();
        return $request;
    }

    private static function applyMonthlyException(PDO $pdo, array $request): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, monto_total_rendido, monto_maximo_aprobable
             FROM rendiciones_gastos
             WHERE id = :id AND solicitud_excepcion_id = :solicitud_id
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => (int)$request['rendicion_id'], ':solicitud_id' => (int)$request['id']]);
        $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rendition) {
            throw new DomainException('La solicitud ya no es la versión vigente de la rendición.');
        }
        $newMaximum = min(
            (float)$rendition['monto_total_rendido'],
            (float)$rendition['monto_maximo_aprobable'] + (float)$request['monto_solicitado']
        );
        $nonReimbursable = max(0.0, (float)$rendition['monto_total_rendido'] - $newMaximum);
        $update = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET monto_maximo_aprobable = :monto_maximo,
                 monto_exceso_no_reembolsable = :monto_no_reembolsable,
                 aplico_tope_presupuestario = :aplico_tope
             WHERE id = :id'
        );
        $update->execute([
            ':monto_maximo' => number_format($newMaximum, 2, '.', ''),
            ':monto_no_reembolsable' => number_format($nonReimbursable, 2, '.', ''),
            ':aplico_tope' => $nonReimbursable > 0 ? 1 : 0,
            ':id' => (int)$rendition['id'],
        ]);
    }

    private static function applyRenditionApproval(PDO $pdo, array $request, string $decision, ?string $comment): void
    {
        $renditionId = (int)$request['rendicion_id'];
        $stmt = $pdo->prepare('SELECT * FROM rendiciones_gastos WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $renditionId]);
        $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rendition) {
            throw new DomainException('La rendición vinculada a la solicitud no existe.');
        }

        $now = date('Y-m-d H:i:s');
        if ($decision === self::DECISION_APPROVED) {
            $approvedAmount = (float)$rendition['monto_total_rendido'];
            $stmtDocSum = $pdo->prepare(
                'SELECT COALESCE(SUM(CASE WHEN estado_item = "RECHAZADO" THEN 0 ELSE COALESCE(monto_validado, monto) END), 0)
                 FROM rendicion_documentos
                 WHERE rendicion_id = :id AND activo = 1'
            );
            $stmtDocSum->execute([':id' => $renditionId]);
            $sumValid = (float)$stmtDocSum->fetchColumn();
            if ($sumValid > 0) {
                $approvedAmount = $sumValid;
            }

            $stmtApproveDocs = $pdo->prepare(
                'UPDATE rendicion_documentos
                 SET estado_item = "APROBADO",
                     monto_validado = COALESCE(monto_validado, monto)
                 WHERE rendicion_id = :id AND activo = 1 AND estado_item = "PENDIENTE"'
            );
            $stmtApproveDocs->execute([':id' => $renditionId]);

            $stmtUpdate = $pdo->prepare(
                'UPDATE rendiciones_gastos
                 SET estado = "APROBADA",
                     monto_total_aprobado = :monto_aprobado,
                     decision_exceso = CASE WHEN monto_exceso > 0 THEN "APROBADO" ELSE decision_exceso END,
                     aprobado_exceso_at = :aprobado_at,
                     aprobado_exceso_por = :aprobador_nombre,
                     token_exceso_usado_at = :token_usado_at,
                     notificacion_exceso_estado = "ENVIADA"
                 WHERE id = :id'
            );
            $stmtUpdate->execute([
                ':monto_aprobado' => number_format($approvedAmount, 2, '.', ''),
                ':aprobado_at' => $now,
                ':aprobador_nombre' => $request['aprobador_nombre_snapshot'],
                ':token_usado_at' => $now,
                ':id' => $renditionId,
            ]);

            try {
                require_once __DIR__ . '/RendicionPlanillaPdf.php';
                RendicionPlanillaPdf::buildAndSave($pdo, $renditionId);
            } catch (Throwable $pdfEx) {
                error_log('[ApprovalWorkflowService.pdf] ' . $pdfEx->getMessage());
            }

            RendicionesService::logHistory($pdo, [
                'rendicion_id' => $renditionId,
                'actor_tipo' => 'JEFATURA',
                'actor_nombre' => $request['aprobador_nombre_snapshot'],
                'actor_email' => $request['aprobador_email_snapshot'],
                'accion' => 'APROBAR_RENDICION_RESPONSABLE',
                'estado_anterior' => $rendition['estado'],
                'estado_nuevo' => 'APROBADA',
                'comentario' => $comment,
                'metadata' => ['monto_aprobado' => $approvedAmount, 'solicitud_id' => (int)$request['id']],
            ]);
        } elseif ($decision === self::DECISION_APPROVED_CAPPED) {
            // Aprueba solo hasta el tope presupuestario; el exceso queda no reembolsable.
            $cappedAmount = (float)$rendition['monto_maximo_aprobable'];
            if ($cappedAmount <= 0) {
                // Si por algún motivo el tope es 0, usar el total rendido (misma lógica que APROBADO)
                $cappedAmount = (float)$rendition['monto_total_rendido'];
            }
            $excessNonReimb = max(0.0, (float)$rendition['monto_total_rendido'] - $cappedAmount);

            $stmtApproveDocs = $pdo->prepare(
                'UPDATE rendicion_documentos
                 SET estado_item = "APROBADO",
                     monto_validado = COALESCE(monto_validado, monto)
                 WHERE rendicion_id = :id AND activo = 1 AND estado_item = "PENDIENTE"'
            );
            $stmtApproveDocs->execute([':id' => $renditionId]);

            $stmtUpdate = $pdo->prepare(
                'UPDATE rendiciones_gastos
                 SET estado = "APROBADA",
                     monto_total_aprobado = :monto_aprobado,
                     monto_exceso_no_reembolsable = :exceso_no_reemb,
                     aplico_tope_presupuestario = 1,
                     decision_exceso = CASE WHEN monto_exceso > 0 THEN "RECHAZADO" ELSE decision_exceso END,
                     aprobado_exceso_at = :aprobado_at,
                     aprobado_exceso_por = :aprobador_nombre,
                     token_exceso_usado_at = :token_usado_at,
                     notificacion_exceso_estado = "ENVIADA"
                 WHERE id = :id'
            );
            $stmtUpdate->execute([
                ':monto_aprobado'   => number_format($cappedAmount, 2, '.', ''),
                ':exceso_no_reemb'  => number_format($excessNonReimb, 2, '.', ''),
                ':aprobado_at'      => $now,
                ':aprobador_nombre' => $request['aprobador_nombre_snapshot'],
                ':token_usado_at'   => $now,
                ':id'               => $renditionId,
            ]);

            try {
                require_once __DIR__ . '/RendicionPlanillaPdf.php';
                RendicionPlanillaPdf::buildAndSave($pdo, $renditionId);
            } catch (Throwable $pdfEx) {
                error_log('[ApprovalWorkflowService.pdf.capped] ' . $pdfEx->getMessage());
            }

            RendicionesService::logHistory($pdo, [
                'rendicion_id'   => $renditionId,
                'actor_tipo'     => 'JEFATURA',
                'actor_nombre'   => $request['aprobador_nombre_snapshot'],
                'actor_email'    => $request['aprobador_email_snapshot'],
                'accion'         => 'APROBAR_RENDICION_HASTA_TOPE',
                'estado_anterior'=> $rendition['estado'],
                'estado_nuevo'   => 'APROBADA',
                'comentario'     => $comment,
                'metadata'       => [
                    'monto_aprobado'   => $cappedAmount,
                    'exceso_no_reemb'  => $excessNonReimb,
                    'solicitud_id'     => (int)$request['id'],
                ],
            ]);
        } else {
            $stmtUpdate = $pdo->prepare(
                'UPDATE rendiciones_gastos
                 SET estado = "RECHAZADA",
                     motivo_rechazo = :motivo,
                     decision_exceso = CASE WHEN monto_exceso > 0 THEN "RECHAZADO" ELSE decision_exceso END,
                     token_exceso_usado_at = :token_usado_at
                 WHERE id = :id'
            );
            $stmtUpdate->execute([
                ':motivo' => $comment,
                ':token_usado_at' => $now,
                ':id' => $renditionId,
            ]);

            $reservedAmount = (float)$rendition['monto_total_rendido'];
            if ($reservedAmount > 0) {
                $stmtBudget = $pdo->prepare(
                    'UPDATE presupuestos_vendedores
                     SET monto_utilizado = GREATEST(0, monto_utilizado - :monto)
                     WHERE id = :id'
                );
                $stmtBudget->execute([':monto' => number_format($reservedAmount, 2, '.', ''), ':id' => (int)$rendition['presupuesto_id']]);
            }

            $stmtRejectDocs = $pdo->prepare(
                'UPDATE rendicion_documentos
                 SET estado_item = "RECHAZADO",
                     motivo_rechazo = :motivo
                 WHERE rendicion_id = :id AND activo = 1 AND estado_item = "PENDIENTE"'
            );
            $stmtRejectDocs->execute([':motivo' => $comment, ':id' => $renditionId]);

            RendicionesService::logHistory($pdo, [
                'rendicion_id' => $renditionId,
                'actor_tipo' => 'JEFATURA',
                'actor_nombre' => $request['aprobador_nombre_snapshot'],
                'actor_email' => $request['aprobador_email_snapshot'],
                'accion' => 'RECHAZAR_RENDICION_RESPONSABLE',
                'estado_anterior' => $rendition['estado'],
                'estado_nuevo' => 'RECHAZADA',
                'comentario' => $comment,
                'metadata' => ['solicitud_id' => (int)$request['id']],
            ]);
        }
    }

    private static function loadApprover(PDO $pdo, int $approverId): array
    {
        if ($approverId <= 0) {
            throw new InvalidArgumentException('Seleccione un responsable de aprobación.');
        }
        $stmt = $pdo->prepare(
            'SELECT id, nombre, cargo, email
             FROM aprobadores_rendiciones
             WHERE id = :id AND activo = :activo
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $approverId, ':activo' => 1]);
        $approver = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$approver || !filter_var($approver['email'], FILTER_VALIDATE_EMAIL)) {
            throw new DomainException('El responsable seleccionado no está activo o no tiene un correo válido.');
        }
        return $approver;
    }

    private static function loadTourBudget(PDO $pdo, int $budgetId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, monto_asignado, estado_aprobacion
             FROM presupuestos_vendedores
             WHERE id = :id AND tipo_presupuesto = :tipo AND activo = :activo
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $budgetId, ':tipo' => self::TYPE_TOUR, ':activo' => 1]);
        $budget = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$budget) {
            throw new DomainException('La gira no existe o no está activa.');
        }
        return $budget;
    }

    private static function loadRendition(PDO $pdo, int $renditionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, monto_total_rendido, monto_maximo_aprobable, monto_presupuesto_asignado,
                    monto_exceso, estado, empresa_id, vendedor_id, codigo_rendicion, presupuesto_id
             FROM rendiciones_gastos
             WHERE id = :id AND activo = :activo
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([':id' => $renditionId, ':activo' => 1]);
        $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rendition) {
            throw new DomainException('La rendición no existe o no está activa.');
        }
        return $rendition;
    }

    private static function loadMonthlyRendition(PDO $pdo, int $renditionId): array
    {
        return self::loadRendition($pdo, $renditionId);
    }

    private static function assertNoOpenRequest(PDO $pdo, string $type, ?int $budgetId, ?int $renditionId): void
    {
        $stmt = $pdo->prepare(
            'SELECT id
             FROM solicitudes_aprobacion
             WHERE tipo_solicitud = :tipo
               AND ((presupuesto_id = :presupuesto_id) OR (presupuesto_id IS NULL AND :presupuesto_id_null IS NULL))
               AND ((rendicion_id = :rendicion_id) OR (rendicion_id IS NULL AND :rendicion_id_null IS NULL))
               AND estado IN (\'PENDIENTE_ENVIO\', \'PENDIENTE_DECISION\', \'ENVIO_FALLIDO\', \'VENCIDA\')
               AND activo = :activo
             LIMIT 1 FOR UPDATE'
        );
        $stmt->execute([
            ':tipo' => $type,
            ':presupuesto_id' => $budgetId, ':presupuesto_id_null' => $budgetId,
            ':rendicion_id' => $renditionId, ':rendicion_id_null' => $renditionId,
            ':activo' => 1,
        ]);
        if ($stmt->fetchColumn()) {
            throw new DomainException('Ya existe una solicitud vigente para este registro. Reenvíela o cancélela.');
        }
    }

    private static function nextVersion(PDO $pdo, string $type, ?int $budgetId, ?int $renditionId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(solicitud_version), 0) + 1
             FROM solicitudes_aprobacion
             WHERE tipo_solicitud = :tipo
               AND ((presupuesto_id = :presupuesto_id) OR (presupuesto_id IS NULL AND :presupuesto_id_null IS NULL))
               AND ((rendicion_id = :rendicion_id) OR (rendicion_id IS NULL AND :rendicion_id_null IS NULL))'
        );
        $stmt->execute([
            ':tipo' => $type,
            ':presupuesto_id' => $budgetId, ':presupuesto_id_null' => $budgetId,
            ':rendicion_id' => $renditionId, ':rendicion_id_null' => $renditionId,
        ]);
        return (int)$stmt->fetchColumn();
    }

    private static function loadById(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM solicitudes_aprobacion WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new DomainException('La solicitud de aprobación no existe.');
        }
        return $request;
    }

    private static function loadByIdForUpdate(PDO $pdo, int $requestId): array
    {
        $stmt = $pdo->prepare('SELECT * FROM solicitudes_aprobacion WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->execute([':id' => $requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new DomainException('La solicitud de aprobación no existe.');
        }
        return $request;
    }

    private static function loadByTokenForUpdate(PDO $pdo, string $rawToken): array
    {
        $token = trim($rawToken);
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new InvalidArgumentException('Token de aprobación no válido.');
        }
        $stmt = $pdo->prepare('SELECT * FROM solicitudes_aprobacion WHERE token_hash = :token_hash LIMIT 1 FOR UPDATE');
        $stmt->execute([':token_hash' => hash('sha256', $token)]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request) {
            throw new InvalidArgumentException('Token de aprobación no válido.');
        }
        return $request;
    }

    private static function log(PDO $pdo, int $requestId, array $entry): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO solicitud_aprobacion_historial (
                solicitud_id, actor_tipo, actor_id, actor_nombre, actor_email,
                accion, estado_anterior, estado_nuevo, comentario,
                metadata_json, ip_origen, user_agent
             ) VALUES (
                :solicitud_id, :actor_tipo, :actor_id, :actor_nombre, :actor_email,
                :accion, :estado_anterior, :estado_nuevo, :comentario,
                :metadata_json, :ip_origen, :user_agent
             )'
        );
        $metadata = $entry['metadata'] ?? null;
        $stmt->execute([
            ':solicitud_id' => $requestId,
            ':actor_tipo' => $entry['actor_tipo'],
            ':actor_id' => isset($entry['actor_id']) ? (int)$entry['actor_id'] : null,
            ':actor_nombre' => substr(trim((string)$entry['actor_nombre']), 0, 150),
            ':actor_email' => isset($entry['actor_email']) ? substr(trim((string)$entry['actor_email']), 0, 190) : null,
            ':accion' => substr(trim((string)$entry['accion']), 0, 80),
            ':estado_anterior' => $entry['estado_anterior'] ?? null,
            ':estado_nuevo' => $entry['estado_nuevo'],
            ':comentario' => isset($entry['comentario']) ? self::optionalText((string)$entry['comentario'], 500) : null,
            ':metadata_json' => $metadata === null ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip_origen' => getClientIp(),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    private static function assertTransaction(PDO $pdo): void
    {
        if (!$pdo->inTransaction()) {
            throw new LogicException('El flujo de aprobación requiere una transacción activa.');
        }
    }

    private static function positiveId($value, string $message): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new InvalidArgumentException($message);
        }
        return (int)$id;
    }

    private static function money($value): string
    {
        $amount = self::moneyAllowZero($value);
        if ((float)$amount <= 0) {
            throw new InvalidArgumentException('El monto solicitado debe ser mayor que cero.');
        }
        return $amount;
    }

    private static function moneyAllowZero($value): string
    {
        if (!is_numeric($value) || (float)$value < 0) {
            throw new InvalidArgumentException('El monto no es válido.');
        }
        return number_format((float)$value, 2, '.', '');
    }

    private static function validateText(string $value, int $maxLength, string $message): string
    {
        $value = trim($value);
        if ($value === '' || self::textLength($value) > $maxLength) {
            throw new InvalidArgumentException($message);
        }
        return $value;
    }

    private static function optionalText(string $value, int $maxLength): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (self::textLength($value) > $maxLength) {
            throw new InvalidArgumentException("El texto no puede superar {$maxLength} caracteres.");
        }
        return $value;
    }

    private static function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value, $characters);
        return $count === false ? strlen($value) : $count;
    }
}
