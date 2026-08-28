<?php

declare(strict_types=1);

/**
 * Datos demostrativos para el dashboard histórico de Rendiciones.
 *
 * Uso local:
 *   php scripts/seed_rendiciones_dashboard_demo.php
 *
 * No modifica los ERP. Sólo consulta identidades reales y escribe datos marcados
 * como DEMO-DASHBOARD en la base central. Es idempotente y está bloqueado fuera
 * de APP_ENV=local.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/ErpSellerDirectoryService.php';

if (!defined('APP_ENV') || APP_ENV !== 'local') {
    fwrite(STDERR, "Este seeder sólo puede ejecutarse con APP_ENV=local.\n");
    exit(1);
}

$pdo = Database::getCobranzasConnection();
$sellerSeeds = [
    ['empresa_id' => 1, 'vendedor_id' => 16, 'presupuesto' => 220000, 'perfil' => [0.42, 0.48, 0.55, 0.61, 0.68, 0.74]],
    ['empresa_id' => 1, 'vendedor_id' => 25, 'presupuesto' => 260000, 'perfil' => [0.76, 0.88, 0.97, 1.05, 1.12, 0.94]],
    ['empresa_id' => 2, 'vendedor_id' => 7, 'presupuesto' => 190000, 'perfil' => [0.08, 0.00, 0.12, 0.15, 0.00, 0.18]],
    ['empresa_id' => 2, 'vendedor_id' => 78, 'presupuesto' => 310000, 'perfil' => [0.35, 0.82, 0.25, 0.91, 0.44, 0.78]],
    ['empresa_id' => 3, 'vendedor_id' => 18, 'presupuesto' => 240000, 'perfil' => [0.28, 0.39, 0.51, 0.63, 0.72, 0.84]],
    ['empresa_id' => 4, 'vendedor_id' => 32, 'presupuesto' => 280000, 'perfil' => [0.65, 0.70, 0.58, 0.76, 0.81, 0.88]],
];
$categories = ['BENCINA', 'PEAJES', 'HOSPEDAJE', 'COLACION', 'ESTACIONAMIENTO', 'CENA_CLIENTE'];
$providers = ['Copec', 'Autopista Central', 'Hotel Norte', 'Restaurant Estación', 'Parking Centro', 'Restaurant Cliente'];
$endMonth = new DateTimeImmutable('first day of this month');
$months = [];
for ($offset = 5; $offset >= 0; $offset--) {
    $months[] = $endMonth->modify("-{$offset} months")->format('Y-m');
}

$selectBudget = $pdo->prepare('SELECT id FROM presupuestos_vendedores WHERE periodo_clave = :periodo_clave LIMIT 1');
$insertBudget = $pdo->prepare(
    'INSERT INTO presupuestos_vendedores (
        empresa_id, vendedor_id, vendedor_nombre, vendedor_email, tipo_presupuesto,
        nombre_gira, periodo_mes, fecha_inicio, fecha_fin, monto_asignado,
        monto_utilizado, periodo_clave, activo, creado_por
     ) VALUES (
        :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email, :tipo_presupuesto,
        :nombre_gira, :periodo_mes, :fecha_inicio, :fecha_fin, :monto_asignado,
        :monto_utilizado, :periodo_clave, :activo, :creado_por
     )'
);
$updateBudget = $pdo->prepare(
    'UPDATE presupuestos_vendedores
     SET vendedor_nombre = :vendedor_nombre, vendedor_email = :vendedor_email,
         monto_asignado = :monto_asignado, monto_utilizado = :monto_utilizado,
         activo = :activo
     WHERE id = :id'
);
$selectRendition = $pdo->prepare('SELECT id FROM rendiciones_gastos WHERE codigo_rendicion = :codigo LIMIT 1');
$insertRendition = $pdo->prepare(
    'INSERT INTO rendiciones_gastos (
        codigo_rendicion, empresa_id, vendedor_id, vendedor_nombre, vendedor_email,
        nota_vendedor, presupuesto_id, periodo_mes, tipo_rendicion,
        monto_total_rendido, monto_total_aprobado, monto_presupuesto_asignado,
        saldo_disponible_al_enviar, monto_exceso, requiere_aprobacion_exceso,
        decision_exceso, aprobado_exceso_at, aprobado_exceso_por,
        aprobador_nombre_snapshot, aprobador_cargo_snapshot, aprobador_email_snapshot,
        notificacion_exceso_estado, estado, documentos_fisicos_recibidos,
        fecha_recepcion_fisica, motivo_rechazo, activo, enviada_at, created_at
     ) VALUES (
        :codigo_rendicion, :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email,
        :nota_vendedor, :presupuesto_id, :periodo_mes, :tipo_rendicion,
        :monto_total_rendido, :monto_total_aprobado, :monto_presupuesto_asignado,
        :saldo_disponible_al_enviar, :monto_exceso, :requiere_aprobacion_exceso,
        :decision_exceso, :aprobado_exceso_at, :aprobado_exceso_por,
        :aprobador_nombre_snapshot, :aprobador_cargo_snapshot, :aprobador_email_snapshot,
        :notificacion_exceso_estado, :estado, :documentos_fisicos_recibidos,
        :fecha_recepcion_fisica, :motivo_rechazo, :activo, :enviada_at, :created_at
     )'
);
$insertDocument = $pdo->prepare(
    'INSERT INTO rendicion_documentos (
        empresa_id, vendedor_id, vendedor_nombre, vendedor_email, rendicion_id,
        tipo_documento, categoria_gasto, rut_proveedor, razon_social_proveedor,
        numero_documento, fecha_emision, monto, monto_validado, descripcion,
        foto_documento_url, document_hash, cliente_invitado_nombre,
        cliente_invitado_rut, cliente_invitado_empresa, cliente_invitado_cargo,
        proposito_comercial, estado_item, motivo_rechazo, activo, created_at
     ) VALUES (
        :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email, :rendicion_id,
        :tipo_documento, :categoria_gasto, :rut_proveedor, :razon_social_proveedor,
        :numero_documento, :fecha_emision, :monto, :monto_validado, :descripcion,
        :foto_documento_url, :document_hash, :cliente_invitado_nombre,
        :cliente_invitado_rut, :cliente_invitado_empresa, :cliente_invitado_cargo,
        :proposito_comercial, :estado_item, :motivo_rechazo, :activo, :created_at
     )'
);
$insertHistory = $pdo->prepare(
    'INSERT INTO rendicion_historial_estados (
        rendicion_id, documento_id, usuario_id, actor_tipo, actor_nombre,
        actor_email, accion, estado_anterior, estado_nuevo, comentario,
        metadata_json, ip_origen, user_agent, created_at
     ) VALUES (
        :rendicion_id, :documento_id, :usuario_id, :actor_tipo, :actor_nombre,
        :actor_email, :accion, :estado_anterior, :estado_nuevo, :comentario,
        :metadata_json, :ip_origen, :user_agent, :created_at
     )'
);

$inserted = ['presupuestos' => 0, 'rendiciones' => 0, 'documentos' => 0, 'historial' => 0];
$reused = 0;
$pdo->beginTransaction();
try {
    foreach ($sellerSeeds as $sellerIndex => $seed) {
        $seller = ErpSellerDirectoryService::findByCompanyAndId($pdo, $seed['empresa_id'], $seed['vendedor_id']);
        if (!$seller) {
            throw new RuntimeException("No se encontró el vendedor ERP {$seed['empresa_id']}/{$seed['vendedor_id']}.");
        }

        foreach ($months as $monthIndex => $period) {
            $budgetAmount = $seed['presupuesto'] + (($monthIndex % 3) * 10000);
            $budgetKey = "DEMO-DASHBOARD|MENSUAL|{$seed['empresa_id']}|{$seed['vendedor_id']}|{$period}";
            $selectBudget->execute([':periodo_clave' => $budgetKey]);
            $budgetId = (int)$selectBudget->fetchColumn();
            if ($budgetId === 0) {
                $insertBudget->execute([
                    ':empresa_id' => $seed['empresa_id'],
                    ':vendedor_id' => $seed['vendedor_id'],
                    ':vendedor_nombre' => $seller['vendedor_nombre'],
                    ':vendedor_email' => $seller['vendedor_email'],
                    ':tipo_presupuesto' => 'MENSUAL',
                    ':nombre_gira' => null,
                    ':periodo_mes' => $period,
                    ':fecha_inicio' => null,
                    ':fecha_fin' => null,
                    ':monto_asignado' => $budgetAmount,
                    ':monto_utilizado' => 0,
                    ':periodo_clave' => $budgetKey,
                    ':activo' => 1,
                    ':creado_por' => null,
                ]);
                $budgetId = (int)$pdo->lastInsertId();
                $inserted['presupuestos']++;
            } else {
                $reused++;
            }

            $approvedAmount = (int)round($budgetAmount * $seed['perfil'][$monthIndex] / 1000) * 1000;
            $committedAmount = 0;
            if ($approvedAmount > 0) {
                $state = $monthIndex < 4 ? 'PAGADA' : 'APROBADA';
                $committedAmount += $approvedAmount;
                seedDemoRendition(
                    $pdo,
                    $selectRendition,
                    $insertRendition,
                    $insertDocument,
                    $insertHistory,
                    $seller,
                    $sellerIndex,
                    $period,
                    $monthIndex,
                    $budgetId,
                    $budgetAmount,
                    'MENSUAL',
                    null,
                    $approvedAmount,
                    $approvedAmount,
                    $state,
                    'A',
                    $categories[$monthIndex % count($categories)],
                    $providers[$monthIndex % count($providers)],
                    $inserted
                );
            }

            if ($sellerIndex === 3 && in_array($monthIndex, [1, 3], true)) {
                $rejectedAmount = (int)round($budgetAmount * 0.32 / 1000) * 1000;
                seedDemoRendition(
                    $pdo,
                    $selectRendition,
                    $insertRendition,
                    $insertDocument,
                    $insertHistory,
                    $seller,
                    $sellerIndex,
                    $period,
                    $monthIndex,
                    $budgetId,
                    $budgetAmount,
                    'MENSUAL',
                    null,
                    $rejectedAmount,
                    0,
                    'RECHAZADA',
                    'R',
                    'OTROS',
                    'Proveedor con respaldo incompleto',
                    $inserted
                );
            }

            if ($monthIndex === 5 && in_array($sellerIndex, [4, 5], true)) {
                $pendingAmount = (int)round($budgetAmount * ($sellerIndex === 4 ? 0.30 : 0.20) / 1000) * 1000;
                $committedAmount += $pendingAmount;
                seedDemoRendition(
                    $pdo,
                    $selectRendition,
                    $insertRendition,
                    $insertDocument,
                    $insertHistory,
                    $seller,
                    $sellerIndex,
                    $period,
                    $monthIndex,
                    $budgetId,
                    $budgetAmount,
                    'MENSUAL',
                    null,
                    $pendingAmount,
                    0,
                    'EN_REVISION_TESORERIA',
                    'P',
                    'HOSPEDAJE',
                    'Hotel Ruta Comercial',
                    $inserted
                );
            }

            $updateBudget->execute([
                ':vendedor_nombre' => $seller['vendedor_nombre'],
                ':vendedor_email' => $seller['vendedor_email'],
                ':monto_asignado' => $budgetAmount,
                ':monto_utilizado' => $committedAmount,
                ':activo' => 1,
                ':id' => $budgetId,
            ]);
        }

        $tourMonthBySeller = [1 => 2, 4 => 4, 5 => 5];
        if (isset($tourMonthBySeller[$sellerIndex])) {
            $tourMonthIndex = $tourMonthBySeller[$sellerIndex];
            $tourPeriod = $months[$tourMonthIndex];
            $tourName = 'Gira comercial demo ' . ($sellerIndex + 1);
            $tourStart = $tourPeriod . '-05';
            $tourEnd = $tourPeriod . '-25';
            $tourAmount = 320000 + ($sellerIndex * 20000);
            $tourApproved = $sellerIndex === 1 ? $tourAmount + 40000 : (int)round($tourAmount * 0.78 / 1000) * 1000;
            $tourKey = "DEMO-DASHBOARD|GIRA|{$seed['empresa_id']}|{$seed['vendedor_id']}|{$tourPeriod}";
            $selectBudget->execute([':periodo_clave' => $tourKey]);
            $tourBudgetId = (int)$selectBudget->fetchColumn();
            if ($tourBudgetId === 0) {
                $insertBudget->execute([
                    ':empresa_id' => $seed['empresa_id'],
                    ':vendedor_id' => $seed['vendedor_id'],
                    ':vendedor_nombre' => $seller['vendedor_nombre'],
                    ':vendedor_email' => $seller['vendedor_email'],
                    ':tipo_presupuesto' => 'GIRA',
                    ':nombre_gira' => $tourName,
                    ':periodo_mes' => $tourPeriod,
                    ':fecha_inicio' => $tourStart,
                    ':fecha_fin' => $tourEnd,
                    ':monto_asignado' => $tourAmount,
                    ':monto_utilizado' => $tourApproved,
                    ':periodo_clave' => $tourKey,
                    ':activo' => 1,
                    ':creado_por' => null,
                ]);
                $tourBudgetId = (int)$pdo->lastInsertId();
                $inserted['presupuestos']++;
            } else {
                $reused++;
            }
            seedDemoRendition(
                $pdo,
                $selectRendition,
                $insertRendition,
                $insertDocument,
                $insertHistory,
                $seller,
                $sellerIndex,
                $tourPeriod,
                $tourMonthIndex,
                $tourBudgetId,
                $tourAmount,
                'GIRA',
                $tourName,
                $tourApproved,
                $tourApproved,
                'APROBADA',
                'G',
                'HOSPEDAJE',
                'Hotel Gira Comercial',
                $inserted
            );
            $updateBudget->execute([
                ':vendedor_nombre' => $seller['vendedor_nombre'],
                ':vendedor_email' => $seller['vendedor_email'],
                ':monto_asignado' => $tourAmount,
                ':monto_utilizado' => $tourApproved,
                ':activo' => 1,
                ':id' => $tourBudgetId,
            ]);
        }
    }
    $pdo->commit();
} catch (Throwable $exception) {
    $pdo->rollBack();
    fwrite(STDERR, 'No fue posible generar los datos demo: ' . $exception->getMessage() . "\n");
    exit(1);
}

echo "Datos demo del dashboard generados correctamente.\n";
echo "Período: {$months[0]} a {$months[5]}\n";
echo "Vendedores: " . count($sellerSeeds) . "\n";
foreach ($inserted as $entity => $count) {
    echo ucfirst($entity) . " nuevos: {$count}\n";
}
echo "Presupuestos reutilizados: {$reused}\n";

function seedDemoRendition(
    PDO $pdo,
    PDOStatement $selectRendition,
    PDOStatement $insertRendition,
    PDOStatement $insertDocument,
    PDOStatement $insertHistory,
    array $seller,
    int $sellerIndex,
    string $period,
    int $monthIndex,
    int $budgetId,
    int $budgetAmount,
    string $budgetType,
    ?string $tourName,
    int $renderedAmount,
    int $approvedAmount,
    string $state,
    string $suffix,
    string $category,
    string $provider,
    array &$inserted
): void {
    $sellerCode = str_pad((string)$seller['vendedor_id'], 3, '0', STR_PAD_LEFT);
    $code = 'RND-D' . str_replace('-', '', substr($period, 2)) . '-E' . $seller['empresa_id'] . 'V' . $sellerCode . $suffix;
    $selectRendition->execute([':codigo' => $code]);
    if ((int)$selectRendition->fetchColumn() > 0) {
        return;
    }

    $eventDate = $period . '-' . str_pad((string)(8 + (($sellerIndex + $monthIndex) % 14)), 2, '0', STR_PAD_LEFT) . ' 10:30:00';
    $excess = max(0, $renderedAmount - $budgetAmount);
    $isApproved = in_array($state, ['APROBADA', 'APROBADA_PARCIAL', 'PAGADA'], true);
    $isRejected = $state === 'RECHAZADA';
    $insertRendition->execute([
        ':codigo_rendicion' => $code,
        ':empresa_id' => $seller['empresa_id'],
        ':vendedor_id' => $seller['vendedor_id'],
        ':vendedor_nombre' => $seller['vendedor_nombre'],
        ':vendedor_email' => $seller['vendedor_email'],
        ':nota_vendedor' => 'DEMO-DASHBOARD · Escenario histórico para análisis local' . ($budgetType === 'GIRA' ? ' asociado a gira.' : '.'),
        ':presupuesto_id' => $budgetId,
        ':periodo_mes' => $period,
        ':tipo_rendicion' => $budgetType,
        ':monto_total_rendido' => $renderedAmount,
        ':monto_total_aprobado' => $approvedAmount,
        ':monto_presupuesto_asignado' => $budgetAmount,
        ':saldo_disponible_al_enviar' => max(0, $budgetAmount - $renderedAmount),
        ':monto_exceso' => $excess,
        ':requiere_aprobacion_exceso' => $excess > 0 ? 1 : 0,
        ':decision_exceso' => $excess > 0 && $isApproved ? 'APROBADO' : null,
        ':aprobado_exceso_at' => $excess > 0 && $isApproved ? $eventDate : null,
        ':aprobado_exceso_por' => $excess > 0 && $isApproved ? 'Gerencia Demo' : null,
        ':aprobador_nombre_snapshot' => $excess > 0 ? 'Gerencia Demo' : null,
        ':aprobador_cargo_snapshot' => $excess > 0 ? 'Gerente de Administración' : null,
        ':aprobador_email_snapshot' => $excess > 0 ? 'gerencia.demo@example.invalid' : null,
        ':notificacion_exceso_estado' => $excess > 0 ? 'ENVIADA' : 'NO_APLICA',
        ':estado' => $state,
        ':documentos_fisicos_recibidos' => $isApproved ? 1 : 0,
        ':fecha_recepcion_fisica' => $isApproved ? $eventDate : null,
        ':motivo_rechazo' => $isRejected ? 'DEMO: respaldo insuficiente para validar el gasto.' : null,
        ':activo' => 1,
        ':enviada_at' => $eventDate,
        ':created_at' => $eventDate,
    ]);
    $renditionId = (int)$pdo->lastInsertId();
    $inserted['rendiciones']++;

    $isDinner = $category === 'CENA_CLIENTE';
    $documentState = $isApproved ? 'APROBADO' : ($isRejected ? 'RECHAZADO' : 'PENDIENTE');
    $documentDate = substr($eventDate, 0, 10);
    $hash = hash('sha256', "DEMO-DASHBOARD|{$code}|{$renderedAmount}");
    $insertDocument->execute([
        ':empresa_id' => $seller['empresa_id'],
        ':vendedor_id' => $seller['vendedor_id'],
        ':vendedor_nombre' => $seller['vendedor_nombre'],
        ':vendedor_email' => $seller['vendedor_email'],
        ':rendicion_id' => $renditionId,
        ':tipo_documento' => $category === 'PEAJES' ? 'PEAJE' : 'BOLETA_ELECTRONICA',
        ':categoria_gasto' => $category,
        ':rut_proveedor' => '76123456-7',
        ':razon_social_proveedor' => $provider,
        ':numero_documento' => 'DEMO-' . $renditionId,
        ':fecha_emision' => $documentDate,
        ':monto' => $renderedAmount,
        ':monto_validado' => $isApproved ? $approvedAmount : null,
        ':descripcion' => 'Comprobante demostrativo para análisis histórico local.',
        ':foto_documento_url' => '../LOGO-HOLDING-AUTOMARCO.png',
        ':document_hash' => $hash,
        ':cliente_invitado_nombre' => $isDinner ? 'Cliente Demostración' : null,
        ':cliente_invitado_rut' => $isDinner ? '11111111-1' : null,
        ':cliente_invitado_empresa' => $isDinner ? 'Empresa Cliente Demo' : null,
        ':cliente_invitado_cargo' => $isDinner ? 'Jefatura de Compras' : null,
        ':proposito_comercial' => $isDinner ? 'Reunión de seguimiento comercial demostrativa.' : null,
        ':estado_item' => $documentState,
        ':motivo_rechazo' => $isRejected ? 'DEMO: documento sin respaldo suficiente.' : null,
        ':activo' => 1,
        ':created_at' => $eventDate,
    ]);
    $documentId = (int)$pdo->lastInsertId();
    $inserted['documentos']++;

    $insertHistory->execute([
        ':rendicion_id' => $renditionId,
        ':documento_id' => $documentId,
        ':usuario_id' => null,
        ':actor_tipo' => $isApproved || $isRejected ? 'TESORERIA' : 'VENDEDOR',
        ':actor_nombre' => $isApproved || $isRejected ? 'Sistema Demo Tesorería' : $seller['vendedor_nombre'],
        ':actor_email' => null,
        ':accion' => 'DEMO_DASHBOARD_' . $state,
        ':estado_anterior' => 'ENVIADA',
        ':estado_nuevo' => $state,
        ':comentario' => 'Registro demostrativo generado en Laragon para validar decisiones del dashboard.',
        ':metadata_json' => json_encode(['demo' => true, 'origen' => 'seed_rendiciones_dashboard_demo.php', 'tipo_presupuesto' => $budgetType, 'nombre_gira' => $tourName], JSON_UNESCAPED_UNICODE),
        ':ip_origen' => '127.0.0.1',
        ':user_agent' => 'Laragon dashboard demo seeder',
        ':created_at' => $eventDate,
    ]);
    $inserted['historial']++;
}
