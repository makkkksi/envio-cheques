<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../../config/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();
    $user = requireAuth($pdo, ADMIN_ROLES);
    echo json_encode([
        'success' => true,
        'data' => [
            'authenticated' => true,
            'expires_in' => getAdminSessionExpiresIn(),
            'user' => [
                'id' => (int)$user['id'],
                'nombre' => (string)$user['nombre'],
                'rol' => (string)$user['rol'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('[admin.auth.session_status] ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No fue posible verificar la sesión.']);
}
