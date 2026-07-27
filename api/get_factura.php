<?php
// 1. Headers obligatorios
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// 2. Imports
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// 3. Solo aceptar el método GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// 4. Autenticación (middleware)
$usuario_id = getUsuarioActual();

// 5. Captura y validación de entrada
$empresa_id = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT);
$numero_factura = filter_input(INPUT_GET, 'numero_factura', FILTER_DEFAULT);
$numero_factura = $numero_factura !== null ? trim($numero_factura) : '';

if (!$empresa_id || empty($numero_factura)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Parámetros requeridos: empresa_id, numero_factura'
    ]);
    exit;
}

// Validar que numero_factura contenga solo dígitos y tenga al menos 4 caracteres
if (!preg_match('/^\d{4,}$/', $numero_factura)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'El número de factura debe contener al menos 4 dígitos'
    ]);
    exit;
}

// 6. Lógica de negocio
try {
    $pdoCentral = Database::getCobranzasConnection();

    // Obtener información de la empresa seleccionada
    $stmtEmpresa = $pdoCentral->prepare('SELECT nombre, nombre_bd FROM empresas WHERE id = :id');
    $stmtEmpresa->execute([':id' => $empresa_id]);
    $empresa = $stmtEmpresa->fetch();

    if (!$empresa) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'message' => 'Empresa no encontrada'
        ]);
        exit;
    }

    $nombre_bd = $empresa['nombre_bd'];
    // F-04: Escapar con backticks para blindaje sintáctico en MySQL (además de la whitelist)
    $nombre_bd_escaped = '`' . str_replace('`', '', $nombre_bd) . '`';

    // Conectar a la BD del ERP previa validación contra whitelist en Database::getErpConnection
    $pdoErp = Database::getErpConnection($nombre_bd);

    $sql = "SELECT 
                v.factura,
                v.cliente_rut AS rut_cliente,
                c.cli_razon_social AS razon_social,
                c.cli_mail AS email_cliente,
                ROUND(SUM(v.neto_item * 1.19)) AS monto_total_factura
            FROM {$nombre_bd_escaped}.tbl_ventas_devoluciones v
            LEFT JOIN {$nombre_bd_escaped}.tbl_clientes c 
                ON REPLACE(v.cliente_rut, '-', '') = REPLACE(c.cli_rut, '-', '')
            WHERE v.factura = :numero_factura
            GROUP BY 
                v.factura,
                v.cliente_rut,
                c.cli_razon_social,
                c.cli_mail";

    $stmtFactura = $pdoErp->prepare($sql);
    $stmtFactura->execute([':numero_factura' => $numero_factura]);
    $facturaData = $stmtFactura->fetch();

    if (!$facturaData) {
        http_response_code(200);
        echo json_encode([
            'success' => false,
            'message' => "Factura no encontrada en el ERP de {$empresa['nombre']}"
        ]);
        exit;
    }

    // Cast numérico explícito para monto_total_factura
    $facturaData['monto_total_factura'] = (float) $facturaData['monto_total_factura'];

    echo json_encode([
        'success' => true,
        'data' => $facturaData
    ]);

} catch (InvalidArgumentException $e) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Base de datos no autorizada'
    ]);
} catch (Exception $e) {
    error_log('[get_factura.php] Error: ' . $e->getMessage());
    http_response_code(500);
    $msg = (defined('APP_ENV') && APP_ENV === 'local') ? $e->getMessage() : 'Error interno del servidor';
    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
}
