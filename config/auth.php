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
    if (defined('APP_ENV') && APP_ENV === 'local') {
        // En local, simular usuario 1 con rol ADMINISTRADOR
        return [
            'id' => 1,
            'nombre' => 'Sistema',
            'email' => 'sistema@app.local',
            'rol' => 'ADMINISTRADOR',
            'activo' => 1
        ];
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado. Token no provisto.']);
        exit;
    }
    
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

function getUsuarioActual(): int {
    if (defined('APP_ENV') && APP_ENV === 'local') {
        return defined('AUTH_BYPASS_USER_ID') ? AUTH_BYPASS_USER_ID : 1;
    }
    $pdo = Database::getCobranzasConnection();
    $user = requireAuth($pdo);
    return (int)$user['id'];
}

function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function checkRateLimit(PDO $pdo, string $email): void {
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
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Demasiados intentos fallidos. Por favor, espere 15 minutos.']);
        exit;
    }
}

function registerFailedAttempt(PDO $pdo, string $email): void {
    $ip = getClientIp();
    $stmt = $pdo->prepare("INSERT INTO login_attempts (ip_address, email) VALUES (:ip, :email)");
    $stmt->execute([':ip' => $ip, ':email' => $email]);
}
