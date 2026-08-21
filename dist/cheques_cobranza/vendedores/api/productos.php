<?php
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? '';
$pdo = getDB();

// ============================================================
// HELPER: getAplicaciones
// ============================================================
function getAplicaciones(PDO $pdo, array $prodIds, string $marca_id = '0', string $mod_id = '0'): array {
    if (empty($prodIds)) return [];
    $placeholders = implode(',', array_fill(0, count($prodIds), '?'));

    if ($marca_id !== '0') {
        $sql = "SELECT pm.prod_id, pm.mod_id AS mod_descripcion, pm.agno_inicio, pm.agno_fin,
                       mm.marca_nombre AS marca_vehiculo
                FROM tbl_productos_modelos_2 pm
                JOIN automarc_automarco.tbl_marcas_2 mm ON pm.marca_id = mm.marca_id
                WHERE pm.prod_id IN ($placeholders) AND pm.marca_id = ?";
        $params = array_merge($prodIds, [$marca_id]);
        if ($mod_id !== '0') { $sql .= " AND pm.mod_id = ?"; $params[] = $mod_id; }
        $sql .= " GROUP BY pm.prod_id";
    } else {
        $sql = "SELECT pm.prod_id, pm.mod_id AS mod_descripcion, pm.agno_inicio, pm.agno_fin,
                       mm.marca_nombre AS marca_vehiculo
                FROM tbl_productos_modelos_2 pm
                JOIN automarc_automarco.tbl_marcas_2 mm ON pm.marca_id = mm.marca_id
                WHERE pm.prod_id IN ($placeholders) AND pm.pm_principal = 1
                GROUP BY pm.prod_id";
        $params = $prodIds;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result = [];
        foreach ($stmt->fetchAll() as $row) $result[$row['prod_id']] = $row;
        // Para los que no tenían pm_principal=1
        $faltantes = array_values(array_diff($prodIds, array_keys($result)));
        if (!empty($faltantes)) {
            $ph2  = implode(',', array_fill(0, count($faltantes), '?'));
            $sql2 = "SELECT pm.prod_id, pm.mod_id AS mod_descripcion, pm.agno_inicio, pm.agno_fin,
                            mm.marca_nombre AS marca_vehiculo
                     FROM tbl_productos_modelos_2 pm
                     JOIN automarc_automarco.tbl_marcas_2 mm ON pm.marca_id = mm.marca_id
                     WHERE pm.prod_id IN ($ph2) GROUP BY pm.prod_id";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($faltantes);
            foreach ($stmt2->fetchAll() as $row) $result[$row['prod_id']] = $row;
        }
        return $result;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = [];
    foreach ($stmt->fetchAll() as $row) $result[$row['prod_id']] = $row;
    return $result;
}

function mergeAplicaciones(array $productos, array $aplicaciones): array {
    foreach ($productos as &$p) {
        $app = $aplicaciones[$p['prod_id']] ?? null;
        $p['mod_descripcion'] = $app['mod_descripcion'] ?? null;
        $p['agno_inicio']     = $app['agno_inicio']     ?? null;
        $p['agno_fin']        = $app['agno_fin']        ?? null;
        $p['marca_vehiculo']  = $app['marca_vehiculo']  ?? null;
    }
    return $productos;
}

// ============================================================
if ($action === 'categorias') {
    $rows = $pdo->query("SELECT cla_id, cla_nombre, id_familia, cla_orden FROM tbl_clasificacion ORDER BY cla_orden")->fetchAll();
    jsonResponse(true, $rows);
}

if ($action === 'marcas_vehiculo') {
    $rows = $pdo->query("SELECT marca_id, marca_nombre FROM automarc_automarco.tbl_marcas_2 WHERE marca_estado=1 AND Autotec = 1 ORDER BY marca_nombre")->fetchAll();
    jsonResponse(true, $rows);
}

if ($action === 'modelos') {
    $marca_id = $_GET['marca_id'] ?? '';
    if (!$marca_id) jsonResponse(false, null, 'Falta marca_id');
    $stmt = $pdo->prepare("SELECT mod_id FROM automarc_automarco.tbl_modelos_marcas_2 WHERE marca_id=? AND estado=1 AND Autotec = 1 ORDER BY mod_id");
    $stmt->execute([$marca_id]);
    jsonResponse(true, $stmt->fetchAll());
}

if ($action === 'agnos') {
    $mod_id   = $_GET['mod_id'] ?? '';
    $marca_id = $_GET['marca_id'] ?? '';
    if (!$mod_id) jsonResponse(false, null, 'Falta mod_id');
    $stmt = $pdo->prepare("SELECT DISTINCT agno_inicio, agno_fin FROM tbl_productos_modelos_2 WHERE mod_id=? AND marca_id=? ORDER BY agno_inicio");
    $stmt->execute([$mod_id, $marca_id]);
    $rows = $stmt->fetchAll();
    $agnos = [];
    foreach ($rows as $r) {
        for ($y = (int)$r['agno_inicio']; $y <= (int)$r['agno_fin']; $y++) $agnos[$y] = $y;
    }
    rsort($agnos);
    jsonResponse(true, array_values($agnos));
}

if ($action === 'cilindradas') {
    $rows = $pdo->query("SELECT cilin_id, cilindrada FROM tbl_cilindrada ORDER BY cilindrada")->fetchAll();
    jsonResponse(true, $rows);
}

// ============================================================
// BUSCAR POR CÓDIGO / OEM
// ============================================================
if ($action === 'buscar_codigo') {
    $q      = trim($_GET['q'] ?? '');
    $page   = max(0, (int)($_GET['page'] ?? 0));
    $limit  = 25;
    $offset = $page * $limit;
    if (strlen($q) < 2) jsonResponse(false, null, 'Ingrese al menos 2 caracteres');

    // Igual que Android: buscar con y sin guiones/espacios
    $qClean = str_replace(['-', '/', ' '], '', $q);
    $like   = "%$q%";
    $likeC  = "%$qClean%";

    $stmtC = $pdo->prepare("
        SELECT COUNT(DISTINCT p.prod_id)
        FROM tbl_productos p
        LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
        LEFT JOIN tbl_productos_multiplos mu ON p.prod_id = mu.id_prod
        LEFT JOIN tbl_prod_equivalente e ON p.prod_id = e.prod_id AND e.tipo_equivalencia = 3
        WHERE mp.marca_estado = 1 AND p.prod_estado = 1
          AND (
            p.prod_id LIKE ? OR REPLACE(p.prod_id,'-','') LIKE ?
            OR p.prod_nombre LIKE ?
            OR p.codigo_equivalente1 LIKE ? OR REPLACE(p.codigo_equivalente1,'-','') LIKE ?
            OR e.cod_equivalente LIKE ? OR REPLACE(e.cod_equivalente,'-','') LIKE ?
          )
    ");
    $stmtC->execute([$like, $likeC, $like, $like, $likeC, $like, $likeC]);
    $total = (int)$stmtC->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT p.prod_id, p.codigo_equivalente1, p.prod_nombre, p.prod_precio,
               p.prod_stock, p.marca_id, p.cla_id, mp.marca_nombre,
               i.img_media AS img_chica, p.unidades_caja AS multiplo_caja, mu.multiplo,
               (SELECT e2.cod_equivalente FROM tbl_prod_equivalente e2
                WHERE e2.prod_id = p.prod_id AND e2.tipo_equivalencia = 3 LIMIT 1) AS oem
        FROM tbl_productos p
        LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
        LEFT JOIN tbl_img_productos i ON p.prod_id = i.prod_id AND i.img_orden = 1
        LEFT JOIN tbl_productos_multiplos mu ON p.prod_id = mu.id_prod
        LEFT JOIN tbl_prod_equivalente e ON p.prod_id = e.prod_id AND e.tipo_equivalencia = 3
        WHERE mp.marca_estado = 1 AND p.prod_estado = 1
          AND (
            p.prod_id LIKE ? OR REPLACE(p.prod_id,'-','') LIKE ?
            OR p.prod_nombre LIKE ?
            OR p.codigo_equivalente1 LIKE ? OR REPLACE(p.codigo_equivalente1,'-','') LIKE ?
            OR e.cod_equivalente LIKE ? OR REPLACE(e.cod_equivalente,'-','') LIKE ?
          )
        GROUP BY p.prod_id
        ORDER BY p.prod_nombre
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([$like, $likeC, $like, $like, $likeC, $like, $likeC, $limit, $offset]);
    $productos = $stmt->fetchAll();
    $prodIds   = array_column($productos, 'prod_id');
    $apps      = getAplicaciones($pdo, $prodIds);
    $productos = mergeAplicaciones($productos, $apps);
    jsonResponse(true, ['productos' => $productos, 'total' => $total, 'page' => $page]);
}

// ============================================================
// BUSCAR POR APLICACIÓN
// ============================================================
if ($action === 'buscar_aplicacion') {
    $categoria = $_GET['categoria'] ?? '0';
    $marca_id  = $_GET['marca_id']  ?? '0';
    $mod_id    = $_GET['mod_id']    ?? '0';
    $agno      = $_GET['agno']      ?? 'TODOS';
    $page      = max(0, (int)($_GET['page'] ?? 0));
    $limit     = 25;
    $offset    = $page * $limit;

    if ($categoria === '0' && $marca_id === '0') {
        jsonResponse(false, null, 'Seleccione al menos una Categoría o Marca de vehículo');
    }

    $params = []; $paramsCount = [];

    if ($marca_id === '0') {
        $sqlCount = "SELECT COUNT(DISTINCT p.prod_id) FROM tbl_productos p LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id WHERE mp.marca_estado = 1 AND p.prod_estado = 1 AND p.cla_id = ?";
        $paramsCount[] = $categoria;
        $sql = "SELECT p.prod_id, p.codigo_equivalente1, p.prod_nombre, p.prod_precio,
                       p.prod_stock, p.marca_id, p.cla_id, mp.marca_nombre, i.img_media as img_chica,
                       p.unidades_caja AS multiplo_caja, mu.multiplo
                FROM tbl_productos p
                LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
                LEFT JOIN tbl_img_productos i ON p.prod_id = i.prod_id AND i.img_orden = 1
                LEFT JOIN tbl_productos_multiplos mu ON p.prod_id = mu.id_prod
                WHERE mp.marca_estado = 1 AND p.prod_estado = 1 AND p.cla_id = ?";
        $params[] = $categoria;
    } else {
        $sqlCount = "SELECT COUNT(DISTINCT p.prod_id) FROM tbl_productos p LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id WHERE mp.marca_estado = 1 AND p.prod_estado = 1 AND EXISTS (SELECT 1 FROM tbl_productos_modelos_2 pm WHERE pm.prod_id = p.prod_id AND pm.marca_id = ?";
        $paramsCount[] = $marca_id;
        if ($mod_id !== '0') { $sqlCount .= " AND pm.mod_id = ?"; $paramsCount[] = $mod_id; }
        if (!empty($agno) && $agno !== 'TODOS') { $sqlCount .= " AND pm.agno_inicio <= ? AND pm.agno_fin >= ?"; $paramsCount[] = $agno; $paramsCount[] = $agno; }
        $sqlCount .= ")";
        if ($categoria !== '0') { $sqlCount .= " AND p.cla_id = ?"; $paramsCount[] = $categoria; }

        $sql = "SELECT DISTINCT p.prod_id, p.codigo_equivalente1, p.prod_nombre, p.prod_precio,
                       p.prod_stock, p.marca_id, p.cla_id, mp.marca_nombre, i.img_media as img_chica,
                       p.unidades_caja AS multiplo_caja, mu.multiplo
                FROM tbl_productos p
                LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
                INNER JOIN tbl_productos_modelos_2 pm ON p.prod_id = pm.prod_id AND pm.marca_id = ?
                LEFT JOIN tbl_img_productos i ON p.prod_id = i.prod_id AND i.img_orden = 1
                LEFT JOIN tbl_productos_multiplos mu ON p.prod_id = mu.id_prod
                WHERE mp.marca_estado = 1 AND p.prod_estado = 1";
        $params[] = $marca_id;
        if ($mod_id !== '0') { $sql .= " AND pm.mod_id = ?"; $params[] = $mod_id; }
        if (!empty($agno) && $agno !== 'TODOS') { $sql .= " AND pm.agno_inicio <= ? AND pm.agno_fin >= ?"; $params[] = $agno; $params[] = $agno; }
        if ($categoria !== '0') { $sql .= " AND p.cla_id = ?"; $params[] = $categoria; }
    }

    $stmtC = $pdo->prepare($sqlCount);
    $stmtC->execute($paramsCount);
    $total = (int)$stmtC->fetchColumn();

    $sql .= " GROUP BY p.prod_id ORDER BY p.prod_nombre LIMIT ? OFFSET ?";
    $params[] = $limit; $params[] = $offset;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll();
    $prodIds   = array_column($productos, 'prod_id');
    $apps      = getAplicaciones($pdo, $prodIds, $marca_id, $mod_id);
    $productos = mergeAplicaciones($productos, $apps);
    jsonResponse(true, ['productos' => $productos, 'total' => $total, 'page' => $page]);
}

// ============================================================
// GET ?action=medidas&subtipo=ALT|PK&tipo=X — Medidas para spinners
// ============================================================
if ($action === 'medidas') {
    $subtipo = $_GET['subtipo'] ?? 'ALT';
    $tipo    = trim($_GET['tipo'] ?? '');
    $where   = "prod_estado = 1 AND marca_id = 1";
    $params  = [];
    if ($subtipo === 'PK') {
        $where .= " AND tipo LIKE '%PK'";
    } else {
        $where .= " AND tipo NOT LIKE '%PK'";
    }
    if ($tipo && $tipo !== '0') { $where .= " AND tipo = ?"; $params[] = $tipo; }
    $stmt = $pdo->prepare("SELECT DISTINCT medida_tipo FROM tbl_productos WHERE $where AND medida_tipo IS NOT NULL AND medida_tipo <> '' ORDER BY CAST(medida_tipo AS UNSIGNED)");
    $stmt->execute($params);
    jsonResponse(true, array_column($stmt->fetchAll(), 'medida_tipo'));
}

// ============================================================
// BUSCAR POR MEDIDAS (Correas Alternador y Correas PK)
// ============================================================
if ($action === 'buscar_medidas') {
    $subtipo = trim($_GET['subtipo'] ?? 'ALT');
    $tipo    = trim($_GET['tipo']    ?? '');
    $medida  = trim($_GET['medida']  ?? '');
    $page    = max(0, (int)($_GET['page'] ?? 0));
    $limit   = 25;
    $offset  = $page * $limit;

    $where  = "p.prod_estado = 1 AND mp.marca_estado = 1 AND p.marca_id = 1";
    $params = [];
    if ($subtipo === 'PK') {
        $where .= " AND p.tipo LIKE '%PK'";
    } else {
        $where .= " AND p.tipo NOT LIKE '%PK'";
    }
    if ($tipo && $tipo !== '0')   { $where .= " AND p.tipo = ?";        $params[] = $tipo; }
    if ($medida && $medida !== '0') { $where .= " AND p.medida_tipo = ?"; $params[] = $medida; }

    $stmtC = $pdo->prepare("SELECT COUNT(DISTINCT p.prod_id) FROM tbl_productos p LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id WHERE $where");
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();

    $stmtP = $pdo->prepare("
        SELECT p.prod_id, p.codigo_equivalente1, p.prod_nombre, p.prod_precio,
               p.prod_stock, p.tipo, p.medida_tipo, p.marca_id, p.cla_id,
               mp.marca_nombre, i.img_media AS img_chica
        FROM tbl_productos p
        LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
        LEFT JOIN tbl_img_productos i ON p.prod_id = i.prod_id AND i.img_orden = 1
        WHERE $where
        GROUP BY p.prod_id
        ORDER BY CAST(p.medida_tipo AS UNSIGNED)
        LIMIT ? OFFSET ?
    ");
    $stmtP->execute(array_merge($params, [$limit, $offset]));
    $productos = $stmtP->fetchAll();
    if (!empty($productos)) {
        $prodIds   = array_column($productos, 'prod_id');
        $apps      = getAplicaciones($pdo, $prodIds);
        $productos = mergeAplicaciones($productos, $apps);
    }
    jsonResponse(true, ['productos' => $productos, 'total' => $total, 'page' => $page]);
}

// ============================================================
// APLICACIONES COMPLETAS DE UN PRODUCTO (modal)
// ============================================================
if ($action === 'aplicaciones_producto') {
    $prod_id = $_GET['prod_id'] ?? '';
    if (!$prod_id) jsonResponse(false, null, 'Falta prod_id');
    $stmt = $pdo->prepare("
        SELECT mm.marca_nombre AS marca_vehiculo,
               pm.mod_id AS mod_descripcion,
               pm.agno_inicio, pm.agno_fin,
               d.cilindrada,
               TRIM(REPLACE(REPLACE(CONCAT(
                   IFNULL(pm.motor,''),' ',IFNULL(b.traccion,''),' ',IFNULL(pm.version,''),' ',
                   IFNULL(c.origen,''),' ',IFNULL(d.cilindrada,''),' ',IFNULL(e.combustible,''),' ',
                   IFNULL(f.abreviacion,'')), '-- ',''), ' --','')) AS motor_completo
        FROM tbl_productos_modelos_2 pm
        JOIN automarc_automarco.tbl_marcas_2 mm ON pm.marca_id = mm.marca_id
        LEFT JOIN automarc_automarco.tbl_traccion b ON pm.traccion_id = b.traccion_id
        LEFT JOIN automarc_automarco.tbl_origen c ON pm.origen_id = c.origen_id
        LEFT JOIN automarc_automarco.tbl_cilindrada2 d ON pm.cilindrada_id = d.cilin_id
        LEFT JOIN automarc_automarco.tbl_combustible e ON pm.id_combustible = e.id_combustible
        LEFT JOIN automarc_automarco.tbl_correa_aplicacion f ON pm.id_corrapli = f.id_corrapli
        WHERE pm.prod_id = ?
        ORDER BY mm.marca_nombre, pm.mod_id, pm.agno_inicio
    ");
    $stmt->execute([$prod_id]);
    jsonResponse(true, $stmt->fetchAll());
}

jsonResponse(false, null, 'Acción no válida');