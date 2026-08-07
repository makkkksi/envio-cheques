<?php
/**
 * api/auth/logout_vendedor.php
 * Cierra sesión del portal del Vendedor.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/form/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

$_SESSION = [];
session_destroy();

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
exit;
