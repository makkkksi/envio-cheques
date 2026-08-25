<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';

RendicionesService::requireMethod('POST');

try {
    $pdo = Database::getCobranzasConnection();
    $seller = requireSellerContext($pdo);
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();
    $documentId = filter_var($input['documento_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$documentId) {
        throw new InvalidArgumentException('documento_id es obligatorio.');
    }

    $stmt = $pdo->prepare(
        'UPDATE rendicion_documentos
         SET estado_item = :estado_nuevo, activo = :activo, descartado_at = NOW()
         WHERE id = :id
           AND empresa_id = :empresa_id
           AND vendedor_id = :vendedor_id
           AND rendicion_id IS NULL
           AND estado_item = :estado_actual
           AND activo = :activo_actual'
    );
    $stmt->execute([
        ':estado_nuevo' => 'DESCARTADO',
        ':activo' => 0,
        ':id' => $documentId,
        ':empresa_id' => $seller['empresa_id'],
        ':vendedor_id' => $seller['vendedor_id'],
        ':estado_actual' => 'BORRADOR',
        ':activo_actual' => 1,
    ]);
    if ($stmt->rowCount() !== 1) {
        RendicionesService::jsonResponse(false, ['message' => 'Documento no encontrado o ya rendido.'], 404);
    }

    RendicionesService::jsonResponse(true, ['message' => 'Documento quitado de la bolsa.']);
} catch (InvalidArgumentException $exception) {
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('[rendiciones.eliminar_documento_bolsa] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible quitar el documento.'], 500);
}
