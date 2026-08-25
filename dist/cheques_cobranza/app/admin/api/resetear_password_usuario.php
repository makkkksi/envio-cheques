<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();
    $actor = requirePermission($pdo, 'users.manage');
    requireCsrfToken();

    $input = json_decode(file_get_contents('php://input'), true);
    $userId = (int)($input['id'] ?? 0);
    $password = (string)($input['password'] ?? '');

    if ($userId <= 0 || strlen($password) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Seleccione un usuario e ingrese una contraseña de al menos 10 caracteres.']);
        exit;
    }

    $pdo->beginTransaction();
    $stmtUser = $pdo->prepare(
        "SELECT id, email FROM usuarios
         WHERE id = :id AND rol IN ('ADMINISTRADOR', 'TESORERIA', 'SUPERVISORA_CC')
         FOR UPDATE"
    );
    $stmtUser->execute([':id' => $userId]);
    $target = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Usuario administrativo no encontrado.']);
        exit;
    }

    $stmtUpdate = $pdo->prepare(
        'UPDATE usuarios
         SET password_hash = :password_hash, api_token = NULL, token_expires_at = NULL
         WHERE id = :id'
    );
    $stmtUpdate->execute([
        ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
        ':id' => $userId,
    ]);

    AuditService::log(
        $pdo,
        (int)$actor['id'],
        $actor['email'],
        'PASSWORD_ADMIN_RESETEADA',
        "Contraseña restablecida para usuario ID {$userId}."
    );
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => 'Contraseña restablecida correctamente.']);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[admin/api/resetear_password_usuario.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No fue posible restablecer la contraseña.']);
}
