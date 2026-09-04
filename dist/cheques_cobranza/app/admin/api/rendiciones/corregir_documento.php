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
                rut_proveedor, numero_documento, numero_documento_original, fecha_emision, monto, monto_original, estado_item
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
                monto_presupuesto_asignado, aplico_tope_presupuestario,
                monto_total_rendido, monto_maximo_aprobable, monto_exceso, monto_exceso_no_reembolsable
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

    // 3. Bloquear presupuesto
    $budgetId = (int)$rendition['presupuesto_id'];
    $stmtBudgetLock = $pdo->prepare(
        'SELECT id, monto_asignado, monto_utilizado
         FROM presupuestos_vendedores
         WHERE id = :id AND activo = 1
         LIMIT 1 FOR UPDATE'
    );
    $stmtBudgetLock->execute([':id' => $budgetId]);
    $budget = $stmtBudgetLock->fetch(PDO::FETCH_ASSOC);
    if (!$budget) {
        throw new DomainException('Presupuesto vinculado no encontrado.');
    }

    $diff = $newAmount - $oldAmount;
    $originalSaved = $doc['monto_original'] !== null ? (float)$doc['monto_original'] : $oldAmount;

    // 4. Determinar número de documento definitivo y recalcular hash
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

    // Verificar que el nuevo hash no colisione con un comprobante en estado bloqueante (BORRADOR, PENDIENTE, APROBADO)
    $stmtHashCheck = $pdo->prepare(
        'SELECT id FROM rendicion_documentos
         WHERE document_hash = :hash
           AND id != :self
           AND activo = 1
           AND estado_item IN ("BORRADOR", "PENDIENTE", "APROBADO")
         LIMIT 1'
    );
    $stmtHashCheck->execute([':hash' => $newDocHash, ':self' => $documentId]);
    if ($stmtHashCheck->fetchColumn()) {
        throw new DomainException('El número de documento corregido ya está registrado en una rendición activa, aprobada o pendiente.');
    }

    // Determinar el valor definitivo de numero_documento_original
    if ($doc['numero_documento_original'] !== null) {
        $originalFolio = (string)$doc['numero_documento_original'];
    } elseif ($newNumber !== null && $newNumber !== (string)$doc['numero_documento']) {
        $originalFolio = (string)$doc['numero_documento'];
    } else {
        $originalFolio = null;
    }

    // Sentencias preparadas estáticas sin interpolación de cadenas SQL
    if ($newNumber !== null) {
        $stmtUpdateDocWithNumber = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET monto                     = :nuevo_monto,
                 monto_original            = :monto_orig,
                 numero_documento_original = COALESCE(numero_documento_original, numero_documento),
                 editado_por               = :admin_id,
                 editado_at                = NOW(),
                 motivo_edicion            = :motivo,
                 document_hash             = :new_hash,
                 numero_documento          = :nuevo_numero
             WHERE id = :id'
        );
        $stmtUpdateDocWithNumber->execute([
            ':nuevo_monto'  => number_format($newAmount, 2, '.', ''),
            ':monto_orig'   => number_format($originalSaved, 2, '.', ''),
            ':admin_id'     => (int)$admin['id'],
            ':motivo'       => RendicionesService::truncateText($reason, 255),
            ':new_hash'     => $newDocHash,
            ':nuevo_numero' => $effectiveNumber,
            ':id'           => $documentId,
        ]);
    } else {
        $stmtUpdateDocWithoutNumber = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET monto           = :nuevo_monto,
                 monto_original  = :monto_orig,
                 editado_por     = :admin_id,
                 editado_at      = NOW(),
                 motivo_edicion  = :motivo,
                 document_hash   = :new_hash
             WHERE id = :id'
        );
        $stmtUpdateDocWithoutNumber->execute([
            ':nuevo_monto'  => number_format($newAmount, 2, '.', ''),
            ':monto_orig'   => number_format($originalSaved, 2, '.', ''),
            ':admin_id'     => (int)$admin['id'],
            ':motivo'       => RendicionesService::truncateText($reason, 255),
            ':new_hash'     => $newDocHash,
            ':id'           => $documentId,
        ]);
    }

    // 5. Recalcular total rendido a partir de la suma real de todos los comprobantes activos
    $stmtSum = $pdo->prepare(
        'SELECT COALESCE(SUM(monto), 0)
         FROM rendicion_documentos
         WHERE rendicion_id = :id AND activo = 1'
    );
    $stmtSum->execute([':id' => $renditionId]);
    $newTotalRendido = (float)$stmtSum->fetchColumn();

    $saldoAlEnviar   = (float)$rendition['saldo_disponible_al_enviar'];
    $saldoBase       = max(0.0, $saldoAlEnviar);

    $reservaAnterior = (float)$rendition['monto_maximo_aprobable'];
    $reservaNueva    = min($newTotalRendido, $saldoBase);
    $ajusteReserva   = $reservaNueva - $reservaAnterior;

    $newExceso       = max(0.0, $newTotalRendido - $saldoAlEnviar);
    $newExcesoNoReemb= $newExceso;
    $newAplicoTope   = ($newExceso > 0.00) ? 1 : 0;
    $newMaxAprobable = $reservaNueva;

    // 6. Actualizar cabecera de la rendición
    $stmtUpdateRend = $pdo->prepare(
        'UPDATE rendiciones_gastos
         SET monto_total_rendido          = :monto_rendido,
             monto_maximo_aprobable       = :max_apr,
             monto_exceso                 = :exceso,
             monto_exceso_no_reembolsable = :exceso_no_reemb,
             aplico_tope_presupuestario   = :aplico_tope
         WHERE id = :id'
    );
    $stmtUpdateRend->execute([
        ':monto_rendido'  => number_format($newTotalRendido, 2, '.', ''),
        ':max_apr'        => number_format($newMaxAprobable, 2, '.', ''),
        ':exceso'         => number_format($newExceso, 2, '.', ''),
        ':exceso_no_reemb'=> number_format($newExcesoNoReemb, 2, '.', ''),
        ':aplico_tope'    => $newAplicoTope,
        ':id'             => $renditionId,
    ]);

    // Actualizar presupuesto si varió la reserva presupuestaria (monto_maximo_aprobable)
    if (abs($ajusteReserva) > 0.001) {
        $stmtBudgetUpdate = $pdo->prepare(
            'UPDATE presupuestos_vendedores
             SET monto_utilizado = GREATEST(0, monto_utilizado + :ajuste)
             WHERE id = :id'
        );
        $stmtBudgetUpdate->execute([
            ':ajuste' => number_format($ajusteReserva, 2, '.', ''),
            ':id'     => $budgetId,
        ]);
    }

    // 7. Auditoría y trazabilidad
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
            'documento_id'              => $documentId,
            'monto_anterior'            => $oldAmount,
            'monto_nuevo'               => $newAmount,
            'monto_original_digitado'   => $originalSaved,
            'numero_anterior'           => $oldNumber,
            'numero_nuevo'              => $effectiveNumber,
            'numero_documento_original' => $originalFolio,
            'total_rendido_anterior'    => (float)$rendition['monto_total_rendido'],
            'nuevo_total_rendicion'     => $newTotalRendido,
            'reserva_anterior'          => $reservaAnterior,
            'reserva_nueva'             => $reservaNueva,
            'ajuste_reserva'            => $ajusteReserva,
            'aplico_tope_anterior'      => (int)$rendition['aplico_tope_presupuestario'],
            'nuevo_aplico_tope'         => $newAplicoTope,
        ],
    ]);

    AuditService::log($pdo, (int)$admin['id'], $admin['email'], 'RENDICION_CORREGIR_DOCUMENTO', json_encode([
        'rendicion_id'              => $renditionId,
        'documento_id'              => $documentId,
        'monto_anterior'            => $oldAmount,
        'monto_nuevo'               => $newAmount,
        'numero_anterior'           => $oldNumber,
        'numero_nuevo'              => $effectiveNumber,
        'numero_documento_original' => $originalFolio,
        'reserva_anterior'          => $reservaAnterior,
        'reserva_nueva'             => $reservaNueva,
        'ajuste_reserva'            => $ajusteReserva,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $pdo->commit();

    RendicionesService::jsonResponse(true, [
        'message' => 'Comprobante corregido exitosamente.',
        'data' => [
            'documento_id'              => $documentId,
            'monto_nuevo'               => $newAmount,
            'monto_original'            => $originalSaved,
            'numero_documento'          => $effectiveNumber,
            'numero_documento_original' => $originalFolio,
            'nuevo_total_rendido'       => $newTotalRendido,
            'nuevo_maximo_aprobable'    => $newMaxAprobable,
            'nuevo_exceso'              => $newExceso,
            'aplico_tope'               => (bool)$newAplicoTope,
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
    if (RendicionesService::isDuplicateKey($exception)) {
        RendicionesService::jsonResponse(false, ['message' => 'El número de documento corregido ya está registrado en una rendición activa, aprobada o pendiente.'], 409);
    }
    error_log('[admin.rendiciones.corregir_documento] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible corregir el monto del comprobante.'], 500);
}
