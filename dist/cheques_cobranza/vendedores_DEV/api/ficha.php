<?php
require_once __DIR__ . '/config.php';

$action  = $_GET['action'] ?? '';
$prod_id = trim($_GET['prod_id'] ?? '');
$pdo     = getDB();

if (!$prod_id) jsonResponse(false, null, 'Falta prod_id');

// ============================================================
// GET ?action=detalle — Datos principales del producto
// ============================================================
if ($action === 'detalle') {
    $stmt = $pdo->prepare("
        SELECT p.prod_id, p.codigo_equivalente1, p.codigo_equivalente2,
               p.prod_nombre, p.prod_texto_corto, p.prod_descripcion,
               p.prod_precio, p.prod_stock, p.cla_id, p.marca_id,
               p.unidades_caja, p.medida_interna, p.medida_externa,
               p.diametro_entrada, p.diametro_salida, p.medida_hilo,
               p.especificacion, p.homologacion, p.nota, p.retorno,
               p.formato, p.color, p.tipo_aceite, p.caracteristica,
               p.aplicacion, p.adaptadores, p.voltaje, p.potencia,
               p.norma, p.capacidad, p.onza, p.origen, p.tipo_asiento,
               p.medida, p.hilo, p.largo, p.aislacion, p.diametro,
               p.electrodo_central, p.electrodo_tierra,
               p.alto, p.ancho, p.num_pieza, p.otros,
               p.a_h, p.cca, p.cap_reserva, p.tipo_borne, p.pos_borne,
               p.familia, p.modelo, p.lw, p.tipo, p.medida_tipo,
               p.descontinuado, p.caja_master, p.prod_desc,
               p.tipo AS tipo2, p.medida_tipo AS med_tipo2, p.unidades_caja,
               mp.marca_nombre, mp.marca_img,
               c.cla_nombre,
               mu.multiplo
        FROM tbl_productos p
        LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
        LEFT JOIN tbl_clasificacion c ON p.cla_id = c.cla_id
        LEFT JOIN tbl_productos_multiplos mu ON p.prod_id = mu.id_prod
        WHERE p.prod_id = ? AND p.prod_estado = 1
        LIMIT 1
    ");
    $stmt->execute([$prod_id]);
    $prod = $stmt->fetch();
    if (!$prod) jsonResponse(false, null, 'Producto no encontrado');
    jsonResponse(true, $prod);
}

// ============================================================
// GET ?action=imagenes — Todas las imágenes del producto
// ============================================================
if ($action === 'imagenes') {
    $stmt = $pdo->prepare("
        SELECT img_chica, img_grande, img_orden
        FROM tbl_img_productos
        WHERE prod_id = ?
        ORDER BY img_orden
    ");
    $stmt->execute([$prod_id]);
    jsonResponse(true, $stmt->fetchAll());
}

// ============================================================
// GET ?action=oem — Códigos OEM / equivalentes
// ============================================================
if ($action === 'oem') {
    $stmt = $pdo->prepare("
        SELECT a.cod_equivalente,
               b.marca_nombre AS marca_competencia
        FROM tbl_prod_equivalente a
        LEFT JOIN automarc_automarco.tbl_marcas_2 b ON a.marca_id = b.marca_id
        WHERE a.prod_id = ? AND a.tipo_equivalencia = 3
        ORDER BY b.marca_nombre, a.cod_equivalente
    ");
    $stmt->execute([$prod_id]);
    jsonResponse(true, $stmt->fetchAll());
}

// ============================================================
// GET ?action=aplicaciones — Todas las aplicaciones (para tabla)
// ============================================================
if ($action === 'aplicaciones') {
    $stmt = $pdo->prepare("
        SELECT mm.marca_nombre AS marca_vehiculo,
               pm.mod_id AS mod_descripcion,
               pm.agno_inicio, pm.agno_fin,
               d.cilindrada,
               TRIM(REPLACE(REPLACE(
                   CONCAT(
                       IFNULL(pm.motor, ''), ' ',
                       IFNULL(b.traccion, ''), ' ',
                       IFNULL(pm.version, ''), ' ',
                       IFNULL(c.origen, ''), ' ',
                       IFNULL(d.cilindrada, ''), ' ',
                       IFNULL(e.combustible, ''), ' ',
                       IFNULL(f.abreviacion, '')
                   ), '-- ', ''), ' --', '')) AS motor_completo
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

// ============================================================
// GET ?action=relacionados — Productos relacionados (misma categoría)
// ============================================================
if ($action === 'relacionados') {
    // Obtener cla_id del producto
    $s = $pdo->prepare("SELECT cla_id FROM tbl_productos WHERE prod_id = ? LIMIT 1");
    $s->execute([$prod_id]);
    $cla = $s->fetchColumn();
    if (!$cla) jsonResponse(true, []);

    $stmt = $pdo->prepare("
        SELECT p.prod_id, p.prod_nombre, p.prod_precio, p.prod_stock,
               mp.marca_nombre, i.img_chica
        FROM tbl_productos p
        LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
        LEFT JOIN tbl_img_productos i ON p.prod_id = i.prod_id AND i.img_orden = 1
        WHERE p.cla_id = ? AND p.prod_id <> ? AND p.prod_estado = 1
        GROUP BY p.prod_id
        ORDER BY RAND()
        LIMIT 6
    ");
    $stmt->execute([$cla, $prod_id]);
    jsonResponse(true, $stmt->fetchAll());
}

jsonResponse(false, null, 'Acción no válida');