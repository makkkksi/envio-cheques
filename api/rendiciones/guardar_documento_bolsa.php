<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';
require_once __DIR__ . '/../../services/RendicionesDocumentService.php';

RendicionesService::requireMethod('POST');

$storedFile = null;
try {
    $pdo = Database::getCobranzasConnection();
    $seller = requireSellerContext($pdo);
    requireCsrfToken();

    $documentId = filter_input(INPUT_POST, 'document_id', FILTER_VALIDATE_INT)
               ?: filter_input(INPUT_POST, 'documento_id', FILTER_VALIDATE_INT)
               ?: 0;

    $document = [
        'tipo_documento' => strtoupper(trim((string)($_POST['tipo_documento'] ?? ''))),
        'categoria_gasto' => strtoupper(trim((string)($_POST['categoria_gasto'] ?? ''))),
        'rut_proveedor' => trim((string)($_POST['rut_proveedor'] ?? '')),
        'razon_social_proveedor' => trim((string)($_POST['razon_social_proveedor'] ?? '')),
        'numero_documento' => trim((string)($_POST['numero_documento'] ?? '')),
        'fecha_emision' => trim((string)($_POST['fecha_emision'] ?? '')),
        'monto' => $_POST['monto'] ?? null,
        'descripcion' => trim((string)($_POST['descripcion'] ?? '')),
        'cliente_invitado_nombre' => trim((string)($_POST['cliente_invitado_nombre'] ?? '')),
        'cliente_invitado_rut' => trim((string)($_POST['cliente_invitado_rut'] ?? '')),
        'cliente_invitado_empresa' => trim((string)($_POST['cliente_invitado_empresa'] ?? '')),
        'cliente_invitado_cargo' => trim((string)($_POST['cliente_invitado_cargo'] ?? '')),
        'proposito_comercial' => trim((string)($_POST['proposito_comercial'] ?? '')),
    ];

    if (!RendicionesService::isValidDate($document['fecha_emision'])) {
        throw new InvalidArgumentException('La fecha de emisión no es válida.');
    }
    $document['monto'] = RendicionesService::normalizeMoney($document['monto']);
    RendicionesService::validateDinnerFields($document);
    $documentHash = RendicionesService::createDocumentHash($document, $seller['vendedor_id'], $seller['empresa_id']);

    $existingDoc = null;
    if ($documentId > 0) {
        $stmtExisting = $pdo->prepare(
            'SELECT id, foto_documento_url, rendicion_id, estado_item
             FROM rendicion_documentos
             WHERE id = :id
               AND empresa_id = :empresa_id
               AND vendedor_id = :vendedor_id
               AND activo = 1
             LIMIT 1'
        );
        $stmtExisting->execute([
            ':id' => $documentId,
            ':empresa_id' => $seller['empresa_id'],
            ':vendedor_id' => $seller['vendedor_id'],
        ]);
        $existingDoc = $stmtExisting->fetch(PDO::FETCH_ASSOC);
        if (!$existingDoc) {
            throw new InvalidArgumentException('El comprobante no existe o no pertenece a tu cuenta.');
        }
        if ($existingDoc['rendicion_id'] !== null || $existingDoc['estado_item'] !== 'BORRADOR') {
            throw new InvalidArgumentException('Este gasto ya forma parte de una rendición enviada y no puede ser modificado.');
        }
    }

    $hasNewFile = isset($_FILES['foto_documento']) && (($_FILES['foto_documento']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK);

    if ($hasNewFile) {
        $storedFile = RendicionesDocumentService::store(
            $_FILES['foto_documento'],
            $seller['empresa_id'],
            $seller['vendedor_id']
        );
        $photoUrl = $storedFile['relative_path'];
    } elseif ($existingDoc) {
        $photoUrl = $existingDoc['foto_documento_url'];
    } else {
        throw new InvalidArgumentException('Debe adjuntar una fotografía válida del documento.');
    }

    $isToll = $document['tipo_documento'] === 'PEAJE' || $document['categoria_gasto'] === 'PEAJES';

    if ($documentId > 0) {
        $stmt = $pdo->prepare(
            'UPDATE rendicion_documentos SET
                tipo_documento = :tipo_documento,
                categoria_gasto = :categoria_gasto,
                rut_proveedor = :rut_proveedor,
                razon_social_proveedor = :razon_social_proveedor,
                numero_documento = :numero_documento,
                fecha_emision = :fecha_emision,
                monto = :monto,
                descripcion = :descripcion,
                foto_documento_url = :foto_documento_url,
                document_hash = :document_hash,
                cliente_invitado_nombre = :cliente_invitado_nombre,
                cliente_invitado_rut = :cliente_invitado_rut,
                cliente_invitado_empresa = :cliente_invitado_empresa,
                cliente_invitado_cargo = :cliente_invitado_cargo,
                proposito_comercial = :proposito_comercial
             WHERE id = :id
               AND empresa_id = :empresa_id
               AND vendedor_id = :vendedor_id
               AND rendicion_id IS NULL
               AND activo = 1'
        );
        $stmt->execute([
            ':id' => $documentId,
            ':empresa_id' => $seller['empresa_id'],
            ':vendedor_id' => $seller['vendedor_id'],
            ':tipo_documento' => $document['tipo_documento'],
            ':categoria_gasto' => $document['categoria_gasto'],
            ':rut_proveedor' => $isToll ? null : RendicionesService::normalizeRut($document['rut_proveedor']),
            ':razon_social_proveedor' => $isToll ? null : RendicionesService::truncateText($document['razon_social_proveedor'], 150),
            ':numero_documento' => $isToll ? null : RendicionesService::normalizeDocumentNumber($document['numero_documento']),
            ':fecha_emision' => $document['fecha_emision'],
            ':monto' => $document['monto'],
            ':descripcion' => $document['descripcion'] !== '' ? RendicionesService::truncateText($document['descripcion'], 500) : null,
            ':foto_documento_url' => $photoUrl,
            ':document_hash' => $documentHash,
            ':cliente_invitado_nombre' => $document['cliente_invitado_nombre'] ?: null,
            ':cliente_invitado_rut' => $document['cliente_invitado_rut'] !== '' ? RendicionesService::normalizeRut($document['cliente_invitado_rut']) : null,
            ':cliente_invitado_empresa' => $document['cliente_invitado_empresa'] ?: null,
            ':cliente_invitado_cargo' => $document['cliente_invitado_cargo'] ?: null,
            ':proposito_comercial' => $document['proposito_comercial'] ?: null,
        ]);

        RendicionesService::jsonResponse(true, [
            'message' => 'Gasto actualizado correctamente.',
            'data' => ['documento_id' => $documentId],
        ]);
    } else {
        $stmt = $pdo->prepare(
            'INSERT INTO rendicion_documentos (
                empresa_id, vendedor_id, vendedor_nombre, vendedor_email,
                tipo_documento, categoria_gasto, rut_proveedor, razon_social_proveedor,
                numero_documento, fecha_emision, monto, descripcion, foto_documento_url,
                document_hash, cliente_invitado_nombre, cliente_invitado_rut,
                cliente_invitado_empresa, cliente_invitado_cargo, proposito_comercial,
                estado_item, activo
             ) VALUES (
                :empresa_id, :vendedor_id, :vendedor_nombre, :vendedor_email,
                :tipo_documento, :categoria_gasto, :rut_proveedor, :razon_social_proveedor,
                :numero_documento, :fecha_emision, :monto, :descripcion, :foto_documento_url,
                :document_hash, :cliente_invitado_nombre, :cliente_invitado_rut,
                :cliente_invitado_empresa, :cliente_invitado_cargo, :proposito_comercial,
                :estado_item, :activo
             )'
        );
        $stmt->execute([
            ':empresa_id' => $seller['empresa_id'],
            ':vendedor_id' => $seller['vendedor_id'],
            ':vendedor_nombre' => RendicionesService::truncateText($seller['nombre'], 150),
            ':vendedor_email' => RendicionesService::truncateText($seller['email'], 150),
            ':tipo_documento' => $document['tipo_documento'],
            ':categoria_gasto' => $document['categoria_gasto'],
            ':rut_proveedor' => $isToll ? null : RendicionesService::normalizeRut($document['rut_proveedor']),
            ':razon_social_proveedor' => $isToll ? null : RendicionesService::truncateText($document['razon_social_proveedor'], 150),
            ':numero_documento' => $isToll ? null : RendicionesService::normalizeDocumentNumber($document['numero_documento']),
            ':fecha_emision' => $document['fecha_emision'],
            ':monto' => $document['monto'],
            ':descripcion' => $document['descripcion'] !== '' ? RendicionesService::truncateText($document['descripcion'], 500) : null,
            ':foto_documento_url' => $photoUrl,
            ':document_hash' => $documentHash,
            ':cliente_invitado_nombre' => $document['cliente_invitado_nombre'] ?: null,
            ':cliente_invitado_rut' => $document['cliente_invitado_rut'] !== '' ? RendicionesService::normalizeRut($document['cliente_invitado_rut']) : null,
            ':cliente_invitado_empresa' => $document['cliente_invitado_empresa'] ?: null,
            ':cliente_invitado_cargo' => $document['cliente_invitado_cargo'] ?: null,
            ':proposito_comercial' => $document['proposito_comercial'] ?: null,
            ':estado_item' => 'BORRADOR',
            ':activo' => 1,
        ]);

        RendicionesService::jsonResponse(true, [
            'message' => 'Documento agregado a la bolsa de gastos.',
            'data' => ['documento_id' => (int)$pdo->lastInsertId()],
        ], 201);
    }
} catch (InvalidArgumentException $exception) {
    RendicionesDocumentService::rollback($storedFile['absolute_path'] ?? null);
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    RendicionesDocumentService::rollback($storedFile['absolute_path'] ?? null);
    if (RendicionesService::isDuplicateKey($exception)) {
        RendicionesService::jsonResponse(false, ['message' => 'Este documento ya fue registrado previamente. No se permite registrar comprobantes duplicados.'], 409);
    }
    error_log('[rendiciones.guardar_documento_bolsa] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible guardar el documento.'], 500);
}
