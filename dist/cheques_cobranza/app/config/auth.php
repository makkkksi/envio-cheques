<?php
/**
 * auth.php — Autenticación, sesión administrativa y matriz RBAC centralizada.
 */

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';

const ADMIN_ROLES = ['ADMINISTRADOR', 'TESORERIA', 'SUPERVISORA_CC'];
const ADMIN_SESSION_IDLE_SECONDS = 28800;

function startSecureSession(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $httpsEnabled = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443')
        || (defined('APP_ENV') && APP_ENV === 'production');

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', $httpsEnabled ? '1' : '0');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $httpsEnabled,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function adminPermissions(): array
{
    return [
        'ADMINISTRADOR' => [
            'cheques.view', 'cheques.manage',
            'cc.view', 'cc.manage',
            'rendiciones.view', 'rendiciones.manage',
            'users.manage', 'companies.manage',
        ],
        'TESORERIA' => [
            'cheques.view', 'cheques.manage',
            'cc.view',
            'rendiciones.view', 'rendiciones.manage',
        ],
        'SUPERVISORA_CC' => [
            'cheques.view',
            'cc.view', 'cc.manage',
        ],
    ];
}

function userHasPermission(string $role, string $permission): bool
{
    $permissions = adminPermissions();
    return isset($permissions[$role]) && in_array($permission, $permissions[$role], true);
}

function getDefaultAdminPathForRole(string $role): string
{
    return $role === 'SUPERVISORA_CC' ? 'cuentas_corrientes.php' : 'index.php';
}

function clearAdminSession(): void
{
    foreach (array_keys($_SESSION) as $key) {
        if (strpos($key, 'admin_') === 0 || $key === 'csrf_token') {
            unset($_SESSION[$key]);
        }
    }
}

function loadAdminSessionUser(PDO $pdo): ?array
{
    startSecureSession();

    if (($_SESSION['admin_logged_in'] ?? false) !== true) {
        return null;
    }

    $lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);
    if ($lastActivity > 0 && (time() - $lastActivity) > ADMIN_SESSION_IDLE_SECONDS) {
        clearAdminSession();
        return null;
    }

    $userId = (int)($_SESSION['admin_user_id'] ?? 0);
    if ($userId <= 0) {
        clearAdminSession();
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, nombre, email, rol, activo FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user || !(bool)$user['activo'] || !in_array($user['rol'], ADMIN_ROLES, true)) {
        clearAdminSession();
        return null;
    }

    $_SESSION['admin_user_id'] = (int)$user['id'];
    $_SESSION['admin_user_nombre'] = $user['nombre'];
    $_SESSION['admin_user_email'] = $user['email'];
    $_SESSION['admin_user_rol'] = $user['rol'];
    $_SESSION['admin_last_activity'] = time();

    return $user;
}

function requireAuth(PDO $pdo, array $allowedRoles = []): array
{
    startSecureSession();

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization']
        ?? $headers['authorization']
        ?? $_SERVER['HTTP_AUTHORIZATION']
        ?? '';

    if (defined('APP_ENV') && APP_ENV === 'local') {
        $isLocalhost = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
        if (empty($authHeader) && empty($_SESSION['vendedor_auth']) && empty($_SESSION['admin_logged_in']) && $isLocalhost) {
            error_log('[SECURITY WARNING] Developer auth bypass triggered in config/auth.php.');
            $localUser = [
                'id' => 1,
                'nombre' => 'Sistema',
                'email' => 'sistema@app.local',
                'rol' => 'ADMINISTRADOR',
                'activo' => 1,
            ];
            enforceAllowedRoles($localUser, $allowedRoles);
            return $localUser;
        }
    }

    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $stmt = $pdo->prepare(
            'SELECT id, nombre, email, rol, activo
             FROM usuarios
             WHERE api_token = :token
               AND (token_expires_at > NOW() OR token_expires_at IS NULL)
             LIMIT 1'
        );
        $stmt->execute([':token' => hash('sha256', $matches[1])]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !(bool)$user['activo']) {
            jsonAuthError(401, 'Sesión expirada o token inválido.');
        }

        enforceAllowedRoles($user, $allowedRoles);
        return $user;
    }

    $adminUser = loadAdminSessionUser($pdo);
    if ($adminUser) {
        enforceAllowedRoles($adminUser, $allowedRoles);
        return $adminUser;
    }

    if (!empty($_SESSION['vendedor_auth']['vendedor_id'])) {
        $user = [
            'id' => (int)$_SESSION['vendedor_auth']['vendedor_id'],
            'nombre' => $_SESSION['vendedor_auth']['nombre'] ?? 'Vendedor',
            'email' => $_SESSION['vendedor_auth']['email'] ?? '',
            'rol' => 'VENDEDOR',
            'activo' => 1,
        ];
        enforceAllowedRoles($user, $allowedRoles);
        return $user;
    }

    jsonAuthError(401, 'Acceso denegado. Sesión no iniciada.');
}

function enforceAllowedRoles(array $user, array $allowedRoles): void
{
    if (!empty($allowedRoles) && !in_array($user['rol'] ?? '', $allowedRoles, true)) {
        jsonAuthError(403, 'Acceso denegado. Rol no autorizado.');
    }
}

function jsonAuthError(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

function requirePermission(PDO $pdo, string $permission): array
{
    $user = requireAuth($pdo, ADMIN_ROLES);
    if (!userHasPermission($user['rol'], $permission)) {
        jsonAuthError(403, 'Acceso denegado. No posee permiso para esta operación.');
    }
    return $user;
}

/**
 * Obtiene la identidad canónica del vendedor desde la sesión del WebView.
 * Nunca acepta vendedor_id ni empresa_id desde el payload del endpoint.
 */
function requireSellerContext(PDO $pdo): array
{
    startSecureSession();
    $sessionSeller = $_SESSION['vendedor_auth'] ?? null;
    if (!is_array($sessionSeller) || empty($sessionSeller['vendedor_id'])) {
        jsonAuthError(401, 'Sesión de vendedor no iniciada.');
    }

    $sellerId = (int)$sessionSeller['vendedor_id'];
    $empresaId = (int)($sessionSeller['empresa_id'] ?? 0);
    if ($empresaId <= 0) {
        $origin = strtoupper(trim((string)($sessionSeller['empresa_origen'] ?? '')));
        $databaseByOrigin = [
            'EMP01' => 'automarc_automarco',
            'AUTOMARCO' => 'automarc_automarco',
            'EMP03' => 'autotec_ecom',
            'AUTOTEC' => 'autotec_ecom',
            'EMP24' => 'autotec_ecom',
            'TOP_REPUESTOS' => 'autotec_ecom',
            'EMP06' => 'autohd_automarcohd',
            'HD' => 'autohd_automarcohd',
            'EMP10' => 'gabteccl_sitbdd1978',
            'GABTEC' => 'gabteccl_sitbdd1978',
        ];
        $databaseName = $databaseByOrigin[$origin] ?? '';
        if ($databaseName !== '') {
            $stmt = $pdo->prepare('SELECT id FROM empresas WHERE nombre_bd = :nombre_bd LIMIT 1');
            $stmt->execute([':nombre_bd' => $databaseName]);
            $empresaId = (int)$stmt->fetchColumn();
            if ($empresaId > 0) {
                $_SESSION['vendedor_auth']['empresa_id'] = $empresaId;
            }
        }
    }

    if ($sellerId <= 0 || $empresaId <= 0) {
        jsonAuthError(401, 'La sesión no contiene un vendedor y empresa válidos.');
    }

    return [
        'vendedor_id' => $sellerId,
        'empresa_id' => $empresaId,
        'nombre' => trim((string)($sessionSeller['nombre'] ?? 'Vendedor')),
        'email' => trim((string)($sessionSeller['email'] ?? '')),
        'rol' => 'VENDEDOR',
    ];
}

function requireAdminPage(string $permission): array
{
    startSecureSession();
    $pdo = Database::getCobranzasConnection();
    $user = loadAdminSessionUser($pdo);

    if (!$user) {
        header('Location: login.php');
        exit;
    }

    if (!userHasPermission($user['rol'], $permission)) {
        header('Location: ' . getDefaultAdminPathForRole($user['rol']) . '?access=denied');
        exit;
    }

    return $user;
}

function requireRole(array $roles): void
{
    $pdo = Database::getCobranzasConnection();
    requireAuth($pdo, $roles);
}

function getUsuarioActual(): int
{
    $pdo = Database::getCobranzasConnection();
    $user = requireAuth($pdo);
    return (int)$user['id'];
}

function getCsrfToken(): string
{
    startSecureSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function requireCsrfToken(): void
{
    startSecureSession();
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonAuthError(403, 'Solicitud no válida. Token de seguridad incorrecto.');
    }
}

function getClientIp(): string
{
    $trustedProxies = ['127.0.0.1', '::1'];
    $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    if (in_array($remoteAddr, $trustedProxies, true) && isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $forwarded = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        $realIp = trim((string)array_shift($forwarded));
        if (filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }
    }

    return $remoteAddr;
}

function checkRateLimit(PDO $pdo, string $email): void
{
    if ($email === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM login_attempts
         WHERE (ip_address = :ip OR email = :email)
           AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
    );
    $stmt->execute([':ip' => getClientIp(), ':email' => $email]);
    if ((int)$stmt->fetchColumn() >= 5) {
        throw new RuntimeException('Demasiados intentos fallidos. Por favor, espere 15 minutos.');
    }
}

function registerFailedAttempt(PDO $pdo, string $email): void
{
    if ($email === '') {
        return;
    }

    $stmt = $pdo->prepare('INSERT INTO login_attempts (ip_address, email) VALUES (:ip, :email)');
    $stmt->execute([':ip' => getClientIp(), ':email' => $email]);
}

function clearFailedAttempts(PDO $pdo, string $email): void
{
    $stmt = $pdo->prepare(
        'UPDATE login_attempts
         SET attempted_at = :expired_at
         WHERE ip_address = :ip OR email = :email'
    );
    $stmt->execute([
        ':expired_at' => '1970-01-01 00:00:01',
        ':ip' => getClientIp(),
        ':email' => $email,
    ]);
}
