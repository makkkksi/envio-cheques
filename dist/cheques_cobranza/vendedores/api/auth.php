<?php
require_once __DIR__ . '/config.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'login') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $usuario  = trim($body['usuario'] ?? '');
    $password = trim($body['password'] ?? '');

    if (!$usuario || !$password) jsonResponse(false, null, 'Complete usuario y contraseña');

    $pdo  = getDB();
    // Buscar usuario — soporta múltiples registros con mismo usuario (ej: autotec)
    // Primero intentar con hash bcrypt, si no coincide comparar texto plano
    $stmt = $pdo->prepare("SELECT * FROM web_usuarios WHERE usuario = :usuario AND activo = 1");
    $stmt->execute([':usuario' => $usuario]);
    $users = $stmt->fetchAll();

    $user = null;
    foreach ($users as $u) {
        // Comparar: hash bcrypt o texto plano (para migración)
        if (password_verify($password, $u['password']) || $password === $u['password']) {
            $user = $u;
            break;
        }
    }

    if (!$user) {
        jsonResponse(false, null, 'Usuario o contraseña incorrectos');
    }

    $token  = bin2hex(random_bytes(32));
    $expira = date('Y-m-d H:i:s', time() + SESSION_TTL);
    $ip     = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua     = $_SERVER['HTTP_USER_AGENT'] ?? '';

    $pdo->prepare("INSERT INTO web_sesiones (usuario_id, token, ip, user_agent, expira_en) VALUES (:usuario_id, :token, :ip, :user_agent, :expira_en)")
        ->execute([
            ':usuario_id' => $user['id'],
            ':token' => $token,
            ':ip' => $ip,
            ':user_agent' => $ua,
            ':expira_en' => $expira,
        ]);

    $pdo->prepare("UPDATE web_usuarios SET ultimo_login = NOW() WHERE id = :id")
        ->execute([':id' => $user['id']]);

    setcookie('at_token', $token, [
        'expires' => time() + SESSION_TTL,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    jsonResponse(true, [
        'token'    => $token,
        'nombre'   => $user['nombre'],
        'rol'      => $user['rol'],
        'usuario'  => $user['usuario'],
        'vend_cod' => $user['vend_cod'],   // ← código vendedor
    ], 'Bienvenido ' . $user['nombre']);
}

if ($method === 'POST' && $action === 'logout') {
    $token = $_COOKIE['at_token'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? '');
    if ($token) {
        $pdo = getDB();
        $pdo->prepare("DELETE FROM web_sesiones WHERE token = :token")->execute([':token' => $token]);
    }
    setcookie('at_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    jsonResponse(true, null, 'Sesión cerrada');
}

if ($method === 'GET' && $action === 'check') {
    $token = $_COOKIE['at_token'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? '');
    if (!$token) jsonResponse(false, null, 'No autenticado');

    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT u.nombre, u.rol, u.usuario, u.cli_rut, u.cli_sec, u.vend_cod, s.expira_en
        FROM web_sesiones s 
        JOIN web_usuarios u ON u.id = s.usuario_id
        WHERE s.token = :token AND s.expira_en > NOW() AND u.activo = 1
    ");
    $stmt->execute([':token' => $token]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(false, null, 'Sesión expirada');
    refreshPortalSession($pdo, $token, (string) $user['expira_en']);
    unset($user['expira_en']);
    jsonResponse(true, $user);
}

jsonResponse(false, null, 'Acción no válida');
