<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';
require_once __DIR__ . '/../../services/MailService.php';

RendicionesService::requireMethod('POST');

$pdo = null;
try {
    startSecureSession();
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();
    $rawToken = strtolower(trim((string)($input['token'] ?? '')));
    $decision = strtoupper(trim((string)($input['decision'] ?? '')));
    $comment = trim((string)($input['comentario'] ?? ''));
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken) || !in_array($decision, RendicionesService::DECISIONES_EXCESO, true)) {
        throw new InvalidArgumentException('Token o decisión no válidos.');
    }

    $pdo = Database::getCobranzasConnection();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'SELECT r.*, p.monto_utilizado
         FROM rendiciones_gastos r
         INNER JOIN presupuestos_vendedores p ON p.id = r.presupuesto_id
         WHERE r.token_aprobacion_exceso_hash = :token_hash
         LIMIT 1
         FOR UPDATE'
    );
    $stmt->execute([':token_hash' => hash('sha256', $rawToken)]);
    $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rendition) {
        throw new DomainException('El enlace de aprobación no es válido.');
    }
    if ($rendition['estado'] !== 'PENDIENTE_APROBACION_EXCESO' || $rendition['token_exceso_usado_at'] !== null) {
        throw new DomainException('Este enlace ya fue utilizado o la rendición fue resuelta.');
    }
    $stmtExpiration = $pdo->prepare('SELECT NOW() > :expires_at');
    $stmtExpiration->execute([':expires_at' => $rendition['token_exceso_expira']]);
    if (!$rendition['token_exceso_expira'] || (bool)$stmtExpiration->fetchColumn()) {
        $pdo->rollBack();
        RendicionesService::jsonResponse(false, ['message' => 'El enlace de aprobación expiró.'], 410);
    }

    $nextState = $decision === 'APROBADO' ? 'EN_REVISION_TESORERIA' : 'RECHAZADA';
    RendicionesService::assertTransition($rendition['estado'], $nextState);
    $stmtUpdate = $pdo->prepare(
        'UPDATE rendiciones_gastos
         SET token_exceso_usado_at = NOW(), decision_exceso = :decision,
             aprobado_exceso_at = NOW(), aprobado_exceso_por = :aprobado_por,
             estado = :estado, motivo_rechazo = :motivo_rechazo
         WHERE id = :id AND estado = :estado_actual AND token_exceso_usado_at IS NULL'
    );
    $stmtUpdate->execute([
        ':decision' => $decision,
        ':aprobado_por' => RENDICIONES_APPROVER_NAME,
        ':estado' => $nextState,
        ':motivo_rechazo' => $decision === 'RECHAZADO' ? ($comment !== '' ? mb_substr($comment, 0, 500) : 'Exceso rechazado por jefatura.') : null,
        ':id' => (int)$rendition['id'],
        ':estado_actual' => 'PENDIENTE_APROBACION_EXCESO',
    ]);
    if ($stmtUpdate->rowCount() !== 1) {
        throw new RuntimeException('La decisión no pudo registrarse de forma atómica.');
    }

    if ($decision === 'RECHAZADO') {
        $stmtBudget = $pdo->prepare(
            'UPDATE presupuestos_vendedores
             SET monto_utilizado = GREATEST(0, monto_utilizado - :monto)
             WHERE id = :id'
        );
        $stmtBudget->execute([':monto' => $rendition['monto_total_rendido'], ':id' => (int)$rendition['presupuesto_id']]);
        $stmtItems = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET estado_item = :estado, motivo_rechazo = :motivo
             WHERE rendicion_id = :rendicion_id AND estado_item = :estado_actual'
        );
        $stmtItems->execute([
            ':estado' => 'RECHAZADO',
            ':motivo' => $comment !== '' ? mb_substr($comment, 0, 500) : 'Exceso rechazado por jefatura.',
            ':rendicion_id' => (int)$rendition['id'],
            ':estado_actual' => 'PENDIENTE',
        ]);
    }

    RendicionesService::logHistory($pdo, [
        'rendicion_id' => (int)$rendition['id'],
        'actor_tipo' => 'JEFATURA',
        'actor_nombre' => RENDICIONES_APPROVER_NAME,
        'actor_email' => RENDICIONES_APPROVER_EMAIL ?: null,
        'accion' => $decision === 'APROBADO' ? 'APROBAR_EXCESO' : 'RECHAZAR_EXCESO',
        'estado_anterior' => $rendition['estado'],
        'estado_nuevo' => $nextState,
        'comentario' => $comment !== '' ? mb_substr($comment, 0, 1000) : null,
        'metadata' => ['monto_exceso' => (float)$rendition['monto_exceso']],
    ]);
    $pdo->commit();

    MailService::notificarDecisionExcesoRendicion($rendition, $decision);
    RendicionesService::jsonResponse(true, [
        'message' => $decision === 'APROBADO' ? 'Exceso aprobado correctamente.' : 'Exceso rechazado correctamente.',
        'data' => ['rendicion_id' => (int)$rendition['id'], 'estado' => $nextState],
    ]);
} catch (InvalidArgumentException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[rendiciones.aprobar_exceso] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible registrar la decisión.'], 500);
}
