<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/ApprovalWorkflowService.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
startSecureSession();
$token = strtolower(trim((string)($_GET['token'] ?? '')));
$validTokenFormat = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$csrfToken = getCsrfToken();
$rendition = null;
$documents = [];
$treasuryComment = '';
$pageError = '';
$canResolve = false;
$resolvedDecision = '';

if ($validTokenFormat) {
    try {
        $pdo = Database::getCobranzasConnection();
        $request = ApprovalWorkflowService::getByToken($pdo, $token);
        if (($request['tipo_solicitud'] ?? '') !== ApprovalWorkflowService::TYPE_MONTHLY_EXCEPTION) {
            throw new InvalidArgumentException('El enlace no corresponde a una excepción mensual.');
        }
        $stmt = $pdo->prepare(
            'SELECT r.*, e.nombre AS empresa_nombre, p.nombre_gira,
                    sa.estado AS solicitud_estado, sa.decision AS solicitud_decision,
                    sa.aprobador_id AS aprobador_solicitado_id,
                    sa.aprobador_nombre_snapshot, sa.aprobador_cargo_snapshot,
                    sa.aprobador_email_snapshot, sa.monto_solicitado AS solicitud_monto,
                    sa.token_expira_at AS token_exceso_expira,
                    sa.token_usado_at AS token_exceso_usado_at
             FROM solicitudes_aprobacion sa
             INNER JOIN rendiciones_gastos r ON r.id = sa.rendicion_id
             INNER JOIN empresas e ON e.id = r.empresa_id
             INNER JOIN presupuestos_vendedores p ON p.id = r.presupuesto_id
             WHERE sa.id = :solicitud_id
             LIMIT 1'
        );
        $stmt->execute([':solicitud_id' => (int)$request['id']]);
        $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rendition) {
            $pageError = 'El enlace no existe o fue reemplazado por una solicitud más reciente.';
        } elseif ($rendition['solicitud_estado'] !== ApprovalWorkflowService::STATE_PENDING_DECISION || $rendition['token_exceso_usado_at'] !== null) {
            $resolvedDecision = ($rendition['solicitud_decision'] ?? '') === ApprovalWorkflowService::DECISION_APPROVED
                ? 'APROBADO'
                : (($rendition['solicitud_decision'] ?? '') === ApprovalWorkflowService::DECISION_REJECTED ? 'RECHAZADO' : '');
            if ($resolvedDecision === '') {
                $pageError = 'Esta solicitud ya fue resuelta y el enlace no puede volver a utilizarse.';
            }
        } elseif (!(bool)$request['activo'] || !$rendition['token_exceso_expira'] || strtotime((string)$rendition['token_exceso_expira']) < time()) {
            $pageError = 'El enlace expiró. Solicite a Tesorería que emita una nueva solicitud.';
        } elseif (!filter_var($rendition['aprobador_email_snapshot'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $pageError = 'La solicitud no tiene un responsable válido. Tesorería debe emitir un nuevo enlace.';
        } else {
            $canResolve = true;
            $stmtDocuments = $pdo->prepare(
                'SELECT id, tipo_documento, categoria_gasto, rut_proveedor,
                        razon_social_proveedor, numero_documento, fecha_emision,
                        monto, descripcion, foto_documento_url,
                        cliente_invitado_nombre, cliente_invitado_rut,
                        cliente_invitado_empresa, cliente_invitado_cargo,
                        proposito_comercial
                 FROM rendicion_documentos
                 WHERE rendicion_id = :rendicion_id AND activo = :activo
                 ORDER BY fecha_emision ASC, id ASC'
            );
            $stmtDocuments->execute([':rendicion_id' => (int)$rendition['id'], ':activo' => 1]);
            $documents = $stmtDocuments->fetchAll(PDO::FETCH_ASSOC);
            $stmtComment = $pdo->prepare(
                'SELECT comentario
                 FROM rendicion_historial_estados
                 WHERE rendicion_id = :rendicion_id
                   AND accion IN (:accion_actual, :accion_legacy)
                   AND comentario IS NOT NULL
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmtComment->execute([
                ':rendicion_id' => (int)$rendition['id'],
                ':accion_actual' => 'SOLICITAR_EXCEPCION_MENSUAL',
                ':accion_legacy' => 'ENVIAR_SOLICITUD_EXCESO',
            ]);
            $treasuryComment = trim((string)($stmtComment->fetchColumn() ?: ''));
        }
    } catch (Throwable $exception) {
        error_log('[rendiciones.aprobar_exceso.page] ' . $exception->getMessage());
        $pageError = 'No fue posible consultar la solicitud. Intente nuevamente.';
    }
} else {
    $pageError = 'El enlace recibido está incompleto o no es válido.';
}

function approvalEscape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function approvalMoney(mixed $value): string { return '$' . number_format((float)$value, 0, ',', '.'); }
function approvalPhotoUrl(mixed $value): string {
    $path = trim((string)$value);
    if ($path === '') return '';
    if (preg_match('#^https://#i', $path)) return $path;
    return rtrim(PORTAL_BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
}
$budget = (float)($rendition['monto_presupuesto_asignado'] ?? 0);
$available = (float)($rendition['saldo_disponible_al_enviar'] ?? 0);
$previouslyCommitted = max(0, $budget - $available);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= approvalEscape($csrfToken) ?>">
    <title>Resolver exceso de rendición</title>
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="aprobar_exceso.css?v=20260826-2">
</head>
<body>
<main class="approval-card" data-token="<?= $canResolve ? approvalEscape($token) : '' ?>">
    <header class="approval-header"><span class="approval-card__brand">Grupo Automarco</span><h1><?= $canResolve ? 'Decisión de exceso presupuestario' : ($resolvedDecision === 'APROBADO' ? 'Exceso aprobado' : ($resolvedDecision === 'RECHAZADO' ? 'Exceso rechazado' : 'Enlace no disponible')) ?></h1><p><?= $canResolve ? 'Revisa los antecedentes antes de aprobar o rechazar. Abrir esta página no registra ninguna decisión.' : ($resolvedDecision !== '' ? 'La solicitud fue resuelta y este enlace ya no admite nuevas decisiones.' : approvalEscape($pageError)) ?></p></header>
    <?php if (!$canResolve && $resolvedDecision !== '' && $rendition): ?>
    <section class="approval-decision"><div class="approval-resolved approval-resolved--<?= $resolvedDecision === 'APROBADO' ? 'approved' : 'rejected' ?>" role="status"><span class="approval-resolved__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><?php if ($resolvedDecision === 'APROBADO'): ?><path d="m5 12 4 4L19 6"/><?php else: ?><path d="m7 7 10 10M17 7 7 17"/><?php endif; ?></svg></span><div><h2><?= $resolvedDecision === 'APROBADO' ? 'Exceso aprobado' : 'Exceso rechazado' ?></h2><p><?= $resolvedDecision === 'APROBADO' ? 'La decisión quedó registrada. La rendición continúa su revisión normal en Tesorería.' : 'La decisión quedó registrada y Tesorería fue informada para continuar la gestión.' ?></p><small>Decisión registrada por <strong><?= approvalEscape($rendition['aprobador_nombre_snapshot']) ?> · <?= approvalEscape($rendition['aprobador_cargo_snapshot']) ?></strong>.</small></div></div></section>
    <?php endif; ?>
    <?php if ($canResolve && $rendition): ?>
    <section class="approval-identity" aria-label="Solicitud">
        <div><span>Rendición</span><strong><?= approvalEscape($rendition['codigo_rendicion']) ?></strong></div>
        <div><span>Responsable</span><strong><?= approvalEscape($rendition['aprobador_nombre_snapshot']) ?></strong><small><?= approvalEscape($rendition['aprobador_cargo_snapshot']) ?></small></div>
        <div><span>Vendedor</span><strong><?= approvalEscape($rendition['vendedor_nombre']) ?></strong><small>Código ERP #<?= (int)$rendition['vendedor_id'] ?></small></div>
        <div><span>Empresa y fondo</span><strong><?= approvalEscape($rendition['empresa_nombre']) ?></strong><small><?= approvalEscape($rendition['tipo_rendicion'] === 'GIRA' ? 'Gira comercial: ' . ($rendition['nombre_gira'] ?: 'Sin nombre') : 'Presupuesto mensual') ?> · <?= approvalEscape($rendition['periodo_mes']) ?></small></div>
    </section>
    <section class="approval-metrics" aria-label="Resumen financiero">
        <div><span>Asignado</span><strong><?= approvalMoney($budget) ?></strong></div><div><span>Rendido previamente</span><strong><?= approvalMoney($previouslyCommitted) ?></strong></div><div><span>Saldo anterior</span><strong><?= approvalMoney($available) ?></strong></div><div class="approval-metrics__total"><span>Total rendido</span><strong><?= approvalMoney($rendition['monto_total_rendido']) ?></strong></div><div class="approval-metrics__excess"><span>Exceso solicitado</span><strong>+<?= approvalMoney($rendition['solicitud_monto']) ?></strong></div>
    </section>
    <?php if (trim((string)$rendition['nota_vendedor']) !== ''): ?><aside class="approval-note"><strong>Nota del vendedor</strong><p><?= nl2br(approvalEscape($rendition['nota_vendedor'])) ?></p></aside><?php endif; ?>
    <?php if ($treasuryComment !== ''): ?><aside class="approval-note approval-note--treasury"><strong>Comentario de Tesorería</strong><p><?= nl2br(approvalEscape($treasuryComment)) ?></p></aside><?php endif; ?>
    <section class="approval-documents"><div class="approval-section-title"><h2>Comprobantes</h2><span><?= count($documents) ?> documento(s)</span></div>
        <?php foreach ($documents as $document): ?><article class="approval-document">
            <div><span><?= approvalEscape(str_replace('_', ' ', $document['categoria_gasto'])) ?></span><strong><?= approvalEscape($document['razon_social_proveedor'] ?: 'Proveedor no informado') ?></strong><small><?= approvalEscape($document['tipo_documento']) ?> · <?= approvalEscape($document['fecha_emision']) ?> · RUT <?= approvalEscape($document['rut_proveedor'] ?: 'no informado') ?> · Folio <?= approvalEscape($document['numero_documento'] ?: 'no informado') ?></small><?php if (approvalPhotoUrl($document['foto_documento_url']) !== ''): ?><a class="approval-document__link" href="<?= approvalEscape(approvalPhotoUrl($document['foto_documento_url'])) ?>" target="_blank" rel="noopener noreferrer">Abrir comprobante</a><?php endif; ?></div><strong><?= approvalMoney($document['monto']) ?></strong>
            <?php if ($document['categoria_gasto'] === 'CENA_CLIENTE'): ?><dl class="approval-document__sii"><div><dt>Invitado</dt><dd><?= approvalEscape($document['cliente_invitado_nombre']) ?> · <?= approvalEscape($document['cliente_invitado_rut']) ?></dd></div><div><dt>Empresa / cargo</dt><dd><?= approvalEscape($document['cliente_invitado_empresa']) ?> · <?= approvalEscape($document['cliente_invitado_cargo']) ?></dd></div><div><dt>Propósito comercial</dt><dd><?= approvalEscape($document['proposito_comercial']) ?></dd></div></dl><?php endif; ?>
        </article><?php endforeach; ?>
    </section>
    <section class="approval-decision"><label for="comentario">Comentario de la decisión <span>(obligatorio al rechazar)</span></label><textarea id="comentario" maxlength="500" rows="4" placeholder="Agrega contexto para Tesorería y la trazabilidad de auditoría."></textarea><div class="approval-actions"><button type="button" class="approval-card__button approval-card__button--rechazado" data-decision="RECHAZADO">Rechazar exceso</button><button type="button" class="approval-card__button approval-card__button--aprobado" data-decision="APROBADO">Aprobar exceso</button></div><p id="resultado" class="approval-card__result" aria-live="polite"></p></section>
    <?php endif; ?>
</main>
<?php if ($canResolve): ?><script src="aprobar_exceso.js?v=20260826-2"></script><?php endif; ?>
</body></html>
