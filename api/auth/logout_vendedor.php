<?php
/**
 * api/auth/logout_vendedor.php
 * Cierra sesión del portal del Vendedor.
 */
require_once __DIR__ . '/../../config/auth.php';

startSellerSession();
destroyCurrentSession(SESSION_CONTEXT_SELLER);

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['success' => true]);
exit;
