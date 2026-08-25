<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();
    requirePermission($pdo, 'users.manage');

    $stmt = $pdo->prepare(
        "SELECT id, nombre, email, rol, activo, created_at
         FROM usuarios
         WHERE rol IN ('ADMINISTRADOR', 'TESORERIA', 'SUPERVISORA_CC')
         ORDER BY activo DESC, nombre ASC"
    );
    $stmt->execute();

    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Throwable $e) {
    error_log('[admin/api/get_usuarios.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No fue posible cargar los usuarios.']);
}
