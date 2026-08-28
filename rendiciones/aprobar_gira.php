<?php
/**
 * aprobar_gira.php — Página pública de aprobación / rechazo de gira comercial.
 *
 * Accedida vía Magic Link (token en query string).
 * No requiere autenticación: el token es el único mecanismo.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
startSecureSession();

$token           = strtolower(trim((string)($_GET['token'] ?? '')));
$validTokenFormat = preg_match('/^[a-f0-9]{64}$/', $token) === 1;
$csrfToken       = getCsrfToken();
$solicitud        = null;
$budget           = null;
$pageError        = '';
$canResolve       = false;
$resolvedDecision = '';

if ($validTokenFormat) {
    try {
        $pdo = Database::getCobranzasConnection();
        $stmt = $pdo->prepare(
            'SELECT sa.*, p.nombre_gira, p.vendedor_nombre, p.vendedor_email,
                    p.monto_asignado, p.periodo_mes, p.fecha_inicio, p.fecha_fin,
                    p.justificacion_gira, e.nombre AS empresa_nombre
             FROM solicitudes_aprobacion sa
             INNER JOIN presupuestos_vendedores p ON p.id = sa.presupuesto_id
             INNER JOIN empresas e ON e.id = p.empresa_id
             WHERE sa.token_hash = :token_hash
               AND sa.tipo_solicitud = :tipo
               AND sa.activo = :activo
             LIMIT 1'
        );
        $stmt->execute([':token_hash' => hash('sha256', $token), ':tipo' => 'GIRA', ':activo' => 1]);
        $solicitud = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$solicitud) {
            $pageError = 'El enlace no existe o fue reemplazado por una solicitud más reciente.';
        } elseif ($solicitud['token_usado_at'] !== null) {
            $resolvedDecision = (string)($solicitud['decision'] ?? '');
            if ($resolvedDecision === '') {
                $pageError = 'Esta solicitud ya fue utilizada y no puede volver a usarse.';
            }
        } elseif (!$solicitud['token_expira_at'] || strtotime((string)$solicitud['token_expira_at']) < time()) {
            $pageError = 'El enlace expiró. Solicite a Tesorería que emita una nueva solicitud.';
        } elseif (!in_array($solicitud['estado'], ['PENDIENTE_DECISION', 'PENDIENTE_ENVIO'], true)) {
            $pageError = 'Esta solicitud ya fue resuelta o cancelada.';
        } else {
            $canResolve = true;
        }
    } catch (Throwable $e) {
        error_log('[aprobar_gira] ' . $e->getMessage());
        $pageError = 'Ocurrió un error al cargar la solicitud. Por favor intente más tarde.';
    }
} else {
    $pageError = 'El enlace proporcionado no es válido.';
}

$approverName  = htmlspecialchars((string)($solicitud['aprobador_nombre_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8');
$approverTitle = htmlspecialchars((string)($solicitud['aprobador_cargo_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8');
$tourName      = htmlspecialchars((string)($solicitud['nombre_gira'] ?? ''), ENT_QUOTES, 'UTF-8');
$sellerName    = htmlspecialchars((string)($solicitud['vendedor_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
$company       = htmlspecialchars((string)($solicitud['empresa_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
$period        = htmlspecialchars((string)($solicitud['periodo_mes'] ?? ''), ENT_QUOTES, 'UTF-8');
$amount        = isset($solicitud['monto_asignado']) ? '$' . number_format((float)$solicitud['monto_asignado'], 0, ',', '.') : '—';
$startDate     = htmlspecialchars((string)($solicitud['fecha_inicio'] ?? '—'), ENT_QUOTES, 'UTF-8');
$endDate       = htmlspecialchars((string)($solicitud['fecha_fin'] ?? '—'), ENT_QUOTES, 'UTF-8');
$justif        = nl2br(htmlspecialchars((string)($solicitud['justificacion_gira'] ?? ''), ENT_QUOTES, 'UTF-8'));
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Aprobación de Gira Comercial</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Inter', sans-serif; background: #f1f5f9; color: #1e293b; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem 1rem; }
  .card { background: #fff; border-radius: 16px; box-shadow: 0 4px 24px rgba(0,0,0,.12); max-width: 640px; width: 100%; overflow: hidden; }
  .card-header { background: #172554; color: #fff; padding: 2rem; }
  .card-header h1 { font-size: 1.35rem; font-weight: 700; margin-bottom: .25rem; }
  .card-header p { font-size: .875rem; color: #bfdbfe; }
  .card-body { padding: 2rem; }
  .info-table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-size: .9rem; }
  .info-table tr { border-bottom: 1px solid #f1f5f9; }
  .info-table td:first-child { padding: .65rem 0; font-weight: 600; color: #64748b; width: 40%; }
  .info-table td:last-child { padding: .65rem 0; }
  .badge { display: inline-block; padding: .25rem .7rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
  .badge-blue { background: #dbeafe; color: #1d4ed8; }
  .justif-box { background: #f0fdf4; border-left: 3px solid #16a34a; border-radius: 4px; padding: 1rem; margin-bottom: 1.5rem; font-size: .9rem; }
  .justif-box strong { display: block; margin-bottom: .4rem; color: #15803d; }
  .btn-row { display: flex; gap: 1rem; margin-top: 1.5rem; }
  .btn { flex: 1; padding: .9rem; border: none; border-radius: 10px; font-family: inherit; font-size: 1rem; font-weight: 700; cursor: pointer; transition: opacity .15s, transform .1s; }
  .btn:hover:not(:disabled) { opacity: .9; transform: translateY(-1px); }
  .btn:disabled { opacity: .5; cursor: not-allowed; }
  .btn-approve { background: #15803d; color: #fff; }
  .btn-reject  { background: #dc2626; color: #fff; }
  textarea { width: 100%; border: 1.5px solid #cbd5e1; border-radius: 8px; padding: .75rem 1rem; font-family: inherit; font-size: .9rem; resize: vertical; min-height: 90px; transition: border-color .15s; margin-top: 1rem; }
  textarea:focus { outline: none; border-color: #3b82f6; }
  .reason-label { font-size: .875rem; font-weight: 600; color: #475569; margin-top: 1.25rem; display: none; }
  #reason-row { display: none; }
  .alert { border-radius: 10px; padding: 1.25rem 1.5rem; font-size: .95rem; line-height: 1.5; }
  .alert-error  { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
  .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
  .resolved-badge { display: flex; align-items: center; gap: .75rem; font-size: 1.05rem; font-weight: 600; padding: 1.25rem; border-radius: 10px; }
  .resolved-approved { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
  .resolved-rejected  { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
  .spinner { display: none; width: 20px; height: 20px; border: 3px solid rgba(255,255,255,.4); border-top-color: #fff; border-radius: 50%; animation: spin .6s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }
  #feedback-msg { margin-top: 1rem; font-size: .9rem; display: none; }
</style>
</head>
<body>
<div class="card">
  <div class="card-header">
    <h1>Aprobación de Gira Comercial</h1>
    <p>Portal de Rendiciones · <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'Holding', ENT_QUOTES, 'UTF-8') ?></p>
  </div>
  <div class="card-body">
<?php if ($pageError): ?>
    <div class="alert alert-error"><?= htmlspecialchars($pageError, ENT_QUOTES, 'UTF-8') ?></div>

<?php elseif ($resolvedDecision !== '' && !$canResolve): ?>
    <div class="resolved-badge <?= $resolvedDecision === 'APROBADA' ? 'resolved-approved' : 'resolved-rejected' ?>">
      <span><?= $resolvedDecision === 'APROBADA' ? '✓' : '✕' ?></span>
      <span>Esta gira ya fue <strong><?= $resolvedDecision === 'APROBADA' ? 'aprobada' : 'rechazada' ?></strong>.</span>
    </div>

<?php elseif ($canResolve): ?>
    <p style="margin-bottom:1.25rem;font-size:.95rem">Hola <strong><?= $approverName ?></strong><?= $approverTitle ? " ($approverTitle)" : '' ?>,<br>se solicita tu decisión sobre la siguiente gira comercial:</p>

    <table class="info-table">
      <tr><td>Vendedor</td><td><strong><?= $sellerName ?></strong></td></tr>
      <tr><td>Empresa</td><td><?= $company ?></td></tr>
      <tr><td>Gira</td><td><?= $tourName ?> <span class="badge badge-blue">GIRA</span></td></tr>
      <tr><td>Período base</td><td><?= $period ?></td></tr>
      <tr><td>Fechas</td><td><?= $startDate ?> → <?= $endDate ?></td></tr>
      <tr><td>Presupuesto solicitado</td><td><strong style="font-size:1.05rem"><?= $amount ?></strong></td></tr>
    </table>

    <?php if ($justif): ?>
    <div class="justif-box">
      <strong>Justificación del vendedor</strong>
      <?= $justif ?>
    </div>
    <?php endif; ?>

    <div id="reason-row">
      <label class="reason-label" id="reason-label-text" style="display:block">Motivo del rechazo <span style="color:#dc2626">*</span></label>
      <textarea id="rejection-reason" placeholder="Explique brevemente el motivo del rechazo…" maxlength="500"></textarea>
    </div>

    <div class="btn-row">
      <button class="btn btn-approve" id="btn-approve" onclick="resolver('APROBADA')">
        ✓ Aprobar gira
        <span class="spinner" id="sp-approve"></span>
      </button>
      <button class="btn btn-reject" id="btn-reject" onclick="mostrarRazon()">
        ✕ Rechazar
      </button>
    </div>
    <div id="confirm-reject-row" style="display:none;margin-top:.75rem">
      <button class="btn btn-reject" onclick="resolver('RECHAZADA')" style="max-width:220px">
        Confirmar rechazo
        <span class="spinner" id="sp-reject"></span>
      </button>
    </div>

    <p id="feedback-msg"></p>

    <script>
    const TOKEN   = <?= json_encode($token) ?>;
    const CSRF    = <?= json_encode($csrfToken) ?>;
    let pending   = false;
    let showingReason = false;

    function mostrarRazon() {
      document.getElementById('reason-row').style.display = 'block';
      document.getElementById('confirm-reject-row').style.display = 'block';
      document.getElementById('btn-reject').style.display = 'none';
      showingReason = true;
    }

    async function resolver(decision) {
      if (pending) return;
      const reason = document.getElementById('rejection-reason')?.value?.trim() ?? '';
      if (decision === 'RECHAZADA' && reason === '') {
        document.getElementById('rejection-reason').style.borderColor = '#dc2626';
        document.getElementById('rejection-reason').focus();
        return;
      }
      pending = true;
      const spId = decision === 'APROBADA' ? 'sp-approve' : 'sp-reject';
      document.getElementById(spId).style.display = 'inline-block';
      document.getElementById('btn-approve').disabled = true;
      document.querySelectorAll('#confirm-reject-row button').forEach(b => b.disabled = true);

      try {
        const res = await fetch('/api/rendiciones/resolver_gira.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
          body: JSON.stringify({ token: TOKEN, decision, comentario: reason }),
        });
        const data = await res.json();
        const msg  = document.getElementById('feedback-msg');
        msg.style.display = 'block';
        if (data.success) {
          document.querySelector('.btn-row').style.display = 'none';
          document.getElementById('confirm-reject-row').style.display = 'none';
          msg.style.background = decision === 'APROBADA' ? '#f0fdf4' : '#fef2f2';
          msg.style.color      = decision === 'APROBADA' ? '#166534' : '#991b1b';
          msg.style.padding    = '1rem';
          msg.style.borderRadius = '8px';
          msg.textContent = decision === 'APROBADA'
            ? '✓ Gira aprobada correctamente. Tesorería recibirá la notificación.'
            : '✕ Gira rechazada. Tesorería recibirá la notificación.';
        } else {
          msg.style.color = '#dc2626';
          msg.textContent = data.message ?? 'Ocurrió un error. Por favor intente nuevamente.';
          pending = false;
          document.getElementById('btn-approve').disabled = false;
          document.querySelectorAll('#confirm-reject-row button').forEach(b => b.disabled = false);
        }
      } catch (_) {
        const msg = document.getElementById('feedback-msg');
        msg.style.display = 'block';
        msg.style.color = '#dc2626';
        msg.textContent = 'Error de conexión. Por favor intente nuevamente.';
        pending = false;
        document.getElementById('btn-approve').disabled = false;
        document.querySelectorAll('#confirm-reject-row button').forEach(b => b.disabled = false);
      }
      document.getElementById(spId).style.display = 'none';
    }
    </script>
<?php endif; ?>
  </div>
</div>
</body>
</html>
