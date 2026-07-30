<?php
/**
 * admin/api/auth/logout.php
 * Cierra sesión del portal Admin (Tesorería / Cuentas Corrientes).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/form/admin/',
        'domain'   => '',
        'secure'   => false,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

$_SESSION = [];
session_destroy();

header('Location: /form/admin/login.php');
exit;
