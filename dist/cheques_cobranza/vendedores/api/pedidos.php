<?php
require_once __DIR__ . '/config.php';
$user   = requireAuth();
$action = $_GET['action'] ?? '';
$pdo    = getDB();

// Estados Android:
// INCOMPLETA = borrador/en edición
// EN PROCESO = pedido enviado
// COTIZACION EN ESPERA = cotización enviada
// COTIZACION ENTREGADA = cotización procesada
// ENTREGADO = pedido procesado/entregado
// ANULADA = anulado

// ============================================================
// GET ?action=lista
// ============================================================
if ($action === 'lista') {
    $page     = max(0, (int)($_GET['page'] ?? 0));
    $limit    = 50;
    $offset   = $page * $limit;
    $estado   = trim($_GET['estado'] ?? '');
    $filtroVendedor = trim($_GET['vendedor'] ?? '');

    $vend_cod = $user['vend_cod'] ?? '';
    $es_admin = ($user['rol'] === 'admin');

    $where  = "1=1";
    $params = [];

    if ($estado !== '') {
        $where .= " AND c.pedi_estado = ?";
        $params[] = $estado;
    }
    // Sin filtro muestra TODOS los estados

    if (!$es_admin) {
        $where .= " AND c.pedi_vendedor = ?";
        $params[] = $vend_cod;
    } elseif ($filtroVendedor !== '') {
        // Admin puede filtrar por un vendedor específico
        $where .= " AND c.pedi_vendedor = ?";
        $params[] = $filtroVendedor;
    }

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM web_carro_cabecera c WHERE $where");
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT c.pedi_id, c.cli_rut, c.cli_sec, c.emp_id,
               c.pedi_fecha, c.pedi_estado, c.pedi_total_neto,
               c.pedi_total_iva, c.pedi_total, c.pedi_orden_compra,
               c.pedi_observaciones, c.pedi_vendedor,
               cl.CLINOMBRE AS cli_razon_social,
               v.nombre AS vendedor_nombre,
               COUNT(d.id) AS items
        FROM web_carro_cabecera c
        LEFT JOIN (
            SELECT CLIRUT, MIN(CLINOMBRE) AS CLINOMBRE
            FROM bd_autotec.tbl_clientes GROUP BY CLIRUT
        ) cl ON c.cli_rut = cl.CLIRUT
        LEFT JOIN (
            SELECT vend_cod, MIN(nombre) AS nombre
            FROM web_usuarios WHERE vend_cod <> '' AND rol <> 'admin' GROUP BY vend_cod
        ) v ON c.pedi_vendedor = v.vend_cod
        LEFT JOIN web_carro_detalle d ON c.pedi_id = d.pedi_id
        WHERE $where
        GROUP BY c.pedi_id
        ORDER BY c.pedi_id DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));

    jsonResponse(true, ['pedidos' => $stmt->fetchAll(), 'total' => $total, 'page' => $page]);
}

// ============================================================
// GET ?action=detalle&pedi_id=X
// ============================================================
if ($action === 'detalle') {
    $pedi_id = (int)($_GET['pedi_id'] ?? 0);
    if (!$pedi_id) jsonResponse(false, null, 'Falta pedi_id');

    $cab = $pdo->prepare("
        SELECT c.*, cl.CLINOMBRE AS cli_razon_social,
               cl.CLIDIR AS cli_dir, cl.CLICOM AS cli_com
        FROM web_carro_cabecera c
        LEFT JOIN (
            SELECT CLIRUT, MIN(CLINOMBRE) AS CLINOMBRE,
                   MIN(CLIDIR) AS CLIDIR, MIN(CLICOM) AS CLICOM
            FROM bd_autotec.tbl_clientes GROUP BY CLIRUT
        ) cl ON c.cli_rut = cl.CLIRUT
        WHERE c.pedi_id = ?
    ");
    $cab->execute([$pedi_id]);
    $cabecera = $cab->fetch();
    if (!$cabecera) jsonResponse(false, null, 'Pedido no encontrado');

    $det = $pdo->prepare("
        SELECT d.prod_id, d.ped_prod_cantidad, d.ped_prod_neto, d.ped_prod_dscto,
               p.prod_nombre, p.codigo_equivalente1, mp.marca_nombre
        FROM web_carro_detalle d
        JOIN tbl_productos p ON d.prod_id = p.prod_id
        LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
        WHERE d.pedi_id = ?
        ORDER BY d.id
    ");
    $det->execute([$pedi_id]);

    jsonResponse(true, ['cabecera' => $cabecera, 'detalle' => $det->fetchAll()]);
}

// ============================================================
// POST ?action=eliminar&pedi_id=X — Borradores y cotizaciones (no pedidos ya procesados)
// ============================================================
if ($action === 'eliminar') {
    $pedi_id = (int)($_GET['pedi_id'] ?? 0);
    if (!$pedi_id) jsonResponse(false, null, 'Falta pedi_id');
    $estadosEliminables = ['INCOMPLETA', 'COTIZACION EN ESPERA', 'COTIZACION ENVIADA'];
    $placeholders = implode(',', array_fill(0, count($estadosEliminables), '?'));
    $check = $pdo->prepare("SELECT pedi_id FROM web_carro_cabecera WHERE pedi_id=? AND usuario_id=? AND pedi_estado IN ($placeholders)");
    $check->execute(array_merge([$pedi_id, $user['id']], $estadosEliminables));
    if (!$check->fetch()) jsonResponse(false, null, 'No autorizado o el pedido ya fue procesado');
    $pdo->prepare("DELETE FROM web_carro_detalle WHERE pedi_id=?")->execute([$pedi_id]);
    $pdo->prepare("DELETE FROM web_carro_cabecera WHERE pedi_id=?")->execute([$pedi_id]);
    jsonResponse(true, null, 'Pedido eliminado');
}

// ============================================================
// GET ?action=vendedores — Lista de vendedores (solo para filtro admin)
// ============================================================
if ($action === 'vendedores') {
    if ($user['rol'] !== 'admin') jsonResponse(false, null, 'No autorizado');
    $rows = $pdo->query("
        SELECT vend_cod, MIN(nombre) AS nombre
        FROM web_usuarios
        WHERE vend_cod <> '' AND rol <> 'admin'
        GROUP BY vend_cod
        ORDER BY nombre ASC
    ")->fetchAll();
    jsonResponse(true, $rows);
}

jsonResponse(false, null, 'Acción no válida');