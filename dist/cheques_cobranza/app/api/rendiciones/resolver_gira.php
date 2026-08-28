<?php
/**
 * resolver_gira.php — Endpoint público de resolución de aprobación de gira.
 *
 * Recibe POST con { token, decision, comentario? }.
 * No requiere sesión: el token SHA-256 es el único mecanismo de autenticación.
 * Válido solo mientras el token no haya sido usado y no haya vencido.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/RendicionesService.php';
require_once __DIR__ . '/../../services/MailService.php';

RendicionesService::requireMethod('POST');

$pdo = null;
try {
    $input    = RendicionesService::readJsonBody();
    $rawToken = strtolower(trim((string)($input['token'] ?? '')));
    $decision = strtoupper(trim((string)($input['decision'] ?? '')));
    $comment  = mb_substr(trim((string)($input['comentario'] ?? '')), 0, 500);

    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken) || !in_array($decision, ['APROBADA', 'RECHAZADA'], true)) {
        throw new InvalidArgumentException('Token o decisión no válidos.');
    }
    if ($decision === 'RECHAZADA' && $comment === '') {
        throw new InvalidArgumentException('Indique el motivo del rechazo.');
    }

    $pdo = Database::getCobranzasConnection();
    $pdo->beginTransaction();

    // Recuperar solicitud (FOR UPDATE para evitar doble resolución)
    $stmtSolicitud = $pdo->prepare(
        'SELECT sa.*, p.id AS pv_id, p.nombre_gira, p.vendedor_nombre, p.vendedor_email,
                p.monto_asignado, p.empresa_id, p.vendedor_id, p.activo AS pv_activo
         FROM solicitudes_aprobacion sa
         INNER JOIN presupuestos_vendedores p ON p.id = sa.presupuesto_id
         WHERE sa.token_hash = :token_hash
           AND sa.tipo_solicitud = :tipo
           AND sa.activo = :activo
         LIMIT 1
         FOR UPDATE'
    );
    $stmtSolicitud->execute([
        ':token_hash' => hash('sha256', $rawToken),
        ':tipo'       => 'GIRA',
        ':activo'     => 1,
    ]);
    $solicitud = $stmtSolicitud->fetch(PDO::FETCH_ASSOC);
    if (!$solicitud) {
        throw new DomainException('El enlace de aprobación no es válido.');
    }
    if ($solicitud['token_usado_at'] !== null || !in_array($solicitud['estado'], ['PENDIENTE_DECISION', 'PENDIENTE_ENVIO'], true)) {
        throw new DomainException('Este enlace ya fue utilizado o la solicitud fue resuelta.');
    }

    // Verificar expiración
    $stmtExpiry = $pdo->prepare('SELECT NOW() > :expira');
    $stmtExpiry->execute([':expira' => $solicitud['token_expira_at']]);
    if (!$solicitud['token_expira_at'] || (bool)$stmtExpiry->fetchColumn()) {
        $pdo->rollBack();
        RendicionesService::jsonResponse(false, ['message' => 'El enlace de aprobación ha expirado.'], 410);
    }

    $approverName = trim((string)($solicitud['aprobador_nombre_snapshot'] ?? ''));

    // Actualizar solicitud
    $pdo->prepare(
        'UPDATE solicitudes_aprobacion
         SET estado = :estado, decision = :decision, resuelto_at = NOW(),
             token_usado_at = NOW(), comentario_decision = :comentario
         WHERE id = :id AND token_usado_at IS NULL'
    )->execute([
        ':estado'    => $decision,
        ':decision'  => $decision,
        ':comentario' => $comment !== '' ? $comment : null,
        ':id'        => (int)$solicitud['id'],
    ]);

    // Actualizar presupuesto
    $nuevoEstado = $decision === 'APROBADA' ? 'APROBADA' : 'RECHAZADA';
    $aprobadoAt  = $decision === 'APROBADA' ? date('Y-m-d H:i:s') : null;
    $pdo->prepare(
        'UPDATE presupuestos_vendedores
         SET estado_aprobacion = :estado, aprobado_at = :aprobado_at
         WHERE id = :id AND activo = :activo'
    )->execute([
        ':estado'     => $nuevoEstado,
        ':aprobado_at' => $aprobadoAt,
        ':id'         => (int)$solicitud['pv_id'],
        ':activo'     => 1,
    ]);

    // Historial en solicitud_aprobacion_historial
    $pdo->prepare(
        'INSERT INTO solicitud_aprobacion_historial
         (solicitud_id, actor_tipo, actor_nombre, actor_email, accion, estado_anterior, estado_nuevo, comentario, ip_origen, user_agent)
         VALUES (:sid, :tipo, :nombre, :email, :accion, :ant, :nvo, :comentario, :ip, :ua)'
    )->execute([
        ':sid'      => (int)$solicitud['id'],
        ':tipo'     => 'RESPONSABLE',
        ':nombre'   => $approverName,
        ':email'    => (string)($solicitud['aprobador_email_snapshot'] ?? ''),
        ':accion'   => 'RESOLVER_GIRA',
        ':ant'      => $solicitud['estado'],
        ':nvo'      => $decision,
        ':comentario' => $comment !== '' ? $comment : null,
        ':ip'       => $_SERVER['REMOTE_ADDR'] ?? null,
        ':ua'       => isset($_SERVER['HTTP_USER_AGENT']) ? mb_substr($_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
    ]);

    $pdo->commit();

    // Notificar al vendedor (fuera de transacción; el vendedor no debe saber del proceso de aprobación
    // hasta que Tesorería lo habilite explícitamente — según decisión de negocio.
    // Por ahora el correo queda en silencio; Tesorería notifica manualmente si lo considera.)
    // MailService::notificarDecisionGira([...], $decision);

    RendicionesService::jsonResponse(true, [
        'message' => 'Decisión registrada correctamente.',
        'data'    => [
            'decision'       => $decision,
            'aprobador'      => $approverName,
            'gira'           => $solicitud['nombre_gira'],
            'estado_nuevo'   => $nuevoEstado,
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
