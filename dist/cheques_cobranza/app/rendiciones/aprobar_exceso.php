<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

header('Cache-Control: no-store, private');
header('Pragma: no-cache');
startSecureSession();
$token = strtolower(trim((string)($_GET['token'] ?? '')));
$decision = strtoupper(trim((string)($_GET['decision'] ?? '')));
$validRequest = preg_match('/^[a-f0-9]{64}$/', $token) === 1
    && in_array($decision, ['APROBADO', 'RECHAZADO'], true);
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Resolver exceso de rendición</title>
    <link rel="stylesheet" href="aprobar_exceso.css">
</head>
<body>
    <main class="approval-card" data-token="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>" data-decision="<?php echo htmlspecialchars($decision, ENT_QUOTES, 'UTF-8'); ?>">
        <span class="approval-card__brand">Grupo Automarco</span>
        <?php if ($validRequest): ?>
            <h1><?php echo $decision === 'APROBADO' ? 'Confirmar aprobación' : 'Confirmar rechazo'; ?></h1>
            <p>Esta acción utilizará el enlace de un solo uso y quedará registrada en la auditoría.</p>
            <label for="comentario">Comentario opcional</label>
            <textarea id="comentario" maxlength="500" rows="4"></textarea>
            <button type="button" id="btnResolver" class="approval-card__button approval-card__button--<?php echo strtolower($decision); ?>">
                <?php echo $decision === 'APROBADO' ? 'Aprobar exceso' : 'Rechazar exceso'; ?>
            </button>
            <p id="resultado" class="approval-card__result" aria-live="polite"></p>
        <?php else: ?>
            <h1>Enlace no válido</h1>
            <p>Revise que el enlace recibido por correo esté completo.</p>
        <?php endif; ?>
    </main>
    <?php if ($validRequest): ?><script src="aprobar_exceso.js"></script><?php endif; ?>
</body>
</html>
