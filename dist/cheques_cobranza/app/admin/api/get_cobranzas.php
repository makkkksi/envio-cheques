<?php
/**
 * admin/api/get_cobranzas.php
 * 
 * Endpoint para Tesorería / Admin que lista las cobranzas de todas las empresas.
 * Permite filtrar por empresa_id, estado y término de búsqueda (Factura, RUT, Cliente).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// Filtros opcionales
$estado = filter_input(INPUT_GET, 'estado', FILTER_DEFAULT);
$estado = ($estado !== null && $estado !== '') ? trim($estado) : 'BANDEJA_TRABAJO';

$empresa_id = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT);
$empresa_id = $empresa_id ?: null;

$busqueda = filter_input(INPUT_GET, 'busqueda', FILTER_DEFAULT);
$busqueda = ($busqueda !== null && trim($busqueda) !== '') ? trim($busqueda) : null;

try {
    $pdo = Database::getCobranzasConnection();

    // Control de acceso: solo roles administrativos pueden listar todas las cobranzas
    requireAuth($pdo, ['ADMINISTRADOR', 'TESORERIA', 'SUPERVISORA_CC']);

    // 1. Obtener métricas globales para las tarjetas superiores
    $stmtMetrics = $pdo->query("SELECT estado, COUNT(*) as cantidad FROM cobranzas GROUP BY estado");
    $metricsRaw = $stmtMetrics->fetchAll(PDO::FETCH_KEY_PAIR);

    $metrics = [
        'bandeja_trabajo'    => (int)($metricsRaw['EN_TRANSITO'] ?? 0) + (int)($metricsRaw['ENTREGADO_SANTIAGO'] ?? 0),
        'pendientes_envio'   => (int)($metricsRaw['PENDIENTE_ENVIO'] ?? 0),
        'en_transito'        => (int)($metricsRaw['EN_TRANSITO'] ?? 0) + (int)($metricsRaw['ENTREGADO_SANTIAGO'] ?? 0),
        'rechazados'         => (int)($metricsRaw['RECHAZADO'] ?? 0),
        'recibidos'          => (int)($metricsRaw['RECIBIDO_TESORERIA'] ?? 0),
        'depositados'        => (int)($metricsRaw['DEPOSITADO'] ?? 0),
        'total'              => array_sum($metricsRaw)
    ];

    // 2. Consulta filtrada de cobranzas
    $sql = "SELECT 
                c.id,
                c.empresa_id,
                COALESCE(e.nombre, 'Multi-Empresa') AS empresa_nombre,
                c.numero_factura,
                c.razon_social_cliente,
                c.rut_cliente,
                c.monto_total_factura,
                c.tipo_entrega,
                c.numero_seguimiento,
                c.comprobante_url,
                c.estado,
                c.created_at,
                COALESCE(NULLIF(c.vendedor_nombre, ''), u.nombre, 'Vendedor no especificado (Registro del Sistema)') AS vendedor_nombre
            FROM cobranzas c
            LEFT JOIN empresas e ON c.empresa_id = e.id
            LEFT JOIN usuarios u ON c.vendedor_id = u.id
            WHERE 1=1";

    $params = [];

    if ($estado !== 'TODOS') {
        if ($estado === 'BANDEJA_TRABAJO') {
            $sql .= " AND c.estado IN ('EN_TRANSITO', 'ENTREGADO_SANTIAGO')";
        } else {
            $sql .= " AND c.estado = :estado";
            $params[':estado'] = $estado;
        }
    }

    if ($empresa_id !== null) {
        $sql .= " AND (c.empresa_id = :empresa_id OR EXISTS (SELECT 1 FROM cobranza_facturas cf WHERE cf.cobranza_id = c.id AND cf.empresa_id = :empresa_id_cf))";
        $params[':empresa_id'] = $empresa_id;
        $params[':empresa_id_cf'] = $empresa_id;
    }

    if ($busqueda !== null) {
        $sql .= " AND (c.numero_factura LIKE :b1 OR c.rut_cliente LIKE :b2 OR c.razon_social_cliente LIKE :b3 OR u.nombre LIKE :b4 OR EXISTS (SELECT 1 FROM cobranza_facturas cf2 WHERE cf2.cobranza_id = c.id AND cf2.numero_factura LIKE :b5))";
        $params[':b1'] = '%' . $busqueda . '%';
        $params[':b2'] = '%' . $busqueda . '%';
        $params[':b3'] = '%' . $busqueda . '%';
        $params[':b4'] = '%' . $busqueda . '%';
        $params[':b5'] = '%' . $busqueda . '%';
    }

    $sql .= " ORDER BY FIELD(c.estado, 'RECIBIDO_TESORERIA', 'EN_TRANSITO', 'ENTREGADO_SANTIAGO', 'DEPOSITADO', 'RECHAZADO', 'PENDIENTE_ENVIO'), c.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $cobranzas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cobranzas)) {
        echo json_encode([
            'success' => true,
            'metrics' => $metrics,
            'data' => []
        ]);
        exit;
    }

    // 3. Traer los cheques y las facturas asociadas
    $cobranzasIds = array_column($cobranzas, 'id');
    $placeholders = implode(',', array_fill(0, count($cobranzasIds), '?'));

    // Facturas en cobranza_facturas
    $stmtFacturas = $pdo->prepare("SELECT 
                                    cobranza_id,
                                    empresa_id,
                                    codigo_empresa,
                                    numero_factura,
                                    cuota_label,
                                    total_cuota,
                                    saldo_cuota,
                                    monto_cubierto
                                FROM cobranza_facturas
                                WHERE cobranza_id IN ($placeholders)
                                ORDER BY id ASC");
    $stmtFacturas->execute($cobranzasIds);
    $todasFacturas = $stmtFacturas->fetchAll(PDO::FETCH_ASSOC);

    $facturasPorCobranza = [];
    foreach ($todasFacturas as $f) {
        $facturasPorCobranza[$f['cobranza_id']][] = $f;
    }

    // Cheques
    $stmtCheques = $pdo->prepare("SELECT 
                                    id,
                                    cobranza_id,
                                    banco,
                                    numero_cheque,
                                    monto,
                                    emitido_a,
                                    cuenta_corriente,
                                    fecha_vencimiento,
                                    foto_cheque_url,
                                    comentario,
                                    numero_papeleta_deposito,
                                    fecha_deposito_real
                                FROM cheques 
                                WHERE cobranza_id IN ($placeholders)
                                ORDER BY id ASC");
    $stmtCheques->execute($cobranzasIds);
    $todosCheques = $stmtCheques->fetchAll(PDO::FETCH_ASSOC);

    // Agrupar cheques por cobranza_id
    $chequesPorCobranza = [];
    foreach ($todosCheques as $chk) {
        $chequesPorCobranza[$chk['cobranza_id']][] = $chk;
    }

    // Inyectar facturas, cheques y totales acumulados
    foreach ($cobranzas as &$cobranza) {
        $cobranza['facturas'] = $facturasPorCobranza[$cobranza['id']] ?? [];
        $cobranza['cheques'] = $chequesPorCobranza[$cobranza['id']] ?? [];
        $totalMontoCheques = 0;
        foreach ($cobranza['cheques'] as $chq) {
            $totalMontoCheques += (float)($chq['monto'] ?? 0);
        }
        $cobranza['total_cheques'] = $totalMontoCheques;
        $cobranza['cantidad_cheques'] = count($cobranza['cheques']);
    }

    echo json_encode([
        'success' => true,
        'metrics' => $metrics,
        'data' => $cobranzas
    ]);

} catch (Exception $e) {
    error_log('[admin/api/get_cobranzas.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor al consultar las cobranzas']);
}
