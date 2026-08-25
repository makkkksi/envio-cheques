<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';
require_once __DIR__ . '/../../services/MailService.php';

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
    if (mb_strlen($sellerNote) > 500) {
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
                nombre_gira, fecha_inicio, fecha_fin, monto_asignado, monto_utilizado
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

    $available = (float)$budget['monto_asignado'] - (float)$budget['monto_utilizado'];
    $excess = max(0, $total - $available);
    $requiresApproval = $excess > 0;
    $targetState = $requiresApproval ? 'PENDIENTE_APROBACION_EXCESO' : 'EN_REVISION_TESORERIA';
    $historyComment = $requiresApproval
        ? 'Rendición enviada con exceso de presupuesto.'
        : 'Rendición enviada a Tesorería.';
    if ($sellerNote !== '') {
        $historyComment .= ' Nota del vendedor: ' . $sellerNote;
    }
    $rawToken = $requiresApproval ? bin2hex(random_bytes(32)) : null;
    $tokenHash = $rawToken !== null ? hash('sha256', $rawToken) : null;
    $tokenExpires = $requiresApproval ? date('Y-m-d H:i:s', time() + (RENDICIONES_TOKEN_TTL_HOURS * 3600)) : null;
    $code = RendicionesService::generateRenditionCode();

    $stmtInsert = $pdo->prepare(
        'INSERT INTO rendiciones_gastos (
            codigo_rendicion, empresa_id, vendedor_id, vendedor_nombre, vendedor_email, nota_vendedor,
            presupuesto_id, periodo_mes, tipo_rendicion, monto_total_rendido,
            monto_presupuesto_asignado, saldo_disponible_al_enviar, monto_exceso,
            requiere_aprobacion_exceso, token_aprobacion_exceso_hash,
            token_exceso_expira, notificacion_exceso_estado, estado, enviada_at
         ) VALUES (
            :codigo, :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email, :nota_vendedor,
            :presupuesto_id, :periodo_mes, :tipo_rendicion, :monto_total_rendido,
            :monto_presupuesto_asignado, :saldo_disponible, :monto_exceso,
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
        ':monto_exceso' => number_format($excess, 2, '.', ''),
        ':requiere_aprobacion' => $requiresApproval ? 1 : 0,
        ':token_hash' => $tokenHash,
        ':token_expira' => $tokenExpires,
        ':notificacion_estado' => $requiresApproval ? 'PENDIENTE' : 'NO_APLICA',
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
    $stmtCommitBudget->execute([':monto' => number_format($total, 2, '.', ''), ':id' => $budgetId, ':activo' => 1]);

    RendicionesService::logHistory($pdo, [
        'rendicion_id' => $renditionId,
        'actor_tipo' => 'VENDEDOR',
        'actor_nombre' => $seller['nombre'],
        'actor_email' => $seller['email'],
        'accion' => 'ENVIAR_RENDICION',
        'estado_anterior' => 'ENVIADA',
        'estado_nuevo' => $targetState,
        'comentario' => $historyComment,
        'metadata' => ['monto_total' => $total, 'monto_exceso' => $excess, 'documentos' => count($documents), 'nota_vendedor' => $sellerNote !== '' ? $sellerNote : null],
    ]);
    $pdo->commit();

    $warnings = [];
    if ($requiresApproval) {
        try {
            $mailSent = MailService::enviarSolicitudExcesoRendicion([
                'id' => $renditionId,
                'codigo_rendicion' => $code,
                'vendedor_nombre' => $seller['nombre'],
                'periodo_mes' => $budget['periodo_mes'],
                'tipo_rendicion' => $budget['tipo_presupuesto'],
                'nombre_gira' => $budget['nombre_gira'],
                'monto_total_rendido' => $total,
                'monto_presupuesto_asignado' => (float)$budget['monto_asignado'],
                'monto_exceso' => $excess,
            ], $documents, (string)$rawToken);
            $stmtMail = $pdo->prepare(
                'UPDATE rendiciones_gastos
                 SET notificacion_exceso_estado = :estado
                 WHERE id = :id AND estado = :rendicion_estado'
            );
            $stmtMail->execute([
                ':estado' => $mailSent ? 'ENVIADA' : 'FALLIDA',
                ':id' => $renditionId,
                ':rendicion_estado' => 'PENDIENTE_APROBACION_EXCESO',
            ]);
        } catch (Throwable $mailException) {
            $mailSent = false;
            error_log('[rendiciones.guardar_rendicion.mail] ' . $mailException->getMessage());
        }
        if (!$mailSent) {
            $warnings[] = 'La rendición fue guardada, pero el correo de aprobación no pudo enviarse.';
        }
    }

    RendicionesService::jsonResponse(true, [
        'message' => $requiresApproval ? 'Rendición enviada y pendiente de aprobación del exceso.' : 'Rendición enviada a Tesorería.',
        'data' => [
            'rendicion_id' => $renditionId,
            'codigo_rendicion' => $code,
            'estado' => $targetState,
            'monto_total' => $total,
            'monto_exceso' => $excess,
            'warnings' => $warnings,
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
