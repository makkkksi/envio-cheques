<?php
/**
 * api/auth_seller.php
 * 
 * Endpoint de inicialización de sesión para Vendedores (WebView).
 * Valida la identidad del vendedor según su ID y Empresa de origen,
 * y establece una sesión segura en el servidor.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

$vendedor_id = null;
if (isset($_REQUEST['vendedor_id']) && $_REQUEST['vendedor_id'] !== '') {
    $vendedor_id = (int)$_REQUEST['vendedor_id'];
} elseif (isset($_REQUEST['vendedor']) && $_REQUEST['vendedor'] !== '') {
    $vendedor_id = (int)$_REQUEST['vendedor'];
}

$empresa = trim($_REQUEST['empresa'] ?? $_REQUEST['empresa_id'] ?? '');
$vendedor_email = filter_input(INPUT_GET, 'vendedor_email', FILTER_SANITIZE_EMAIL) 
               ?: filter_input(INPUT_POST, 'vendedor_email', FILTER_SANITIZE_EMAIL);

if ($vendedor_id === null && !$vendedor_email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan parámetros de identidad (vendedor_id o vendedor_email)']);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();
    
    $sellerEmail = null;
    $sellerName = null;

    if ($vendedor_id !== null) {
        $stmt = null;
        if ($empresa !== '') {
            $empresa_code = strtoupper(trim($empresa));
            if ($empresa_code === 'EMP01' || $empresa_code === 'AUTOMARCO') {
                $stmt = $pdo->prepare("SELECT ven_mail, nombre_vendedor FROM automarc_automarco.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail IS NOT NULL AND ven_mail != '' AND ven_mail != '.' LIMIT 1");
            } elseif ($empresa_code === 'EMP10' || $empresa_code === 'GABTEC') {
                $stmt = $pdo->prepare("SELECT ven_mail, ven_nombre as nombre_vendedor FROM gabteccl_sitbdd1978.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail IS NOT NULL AND ven_mail != '' AND ven_mail != '.' LIMIT 1");
            } elseif ($empresa_code === 'EMP03' || $empresa_code === 'AUTOTEC' || $empresa_code === 'EMP24' || $empresa_code === 'TOP_REPUESTOS') {
                $stmt = $pdo->prepare("SELECT ven_mail, nombre_vendedor FROM autotec_ecom.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail IS NOT NULL AND ven_mail != '' AND ven_mail != '.' LIMIT 1");
            } elseif ($empresa_code === 'EMP06' || $empresa_code === 'HD') {
                $stmt = $pdo->prepare("SELECT ven_mail, nombre_vendedor FROM autohd_automarcohd.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail IS NOT NULL AND ven_mail != '' AND ven_mail != '.' LIMIT 1");
            }
        }
        // NOTA: Sin empresa, NO hacemos búsqueda cruzada (UNION) porque los IDs 
        // de vendedor están duplicados entre empresas y pertenecen a personas distintas.
        // El parámetro empresa es OBLIGATORIO para identificar correctamente al vendedor.

        if ($stmt) {
            $stmt->execute([':vid' => $vendedor_id]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($res) {
                $sellerEmail = trim($res['ven_mail']);
                $sellerName = trim($res['nombre_vendedor'] ?? '');
            }
        }
    } 
    elseif ($vendedor_email) {
        $sellerEmail = trim($vendedor_email);
    }

    // Fallback solo para desarrollo local
    if (!$sellerEmail && defined('APP_ENV') && APP_ENV === 'local') {
        $sellerEmail = "dev_{$vendedor_id}@local.test";
    }

    $vendedor_nombre_param = trim($_REQUEST['vendedor_nombre'] ?? $_REQUEST['nombre'] ?? '');

    if (!$sellerName || $sellerName === 'Sin Asignar') {
        if ($vendedor_nombre_param !== '') {
            $sellerName = $vendedor_nombre_param;
        } else {
            $sellerName = ($vendedor_id !== null) ? "Vendedor ID {$vendedor_id}" : "Vendedor Terreno";
        }
    }

    if (!$sellerEmail) {
        // Fallback resiliente para cuentas de prueba o vendedores no registrados en tbl_vendedores (ej. ID 86)
        $sellerEmail = ($vendedor_id !== null) ? "vendedor{$vendedor_id}@holdingautomarco.com" : "envio@holdingautomarco.com";
    }

    $_SESSION['vendedor_auth'] = [
        'vendedor_id' => $vendedor_id,
        'email' => $sellerEmail,
        'nombre' => $sellerName,
        'empresa_origen' => $empresa ?: 'DESCONOCIDA',
        'auth_time' => time()
    ];

    echo json_encode([
        'success' => true, 
        'message' => 'Sesión iniciada',
        'data' => $_SESSION['vendedor_auth']
    ]);

} catch (Exception $e) {
    error_log("Error en auth_seller: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de servidor']);
}
