<?php
/**
 * QA transaccional de ApprovalWorkflowService. Todos los datos se revierten.
 * Uso: php scripts/test_approval_workflow.php
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ApprovalWorkflowService.php';

$pdo = Database::getCobranzasConnection();
$passes = 0;

function check(bool $condition, string $message): void
{
    global $passes;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passes++;
    echo "PASS: {$message}\n";
}

function expectException(callable $operation, string $expectedClass, string $message): void
{
    try {
        $operation();
        check(false, $message);
    } catch (Throwable $exception) {
        check($exception instanceof $expectedClass, $message);
    }
}

function insertBudget(PDO $pdo, int $companyId, int $userId, string $type, string $key, string $amount): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO presupuestos_vendedores (
            empresa_id, vendedor_id, vendedor_nombre, vendedor_email,
            tipo_presupuesto, nombre_gira, periodo_mes, fecha_inicio, fecha_fin,
            monto_asignado, monto_utilizado, estado_aprobacion,
            justificacion_gira, periodo_clave, activo, creado_por
         ) VALUES (
            :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email,
            :tipo, :nombre_gira, :periodo, :fecha_inicio, :fecha_fin,
            :monto_asignado, :monto_utilizado, :estado_aprobacion,
            :justificacion, :periodo_clave, :activo, :creado_por
         )'
    );
    $stmt->execute([
        ':empresa_id' => $companyId, ':vendedor_id' => 987654321,
        ':vendedor_nombre' => 'QA Workflow', ':vendedor_email' => 'qa@example.invalid',
        ':tipo' => $type, ':nombre_gira' => $type === 'GIRA' ? 'Gira QA' : null,
        ':periodo' => '2099-01', ':fecha_inicio' => $type === 'GIRA' ? '2099-01-10' : null,
        ':fecha_fin' => $type === 'GIRA' ? '2099-01-15' : null,
        ':monto_asignado' => $amount, ':monto_utilizado' => '0.00',
        ':estado_aprobacion' => 'NO_APLICA', ':justificacion' => null,
        ':periodo_clave' => $key, ':activo' => 1, ':creado_por' => $userId,
    ]);
    return (int)$pdo->lastInsertId();
}

function insertRendition(PDO $pdo, int $companyId, int $budgetId, string $code, string $total, string $maximum): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, vendedor_nombre,
            presupuesto_id, periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_total_aprobado, monto_maximo_aprobable, monto_presupuesto_asignado,
            saldo_disponible_al_enviar, monto_exceso, monto_exceso_no_reembolsable,
            aplico_tope_presupuestario, estado, activo, enviada_at
         ) VALUES (
            :codigo, :empresa_id, :vendedor_id, :vendedor_nombre,
            :presupuesto_id, :periodo, :tipo, :monto_total,
            :monto_aprobado, :monto_maximo, :monto_presupuesto,
            :saldo, :monto_exceso, :monto_no_reembolsable,
            :aplico_tope, :estado, :activo, :enviada_at
         )'
    );
    $totalValue = (float)$total;
    $maximumValue = (float)$maximum;
    $stmt->execute([
        ':codigo' => $code, ':empresa_id' => $companyId, ':vendedor_id' => 987654321,
        ':vendedor_nombre' => 'QA Workflow', ':presupuesto_id' => $budgetId,
        ':periodo' => '2099-01', ':tipo' => 'MENSUAL', ':monto_total' => $total,
        ':monto_aprobado' => '0.00', ':monto_maximo' => $maximum,
        ':monto_presupuesto' => '200000.00', ':saldo' => '200000.00',
        ':monto_exceso' => number_format(max(0, $totalValue - $maximumValue), 2, '.', ''),
        ':monto_no_reembolsable' => number_format(max(0, $totalValue - $maximumValue), 2, '.', ''),
        ':aplico_tope' => $totalValue > $maximumValue ? 1 : 0,
        ':estado' => 'EN_REVISION_TESORERIA', ':activo' => 1,
        ':enviada_at' => date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function insertRawApprovalRequest(PDO $pdo, array $data): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO solicitudes_aprobacion (
            tipo_solicitud, presupuesto_id, rendicion_id, solicitud_version,
            token_version, aprobador_id, aprobador_nombre_snapshot,
            aprobador_cargo_snapshot, aprobador_email_snapshot,
            monto_base_aprobable, monto_solicitado, justificacion,
            token_hash, token_expira_at, estado, solicitado_por
         ) VALUES (
            :tipo, :presupuesto_id, :rendicion_id, :solicitud_version,
            :token_version, :aprobador_id, :nombre, :cargo, :email,
            :monto_base, :monto_solicitado, :justificacion,
            :token_hash, :token_expira, :estado, :solicitado_por
         )'
    );
    $stmt->execute([
        ':tipo' => $data['tipo'], ':presupuesto_id' => $data['presupuesto_id'] ?? null,
        ':rendicion_id' => $data['rendicion_id'] ?? null,
        ':solicitud_version' => $data['solicitud_version'] ?? 1, ':token_version' => 1,
        ':aprobador_id' => $data['aprobador_id'], ':nombre' => 'Responsable QA',
        ':cargo' => 'Gerencia QA', ':email' => 'responsable.qa@example.invalid',
        ':monto_base' => $data['monto_base'] ?? '0.00',
        ':monto_solicitado' => $data['monto_solicitado'],
        ':justificacion' => 'Validación directa de constraint.',
        ':token_hash' => $data['token_hash'], ':token_expira' => '2099-01-01 00:00:00',
        ':estado' => 'PENDIENTE_ENVIO', ':solicitado_por' => $data['solicitado_por'],
    ]);
}

try {
    $pdo->beginTransaction();
    $companyId = (int)$pdo->query('SELECT id FROM empresas ORDER BY id LIMIT 1')->fetchColumn();
    $userId = (int)$pdo->query('SELECT id FROM usuarios WHERE activo = 1 ORDER BY id LIMIT 1')->fetchColumn();
    check($companyId > 0 && $userId > 0, 'prerrequisitos locales disponibles');

    $approver = $pdo->query('SELECT id FROM aprobadores_rendiciones ORDER BY id LIMIT 1 FOR UPDATE')->fetch(PDO::FETCH_ASSOC);
    if ($approver) {
        $approverId = (int)$approver['id'];
        $stmt = $pdo->prepare('UPDATE aprobadores_rendiciones SET activo = :activo WHERE id = :id');
        $stmt->execute([':activo' => 1, ':id' => $approverId]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO aprobadores_rendiciones (orden, nombre, cargo, email, activo, actualizado_por)
             VALUES (:orden, :nombre, :cargo, :email, :activo, :usuario_id)'
        );
        $stmt->execute([
            ':orden' => 1, ':nombre' => 'Responsable QA', ':cargo' => 'Gerencia QA',
            ':email' => 'responsable.qa@example.invalid', ':activo' => 1, ':usuario_id' => $userId,
        ]);
        $approverId = (int)$pdo->lastInsertId();
    }

    $suffix = bin2hex(random_bytes(4));

    $invalidTourId = insertBudget($pdo, $companyId, $userId, 'GIRA', 'QA-GIRA-INVALIDA-' . $suffix, '100000.00');
    expectException(
        fn() => ApprovalWorkflowService::createRequest($pdo, [
            'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
            'presupuesto_id' => $invalidTourId, 'aprobador_id' => $approverId,
            'solicitado_por' => $userId, 'justificacion' => '',
        ]),
        InvalidArgumentException::class,
        'gira sin justificación es rechazada'
    );
    expectException(
        fn() => ApprovalWorkflowService::createRequest($pdo, [
            'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
            'presupuesto_id' => $invalidTourId, 'aprobador_id' => 999999999,
            'solicitado_por' => $userId, 'justificacion' => 'Solicitud con responsable inexistente.',
        ]),
        DomainException::class,
        'responsable inexistente o inactivo es rechazado'
    );

    $tourId = insertBudget($pdo, $companyId, $userId, 'GIRA', 'QA-GIRA-' . $suffix, '350000.00');
    $tourRequest = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
        'presupuesto_id' => $tourId, 'aprobador_id' => $approverId,
        'solicitado_por' => $userId, 'justificacion' => 'Visita comercial regional para QA.',
        'actor_nombre' => 'Usuario QA', 'actor_email' => 'qa@example.invalid',
    ]);
    check(strlen($tourRequest['raw_token']) === 64, 'token crudo seguro generado');
    check(!str_contains(json_encode($tourRequest['solicitud']), $tourRequest['raw_token']), 'token crudo no persistido');
    expectException(
        fn() => insertRawApprovalRequest($pdo, [
            'tipo' => 'GIRA', 'presupuesto_id' => null, 'rendicion_id' => null,
            'monto_solicitado' => '1000.00', 'token_hash' => hash('sha256', random_bytes(32)),
            'aprobador_id' => $approverId, 'solicitado_por' => $userId,
        ]),
        PDOException::class,
        'CHECK impide solicitudes sin objetivo'
    );
    expectException(
        fn() => insertRawApprovalRequest($pdo, [
            'tipo' => 'GIRA', 'presupuesto_id' => $invalidTourId,
            'monto_solicitado' => '0.00', 'token_hash' => hash('sha256', random_bytes(32)),
            'aprobador_id' => $approverId, 'solicitado_por' => $userId,
        ]),
        PDOException::class,
        'CHECK impide solicitudes de monto cero'
    );
    expectException(
        fn() => insertRawApprovalRequest($pdo, [
            'tipo' => 'GIRA', 'presupuesto_id' => $invalidTourId,
            'monto_solicitado' => '1000.00',
            'token_hash' => (string)$tourRequest['solicitud']['token_hash'],
            'aprobador_id' => $approverId, 'solicitado_por' => $userId,
        ]),
        PDOException::class,
        'índice único impide reutilizar un hash de token'
    );
    $budgetState = $pdo->prepare('SELECT estado_aprobacion FROM presupuestos_vendedores WHERE id = :id');
    $budgetState->execute([':id' => $tourId]);
    check($budgetState->fetchColumn() === 'PENDIENTE', 'gira queda pendiente antes del correo');
    expectException(
        fn() => ApprovalWorkflowService::createRequest($pdo, [
            'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
            'presupuesto_id' => $tourId, 'aprobador_id' => $approverId,
            'solicitado_por' => $userId, 'justificacion' => 'Intento duplicado.',
        ]),
        DomainException::class,
        'segunda solicitud abierta para la misma gira es rechazada'
    );
    expectException(
        fn() => ApprovalWorkflowService::resolveByToken(
            $pdo,
            $tourRequest['raw_token'],
            ApprovalWorkflowService::DECISION_APPROVED
        ),
        DomainException::class,
        'token no permite decidir antes de registrar correo enviado'
    );

    ApprovalWorkflowService::markEmailResult($pdo, (int)$tourRequest['solicitud']['id'], true);
    expectException(
        fn() => ApprovalWorkflowService::markEmailResult($pdo, (int)$tourRequest['solicitud']['id'], true),
        DomainException::class,
        'resultado de correo no puede registrarse dos veces sin reenvío'
    );
    $tourDecision = ApprovalWorkflowService::resolveByToken(
        $pdo,
        $tourRequest['raw_token'],
        ApprovalWorkflowService::DECISION_APPROVED
    );
    check($tourDecision['solicitud']['estado'] === 'APROBADA', 'responsable aprueba fondo de gira');
    $budgetState->execute([':id' => $tourId]);
    check($budgetState->fetchColumn() === 'APROBADA', 'aprobación sincroniza la gira');
    try {
        ApprovalWorkflowService::resolveByToken($pdo, $tourRequest['raw_token'], ApprovalWorkflowService::DECISION_APPROVED);
        check(false, 'token de uso único');
    } catch (DomainException $exception) {
        check(true, 'token de uso único');
    }

    $monthlyBudgetId = insertBudget($pdo, $companyId, $userId, 'MENSUAL', 'QA-MENSUAL-' . $suffix, '200000.00');
    $renditionId = insertRendition($pdo, $companyId, $monthlyBudgetId, 'RND-QA-' . strtoupper($suffix), '230000.00', '200000.00');
    expectException(
        fn() => ApprovalWorkflowService::createRequest($pdo, [
            'tipo_solicitud' => ApprovalWorkflowService::TYPE_MONTHLY_EXCEPTION,
            'rendicion_id' => $renditionId, 'aprobador_id' => $approverId,
            'solicitado_por' => $userId, 'monto_solicitado' => '30000.01',
            'justificacion' => 'Intento por encima del exceso real.',
        ]),
        DomainException::class,
        'excepción mayor que el exceso disponible es rechazada'
    );
    $exceptionRequest = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_MONTHLY_EXCEPTION,
        'rendicion_id' => $renditionId, 'aprobador_id' => $approverId,
        'solicitado_por' => $userId, 'monto_solicitado' => '30000.00',
        'justificacion' => 'Excepción mensual controlada por QA.', 'actor_nombre' => 'Usuario QA',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$exceptionRequest['solicitud']['id'], true);
    ApprovalWorkflowService::resolveByToken(
        $pdo,
        $exceptionRequest['raw_token'],
        ApprovalWorkflowService::DECISION_APPROVED
    );
    $stmt = $pdo->prepare('SELECT monto_maximo_aprobable, monto_exceso_no_reembolsable FROM rendiciones_gastos WHERE id = :id');
    $stmt->execute([':id' => $renditionId]);
    $amounts = $stmt->fetch(PDO::FETCH_ASSOC);
    check((float)$amounts['monto_maximo_aprobable'] === 230000.0, 'excepción aprobada amplía sólo el máximo pagable');
    check((float)$amounts['monto_exceso_no_reembolsable'] === 0.0, 'exceso autorizado deja cero no reembolsable');

    $rejectedId = insertRendition($pdo, $companyId, $monthlyBudgetId, 'RND-QAR-' . strtoupper($suffix), '240000.00', '200000.00');
    $rejectedRequest = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_MONTHLY_EXCEPTION,
        'rendicion_id' => $rejectedId, 'aprobador_id' => $approverId,
        'solicitado_por' => $userId, 'monto_solicitado' => '40000.00',
        'justificacion' => 'Excepción que será rechazada en QA.', 'actor_nombre' => 'Usuario QA',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$rejectedRequest['solicitud']['id'], true);
    expectException(
        fn() => ApprovalWorkflowService::resolveByToken(
            $pdo,
            $rejectedRequest['raw_token'],
            ApprovalWorkflowService::DECISION_REJECTED
        ),
        InvalidArgumentException::class,
        'rechazo sin motivo es rechazado'
    );
    ApprovalWorkflowService::resolveByToken(
        $pdo,
        $rejectedRequest['raw_token'],
        ApprovalWorkflowService::DECISION_REJECTED,
        'El gasto extraordinario no corresponde.'
    );
    $stmt = $pdo->prepare('SELECT estado, monto_maximo_aprobable FROM rendiciones_gastos WHERE id = :id');
    $stmt->execute([':id' => $rejectedId]);
    $rejected = $stmt->fetch(PDO::FETCH_ASSOC);
    check($rejected['estado'] === 'EN_REVISION_TESORERIA', 'rechazar excepción no rechaza la rendición base');
    check((float)$rejected['monto_maximo_aprobable'] === 200000.0, 'rechazo conserva el tope ordinario');

    $tourResendId = insertBudget($pdo, $companyId, $userId, 'GIRA', 'QA-GIRA-REENVIO-' . $suffix, '150000.00');
    $resend = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
        'presupuesto_id' => $tourResendId, 'aprobador_id' => $approverId,
        'solicitado_por' => $userId, 'justificacion' => 'Prueba de reenvío y cancelación.',
        'actor_nombre' => 'Usuario QA',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$resend['solicitud']['id'], false, 'SMTP controlado no disponible');
    $rotated = ApprovalWorkflowService::rotateToken(
        $pdo,
        (int)$resend['solicitud']['id'],
        $approverId,
        ['id' => $userId, 'nombre' => 'Usuario QA']
    );
    check($rotated['raw_token'] !== $resend['raw_token'], 'reenvío rota e invalida el token anterior');
    expectException(
        fn() => ApprovalWorkflowService::resolveByToken(
            $pdo,
            $resend['raw_token'],
            ApprovalWorkflowService::DECISION_APPROVED
        ),
        InvalidArgumentException::class,
        'token anterior queda inválido después de rotación'
    );
    $cancelled = ApprovalWorkflowService::cancelRequest(
        $pdo,
        (int)$resend['solicitud']['id'],
        ['id' => $userId, 'nombre' => 'Usuario QA'],
        'Corrección administrativa de la gira.'
    );
    check($cancelled['estado'] === 'CANCELADA' && !(bool)$cancelled['activo'], 'cancelación es lógica y auditable');
    expectException(
        fn() => ApprovalWorkflowService::resolveByToken(
            $pdo,
            $rotated['raw_token'],
            ApprovalWorkflowService::DECISION_APPROVED
        ),
        DomainException::class,
        'solicitud cancelada no admite decisión'
    );

    $history = $pdo->prepare('SELECT COUNT(*) FROM solicitud_aprobacion_historial WHERE solicitud_id = :id');
    $history->execute([':id' => (int)$resend['solicitud']['id']]);
    check((int)$history->fetchColumn() === 4, 'historial registra creación, fallo, reenvío y cancelación');

    $expiredTourId = insertBudget($pdo, $companyId, $userId, 'GIRA', 'QA-GIRA-VENCIDA-' . $suffix, '180000.00');
    $expiredRequest = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
        'presupuesto_id' => $expiredTourId, 'aprobador_id' => $approverId,
        'solicitado_por' => $userId, 'justificacion' => 'Prueba controlada de expiración.',
        'actor_nombre' => 'Usuario QA',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$expiredRequest['solicitud']['id'], true);
    $stmt = $pdo->prepare('UPDATE solicitudes_aprobacion SET token_expira_at = :expira WHERE id = :id');
    $stmt->execute([':expira' => '2000-01-01 00:00:00', ':id' => (int)$expiredRequest['solicitud']['id']]);
    $expiredResult = ApprovalWorkflowService::resolveByToken(
        $pdo,
        $expiredRequest['raw_token'],
        ApprovalWorkflowService::DECISION_APPROVED
    );
    check($expiredResult['expired'] === true && $expiredResult['solicitud']['estado'] === 'VENCIDA', 'token vencido se registra sin ejecutar decisión');
    $renewed = ApprovalWorkflowService::rotateToken(
        $pdo,
        (int)$expiredRequest['solicitud']['id'],
        $approverId,
        ['id' => $userId, 'nombre' => 'Usuario QA']
    );
    check($renewed['solicitud']['estado'] === 'PENDIENTE_ENVIO' && (int)$renewed['solicitud']['token_version'] === 2, 'solicitud vencida puede reenviarse con nueva versión de token');

    $failedTourId = insertBudget($pdo, $companyId, $userId, 'GIRA', 'QA-GIRA-FALLO-' . $suffix, '125000.00');
    $failedRequest = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_TOUR,
        'presupuesto_id' => $failedTourId, 'aprobador_id' => $approverId,
        'solicitado_por' => $userId, 'justificacion' => 'Fallo SMTP controlado para el Dashboard.',
        'actor_nombre' => 'Usuario QA',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$failedRequest['solicitud']['id'], false, 'SMTP QA intencionalmente no disponible');

    $stmt = $pdo->prepare(
        'UPDATE solicitudes_aprobacion
         SET correo_enviado_at = DATE_SUB(resuelto_at, INTERVAL :minutos MINUTE)
         WHERE id IN (:gira_aprobada, :excepcion_aprobada, :excepcion_rechazada)'
    );
    $stmt->execute([
        ':minutos' => 90,
        ':gira_aprobada' => (int)$tourRequest['solicitud']['id'],
        ':excepcion_aprobada' => (int)$exceptionRequest['solicitud']['id'],
        ':excepcion_rechazada' => (int)$rejectedRequest['solicitud']['id'],
    ]);
    $dashboardStatement = $pdo->prepare(
        'SELECT COUNT(id) AS total,
                SUM(CASE WHEN estado IN (:pendiente_envio, :pendiente_decision) AND activo = :activo THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN estado = :envio_fallido AND activo = :activo_fallo THEN 1 ELSE 0 END) AS correos_fallidos,
                SUM(CASE WHEN estado = :aprobada THEN 1 ELSE 0 END) AS aprobadas,
                SUM(CASE WHEN estado = :rechazada THEN 1 ELSE 0 END) AS rechazadas,
                SUM(CASE WHEN estado = :cancelada THEN 1 ELSE 0 END) AS canceladas,
                AVG(CASE WHEN resuelto_at IS NOT NULL
                         THEN TIMESTAMPDIFF(MINUTE, COALESCE(correo_enviado_at, created_at), resuelto_at) / 60
                         ELSE NULL END) AS horas_respuesta
         FROM solicitudes_aprobacion
         WHERE id IN (:id_1, :id_2, :id_3, :id_4, :id_5, :id_6)'
    );
    $dashboardStatement->execute([
        ':pendiente_envio' => 'PENDIENTE_ENVIO', ':pendiente_decision' => 'PENDIENTE_DECISION', ':activo' => 1,
        ':envio_fallido' => 'ENVIO_FALLIDO', ':activo_fallo' => 1,
        ':aprobada' => 'APROBADA', ':rechazada' => 'RECHAZADA', ':cancelada' => 'CANCELADA',
        ':id_1' => (int)$tourRequest['solicitud']['id'],
        ':id_2' => (int)$exceptionRequest['solicitud']['id'],
        ':id_3' => (int)$rejectedRequest['solicitud']['id'],
        ':id_4' => (int)$resend['solicitud']['id'],
        ':id_5' => (int)$expiredRequest['solicitud']['id'],
        ':id_6' => (int)$failedRequest['solicitud']['id'],
    ]);
    $dashboard = $dashboardStatement->fetch(PDO::FETCH_ASSOC);
    check((int)$dashboard['total'] === 6, 'dashboard consolida todas las versiones de solicitud del escenario QA');
    check((int)$dashboard['pendientes'] === 1, 'dashboard separa solicitudes pendientes activas');
    check((int)$dashboard['correos_fallidos'] === 1, 'dashboard identifica correos fallidos que requieren reenvío');
    check((int)$dashboard['aprobadas'] === 2 && (int)$dashboard['rechazadas'] === 1, 'dashboard separa decisiones aprobadas y rechazadas');
    check((int)$dashboard['canceladas'] === 1, 'dashboard conserva cancelaciones en la fricción operativa');
    check((float)$dashboard['horas_respuesta'] === 1.5, 'dashboard calcula tiempo promedio desde envío hasta decisión');

    $pdo->rollBack();
    echo "\nRESULTADO: {$passes} comprobaciones PASS; ROLLBACK completado.\n";
    exit(0);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
