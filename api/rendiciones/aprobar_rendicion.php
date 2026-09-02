<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';
require_once __DIR__ . '/../../services/ApprovalWorkflowService.php';

RendicionesService::requireMethod('POST');

$pdo = null;
try {
    startSecureSession();
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();
    $rawToken = strtolower(trim((string)($input['token'] ?? '')));
    $legacyDecision = strtoupper(trim((string)($input['decision'] ?? '')));
    $decisionMap = [
        'APROBADO'      => ApprovalWorkflowService::DECISION_APPROVED,
        'RECHAZADO'     => ApprovalWorkflowService::DECISION_REJECTED,
        'APROBADO_TOPE' => ApprovalWorkflowService::DECISION_APPROVED_CAPPED,
    ];
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken) || !isset($decisionMap[$legacyDecision])) {
        throw new InvalidArgumentException('Token o decisión no válidos.');
    }
    $comment = RendicionesService::truncateText(trim((string)($input['comentario'] ?? '')), 500);

    $pdo = Database::getCobranzasConnection();
    $pdo->beginTransaction();
    $result = ApprovalWorkflowService::resolveByToken($pdo, $rawToken, $decisionMap[$legacyDecision], $comment);
    $request = $result['solicitud'];
    if (($request['tipo_solicitud'] ?? '') !== ApprovalWorkflowService::TYPE_RENDITION_APPROVAL) {
        throw new DomainException('El enlace no corresponde a una solicitud de aprobación de rendición.');
    }
    if (!empty($result['expired'])) {
        $pdo->commit();
        RendicionesService::jsonResponse(false, ['message' => 'El enlace de aprobación expiró. Solicite a Tesorería un nuevo enlace.'], 410);
    }
    $pdo->commit();

    $pdfUrl = null;
    if ($legacyDecision === 'APROBADO' || $legacyDecision === 'APROBADO_TOPE') {
        $pdfUrl = PORTAL_BASE_URL . '/admin/api/rendiciones/descargar_planilla.php?token=' . rawurlencode($rawToken);
    }

    $messages = [
        'APROBADO'      => 'Rendición de gastos aprobada en su totalidad.',
        'APROBADO_TOPE' => 'Rendición aprobada hasta el tope presupuestario. El exceso no será reembolsado.',
        'RECHAZADO'     => 'Rendición de gastos rechazada.',
    ];

    RendicionesService::jsonResponse(true, [
        'message' => $messages[$legacyDecision],
        'data' => [
            'rendicion_id'    => (int)$request['rendicion_id'],
            'decision'        => $legacyDecision,
            'aprobador_nombre'=> $request['aprobador_nombre_snapshot'],
            'aprobador_cargo' => $request['aprobador_cargo_snapshot'],
            'pdf_url'         => $pdfUrl,
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
    error_log('[rendiciones.aprobar_rendicion] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible registrar la decisión de la rendición.'], 500);
}
