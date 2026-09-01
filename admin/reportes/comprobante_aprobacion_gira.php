<?php

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/GiraApprovalPdf.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        throw new InvalidArgumentException('Método no permitido.');
    }

    $budgetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$budgetId) {
        http_response_code(422);
        throw new InvalidArgumentException('La gira indicada no es válida.');
    }

    $pdo = Database::getCobranzasConnection();
    requirePermission($pdo, 'rendiciones.view');

    $stmt = $pdo->prepare(
        'SELECT p.*, e.nombre AS empresa_nombre,
                sa.aprobador_nombre_snapshot, sa.aprobador_cargo_snapshot,
                sa.aprobador_email_snapshot, sa.resuelto_at, sa.comentario_decision,
                sa.justificacion
         FROM presupuestos_vendedores p
         INNER JOIN empresas e ON e.id = p.empresa_id
         LEFT JOIN solicitudes_aprobacion sa ON sa.id = p.solicitud_aprobacion_id
         WHERE p.id = :id
           AND p.tipo_presupuesto = :tipo_presupuesto
           AND p.estado_aprobacion = :estado_aprobacion
         LIMIT 1'
    );
    $stmt->execute([
        ':id' => $budgetId,
        ':tipo_presupuesto' => 'GIRA',
        ':estado_aprobacion' => 'APROBADA',
    ]);
    $tour = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$tour) {
        http_response_code(404);
        throw new DomainException('No existe un comprobante de aprobación disponible para esta gira.');
    }

    $pdf = GiraApprovalPdf::build($tour);
    $safeName = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$tour['nombre_gira']);
    $filename = 'Comprobante_Gira_' . ($safeName ?: $budgetId) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
} catch (InvalidArgumentException | DomainException $exception) {
    if (http_response_code() < 400) {
        http_response_code(422);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo $exception->getMessage();
} catch (Throwable $exception) {
    error_log('[admin.rendiciones.comprobante_aprobacion_gira] ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No fue posible generar el comprobante de aprobación de gira.';
}
