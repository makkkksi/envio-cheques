<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once __DIR__ . '/../../config/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido.']);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();
    $seller = requireSellerContext($pdo);
    $origin = (string)($_SESSION['vendedor_auth']['empresa_origen'] ?? '');
    echo json_encode([
        'success' => true,
        'data' => [
            'authenticated' => true,
            'expires_in' => getSellerSessionExpiresIn(),
            'csrf_token' => getCsrfToken(),
            'seller' => [
                'vendedor_id' => (int)$seller['vendedor_id'],
                'empresa_id' => (int)$seller['empresa_id'],
                'empresa_origen' => $origin,
                'nombre' => (string)$seller['nombre'],
                'email' => (string)$seller['email'],
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('[seller.auth.session_status] ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No fue posible verificar la sesión.']);
}
