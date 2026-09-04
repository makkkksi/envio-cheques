<?php

declare(strict_types=1);

/**
 * api/seller_handoff.php
 *
 * Endpoint dedicado para la transición (handoff) segura desde los portales comerciales
 * hacia los módulos de Cheques y Rendiciones.
 *
 * Recibe exclusivamente por POST:
 * - session_token: token de la sesión activa en el portal comercial (web_sesiones)
 * - empresa: código de la empresa seleccionada o activa
 * - destino: 'cheques' o 'rendiciones' (lista cerrada)
 *
 * Tras validar en modo solo lectura contra web_sesiones JOIN web_usuarios de la BD ERP:
 * - Regenera el ID de sesión PHP.
 * - Emite la cookie segura del módulo de cobranzas.
 * - Redirige mediante HTTP 303 a la URL fija y limpia, sin tokens ni datos en query string.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/AuditService.php';
require_once __DIR__ . '/../services/SellerHandoffService.php';

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Método no permitido. Se requiere POST.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Validación de Origen / Referer como defensa complementaria
$allowedOrigins = (defined('APP_ENV') && APP_ENV === 'local')
    ? [
        'http://localhost',
        'http://127.0.0.1',
        'http://localhost:80',
        'http://form.test',
        'http://automarco.test',
        'http://autotec.test',
        'http://gabtec.test',
    ]
    : [
        'https://www.automarco.cl',
        'https://automarco.cl',
        'https://www.autotec.cl',
        'https://autotec.cl',
        'https://www.gabtec.cl',
        'https://gabtec.cl',
    ];

$originHeader = $_SERVER['HTTP_ORIGIN'] ?? null;
if ($originHeader !== null && $originHeader !== '') {
    $originTrimmed = rtrim(strtolower($originHeader), '/');
    $normalizedAllowed = array_map(static fn(string $o): string => rtrim(strtolower($o), '/'), $allowedOrigins);
    if (!in_array($originTrimmed, $normalizedAllowed, true)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Origen no autorizado.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 2. Parámetros del request
$sessionToken = trim((string)($_POST['session_token'] ?? ''));
$empresaInput = trim((string)($_POST['empresa'] ?? ''));
$destino = strtolower(trim((string)($_POST['destino'] ?? '')));

// 3. Destino en lista cerrada
$allowedDestinations = [
    'cheques' => PORTAL_BASE_URL . '/index.html',
    'rendiciones' => PORTAL_BASE_URL . '/rendiciones/vendedor.php',
];

if (!isset($allowedDestinations[$destino])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Destino no válido. Se permite exclusivamente: cheques o rendiciones.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$targetUrl = $allowedDestinations[$destino];

// 4. Validación de formato de token
if ($sessionToken === '' || strlen($sessionToken) < 16 || strlen($sessionToken) > 128 || !preg_match('/^[a-zA-Z0-9_-]+$/', $sessionToken)) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Token de sesión comercial no provisto o con formato inválido.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $centralPdo = Database::getCobranzasConnection();

    // 5. Resolución de empresa contra catálogo central
    try {
        $empresa = SellerHandoffService::resolveEmpresa($centralPdo, $empresaInput);
    } catch (InvalidArgumentException $e) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 6. Verificación de sesión contra la base ERP de solo lectura
    $verified = SellerHandoffService::verifySessionToken($empresa['nombre_bd'], $sessionToken);
    if ($verified === null) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'message' => 'Sesión comercial no válida o expirada.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 7. Establecer sesión PHP de vendedor
    startSellerSession();
    session_regenerate_id(true);

    $csrfToken = bin2hex(random_bytes(32));
    $authenticatedAt = time();

    $_SESSION['vendedor_auth'] = [
        'vendedor_id' => $verified['vend_cod'],
        'empresa_id' => $empresa['id'],
        'email' => $verified['email'], // null si no tiene (ej. Autotec)
        'nombre' => $verified['nombre'],
        'empresa_origen' => $empresaInput,
        'auth_time' => $authenticatedAt,
        'last_activity' => $authenticatedAt,
        'csrf_token' => $csrfToken,
    ];
    $_SESSION['csrf_token'] = $csrfToken;

    refreshSessionCookie(SESSION_CONTEXT_SELLER, true);

    // 8. Registro de auditoría seguro (sin tokens, sin hashes reutilizables)
    try {
        AuditService::log(
            $centralPdo,
            1,
            'sistema@app.local',
            'SELLER_HANDOFF',
            json_encode([
                'empresa_id' => $empresa['id'],
                'vendedor_id' => $verified['vend_cod'],
                'destino' => $destino,
                'resultado' => 'SUCCESS',
            ], JSON_UNESCAPED_UNICODE)
        );
    } catch (Throwable $auditError) {
        error_log('[seller_handoff] Advertencia de auditoría: ' . $auditError->getMessage());
    }

    // 9. Redirección HTTP 303 (See Other) a URL limpia
    header('Location: ' . $targetUrl, true, 303);
    exit;

} catch (Throwable $e) {
    error_log('[seller_handoff] Error no controlado: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
