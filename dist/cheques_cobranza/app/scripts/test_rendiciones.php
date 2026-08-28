<?php
/**
 * Pruebas automatizadas sin dependencias externas para Rendiciones.
 * Sólo se ejecuta en APP_ENV=local y revierte todos sus fixtures.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/RendicionesService.php';
require_once __DIR__ . '/../services/ErpSellerDirectoryService.php';
require_once __DIR__ . '/../services/RendicionApprovalPdf.php';

if (APP_ENV !== 'local') {
    fwrite(STDERR, "Este script sólo puede ejecutarse con APP_ENV=local.\n");
    exit(2);
}

$checks = 0;
function assertRendiciones(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $requiredTables = [
        'presupuestos_vendedores',
        'aprobadores_rendiciones',
        'rendiciones_gastos',
        'rendicion_documentos',
        'rendicion_historial_estados',
    ];
    $stmtTable = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name'
    );
    foreach ($requiredTables as $tableName) {
        $stmtTable->execute([':schema_name' => DB_NAME_CENTRAL, ':table_name' => $tableName]);
        assertRendiciones((int)$stmtTable->fetchColumn() === 1, "Falta la tabla {$tableName} en la BD local.");
    }
    $stmtColumn = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = :schema_name AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $requiredRenditionColumns = [
        'nota_vendedor',
        'aprobador_solicitado_id',
        'aprobador_nombre_snapshot',
        'aprobador_cargo_snapshot',
        'aprobador_email_snapshot',
        'solicitud_exceso_enviada_at',
        'solicitud_exceso_enviada_por',
    ];
    foreach ($requiredRenditionColumns as $columnName) {
        $stmtColumn->execute([':schema_name' => DB_NAME_CENTRAL, ':table_name' => 'rendiciones_gastos', ':column_name' => $columnName]);
        assertRendiciones((int)$stmtColumn->fetchColumn() === 1, "Falta la columna {$columnName} en rendiciones_gastos.");
    }

    $normalHashA = RendicionesService::createDocumentHash([
        'tipo_documento' => 'BOLETA_ELECTRONICA',
        'categoria_gasto' => 'COLACION',
        'rut_proveedor' => '76.123.456-7',
        'numero_documento' => '000123',
        'monto' => 15000,
    ], 55, 1);
    $normalHashB = RendicionesService::createDocumentHash([
        'tipo_documento' => 'BOLETA_ELECTRONICA',
        'categoria_gasto' => 'COLACION',
        'rut_proveedor' => '76123456-7',
        'numero_documento' => '123',
        'monto' => 99999,
    ], 99, 4);
    assertRendiciones(hash_equals($normalHashA, $normalHashB), 'La normalización antifraude de documentos no es determinista.');

    $tollHashA = RendicionesService::createDocumentHash([
        'tipo_documento' => 'PEAJE',
        'categoria_gasto' => 'PEAJES',
        'fecha_emision' => '2026-08-21',
        'monto' => '3500',
    ], 55, 1);
    $tollHashB = RendicionesService::createDocumentHash([
        'tipo_documento' => 'PEAJE',
        'categoria_gasto' => 'PEAJES',
        'fecha_emision' => '2026-08-21',
        'monto' => '3500',
    ], 56, 1);
    assertRendiciones(!hash_equals($tollHashA, $tollHashB), 'El hash de peaje no separa vendedores.');
    assertRendiciones(RendicionesService::canTransition('EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'), 'Falta transición de recepción física.');
    assertRendiciones(RendicionesService::canTransition('PENDIENTE_APROBACION_EXCESO', 'RECHAZADA'), 'Tesorería no puede rechazar un exceso pendiente.');
    assertRendiciones(!RendicionesService::canTransition('PAGADA', 'EN_REVISION_TESORERIA'), 'Se permitió una regresión desde estado final.');

    $validTourKey = RendicionesService::createBudgetKey(1, 20, 'GIRA', '2026-09', 'Gira Zona Norte', '2026-09-02', '2026-09-08');
    assertRendiciones(str_starts_with($validTourKey, 'GIRA|1|20|2026-09|GIRA-ZONA-NORTE|'), 'La clave canónica de gira no conserva su identidad y período.');
    $derivedTourKey = RendicionesService::createBudgetKey(1, 20, 'GIRA', '', 'Gira Sur', '2026-10-03', '2026-10-07');
    assertRendiciones(str_starts_with($derivedTourKey, 'GIRA|1|20|2026-10|GIRA-SUR|'), 'La gira no deriva el período desde su fecha de inicio cuando el campo mensual viene vacío.');
    $tourNameError = '';
    try {
        RendicionesService::createBudgetKey(1, 20, 'GIRA', '2026-09', '', '2026-09-02', '2026-09-08');
    } catch (InvalidArgumentException $exception) {
        $tourNameError = $exception->getMessage();
    }
    assertRendiciones(str_contains($tourNameError, 'nombre de gira'), 'La validación de gira no identifica específicamente un nombre inválido.');
    $tourRangeError = '';
    try {
        RendicionesService::createBudgetKey(1, 20, 'GIRA', '2026-09', 'Gira QA', '2026-09-08', '2026-09-02');
    } catch (InvalidArgumentException $exception) {
        $tourRangeError = $exception->getMessage();
    }
    assertRendiciones(str_contains($tourRangeError, 'término'), 'La validación de gira no identifica específicamente un rango invertido.');
    RendicionesService::assertDocumentsFitBudget([
        'tipo_presupuesto' => 'GIRA',
        'fecha_inicio' => '2026-09-02',
        'fecha_fin' => '2026-09-08',
    ], [['fecha_emision' => '2026-09-05']]);
    assertRendiciones(true, 'Un comprobante dentro de la gira fue rechazado.');
    $outsideTourBlocked = false;
    try {
        RendicionesService::assertDocumentsFitBudget([
            'tipo_presupuesto' => 'GIRA',
            'fecha_inicio' => '2026-09-02',
            'fecha_fin' => '2026-09-08',
        ], [['fecha_emision' => '2026-09-12']]);
    } catch (DomainException $exception) {
        $outsideTourBlocked = true;
    }
    assertRendiciones($outsideTourBlocked, 'Una boleta fuera del período pudo imputarse a la gira.');

    $companies = ErpSellerDirectoryService::getCompanies($pdo);
    assertRendiciones(count($companies) === 4, 'El directorio ERP no resolvió las cuatro empresas autorizadas.');
    $erpSellers = ErpSellerDirectoryService::searchByCompany($pdo, (int)$companies[0]['id']);
    assertRendiciones(count($erpSellers) > 0, 'El catálogo ERP de vendedores está vacío o no pudo consultarse.');
    $erpSeller = $erpSellers[0];
    $verifiedSeller = ErpSellerDirectoryService::findByCompanyAndId($pdo, (int)$erpSeller['empresa_id'], (int)$erpSeller['vendedor_id']);
    assertRendiciones($verifiedSeller !== null, 'No fue posible revalidar el vendedor ERP seleccionado.');
    assertRendiciones($verifiedSeller['vendedor_nombre'] === $erpSeller['vendedor_nombre'], 'La identidad canónica del vendedor cambió entre búsqueda y validación.');
    $holdingDirectory = ErpSellerDirectoryService::getHoldingDirectory($pdo);
    assertRendiciones(count($holdingDirectory) > 0, 'El inventario homologado del holding está vacío.');
    $multiCompanySeller = array_values(array_filter(
        $holdingDirectory,
        static fn(array $seller): bool => count($seller['empresas'] ?? []) > 1
    ));
    assertRendiciones(count($multiCompanySeller) > 0, 'La homologación por ven_mail no reunió códigos de distintas empresas.');

    $pdo->beginTransaction();
    $testPeriod = '2099-12';
    $monthlyKey = RendicionesService::createBudgetKey((int)$erpSeller['empresa_id'], (int)$erpSeller['vendedor_id'], 'MENSUAL', $testPeriod, null, null, null);
    $tourName = 'Gira QA ' . bin2hex(random_bytes(4));
    $tourKey = RendicionesService::createBudgetKey((int)$erpSeller['empresa_id'], (int)$erpSeller['vendedor_id'], 'GIRA', $testPeriod, $tourName, '2099-12-01', '2099-12-05');
    $stmtVerifiedBudget = $pdo->prepare(
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
    $baseVerifiedBudget = [
        ':empresa_id' => (int)$erpSeller['empresa_id'],
        ':vendedor_id' => (int)$erpSeller['vendedor_id'],
        ':vendedor_nombre' => $verifiedSeller['vendedor_nombre'],
        ':vendedor_email' => $verifiedSeller['vendedor_email'],
        ':periodo_mes' => $testPeriod,
        ':monto_asignado' => '250000.00',
        ':monto_utilizado' => '0.00',
        ':activo' => 1,
        ':creado_por' => 1,
    ];
    $stmtVerifiedBudget->execute($baseVerifiedBudget + [
        ':tipo_presupuesto' => 'MENSUAL',
        ':nombre_gira' => null,
        ':fecha_inicio' => null,
        ':fecha_fin' => null,
        ':periodo_clave' => $monthlyKey,
    ]);
    $stmtVerifiedBudget->execute($baseVerifiedBudget + [
        ':tipo_presupuesto' => 'GIRA',
        ':nombre_gira' => $tourName,
        ':fecha_inicio' => '2099-12-01',
        ':fecha_fin' => '2099-12-05',
        ':periodo_clave' => $tourKey,
    ]);
    $stmtConcurrentBudgets = $pdo->prepare(
        'SELECT COUNT(*) FROM presupuestos_vendedores
         WHERE empresa_id = :empresa_id AND vendedor_id = :vendedor_id
           AND periodo_mes = :periodo_mes AND periodo_clave IN (:monthly_key, :tour_key)'
    );
    $stmtConcurrentBudgets->execute([
        ':empresa_id' => (int)$erpSeller['empresa_id'],
        ':vendedor_id' => (int)$erpSeller['vendedor_id'],
        ':periodo_mes' => $testPeriod,
        ':monthly_key' => $monthlyKey,
        ':tour_key' => $tourKey,
    ]);
    assertRendiciones((int)$stmtConcurrentBudgets->fetchColumn() === 2, 'El vendedor no pudo mantener presupuesto mensual y gira simultáneos.');

    $sellerId = random_int(800000, 899999);
    $budgetKey = RendicionesService::createBudgetKey(1, $sellerId, 'MENSUAL', '2026-08', null, null, null);
    $stmtBudget = $pdo->prepare(
        'INSERT INTO presupuestos_vendedores (
            empresa_id, vendedor_id, vendedor_nombre, tipo_presupuesto,
            periodo_mes, monto_asignado, monto_utilizado, periodo_clave,
            activo, creado_por
         ) VALUES (
            :empresa_id, :vendedor_id, :vendedor_nombre, :tipo_presupuesto,
            :periodo_mes, :monto_asignado, :monto_utilizado, :periodo_clave,
            :activo, :creado_por
         )'
    );
    $stmtBudget->execute([
        ':empresa_id' => 1,
        ':vendedor_id' => $sellerId,
        ':vendedor_nombre' => 'Prueba Automatizada',
        ':tipo_presupuesto' => 'MENSUAL',
        ':periodo_mes' => '2026-08',
        ':monto_asignado' => '100000.00',
        ':monto_utilizado' => '120000.00',
        ':periodo_clave' => $budgetKey,
        ':activo' => 1,
        ':creado_por' => 1,
    ]);
    $budgetId = (int)$pdo->lastInsertId();

    $rawToken = bin2hex(random_bytes(32));
    $stmtRendition = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, presupuesto_id,
            periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_presupuesto_asignado, saldo_disponible_al_enviar,
            monto_exceso, requiere_aprobacion_exceso,
            token_aprobacion_exceso_hash, token_exceso_expira,
            notificacion_exceso_estado, estado, enviada_at
         ) VALUES (
            :codigo, :empresa_id, :vendedor_id, :presupuesto_id,
            :periodo_mes, :tipo_rendicion, :monto_total_rendido,
            :monto_presupuesto_asignado, :saldo_disponible_al_enviar,
            :monto_exceso, :requiere_aprobacion_exceso,
            :token_hash, :token_expira, :notificacion_estado, :estado, NOW()
         )'
    );
    $stmtRendition->execute([
        ':codigo' => RendicionesService::generateRenditionCode(),
        ':empresa_id' => 1,
        ':vendedor_id' => $sellerId,
        ':presupuesto_id' => $budgetId,
        ':periodo_mes' => '2026-08',
        ':tipo_rendicion' => 'MENSUAL',
        ':monto_total_rendido' => '120000.00',
        ':monto_presupuesto_asignado' => '100000.00',
        ':saldo_disponible_al_enviar' => '100000.00',
        ':monto_exceso' => '20000.00',
        ':requiere_aprobacion_exceso' => 1,
        ':token_hash' => hash('sha256', $rawToken),
        ':token_expira' => date('Y-m-d H:i:s', time() + 3600),
        ':notificacion_estado' => 'PENDIENTE',
        ':estado' => 'PENDIENTE_APROBACION_EXCESO',
    ]);
    $renditionId = (int)$pdo->lastInsertId();

    $stmtApprovedRendition = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, presupuesto_id,
            periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_total_aprobado, monto_presupuesto_asignado,
            saldo_disponible_al_enviar, monto_exceso,
            requiere_aprobacion_exceso, estado, enviada_at
         ) VALUES (
            :codigo, :empresa_id, :vendedor_id, :presupuesto_id,
            :periodo_mes, :tipo_rendicion, :monto_total_rendido,
            :monto_total_aprobado, :monto_presupuesto_asignado,
            :saldo_disponible_al_enviar, :monto_exceso,
            :requiere_aprobacion_exceso, :estado, NOW()
         )'
    );
    $stmtApprovedRendition->execute([
        ':codigo' => RendicionesService::generateRenditionCode(),
        ':empresa_id' => 1,
        ':vendedor_id' => $sellerId,
        ':presupuesto_id' => $budgetId,
        ':periodo_mes' => '2026-08',
        ':tipo_rendicion' => 'MENSUAL',
        ':monto_total_rendido' => '30000.00',
        ':monto_total_aprobado' => '30000.00',
        ':monto_presupuesto_asignado' => '100000.00',
        ':saldo_disponible_al_enviar' => '-20000.00',
        ':monto_exceso' => '0.00',
        ':requiere_aprobacion_exceso' => 0,
        ':estado' => 'APROBADA',
    ]);
    $stmtUpdateCommitted = $pdo->prepare(
        'UPDATE presupuestos_vendedores
         SET monto_utilizado = :monto_utilizado
         WHERE id = :id'
    );
    $stmtUpdateCommitted->execute([':monto_utilizado' => '150000.00', ':id' => $budgetId]);

    $stmtBudgetBreakdown = $pdo->prepare(
        'SELECT p.monto_utilizado,
                COALESCE((
                    SELECT SUM(r.monto_total_aprobado)
                    FROM rendiciones_gastos r
                    WHERE r.presupuesto_id = p.id
                      AND r.estado IN (:estado_aprobada, :estado_parcial, :estado_pagada)
                ), 0) AS monto_aprobado
         FROM presupuestos_vendedores p
         WHERE p.id = :id'
    );
    $stmtBudgetBreakdown->execute([
        ':estado_aprobada' => 'APROBADA',
        ':estado_parcial' => 'APROBADA_PARCIAL',
        ':estado_pagada' => 'PAGADA',
        ':id' => $budgetId,
    ]);
    $budgetBreakdown = $stmtBudgetBreakdown->fetch(PDO::FETCH_ASSOC);
    $approvedAmount = (float)($budgetBreakdown['monto_aprobado'] ?? 0);
    $pendingAmount = max(0.0, (float)($budgetBreakdown['monto_utilizado'] ?? 0) - $approvedAmount);
    assertRendiciones($approvedAmount === 30000.0, 'El presupuesto no separó correctamente el monto aprobado.');
    assertRendiciones($pendingAmount === 120000.0, 'El presupuesto no separó correctamente el monto pendiente de Tesorería.');

    $stmtDocument = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, rendicion_id, tipo_documento,
            categoria_gasto, rut_proveedor, numero_documento, fecha_emision,
            monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            :empresa_id, :vendedor_id, :rendicion_id, :tipo_documento,
            :categoria_gasto, :rut_proveedor, :numero_documento, :fecha_emision,
            :monto, :foto_documento_url, :document_hash, :estado_item, :activo
         )'
    );
    $documentParams = [
        ':empresa_id' => 1,
        ':vendedor_id' => $sellerId,
        ':rendicion_id' => $renditionId,
        ':tipo_documento' => 'BOLETA_ELECTRONICA',
        ':categoria_gasto' => 'COLACION',
        ':rut_proveedor' => '761234567',
        ':numero_documento' => '123',
        ':fecha_emision' => '2026-08-21',
        ':monto' => '120000.00',
        ':foto_documento_url' => 'uploads/test/rendicion.jpg',
        ':document_hash' => $normalHashA,
        ':estado_item' => 'PENDIENTE',
        ':activo' => 1,
    ];
    $stmtDocument->execute($documentParams);
    $duplicateBlocked = false;
    try {
        $stmtDocument->execute($documentParams);
    } catch (PDOException $exception) {
        $duplicateBlocked = (string)$exception->getCode() === '23000';
    }
    assertRendiciones($duplicateBlocked, 'El índice UNIQUE de document_hash no bloqueó un duplicado.');

    $stmtConsume = $pdo->prepare(
        'UPDATE rendiciones_gastos
         SET token_exceso_usado_at = NOW(), decision_exceso = :decision
         WHERE id = :id AND token_exceso_usado_at IS NULL'
    );
    $stmtConsume->execute([':decision' => 'APROBADO', ':id' => $renditionId]);
    assertRendiciones($stmtConsume->rowCount() === 1, 'El primer uso del Magic Token no fue aceptado.');
    $stmtConsume->execute([':decision' => 'APROBADO', ':id' => $renditionId]);
    assertRendiciones($stmtConsume->rowCount() === 0, 'El Magic Token pudo utilizarse más de una vez.');

    $pdfFixture = [
        'id' => $renditionId,
        'codigo_rendicion' => 'RND-QA-PDF',
        'empresa_nombre' => 'Automarco LTDA',
        'vendedor_nombre' => 'Vendedor QA',
        'vendedor_id' => $sellerId,
        'tipo_rendicion' => 'MENSUAL',
        'periodo_mes' => '2026-08',
        'monto_presupuesto_asignado' => 100000,
        'saldo_disponible_al_enviar' => 80000,
        'monto_total_rendido' => 90000,
        'monto_exceso' => 10000,
        'aprobado_exceso_at' => '2026-08-26 12:00:00',
        'aprobador_nombre_snapshot' => 'Responsable QA',
        'aprobador_cargo_snapshot' => 'Gerencia QA',
        'aprobador_email_snapshot' => 'responsable.qa@example.test',
    ];
    $pdfDocument = [[
        'tipo_documento' => 'BOLETA_ELECTRONICA',
        'categoria_gasto' => 'COLACION',
        'rut_proveedor' => '761234567',
        'razon_social_proveedor' => 'Proveedor QA',
        'numero_documento' => '123',
        'fecha_emision' => '2026-08-21',
        'monto' => 90000,
    ]];
    $pdfBytes = RendicionApprovalPdf::build($pdfFixture, $pdfDocument);
    assertRendiciones(str_starts_with($pdfBytes, '%PDF-'), 'El comprobante de aprobación no generó un PDF válido.');
    assertRendiciones(strlen($pdfBytes) > 1500, 'El comprobante PDF quedó vacío o incompleto.');
    assertRendiciones(strpos($pdfBytes, hash('sha256', 'token-qa-no-publicar')) === false, 'El PDF expuso material de token.');

    $pdo->rollBack();
    echo "OK: {$checks} comprobaciones de Rendiciones superadas.\n";
    exit(0);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
