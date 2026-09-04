<?php

declare(strict_types=1);

/**
 * api/auth_seller.php
 * 
 * Endpoint de autenticación de vendedores.
 * En producción (APP_ENV === 'production'), el acceso por URL query string está estrictamente bloqueado.
 * En desarrollo local (APP_ENV === 'local'), se permite únicamente para pruebas unitarias y desarrollo.
 * La fuente de identidad consulta web_usuarios para Automarco, Autotec y Gabtec,
 * y tbl_vendedores para Automarco HD mediante ErpSellerDirectoryService.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../services/ErpSellerDirectoryService.php';
require_once __DIR__ . '/../services/SellerHandoffService.php';

// Bloqueo estricto de parámetros de identidad en URL para producción
if (isset($_GET['vendedor_id']) || isset($_GET['vendedor']) || isset($_GET['vendedor_nombre']) || isset($_GET['empresa'])) {
    if (defined('APP_ENV') && APP_ENV === 'production') {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'Autenticación por URL no permitida en entorno de producción.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    error_log('[SECURITY WARNING] Acceso de prueba por URL permitido exclusivamente en entorno local.');
}

// Control estricto de CORS con allowlist por entorno
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

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '') {
    $originTrimmed = rtrim(strtolower($origin), '/');
    $normalizedAllowed = array_map(static fn(string $o): string => rtrim(strtolower($o), '/'), $allowedOrigins);
    if (in_array($originTrimmed, $normalizedAllowed, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

startSellerSession();

$vendedor_id = null;
if (isset($_REQUEST['vendedor_id']) && $_REQUEST['vendedor_id'] !== '') {
    $vendedor_id = (int)$_REQUEST['vendedor_id'];
} elseif (isset($_REQUEST['vendedor']) && $_REQUEST['vendedor'] !== '') {
    $vendedor_id = (int)$_REQUEST['vendedor'];
}

$empresa = trim((string)($_REQUEST['empresa'] ?? $_REQUEST['empresa_id'] ?? ''));

if ($vendedor_id === null || $vendedor_id <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Faltan parámetros de identidad (vendedor_id).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();

    // Resolver empresa contra catálogo central
    $empresaInfo = null;
    if ($empresa !== '') {
        try {
            $empresaInfo = SellerHandoffService::resolveEmpresa($pdo, $empresa);
        } catch (InvalidArgumentException $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'Empresa no autorizada o no encontrada.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    if (!$empresaInfo) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'La empresa de origen es obligatoria.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Consultar el vendedor oficial en el ERP usando el servicio desacoplado
    $seller = ErpSellerDirectoryService::findByCompanyAndId($pdo, $empresaInfo['id'], $vendedor_id);
    if (!$seller) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'El vendedor no existe o no está activo en la empresa especificada.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $authenticatedAt = time();

    $_SESSION['vendedor_auth'] = [
        'vendedor_id' => $seller['vendedor_id'],
        'empresa_id' => $seller['empresa_id'],
        'email' => $seller['vendedor_email'], // Puede ser null
        'nombre' => $seller['vendedor_nombre'],
        'empresa_origen' => $empresa,
        'auth_time' => $authenticatedAt,
        'last_activity' => $authenticatedAt,
        'csrf_token' => $_SESSION['csrf_token'],
    ];
    refreshSessionCookie(SESSION_CONTEXT_SELLER, true);

    echo json_encode([
        'success' => true,
        'message' => 'Sesión iniciada',
        'data' => $_SESSION['vendedor_auth'],
    ], JSON_UNESCAPED_UNICODE);

} catch (DomainException $e) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[auth_seller] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error interno del servidor.',
    ], JSON_UNESCAPED_UNICODE);
}
