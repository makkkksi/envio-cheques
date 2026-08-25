<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/RendicionesService.php';

RendicionesService::requireMethod('GET');

try {
    $pdo = Database::getCobranzasConnection();
    requirePermission($pdo, 'rendiciones.view');

    $allowedStates = ['TODOS', 'PENDIENTE_APROBACION_EXCESO', 'EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS', 'APROBADA', 'APROBADA_PARCIAL', 'RECHAZADA', 'PAGADA'];
    $allowedTypes = ['TODOS', 'MENSUAL', 'GIRA'];
    $state = strtoupper(trim((string)($_GET['estado'] ?? 'TODOS')));
    $type = strtoupper(trim((string)($_GET['tipo'] ?? 'TODOS')));
    $period = trim((string)($_GET['mes'] ?? ''));
    $sellerId = filter_input(INPUT_GET, 'vendedor_id', FILTER_VALIDATE_INT) ?: 0;
    $companyId = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT) ?: 0;
    if (!in_array($state, $allowedStates, true) || !in_array($type, $allowedTypes, true)) {
        throw new InvalidArgumentException('Los filtros de estado o tipo no son válidos.');
    }
    if ($period !== '') {
        RendicionesService::validatePeriod($period);
    }
    $page = max(1, (int)($_GET['pagina'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limite'] ?? 30)));
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare(
        'SELECT r.id, r.codigo_rendicion, r.empresa_id, e.nombre AS empresa_nombre,
                r.vendedor_id, r.vendedor_nombre, r.vendedor_email,
                r.periodo_mes, r.tipo_rendicion, p.nombre_gira,
                r.monto_total_rendido, r.monto_total_aprobado,
                r.monto_presupuesto_asignado, r.monto_exceso,
                r.requiere_aprobacion_exceso, r.notificacion_exceso_estado,
                r.estado, r.documentos_fisicos_recibidos, r.enviada_at,
                COUNT(d.id) AS cantidad_documentos
         FROM rendiciones_gastos r
         INNER JOIN empresas e ON e.id = r.empresa_id
         INNER JOIN presupuestos_vendedores p ON p.id = r.presupuesto_id
         LEFT JOIN rendicion_documentos d ON d.rendicion_id = r.id AND d.activo = :documento_activo
         WHERE r.activo = :rendicion_activa
           AND (:todos_estado = 1 OR r.estado = :estado)
           AND (:todos_tipo = 1 OR r.tipo_rendicion = :tipo)
           AND (:todos_periodos = 1 OR r.periodo_mes = :periodo)
           AND (:todos_vendedores = 1 OR r.vendedor_id = :vendedor_id)
           AND (:todas_empresas = 1 OR r.empresa_id = :empresa_id)
         GROUP BY r.id, r.codigo_rendicion, r.empresa_id, e.nombre,
                  r.vendedor_id, r.vendedor_nombre, r.vendedor_email,
                  r.periodo_mes, r.tipo_rendicion, p.nombre_gira,
                  r.monto_total_rendido, r.monto_total_aprobado,
                  r.monto_presupuesto_asignado, r.monto_exceso,
                  r.requiere_aprobacion_exceso, r.notificacion_exceso_estado,
                  r.estado, r.documentos_fisicos_recibidos, r.enviada_at
         ORDER BY r.enviada_at DESC, r.id DESC
         LIMIT :limite OFFSET :offset'
    );
    $stmt->bindValue(':documento_activo', 1, PDO::PARAM_INT);
    $stmt->bindValue(':rendicion_activa', 1, PDO::PARAM_INT);
    $stmt->bindValue(':todos_estado', $state === 'TODOS' ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':estado', $state === 'TODOS' ? 'EN_REVISION_TESORERIA' : $state, PDO::PARAM_STR);
    $stmt->bindValue(':todos_tipo', $type === 'TODOS' ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':tipo', $type === 'TODOS' ? 'MENSUAL' : $type, PDO::PARAM_STR);
    $stmt->bindValue(':todos_periodos', $period === '' ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':periodo', $period === '' ? date('Y-m') : $period, PDO::PARAM_STR);
    $stmt->bindValue(':todos_vendedores', $sellerId === 0 ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':vendedor_id', $sellerId, PDO::PARAM_INT);
    $stmt->bindValue(':todas_empresas', $companyId === 0 ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':empresa_id', $companyId, PDO::PARAM_INT);
    $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        foreach (['id', 'empresa_id', 'vendedor_id', 'cantidad_documentos'] as $integerField) {
            $row[$integerField] = (int)$row[$integerField];
        }
        foreach (['monto_total_rendido', 'monto_total_aprobado', 'monto_presupuesto_asignado', 'monto_exceso'] as $moneyField) {
            $row[$moneyField] = (float)$row[$moneyField];
        }
        $row['requiere_aprobacion_exceso'] = (bool)$row['requiere_aprobacion_exceso'];
        $row['documentos_fisicos_recibidos'] = (bool)$row['documentos_fisicos_recibidos'];
    }
    unset($row);

    RendicionesService::jsonResponse(true, ['data' => ['rendiciones' => $rows, 'pagina' => $page, 'limite' => $limit]]);
} catch (InvalidArgumentException $exception) {
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('[admin.rendiciones.get_rendiciones] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible cargar las rendiciones.'], 500);
}
