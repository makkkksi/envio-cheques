<?php
/**
 * index.php (Raíz)
 * Redirección inteligente al portal correspondiente
 */

// Si tiene sesión administrativa activa o se consulta la raíz, derivar al portal admin
require_once __DIR__ . '/config/auth.php';
startAdminSession();
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin/index.php');
    exit;
}

// Por defecto, redirigir a la administración
header('Location: admin/login.php');
exit;
