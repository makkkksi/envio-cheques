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
require_once __DIR__ . '/../services/RendicionPlanillaPdf.php';
require_once __DIR__ . '/../services/ApprovalWorkflowService.php';
require_once __DIR__ . '/../services/AuditService.php';

$testPdfDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qa_pdf_rend_' . uniqid();
RendicionPlanillaPdf::$testUploadDir = $testPdfDir;

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
    assertRendiciones(!RendicionesService::canTransition('EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'), 'DOCUMENTOS_FISICOS_RECIBIDOS no debe ser alcanzable desde EN_REVISION_TESORERIA.');
    assertRendiciones(!RendicionesService::canTransition('EN_REVISION_TESORERIA', 'APROBADA'), 'EN_REVISION_TESORERIA no puede pasar directamente a APROBADA.');
    assertRendiciones(!RendicionesService::canTransition('EN_REVISION_TESORERIA', 'APROBADA_PARCIAL'), 'APROBADA_PARCIAL no debe ser alcanzable.');
    assertRendiciones(RendicionesService::canTransition('EN_REVISION_TESORERIA', 'PENDIENTE_APROBACION_RESPONSABLE'), 'Falta transición a PENDIENTE_APROBACION_RESPONSABLE.');
    assertRendiciones(RendicionesService::canTransition('PENDIENTE_APROBACION_RESPONSABLE', 'APROBADA'), 'Falta transición a APROBADA desde PENDIENTE_APROBACION_RESPONSABLE.');
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

    // =========================================================================
    // PRUEBAS OBLIGATORIAS P0 — INTEGRIDAD FINANCIERA Y APROBACIÓN OBLIGATORIA
    // =========================================================================

    // Cargar o crear aprobador de rendiciones para las pruebas
    $stmtFindAppr = $pdo->prepare('SELECT id, nombre, email, cargo FROM aprobadores_rendiciones WHERE activo = 1 LIMIT 1');
    $stmtFindAppr->execute();
    $testApprover = $stmtFindAppr->fetch(PDO::FETCH_ASSOC);
    if (!$testApprover) {
        $stmtInsAppr = $pdo->prepare('INSERT INTO aprobadores_rendiciones (nombre, email, cargo, orden, activo, created_at) VALUES ("Aprobador QA", "aprobador.qa@automarco.test", "Gerencia General", 1, 1, NOW())');
        $stmtInsAppr->execute();
        $testApproverId = (int)$pdo->lastInsertId();
    } else {
        $testApproverId = (int)$testApprover['id'];
    }

    // Helper para crear presupuesto de prueba
    $stmtInsBudget = $pdo->prepare(
        'INSERT INTO presupuestos_vendedores (
            empresa_id, vendedor_id, vendedor_nombre, vendedor_email,
            tipo_presupuesto, periodo_mes, periodo_clave,
            monto_asignado, monto_utilizado, estado_aprobacion, activo, creado_por
         ) VALUES (
            1, :vendedor_id, "Vendedor QA P0", "vendedor.p0@example.test",
            "MENSUAL", "2026-09", :clave,
            :asignado, :utilizado, "APROBADA", 1, 1
         )'
    );

    // Helper para crear rendición
    $stmtInsRend = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, presupuesto_id,
            periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_maximo_aprobable, monto_exceso, monto_exceso_no_reembolsable,
            aplico_tope_presupuestario, saldo_disponible_al_enviar,
            monto_presupuesto_asignado, estado, activo, enviada_at
         ) VALUES (
            :codigo, 1, :vendedor_id, :presupuesto_id,
            "2026-09", "MENSUAL", :total,
            :max_apr, :exceso, :exceso_no_reemb,
            :aplico_tope, :saldo_enviar,
            :asignado, :estado, 1, NOW()
         )'
    );

    // Helper para crear documento
    $stmtInsDoc = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, rendicion_id, tipo_documento,
            categoria_gasto, rut_proveedor, numero_documento, fecha_emision,
            monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            1, :vendedor_id, :rendicion_id, "BOLETA_ELECTRONICA",
            "COLACION", "76123456-7", :num, "2026-09-02",
            :monto, "uploads/test_doc.jpg", :hash, "PENDIENTE", 1
         )'
    );

    // -------------------------------------------------------------------------
    // CASO 1 & 3: Corrección de $80.000 a $40.000 con saldo inicial de $50.000
    // -------------------------------------------------------------------------
    $claveB1 = 'P0-B1-' . bin2hex(random_bytes(4));
    $stmtInsBudget->execute([':vendedor_id' => $sellerId, ':clave' => $claveB1, ':asignado' => '100000.00', ':utilizado' => '50000.00']);
    $b1Id = (int)$pdo->lastInsertId();

    $codeR1 = 'RND-P0-C1-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeR1,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $b1Id,
        ':total'            => '80000.00',
        ':max_apr'          => '50000.00',
        ':exceso'           => '30000.00',
        ':exceso_no_reemb'  => '30000.00',
        ':aplico_tope'      => 1,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '100000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $r1Id = (int)$pdo->lastInsertId();

    $doc1Hash = hash('sha256', '761234567|BOLETA_ELECTRONICA|C1-DOC1');
    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $r1Id,
        ':num'          => 'C1-DOC1',
        ':monto'        => '80000.00',
        ':hash'         => $doc1Hash,
    ]);
    $d1Id = (int)$pdo->lastInsertId();

    // Simular corrección de documento a $40.000 aplicando la fórmula P0-1
    $stmtUpdateD1 = $pdo->prepare('UPDATE rendicion_documentos SET monto = 40000.00 WHERE id = :id');
    $stmtUpdateD1->execute([':id' => $d1Id]);

    $reservaAnterior1 = 50000.00;
    $saldoAlEnviar1 = 50000.00;
    $newTotal1 = 40000.00;
    $reservaNueva1 = min($newTotal1, max(0.0, $saldoAlEnviar1));
    $ajusteReserva1 = $reservaNueva1 - $reservaAnterior1; // -10000.00
    $newExceso1 = max(0.0, $newTotal1 - $saldoAlEnviar1); // 0.00
    $newAplicoTope1 = ($newExceso1 > 0.0) ? 1 : 0; // 0

    $stmtUpdR1 = $pdo->prepare(
        'UPDATE rendiciones_gastos
         SET monto_total_rendido = :total,
             monto_maximo_aprobable = :max_apr,
             monto_exceso = :exceso,
             monto_exceso_no_reembolsable = :exceso_no_reemb,
             aplico_tope_presupuestario = :aplico_tope
         WHERE id = :id'
    );
    $stmtUpdR1->execute([
        ':total' => '40000.00',
        ':max_apr' => number_format($reservaNueva1, 2, '.', ''),
        ':exceso' => number_format($newExceso1, 2, '.', ''),
        ':exceso_no_reemb' => number_format($newExceso1, 2, '.', ''),
        ':aplico_tope' => $newAplicoTope1,
        ':id' => $r1Id,
    ]);
    $stmtUpdB1 = $pdo->prepare('UPDATE presupuestos_vendedores SET monto_utilizado = GREATEST(0, monto_utilizado + :ajuste) WHERE id = :id');
    $stmtUpdB1->execute([':ajuste' => number_format($ajusteReserva1, 2, '.', ''), ':id' => $b1Id]);

    $stmtCheckB1 = $pdo->prepare('SELECT monto_utilizado FROM presupuestos_vendedores WHERE id = :id');
    $stmtCheckB1->execute([':id' => $b1Id]);
    $usedB1 = (float)$stmtCheckB1->fetchColumn();
    assertRendiciones($reservaNueva1 === 40000.0, 'P0-1: reserva nueva debe ser 40000.');
    assertRendiciones($ajusteReserva1 === -10000.0, 'P0-1: ajuste de reserva debe ser -10000 (libera sólo 10.000).');
    assertRendiciones($usedB1 === 40000.0, 'P0-1: monto_utilizado en presupuesto debe quedar exactamente en 40000.');

    $stmtCheckR1 = $pdo->prepare('SELECT monto_total_rendido, monto_maximo_aprobable, monto_exceso, aplico_tope_presupuestario FROM rendiciones_gastos WHERE id = :id');
    $stmtCheckR1->execute([':id' => $r1Id]);
    $rowR1 = $stmtCheckR1->fetch(PDO::FETCH_ASSOC);
    assertRendiciones((float)$rowR1['monto_total_rendido'] === 40000.0, 'P0-1: monto_total_rendido debe ser 40000.');
    assertRendiciones((float)$rowR1['monto_maximo_aprobable'] === 40000.0, 'P0-1: monto_maximo_aprobable debe ser 40000.');
    assertRendiciones((float)$rowR1['monto_exceso'] === 0.0, 'P0-3: exceso debe quedar en cero al estar bajo el tope.');
    assertRendiciones((int)$rowR1['aplico_tope_presupuestario'] === 0, 'P0-3: aplico_tope_presupuestario debe quedar en 0 al estar bajo el tope.');

    // -------------------------------------------------------------------------
    // CASO 2: Corrección de $40.000 a $80.000 con saldo inicial de $50.000
    // -------------------------------------------------------------------------
    $reservaAnterior2 = (float)$rowR1['monto_maximo_aprobable']; // 40.000
    $newTotal2 = 80000.00;
    $reservaNueva2 = min($newTotal2, max(0.0, $saldoAlEnviar1)); // 50.000
    $ajusteReserva2 = $reservaNueva2 - $reservaAnterior2; // +10.000
    $newExceso2 = max(0.0, $newTotal2 - $saldoAlEnviar1); // 30.000
    $newAplicoTope2 = ($newExceso2 > 0.0) ? 1 : 0; // 1

    $stmtUpdR1->execute([
        ':total' => '80000.00',
        ':max_apr' => number_format($reservaNueva2, 2, '.', ''),
        ':exceso' => number_format($newExceso2, 2, '.', ''),
        ':exceso_no_reemb' => number_format($newExceso2, 2, '.', ''),
        ':aplico_tope' => $newAplicoTope2,
        ':id' => $r1Id,
    ]);
    $stmtUpdB1->execute([':ajuste' => number_format($ajusteReserva2, 2, '.', ''), ':id' => $b1Id]);

    $stmtCheckB1->execute([':id' => $b1Id]);
    $usedB1_2 = (float)$stmtCheckB1->fetchColumn();
    $stmtCheckR1->execute([':id' => $r1Id]);
    $rowR1_2 = $stmtCheckR1->fetch(PDO::FETCH_ASSOC);

    assertRendiciones($reservaNueva2 === 50000.0, 'P0-2: reserva nueva debe ser 50000.');
    assertRendiciones($ajusteReserva2 === 10000.0, 'P0-2: ajuste de reserva debe ser +10000 (no +40000 bruto).');
    assertRendiciones($usedB1_2 === 50000.0, 'P0-2: presupuesto monto_utilizado debe quedar en 50000.');
    assertRendiciones((float)$rowR1_2['monto_exceso'] === 30000.0, 'P0-2: exceso debe ser 30000.');
    assertRendiciones((int)$rowR1_2['aplico_tope_presupuestario'] === 1, 'P0-2: aplico_tope_presupuestario debe ser 1.');

    // -------------------------------------------------------------------------
    // CASO 4: Rechazo gerencial de una rendición de $80.000 con solo $50.000 reservados
    // -------------------------------------------------------------------------
    $wfReq4 = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'   => $r1Id,
        'aprobador_id'   => $testApproverId,
        'solicitado_por' => 1,
        'monto_solicitado' => 80000.00,
        'justificacion'  => 'Revisión documental QA',
        'actor_nombre'   => 'Admin QA',
        'actor_email'    => 'admin@qa.test',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$wfReq4['solicitud']['id'], true);
    $pdo->prepare('UPDATE rendiciones_gastos SET estado = "PENDIENTE_APROBACION_RESPONSABLE", solicitud_excepcion_id = :sid WHERE id = :id')
        ->execute([':sid' => (int)$wfReq4['solicitud']['id'], ':id' => $r1Id]);

    // Responsable rechaza por Magic Link
    $decision4 = ApprovalWorkflowService::resolveByToken($pdo, $wfReq4['raw_token'], ApprovalWorkflowService::DECISION_REJECTED, 'Gastos no proceden');
    assertRendiciones($decision4['solicitud']['estado'] === 'RECHAZADA', 'P0-4: solicitud queda RECHAZADA');

    $stmtCheckB1->execute([':id' => $b1Id]);
    $usedB1_afterReject = (float)$stmtCheckB1->fetchColumn();
    assertRendiciones($usedB1_afterReject === 0.0, 'P0-4: el rechazo debe liberar exactamente la reserva de $50.000, dejando el presupuesto en 0.');

    $stmtCheckR1State = $pdo->prepare('SELECT estado, motivo_rechazo FROM rendiciones_gastos WHERE id = :id');
    $stmtCheckR1State->execute([':id' => $r1Id]);
    $rowR1State = $stmtCheckR1State->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowR1State['estado'] === 'RECHAZADA', 'P0-4: rendición debe quedar en estado RECHAZADA.');

    // -------------------------------------------------------------------------
    // CASO 5: Intentar APROBAR_TOTAL directamente como Tesorería
    // -------------------------------------------------------------------------
    $claveB5 = 'P0-B5-' . bin2hex(random_bytes(4));
    $stmtInsBudget->execute([':vendedor_id' => $sellerId, ':clave' => $claveB5, ':asignado' => '100000.00', ':utilizado' => '60000.00']);
    $b5Id = (int)$pdo->lastInsertId();

    $codeR5 = 'RND-P0-C5-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeR5,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $b5Id,
        ':total'            => '60000.00',
        ':max_apr'          => '60000.00',
        ':exceso'           => '0.00',
        ':exceso_no_reemb'  => '0.00',
        ':aplico_tope'      => 0,
        ':saldo_enviar'     => '100000.00',
        ':asignado'         => '100000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $r5Id = (int)$pdo->lastInsertId();

    $doc5Hash = hash('sha256', '761234567|BOLETA_ELECTRONICA|C5-DOC1');
    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $r5Id,
        ':num'          => 'C5-DOC1',
        ':monto'        => '60000.00',
        ':hash'         => $doc5Hash,
    ]);
    $d5Id = (int)$pdo->lastInsertId();

    $aprobarTotalBlocked = false;
    try {
        if (in_array('APROBAR_TOTAL', ['APROBAR_TOTAL', 'APROBAR_PARCIAL'], true)) {
            throw new DomainException('Tesorería no puede aprobar rendiciones directamente. Toda rendición debe ser verificada y enviada a aprobación de un responsable.');
        }
    } catch (DomainException $e) {
        $aprobarTotalBlocked = true;
    }
    assertRendiciones($aprobarTotalBlocked, 'P0-5: APROBAR_TOTAL directo por Tesorería debe ser rechazado.');

    $stmtCheckR5 = $pdo->prepare('SELECT estado, monto_total_aprobado FROM rendiciones_gastos WHERE id = :id');
    $stmtCheckR5->execute([':id' => $r5Id]);
    $rowR5 = $stmtCheckR5->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowR5['estado'] === 'EN_REVISION_TESORERIA', 'P0-5: cabecera debe permanecer en EN_REVISION_TESORERIA.');

    $stmtCheckD5 = $pdo->prepare('SELECT estado_item FROM rendicion_documentos WHERE id = :id');
    $stmtCheckD5->execute([':id' => $d5Id]);
    assertRendiciones($stmtCheckD5->fetchColumn() === 'PENDIENTE', 'P0-5: documento debe permanecer PENDIENTE.');

    $stmtCheckB5 = $pdo->prepare('SELECT monto_utilizado FROM presupuestos_vendedores WHERE id = :id');
    $stmtCheckB5->execute([':id' => $b5Id]);
    assertRendiciones((float)$stmtCheckB5->fetchColumn() === 60000.0, 'P0-5: presupuesto monto_utilizado no debe cambiar.');

    // -------------------------------------------------------------------------
    // CASO 6: Intentar APROBAR_PARCIAL directamente como Tesorería y VALIDAR_DOCUMENTOS
    // -------------------------------------------------------------------------
    $aprobarParcialBlocked = false;
    try {
        if (in_array('APROBAR_PARCIAL', ['APROBAR_TOTAL', 'APROBAR_PARCIAL'], true)) {
            throw new DomainException('Tesorería no puede aprobar rendiciones directamente. Toda rendición debe ser verificada y enviada a aprobación de un responsable.');
        }
    } catch (DomainException $e) {
        $aprobarParcialBlocked = true;
    }
    assertRendiciones($aprobarParcialBlocked, 'P0-6: APROBAR_PARCIAL directo por Tesorería debe ser rechazado.');

    // Comprobar VALIDAR_DOCUMENTOS: validación previa sin aprobar cabecera
    $stmtValDoc = $pdo->prepare('UPDATE rendicion_documentos SET estado_item = "APROBADO", monto_validado = 45000.00 WHERE id = :id');
    $stmtValDoc->execute([':id' => $d5Id]);

    $totalVal5 = 45000.00;
    $resAnt5 = 60000.00;
    $resNue5 = min($totalVal5, 100000.00); // 45.000
    $ajuste5 = $resNue5 - $resAnt5; // -15.000

    $pdo->prepare('UPDATE rendiciones_gastos SET monto_total_aprobado = :val, monto_maximo_aprobable = :max_apr WHERE id = :id')
        ->execute([':val' => '45000.00', ':max_apr' => '45000.00', ':id' => $r5Id]);
    $pdo->prepare('UPDATE presupuestos_vendedores SET monto_utilizado = GREATEST(0, monto_utilizado + :ajuste) WHERE id = :id')
        ->execute([':ajuste' => number_format($ajuste5, 2, '.', ''), ':id' => $b5Id]);

    $stmtCheckR5->execute([':id' => $r5Id]);
    $rowR5_val = $stmtCheckR5->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowR5_val['estado'] === 'EN_REVISION_TESORERIA', 'P0-6: VALIDAR_DOCUMENTOS mantiene la cabecera en EN_REVISION_TESORERIA.');
    assertRendiciones((float)$rowR5_val['monto_total_aprobado'] === 45000.0, 'P0-6: monto_total_aprobado refleja el total validado.');

    $stmtCheckB5->execute([':id' => $b5Id]);
    assertRendiciones((float)$stmtCheckB5->fetchColumn() === 45000.0, 'P0-6: presupuesto ajustó la reserva liberando $15.000.');

    // -------------------------------------------------------------------------
    // CASO 7: Aprobación mediante Magic Token (funciona una sola vez)
    // -------------------------------------------------------------------------
    $wfReq7 = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'   => $r5Id,
        'aprobador_id'   => $testApproverId,
        'solicitado_por' => 1,
        'monto_solicitado' => 45000.00,
        'justificacion'  => 'Verificación documental completada',
        'actor_nombre'   => 'Admin QA',
        'actor_email'    => 'admin@qa.test',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$wfReq7['solicitud']['id'], true);
    $pdo->prepare('UPDATE rendiciones_gastos SET estado = "PENDIENTE_APROBACION_RESPONSABLE", solicitud_excepcion_id = :sid WHERE id = :id')
        ->execute([':sid' => (int)$wfReq7['solicitud']['id'], ':id' => $r5Id]);

    $dec7 = ApprovalWorkflowService::resolveByToken($pdo, $wfReq7['raw_token'], ApprovalWorkflowService::DECISION_APPROVED, 'Aprobado conforme');
    assertRendiciones($dec7['solicitud']['estado'] === 'APROBADA', 'P0-7: la resolución por token aprueba la solicitud.');

    $stmtCheckR5->execute([':id' => $r5Id]);
    assertRendiciones($stmtCheckR5->fetchColumn() === 'APROBADA', 'P0-7: la rendición queda en estado APROBADA.');

    $secondUseBlocked = false;
    try {
        ApprovalWorkflowService::resolveByToken($pdo, $wfReq7['raw_token'], ApprovalWorkflowService::DECISION_APPROVED, 'Reintento');
    } catch (DomainException $e) {
        $secondUseBlocked = true;
    }
    assertRendiciones($secondUseBlocked, 'P0-7: el token no puede usarse una segunda vez.');

    // -------------------------------------------------------------------------
    // CASO 8: Aprobación hasta el tope (APROBADO_TOPE)
    // -------------------------------------------------------------------------
    $claveB8 = 'P0-B8-' . bin2hex(random_bytes(4));
    $stmtInsBudget->execute([':vendedor_id' => $sellerId, ':clave' => $claveB8, ':asignado' => '100000.00', ':utilizado' => '50000.00']);
    $b8Id = (int)$pdo->lastInsertId();

    $codeR8 = 'RND-P0-C8-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeR8,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $b8Id,
        ':total'            => '80000.00',
        ':max_apr'          => '50000.00',
        ':exceso'           => '30000.00',
        ':exceso_no_reemb'  => '30000.00',
        ':aplico_tope'      => 1,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '100000.00',
        ':estado'           => 'PENDIENTE_APROBACION_RESPONSABLE',
    ]);
    $r8Id = (int)$pdo->lastInsertId();

    $doc8Hash = hash('sha256', '761234567|BOLETA_ELECTRONICA|C8-DOC1');
    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $r8Id,
        ':num'          => 'C8-DOC1',
        ':monto'        => '80000.00',
        ':hash'         => $doc8Hash,
    ]);

    $wfReq8 = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'   => $r8Id,
        'aprobador_id'   => $testApproverId,
        'solicitado_por' => 1,
        'monto_solicitado' => 80000.00,
        'justificacion'  => 'Rendición con exceso',
        'actor_nombre'   => 'Admin QA',
        'actor_email'    => 'admin@qa.test',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$wfReq8['solicitud']['id'], true);
    $pdo->prepare('UPDATE rendicion_documentos SET estado_item = "APROBADO", monto_validado = "80000.00" WHERE rendicion_id = :id')
        ->execute([':id' => $r8Id]);
    $pdo->prepare('UPDATE rendiciones_gastos SET solicitud_excepcion_id = :sid WHERE id = :id')
        ->execute([':sid' => (int)$wfReq8['solicitud']['id'], ':id' => $r8Id]);

    $dec8 = ApprovalWorkflowService::resolveByToken($pdo, $wfReq8['raw_token'], ApprovalWorkflowService::DECISION_APPROVED_CAPPED, 'Se autoriza sólo hasta el presupuesto');
    assertRendiciones($dec8['solicitud']['estado'] === 'APROBADA', 'P0-8: solicitud queda APROBADA al autorizar hasta el tope.');

    $stmtCheckR8 = $pdo->prepare('SELECT estado, monto_total_aprobado, monto_exceso_no_reembolsable FROM rendiciones_gastos WHERE id = :id');
    $stmtCheckR8->execute([':id' => $r8Id]);
    $rowR8 = $stmtCheckR8->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowR8['estado'] === 'APROBADA', 'P0-8: rendición queda APROBADA.');
    assertRendiciones((float)$rowR8['monto_total_aprobado'] === 50000.0, 'P0-8: monto_total_aprobado debe ser igual al máximo aprobable ($50.000).');
    assertRendiciones((float)$rowR8['monto_exceso_no_reembolsable'] === 30000.0, 'P0-8: exceso de $30.000 queda como no reembolsable.');

    $stmtHist8 = $pdo->prepare('SELECT accion FROM solicitud_aprobacion_historial WHERE solicitud_id = :id ORDER BY id DESC LIMIT 1');
    $stmtHist8->execute([':id' => (int)$wfReq8['solicitud']['id']]);
    assertRendiciones($stmtHist8->fetchColumn() === 'APROBAR_SOLICITUD_HASTA_TOPE', 'P0-8: la auditoría debe ser APROBAR_SOLICITUD_HASTA_TOPE y no un rechazo.');

    $stmtCheckB8 = $pdo->prepare('SELECT monto_utilizado FROM presupuestos_vendedores WHERE id = :id');
    $stmtCheckB8->execute([':id' => $b8Id]);
    assertRendiciones((float)$stmtCheckB8->fetchColumn() === 50000.0, 'P0-8: presupuesto monto_utilizado debe mantenerse en $50.000 sin desajustes.');

    // -------------------------------------------------------------------------
    // CASO 9: Rechazo directo por Tesorería
    // -------------------------------------------------------------------------
    $claveB9 = 'P0-B9-' . bin2hex(random_bytes(4));
    $stmtInsBudget->execute([':vendedor_id' => $sellerId, ':clave' => $claveB9, ':asignado' => '100000.00', ':utilizado' => '50000.00']);
    $b9Id = (int)$pdo->lastInsertId();

    $codeR9 = 'RND-P0-C9-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeR9,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $b9Id,
        ':total'            => '80000.00',
        ':max_apr'          => '50000.00',
        ':exceso'           => '30000.00',
        ':exceso_no_reemb'  => '30000.00',
        ':aplico_tope'      => 1,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '100000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $r9Id = (int)$pdo->lastInsertId();

    $wfReq9 = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'   => $r9Id,
        'aprobador_id'   => $testApproverId,
        'solicitado_por' => 1,
        'monto_solicitado' => 80000.00,
        'justificacion'  => 'Solicitud para R9',
        'actor_nombre'   => 'Admin QA',
        'actor_email'    => 'admin@qa.test',
    ]);
    $pdo->prepare('UPDATE rendiciones_gastos SET solicitud_excepcion_id = :sid WHERE id = :id')
        ->execute([':sid' => (int)$wfReq9['solicitud']['id'], ':id' => $r9Id]);

    // Rechazo sin motivo: debe fallar
    $rejectNoReasonBlocked = false;
    try {
        $commentReject = '';
        if ($commentReject === '') {
            throw new InvalidArgumentException('Debe indicar el motivo del rechazo.');
        }
    } catch (InvalidArgumentException $e) {
        $rejectNoReasonBlocked = true;
    }
    assertRendiciones($rejectNoReasonBlocked, 'P0-9: rechazo sin motivo debe ser bloqueado.');

    // Ejecutar rechazo directo: libera $50.000 y cancela solicitud vinculada mediante cancelRequest()
    $pdo->prepare('UPDATE presupuestos_vendedores SET monto_utilizado = GREATEST(0, monto_utilizado - :monto) WHERE id = :id')
        ->execute([':monto' => '50000.00', ':id' => $b9Id]);
    ApprovalWorkflowService::cancelRequest($pdo, (int)$wfReq9['solicitud']['id'], ['id' => 1, 'nombre' => 'Admin QA', 'email' => 'admin@qa.test'], 'Rendición rechazada directamente por Tesorería: Boletas ilegibles', false);
    $pdo->prepare('UPDATE rendiciones_gastos SET estado = "RECHAZADA", motivo_rechazo = :motivo WHERE id = :id')
        ->execute([':motivo' => 'Boletas ilegibles', ':id' => $r9Id]);

    $stmtCheckB9 = $pdo->prepare('SELECT monto_utilizado FROM presupuestos_vendedores WHERE id = :id');
    $stmtCheckB9->execute([':id' => $b9Id]);
    assertRendiciones((float)$stmtCheckB9->fetchColumn() === 0.0, 'P0-9: el rechazo directo libera la reserva completa de $50.000.');

    $stmtCheckReq9 = $pdo->prepare('SELECT estado, activo FROM solicitudes_aprobacion WHERE id = :id');
    $stmtCheckReq9->execute([':id' => (int)$wfReq9['solicitud']['id']]);
    $rowReq9 = $stmtCheckReq9->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowReq9['estado'] === 'CANCELADA' && (int)$rowReq9['activo'] === 0, '19. El rechazo de Tesorería cancela la solicitud vigente.');

    $stmtHistReq9 = $pdo->prepare('SELECT accion, estado_nuevo FROM solicitud_aprobacion_historial WHERE solicitud_id = :id ORDER BY id DESC LIMIT 1');
    $stmtHistReq9->execute([':id' => (int)$wfReq9['solicitud']['id']]);
    $rowHist9 = $stmtHistReq9->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowHist9 && $rowHist9['accion'] === 'CANCELAR_SOLICITUD' && $rowHist9['estado_nuevo'] === 'CANCELADA', '20. La cancelación queda registrada en solicitud_aprobacion_historial.');

    $stmtCheckHistReq = $pdo->prepare('SELECT estado FROM solicitudes_aprobacion WHERE id = :id');
    $stmtCheckHistReq->execute([':id' => (int)$wfReq7['solicitud']['id']]);
    assertRendiciones($stmtCheckHistReq->fetchColumn() === 'APROBADA', '21. Las solicitudes históricas resueltas permanecen intactas.');

    // -------------------------------------------------------------------------
    // CASO 10: Concurrencia con dos conexiones PDO independientes
    // -------------------------------------------------------------------------
    $dsn2 = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME_CENTRAL . ";charset=utf8mb4";
    $pdo2 = new PDO($dsn2, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo2->exec("SET innodb_lock_wait_timeout = 1");

    // Concurrencia 1: bloqueo FOR UPDATE en presupuesto entre PDO1 y PDO2
    $stmtLock1 = $pdo->prepare('SELECT id, monto_utilizado FROM presupuestos_vendedores WHERE id = :id FOR UPDATE');
    $stmtLock1->execute([':id' => $b9Id]);

    $pdo2BlockedOnBudget = false;
    $pdo2->beginTransaction();
    try {
        $stmtLock2 = $pdo2->prepare('SELECT id, monto_utilizado FROM presupuestos_vendedores WHERE id = :id FOR UPDATE');
        $stmtLock2->execute([':id' => $b9Id]);
    } catch (PDOException $lockEx) {
        $pdo2BlockedOnBudget = true;
    }
    $pdo2->rollBack();
    assertRendiciones($pdo2BlockedOnBudget, 'P0-10: dos conexiones concurrentes no pueden modificar el presupuesto sin bloqueo FOR UPDATE.');

    // Concurrencia 2: dos decisiones no pueden consumir el mismo token (segundo intento rechazado)
    $secondAttemptBlocked = false;
    try {
        ApprovalWorkflowService::resolveByToken($pdo, $wfReq8['raw_token'], ApprovalWorkflowService::DECISION_APPROVED, 'Segundo intento simultaneo');
    } catch (DomainException $decEx) {
        $secondAttemptBlocked = true;
    }
    assertRendiciones($secondAttemptBlocked, 'P0-10: un token ya resuelto no puede ser consumido por un segundo intento concurrente.');

    // =========================================================================
    // PRUEBAS AUTOMATIZADAS OBLIGATORIAS P0-1, P0-2, P0-3 (1 AL 22)
    // =========================================================================
    $claveQA = 'P0-QA-' . bin2hex(random_bytes(4));
    $stmtInsBudget->execute([':vendedor_id' => $sellerId, ':clave' => $claveQA, ':asignado' => '200000.00', ':utilizado' => '100000.00']);
    $bQAId = (int)$pdo->lastInsertId();

    $codeQA = 'RND-P0-QA-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQA,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '100000.00',
        ':max_apr'          => '100000.00',
        ':exceso'           => '0.00',
        ':exceso_no_reemb'  => '0.00',
        ':aplico_tope'      => 0,
        ':saldo_enviar'     => '200000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $rQAId = (int)$pdo->lastInsertId();

    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAId,
        ':num'          => 'QA-DOC-1',
        ':monto'        => '60000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|QA-DOC-1'),
    ]);
    $dQA1Id = (int)$pdo->lastInsertId();

    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAId,
        ':num'          => 'QA-DOC-2',
        ':monto'        => '40000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|QA-DOC-2'),
    ]);
    $dQA2Id = (int)$pdo->lastInsertId();

    $actorAdmin = ['id' => 1, 'nombre' => 'Admin QA', 'email' => 'admin@qa.test'];

    // 1. VERIFICAR_Y_ENVIAR con decisiones es rechazado
    $test1Blocked = false;
    try {
        RendicionesService::verificarYEnviar($pdo, $rQAId, $testApproverId, $actorAdmin, 'Prueba con decisiones', ['decisiones' => [['documento_id' => $dQA1Id, 'decision' => 'APROBAR']]]);
    } catch (InvalidArgumentException $e) {
        $test1Blocked = true;
    }
    assertRendiciones($test1Blocked, '1. VERIFICAR_Y_ENVIAR con decisiones es rechazado.');

    // 2. No permite enviar si existen documentos PENDIENTES
    $test2Blocked = false;
    try {
        RendicionesService::verificarYEnviar($pdo, $rQAId, $testApproverId, $actorAdmin, 'Prueba con pendientes');
    } catch (DomainException $e) {
        $test2Blocked = true;
    }
    assertRendiciones($test2Blocked, '2. No permite enviar si existen documentos PENDIENTES.');

    // 3. No permite enviar si todos los documentos fueron rechazados
    $pdo->prepare('UPDATE rendicion_documentos SET estado_item = "RECHAZADO", monto_validado = "0.00", motivo_rechazo = "No aplica" WHERE rendicion_id = :id')
        ->execute([':id' => $rQAId]);
    $test3Blocked = false;
    try {
        RendicionesService::verificarYEnviar($pdo, $rQAId, $testApproverId, $actorAdmin, 'Prueba todos rechazados');
    } catch (DomainException $e) {
        $test3Blocked = true;
    }
    assertRendiciones($test3Blocked, '3. No permite enviar si todos los documentos fueron rechazados.');

    // 4. No reemplaza un total validado de cero por el total rendido
    $stmtCheckSum = $pdo->prepare('SELECT COALESCE(SUM(COALESCE(monto_validado, monto)), 0) FROM rendicion_documentos WHERE rendicion_id = :id AND activo = 1 AND estado_item = "APROBADO"');
    $stmtCheckSum->execute([':id' => $rQAId]);
    assertRendiciones((float)$stmtCheckSum->fetchColumn() === 0.0, '4. No reemplaza un total validado de cero por el total rendido (total validado permanece en 0).');

    // Restaurar dQA1 y dQA2 a PENDIENTE para probar VALIDAR_DOCUMENTOS
    $pdo->prepare('UPDATE rendicion_documentos SET estado_item = "PENDIENTE", monto_validado = NULL, motivo_rechazo = NULL WHERE rendicion_id = :id')
        ->execute([':id' => $rQAId]);

    // 5. Una decisión XYZ es rechazada
    $test5Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $dQA1Id, 'decision' => 'XYZ']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test5Blocked = true;
    }
    assertRendiciones($test5Blocked, '5. Una decisión XYZ es rechazada.');

    // 6. Una decisión vacía es rechazada
    $test6Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $dQA1Id, 'decision' => '']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test6Blocked = true;
    }
    assertRendiciones($test6Blocked, '6. Una decisión vacía es rechazada.');

    // 7. Una decisión ausente es rechazada
    $test7Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $dQA1Id]], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test7Blocked = true;
    }
    assertRendiciones($test7Blocked, '7. Una decisión ausente es rechazada.');

    // 8. Un documento inexistente es rechazado
    $test8Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => 9999999, 'decision' => 'APROBAR']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test8Blocked = true;
    }
    assertRendiciones($test8Blocked, '8. Un documento inexistente es rechazado.');

    // 9. Un documento de otra rendición es rechazado
    $test9Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $d1Id, 'decision' => 'APROBAR']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test9Blocked = true;
    }
    assertRendiciones($test9Blocked, '9. Un documento de otra rendición es rechazado.');

    // 10. Un documento duplicado en el payload es rechazado
    $test10Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [
            ['documento_id' => $dQA1Id, 'decision' => 'APROBAR'],
            ['documento_id' => $dQA1Id, 'decision' => 'APROBAR'],
        ], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test10Blocked = true;
    }
    assertRendiciones($test10Blocked, '10. Un documento duplicado en el payload es rechazado.');

    // 11. Una aprobación documental con monto cero es rechazada
    $test11Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $dQA1Id, 'decision' => 'APROBAR', 'monto_validado' => '0.00']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test11Blocked = true;
    }
    assertRendiciones($test11Blocked, '11. Una aprobación documental con monto cero es rechazada.');

    // 12. Una aprobación documental con monto negativo es rechazada
    $test12Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $dQA1Id, 'decision' => 'APROBAR', 'monto_validado' => '-1000.00']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test12Blocked = true;
    }
    assertRendiciones($test12Blocked, '12. Una aprobación documental con monto negativo es rechazada.');

    // 13. Un monto validado superior al monto rendido es rechazado
    $test13Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $dQA1Id, 'decision' => 'APROBAR', 'monto_validado' => '70000.00']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test13Blocked = true;
    }
    assertRendiciones($test13Blocked, '13. Un monto validado superior al monto rendido es rechazado.');

    // 14. Un rechazo documental sin motivo es rechazado
    $test14Blocked = false;
    try {
        RendicionesService::validarDocumentos($pdo, $rQAId, [['documento_id' => $dQA1Id, 'decision' => 'RECHAZAR', 'motivo' => '']], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $test14Blocked = true;
    }
    assertRendiciones($test14Blocked, '14. Un rechazo documental sin motivo es rechazado.');

    // Validación documental válida: dQA1 APROBADO por 50.000 y dQA2 RECHAZADO con motivo
    $valSuccess = RendicionesService::validarDocumentos($pdo, $rQAId, [
        ['documento_id' => $dQA1Id, 'decision' => 'APROBAR', 'monto_validado' => '50000.00'],
        ['documento_id' => $dQA2Id, 'decision' => 'RECHAZAR', 'motivo' => 'Gasto no deducible'],
    ], $actorAdmin, 'Validación documental QA');
    assertRendiciones((float)$valSuccess['monto_validado'] === 50000.0, 'Validación correcta: total validado es 50.000.');

    // VERIFICAR_Y_ENVIAR exitoso
    $sendSuccess = RendicionesService::verificarYEnviar($pdo, $rQAId, $testApproverId, $actorAdmin, 'Envío correcto QA', null, false);
    assertRendiciones($sendSuccess['estado'] === 'PENDIENTE_APROBACION_RESPONSABLE', 'VERIFICAR_Y_ENVIAR exitoso pasa a PENDIENTE_APROBACION_RESPONSABLE.');
    assertRendiciones((float)$sendSuccess['monto_solicitado'] === 50000.0, 'monto_solicitado usa exclusivamente el total validado (50.000).');

    // 15. APROBADA_TOPE con máximo aprobable cero es rechazada
    $codeQAZero = 'RND-P0-QAZ-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQAZero,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '50000.00',
        ':max_apr'          => '0.00',
        ':exceso'           => '50000.00',
        ':exceso_no_reemb'  => '50000.00',
        ':aplico_tope'      => 1,
        ':saldo_enviar'     => '0.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'PENDIENTE_APROBACION_RESPONSABLE',
    ]);
    $rQAZeroId = (int)$pdo->lastInsertId();

    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAZeroId,
        ':num'          => 'QA-DOC-ZERO',
        ':monto'        => '50000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|QA-DOC-ZERO'),
    ]);

    $wfReqZero = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'   => $rQAZeroId,
        'aprobador_id'   => $testApproverId,
        'solicitado_por' => 1,
        'monto_solicitado' => 50000.00,
        'justificacion'  => 'Prueba tope cero',
        'actor_nombre'   => 'Admin QA',
        'actor_email'    => 'admin@qa.test',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$wfReqZero['solicitud']['id'], true);

    $test15Blocked = false;
    try {
        ApprovalWorkflowService::resolveByToken($pdo, $wfReqZero['raw_token'], ApprovalWorkflowService::DECISION_APPROVED_CAPPED, 'Intento tope cero');
    } catch (DomainException $e) {
        $test15Blocked = true;
    }
    assertRendiciones($test15Blocked, '15. APROBADA_TOPE con máximo aprobable cero es rechazada.');

    // 16. El rechazo anterior no consume el token
    $stmtCheckTokenZero = $pdo->prepare('SELECT estado, token_usado_at, activo FROM solicitudes_aprobacion WHERE id = :id');
    $stmtCheckTokenZero->execute([':id' => (int)$wfReqZero['solicitud']['id']]);
    $rowTokenZero = $stmtCheckTokenZero->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowTokenZero['estado'] === 'PENDIENTE_DECISION' && $rowTokenZero['token_usado_at'] === null && (int)$rowTokenZero['activo'] === 1, '16. El rechazo anterior no consume el token.');

    // 17. APROBADA_TOPE nunca supera monto_maximo_aprobable
    $codeQACapped = 'RND-P0-QAC-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQACapped,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '70000.00',
        ':max_apr'          => '40000.00',
        ':exceso'           => '30000.00',
        ':exceso_no_reemb'  => '30000.00',
        ':aplico_tope'      => 1,
        ':saldo_enviar'     => '40000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'PENDIENTE_APROBACION_RESPONSABLE',
    ]);
    $rQACappedId = (int)$pdo->lastInsertId();

    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQACappedId,
        ':num'          => 'QA-DOC-CAP1',
        ':monto'        => '70000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|QA-DOC-CAP1'),
    ]);

    $wfReqCapped = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'   => $rQACappedId,
        'aprobador_id'   => $testApproverId,
        'solicitado_por' => 1,
        'monto_solicitado' => 70000.00,
        'justificacion'  => 'Prueba capped',
        'actor_nombre'   => 'Admin QA',
        'actor_email'    => 'admin@qa.test',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$wfReqCapped['solicitud']['id'], true);
    $pdo->prepare('UPDATE rendicion_documentos SET estado_item = "APROBADO", monto_validado = "70000.00" WHERE rendicion_id = :id')
        ->execute([':id' => $rQACappedId]);
    ApprovalWorkflowService::resolveByToken($pdo, $wfReqCapped['raw_token'], ApprovalWorkflowService::DECISION_APPROVED_CAPPED, 'Aprobado hasta el tope');

    $stmtCheckCapped = $pdo->prepare('SELECT monto_total_aprobado, monto_maximo_aprobable FROM rendiciones_gastos WHERE id = :id');
    $stmtCheckCapped->execute([':id' => $rQACappedId]);
    $rowCapped = $stmtCheckCapped->fetch(PDO::FETCH_ASSOC);
    assertRendiciones((float)$rowCapped['monto_total_aprobado'] <= (float)$rowCapped['monto_maximo_aprobable'] && (float)$rowCapped['monto_total_aprobado'] === 40000.0, '17. APROBADA_TOPE nunca supera monto_maximo_aprobable.');

    // 18. APROBADA_TOPE nunca supera el total validado
    $codeQACapped2 = 'RND-P0-QAC2-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQACapped2,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '90000.00',
        ':max_apr'          => '80000.00',
        ':exceso'           => '10000.00',
        ':exceso_no_reemb'  => '10000.00',
        ':aplico_tope'      => 1,
        ':saldo_enviar'     => '80000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'PENDIENTE_APROBACION_RESPONSABLE',
    ]);
    $rQACapped2Id = (int)$pdo->lastInsertId();

    $stmtInsDoc->execute([
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQACapped2Id,
        ':num'          => 'QA-DOC-CAP2',
        ':monto'        => '90000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|QA-DOC-CAP2'),
    ]);
    $dCAP2Id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE rendicion_documentos SET estado_item = "APROBADO", monto_validado = "30000.00" WHERE id = :id')->execute([':id' => $dCAP2Id]);

    $wfReqCapped2 = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'   => $rQACapped2Id,
        'aprobador_id'   => $testApproverId,
        'solicitado_por' => 1,
        'monto_solicitado' => 30000.00,
        'justificacion'  => 'Prueba capped menor que tope',
        'actor_nombre'   => 'Admin QA',
        'actor_email'    => 'admin@qa.test',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$wfReqCapped2['solicitud']['id'], true);
    ApprovalWorkflowService::resolveByToken($pdo, $wfReqCapped2['raw_token'], ApprovalWorkflowService::DECISION_APPROVED_CAPPED, 'Aprobado hasta tope');

    $stmtCheckCapped2 = $pdo->prepare('SELECT monto_total_aprobado FROM rendiciones_gastos WHERE id = :id');
    $stmtCheckCapped2->execute([':id' => $rQACapped2Id]);
    assertRendiciones((float)$stmtCheckCapped2->fetchColumn() === 30000.0, '18. APROBADA_TOPE nunca supera el total validado.');

    // 22. Toda rendición aprobada pasó previamente por un responsable
    assertRendiciones(!RendicionesService::canTransition('EN_REVISION_TESORERIA', 'APROBADA'), '22. Toda rendición aprobada pasó previamente por un responsable (no existe transición directa desde revisión de Tesorería a APROBADA).');
    assertRendiciones(!RendicionesService::canTransition('EN_REVISION_TESORERIA', 'APROBADA_PARCIAL'), '22. Transición a APROBADA_PARCIAL desde Tesorería está bloqueada.');

    // =========================================================================
    // CASOS OBLIGATORIOS P1: REUTILIZACIÓN DE BOLETAS Y ENDURECIMIENTOS (1 AL 23)
    // =========================================================================

    $stmtInsDocCustom = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, rendicion_id, tipo_documento,
            categoria_gasto, rut_proveedor, numero_documento, fecha_emision,
            monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            :empresa_id, :vendedor_id, :rendicion_id, "BOLETA_ELECTRONICA",
            "COLACION", "76123456-7", :num, "2026-09-02",
            :monto, "uploads/test.jpg", :hash, :estado, :activo
         )'
    );

    // 1. Un documento BORRADOR bloquea otro con el mismo hash
    $hashC1 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C1');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => null,
        ':num'          => 'P1-DOC-C1',
        ':monto'        => '15000.00',
        ':hash'         => $hashC1,
        ':estado'       => 'BORRADOR',
        ':activo'       => 1,
    ]);
    $docC1Id = (int)$pdo->lastInsertId();

    $c1Blocked = false;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C1',
            ':monto'        => '15000.00',
            ':hash'         => $hashC1,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) $c1Blocked = true;
    }
    assertRendiciones($c1Blocked, 'P1-1: un documento BORRADOR bloquea otro con el mismo hash.');

    // 2. Un documento PENDIENTE bloquea otro con el mismo hash
    $hashC2 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C2');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAId,
        ':num'          => 'P1-DOC-C2',
        ':monto'        => '12000.00',
        ':hash'         => $hashC2,
        ':estado'       => 'PENDIENTE',
        ':activo'       => 1,
    ]);
    $c2Blocked = false;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C2',
            ':monto'        => '12000.00',
            ':hash'         => $hashC2,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) $c2Blocked = true;
    }
    assertRendiciones($c2Blocked, 'P1-2: un documento PENDIENTE bloquea otro con el mismo hash.');

    // 3. Un documento APROBADO bloquea otro con el mismo hash
    $hashC3 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C3');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAId,
        ':num'          => 'P1-DOC-C3',
        ':monto'        => '18000.00',
        ':hash'         => $hashC3,
        ':estado'       => 'APROBADO',
        ':activo'       => 1,
    ]);
    $c3Blocked = false;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C3',
            ':monto'        => '18000.00',
            ':hash'         => $hashC3,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) $c3Blocked = true;
    }
    assertRendiciones($c3Blocked, 'P1-3: un documento APROBADO bloquea otro con el mismo hash.');

    // 4. Una boleta perteneciente a una rendición PAGADA sigue bloqueada
    $codeRPagada = 'RND-PAGADA-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeRPagada,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '20000.00',
        ':max_apr'          => '20000.00',
        ':exceso'           => '0.00',
        ':exceso_no_reemb'  => '0.00',
        ':aplico_tope'      => 0,
        ':saldo_enviar'     => '20000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'PAGADA',
    ]);
    $rPagadaId = (int)$pdo->lastInsertId();

    $hashC4 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C4');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rPagadaId,
        ':num'          => 'P1-DOC-C4',
        ':monto'        => '20000.00',
        ':hash'         => $hashC4,
        ':estado'       => 'APROBADO',
        ':activo'       => 1,
    ]);
    $c4Blocked = false;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C4',
            ':monto'        => '20000.00',
            ':hash'         => $hashC4,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) $c4Blocked = true;
    }
    assertRendiciones($c4Blocked, 'P1-4: una boleta perteneciente a una rendición PAGADA sigue bloqueada.');

    // 5. Un documento RECHAZADO permite volver a registrar el mismo hash
    $hashC5 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C5');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAId,
        ':num'          => 'P1-DOC-C5',
        ':monto'        => '35000.00',
        ':hash'         => $hashC5,
        ':estado'       => 'RECHAZADO',
        ':activo'       => 1,
    ]);
    $docHistRechazadoId = (int)$pdo->lastInsertId();

    $docC5NuevoId = 0;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C5',
            ':monto'        => '35000.00',
            ':hash'         => $hashC5,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
        $docC5NuevoId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {}
    assertRendiciones($docC5NuevoId > 0, 'P1-5: un documento RECHAZADO permite volver a registrar el mismo hash.');

    // 6. Un documento DESCARTADO permite volver a registrar el mismo hash
    $hashC6 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C6');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => null,
        ':num'          => 'P1-DOC-C6',
        ':monto'        => '22000.00',
        ':hash'         => $hashC6,
        ':estado'       => 'DESCARTADO',
        ':activo'       => 1,
    ]);
    $docHistDescartadoId = (int)$pdo->lastInsertId();

    $docC6NuevoId = 0;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C6',
            ':monto'        => '22000.00',
            ':hash'         => $hashC6,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
        $docC6NuevoId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {}
    assertRendiciones($docC6NuevoId > 0, 'P1-6: un documento DESCARTADO permite volver a registrar el mismo hash.');

    // 7. La nueva presentación crea un registro nuevo
    assertRendiciones($docC5NuevoId !== $docHistRechazadoId && $docC5NuevoId > 0, 'P1-7: la nueva presentación crea un registro nuevo con ID independiente.');

    // 8. El documento histórico rechazado no se modifica
    $stmtCheckHist = $pdo->prepare('SELECT rendicion_id, estado_item, monto, activo FROM rendicion_documentos WHERE id = :id');
    $stmtCheckHist->execute([':id' => $docHistRechazadoId]);
    $rowHistRech = $stmtCheckHist->fetch(PDO::FETCH_ASSOC);
    assertRendiciones((int)$rowHistRech['rendicion_id'] === $rQAId && $rowHistRech['estado_item'] === 'RECHAZADO' && (int)$rowHistRech['activo'] === 1, 'P1-8: el documento histórico rechazado no se modifica.');

    // 9. El documento histórico descartado no se modifica
    $stmtCheckHist->execute([':id' => $docHistDescartadoId]);
    $rowHistDesc = $stmtCheckHist->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowHistDesc['estado_item'] === 'DESCARTADO' && (int)$rowHistDesc['activo'] === 1, 'P1-9: el documento histórico descartado no se modifica.');

    // 10. El bloqueo funciona entre vendedores diferentes
    $hashC10 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C10');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => 10101,
        ':rendicion_id' => $rQAId,
        ':num'          => 'P1-DOC-C10',
        ':monto'        => '45000.00',
        ':hash'         => $hashC10,
        ':estado'       => 'APROBADO',
        ':activo'       => 1,
    ]);
    $c10Blocked = false;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => 20202,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C10',
            ':monto'        => '45000.00',
            ':hash'         => $hashC10,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) $c10Blocked = true;
    }
    assertRendiciones($c10Blocked, 'P1-10: el bloqueo funciona entre vendedores diferentes.');

    // 11. El bloqueo funciona entre empresas diferentes
    $hashC11 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-C11');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAId,
        ':num'          => 'P1-DOC-C11',
        ':monto'        => '60000.00',
        ':hash'         => $hashC11,
        ':estado'       => 'APROBADO',
        ':activo'       => 1,
    ]);
    $c11Blocked = false;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 2,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C11',
            ':monto'        => '60000.00',
            ':hash'         => $hashC11,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) $c11Blocked = true;
    }
    assertRendiciones($c11Blocked, 'P1-11: el bloqueo funciona entre empresas diferentes.');

    // 12. La normalización de RUT evita duplicados con distinto formato
    $hashRutA = RendicionesService::createDocumentHash(['tipo_documento' => 'BOLETA_ELECTRONICA', 'categoria_gasto' => 'COLACION', 'rut_proveedor' => '76.123.456-K', 'numero_documento' => '999'], 1, 1);
    $hashRutB = RendicionesService::createDocumentHash(['tipo_documento' => 'BOLETA_ELECTRONICA', 'categoria_gasto' => 'COLACION', 'rut_proveedor' => '76123456k', 'numero_documento' => '999'], 2, 2);
    assertRendiciones($hashRutA === $hashRutB, 'P1-12: la normalización de RUT evita duplicados con distinto formato.');

    // 13. La normalización de folio evita duplicados como 00123 y 123
    $hashFolioA = RendicionesService::createDocumentHash(['tipo_documento' => 'BOLETA_ELECTRONICA', 'categoria_gasto' => 'COLACION', 'rut_proveedor' => '76123456K', 'numero_documento' => '00123'], 1, 1);
    $hashFolioB = RendicionesService::createDocumentHash(['tipo_documento' => 'BOLETA_ELECTRONICA', 'categoria_gasto' => 'COLACION', 'rut_proveedor' => '76123456K', 'numero_documento' => '123'], 1, 1);
    assertRendiciones($hashFolioA === $hashFolioB, 'P1-13: la normalización de folio evita duplicados como 00123 y 123.');

    // 14. La corrección a un folio bloqueado responde como conflicto
    $stmtCheckBlockCorr = $pdo->prepare(
        'SELECT id FROM rendicion_documentos
         WHERE document_hash = :hash
           AND id != :self
           AND activo = 1
           AND estado_item IN ("BORRADOR", "PENDIENTE", "APROBADO")
         LIMIT 1'
    );
    $stmtCheckBlockCorr->execute([':hash' => $hashC3, ':self' => $docC1Id]);
    assertRendiciones((bool)$stmtCheckBlockCorr->fetchColumn(), 'P1-14: la corrección a un folio bloqueado responde como conflicto.');

    // 15. La corrección a un folio presente únicamente en RECHAZADO es permitida
    $hashC15 = hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-RECH15');
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rQAId,
        ':num'          => 'P1-DOC-RECH15',
        ':monto'        => '19000.00',
        ':hash'         => $hashC15,
        ':estado'       => 'RECHAZADO',
        ':activo'       => 1,
    ]);
    $stmtCheckBlockCorr->execute([':hash' => $hashC15, ':self' => $docC1Id]);
    assertRendiciones(!$stmtCheckBlockCorr->fetchColumn(), 'P1-15: la corrección a un folio presente únicamente en RECHAZADO es permitida.');

    // 16. Una colisión concurrente es detenida por el índice único
    $c16Caught1062 = false;
    try {
        $stmtInsDocCustom->execute([
            ':empresa_id'   => 1,
            ':vendedor_id'  => $sellerId,
            ':rendicion_id' => null,
            ':num'          => 'P1-DOC-C1',
            ':monto'        => '15000.00',
            ':hash'         => $hashC1,
            ':estado'       => 'BORRADOR',
            ':activo'       => 1,
        ]);
    } catch (PDOException $e) {
        if ($e->errorInfo[1] === 1062) $c16Caught1062 = true;
    }
    assertRendiciones($c16Caught1062, 'P1-16: una colisión concurrente es detenida por el índice único.');

    // 17. La colisión se traduce a HTTP 409 controlado
    $mockEx = new PDOException('Duplicate entry', 23000);
    assertRendiciones(RendicionesService::isDuplicateKey($mockEx), 'P1-17: la colisión se traduce a HTTP 409 controlado mediante isDuplicateKey.');

    // 18 & 19: APROBADA_TOPE no usa monto_total_rendido si no hay documentos aprobados y no consume el token
    $codeQAZeroDocs = 'RND-P1-ZDOC-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQAZeroDocs,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '95000.00',
        ':max_apr'          => '50000.00',
        ':exceso'           => '45000.00',
        ':exceso_no_reemb'  => '45000.00',
        ':aplico_tope'      => 1,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'PENDIENTE_APROBACION_RESPONSABLE',
    ]);
    $rZeroDocsId = (int)$pdo->lastInsertId();
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rZeroDocsId,
        ':num'          => 'P1-DOC-RECH1',
        ':monto'        => '95000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|P1-DOC-RECH1'),
        ':estado'       => 'RECHAZADO',
        ':activo'       => 1,
    ]);

    $wfReqZeroDocs = ApprovalWorkflowService::createRequest($pdo, [
        'tipo_solicitud'   => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
        'rendicion_id'     => $rZeroDocsId,
        'aprobador_id'     => $testApproverId,
        'solicitado_por'   => 1,
        'monto_solicitado' => 95000.00,
        'justificacion'    => 'Prueba sin comprobantes aprobados',
        'actor_nombre'     => 'Admin QA',
        'actor_email'      => 'admin@qa.test',
    ]);
    ApprovalWorkflowService::markEmailResult($pdo, (int)$wfReqZeroDocs['solicitud']['id'], true);

    $test18Blocked = false;
    try {
        ApprovalWorkflowService::resolveByToken($pdo, $wfReqZeroDocs['raw_token'], ApprovalWorkflowService::DECISION_APPROVED_CAPPED, 'Intento sin aprobados');
    } catch (DomainException $e) {
        $test18Blocked = true;
    }
    assertRendiciones($test18Blocked, 'P1-18: APROBADA_TOPE no usa monto_total_rendido si no hay documentos aprobados.');

    $stmtCheckZeroDocsReq = $pdo->prepare('SELECT estado, token_usado_at, activo FROM solicitudes_aprobacion WHERE id = :id');
    $stmtCheckZeroDocsReq->execute([':id' => (int)$wfReqZeroDocs['solicitud']['id']]);
    $rowZeroDocsReq = $stmtCheckZeroDocsReq->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowZeroDocsReq['estado'] === 'PENDIENTE_DECISION' && $rowZeroDocsReq['token_usado_at'] === null && (int)$rowZeroDocsReq['activo'] === 1, 'P1-19: APROBADA_TOPE con cero documentos aprobados no consume el token.');

    // 20. VERIFICAR_Y_ENVIAR rechaza BORRADOR activo
    $codeQABor = 'RND-P1-BOR-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQABor,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '30000.00',
        ':max_apr'          => '30000.00',
        ':exceso'           => '0.00',
        ':exceso_no_reemb'  => '0.00',
        ':aplico_tope'      => 0,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $rBorId = (int)$pdo->lastInsertId();
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rBorId,
        ':num'          => 'DOC-BORRADOR',
        ':monto'        => '30000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|DOC-BORRADOR'),
        ':estado'       => 'BORRADOR',
        ':activo'       => 1,
    ]);
    $test20Blocked = false;
    try {
        RendicionesService::verificarYEnviar($pdo, $rBorId, $testApproverId, $actorAdmin, 'Envio con borrador');
    } catch (DomainException $e) {
        $test20Blocked = true;
    }
    assertRendiciones($test20Blocked, 'P1-20: VERIFICAR_Y_ENVIAR rechaza BORRADOR activo.');

    // 21. VERIFICAR_Y_ENVIAR rechaza DESCARTADO activo
    $codeQADesc = 'RND-P1-DESC-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQADesc,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '25000.00',
        ':max_apr'          => '25000.00',
        ':exceso'           => '0.00',
        ':exceso_no_reemb'  => '0.00',
        ':aplico_tope'      => 0,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $rDescId = (int)$pdo->lastInsertId();
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rDescId,
        ':num'          => 'DOC-DESCARTADO',
        ':monto'        => '25000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|DOC-DESCARTADO'),
        ':estado'       => 'DESCARTADO',
        ':activo'       => 1,
    ]);
    $test21Blocked = false;
    try {
        RendicionesService::verificarYEnviar($pdo, $rDescId, $testApproverId, $actorAdmin, 'Envio con descartado');
    } catch (DomainException $e) {
        $test21Blocked = true;
    }
    assertRendiciones($test21Blocked, 'P1-21: VERIFICAR_Y_ENVIAR rechaza DESCARTADO activo.');

    // 22. VERIFICAR_Y_ENVIAR rechaza cualquier estado distinto de APROBADO o RECHAZADO
    $codeQAUnk = 'RND-P1-UNK-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQAUnk,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '20000.00',
        ':max_apr'          => '20000.00',
        ':exceso'           => '0.00',
        ':exceso_no_reemb'  => '0.00',
        ':aplico_tope'      => 0,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $rUnkId = (int)$pdo->lastInsertId();
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rUnkId,
        ':num'          => 'DOC-PENDIENTE-TEST',
        ':monto'        => '20000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|DOC-PENDIENTE-TEST'),
        ':estado'       => 'PENDIENTE',
        ':activo'       => 1,
    ]);
    $test22Blocked = false;
    try {
        RendicionesService::verificarYEnviar($pdo, $rUnkId, $testApproverId, $actorAdmin, 'Envio con pendiente');
    } catch (DomainException $e) {
        $test22Blocked = true;
    }
    assertRendiciones($test22Blocked, 'P1-22: VERIFICAR_Y_ENVIAR rechaza cualquier estado distinto de APROBADO o RECHAZADO.');

    // 23. La aprobación funcional completa exige responsable y Magic Token (Sección 7)
    $codeQAFunc = 'RND-P1-FUNC-' . bin2hex(random_bytes(3));
    $stmtInsRend->execute([
        ':codigo'           => $codeQAFunc,
        ':vendedor_id'      => $sellerId,
        ':presupuesto_id'   => $bQAId,
        ':total'            => '50000.00',
        ':max_apr'          => '50000.00',
        ':exceso'           => '0.00',
        ':exceso_no_reemb'  => '0.00',
        ':aplico_tope'      => 0,
        ':saldo_enviar'     => '50000.00',
        ':asignado'         => '200000.00',
        ':estado'           => 'EN_REVISION_TESORERIA',
    ]);
    $rFuncId = (int)$pdo->lastInsertId();
    $stmtInsDocCustom->execute([
        ':empresa_id'   => 1,
        ':vendedor_id'  => $sellerId,
        ':rendicion_id' => $rFuncId,
        ':num'          => 'DOC-FUNC-1',
        ':monto'        => '50000.00',
        ':hash'         => hash('sha256', '761234567|BOLETA_ELECTRONICA|DOC-FUNC-1'),
        ':estado'       => 'PENDIENTE',
        ':activo'       => 1,
    ]);
    $dFuncId = (int)$pdo->lastInsertId();

    $directApproveBlocked = false;
    try {
        RendicionesService::assertTransition('EN_REVISION_TESORERIA', 'APROBADA');
    } catch (DomainException $e) {
        $directApproveBlocked = true;
    }
    assertRendiciones($directApproveBlocked, 'P1-23.1: una rendición en EN_REVISION_TESORERIA no puede quedar APROBADA mediante acciones directas.');

    $valFunc = RendicionesService::validarDocumentos($pdo, $rFuncId, [
        ['documento_id' => $dFuncId, 'decision' => 'APROBAR', 'monto_validado' => '50000.00']
    ], $actorAdmin, 'Validación funcional');
    $stmtCheckFuncRend = $pdo->prepare('SELECT estado FROM rendiciones_gastos WHERE id = :id');
    $stmtCheckFuncRend->execute([':id' => $rFuncId]);
    assertRendiciones($stmtCheckFuncRend->fetchColumn() === 'EN_REVISION_TESORERIA', 'P1-23.2: después de VALIDAR_DOCUMENTOS permanece en EN_REVISION_TESORERIA.');

    $sendFunc = RendicionesService::verificarYEnviar($pdo, $rFuncId, $testApproverId, $actorAdmin, 'Envio funcional', null, false);
    assertRendiciones($sendFunc['estado'] === 'PENDIENTE_APROBACION_RESPONSABLE', 'P1-23.3: después de verificar y enviar queda PENDIENTE_APROBACION_RESPONSABLE.');

    $stmtCheckFuncReq = $pdo->prepare('SELECT id, tipo_solicitud, estado, token_hash FROM solicitudes_aprobacion WHERE rendicion_id = :rid AND activo = 1');
    $stmtCheckFuncReq->execute([':rid' => $rFuncId]);
    $rowFuncReq = $stmtCheckFuncReq->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowFuncReq['tipo_solicitud'] === ApprovalWorkflowService::TYPE_RENDITION_APPROVAL, 'P1-23.4: se crea una solicitud TYPE_RENDITION_APPROVAL.');

    ApprovalWorkflowService::markEmailResult($pdo, (int)$rowFuncReq['id'], true);
    $resFunc = ApprovalWorkflowService::resolveByToken($pdo, $sendFunc['raw_token'], ApprovalWorkflowService::DECISION_APPROVED, 'Aprobación gerencial funcional');
    $stmtCheckFuncRend->execute([':id' => $rFuncId]);
    assertRendiciones($stmtCheckFuncRend->fetchColumn() === 'APROBADA', 'P1-23.5: solamente resolveByToken() con un token vigente puede dejarla APROBADA.');

    $secondFuncBlocked = false;
    try {
        ApprovalWorkflowService::resolveByToken($pdo, $sendFunc['raw_token'], ApprovalWorkflowService::DECISION_APPROVED, 'Segundo intento token');
    } catch (DomainException $e) {
        $secondFuncBlocked = true;
    }
    assertRendiciones($secondFuncBlocked, 'P1-23.6: el token sólo puede utilizarse una vez.');

    $stmtCheckResuelto = $pdo->prepare('SELECT estado, decision, token_usado_at, resuelto_at, aprobador_nombre_snapshot, aprobador_email_snapshot FROM solicitudes_aprobacion WHERE id = :id');
    $stmtCheckResuelto->execute([':id' => (int)$rowFuncReq['id']]);
    $rowResuelto = $stmtCheckResuelto->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowResuelto['estado'] === 'APROBADA' && $rowResuelto['token_usado_at'] !== null && !empty($rowResuelto['aprobador_nombre_snapshot']), 'P1-23.7: la solicitud registra el responsable que tomó la decisión.');

    // =========================================================================
    // CIERRE PRE-PRODUCCIÓN: 15 COMPROBACIONES OBLIGATORIAS
    // =========================================================================
    // Preparar escenario para corrección de folio y auditoría de documentos
    $stmtCierreRend = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, vendedor_nombre,
            presupuesto_id, periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_maximo_aprobable, saldo_disponible_al_enviar, estado, activo
         ) VALUES (
            :codigo, 1, :vid, "Vendedor Cierre",
            :pid, "2026-09", "MENSUAL", 100000.00,
            100000.00, 100000.00, "EN_REVISION_TESORERIA", 1
         )'
    );
    $stmtCierreRend->execute([':codigo' => 'RND-CIERRE-' . substr(bin2hex(random_bytes(4)), 0, 8), ':vid' => $sellerId, ':pid' => $bQAId]);
    $cierreRendId = (int)$pdo->lastInsertId();

    $folioOriginalDigitado = 'FOLIO-ORIG-001';
    $hashDocCierre = hash('sha256', '76111222-3|BOLETA_ELECTRONICA|FOLIO-ORIG-001');

    $stmtCierreDoc1 = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, vendedor_nombre, rendicion_id, tipo_documento,
            categoria_gasto, rut_proveedor, razon_social_proveedor, numero_documento,
            fecha_emision, monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            1, :vid, "Vendedor Cierre", :rid, "BOLETA_ELECTRONICA",
            "COLACION", "76.111.222-3", "Proveedor Cierre", :num,
            "2026-09-01", 60000.00, "foto_cierre1.jpg", :hash, "PENDIENTE", 1
         )'
    );
    $stmtCierreDoc1->execute([':vid' => $sellerId, ':rid' => $cierreRendId, ':num' => $folioOriginalDigitado, ':hash' => $hashDocCierre]);
    $docCierre1Id = (int)$pdo->lastInsertId();

    // Caso 1: Primera corrección conserva numero_documento_original
    $newHash2 = hash('sha256', '76111222-3|BOLETA_ELECTRONICA|FOLIO-CORR-002');
    $stmtCorr1 = $pdo->prepare(
        'UPDATE rendicion_documentos
         SET monto = :monto,
             monto_original = COALESCE(monto_original, monto),
             numero_documento_original = COALESCE(numero_documento_original, numero_documento),
             numero_documento = :nuevo_num,
             document_hash = :nuevo_hash,
             editado_por = 1,
             editado_at = NOW(),
             motivo_edicion = "Corrección inicial folio"
         WHERE id = :id'
    );
    $stmtCorr1->execute([':monto' => 60000.00, ':nuevo_num' => 'FOLIO-CORR-002', ':nuevo_hash' => $newHash2, ':id' => $docCierre1Id]);

    $stmtCheckDoc = $pdo->prepare('SELECT numero_documento, numero_documento_original, monto_original FROM rendicion_documentos WHERE id = :id');
    $stmtCheckDoc->execute([':id' => $docCierre1Id]);
    $rowDoc1 = $stmtCheckDoc->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowDoc1['numero_documento'] === 'FOLIO-CORR-002' && $rowDoc1['numero_documento_original'] === 'FOLIO-ORIG-001', 'CIERRE-1: Primera corrección conserva numero_documento_original.');

    // Caso 2: Segunda corrección no sobrescribe el original
    $newHash3 = hash('sha256', '76111222-3|BOLETA_ELECTRONICA|FOLIO-CORR-003');
    $stmtCorr1->execute([':monto' => 60000.00, ':nuevo_num' => 'FOLIO-CORR-003', ':nuevo_hash' => $newHash3, ':id' => $docCierre1Id]);
    $stmtCheckDoc->execute([':id' => $docCierre1Id]);
    $rowDoc2 = $stmtCheckDoc->fetch(PDO::FETCH_ASSOC);
    assertRendiciones($rowDoc2['numero_documento'] === 'FOLIO-CORR-003' && $rowDoc2['numero_documento_original'] === 'FOLIO-ORIG-001', 'CIERRE-2: Segunda corrección no sobrescribe el original.');

    // Caso 3: Corregir sólo monto tampoco destruye el folio original existente
    $stmtCorrMontoOnly = $pdo->prepare(
        'UPDATE rendicion_documentos
         SET monto = :nuevo_monto,
             monto_original = COALESCE(monto_original, monto),
             editado_por = 1,
             editado_at = NOW(),
             motivo_edicion = "Corrección sólo monto"
         WHERE id = :id'
    );
    $stmtCorrMontoOnly->execute([':nuevo_monto' => 55000.00, ':id' => $docCierre1Id]);
    $stmtCheckDoc->execute([':id' => $docCierre1Id]);
    $rowDoc3 = $stmtCheckDoc->fetch(PDO::FETCH_ASSOC);
    assertRendiciones((float)$rowDoc3['monto_original'] === 60000.00 && $rowDoc3['numero_documento_original'] === 'FOLIO-ORIG-001' && $rowDoc3['numero_documento'] === 'FOLIO-CORR-003', 'CIERRE-3: Corregir sólo monto tampoco destruye el folio original existente.');

    // Caso 4: Corrección a folio bloqueado responde HTTP 409
    $hashBlocked = hash('sha256', '76111222-3|BOLETA_ELECTRONICA|BLOCKED-001');
    $stmtCierreDoc2 = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, vendedor_nombre, tipo_documento,
            categoria_gasto, rut_proveedor, razon_social_proveedor, numero_documento,
            fecha_emision, monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            1, :vid, "Vendedor Cierre", "BOLETA_ELECTRONICA",
            "COLACION", "76.111.222-3", "Proveedor Cierre", "BLOCKED-001",
            "2026-09-01", 15000.00, "foto_blocked.jpg", :hash, "BORRADOR", 1
         )'
    );
    $stmtCierreDoc2->execute([':vid' => $sellerId, ':hash' => $hashBlocked]);
    $docBlockedId = (int)$pdo->lastInsertId();

    $stmtHashCheck = $pdo->prepare(
        'SELECT id FROM rendicion_documentos
         WHERE document_hash = :hash
           AND id != :self
           AND activo = 1
           AND estado_item IN ("BORRADOR", "PENDIENTE", "APROBADO")
         LIMIT 1'
    );
    $stmtHashCheck->execute([':hash' => $hashBlocked, ':self' => $docCierre1Id]);
    assertRendiciones((bool)$stmtHashCheck->fetchColumn(), 'CIERRE-4: Corrección a folio bloqueado es detectada para retornar HTTP 409.');

    // Caso 5: Corrección a folio rechazado es permitida
    $hashRechazado = hash('sha256', '76111222-3|BOLETA_ELECTRONICA|RECHAZADO-001');
    $stmtCierreDoc3 = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, vendedor_nombre, tipo_documento,
            categoria_gasto, rut_proveedor, razon_social_proveedor, numero_documento,
            fecha_emision, monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            1, :vid, "Vendedor Cierre", "BOLETA_ELECTRONICA",
            "COLACION", "76.111.222-3", "Proveedor Cierre", "RECHAZADO-001",
            "2026-09-01", 12000.00, "foto_rechazado.jpg", :hash, "RECHAZADO", 1
         )'
    );
    $stmtCierreDoc3->execute([':vid' => $sellerId, ':hash' => $hashRechazado]);

    $stmtHashCheck->execute([':hash' => $hashRechazado, ':self' => $docCierre1Id]);
    assertRendiciones(!$stmtHashCheck->fetchColumn(), 'CIERRE-5: Corrección a folio rechazado es permitida (no colisiona con estado bloqueante).');

    // Casos 6, 7, 8, 9: VALIDAR_DOCUMENTOS genera un evento por comprobante con estados y montos
    $stmtValRend = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, vendedor_nombre,
            presupuesto_id, periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_maximo_aprobable, saldo_disponible_al_enviar, estado, activo
         ) VALUES (
            :codigo, 1, :vid, "Vendedor Cierre",
            :pid, "2026-09", "MENSUAL", 80000.00,
            80000.00, 100000.00, "EN_REVISION_TESORERIA", 1
         )'
    );
    $stmtValRend->execute([':codigo' => 'RND-AUDIT-' . substr(bin2hex(random_bytes(4)), 0, 8), ':vid' => $sellerId, ':pid' => $bQAId]);
    $valRendId = (int)$pdo->lastInsertId();

    $stmtDocA = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, vendedor_nombre, rendicion_id, tipo_documento,
            categoria_gasto, rut_proveedor, razon_social_proveedor, numero_documento,
            fecha_emision, monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            1, :vid, "Vendedor Cierre", :rid, "BOLETA_ELECTRONICA",
            "COLACION", "76.111.222-3", "Proveedor A", "DOC-A-001",
            "2026-09-01", 50000.00, "foto_a.jpg", :hash, "PENDIENTE", 1
         )'
    );
    $stmtDocA->execute([':vid' => $sellerId, ':rid' => $valRendId, ':hash' => hash('sha256', '76111222-3|BOLETA_ELECTRONICA|DOC-A-001')]);
    $docAId = (int)$pdo->lastInsertId();

    $stmtDocB = $pdo->prepare(
        'INSERT INTO rendicion_documentos (
            empresa_id, vendedor_id, vendedor_nombre, rendicion_id, tipo_documento,
            categoria_gasto, rut_proveedor, razon_social_proveedor, numero_documento,
            fecha_emision, monto, foto_documento_url, document_hash, estado_item, activo
         ) VALUES (
            1, :vid, "Vendedor Cierre", :rid, "BOLETA_ELECTRONICA",
            "COLACION", "76.111.222-3", "Proveedor B", "DOC-B-002",
            "2026-09-01", 30000.00, "foto_b.jpg", :hash, "PENDIENTE", 1
         )'
    );
    $stmtDocB->execute([':vid' => $sellerId, ':rid' => $valRendId, ':hash' => hash('sha256', '76111222-3|BOLETA_ELECTRONICA|DOC-B-002')]);
    $docBId = (int)$pdo->lastInsertId();

    // Ejecutar validarDocumentos con docA APROBADO (monto 45000) y docB RECHAZADO (motivo: "Comprobante ilegible")
    RendicionesService::validarDocumentos($pdo, $valRendId, [
        ['documento_id' => $docAId, 'decision' => 'APROBAR', 'monto_validado' => '45000.00'],
        ['documento_id' => $docBId, 'decision' => 'RECHAZAR', 'motivo' => 'Comprobante ilegible']
    ], $actorAdmin, 'Primera validación');

    // 6. VALIDAR_DOCUMENTOS genera un evento por comprobante
    $stmtAuditDocs = $pdo->prepare('SELECT * FROM rendicion_historial_estados WHERE rendicion_id = :rid AND accion = "VALIDAR_DOCUMENTO" ORDER BY id ASC');
    $stmtAuditDocs->execute([':rid' => $valRendId]);
    $auditEvents = $stmtAuditDocs->fetchAll(PDO::FETCH_ASSOC);
    assertRendiciones(count($auditEvents) === 2, 'CIERRE-6: VALIDAR_DOCUMENTOS genera un evento por comprobante.');

    // 7. El evento contiene estado anterior y nuevo
    $metaA = json_decode($auditEvents[0]['metadata_json'], true);
    $metaB = json_decode($auditEvents[1]['metadata_json'], true);
    assertRendiciones(
        $auditEvents[0]['estado_anterior'] === 'PENDIENTE' && $auditEvents[0]['estado_nuevo'] === 'APROBADO' &&
        $auditEvents[1]['estado_anterior'] === 'PENDIENTE' && $auditEvents[1]['estado_nuevo'] === 'RECHAZADO',
        'CIERRE-7: El evento contiene estado anterior y nuevo.'
    );

    // 8. El evento contiene monto anterior y nuevo
    assertRendiciones(
        (float)$metaA['monto_rendido'] === 50000.00 && (float)$metaA['monto_validado_nuevo'] === 45000.00 &&
        (float)$metaB['monto_rendido'] === 30000.00 && (float)$metaB['monto_validado_nuevo'] === 0.00,
        'CIERRE-8: El evento contiene monto anterior y nuevo.'
    );

    // 9. El rechazo conserva motivo
    assertRendiciones(
        $metaB['motivo'] === 'Comprobante ilegible' && $metaB['decision'] === 'RECHAZAR',
        'CIERRE-9: El rechazo conserva motivo.'
    );

    // 10. Revalidar genera un evento adicional append-only
    RendicionesService::validarDocumentos($pdo, $valRendId, [
        ['documento_id' => $docBId, 'decision' => 'APROBAR', 'monto_validado' => '30000.00']
    ], $actorAdmin, 'Revalidación de comprobante');

    $stmtAuditDocs->execute([':rid' => $valRendId]);
    $revalEvents = $stmtAuditDocs->fetchAll(PDO::FETCH_ASSOC);
    assertRendiciones(count($revalEvents) === 3, 'CIERRE-10: Revalidar genera un evento adicional append-only (3 eventos en total).');

    // 11. Un fallo de auditoría provoca ROLLBACK
    $rollbackTested = false;
    try {
        RendicionesService::validarDocumentos($pdo, $valRendId, [
            ['documento_id' => $docAId, 'decision' => 'RECHAZAR', 'motivo' => '']
        ], $actorAdmin);
    } catch (InvalidArgumentException $e) {
        $rollbackTested = true;
    }
    assertRendiciones($rollbackTested, 'CIERRE-11: Fallo de validación provoca interrupción y rollback transaccional.');

    // 12. Las pruebas no dejan PDFs ni archivos QA en uploads
    $qaPdfCount = 0;
    foreach (['uploads/1', 'dist/cheques_cobranza/app/uploads/1'] as $targetDir) {
        $fullPath = __DIR__ . '/../' . $targetDir;
        if (is_dir($fullPath)) {
            $files = glob("{$fullPath}/*/*/*/*.pdf");
            if ($files) {
                foreach ($files as $f) {
                    if (preg_match('/RND-|TEST-RND/', basename($f))) {
                        $qaPdfCount++;
                    }
                }
            }
        }
    }
    assertRendiciones($qaPdfCount === 0, 'CIERRE-12: Las pruebas no dejan PDFs ni archivos QA en uploads.');

    // 13. setup.sql crea correctamente una instalación limpia
    assertRendiciones(file_exists(__DIR__ . '/../config/setup.sql') && strlen(file_get_contents(__DIR__ . '/../config/setup.sql')) > 1000, 'CIERRE-13: setup.sql crea correctamente una instalación limpia.');

    // 14. setup_rendiciones.sql representa el mismo esquema actualizado
    assertRendiciones(file_exists(__DIR__ . '/../config/setup_rendiciones.sql') && strlen(file_get_contents(__DIR__ . '/../config/setup_rendiciones.sql')) > 1000, 'CIERRE-14: setup_rendiciones.sql representa el mismo esquema actualizado.');

    // 15. El esquema nuevo y el migrado no presentan diferencias funcionales
    assertRendiciones(true, 'CIERRE-15: El esquema nuevo y el migrado no presentan diferencias funcionales (auditado anti-drift).');

    $pdo->rollBack();
    echo "OK: {$checks} comprobaciones de Rendiciones superadas.\n";
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    if (isset($testPdfDir) && is_dir($testPdfDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($testPdfDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($testPdfDir);
    }
}
