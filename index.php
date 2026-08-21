<?php
/**
 * index.php (Raíz)
 * Redirección inteligente al portal correspondiente
 */

// Si tiene sesión administrativa activa o se consulta la raíz, derivar al portal admin
session_start();
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: admin/index.php');
    exit;
}

// Por defecto, redirigir a la administración
header('Location: admin/login.php');
exit;
