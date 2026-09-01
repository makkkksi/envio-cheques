<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/RendicionesService.php';
require_once __DIR__ . '/../../services/ApprovalWorkflowService.php';
require_once __DIR__ . '/../../services/MailService.php';

RendicionesService::requireMethod('POST');

$pdo = null;
try {
    $input = RendicionesService::readJsonBody();
    $rawToken = strtolower(trim((string)($input['token'] ?? '')));
    $decision = strtoupper(trim((string)($input['decision'] ?? '')));
    $comment = RendicionesService::truncateText(trim((string)($input['comentario'] ?? '')), 500);
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        throw new InvalidArgumentException('Token de aprobación no válido.');
    }

    $pdo = Database::getCobranzasConnection();
    $pdo->beginTransaction();
    $result = ApprovalWorkflowService::resolveByToken($pdo, $rawToken, $decision, $comment);
    $request = $result['solicitud'];
    if (($request['tipo_solicitud'] ?? '') !== ApprovalWorkflowService::TYPE_TOUR) {
        throw new DomainException('El enlace no corresponde a una aprobación de gira.');
    }
    if (!empty($result['expired'])) {
        $pdo->commit();
        RendicionesService::jsonResponse(false, ['message' => 'El enlace de aprobación ha expirado. Solicite a Tesorería un reenvío.'], 410);
    }

    $stmtContext = $pdo->prepare(
        'SELECT p.nombre_gira, p.vendedor_nombre, p.monto_asignado,
                sa.aprobador_nombre_snapshot, sa.aprobador_cargo_snapshot
         FROM presupuestos_vendedores p
         INNER JOIN solicitudes_aprobacion sa ON sa.id = :solicitud_id
         WHERE p.id = :presupuesto_id
         LIMIT 1'
    );
    $stmtContext->execute([
        ':solicitud_id' => (int)$request['id'],
        ':presupuesto_id' => (int)$request['presupuesto_id'],
    ]);
    $context = $stmtContext->fetch(PDO::FETCH_ASSOC) ?: [];
    $pdo->commit();

    $treasuryNotified = false;
    try {
        $treasuryNotified = MailService::notificarDecisionGiraTesoreria($pdo, $context, $decision);
    } catch (Throwable $mailException) {
        error_log('[rendiciones.resolver_gira.tesoreria_mail] ' . $mailException->getMessage());
    }

    RendicionesService::jsonResponse(true, [
        'message' => 'Decisión registrada correctamente.',
        'data' => [
            'decision' => $decision,
            'aprobador' => (string)($request['aprobador_nombre_snapshot'] ?? ''),
            'gira' => (string)($context['nombre_gira'] ?? ''),
            'estado_nuevo' => $decision,
            'tesoreria_notificada' => $treasuryNotified,
        ],
    ]);
} catch (InvalidArgumentException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (DomainException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 409);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[rendiciones.resolver_gira] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible registrar la decisión.'], 500);
}
