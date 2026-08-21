<?php
require_once __DIR__ . '/config.php';
$user   = requireAuth();
$action = $_GET['action'] ?? '';
$pdo    = getDB();

if ($action === 'buscar') {
    $rut    = trim($_GET['rut']    ?? '');
    $nombre = trim($_GET['nombre'] ?? '');
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $limit  = 25;
    $offset = $page * $limit;

    $vend_cod = $user['vend_cod'] ?? '';
    $es_admin = ($user['rol'] === 'admin');

    $where  = "1=1";
    $params = [];

    // Filtro por vendedor (salvo admin)
    if (!$es_admin && $vend_cod !== '') {
        $where .= " AND CLIVENCOD = ?";
        $params[] = (int)$vend_cod;
    }

    if ($rut !== '') {
        $where .= " AND CLIRUT LIKE ?";
        $params[] = "%$rut%";
    }
    if ($nombre !== '') {
        $where .= " AND CLINOMBRE LIKE ?";
        $params[] = "%$nombre%";
    }

    $stmtC = $pdo->prepare("SELECT COUNT(*) FROM bd_autotec.tbl_clientes WHERE $where");
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT CLIRUT AS cli_rut, CLIDV AS cli_dv, CLISEC AS cli_sec,
               CLINOMBRE AS cli_razon_social,
               CLIDIR AS cli_dir, CLICOM AS cli_com, CLICIU AS cli_ciu,
               CLIEML AS cli_eml, CLIDISDES AS cli_disdes,
               CLIVENCOD AS cli_vencod
        FROM bd_autotec.tbl_clientes
        WHERE $where
        ORDER BY CLINOMBRE
        LIMIT ? OFFSET ?
    ");
    $stmt->execute(array_merge($params, [$limit, $offset]));

    jsonResponse(true, [
        'clientes' => $stmt->fetchAll(),
        'total'    => $total,
        'page'     => $page,
    ]);
}

jsonResponse(false, null, 'Acción no válida');