<?php
/**
 * admin/login.php
 * 
 * Portal de Tesorería — Login Seguro con Protecciones Completas (SECURITY.md)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/AuditService.php';

startSecureSession();

// Redireccionar si ya está logueado
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

// Generar CSRF Token si no existe
$csrfToken = getCsrfToken();

// Procesar el POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $submittedCsrfToken = $_POST['csrf_token'] ?? '';

    // 1. Validar CSRF
    if ($submittedCsrfToken === '' || !hash_equals($_SESSION['csrf_token'], $submittedCsrfToken)) {
        http_response_code(403);
        $error = 'Solicitud no válida (Falta o discrepancia en token de seguridad)';
    } else {
        try {
            $pdo = Database::getCobranzasConnection();

            // 2. Control de Fuerza Bruta / Rate Limiting (IP y Email)
            // checkRateLimit detiene el script con HTTP 429 si hay 5 o más intentos fallidos en los últimos 15 min.
            checkRateLimit($pdo, $email);

            if (empty($email) || empty($password)) {
                $error = 'Por favor, complete todos los campos';
            } else {
                // 3. Buscar usuario con rol autorizado
                $stmt = $pdo->prepare('SELECT id, nombre, email, password_hash, rol, activo FROM usuarios WHERE email = :email LIMIT 1');
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch();

                // 4. Validar contraseña y rol
                if ($user && (bool)$user['activo'] && in_array($user['rol'], ['TESORERIA', 'ADMINISTRADOR', 'SUPERVISORA_CC'])) {
                    if (password_verify($password, $user['password_hash'])) {
                        // Limpiar intentos fallidos previos si el login es exitoso
                        clearFailedAttempts($pdo, $email);

                        // Regenerar ID de sesión para prevenir Session Fixation
                        session_regenerate_id(true);

                        // Establecer variables de sesión
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_user_id'] = $user['id'];
                        $_SESSION['admin_user_nombre'] = $user['nombre'];
                        $_SESSION['admin_user_email'] = $user['email'];
                        $_SESSION['admin_user_rol'] = $user['rol'];
                        $_SESSION['admin_last_activity'] = time();
                        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                        AuditService::log(
                            $pdo,
                            (int)$user['id'],
                            $user['email'],
                            'LOGIN_ADMIN_EXITOSO',
                            'Inicio de sesión administrativa.'
                        );

                        if ($user['rol'] === 'SUPERVISORA_CC') {
                            header('Location: cuentas_corrientes.php');
                        } else {
                            header('Location: index.php');
                        }
                        exit;
                    }
                }

                // Si falló el login
                registerFailedAttempt($pdo, $email);
                $error = 'Credenciales inválidas o cuenta no autorizada';
            }
        } catch (Exception $e) {
            error_log('Error en Login Admin: ' . $e->getMessage());
            if (strpos($e->getMessage(), 'Demasiados intentos fallidos') !== false) {
                $error = $e->getMessage();
            } else {
                $error = 'Ocurrió un error inesperado al procesar la solicitud.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/login.css">
</head>
<body>

    <div class="login-card">
        <div style="text-align: center; margin-bottom: 20px;">
            <img src="../LOGO-HOLDING-AUTOMARCO.png" alt="Automarco Logo" style="max-width: 220px; height: auto;">
        </div>
        <div class="header">
            <h1>Acceso de Tesorería</h1>
            <p>Inspección y Gestión de Cobranzas</p>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

            <div class="form-group">
                <label for="email">Correo Electrónico</label>
                <input type="email" id="email" name="email" class="form-input" required autocomplete="email" value="<?php echo htmlspecialchars($email); ?>">
            </div>

            <div class="form-group">
                <label for="password">Contraseña</label>
                <input type="password" id="password" name="password" class="form-input" required autocomplete="current-password" placeholder="••••••••">
            </div>

            <button type="submit" class="btn-submit">Iniciar Sesión</button>
        </form>
    </div>

</body>
</html>
