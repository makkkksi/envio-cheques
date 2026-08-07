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

// 5. Captura de filtros opcionales
$estado = filter_input(INPUT_GET, 'estado', FILTER_DEFAULT);
$estado = ($estado !== null && $estado !== '' && $estado !== 'TODOS') ? trim($estado) : null;

$empresa_id = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT);
$empresa_id = $empresa_id ?: null;

$busqueda = filter_input(INPUT_GET, 'busqueda', FILTER_DEFAULT);
$busqueda = ($busqueda !== null && trim($busqueda) !== '') ? trim($busqueda) : null;

// 6. Lógica de negocio
try {
    $pdo = Database::getCobranzasConnection();

    // Consulta base
    $sql = "SELECT 
                c.id,
                c.empresa_id,
                e.nombre AS empresa_nombre,
                c.numero_factura,
                c.razon_social_cliente,
                c.rut_cliente,
                c.monto_total_factura,
                c.tipo_entrega,
                c.numero_seguimiento,
                c.comprobante_url,
                c.estado,
                c.created_at,
                c.updated_at
            FROM cobranzas c
            INNER JOIN empresas e ON c.empresa_id = e.id
            WHERE 1=1";

    $params = [];

    // En producción filtra por vendedor autenticado. En local (bypass) si vendedor_id es NULL o se consultan todas.
    if (defined('APP_ENV') && APP_ENV === 'production') {
        $sql .= " AND c.vendedor_id = :vendedor_id";
        $params[':vendedor_id'] = $usuario_id;
    }

    if ($estado !== null) {
        $sql .= " AND c.estado = :estado";
        $params[':estado'] = $estado;
    }

    if ($empresa_id !== null) {
        $sql .= " AND c.empresa_id = :empresa_id";
        $params[':empresa_id'] = $empresa_id;
    }

    if ($busqueda !== null) {
        $sql .= " AND (c.numero_factura LIKE :busqueda OR c.rut_cliente LIKE :busqueda OR c.razon_social_cliente LIKE :busqueda)";
        $params[':busqueda'] = '%' . $busqueda . '%';
    }

    $sql .= " ORDER BY COALESCE(c.updated_at, c.created_at) DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cobranzas = $stmt->fetchAll();

    // Si no hay registros
    if (empty($cobranzas)) {
        echo json_encode([
            'success' => true,
            'data' => [
                'por_enviar' => [],
                'enviados' => []
            ]
        ]);
        exit;
    }

    // Obtener los IDs de cobranzas para traer sus cheques anidados
    $cobranzasIds = array_column($cobranzas, 'id');
    $placeholders = implode(',', array_fill(0, count($cobranzasIds), '?'));

    $stmtCheques = $pdo->prepare("SELECT 
                                    id,
                                    cobranza_id,
                                    banco,
                                    numero_cheque,
                                    monto,
                                    fecha_vencimiento,
                                    foto_cheque_url,
                                    comentario
                                 FROM cheques
                                 WHERE cobranza_id IN ({$placeholders})
                                 ORDER BY id ASC");
    $stmtCheques->execute($cobranzasIds);
    $todosCheques = $stmtCheques->fetchAll();

    // Agrupar cheques por cobranza_id
    $chequesPorCobranza = [];
    foreach ($todosCheques as $chq) {
        $chq['monto'] = (float) $chq['monto'];
        $chequesPorCobranza[$chq['cobranza_id']][] = $chq;
    }

    // Anidar cheques en las cobranzas correspondientes
    foreach ($cobranzas as &$cobranza) {
        $cobranza['id'] = (int) $cobranza['id'];
        $cobranza['empresa_id'] = (int) $cobranza['empresa_id'];
        $cobranza['monto_total_factura'] = $cobranza['monto_total_factura'] !== null ? (float) $cobranza['monto_total_factura'] : null;
        $cobranza['cheques'] = $chequesPorCobranza[$cobranza['id']] ?? [];
    }
    unset($cobranza);

    // Separar en Por Enviar (PENDIENTE_ENVIO) y Enviados
    $porEnviar = [];
    $enviados = [];
    foreach ($cobranzas as $cobranza) {
        if ($cobranza['estado'] === 'PENDIENTE_ENVIO') {
            $porEnviar[] = $cobranza;
        } else {
            $enviados[] = $cobranza;
        }
    }

    echo json_encode([
        'success' => true,
        'data' => [
            'por_enviar' => $porEnviar,
            'enviados' => $enviados
        ]
    ]);

} catch (Exception $e) {
    error_log('[get_mis_cobranzas.php] Error: ' . $e->getMessage());
    http_response_code(500);
    $msg = (defined('APP_ENV') && APP_ENV === 'local') ? $e->getMessage() : 'Error interno del servidor';
    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
}
