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
        if (($request['tipo_solicitud'] ?? '') !== ApprovalWorkflowService::TYPE_RENDITION_APPROVAL) {
            throw new InvalidArgumentException('El enlace no corresponde a una aprobación de rendición.');
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
            $resolvedDecision = '';
            $resolvedIsCapped = false;
            $solDecision = (string)($rendition['solicitud_decision'] ?? '');
            if ($solDecision === ApprovalWorkflowService::DECISION_APPROVED) {
                $resolvedDecision = 'APROBADO';
            } elseif ($solDecision === ApprovalWorkflowService::DECISION_APPROVED_CAPPED) {
                $resolvedDecision = 'APROBADO';
                $resolvedIsCapped = true;
            } elseif ($solDecision === ApprovalWorkflowService::DECISION_REJECTED) {
                $resolvedDecision = 'RECHAZADO';
            }
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
                        monto, monto_validado, descripcion, foto_documento_url,
                        cliente_invitado_nombre, cliente_invitado_rut,
                        cliente_invitado_empresa, cliente_invitado_cargo,
                        proposito_comercial, estado_item, motivo_rechazo
                 FROM rendicion_documentos
                 WHERE rendicion_id = :rendicion_id AND activo = 1 AND estado_item != "DESCARTADO"
                 ORDER BY fecha_emision ASC, id ASC'
            );
            $stmtDocuments->execute([':rendicion_id' => (int)$rendition['id']]);
            $documents = $stmtDocuments->fetchAll(PDO::FETCH_ASSOC);

            $stmtComment = $pdo->prepare(
                'SELECT comentario
                 FROM rendicion_historial_estados
                 WHERE rendicion_id = :rendicion_id
                   AND accion = "VERIFICAR_Y_ENVIAR_RESPONSABLE"
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmtComment->execute([':rendicion_id' => (int)$rendition['id']]);
            $treasuryComment = trim((string)($stmtComment->fetchColumn() ?: ''));
        }
    } catch (Throwable $exception) {
        error_log('[rendiciones.aprobar_rendicion.page] ' . $exception->getMessage());
        $pageError = 'No fue posible consultar la solicitud. Intente nuevamente.';
    }
} else {
    $pageError = 'El enlace recibido está incompleto o no es válido.';
}

function rEscape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function rMoney(mixed $value): string { return '$' . number_format((float)$value, 0, ',', '.'); }
function rPhotoUrl(mixed $value): string {
    $path = trim((string)$value);
    if ($path === '') return '';
    if (preg_match('#^https?://#i', $path)) return $path;
    return rtrim(PORTAL_BASE_URL, '/') . '/' . ltrim(str_replace('\\', '/', $path), '/');
}

$budget = (float)($rendition['monto_presupuesto_asignado'] ?? 0);
$totalRendido = (float)($rendition['monto_total_rendido'] ?? 0);
$excess = (float)($rendition['monto_exceso'] ?? 0);
$totalAprobado = (float)($rendition['monto_total_aprobado'] ?? $totalRendido);
if ($totalAprobado <= 0) $totalAprobado = $totalRendido;
$resolvedIsCapped = $resolvedIsCapped ?? false;
$downloadPdfUrl = PORTAL_BASE_URL . '/admin/api/rendiciones/descargar_planilla.php?token=' . rawurlencode($token);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= rEscape($csrfToken) ?>">
    <title>Aprobación de Rendición de Gastos</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="aprobar_exceso.css?v=20260904-1">
</head>
<body>
<main class="approval-card"
    data-token="<?= $canResolve ? rEscape($token) : '' ?>"
    data-pdf-url="<?= rEscape($downloadPdfUrl) ?>"
    data-budget="<?= $budget ?>"
    data-max-aprobable="<?= $canResolve ? number_format((float)($rendition['monto_maximo_aprobable'] ?? $totalRendido), 0, ',', '.') : '' ?>"
    data-excess="<?= $canResolve ? ($excess > 0 ? '1' : '0') : '0' ?>">
    <header class="approval-header">
        <span class="approval-card__brand"><?= rEscape($rendition['empresa_nombre'] ?? 'Grupo Automarco') ?></span>
        <h1><?= $canResolve ? 'Aprobación de Rendición de Gastos' : ($resolvedDecision === 'APROBADO' ? 'Rendición Aprobada' : ($resolvedDecision === 'RECHAZADO' ? 'Rendición Rechazada' : 'Enlace no disponible')) ?></h1>
        <p><?= $canResolve ? 'Fotos cotejadas por Tesorería. Audita los comprobantes y emite tu resolución.' : ($resolvedDecision !== '' ? 'La solicitud fue resuelta y este enlace ya no admite nuevas decisiones.' : rEscape($pageError)) ?></p>
    </header>

    <?php if (!$canResolve && $resolvedDecision !== '' && $rendition): ?>
    <section class="approval-decision">
        <div class="approval-resolved approval-resolved--<?= $resolvedDecision === 'APROBADO' ? 'approved' : 'rejected' ?>" role="status">
            <span class="approval-resolved__icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">
                    <?php if ($resolvedDecision === 'APROBADO' || $resolvedIsCapped): ?>
                        <path d="m5 12 4 4L19 6"/>
                    <?php else: ?>
                        <path d="m7 7 10 10M17 7 7 17"/>
                    <?php endif; ?>
                </svg>
            </span>
            <div>
                <?php if ($resolvedIsCapped): ?>
                    <h2>Rendición Aprobada hasta el Tope</h2>
                    <p>La rendición fue aprobada solo hasta el presupuesto asignado. El exceso no fue cubierto y no será reembolsado.</p>
                <?php elseif ($resolvedDecision === 'APROBADO'): ?>
                    <h2>Rendición aprobada exitosamente</h2>
                    <p>La rendición quedó oficialmente autorizada para que Tesorería proceda con el pago/reembolso.</p>
                <?php else: ?>
                    <h2>Rendición rechazada</h2>
                    <p>La rendición fue rechazada y se notificó a Tesorería y al vendedor.</p>
                <?php endif; ?>
                <small>Decisión registrada por <strong><?= rEscape($rendition['aprobador_nombre_snapshot']) ?> · <?= rEscape($rendition['aprobador_cargo_snapshot']) ?></strong>.</small>
                <?php if ($resolvedDecision === 'APROBADO' || $resolvedIsCapped): ?>
                <br>
                <a href="<?= rEscape($downloadPdfUrl) ?>" target="_blank" class="approval-card__pdf-btn">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                    Descargar Planilla Oficial en PDF (Excel)
                </a>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($canResolve && $rendition): ?>
    <section class="approval-identity" aria-label="Identificación">
        <div><span>Rendición Folio</span><strong><?= rEscape($rendition['codigo_rendicion']) ?></strong></div>
        <div><span>Responsable</span><strong><?= rEscape($rendition['aprobador_nombre_snapshot']) ?></strong><small><?= rEscape($rendition['aprobador_cargo_snapshot']) ?></small></div>
        <div><span>Vendedor</span><strong><?= rEscape($rendition['vendedor_nombre']) ?></strong><small>Código ERP #<?= (int)$rendition['vendedor_id'] ?></small></div>
        <div><span>Empresa y Asignación</span><strong><?= rEscape($rendition['empresa_nombre']) ?></strong><small><?= rEscape($rendition['tipo_rendicion'] === 'GIRA' ? 'Gira comercial: ' . ($rendition['nombre_gira'] ?: 'Sin nombre') : 'Presupuesto mensual') ?> · <?= rEscape($rendition['periodo_mes']) ?></small></div>
    </section>

    <div class="approval-card__excess-box" id="excessAlertBox" style="display: <?= $excess > 0 ? 'block' : 'none' ?>;">
        <strong>Exceso Presupuestario:</strong> Esta rendición presenta un gasto de <strong id="excessAmountText"><?= rMoney($excess) ?></strong> por sobre el presupuesto asignado. Al pulsar "Aprobar Rendición", autorizas la cobertura de este exceso. Puedes también optar por <strong>Aprobar hasta el Tope</strong> sin cubrir el exceso.
    </div>

    <section class="approval-metrics" aria-label="Resumen financiero">
        <div><span>Presupuesto</span><strong><?= rMoney($budget) ?></strong></div>
        <div><span>Total Rendido</span><strong><?= rMoney($totalRendido) ?></strong></div>
        <div><span>Exceso</span><strong id="metricExcess" style="color:<?= $excess > 0 ? '#b91c1c' : '#64748b' ?>"><?= $excess > 0 ? '+' . rMoney($excess) : '$0' ?></strong></div>
        <div class="approval-metrics__total"><span>Total a Autorizar</span><strong id="metricTotalApproved"><?= rMoney($totalAprobado) ?></strong></div>
    </section>

    <?php if (trim((string)$rendition['nota_vendedor']) !== ''): ?>
    <aside class="approval-note">
        <strong>Nota del vendedor</strong>
        <p><?= nl2br(rEscape($rendition['nota_vendedor'])) ?></p>
    </aside>
    <?php endif; ?>

    <?php if ($treasuryComment !== ''): ?>
    <aside class="approval-note approval-note--treasury">
        <strong>Observación de Tesorería</strong>
        <p><?= nl2br(rEscape($treasuryComment)) ?></p>
    </aside>
    <?php endif; ?>

    <section class="approval-documents">
        <div class="approval-section-title">
            <div>
                <h2>Comprobantes para Auditoría</h2>
                <p style="margin:2px 0 0;font-size:0.8rem;color:#64748b">Revisa cada gasto individualmente. Puedes aprobar con el monto completo, rebajar el monto a reembolsar o rechazar boletas no correspondientes.</p>
            </div>
            <span class="approval-documents-count"><?= count($documents) ?> comprobante(s)</span>
        </div>
        <div class="approval-doc-list" id="approvalDocList">
        <?php foreach ($documents as $document): 
            $isItemRejected = ($document['estado_item'] === 'RECHAZADO');
            $docMonto = (float)$document['monto'];
            $docMontoVal = ($document['monto_validado'] !== null && !$isItemRejected) ? (float)$document['monto_validado'] : $docMonto;
            $docReason = (string)($document['motivo_rechazo'] ?? '');
        ?>
        <article class="approval-doc-card <?= $isItemRejected ? 'is-rejected' : 'is-approved' ?>" data-doc-id="<?= (int)$document['id'] ?>" data-doc-orig-amount="<?= $docMonto ?>">
            <div class="approval-doc-card__header">
                <div class="approval-doc-card__icon-wrap">
                    <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <rect x="4" y="3" width="16" height="18" rx="2"/>
                        <path d="M8 7h8M8 11h8M8 15h5"/>
                    </svg>
                </div>
                <div class="approval-doc-card__info">
                    <div class="approval-doc-card__title-row">
                        <strong><?= rEscape($document['razon_social_proveedor'] ?: 'Proveedor no informado') ?></strong>
                        <span class="approval-doc-card__badge"><?= rEscape(str_replace('_', ' ', $document['categoria_gasto'])) ?></span>
                    </div>
                    <div class="approval-doc-card__meta">
                        <span><?= rEscape($document['tipo_documento']) ?></span>
                        <span>Folio: <?= rEscape($document['numero_documento'] ?: 's/i') ?></span>
                        <span><?= rEscape($document['fecha_emision']) ?></span>
                        <span>RUT: <?= rEscape($document['rut_proveedor'] ?: 's/i') ?></span>
                    </div>
                    <?php if (rPhotoUrl($document['foto_documento_url']) !== ''): ?>
                    <div style="margin-top:5px">
                        <a class="approval-doc-card__photo-link" href="<?= rEscape(rPhotoUrl($document['foto_documento_url'])) ?>" target="_blank" rel="noopener noreferrer">
                            <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            Ver foto boleta
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="approval-doc-card__claimed">
                    <span class="approval-doc-card__claimed-label">Monto rendido</span>
                    <strong class="approval-doc-card__claimed-amount"><?= rMoney($docMonto) ?></strong>
                </div>
            </div>

            <?php if ($document['categoria_gasto'] === 'CENA_CLIENTE'): ?>
            <dl class="approval-document__sii" style="margin:8px 0;background:#fffbeb;padding:8px 12px;border-radius:6px;border:1px solid #fef3c7;font-size:0.75rem">
                <div><dt style="font-weight:700;color:#92400e">Invitado:</dt> <dd><?= rEscape($document['cliente_invitado_nombre']) ?> · <?= rEscape($document['cliente_invitado_rut']) ?></dd></div>
                <div><dt style="font-weight:700;color:#92400e">Empresa / Cargo:</dt> <dd><?= rEscape($document['cliente_invitado_empresa']) ?> · <?= rEscape($document['cliente_invitado_cargo']) ?></dd></div>
                <div><dt style="font-weight:700;color:#92400e">Propósito SII:</dt> <dd><?= rEscape($document['proposito_comercial']) ?></dd></div>
            </dl>
            <?php endif; ?>

            <div class="approval-doc-card__controls">
                <div class="approval-doc-field">
                    <label class="approval-doc-label">Decisión</label>
                    <select class="approval-doc-select" data-doc-decision>
                        <option value="APROBAR" <?= !$isItemRejected ? 'selected' : '' ?>>Aprobar</option>
                        <option value="RECHAZAR" <?= $isItemRejected ? 'selected' : '' ?>>Rechazar</option>
                    </select>
                </div>
                <div class="approval-doc-field">
                    <label class="approval-doc-label">Monto validado ($)</label>
                    <input class="approval-doc-input approval-doc-input--amount" data-doc-amount type="number" min="0" max="<?= $docMonto ?>" step="1" value="<?= $docMontoVal ?>" <?= $isItemRejected ? 'disabled' : '' ?>>
                </div>
                <div class="approval-doc-field approval-doc-field--reason">
                    <label class="approval-doc-label">Motivo de rechazo</label>
                    <input class="approval-doc-input approval-doc-input--reason" data-doc-reason type="text" maxlength="255" placeholder="Obligatorio si se rechaza..." value="<?= rEscape($docReason) ?>" <?= !$isItemRejected ? 'disabled' : '' ?>>
                </div>
            </div>
        </article>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="approval-decision">
        <label for="comentario">Comentario de la resolución <span>(opcional, obligatorio si rechazas la rendición completa)</span></label>
        <textarea id="comentario" maxlength="500" rows="3" placeholder="Ingresa una observación o motivo general..."></textarea>
        <div class="approval-actions" id="approvalActions">
            <button type="button" class="approval-card__button approval-card__button--rechazado" data-decision="RECHAZADO">Rechazar Rendición</button>
            <button type="button" class="approval-card__button approval-card__button--tope" id="btnApproveTope" data-decision="APROBADO_TOPE" style="display: <?= $excess > 0 ? 'inline-block' : 'none' ?>;">
                Aprobar hasta el Tope<br><small style="font-weight:500;font-size:0.8rem;opacity:0.9" id="btnTopeSubtext">Sin cubrir exceso (<?= rMoney($excess) ?>)</small>
            </button>
            <button type="button" class="approval-card__button approval-card__button--aprobado" id="btnApproveAll" data-decision="APROBADO">Aprobar Rendición</button>
        </div>
        <p id="resultado" class="approval-card__result" aria-live="polite"></p>
    </section>
    <?php endif; ?>
</main>
<?php if ($canResolve): ?>
<script src="aprobar_rendicion.js?v=20260904-1"></script>
<?php endif; ?>
</body>
</html>
