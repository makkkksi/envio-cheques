<?php
/** Cierra la sesión administrativa mediante POST protegido por CSRF. */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

startAdminSession();
requireCsrfToken();
destroyCurrentSession(SESSION_CONTEXT_ADMIN);

echo json_encode(['success' => true, 'message' => 'Sesión cerrada correctamente.']);
