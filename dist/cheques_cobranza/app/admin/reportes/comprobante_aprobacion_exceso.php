<?php

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionApprovalPdf.php';

header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        throw new InvalidArgumentException('Método no permitido.');
    }

    $renditionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if (!$renditionId) {
        http_response_code(422);
        throw new InvalidArgumentException('La rendición indicada no es válida.');
    }

    $pdo = Database::getCobranzasConnection();
    requirePermission($pdo, 'rendiciones.view');

    $stmt = $pdo->prepare(
        'SELECT r.*, e.nombre AS empresa_nombre, p.nombre_gira,
                sa.aprobador_nombre_snapshot, sa.aprobador_cargo_snapshot,
                sa.aprobador_email_snapshot, sa.resuelto_at AS aprobado_exceso_at,
                :decision_legacy AS decision_exceso
         FROM rendiciones_gastos r
         INNER JOIN empresas e ON e.id = r.empresa_id
         INNER JOIN presupuestos_vendedores p ON p.id = r.presupuesto_id
         INNER JOIN solicitudes_aprobacion sa ON sa.id = r.solicitud_excepcion_id
         WHERE r.id = :id
           AND r.activo = :activo
           AND sa.tipo_solicitud = :tipo_solicitud
           AND sa.decision = :decision
           AND sa.resuelto_at IS NOT NULL
         LIMIT 1'
    );
    $stmt->execute([
        ':decision_legacy' => 'APROBADO', ':id' => $renditionId, ':activo' => 1,
        ':tipo_solicitud' => 'EXCEPCION_MENSUAL', ':decision' => 'APROBADA',
    ]);
    $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rendition) {
        http_response_code(404);
        throw new DomainException('No existe una aprobación de exceso disponible para esta rendición.');
    }

    $stmtDocuments = $pdo->prepare(
        'SELECT tipo_documento, categoria_gasto, rut_proveedor,
                razon_social_proveedor, numero_documento, fecha_emision,
                monto, cliente_invitado_nombre, cliente_invitado_rut,
                cliente_invitado_empresa, cliente_invitado_cargo,
                proposito_comercial
         FROM rendicion_documentos
         WHERE rendicion_id = :rendicion_id AND activo = :activo
         ORDER BY fecha_emision ASC, id ASC'
    );
    $stmtDocuments->execute([':rendicion_id' => $renditionId, ':activo' => 1]);

    $pdf = RendicionApprovalPdf::build($rendition, $stmtDocuments->fetchAll(PDO::FETCH_ASSOC));
    $safeCode = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$rendition['codigo_rendicion']);
    $filename = 'Comprobante_Exceso_' . ($safeCode ?: $renditionId) . '.pdf';
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
    error_log('[admin.rendiciones.comprobante_aprobacion_exceso] ' . $exception->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No fue posible generar el comprobante de aprobación.';
}
