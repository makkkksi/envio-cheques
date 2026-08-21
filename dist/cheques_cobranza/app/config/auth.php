<?php
/**
 * auth.php — Middleware de Autenticación
 * 
 * Obtiene y valida el usuario actual del request.
 * Soporta dos modos:
 * - Local (`APP_ENV = 'local'`): Modo bypass que retorna AUTH_BYPASS_USER_ID.
 * - Producción (`APP_ENV = 'production'`): Valida el token Bearer en el header Authorization.
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';

function requireAuth(PDO $pdo, array $allowedRoles = []): array {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (defined('APP_ENV') && APP_ENV === 'local') {
        $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1']);
        // En local, simular usuario 1 solo si no hay token explícito ni sesión de vendedor y la IP es localhost
        if (empty($authHeader) && empty($_SESSION['vendedor_auth']) && $isLocalhost) {
            error_log("[SECURITY WARNING] Developer auth bypass triggered in config/auth.php. Local environment mock active.");
            return [
                'id' => 1,
                'nombre' => 'Sistema',
                'email' => 'sistema@app.local',
                'rol' => 'ADMINISTRADOR',
                'activo' => 1
            ];
        }
    }
    
    // 1. Intentar auth por Bearer Token (Admin / Tesorería)
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $rawToken = $matches[1];
        $hashedToken = hash('sha256', $rawToken);
        
        $stmt = $pdo->prepare("
            SELECT id, nombre, email, rol, activo 
            FROM usuarios 
            WHERE api_token = :token 
              AND (token_expires_at > NOW() OR token_expires_at IS NULL)
            LIMIT 1
        ");
        $stmt->execute([':token' => $hashedToken]);
        $user = $stmt->fetch();
        
        if (!$user || !$user['activo']) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesión expirada o token inválido.']);
            exit;
        }
        
        if (!empty($allowedRoles) && !in_array($user['rol'], $allowedRoles)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado. Privilegios insuficientes.']);
            exit;
        }
        
        return $user;
    }

    // 1.5. Intentar auth por Sesión de Administrador/Tesorería (Portal Web)
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        $user = [
            'id' => $_SESSION['admin_user_id'] ?? 1,
            'nombre' => $_SESSION['admin_user_nombre'] ?? 'Admin',
            'email' => '',
            'rol' => $_SESSION['admin_user_rol'] ?? 'ADMINISTRADOR',
            'activo' => 1
        ];

        if (!empty($allowedRoles) && !in_array($user['rol'], $allowedRoles)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado. Los administradores/tesorería no tienen el rol requerido.']);
            exit;
        }

        return $user;
    }

    // 2. Intentar auth por Sesión de Vendedor (WebView)
    if (isset($_SESSION['vendedor_auth']) && !empty($_SESSION['vendedor_auth']['vendedor_id'])) {
        $user = [
            'id' => $_SESSION['vendedor_auth']['vendedor_id'],
            'nombre' => $_SESSION['vendedor_auth']['nombre'],
            'email' => $_SESSION['vendedor_auth']['email'],
            'rol' => 'VENDEDOR',
            'activo' => 1
        ];

        if (!empty($allowedRoles) && !in_array($user['rol'], $allowedRoles)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado. Los vendedores no tienen acceso a esta función.']);
            exit;
        }

        return $user;
    }
    
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado. Token no provisto ni sesión iniciada.']);
    exit;
}

function getUsuarioActual(): int {
    $pdo = Database::getCobranzasConnection();
    $user = requireAuth($pdo);
    return (int)$user['id'];
}

function requireRole(array $roles): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión no iniciada. Acceso denegado.']);
        exit;
    }
    $rol = $_SESSION['admin_user_rol'] ?? '';
    if (!in_array($rol, $roles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado. Rol no autorizado.']);
        exit;
    }
}


function getClientIp(): string {
    // Lista de IPs de proxies de confianza (ej. IPs locales de Docker, Load Balancers, Cloudflare IPs)
    $trustedProxies = ['127.0.0.1', '::1']; 
    
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (in_array($remoteAddr, $trustedProxies, true) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $realIp = trim(array_shift($forwarded));
        if (filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }
    }
    
    return $remoteAddr;
}

function checkRateLimit(PDO $pdo, string $email): void {
    if (empty($email)) return;
    $ip = getClientIp();
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM login_attempts 
        WHERE (ip_address = :ip OR email = :email) 
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([':ip' => $ip, ':email' => $email]);
    $attempts = (int)$stmt->fetchColumn();
    
    if ($attempts >= 5) {
        throw new Exception('Demasiados intentos fallidos. Por favor, espere 15 minutos.');
    }
}

function registerFailedAttempt(PDO $pdo, string $email): void {
    if (empty($email)) return;
    $ip = getClientIp();
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email) VALUES (:ip, :email)");
    $stmt->execute([':ip' => $ip, ':email' => $email]);
}

function clearFailedAttempts(PDO $pdo, string $email): void {
    $ip = getClientIp();
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = :ip OR email = :email");
    $stmt->execute([':ip' => $ip, ':email' => $email]);
}
