<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

/**
 * Extrae el indicador de cuota desde la glosa del ERP (Softland).
 * Cada fila de tbl_cobranza representa UNA CUOTA de un documento,
 * no el documento completo. La glosa indica la cuota: "Pedido 440042 2/3"
 *
 * Retorna "2/3" si hay patrón N/M al final, o null si es pago único.
 */
function _parseCuotaLabel(string $glosa): ?string {
    if (preg_match('/(\d+\/\d+)\s*$/', $glosa, $m)) {
        return $m[1];
    }
    return null;
}

try {
    $pdo = Database::getCobranzasConnection();

    $rut_param = $_GET['rut_cliente'] ?? filter_input(INPUT_GET, 'rut_cliente', FILTER_SANITIZE_SPECIAL_CHARS);

    if (!$rut_param) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Parámetro rut_cliente es obligatorio']);
        exit;
    }

    // Extraer solo la parte numérica del RUT si viene con dígito verificador
    $clirut = explode('-', $rut_param)[0];
    $clirut = preg_replace('/[^0-9]/', '', $clirut);

    if (empty($clirut)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Formato de RUT no válido']);
        exit;
    }

    // Mapa de empresas del holding (Código -> ID Central y Nombre)
    $empresaMap = [
        'EMP01' => ['id' => 1, 'nombre' => 'Automarco LTDA'],
        'EMP06' => ['id' => 2, 'nombre' => 'HD Automarco S.A'],
        'EMP03' => ['id' => 3, 'nombre' => 'Autotec S.A'],
        'EMP10' => ['id' => 4, 'nombre' => 'Gabtec S.A']
    ];

    $sql = "
        SELECT 
            c.empresa AS codigo_empresa,
            c.docto AS numero_factura,
            c.emision AS fecha_emision,
            c.vencto AS fecha_vencimiento,
            c.glosa,
            CAST(c.total_cuota AS DECIMAL(12,0)) AS total_cuota,
            CAST(c.saldo_cuota AS DECIMAL(12,0)) AS saldo_cuota,
            c.tipo_doc
        FROM bd_automarco.tbl_cobranza c
        WHERE c.clirut = :clirut
          AND c.empresa != 'EMP07'
        ORDER BY c.vencto ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':clirut' => $clirut]);
    $rawFacturas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener cuotas actualmente en proceso de cobranza activa (estados != RECHAZADO)
    // para no mostrarlas duplicadas al vendedor mientras se gestionan.
    $stmtEnProceso = $pdo->prepare("
        SELECT cf.codigo_empresa, cf.numero_factura, cf.saldo_cuota
        FROM cobranza_facturas cf
        INNER JOIN cobranzas c ON cf.cobranza_id = c.id
        WHERE c.estado != 'RECHAZADO'
    ");
    $stmtEnProceso->execute();
    $enProceso = $stmtEnProceso->fetchAll(PDO::FETCH_ASSOC);

    // Contador de ocurrencias ocupadas por (empresa + doc + saldo)
    $ocupadasCount = [];
    foreach ($enProceso as $op) {
        $k = trim($op['codigo_empresa']) . '_' . trim($op['numero_factura']) . '_' . (int)round((float)$op['saldo_cuota']);
        $ocupadasCount[$k] = ($ocupadasCount[$k] ?? 0) + 1;
    }

    $total_deuda = 0;
    $facturas = [];

    foreach ($rawFacturas as $f) {
        $codEmp  = trim($f['codigo_empresa']);
        $numDoc  = trim($f['numero_factura']);
        $saldo   = (float)$f['saldo_cuota'];
        $key     = $codEmp . '_' . $numDoc . '_' . (int)round($saldo);

        // Si esta cuota específica ya está en una cobranza activa, omitirla
        if (!empty($ocupadasCount[$key]) && $ocupadasCount[$key] > 0) {
            $ocupadasCount[$key]--;
            continue;
        }

        $empInfo = $empresaMap[$codEmp] ?? ['id' => 1, 'nombre' => 'Empresa No Especificada'];
        $glosa   = trim($f['glosa']);
        $total_deuda += $saldo;

        $facturas[] = [
            'codigo_empresa'    => $codEmp,
            'empresa_id'        => $empInfo['id'],
            'empresa_nombre'    => $empInfo['nombre'],
            'numero_factura'    => trim($f['numero_factura']),
            'fecha_emision'     => trim($f['fecha_emision']),
            'fecha_vencimiento' => trim($f['fecha_vencimiento']),
            'glosa'             => $glosa,
            'cuota_label'       => _parseCuotaLabel($glosa), // "1/3", "2/3", etc. — null si pago único
            'total_cuota'       => (float)$f['total_cuota'],
            'saldo_cuota'       => $saldo,
            'tipo_doc'          => (int)$f['tipo_doc']
        ];
    }

    echo json_encode([
        'success'     => true,
        'clirut'      => $clirut,
        'count'       => count($facturas),
        'total_deuda' => $total_deuda,
        'data'        => $facturas
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar facturas: ' . $e->getMessage()]);
}
