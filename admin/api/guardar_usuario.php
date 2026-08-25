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
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Datos inválidos.']);
        exit;
    }

    $userId = (int)($input['id'] ?? 0);
    $nombre = trim((string)($input['nombre'] ?? ''));
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $rol = trim((string)($input['rol'] ?? ''));
    $activo = !empty($input['activo']) ? 1 : 0;
    $password = (string)($input['password'] ?? '');
    $allowedRoles = ['ADMINISTRADOR', 'TESORERIA', 'SUPERVISORA_CC'];

    if ($nombre === '' || mb_strlen($nombre) > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ingrese un nombre válido de hasta 100 caracteres.']);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Ingrese un correo electrónico válido.']);
        exit;
    }
    if (!in_array($rol, $allowedRoles, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El rol seleccionado no es válido.']);
        exit;
    }
    if ($userId === 0 && strlen($password) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'La contraseña inicial debe tener al menos 10 caracteres.']);
        exit;
    }
    if ($userId === (int)$actor['id'] && ($activo === 0 || $rol !== 'ADMINISTRADOR')) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'No puede desactivar su propia cuenta ni retirar su rol administrador.']);
        exit;
    }

    $pdo->beginTransaction();

    $stmtDuplicate = $pdo->prepare(
        'SELECT id FROM usuarios WHERE email = :email AND id <> :id LIMIT 1'
    );
    $stmtDuplicate->execute([':email' => $email, ':id' => $userId]);
    if ($stmtDuplicate->fetch()) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Ya existe un usuario con ese correo electrónico.']);
        exit;
    }

    if ($userId > 0) {
        $stmtCurrent = $pdo->prepare(
            'SELECT id, nombre, email, rol, activo FROM usuarios WHERE id = :id FOR UPDATE'
        );
        $stmtCurrent->execute([':id' => $userId]);
        $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC);
        if (!$current || !in_array($current['rol'], $allowedRoles, true)) {
            $pdo->rollBack();
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Usuario administrativo no encontrado.']);
            exit;
        }

        if ($current['rol'] === 'ADMINISTRADOR' && ($rol !== 'ADMINISTRADOR' || $activo === 0)) {
            $stmtAdmins = $pdo->prepare(
                "SELECT COUNT(*) FROM usuarios
                 WHERE rol = 'ADMINISTRADOR' AND activo = 1 AND id <> :id"
            );
            $stmtAdmins->execute([':id' => $userId]);
            if ((int)$stmtAdmins->fetchColumn() === 0) {
                $pdo->rollBack();
                http_response_code(409);
                echo json_encode(['success' => false, 'message' => 'Debe permanecer al menos un administrador activo.']);
                exit;
            }
        }

        $stmtUpdate = $pdo->prepare(
            'UPDATE usuarios
             SET nombre = :nombre, email = :email, rol = :rol, activo = :activo
             WHERE id = :id'
        );
        $stmtUpdate->execute([
            ':nombre' => $nombre,
            ':email' => $email,
            ':rol' => $rol,
            ':activo' => $activo,
            ':id' => $userId,
        ]);
        $action = 'USUARIO_ADMIN_ACTUALIZADO';
    } else {
        $stmtInsert = $pdo->prepare(
            'INSERT INTO usuarios (nombre, email, password_hash, rol, activo)
             VALUES (:nombre, :email, :password_hash, :rol, :activo)'
        );
        $stmtInsert->execute([
            ':nombre' => $nombre,
            ':email' => $email,
            ':password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ':rol' => $rol,
            ':activo' => $activo,
        ]);
        $userId = (int)$pdo->lastInsertId();
        $action = 'USUARIO_ADMIN_CREADO';
    }

    AuditService::log(
        $pdo,
        (int)$actor['id'],
        $actor['email'],
        $action,
        "Usuario ID {$userId}; rol {$rol}; activo {$activo}."
    );
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => $action === 'USUARIO_ADMIN_CREADO'
            ? 'Usuario creado correctamente.'
            : 'Usuario actualizado correctamente.',
        'data' => ['id' => $userId],
    ]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[admin/api/guardar_usuario.php] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No fue posible guardar el usuario.']);
}
