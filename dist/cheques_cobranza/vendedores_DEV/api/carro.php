<?php
require_once __DIR__ . '/config.php';

$user   = requireAuth();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];
$pdo    = getDB();

// ============================================================
// Obtener o crear carro activo del usuario
// ============================================================
function getCarroActivo(PDO $pdo, int $userId, string $cliRut = '', string $cliSec = '', int $pediIdSesion = 0): int {
    global $user;

    // Si viene un pedi_id de sesión, verificar que existe, es INCOMPLETA y pertenece al usuario
    if ($pediIdSesion) {
        $stmt = $pdo->prepare("SELECT pedi_id FROM web_carro_cabecera WHERE pedi_id=? AND usuario_id=? AND pedi_estado='INCOMPLETA'");
        $stmt->execute([$pediIdSesion, $userId]);
        $row = $stmt->fetch();
        if ($row) return (int)$row['pedi_id'];
    }

    // Sin pedi_id de sesión: crear siempre un carro nuevo para este cliente
    $ins = $pdo->prepare("INSERT INTO web_carro_cabecera (usuario_id, cli_rut, cli_sec, emp_id, pedi_vendedor, pedi_estado) VALUES (?,?,?,?,?,?)");
    $ins->execute([$userId, $cliRut, $cliSec, EMP_ID, $user['vend_cod'] ?? '', 'INCOMPLETA']);
    return (int)$pdo->lastInsertId();
}

function recalcularTotales(PDO $pdo, int $pediId): void {
    // Neto considera el descuento por ítem: precio * cantidad * (1 - dscto/100)
    $stmt = $pdo->prepare("
        SELECT SUM(ROUND(ped_prod_neto * ped_prod_cantidad * (1 - ped_prod_dscto / 100)))
        FROM web_carro_detalle WHERE pedi_id=?
    ");
    $stmt->execute([$pediId]);
    $neto = (int)$stmt->fetchColumn();
    $iva  = (int)round($neto * IVA_PORCENTAJE / 100);
    $total = $neto + $iva;
    $pdo->prepare("UPDATE web_carro_cabecera SET pedi_total_neto=?, pedi_total_iva=?, pedi_total=? WHERE pedi_id=?")
        ->execute([$neto, $iva, $total, $pediId]);
}

// ============================================================
// CUERPO DEL CORREO DE COTIZACIÓN
// Replica el HTML armado en Persistencia_Correo.java: tabla con
// código, código equivalente, descripción, precio, cantidad,
// %dcto y total, más los totales Neto/IVA/Total a Pagar.
// ============================================================
// ============================================================
// HEADER DEL CORREO — imagen con ruta absoluta, igual que Android
// ============================================================
function headerCorreoHtml(): string {
    return "<table width='600' border='1' cellspacing='0' cellpadding='0'><tr style='font-size:0px;'>"
        . "<td colspan='7'><img src='http://www.autotec.cl/images/img-marcas.jpg' alt='' width='600'></td>"
        . "</tr></table>";
}

// ============================================================
// TABLA DE PRODUCTOS — compartida entre cotización y pedido
// ============================================================
function tablaProductosHtml(array $detalle, array $cabecera): array {
    $filas = '';
    foreach ($detalle as $d) {
        $precio = (float)$d['ped_prod_neto'];
        $cant   = (int)$d['ped_prod_cantidad'];
        $dscto  = (float)$d['ped_prod_dscto'];
        $totalItem = round($precio * $cant * (1 - $dscto / 100));

        $filas .= "<tr>"
            . "<td style='padding:4px 8px;border:1px solid #ccc'>" . htmlspecialchars($d['prod_id']) . "</td>"
            . "<td style='padding:4px 8px;border:1px solid #ccc'>" . htmlspecialchars($d['codigo_equivalente1'] ?? '') . "</td>"
            . "<td style='padding:4px 8px;border:1px solid #ccc'>" . htmlspecialchars($d['prod_nombre'] ?? '') . "</td>"
            . "<td style='padding:4px 8px;border:1px solid #ccc;text-align:right'>" . formatoPrecio((int)$precio) . "</td>"
            . "<td style='padding:4px 8px;border:1px solid #ccc;text-align:right'>$cant</td>"
            . "<td style='padding:4px 8px;border:1px solid #ccc;text-align:right'>" . rtrim(rtrim(number_format($dscto, 1), '0'), '.') . "%</td>"
            . "<td style='padding:4px 8px;border:1px solid #ccc;text-align:right'>" . formatoPrecio((int)$totalItem) . "</td>"
            . "</tr>";
    }

    $neto  = (int)($cabecera['pedi_total_neto'] ?? 0);
    $iva   = (int)($cabecera['pedi_total_iva']  ?? 0);
    $total = (int)($cabecera['pedi_total']      ?? 0);

    $tabla = "
        <table width='600' border='1' cellspacing='0' cellpadding='0' style='border-collapse:collapse'>
            <tr style='background:#f5f5f5;font-weight:bold;font-size:11px'>
                <td style='padding:4px 8px;border:1px solid #ccc'>Código</td>
                <td style='padding:4px 8px;border:1px solid #ccc'>Cód. Equiv.</td>
                <td style='padding:4px 8px;border:1px solid #ccc'>Descripción</td>
                <td style='padding:4px 8px;border:1px solid #ccc' align='right'>Precio</td>
                <td style='padding:4px 8px;border:1px solid #ccc' align='right'>Cantidad</td>
                <td style='padding:4px 8px;border:1px solid #ccc' align='right'>%Dcto.</td>
                <td style='padding:4px 8px;border:1px solid #ccc' align='right'>Total</td>
            </tr>
            $filas
            <tr><td colspan='6' align='right' style='padding:4px 8px'><strong>Neto:</strong></td><td align='right' style='padding:4px 8px'>" . formatoPrecio($neto) . "</td></tr>
            <tr><td colspan='6' align='right' style='padding:4px 8px'><strong>IVA:</strong></td><td align='right' style='padding:4px 8px'>" . formatoPrecio($iva) . "</td></tr>
            <tr><td colspan='6' align='right' style='padding:4px 8px'><strong>Total a Pagar:</strong></td><td align='right' style='padding:4px 8px'>" . formatoPrecio($total) . "</td></tr>
        </table>";

    return [$tabla, $neto, $iva, $total];
}

// ============================================================
// CUERPO DEL CORREO DE COTIZACIÓN (con header/imagen igual que Android)
// ============================================================
function construirCuerpoCotizacion(int $pediId, array $cabecera, array $detalle): string {
    [$tabla] = tablaProductosHtml($detalle, $cabecera);
    $cliente = htmlspecialchars($cabecera['cli_razon_social'] ?? '');
    $ciudad  = htmlspecialchars($cabecera['cli_ciu'] ?? '');

    return "
    <div style='font-family:Verdana, Geneva, sans-serif; font-size:12px; color:#333'>
        " . headerCorreoHtml() . "
        <br>
        <p>Estimado(s) Señor(es) <strong>$cliente</strong>, agradecemos su preferencia.
        El detalle de su cotización es el siguiente:<br><br>
        Para cualquier consulta llámenos al 22 896 4201 o escríbanos al e-mail
        <a href='mailto:consultas@autotec.cl'>consultas@autotec.cl</a>.</p>
        $tabla
        <p style='margin-top:12px'>$ciudad</p>
    </div>";
}

// ============================================================
// CUERPO DEL CORREO DE PEDIDO CONFIRMADO (con header/imagen igual que Android)
// ============================================================
function construirCuerpoPedido(int $pediId, array $cabecera, array $detalle): string {
    [$tabla] = tablaProductosHtml($detalle, $cabecera);
    $cliente = htmlspecialchars($cabecera['cli_razon_social'] ?? '');
    $ciudad  = htmlspecialchars($cabecera['cli_ciu'] ?? '');

    return "
    <div style='font-family:Verdana, Geneva, sans-serif; font-size:12px; color:#333'>
        " . headerCorreoHtml() . "
        <br>
        <p>Estimado(s) Señor(es) <strong>$cliente</strong>, agradecemos su preferencia.
        El detalle de su compra es el siguiente:<br><br>
        Para cualquier consulta y/o modificación llámenos al 2 896 4201 o escríbanos al e-mail
        <a href='mailto:consultas@autotec.cl'>consultas@autotec.cl</a>.</p>
        $tabla
        <p style='margin-top:12px'>$ciudad</p>
    </div>";
}

// ============================================================
// INYECCIÓN DE PEDIDO AL WEBSERVICE
// Replica ingresarPedidoCabecera + ingresarPedidoDetalle de
// Persistencia.java. Simplificado: NO incluye el paso de
// verificaItems/cambiaEstado (verificación de correlativo) que
// hacía Android en background — a confirmar si es necesario.
// ============================================================
function inyectarPedidoWebservice(array $user, array $cabecera, array $detalle): array {
    $GLOBALS['_soap_trace'] = [];
    $vendCod = $user['vend_cod'] ?? '';
    $neto    = (int)($cabecera['pedi_total_neto'] ?? 0);
    $iva     = (int)($cabecera['pedi_total_iva']  ?? 0);
    $total   = (int)($cabecera['pedi_total']      ?? 0);

    // 1) ingresarPedidoCabecera
    $respCab = llamarSoapWS('ingresarPedidoCabecera', [
        'PEDVENRUT'    => $vendCod,
        'PEDVENSEC'    => '0',
        'PEDCLIRUT'    => $cabecera['cli_rut'] ?? '',
        'PEDCLISEC'    => $cabecera['cli_sec'] ?? '',
        'PEDVIADES'    => $cabecera['tran_nombre'] ?? '',
        'PEDCONVEN'    => $cabecera['cond_codigo'] ?? '',
        'PEDSUBTOT'    => $neto,
        'PEDNET'       => $neto,
        'PEDIVA'       => $iva,
        'PEDTOT'       => $total,
        'PEDOBS1'      => $cabecera['pedi_observaciones'] ?? '',
        'PEDOBS2'      => $cabecera['pedi_orden_compra']  ?? '',
        'PEDENCDC1'    => '0',
        'PEDENCDP1'    => '0',
        'PEDENCDV1'    => '0',
        'PEDCLIDESDIR' => $cabecera['cli_dir'] ?? '',
        'PEDCLIDESCOM' => $cabecera['cli_com'] ?? '',
        'PEDCLIDESCIU' => $cabecera['cli_ciu'] ?? '',
        'PEDEMP'       => 'AUTOTEC',
        'PEDTRAN'      => $cabecera['tran_nombre'] ?? '',
        'PEDESTPRO'    => '2',
    ]);

    if ($respCab === null) {
        return ['ok' => false, 'msg' => 'No se pudo contactar el webservice (ingresarPedidoCabecera)', 'trace' => $GLOBALS['_soap_trace']];
    }

    // 2) obtenerUltimoCorrelativo
    $respCorr    = llamarSoapWS('obtenerUltimoCorrelativo', [
        'PEDVENRUT' => $vendCod,
        'PEDVENSEC' => '0',
    ]);
    $correlativo = extraerPrimerValorSoap($respCorr) ?? '1';

    // 3) ingresarPedidoDetalle — uno por cada producto, usando el correlativo
    $itemsOk = 0;
    foreach ($detalle as $d) {
        $cant      = (int)$d['ped_prod_cantidad'];
        $precio    = (int)$d['ped_prod_neto'];
        $dscto     = (float)$d['ped_prod_dscto'];
        $totalItem = $precio * $cant;
        $totalDesc = (int)round($totalItem * $dscto / 100);
        // Android manda el código de producto sin el guion (ej: "24161-K" → "24161K")
        $prodCodigo = str_replace('-', '', $d['prod_id']);

        $respDet = llamarSoapWS('ingresarPedidoDetalle', [
            'PEDVENRUT'  => $vendCod,
            'PEDVENSEC'  => '0',
            'PEDCORINT'  => $correlativo,
            'PRDCODIGO'  => $prodCodigo,
            'PEDDETCAN'  => $cant,
            'PEDDETPRE'  => $precio,
            'PEDDETDES1' => '0',
            'PEDDETDEP1' => $dscto,
            'PEDDETDEV1' => $totalDesc,
            'PEDDETTOT'  => $totalItem - $totalDesc,
            'PEDDETNET'  => $totalItem,
            'PEDDETPOR'  => '0',
        ]);
        if ($respDet !== null) $itemsOk++;
    }

    // 4) verificaItems — compara cuántos ítems quedaron realmente insertados
    $respVerif = llamarSoapWS('verificaItems', [
        'PEDVENRUT' => $vendCod,
        'PEDVENSEC' => '0',
        'PEDCORINT' => $correlativo,
    ]);
    $contadorWs = extraerPrimerValorSoap($respVerif) ?? '1';

    if ((string)$contadorWs !== (string)count($detalle)) {
        return [
            'ok'  => false,
            'msg' => "Los ítems no coinciden (esperados " . count($detalle) . ", confirmados por el servidor $contadorWs) — el pedido NO quedó cerrado en el ERP. Correlativo: $correlativo",
            'trace' => $GLOBALS['_soap_trace'],
        ];
    }

    // 5) cambiaEstado — recién esto cierra el pedido en el ERP
    $respCambio = llamarSoapWS('cambiaEstado', [
        'PEDVENRUT' => $vendCod,
        'PEDVENSEC' => '0',
        'PEDCORINT' => $correlativo,
    ]);

    if ($respCambio === null) {
        return ['ok' => false, 'msg' => "Se insertaron los ítems pero falló el cierre final (cambiaEstado). Correlativo: $correlativo", 'trace' => $GLOBALS['_soap_trace']];
    }

    return [
        'ok'  => true,
        'msg' => "Pedido inyectado y cerrado correctamente en el ERP (correlativo $correlativo, $itemsOk de " . count($detalle) . " ítems)",
        'trace' => $GLOBALS['_soap_trace'],
    ];
}

// ============================================================
// GET ?action=ver — Ver carro actual
// ============================================================
if ($action === 'ver' && $method === 'GET') {
    $pediIdParam      = (int)($_GET['pedi_id']        ?? 0);
    $pediIdSesion     = (int)($_GET['pedi_id_sesion'] ?? 0);

    if ($pediIdParam) {
        // Ver pedido específico desde Mis Pedidos
        $pediId = $pediIdParam;
    } elseif ($pediIdSesion) {
        // Ver carro de la sesión actual — verificar que existe y pertenece al usuario
        $chk = $pdo->prepare("SELECT pedi_id FROM web_carro_cabecera WHERE pedi_id=? AND usuario_id=?");
        $chk->execute([$pediIdSesion, $user['id']]);
        $row = $chk->fetch();
        $pediId = $row ? (int)$row['pedi_id'] : 0;
    } else {
        // Sin pedi_id: devolver carro vacío sin crear nada
        $pediId = 0;
    }

    if (!$pediId) {
        // Carro vacío — no crear ningún registro
        jsonResponse(true, ['cabecera' => null, 'detalle' => []]);
    }

    // Recalcular totales antes de mostrar
    recalcularTotales($pdo, $pediId);

    $cab = $pdo->prepare("SELECT * FROM web_carro_cabecera WHERE pedi_id=?");
    $cab->execute([$pediId]);
    $cabecera = $cab->fetch();

    $det = $pdo->prepare("
        SELECT d.*, p.prod_nombre, p.codigo_equivalente1, p.cla_id, p.prod_desc,
               mp.marca_nombre, i.img_media AS img_chica
        FROM web_carro_detalle d
        JOIN tbl_productos p ON d.prod_id = p.prod_id
        LEFT JOIN tbl_marcas_productos mp ON p.marca_id = mp.marca_id
        LEFT JOIN tbl_img_productos i ON p.prod_id = i.prod_id AND i.img_orden = 1
        WHERE d.pedi_id = ?
        ORDER BY d.id
    ");
    $det->execute([$pediId]);
    $detalle = $det->fetchAll();

    jsonResponse(true, ['cabecera' => $cabecera, 'detalle' => $detalle]);
}

// ============================================================
// POST ?action=agregar — Agregar producto al carro
// ============================================================
if ($action === 'agregar' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $prod_id  = trim($body['prod_id'] ?? '');
    $cantidad = max(1, (int)($body['cantidad'] ?? 1));
    $dscto    = max(0, min(100, (float)($body['dscto'] ?? 0)));

    if (!$prod_id) jsonResponse(false, null, 'Falta prod_id');

    // Obtener precio del producto
    $stmt = $pdo->prepare("SELECT p.prod_precio, p.prod_desc, mu.multiplo FROM tbl_productos p LEFT JOIN tbl_productos_multiplos mu ON p.prod_id = mu.id_prod WHERE p.prod_id=? LIMIT 1");
    $stmt->execute([$prod_id]);
    $prod = $stmt->fetch();
    if (!$prod) jsonResponse(false, null, 'Producto no encontrado');

    $precio_neto = (int)$prod['prod_precio'];
    // Si prod_desc=1 el producto no permite descuento
    if ((int)$prod['prod_desc'] === 1) $dscto = 0;

    // Obtener cliente y pedi_id de sesión del body
    $cli_rut       = trim($body['cli_rut']        ?? '');
    $cli_sec       = trim($body['cli_sec']         ?? '');
    $pediIdSesion  = (int)($body['pedi_id_sesion'] ?? 0);

    // Usar carro de sesión si existe y corresponde, sino crear uno nuevo
    $pediId = getCarroActivo($pdo, $user['id'], $cli_rut, $cli_sec, $pediIdSesion);

    // ¿Ya existe en el carro?
    $ex = $pdo->prepare("SELECT id, ped_prod_cantidad FROM web_carro_detalle WHERE pedi_id=? AND prod_id=?");
    $ex->execute([$pediId, $prod_id]);
    $existe = $ex->fetch();

    $forzar = (bool)($body['forzar'] ?? false);

    if ($existe) {
        if (!$forzar) {
            // Notificar al cliente que ya existe — NO agregar aún
            jsonResponse(true, [
                'ya_existe'       => true,
                'pedi_id'         => $pediId,
                'cantidad_actual' => (int)$existe['ped_prod_cantidad'],
                'item_id'         => (int)$existe['id'],
            ], 'Producto ya está en el carro');
        }
        // Con forzar=true: reemplazar cantidad
        $pdo->prepare("UPDATE web_carro_detalle SET ped_prod_cantidad=?, ped_prod_neto=?, ped_prod_dscto=? WHERE id=?")
            ->execute([$cantidad, $precio_neto, $dscto, $existe['id']]);
    } else {
        $pdo->prepare("INSERT INTO web_carro_detalle (pedi_id, prod_id, ped_prod_cantidad, ped_prod_neto, ped_prod_dscto) VALUES (?,?,?,?,?)")
            ->execute([$pediId, $prod_id, $cantidad, $precio_neto, $dscto]);
    }

    recalcularTotales($pdo, $pediId);
    jsonResponse(true, ['ya_existe' => false, 'pedi_id' => $pediId], 'Producto agregado');
}

// ============================================================
// POST ?action=actualizar — Actualizar cantidad/descuento
// ============================================================
if ($action === 'actualizar' && $method === 'POST') {
    $body     = json_decode(file_get_contents('php://input'), true);
    $item_id  = (int)($body['item_id'] ?? 0);
    $cantidad = max(0, (int)($body['cantidad'] ?? 1));
    $dscto    = max(0, min(100, (float)($body['dscto'] ?? 0)));

    // Verificar si el producto permite descuento
    $chkProd = $pdo->prepare("SELECT p.prod_desc FROM web_carro_detalle d JOIN tbl_productos p ON d.prod_id = p.prod_id WHERE d.id = ?");
    $chkProd->execute([$item_id]);
    $chkRow = $chkProd->fetch();
    if ($chkRow && (int)$chkRow['prod_desc'] === 1) $dscto = 0;

    if (!$item_id) jsonResponse(false, null, 'Falta item_id');

    // Obtener el pedi_id real dueño de este item, verificando que pertenece al usuario
    // (en vez de getCarroActivo(), que sin pedi_id_sesion crearía un carro nuevo vacío)
    $own = $pdo->prepare("
        SELECT d.pedi_id FROM web_carro_detalle d
        JOIN web_carro_cabecera c ON c.pedi_id = d.pedi_id
        WHERE d.id = ? AND c.usuario_id = ?
    ");
    $own->execute([$item_id, $user['id']]);
    $ownRow = $own->fetch();
    if (!$ownRow) jsonResponse(false, null, 'Item no encontrado');
    $pediId = (int)$ownRow['pedi_id'];

    if ($cantidad === 0) {
        $pdo->prepare("DELETE FROM web_carro_detalle WHERE id=?")->execute([$item_id]);
    } else {
        $pdo->prepare("UPDATE web_carro_detalle SET ped_prod_cantidad=?, ped_prod_dscto=? WHERE id=?")
            ->execute([$cantidad, $dscto, $item_id]);
    }

    recalcularTotales($pdo, $pediId);
    jsonResponse(true, null, 'Actualizado');
}

// ============================================================
// POST ?action=aplicar_descuento_general — aplica un % de descuento a
// TODOS los ítems del carro que lo permitan (replica EditPorcentajes +
// ActualizaDetalleCarro2 de Carro.java en Android)
// ============================================================
if ($action === 'aplicar_descuento_general' && $method === 'POST') {
    $body         = json_decode(file_get_contents('php://input'), true);
    $pediIdSesion = (int)($body['pedi_id_sesion'] ?? 0);
    $dscto        = (float)($body['dscto'] ?? 0);

    if (!$pediIdSesion) jsonResponse(false, null, 'No hay un carro activo en la sesión');
    if ($dscto < 0 || $dscto > 20) jsonResponse(false, null, 'El descuento no puede ser superior al 20%');

    $chk = $pdo->prepare("SELECT pedi_id FROM web_carro_cabecera WHERE pedi_id=? AND usuario_id=?");
    $chk->execute([$pediIdSesion, $user['id']]);
    if (!$chk->fetch()) jsonResponse(false, null, 'Carro no encontrado');

    // Igual que Android: no se aplica a productos sin descuento permitido
    // (prod_desc=1), a 3 códigos específicos, ni a la categoría cla_id=12.
    $stmt = $pdo->prepare("
        UPDATE web_carro_detalle d
        JOIN tbl_productos p ON d.prod_id = p.prod_id
        SET d.ped_prod_dscto = ?
        WHERE d.pedi_id = ?
          AND IFNULL(p.prod_desc, 0) = 0
          AND d.prod_id NOT IN ('23874-0','23230-0','23228-9')
          AND (p.cla_id IS NULL OR p.cla_id <> 12)
    ");
    $stmt->execute([$dscto, $pediIdSesion]);
    $afectados = $stmt->rowCount();

    recalcularTotales($pdo, $pediIdSesion);
    jsonResponse(true, ['afectados' => $afectados], "Descuento aplicado a $afectados producto" . ($afectados == 1 ? '' : 's'));
}

// ============================================================
// POST ?action=vaciar — Vaciar carro completo
// ============================================================
if ($action === 'vaciar' && $method === 'POST') {
    $body         = json_decode(file_get_contents('php://input'), true);
    $pediIdSesion = (int)($body['pedi_id_sesion'] ?? 0);
    if (!$pediIdSesion) jsonResponse(false, null, 'No hay un carro activo en la sesión');

    $chk = $pdo->prepare("SELECT pedi_id FROM web_carro_cabecera WHERE pedi_id=? AND usuario_id=?");
    $chk->execute([$pediIdSesion, $user['id']]);
    if (!$chk->fetch()) jsonResponse(false, null, 'Carro no encontrado');

    $pdo->prepare("DELETE FROM web_carro_detalle WHERE pedi_id=?")->execute([$pediIdSesion]);
    recalcularTotales($pdo, $pediIdSesion);
    jsonResponse(true, null, 'Carro vaciado');
}

// ============================================================
// POST ?action=confirmar — Confirmar pedido (cambiar estado)
// ============================================================
if ($action === 'confirmar' && $method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    $pediIdSesion = (int)($body['pedi_id_sesion'] ?? 0);
    if (!$pediIdSesion) jsonResponse(false, null, 'No hay un carro activo en la sesión');

    $chk = $pdo->prepare("SELECT pedi_id FROM web_carro_cabecera WHERE pedi_id=? AND usuario_id=?");
    $chk->execute([$pediIdSesion, $user['id']]);
    if (!$chk->fetch()) jsonResponse(false, null, 'Carro no encontrado');
    $pediId = $pediIdSesion;

    // Verificar que hay items
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM web_carro_detalle WHERE pedi_id=?");
    $cnt->execute([$pediId]);
    if ($cnt->fetchColumn() == 0) jsonResponse(false, null, 'El carro está vacío');

    $obs       = trim($body['observaciones']  ?? '');
    $oc        = trim($body['orden_compra']   ?? '');
    $estado    = trim($body['estado']         ?? 'EN PROCESO');
    $tran_id   = trim($body['tran_id']        ?? '');
    $cond_id   = trim($body['cond_id']        ?? '');
    $forma_id  = trim($body['forma_id']       ?? '');
    $fecha_oc  = trim($body['pedi_fecha_oc']  ?? '');
    $fecha_oc  = $fecha_oc === '' ? null : $fecha_oc;

    // Validar estados permitidos (igual que Android)
    $estadosValidos = ['EN PROCESO','COTIZACION EN ESPERA','ENVIO MERCADOPAGO'];
    if (!in_array($estado, $estadosValidos)) $estado = 'EN PROCESO';

    $pdo->prepare("UPDATE web_carro_cabecera SET pedi_estado=?, pedi_observaciones=?, pedi_orden_compra=?, tran_id=?, cond_id=?, pedi_forma_pago=?, pedi_fecha_oc=?, pedi_vendedor=?, pedi_fecha=NOW() WHERE pedi_id=?")
        ->execute([$estado, $obs, $oc, $tran_id, $cond_id, $forma_id, $fecha_oc, $user['vend_cod'] ?? '', $pediId]);

    // Log
    $pdo->prepare("INSERT INTO web_pedidos_log (pedi_id, usuario_id, accion) VALUES (?,?,'confirmado')")
        ->execute([$pediId, $user['id']]);

    // Recargar cabecera + detalle con datos de cliente/producto para correo/WS
    $cabStmt = $pdo->prepare("
        SELECT c.*, cl.CLINOMBRE AS cli_razon_social, cl.CLIDIR AS cli_dir,
               cl.CLICOM AS cli_com, cl.CLICIU AS cli_ciu,
               t.tran_nombre AS tran_nombre, cp.cond_codigo AS cond_codigo
        FROM web_carro_cabecera c
        LEFT JOIN (
            SELECT CLIRUT, MIN(CLINOMBRE) AS CLINOMBRE, MIN(CLIDIR) AS CLIDIR,
                   MIN(CLICOM) AS CLICOM, MIN(CLICIU) AS CLICIU
            FROM bd_autotec.tbl_clientes GROUP BY CLIRUT
        ) cl ON c.cli_rut = cl.CLIRUT
        LEFT JOIN tbl_transportes t ON c.tran_id = t.tran_id
        LEFT JOIN tbl_condicion_pago cp ON c.cond_id = cp.cond_id
        WHERE c.pedi_id = ?
    ");
    $cabStmt->execute([$pediId]);
    $cabecera = $cabStmt->fetch();

    $detStmt = $pdo->prepare("
        SELECT d.*, p.prod_nombre, p.codigo_equivalente1
        FROM web_carro_detalle d
        JOIN tbl_productos p ON d.prod_id = p.prod_id
        WHERE d.pedi_id = ?
        ORDER BY d.id
    ");
    $detStmt->execute([$pediId]);
    $detalle = $detStmt->fetchAll();

    $extra = [];

    // ── COTIZACIÓN: enviar correo al vendedor + cliente (igual que Persistencia_Correo.java / VerCarro.java) ──
    if ($estado === 'COTIZACION EN ESPERA') {
        $emailVendedor = trim($user['email'] ?? '');
        $emailCliente  = trim($body['email_destino'] ?? '');

        $destinatarios = array_filter([$emailVendedor, $emailCliente]);
        if (!$destinatarios) {
            $extra['correo'] = ['ok' => false, 'msg' => 'No hay correo de vendedor ni de cliente para enviar la cotización'];
        } else {
            try {
                $cuerpo  = construirCuerpoCotizacion($pediId, $cabecera, $detalle);
                $asunto  = "Cotización N° $pediId - VENDEDOR: " . ($user['nombre'] ?? '');
                $extra['correo'] = enviarCorreoSMTP(implode(',', $destinatarios), $asunto, $cuerpo);
            } catch (Exception $e) {
                error_log('[confirmar cotizacion] ' . $e->getMessage());
                $extra['correo'] = ['ok' => false, 'msg' => 'Error al construir/enviar el correo'];
            }
        }

        // En la web el envío es inmediato (no hay servicio en background como en
        // Android), así que si el correo salió bien, el estado refleja eso mismo.
        // Si falló, queda "EN ESPERA" para reintentar más tarde.
        if (!empty($extra['correo']['ok'])) {
            $estado = 'COTIZACION ENVIADA';
            $pdo->prepare("UPDATE web_carro_cabecera SET pedi_estado=? WHERE pedi_id=?")
                ->execute([$estado, $pediId]);
        }
    }

    // ── PEDIDO EN PROCESO: inyectar al webservice (igual que Persistencia.java) ──
    if ($estado === 'EN PROCESO') {
        try {
            $extra['webservice'] = inyectarPedidoWebservice($user, $cabecera, $detalle);
        } catch (Exception $e) {
            error_log('[confirmar ws] ' . $e->getMessage());
            $extra['webservice'] = ['ok' => false, 'msg' => 'Error al inyectar el pedido al webservice'];
        }

        // En la web el envío al ERP es inmediato (no hay servicio en background
        // como en Android que revisa cada minuto), así que si la inyección se
        // completó y cerró bien (cambiaEstado incluido), el pedido ya quedó
        // efectivamente entregado al sistema central.
        if (!empty($extra['webservice']['ok'])) {
            $estado = 'ENTREGADO';
            $pdo->prepare("UPDATE web_carro_cabecera SET pedi_estado=? WHERE pedi_id=?")
                ->execute([$estado, $pediId]);
        }

        // Correo de confirmación de pedido (igual que Persistencia.java: se envía
        // a la casilla de pedidos + el vendedor, además del webservice)
        // NOTA: envío desactivado temporalmente vía ENVIAR_CORREO_PEDIDO_CONFIRMADO
        if (!empty($extra['webservice']['ok']) && ENVIAR_CORREO_PEDIDO_CONFIRMADO) {
            $emailVendedor = trim($user['email'] ?? '');
            $destinatarios = array_filter(['pedidos_tablet_autotec@holdingautomarco.com', $emailVendedor]);
            try {
                $cuerpo = construirCuerpoPedido($pediId, $cabecera, $detalle);
                $asunto = "Detalle Pedido Tablet N° $pediId - VENDEDOR: " . ($user['nombre'] ?? '');
                $extra['correo'] = enviarCorreoSMTP(implode(',', $destinatarios), $asunto, $cuerpo);
            } catch (Exception $e) {
                error_log('[confirmar pedido correo] ' . $e->getMessage());
                $extra['correo'] = ['ok' => false, 'msg' => 'Error al construir/enviar el correo de confirmación'];
            }
        }
    }

    $msgFinal = "Pedido #$pediId confirmado exitosamente";
    if ($estado === 'COTIZACION ENVIADA') {
        $msgFinal = "Cotización #$pediId enviada por correo";
    } elseif ($estado === 'COTIZACION EN ESPERA') {
        $msgFinal = "Cotización #$pediId guardada, pendiente de envío por correo";
    } elseif ($estado === 'ENTREGADO') {
        $msgFinal = "Pedido #$pediId entregado al sistema central exitosamente";
    } elseif ($estado === 'EN PROCESO') {
        $msgFinal = "Pedido #$pediId guardado, pero no se pudo confirmar la entrega al sistema central";
    }

    jsonResponse(true, ['pedi_id' => $pediId, 'estado' => $estado] + $extra, $msgFinal);
}

// ============================================================
// GET ?action=historial — Pedidos anteriores del usuario
// ============================================================
if ($action === 'historial' && $method === 'GET') {
    $stmt = $pdo->prepare("
        SELECT c.*, COUNT(d.id) AS items, c.pedi_total
        FROM web_carro_cabecera c
        LEFT JOIN web_carro_detalle d ON c.pedi_id = d.pedi_id
        WHERE c.usuario_id = ? AND c.pedi_estado <> 'INCOMPLETA'
        GROUP BY c.pedi_id
        ORDER BY c.pedi_fecha DESC
        LIMIT 50
    ");
    $stmt->execute([$user['id']]);
    jsonResponse(true, $stmt->fetchAll());
}

// ============================================================
// POST ?action=recuperar — Crear carro nuevo desde carro pendiente (legacy)
// ============================================================
if ($action === 'recuperar' && $method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true);
    $cliRut = trim($body['cli_rut'] ?? '');
    $cliSec = trim($body['cli_sec'] ?? '');
    $items  = $body['items'] ?? [];

    if (!$cliRut) jsonResponse(false, null, 'Falta cli_rut');
    if (!is_array($items) || !count($items)) jsonResponse(false, null, 'El carro pendiente no tiene productos');

    // Crear cabecera nueva — representa el carro recién recuperado
    $ins = $pdo->prepare("INSERT INTO web_carro_cabecera (usuario_id, cli_rut, cli_sec, emp_id, pedi_vendedor, pedi_estado) VALUES (?,?,?,?,?,?)");
    $ins->execute([$user['id'], $cliRut, $cliSec, EMP_ID, $user['vend_cod'] ?? '', 'INCOMPLETA']);
    $pediId = (int)$pdo->lastInsertId();

    $insertados = 0;
    foreach ($items as $it) {
        $prodId   = trim($it['prod_id'] ?? '');
        $cantidad = max(1, (int)($it['cantidad'] ?? 1));
        $precio   = (int)($it['precio'] ?? 0);
        $dscto    = max(0, min(100, (float)($it['descuento'] ?? 0)));
        if (!$prodId) continue;

        // Respetar productos que no permiten descuento
        $chk = $pdo->prepare("SELECT prod_desc FROM tbl_productos WHERE prod_id=? LIMIT 1");
        $chk->execute([$prodId]);
        $prodDesc = (int)$chk->fetchColumn();
        if ($prodDesc === 1) $dscto = 0;

        $pdo->prepare("INSERT INTO web_carro_detalle (pedi_id, prod_id, ped_prod_cantidad, ped_prod_neto, ped_prod_dscto) VALUES (?,?,?,?,?)")
            ->execute([$pediId, $prodId, $cantidad, $precio, $dscto]);
        $insertados++;
    }

    if ($insertados === 0) {
        // No se pudo insertar ningún producto válido — eliminar cabecera vacía
        $pdo->prepare("DELETE FROM web_carro_cabecera WHERE pedi_id=?")->execute([$pediId]);
        jsonResponse(false, null, 'No se pudo recuperar ningún producto del carro');
    }

    recalcularTotales($pdo, $pediId);
    jsonResponse(true, ['pedi_id' => $pediId, 'items_insertados' => $insertados], 'Carro recuperado');
}

// ============================================================
// GET ?action=nombres&ids=1,2,3 — Nombres de productos (para preview de carro pendiente)
// ============================================================
if ($action === 'nombres' && $method === 'GET') {
    $idsParam = trim($_GET['ids'] ?? '');
    if (!$idsParam) jsonResponse(true, []);
    $ids = array_filter(array_map('trim', explode(',', $idsParam)));
    if (!$ids) jsonResponse(true, []);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT prod_id, prod_nombre FROM tbl_productos WHERE prod_id IN ($placeholders)");
    $stmt->execute(array_values($ids));
    $map = [];
    foreach ($stmt->fetchAll() as $row) $map[$row['prod_id']] = $row['prod_nombre'];
    jsonResponse(true, $map);
}

// ============================================================
// GET ?action=formas_pago&cli_rut=X&cli_sec=Y
// Replica ShppingCart.SpinnerFormapago2() de Android:
// determina qué formas de pago mostrar según la condición
// de pago propia del cliente (tbl_clientes.cli_cvcod →
// tbl_condicion_pago.cond_codigo).
// ============================================================
if ($action === 'formas_pago' && $method === 'GET') {
    $cliRut = trim($_GET['cli_rut'] ?? $_GET['rut'] ?? '');
    $cliSec = trim($_GET['cli_sec'] ?? $_GET['sec'] ?? '');

    $formaId = '';
    $condId  = '';
    $f       = '1,2,3'; // valor por defecto de Android cuando el cliente no tiene condición asignada
    $otro    = false;

    if ($cliRut) {
        try {
            $stmt = $pdo->prepare("
                SELECT a.forma_id, a.cond_id
                FROM tbl_condicion_pago a
                JOIN bd_autotec.tbl_clientes b ON b.CLICVCOD = a.cond_codigo
                WHERE b.CLIRUT = ? AND b.CLISEC = ? AND a.cond_estado = 1
                LIMIT 1
            ");
            $stmt->execute([$cliRut, $cliSec]);
            $row = $stmt->fetch();
            if ($row) {
                $formaId = (string)$row['forma_id'];
                $condId  = (string)$row['cond_id'];
                $f = '0';
            }
        } catch (PDOException $e) {
            error_log('[carro.php formas_pago] ' . $e->getMessage());
            // Ante error de consulta, seguir con el valor por defecto (1,2,3)
        }
    }

    if ($formaId === '1') $f = '1';
    if ($formaId === '2') $f = '2';
    if ($formaId === '3') $f = '3';
    if ($condId === '1' || $condId === '2') $f = '1,3';
    if (in_array($formaId, ['6','7','8','9','10'], true)) { $f = $formaId; $otro = true; }

    $fIds = array_filter(array_map('intval', explode(',', $f)));
    if (!$fIds) $fIds = [0];
    $fPlaceholders = implode(',', $fIds);

    if ($otro) {
        $sql = "SELECT forma_id, forma_nombre FROM tbl_formas_pago
                WHERE forma_estado = 1 AND forma_id IN ($fPlaceholders)
                ORDER BY forma_nombre ASC";
    } else {
        $sql = "SELECT forma_id, forma_nombre FROM tbl_formas_pago
                WHERE forma_estado = 1 AND forma_id IN ($fPlaceholders,4,5) AND forma_id <= 5
                ORDER BY forma_nombre ASC";
    }
    $formas = $pdo->query($sql)->fetchAll();

    jsonResponse(true, [
        'formas'         => $formas,
        'forma_default'  => $formaId, // forma_id propia del cliente, para pre-seleccionar
        'cond_default'   => $condId,  // cond_id propio del cliente, para pre-seleccionar en plazos
    ]);
}

// ============================================================
// GET ?action=plazos&forma_id=X
// Replica ShppingCart.SpinnerPlazos(): condiciones de pago
// disponibles para la forma de pago seleccionada, incluyendo
// el monto de la condición (para el aviso de crédito).
// ============================================================
if ($action === 'plazos' && $method === 'GET') {
    $formaId = trim($_GET['forma_id'] ?? '');
    if (!$formaId) jsonResponse(false, null, 'Falta forma_id');

    $stmt = $pdo->prepare("
        SELECT cond_id, cond_descripcion, cond_monto
        FROM tbl_condicion_pago
        WHERE forma_id = ? AND cond_estado = 1
        ORDER BY cond_descripcion ASC
    ");
    $stmt->execute([$formaId]);
    jsonResponse(true, $stmt->fetchAll());
}

// ============================================================
// GET ?action=transportes
// Replica ShppingCart.SpinnerTransporte()
// ============================================================
if ($action === 'transportes' && $method === 'GET') {
    $rows = $pdo->query("
        SELECT tran_id, tran_nombre
        FROM tbl_transportes
        WHERE tran_estado = 1
        ORDER BY tran_nombre ASC
    ")->fetchAll();
    jsonResponse(true, $rows);
}

jsonResponse(false, null, 'Acción no válida');