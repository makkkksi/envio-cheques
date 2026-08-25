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

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $admin = requirePermission($pdo, 'rendiciones.manage');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $companyId = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT) ?: 0;
        $sellerId = filter_input(INPUT_GET, 'vendedor_id', FILTER_VALIDATE_INT) ?: 0;
        $stmt = $pdo->prepare(
            'SELECT p.*, e.nombre AS empresa_nombre,
                    (p.monto_asignado - p.monto_utilizado) AS saldo_disponible
             FROM presupuestos_vendedores p
             INNER JOIN empresas e ON e.id = p.empresa_id
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
    if (!in_array($action, ['CREAR', 'ACTUALIZAR', 'DESACTIVAR'], true)) {
        throw new InvalidArgumentException('Acción de presupuesto no válida.');
    }

    $pdo->beginTransaction();
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

    $budgetId = $action === 'ACTUALIZAR' ? filter_var($input['id'] ?? null, FILTER_VALIDATE_INT) : null;
    $companyId = filter_var($input['empresa_id'] ?? null, FILTER_VALIDATE_INT);
    $sellerId = filter_var($input['vendedor_id'] ?? null, FILTER_VALIDATE_INT);
    $type = strtoupper(trim((string)($input['tipo_presupuesto'] ?? '')));
    $period = RendicionesService::validatePeriod(trim((string)($input['periodo_mes'] ?? '')));
    $tourName = trim((string)($input['nombre_gira'] ?? '')) ?: null;
    $startDate = trim((string)($input['fecha_inicio'] ?? '')) ?: null;
    $endDate = trim((string)($input['fecha_fin'] ?? '')) ?: null;
    $amount = RendicionesService::normalizeMoney($input['monto_asignado'] ?? null);
    if (!$companyId || !$sellerId || !in_array($type, RendicionesService::TIPOS_PRESUPUESTO, true)) {
        throw new InvalidArgumentException('Empresa, vendedor y tipo de presupuesto son obligatorios.');
    }
    if ($type === 'MENSUAL') {
        $tourName = null;
        $startDate = null;
        $endDate = null;
    }
    $key = RendicionesService::createBudgetKey($companyId, $sellerId, $type, $period, $tourName, $startDate, $endDate);
    $erpSeller = ErpSellerDirectoryService::findByCompanyAndId($pdo, (int)$companyId, (int)$sellerId);
    if (!$erpSeller) {
        throw new InvalidArgumentException('El vendedor seleccionado no existe en el ERP de esa empresa.');
    }
    $name = $erpSeller['vendedor_nombre'];
    $email = $erpSeller['vendedor_email'];

    if ($action === 'CREAR') {
        $stmtInsert = $pdo->prepare(
            'INSERT INTO presupuestos_vendedores (
                empresa_id, vendedor_id, vendedor_nombre, vendedor_email,
                tipo_presupuesto, nombre_gira, periodo_mes, fecha_inicio,
                fecha_fin, monto_asignado, monto_utilizado, periodo_clave,
                activo, creado_por
             ) VALUES (
                :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email,
                :tipo_presupuesto, :nombre_gira, :periodo_mes, :fecha_inicio,
                :fecha_fin, :monto_asignado, :monto_utilizado, :periodo_clave,
                :activo, :creado_por
             )'
        );
        $stmtInsert->execute([
            ':empresa_id' => $companyId,
            ':vendedor_id' => $sellerId,
            ':vendedor_nombre' => substr($name, 0, 150),
            ':vendedor_email' => $email !== null ? substr($email, 0, 150) : null,
            ':tipo_presupuesto' => $type,
            ':nombre_gira' => $tourName,
            ':periodo_mes' => $period,
            ':fecha_inicio' => $startDate,
            ':fecha_fin' => $endDate,
            ':monto_asignado' => $amount,
            ':monto_utilizado' => '0.00',
            ':periodo_clave' => $key,
            ':activo' => 1,
            ':creado_por' => (int)$admin['id'],
        ]);
        $budgetId = (int)$pdo->lastInsertId();
        $auditAction = 'RENDICION_PRESUPUESTO_CREADO';
    } else {
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
            ':empresa_id' => $companyId,
            ':vendedor_id' => $sellerId,
            ':vendedor_nombre' => substr($name, 0, 150),
            ':vendedor_email' => $email !== null ? substr($email, 0, 150) : null,
            ':tipo_presupuesto' => $type,
            ':nombre_gira' => $tourName,
            ':periodo_mes' => $period,
            ':fecha_inicio' => $startDate,
            ':fecha_fin' => $endDate,
            ':monto_asignado' => $amount,
            ':periodo_clave' => $key,
            ':id' => $budgetId,
            ':activo' => 1,
        ]);
        $auditAction = 'RENDICION_PRESUPUESTO_ACTUALIZADO';
    }

    AuditService::log($pdo, (int)$admin['id'], $admin['email'], $auditAction, json_encode(['presupuesto_id' => $budgetId, 'monto_asignado' => $amount]));
    $pdo->commit();
    RendicionesService::jsonResponse(true, ['message' => 'Presupuesto guardado correctamente.', 'data' => ['presupuesto_id' => $budgetId]]);
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
