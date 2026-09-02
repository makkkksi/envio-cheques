<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/AuditService.php';
require_once __DIR__ . '/../../../services/RendicionesService.php';

RendicionesService::requireMethod('POST');

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $admin = requirePermission($pdo, 'rendiciones.manage');
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();

    $documentId = filter_var($input['documento_id'] ?? null, FILTER_VALIDATE_INT);
    $rawAmount   = $input['nuevo_monto'] ?? null;
    $rawNumber   = isset($input['nuevo_numero']) ? trim((string)$input['nuevo_numero']) : null;
    $reason      = trim((string)($input['motivo'] ?? 'Corrección de digitación por Tesorería'));

    if (!$documentId) {
        throw new InvalidArgumentException('ID de comprobante no válido.');
    }
    $newAmount = (float)RendicionesService::normalizeMoney($rawAmount);
    if ($newAmount <= 0) {
        throw new InvalidArgumentException('El monto debe ser superior a cero.');
    }
    // Nuevo número de documento (opcional; si se omite se conserva el actual)
    $newNumber = ($rawNumber !== null && $rawNumber !== '') ? RendicionesService::normalizeDocumentNumber($rawNumber) : null;
    if ($rawNumber !== null && $rawNumber !== '' && $newNumber === '') {
        throw new InvalidArgumentException('El número de documento no puede quedar vacío.');
    }

    $pdo->beginTransaction();

    // 1. Bloquear documento
    $stmtDoc = $pdo->prepare(
        'SELECT id, rendicion_id, vendedor_id, empresa_id, tipo_documento, categoria_gasto,
                rut_proveedor, numero_documento, fecha_emision, monto, monto_original, estado_item
         FROM rendicion_documentos
         WHERE id = :id AND activo = 1
         LIMIT 1 FOR UPDATE'
    );
    $stmtDoc->execute([':id' => $documentId]);
    $doc = $stmtDoc->fetch(PDO::FETCH_ASSOC);
    if (!$doc || empty($doc['rendicion_id'])) {
        throw new DomainException('Comprobante no encontrado o no asignado a una rendición.');
    }

    $renditionId = (int)$doc['rendicion_id'];
    $oldAmount = (float)$doc['monto'];

    // 2. Bloquear rendición
    $stmtRendition = $pdo->prepare(
        'SELECT id, estado, presupuesto_id, saldo_disponible_al_enviar,
                monto_presupuesto_asignado, aplico_tope_presupuestario
         FROM rendiciones_gastos
         WHERE id = :id AND activo = 1
         LIMIT 1 FOR UPDATE'
    );
    $stmtRendition->execute([':id' => $renditionId]);
    $rendition = $stmtRendition->fetch(PDO::FETCH_ASSOC);
    if (!$rendition) {
        throw new DomainException('Rendición no encontrada.');
    }

    $allowedStates = ['EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'];
    if (!in_array($rendition['estado'], $allowedStates, true)) {
        throw new DomainException('Sólo se pueden corregir montos mientras la rendición esté en revisión de Tesorería.');
    }

    $diff = $newAmount - $oldAmount;
    $originalSaved = $doc['monto_original'] !== null ? (float)$doc['monto_original'] : $oldAmount;

    // 3. Determinar número de documento definitivo y recalcular hash
    $isToll = $doc['tipo_documento'] === 'PEAJE' || $doc['categoria_gasto'] === 'PEAJES';
    $effectiveNumber = $isToll ? null : ($newNumber ?? (string)$doc['numero_documento']);

    // Calcular nuevo document_hash con el número/monto corregido
    if ($isToll) {
        $date      = (string)$doc['fecha_emision'];
        $amountStr = number_format($newAmount, 2, '.', '');
        $newDocHash = hash('sha256', "PEAJE|{$date}|{$amountStr}|{$doc['vendedor_id']}|{$doc['empresa_id']}");
    } else {
        $rut = RendicionesService::normalizeRut((string)($doc['rut_proveedor'] ?? ''));
        $type = strtoupper(trim((string)$doc['tipo_documento']));
        $num  = RendicionesService::normalizeDocumentNumber((string)$effectiveNumber);
        $newDocHash = hash('sha256', "{$rut}|{$type}|{$num}");
    }

    // Verificar que el nuevo hash no colisione con OTRO comprobante (de otro vendedor u otra rendición)
    $stmtHashCheck = $pdo->prepare(
        'SELECT id FROM rendicion_documentos WHERE document_hash = :hash AND id != :self AND activo = 1 LIMIT 1'
    );
    $stmtHashCheck->execute([':hash' => $newDocHash, ':self' => $documentId]);
    if ($stmtHashCheck->fetchColumn()) {
        throw new DomainException('El número de documento corregido ya existe en otro comprobante registrado. No se puede duplicar.');
    }

    $updateNumberSql = $newNumber !== null ? ', numero_documento = :nuevo_numero' : '';

    $paramsDoc = [
        ':nuevo_monto'  => number_format($newAmount, 2, '.', ''),
        ':monto_orig'   => number_format($originalSaved, 2, '.', ''),
        ':admin_id'     => (int)$admin['id'],
        ':motivo'       => RendicionesService::truncateText($reason, 255),
        ':new_hash'     => $newDocHash,
        ':id'           => $documentId,
    ];
    if ($newNumber !== null) {
        $paramsDoc[':nuevo_numero'] = $effectiveNumber;
    }

    $stmtUpdateDoc = $pdo->prepare(
        "UPDATE rendicion_documentos
         SET monto           = :nuevo_monto,
             monto_original  = :monto_orig,
             editado_por     = :admin_id,
             editado_at      = NOW(),
             motivo_edicion  = :motivo,
             document_hash   = :new_hash
             {$updateNumberSql}
         WHERE id = :id"
    );
    $stmtUpdateDoc->execute($paramsDoc);

    // 4. Recalcular totales de la rendición
    $stmtSum = $pdo->prepare(
        'SELECT COALESCE(SUM(monto), 0) FROM rendicion_documentos WHERE rendicion_id = :id AND activo = 1'
    );
    $stmtSum->execute([':id' => $renditionId]);
    $newTotalRendido = (float)$stmtSum->fetchColumn();

    $saldoAlEnviar = (float)($rendition['saldo_disponible_al_enviar'] ?? 0);
    $aplicoTope = (bool)($rendition['aplico_tope_presupuestario'] ?? false);

    if ($aplicoTope) {
        $newMaxAprobable = min($newTotalRendido, max(0.0, $saldoAlEnviar));
        $newExcesoNoReemb = max(0.0, $newTotalRendido - $newMaxAprobable);
    } else {
        $newMaxAprobable = $newTotalRendido;
        $newExcesoNoReemb = 0.0;
    }
    $newExceso = max(0.0, $newTotalRendido - $saldoAlEnviar);

    $stmtUpdateRendition = $pdo->prepare(
        'UPDATE rendiciones_gastos
         SET monto_total_rendido = :total,
             monto_maximo_aprobable = :max_apr,
             monto_exceso = :exceso,
             monto_exceso_no_reembolsable = :exceso_no_reemb
         WHERE id = :id'
    );
    $stmtUpdateRendition->execute([
        ':total' => number_format($newTotalRendido, 2, '.', ''),
        ':max_apr' => number_format($newMaxAprobable, 2, '.', ''),
        ':exceso' => number_format($newExceso, 2, '.', ''),
        ':exceso_no_reemb' => number_format($newExcesoNoReemb, 2, '.', ''),
        ':id' => $renditionId,
    ]);

    // 5. Ajustar reserva en presupuestos_vendedores si hubo diferencia
    if (abs($diff) > 0.001) {
        $stmtBudget = $pdo->prepare(
            'UPDATE presupuestos_vendedores
             SET monto_utilizado = GREATEST(0, monto_utilizado + :diff)
             WHERE id = :id'
        );
        $stmtBudget->execute([
            ':diff' => number_format($diff, 2, '.', ''),
            ':id' => (int)$rendition['presupuesto_id'],
        ]);
    }

    // 6. Auditoría y trazabilidad
    $oldNumber   = (string)$doc['numero_documento'];
    $commentParts = [];
    if (abs($diff) > 0.001) {
        $commentParts[] = 'Monto: $' . number_format($oldAmount, 0, ',', '.') . ' → $' . number_format($newAmount, 0, ',', '.');
    }
    if ($newNumber !== null && $newNumber !== $oldNumber) {
        $commentParts[] = 'N° Folio: ' . $oldNumber . ' → ' . $effectiveNumber;
    }
    $commentParts[] = 'Motivo: ' . $reason;

    RendicionesService::logHistory($pdo, [
        'rendicion_id'   => $renditionId,
        'documento_id'   => $documentId,
        'usuario_id'     => (int)$admin['id'],
        'actor_tipo'     => 'TESORERIA',
        'actor_nombre'   => $admin['nombre'],
        'actor_email'    => $admin['email'],
        'accion'         => 'CORREGIR_DOCUMENTO',
        'estado_anterior'=> $rendition['estado'],
        'estado_nuevo'   => $rendition['estado'],
        'comentario'     => implode('. ', $commentParts),
        'metadata' => [
            'documento_id'          => $documentId,
            'monto_anterior'        => $oldAmount,
            'monto_nuevo'           => $newAmount,
            'monto_original_digitado' => $originalSaved,
            'numero_anterior'       => $oldNumber,
            'numero_nuevo'          => $effectiveNumber,
            'nuevo_total_rendicion' => $newTotalRendido,
        ],
    ]);

    AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_CORREGIR_DOCUMENTO', json_encode([
        'rendicion_id'    => $renditionId,
        'documento_id'    => $documentId,
        'monto_anterior'  => $oldAmount,
        'monto_nuevo'     => $newAmount,
        'numero_anterior' => $oldNumber,
        'numero_nuevo'    => $effectiveNumber,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $pdo->commit();

    RendicionesService::jsonResponse(true, [
        'message' => 'Comprobante corregido exitosamente.',
        'data' => [
            'documento_id'          => $documentId,
            'monto_nuevo'           => $newAmount,
            'monto_original'        => $originalSaved,
            'numero_documento'      => $effectiveNumber,
            'nuevo_total_rendido'   => $newTotalRendido,
            'nuevo_maximo_aprobable'=> $newMaxAprobable,
            'nuevo_exceso'          => $newExceso,
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
    error_log('[admin.rendiciones.corregir_documento] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible corregir el monto del comprobante.'], 500);
}
