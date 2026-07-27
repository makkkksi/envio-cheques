<?php
/**
 * admin/login.php
 * 
 * Portal de Tesorería — Login Seguro con Protecciones Completas (SECURITY.md)
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// Configuración de sesión segura
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/form/admin/',
        'domain' => '',
        'secure' => false,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

// Redireccionar si ya está logueado
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
$email = '';

// Generar CSRF Token si no existe
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Procesar el POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    // 1. Validar CSRF
    if (empty($csrfToken) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
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
                if ($user && (bool)$user['activo'] && in_array($user['rol'], ['TESORERIA', 'ADMINISTRADOR'])) {
                    if (password_verify($password, $user['password_hash'])) {
                        // Regenerar ID de sesión para prevenir Session Fixation
                        session_regenerate_id(true);

                        // Establecer variables de sesión
                        $_SESSION['admin_logged_in'] = true;
                        $_SESSION['admin_user_id'] = $user['id'];
                        $_SESSION['admin_user_nombre'] = $user['nombre'];
                        $_SESSION['admin_user_rol'] = $user['rol'];

                        // Eliminar intentos de login fallidos previos si la IP ya es exitosa (opcional)
                        header('Location: index.php');
                        exit;
                    }
                }

                // Si falló el login
                registerFailedAttempt($pdo, $email);
                $error = 'Credenciales inválidas o cuenta no autorizada';
            }
        } catch (Exception $e) {
            error_log('Error en Login Admin: ' . $e->getMessage());
            $error = 'Ocurrió un error inesperado al procesar la solicitud.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Seguro — Portal de Tesorería</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --color-bg: #0f172a;
            --color-surface: #1e293b;
            --color-primary: #3b82f6;
            --color-primary-hover: #2563eb;
            --color-text: #f8fafc;
            --color-text-muted: #94a3b8;
            --color-border: #334155;
            --color-danger: #ef4444;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--color-bg);
            color: var(--color-text);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }

        .login-card {
            width: 100%;
            max-width: 400px;
            background: rgba(30, 41, 59, 0.7);
            backdrop-filter: blur(12px);
            border: 1px solid var(--color-border);
            border-radius: 16px;
            padding: 32px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            animation: fadeIn 0.4s ease-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .header {
            text-align: center;
            margin-bottom: 24px;
        }

        .header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--color-text);
            margin-bottom: 6px;
        }

        .header p {
            font-size: 0.85rem;
            color: var(--color-text-muted);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--color-text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .form-input {
            width: 100%;
            height: 44px;
            background: #0f172a;
            border: 1px solid var(--color-border);
            border-radius: 8px;
            padding: 0 14px;
            color: var(--color-text);
            font-size: 0.9rem;
            font-family: inherit;
            outline: none;
            transition: border-color 0.2s;
        }

        .form-input:focus {
            border-color: var(--color-primary);
        }

        .btn-submit {
            width: 100%;
            height: 44px;
            background: var(--color-primary);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s;
            margin-top: 8px;
        }

        .btn-submit:hover {
            background: var(--color-primary-hover);
        }

        .alert {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid var(--color-danger);
            color: #fca5a5;
            padding: 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            line-height: 1.4;
        }
    </style>
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
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

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
