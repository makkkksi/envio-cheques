<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/RendicionPlanillaPdf.php';

try {
    $pdo = Database::getCobranzasConnection();
    $renditionId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $rawToken = strtolower(trim((string)($_GET['token'] ?? '')));

    // Autenticación: o por sesión de usuario, o por Magic Token válido
    $authorized = false;
    if ($rawToken !== '' && preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        $stmtToken = $pdo->prepare('SELECT rendicion_id FROM solicitudes_aprobacion WHERE token_hash = :hash LIMIT 1');
        $stmtToken->execute([':hash' => hash('sha256', $rawToken)]);
        $tokenRow = $stmtToken->fetch(PDO::FETCH_ASSOC);
        if ($tokenRow && !empty($tokenRow['rendicion_id'])) {
            $renditionId = (int)$tokenRow['rendicion_id'];
            $authorized = true;
        }
    }

    if (!$authorized) {
        requirePermission($pdo, 'rendiciones.view');
    }

    if (!$renditionId) {
        http_response_code(400);
        die('ID de rendición no especificado.');
    }

    $stmt = $pdo->prepare('SELECT id, codigo_rendicion, pdf_planilla_url, estado FROM rendiciones_gastos WHERE id = :id AND activo = 1 LIMIT 1');
    $stmt->execute([':id' => $renditionId]);
    $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$rendition) {
        http_response_code(404);
        die('Rendición no encontrada.');
    }

    $relativePdf = (string)($rendition['pdf_planilla_url'] ?? '');
    $absolutePdf = __DIR__ . '/../../../' . $relativePdf;

    // Si el archivo físico no existe aún, generarlo
    if ($relativePdf === '' || !file_exists($absolutePdf)) {
        $relativePdf = RendicionPlanillaPdf::buildAndSave($pdo, $renditionId);
        $absolutePdf = __DIR__ . '/../../../' . $relativePdf;
    }

    if (!file_exists($absolutePdf)) {
        http_response_code(500);
        die('No fue posible generar el archivo PDF de la planilla.');
    }

    $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$rendition['codigo_rendicion']);
    $filename = "Planilla_{$code}.pdf";

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($absolutePdf));
    header('Cache-Control: private, max-age=3600');
    readfile($absolutePdf);
    exit;
} catch (Throwable $e) {
    error_log('[descargar_planilla.php] ' . $e->getMessage());
    http_response_code(500);
    die('Error al procesar la descarga de la planilla.');
}
