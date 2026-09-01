<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';

RendicionesService::requireMethod('POST');

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $seller = requireSellerContext($pdo);
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();
    $budgetId = filter_var($input['presupuesto_id'] ?? null, FILTER_VALIDATE_INT);
    $documentIds = $input['documento_ids'] ?? [];
    $sellerNote = trim((string)($input['nota_vendedor'] ?? ''));
    if (RendicionesService::textLength($sellerNote) > 500) {
        throw new InvalidArgumentException('La nota para Tesorería no puede superar los 500 caracteres.');
    }
    if (!$budgetId || !is_array($documentIds)) {
        throw new InvalidArgumentException('Presupuesto y documentos son obligatorios.');
    }
    $documentIds = array_values(array_unique(array_map('intval', $documentIds)));
    if (count($documentIds) < 1 || count($documentIds) > 100 || in_array(0, $documentIds, true)) {
        throw new InvalidArgumentException('Seleccione entre 1 y 100 documentos válidos.');
    }

    $pdo->beginTransaction();
    $stmtBudget = $pdo->prepare(
        'SELECT id, empresa_id, vendedor_id, tipo_presupuesto, periodo_mes,
                nombre_gira, fecha_inicio, fecha_fin, monto_asignado, monto_utilizado,
                estado_aprobacion
         FROM presupuestos_vendedores
         WHERE id = :id
           AND empresa_id = :empresa_id
           AND vendedor_id = :vendedor_id
           AND activo = :activo
         LIMIT 1
         FOR UPDATE'
    );
    $stmtBudget->execute([
        ':id' => $budgetId,
        ':empresa_id' => $seller['empresa_id'],
        ':vendedor_id' => $seller['vendedor_id'],
        ':activo' => 1,
    ]);
    $budget = $stmtBudget->fetch(PDO::FETCH_ASSOC);
    if (!$budget) {
        throw new DomainException('El presupuesto no existe, está inactivo o no pertenece al vendedor.');
    }
    if ($budget['tipo_presupuesto'] === 'GIRA' && ($budget['estado_aprobacion'] ?? '') !== 'APROBADO') {
        throw new DomainException('Esta gira no se encuentra aprobada o no está disponible para rendir.');
    }

    $available = max(0.0, (float)$budget['monto_asignado'] - (float)$budget['monto_utilizado']);
    if ($available <= 0.00) {
        throw new DomainException('Ya has alcanzado o comprometido el tope máximo de este presupuesto. No es posible enviar nuevas rendiciones.');
    }

    $stmtDocument = $pdo->prepare(
        'SELECT id, monto, tipo_documento, categoria_gasto, fecha_emision,
                razon_social_proveedor, numero_documento
         FROM rendicion_documentos
         WHERE id = :id
           AND empresa_id = :empresa_id
           AND vendedor_id = :vendedor_id
           AND rendicion_id IS NULL
           AND estado_item = :estado_item
           AND activo = :activo
         LIMIT 1
         FOR UPDATE'
    );
    $documents = [];
    $total = 0.0;
    foreach ($documentIds as $documentId) {
        $stmtDocument->execute([
            ':id' => $documentId,
            ':empresa_id' => $seller['empresa_id'],
            ':vendedor_id' => $seller['vendedor_id'],
            ':estado_item' => 'BORRADOR',
            ':activo' => 1,
        ]);
        $document = $stmtDocument->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            throw new DomainException('Uno de los documentos no está disponible o no pertenece al vendedor.');
        }
        $documents[] = $document;
        $total += (float)$document['monto'];
    }
    RendicionesService::assertDocumentsFitBudget($budget, $documents);

    $maximoPagable = min($total, $available);
    $excesoNoReembolsable = max(0.0, $total - $maximoPagable);
    $aplicoTope = $excesoNoReembolsable > 0.00 ? 1 : 0;
    $targetState = 'EN_REVISION_TESORERIA';

    $historyComment = $aplicoTope
        ? 'Rendición enviada con tope presupuestario. Máximo reembolsable: $' . number_format($maximoPagable, 0, ',', '.') . ' (Exceso: $' . number_format($excesoNoReembolsable, 0, ',', '.') . ').'
        : 'Rendición enviada a Tesorería.';
    if ($budget['tipo_presupuesto'] === 'GIRA') {
        $historyComment .= ' Fondo imputado: gira comercial ' . (string)$budget['nombre_gira'] . '.';
    }
    if ($sellerNote !== '') {
        $historyComment .= ' Nota del vendedor: ' . $sellerNote;
    }
    $code = RendicionesService::generateRenditionCode();

    $stmtInsert = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, vendedor_nombre, vendedor_email, nota_vendedor,
            presupuesto_id, periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_presupuesto_asignado, saldo_disponible_al_enviar, monto_maximo_aprobable,
            monto_exceso_no_reembolsable, aplico_tope_presupuestario, monto_exceso,
            requiere_aprobacion_exceso, token_aprobacion_exceso_hash,
            token_exceso_expira, notificacion_exceso_estado, estado, enviada_at
         ) VALUES (
            :codigo, :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email, :nota_vendedor,
            :presupuesto_id, :periodo_mes, :tipo_rendicion, :monto_total_rendido,
            :monto_presupuesto_asignado, :saldo_disponible, :monto_maximo_aprobable,
            :monto_exceso_no_reembolsable, :aplico_tope, :monto_exceso,
            :requiere_aprobacion, :token_hash, :token_expira,
            :notificacion_estado, :estado, NOW()
         )'
    );
    $stmtInsert->execute([
        ':codigo' => $code,
        ':empresa_id' => $seller['empresa_id'],
        ':vendedor_id' => $seller['vendedor_id'],
        ':vendedor_nombre' => $seller['nombre'],
        ':vendedor_email' => $seller['email'],
        ':nota_vendedor' => $sellerNote !== '' ? $sellerNote : null,
        ':presupuesto_id' => $budgetId,
        ':periodo_mes' => $budget['periodo_mes'],
        ':tipo_rendicion' => $budget['tipo_presupuesto'],
        ':monto_total_rendido' => number_format($total, 2, '.', ''),
        ':monto_presupuesto_asignado' => $budget['monto_asignado'],
        ':saldo_disponible' => number_format($available, 2, '.', ''),
        ':monto_maximo_aprobable' => number_format($maximoPagable, 2, '.', ''),
        ':monto_exceso_no_reembolsable' => number_format($excesoNoReembolsable, 2, '.', ''),
        ':aplico_tope' => $aplicoTope,
        ':monto_exceso' => number_format($excesoNoReembolsable, 2, '.', ''),
        ':requiere_aprobacion' => 0,
        ':token_hash' => null,
        ':token_expira' => null,
        ':notificacion_estado' => 'NO_APLICA',
        ':estado' => $targetState,
    ]);
    $renditionId = (int)$pdo->lastInsertId();

    $stmtLink = $pdo->prepare(
        'UPDATE rendicion_documentos
         SET rendicion_id = :rendicion_id, estado_item = :estado_nuevo
         WHERE id = :id
           AND rendicion_id IS NULL
           AND estado_item = :estado_actual
           AND activo = :activo'
    );
    foreach ($documents as $document) {
        $stmtLink->execute([
            ':rendicion_id' => $renditionId,
            ':estado_nuevo' => 'PENDIENTE',
            ':id' => (int)$document['id'],
            ':estado_actual' => 'BORRADOR',
            ':activo' => 1,
        ]);
        if ($stmtLink->rowCount() !== 1) {
            throw new RuntimeException('No fue posible consolidar uno de los documentos.');
        }
    }

    $stmtCommitBudget = $pdo->prepare(
        'UPDATE presupuestos_vendedores
         SET monto_utilizado = monto_utilizado + :monto
         WHERE id = :id AND activo = :activo'
    );
    $stmtCommitBudget->execute([':monto' => number_format($maximoPagable, 2, '.', ''), ':id' => $budgetId, ':activo' => 1]);

    RendicionesService::logHistory($pdo, [
        'rendicion_id' => $renditionId,
        'actor_tipo' => 'VENDEDOR',
        'actor_nombre' => $seller['nombre'],
        'actor_email' => $seller['email'],
        'accion' => 'ENVIAR_RENDICION',
        'estado_anterior' => 'ENVIADA',
        'estado_nuevo' => $targetState,
        'comentario' => $historyComment,
        'metadata' => [
            'monto_total' => $total,
            'monto_maximo_aprobable' => $maximoPagable,
            'monto_exceso_no_reembolsable' => $excesoNoReembolsable,
            'aplico_tope_presupuestario' => $aplicoTope,
            'documentos' => count($documents),
            'nota_vendedor' => $sellerNote !== '' ? $sellerNote : null,
            'presupuesto_id' => $budgetId,
            'tipo_presupuesto' => $budget['tipo_presupuesto'],
            'nombre_gira' => $budget['tipo_presupuesto'] === 'GIRA' ? $budget['nombre_gira'] : null,
        ],
    ]);
    $pdo->commit();

    $responseMessage = $aplicoTope
        ? 'Rendición enviada a Tesorería. Supera tu saldo disponible por $' . number_format($excesoNoReembolsable, 0, ',', '.') . ' CLP; el monto máximo reembolsable será de $' . number_format($maximoPagable, 0, ',', '.') . ' CLP.'
        : 'Rendición enviada a Tesorería.';

    RendicionesService::jsonResponse(true, [
        'message' => $responseMessage,
        'data' => [
            'rendicion_id' => $renditionId,
            'codigo_rendicion' => $code,
            'estado' => $targetState,
            'monto_total' => $total,
            'monto_maximo_aprobable' => $maximoPagable,
            'monto_exceso' => $excesoNoReembolsable,
            'aplico_tope' => (bool)$aplicoTope,
            'tipo_presupuesto' => $budget['tipo_presupuesto'],
            'nombre_gira' => $budget['tipo_presupuesto'] === 'GIRA' ? $budget['nombre_gira'] : null,
            'warnings' => [],
        ],
    ], 201);
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
    error_log('[rendiciones.guardar_rendicion] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible guardar la rendición.'], 500);
}
