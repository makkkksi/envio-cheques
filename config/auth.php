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
        session_start();
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (defined('APP_ENV') && APP_ENV === 'local') {
        // En local, simular usuario 1 solo si no hay token explícito ni sesión de vendedor
        if (empty($authHeader) && empty($_SESSION['vendedor_auth'])) {
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
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
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
