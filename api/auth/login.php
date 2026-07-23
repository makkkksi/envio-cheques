<?php
// 1. Headers obligatorios
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// 2. Imports
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';

// 3. Solo aceptar el método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// 4. Leer el body en JSON o POST form data
$inputRaw = file_get_contents('php://input');
$data = json_decode($inputRaw, true);

if (!is_array($data)) {
    $data = $_POST;
}

$email = trim($data['email'] ?? '');
$password = trim($data['password'] ?? '');

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Los campos email y password son requeridos'
    ]);
    exit;
}

// 5. Lógica de autenticación
try {
    $pdo = Database::getCobranzasConnection();

    $stmt = $pdo->prepare('SELECT id, nombre, email, password_hash, rol, activo FROM usuarios WHERE email = :email');
    $stmt->execute([':email' => $email]);
    $usuario = $stmt->fetch();

    if (!$usuario || !(bool)$usuario['activo']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Credenciales incorrectas'
        ]);
        exit;
    }

    // Verificar password si password_hash está establecido
    if (!empty($usuario['password_hash'])) {
        if (!password_verify($password, $usuario['password_hash'])) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Credenciales incorrectas'
            ]);
            exit;
        }
    }

    // Generar token Bearer de 64 caracteres hexadecimales de forma criptográficamente segura
    $token = bin2hex(random_bytes(32));

    // Persistir el token en la base de datos
    $stmtUpdate = $pdo->prepare('UPDATE usuarios SET api_token = :token WHERE id = :id');
    $stmtUpdate->execute([':token' => $token, ':id' => $usuario['id']]);

    echo json_encode([
        'success' => true,
        'token' => $token,
        'usuario' => [
            'id' => (int) $usuario['id'],
            'nombre' => $usuario['nombre'],
            'rol' => $usuario['rol']
        ]
    ]);

} catch (Exception $e) {
    error_log('[login.php] Error: ' . $e->getMessage());
    http_response_code(500);
    $msg = (defined('APP_ENV') && APP_ENV === 'local') ? $e->getMessage() : 'Error interno del servidor';
    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
}
