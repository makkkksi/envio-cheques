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

    $endPeriod = RendicionesService::validatePeriod(trim((string)($_GET['mes'] ?? date('Y-m'))));
    $window = filter_input(INPUT_GET, 'ventana', FILTER_VALIDATE_INT) ?: 6;
    if (!in_array($window, [6, 12], true)) {
        throw new InvalidArgumentException('La ventana de análisis debe ser de 6 o 12 meses.');
    }

    $endDate = DateTimeImmutable::createFromFormat('!Y-m', $endPeriod);
    if (!$endDate) {
        throw new InvalidArgumentException('El período seleccionado no es válido.');
    }
    $startPeriod = $endDate->modify('-' . ($window - 1) . ' months')->format('Y-m');
    $periods = [];
    for ($index = 0; $index < $window; $index++) {
        $periods[] = DateTimeImmutable::createFromFormat('!Y-m', $startPeriod)->modify('+' . $index . ' months')->format('Y-m');
    }

    $budgetStatement = $pdo->prepare(
        'SELECT p.empresa_id, e.nombre AS empresa_nombre, p.vendedor_id,
                MAX(p.vendedor_nombre) AS vendedor_nombre, p.periodo_mes,
                p.tipo_presupuesto, COUNT(p.id) AS fondos_count,
                SUM(p.monto_asignado) AS presupuesto_total
         FROM presupuestos_vendedores p
         INNER JOIN empresas e ON e.id = p.empresa_id
         WHERE p.periodo_mes BETWEEN :periodo_inicio AND :periodo_fin
           AND p.activo = :presupuesto_activo
         GROUP BY p.empresa_id, e.nombre, p.vendedor_id, p.periodo_mes, p.tipo_presupuesto
         ORDER BY p.periodo_mes ASC, e.nombre ASC, vendedor_nombre ASC'
    );
    $budgetStatement->execute([
        ':periodo_inicio' => $startPeriod,
        ':periodo_fin' => $endPeriod,
        ':presupuesto_activo' => 1,
    ]);
    $budgetRows = $budgetStatement->fetchAll(PDO::FETCH_ASSOC);

    $renditionStatement = $pdo->prepare(
        'SELECT r.empresa_id, e.nombre AS empresa_nombre, r.vendedor_id,
                MAX(r.vendedor_nombre) AS vendedor_nombre, r.periodo_mes, r.tipo_rendicion,
                SUM(CASE WHEN r.estado IN (:aprobada, :aprobada_parcial, :pagada)
                         THEN r.monto_total_aprobado ELSE 0 END) AS aprobado_total,
                SUM(CASE WHEN r.estado IN (:enviada, :exceso_pendiente, :revision, :fisicos)
                         THEN r.monto_total_rendido ELSE 0 END) AS pendiente_total,
                SUM(CASE WHEN r.estado IN (:aprobada_count, :aprobada_parcial_count, :pagada_count)
                         THEN 1 ELSE 0 END) AS rendiciones_aprobadas,
                SUM(CASE WHEN r.estado = :rechazada THEN 1 ELSE 0 END) AS rendiciones_rechazadas,
                SUM(CASE WHEN r.estado IN (:enviada_count, :exceso_pendiente_count, :revision_count, :fisicos_count)
                         THEN 1 ELSE 0 END) AS rendiciones_pendientes,
                SUM(CASE WHEN r.monto_exceso > :sin_exceso
                              AND r.estado IN (:exceso_aprobada, :exceso_aprobada_parcial, :exceso_pagada)
                         THEN 1 ELSE 0 END) AS casos_exceso,
                MAX(r.enviada_at) AS ultimo_movimiento
         FROM rendiciones_gastos r
         INNER JOIN empresas e ON e.id = r.empresa_id
         WHERE r.periodo_mes BETWEEN :periodo_inicio AND :periodo_fin
           AND r.activo = :rendicion_activa
         GROUP BY r.empresa_id, e.nombre, r.vendedor_id, r.periodo_mes, r.tipo_rendicion
         ORDER BY r.periodo_mes ASC, e.nombre ASC, vendedor_nombre ASC'
    );
    $renditionStatement->execute([
        ':aprobada' => 'APROBADA',
        ':aprobada_parcial' => 'APROBADA_PARCIAL',
        ':pagada' => 'PAGADA',
        ':enviada' => 'ENVIADA',
        ':exceso_pendiente' => 'PENDIENTE_APROBACION_EXCESO',
        ':revision' => 'EN_REVISION_TESORERIA',
        ':fisicos' => 'DOCUMENTOS_FISICOS_RECIBIDOS',
        ':aprobada_count' => 'APROBADA',
        ':aprobada_parcial_count' => 'APROBADA_PARCIAL',
        ':pagada_count' => 'PAGADA',
        ':rechazada' => 'RECHAZADA',
        ':enviada_count' => 'ENVIADA',
        ':exceso_pendiente_count' => 'PENDIENTE_APROBACION_EXCESO',
        ':revision_count' => 'EN_REVISION_TESORERIA',
        ':fisicos_count' => 'DOCUMENTOS_FISICOS_RECIBIDOS',
        ':sin_exceso' => 0,
        ':exceso_aprobada' => 'APROBADA',
        ':exceso_aprobada_parcial' => 'APROBADA_PARCIAL',
        ':exceso_pagada' => 'PAGADA',
        ':periodo_inicio' => $startPeriod,
        ':periodo_fin' => $endPeriod,
        ':rendicion_activa' => 1,
    ]);
    $renditionRows = $renditionStatement->fetchAll(PDO::FETCH_ASSOC);

    $approvalStatement = $pdo->prepare(
        'SELECT sa.tipo_solicitud,
                COUNT(sa.id) AS solicitudes_total,
                SUM(CASE WHEN sa.estado IN (:pendiente_envio, :pendiente_decision) AND sa.activo = :activa THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN sa.estado = :envio_fallido AND sa.activo = :activa_fallo THEN 1 ELSE 0 END) AS correos_fallidos,
                SUM(CASE WHEN sa.estado = :aprobada THEN 1 ELSE 0 END) AS aprobadas,
                SUM(CASE WHEN sa.estado = :rechazada THEN 1 ELSE 0 END) AS rechazadas,
                SUM(CASE WHEN sa.estado = :vencida THEN 1 ELSE 0 END) AS vencidas,
                SUM(CASE WHEN sa.estado = :cancelada THEN 1 ELSE 0 END) AS canceladas,
                AVG(CASE WHEN sa.resuelto_at IS NOT NULL
                         THEN TIMESTAMPDIFF(MINUTE, COALESCE(sa.correo_enviado_at, sa.created_at), sa.resuelto_at) / 60
                         ELSE NULL END) AS horas_respuesta_promedio,
                MAX(CASE WHEN sa.estado IN (:pendiente_envio_antigua, :pendiente_decision_antigua, :envio_fallido_antiguo)
                              AND sa.activo = :activa_antigua
                         THEN TIMESTAMPDIFF(HOUR, COALESCE(sa.correo_enviado_at, sa.created_at), NOW())
                         ELSE 0 END) AS horas_pendiente_mas_antigua
         FROM solicitudes_aprobacion sa
         WHERE DATE_FORMAT(sa.created_at, :formato_periodo) BETWEEN :periodo_inicio AND :periodo_fin
         GROUP BY sa.tipo_solicitud
         ORDER BY sa.tipo_solicitud ASC'
    );
    $approvalStatement->execute([
        ':pendiente_envio' => 'PENDIENTE_ENVIO',
        ':pendiente_decision' => 'PENDIENTE_DECISION',
        ':activa' => 1,
        ':envio_fallido' => 'ENVIO_FALLIDO',
        ':activa_fallo' => 1,
        ':aprobada' => 'APROBADA',
        ':rechazada' => 'RECHAZADA',
        ':vencida' => 'VENCIDA',
        ':cancelada' => 'CANCELADA',
        ':pendiente_envio_antigua' => 'PENDIENTE_ENVIO',
        ':pendiente_decision_antigua' => 'PENDIENTE_DECISION',
        ':envio_fallido_antiguo' => 'ENVIO_FALLIDO',
        ':activa_antigua' => 1,
        ':formato_periodo' => '%Y-%m',
        ':periodo_inicio' => $startPeriod,
        ':periodo_fin' => $endPeriod,
    ]);
    $approvalRows = $approvalStatement->fetchAll(PDO::FETCH_ASSOC);

    $sellerMap = [];
    $trendMap = [];
    $fundTypeMap = [
        'MENSUAL' => ['tipo' => 'MENSUAL', 'fondos_activos' => 0, 'presupuesto' => 0.0, 'aprobado' => 0.0, 'pendiente' => 0.0, 'rendiciones' => 0, 'excesos' => 0],
        'GIRA' => ['tipo' => 'GIRA', 'fondos_activos' => 0, 'presupuesto' => 0.0, 'aprobado' => 0.0, 'pendiente' => 0.0, 'rendiciones' => 0, 'excesos' => 0],
    ];
    foreach ($periods as $period) {
        $trendMap[$period] = ['periodo' => $period, 'presupuesto' => 0.0, 'aprobado' => 0.0, 'pendiente' => 0.0];
    }

    $ensureSeller = static function (array $row) use (&$sellerMap, $periods): string {
        $key = (int)$row['empresa_id'] . '|' . (int)$row['vendedor_id'];
        if (!isset($sellerMap[$key])) {
            $monthly = [];
            foreach ($periods as $period) {
                $monthly[$period] = ['periodo' => $period, 'presupuesto' => 0.0, 'aprobado' => 0.0, 'pendiente' => 0.0];
            }
            $sellerMap[$key] = [
                'clave' => $key,
                'empresa_id' => (int)$row['empresa_id'],
                'empresa_nombre' => (string)$row['empresa_nombre'],
                'vendedor_id' => (int)$row['vendedor_id'],
                'vendedor_nombre' => (string)($row['vendedor_nombre'] ?? 'Vendedor sin nombre'),
                'presupuesto_total' => 0.0,
                'aprobado_total' => 0.0,
                'pendiente_total' => 0.0,
                'rendiciones_aprobadas' => 0,
                'rendiciones_rechazadas' => 0,
                'rendiciones_pendientes' => 0,
                'casos_exceso' => 0,
                'ultimo_movimiento' => null,
                'tendencia' => $monthly,
            ];
        } elseif (!empty($row['vendedor_nombre'])) {
            $sellerMap[$key]['vendedor_nombre'] = (string)$row['vendedor_nombre'];
        }
        return $key;
    };

    foreach ($budgetRows as $row) {
        $key = $ensureSeller($row);
        $amount = (float)$row['presupuesto_total'];
        $period = (string)$row['periodo_mes'];
        $sellerMap[$key]['presupuesto_total'] += $amount;
        $sellerMap[$key]['tendencia'][$period]['presupuesto'] += $amount;
        $trendMap[$period]['presupuesto'] += $amount;
        $fundType = (string)$row['tipo_presupuesto'];
        if (isset($fundTypeMap[$fundType])) {
            $fundTypeMap[$fundType]['fondos_activos'] += (int)$row['fondos_count'];
            $fundTypeMap[$fundType]['presupuesto'] += $amount;
        }
    }

    foreach ($renditionRows as $row) {
        $key = $ensureSeller($row);
        $period = (string)$row['periodo_mes'];
        $approved = (float)$row['aprobado_total'];
        $pending = (float)$row['pendiente_total'];
        $sellerMap[$key]['aprobado_total'] += $approved;
        $sellerMap[$key]['pendiente_total'] += $pending;
        $sellerMap[$key]['rendiciones_aprobadas'] += (int)$row['rendiciones_aprobadas'];
        $sellerMap[$key]['rendiciones_rechazadas'] += (int)$row['rendiciones_rechazadas'];
        $sellerMap[$key]['rendiciones_pendientes'] += (int)$row['rendiciones_pendientes'];
        $sellerMap[$key]['casos_exceso'] += (int)$row['casos_exceso'];
        if ($row['ultimo_movimiento'] && (!$sellerMap[$key]['ultimo_movimiento'] || $row['ultimo_movimiento'] > $sellerMap[$key]['ultimo_movimiento'])) {
            $sellerMap[$key]['ultimo_movimiento'] = (string)$row['ultimo_movimiento'];
        }
        $sellerMap[$key]['tendencia'][$period]['aprobado'] += $approved;
        $sellerMap[$key]['tendencia'][$period]['pendiente'] += $pending;
        $trendMap[$period]['aprobado'] += $approved;
        $trendMap[$period]['pendiente'] += $pending;
        $fundType = (string)$row['tipo_rendicion'];
        if (isset($fundTypeMap[$fundType])) {
            $fundTypeMap[$fundType]['aprobado'] += $approved;
            $fundTypeMap[$fundType]['pendiente'] += $pending;
            $fundTypeMap[$fundType]['rendiciones'] += (int)$row['rendiciones_aprobadas'] + (int)$row['rendiciones_pendientes'];
            $fundTypeMap[$fundType]['excesos'] += (int)$row['casos_exceso'];
        }
    }

    $sellers = [];
    foreach ($sellerMap as $seller) {
        $seller['ejecucion_pct'] = $seller['presupuesto_total'] > 0
            ? round(($seller['aprobado_total'] / $seller['presupuesto_total']) * 100, 1)
            : 0.0;
        $seller['ticket_promedio'] = $seller['rendiciones_aprobadas'] > 0
            ? round($seller['aprobado_total'] / $seller['rendiciones_aprobadas'], 2)
            : 0.0;
        $seller['tendencia'] = array_values($seller['tendencia']);
        $sellers[] = $seller;
    }
    usort($sellers, static function (array $left, array $right): int {
        $amountComparison = $right['aprobado_total'] <=> $left['aprobado_total'];
        return $amountComparison !== 0 ? $amountComparison : strcasecmp($left['vendedor_nombre'], $right['vendedor_nombre']);
    });

    $budgetTotal = array_sum(array_column($sellers, 'presupuesto_total'));
    $approvedTotal = array_sum(array_column($sellers, 'aprobado_total'));
    $pendingTotal = array_sum(array_column($sellers, 'pendiente_total'));
    $approvedCount = array_sum(array_column($sellers, 'rendiciones_aprobadas'));
    $rejectedCount = array_sum(array_column($sellers, 'rendiciones_rechazadas'));
    $excessCount = array_sum(array_column($sellers, 'casos_exceso'));
    $topSellerAmount = $sellers[0]['aprobado_total'] ?? 0.0;
    foreach ($fundTypeMap as &$fundType) {
        $fundType['ejecucion_pct'] = $fundType['presupuesto'] > 0
            ? round(($fundType['aprobado'] / $fundType['presupuesto']) * 100, 1)
            : 0.0;
        $fundType['promedio_fondo'] = $fundType['fondos_activos'] > 0
            ? round($fundType['presupuesto'] / $fundType['fondos_activos'], 2)
            : 0.0;
    }
    unset($fundType);

    $approvalSummary = [
        'solicitudes_total' => 0,
        'pendientes' => 0,
        'correos_fallidos' => 0,
        'aprobadas' => 0,
        'rechazadas' => 0,
        'vencidas' => 0,
        'canceladas' => 0,
        'horas_respuesta_promedio' => 0.0,
        'horas_pendiente_mas_antigua' => 0,
        'tasa_aprobacion_pct' => 0.0,
    ];
    $approvalResponseHours = 0.0;
    $approvalResolvedWithTime = 0;
    $approvalsByType = [];
    foreach ($approvalRows as $row) {
        $resolved = (int)$row['aprobadas'] + (int)$row['rechazadas'];
        $averageHours = $row['horas_respuesta_promedio'] !== null ? round((float)$row['horas_respuesta_promedio'], 1) : 0.0;
        $typed = [
            'tipo' => (string)$row['tipo_solicitud'],
            'solicitudes_total' => (int)$row['solicitudes_total'],
            'pendientes' => (int)$row['pendientes'],
            'correos_fallidos' => (int)$row['correos_fallidos'],
            'aprobadas' => (int)$row['aprobadas'],
            'rechazadas' => (int)$row['rechazadas'],
            'vencidas' => (int)$row['vencidas'],
            'canceladas' => (int)$row['canceladas'],
            'horas_respuesta_promedio' => $averageHours,
            'horas_pendiente_mas_antigua' => max(0, (int)$row['horas_pendiente_mas_antigua']),
            'tasa_aprobacion_pct' => $resolved > 0 ? round(((int)$row['aprobadas'] / $resolved) * 100, 1) : 0.0,
        ];
        $approvalsByType[] = $typed;
        foreach (['solicitudes_total', 'pendientes', 'correos_fallidos', 'aprobadas', 'rechazadas', 'vencidas', 'canceladas'] as $metric) {
            $approvalSummary[$metric] += $typed[$metric];
        }
        $approvalSummary['horas_pendiente_mas_antigua'] = max($approvalSummary['horas_pendiente_mas_antigua'], $typed['horas_pendiente_mas_antigua']);
        if ($resolved > 0 && $row['horas_respuesta_promedio'] !== null) {
            $approvalResponseHours += $averageHours * $resolved;
            $approvalResolvedWithTime += $resolved;
        }
    }
    $resolvedTotal = $approvalSummary['aprobadas'] + $approvalSummary['rechazadas'];
    $approvalSummary['horas_respuesta_promedio'] = $approvalResolvedWithTime > 0
        ? round($approvalResponseHours / $approvalResolvedWithTime, 1)
        : 0.0;
    $approvalSummary['tasa_aprobacion_pct'] = $resolvedTotal > 0
        ? round(($approvalSummary['aprobadas'] / $resolvedTotal) * 100, 1)
        : 0.0;

    RendicionesService::jsonResponse(true, ['data' => [
        'periodo_inicio' => $startPeriod,
        'periodo_fin' => $endPeriod,
        'ventana' => $window,
        'resumen' => [
            'presupuesto_total' => $budgetTotal,
            'aprobado_total' => $approvedTotal,
            'pendiente_total' => $pendingTotal,
            'saldo_no_ejecutado' => max(0, $budgetTotal - $approvedTotal),
            'ejecucion_pct' => $budgetTotal > 0 ? round(($approvedTotal / $budgetTotal) * 100, 1) : 0.0,
            'concentracion_principal_pct' => $approvedTotal > 0 ? round(($topSellerAmount / $approvedTotal) * 100, 1) : 0.0,
            'rendiciones_aprobadas' => $approvedCount,
            'rendiciones_rechazadas' => $rejectedCount,
            'casos_exceso' => $excessCount,
            'vendedores_analizados' => count($sellers),
        ],
        'tendencia_holding' => array_values($trendMap),
        'fondos_por_tipo' => array_values($fundTypeMap),
        'aprobaciones' => [
            'resumen' => $approvalSummary,
            'por_tipo' => $approvalsByType,
        ],
        'vendedores' => $sellers,
    ]]);
} catch (InvalidArgumentException $exception) {
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('[admin.rendiciones.get_dashboard_analitico] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible construir el análisis histórico.'], 500);
}
