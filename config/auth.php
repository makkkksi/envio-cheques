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

/**
 * Obtiene el ID del usuario actualmente autenticado.
 * 
 * @return int ID del usuario en la tabla `usuarios`.
 */
function getUsuarioActual(): int
{
    if (defined('APP_ENV') && APP_ENV === 'local') {
        // Modo bypass — desarrollo sin autenticación
        return defined('AUTH_BYPASS_USER_ID') ? AUTH_BYPASS_USER_ID : 1;
    }

    // Modo producción — valida token Bearer
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    
    // Normalizar nombres de encabezados para compatibilidad
    $authHeader = '';
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'authorization') {
            $authHeader = $value;
            break;
        }
    }

    if (empty($authHeader) && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'];
    }

    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticación requerida']);
        exit;
    }

    $token = substr($authHeader, 7);
    if (empty($token)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Token de autenticación no proporcionado']);
        exit;
    }

    try {
        $pdo = Database::getCobranzasConnection();
        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE api_token = :token AND activo = 1');
        $stmt->execute([':token' => $token]);
        $usuario = $stmt->fetch();

        if (!$usuario) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Token inválido o expirado']);
            exit;
        }

        return (int) $usuario['id'];
    } catch (Exception $e) {
        error_log('[auth.php] Error al validar token: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
        exit;
    }
}
