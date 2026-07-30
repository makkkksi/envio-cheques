<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

try {
    $pdo = Database::getCobranzasConnection();

    // Validar autenticación
    $usuario_actual = getUsuarioActual(); // Exige token o sesión de vendedor válida
    
    // Obtener parámetros de desambiguación (mantener compatibilidad)
    $vendedor_id_get = filter_input(INPUT_GET, 'vendedor_id', FILTER_VALIDATE_INT);
    $vendedor_id = $vendedor_id_get ?: $usuario_actual;

    $sellerEmails = [];
    $sellerName = '';

    // Si es un vendedor con sesión activa, confiamos plenamente en el email resuelto en el login
    if (isset($_SESSION['vendedor_auth']['email'])) {
        $sellerEmails = [trim($_SESSION['vendedor_auth']['email'])];
        $sellerName = $_SESSION['vendedor_auth']['nombre'] ?? '';
    } 
    else {
        // Fallback en caso de que sea el panel Admin consultando (el admin usa token, no sesión de vendedor)
        $vendedor_email_param = filter_input(INPUT_GET, 'vendedor_email', FILTER_SANITIZE_EMAIL);
        $empresa_param = filter_input(INPUT_GET, 'empresa', FILTER_DEFAULT);

        if ($vendedor_email_param) {
            $sellerEmails = [trim($vendedor_email_param)];
        } elseif ($empresa_param && $vendedor_id) {
            $empresa_code = strtoupper(trim($empresa_param));
            if ($empresa_code === 'EMP01' || $empresa_code === 'AUTOMARCO') {
                $stmt = $pdo->prepare("SELECT ven_mail, nombre_vendedor FROM automarc_automarco.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail != ''");
            } elseif ($empresa_code === 'EMP10' || $empresa_code === 'GABTEC') {
                $stmt = $pdo->prepare("SELECT ven_mail, nombre_vendedor FROM gabteccl_sitbdd1978.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail != ''");
            } elseif ($empresa_code === 'EMP03' || $empresa_code === 'AUTOTEC') {
                $stmt = $pdo->prepare("SELECT ven_mail, nombre_vendedor FROM autotec_ecom.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail != ''");
            } elseif ($empresa_code === 'EMP06' || $empresa_code === 'HD') {
                $stmt = $pdo->prepare("SELECT ven_mail, nombre_vendedor FROM autohd_automarcohd.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail != ''");
            }

            if (isset($stmt)) {
                $stmt->execute([':vid' => $vendedor_id]);
                $res = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($res) {
                    $sellerEmails = [trim($res['ven_mail'])];
                    $sellerName = trim($res['nombre_vendedor'] ?? '');
                }
            }
        }
        
        // CUIDADO: Se eliminó el UNION cross-db ciego porque causaba fuga de datos inter-empresa.
        // Si el admin no provee empresa ni email, intentaremos buscar de todas formas si estamos en local.
        if (empty($sellerEmails) && defined('APP_ENV') && APP_ENV === 'local') {
             $sellerEmails = ["dev_{$vendedor_id}@local.test"];
        }
    }

    // 2. Construir la consulta de clientes
    // Si encontramos emails homologados del vendedor, filtramos por todos sus IDs locales en las 4 empresas
    if (!empty($sellerEmails)) {
        $inMails = implode(',', array_map(function($i) use ($pdo) { return $pdo->quote($i); }, $sellerEmails));
        $whereVendedor = "
            (
                (c.empresa = 'EMP01' AND c.vendedor IN (SELECT cli_vendedor FROM automarc_automarco.tbl_vendedores WHERE TRIM(ven_mail) IN ($inMails))) OR
                (c.empresa = 'EMP10' AND c.vendedor IN (SELECT cli_vendedor FROM gabteccl_sitbdd1978.tbl_vendedores WHERE TRIM(ven_mail) IN ($inMails))) OR
                (c.empresa = 'EMP03' AND c.vendedor IN (SELECT cli_vendedor FROM autotec_ecom.tbl_vendedores WHERE TRIM(ven_mail) IN ($inMails))) OR
                (c.empresa = 'EMP06' AND c.vendedor IN (SELECT cli_vendedor FROM autohd_automarcohd.tbl_vendedores WHERE TRIM(ven_mail) IN ($inMails)))
            )
        ";
    } else {
        // Fallback por si el vendedor no tiene email registrado
        $whereVendedor = "c.vendedor = " . (int)$vendedor_id;
    }

    // Paso 1: Consultar clientes resumidos de tbl_cobranza (Ultrarrápido, ~20ms)
    $sqlCobranza = "
        SELECT 
            c.clirut,
            c.clidv,
            COUNT(*) AS total_facturas,
            SUM(CAST(c.saldo_cuota AS DECIMAL(15,2))) AS total_deuda
        FROM bd_automarco.tbl_cobranza c
        WHERE {$whereVendedor}
          AND c.empresa != 'EMP07'
          AND c.saldo_cuota > 0
        GROUP BY c.clirut, c.clidv
        ORDER BY total_deuda DESC
    ";

    $stmt = $pdo->query($sqlCobranza);
    $rawClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rawClients)) {
        echo json_encode([
            'success' => true,
            'vendedor_id' => $vendedor_id,
            'count' => 0,
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Paso 2: Buscar Razón Social y Email únicamente para los RUTs filtrados
    $rutList = array_map(function($c) { return "'" . $c['clirut'] . '-' . $c['clidv'] . "'"; }, $rawClients);
    $inClause = implode(',', $rutList);

    $mapNames = [];
    $mapMails = [];

    $sqlNames = "
        SELECT cli_rut, cli_razon_social, cli_mail FROM (
            SELECT cli_rut, cli_razon_social, cli_mail FROM automarc_automarco.tbl_clientes WHERE cli_rut IN ($inClause)
            UNION
            SELECT cli_rut, cli_razon_social, cli_mail FROM autotec_ecom.tbl_clientes WHERE cli_rut IN ($inClause)
            UNION
            SELECT cli_rut, cli_razon_social, cli_mail FROM autohd_automarcohd.tbl_clientes WHERE cli_rut IN ($inClause)
            UNION
            SELECT cli_rut, cli_razon_social, cli_mail FROM gabteccl_sitbdd1978.tbl_clientes WHERE cli_rut IN ($inClause)
        ) AS t
    ";
    
    try {
        $stmtNames = $pdo->query($sqlNames);
        while ($row = $stmtNames->fetch(PDO::FETCH_ASSOC)) {
            $cleanKey = explode('-', $row['cli_rut'])[0];
            if (!isset($mapNames[$cleanKey]) && !empty($row['cli_razon_social'])) {
                $mapNames[$cleanKey] = trim($row['cli_razon_social']);
            }
            if (!isset($mapMails[$cleanKey]) && !empty($row['cli_mail']) && $row['cli_mail'] !== '.') {
                $mapMails[$cleanKey] = trim($row['cli_mail']);
            }
        }
    } catch (Exception $e) {
        // Fallback en caso de error de lectura de nombres
    }

    $clientes = array_map(function($row) use ($mapNames, $mapMails) {
        $key = (string)$row['clirut'];
        $razonSocial = $mapNames[$key] ?? ('CLIENTE RUT ' . $row['clirut'] . '-' . $row['clidv']);
        $emailCliente = $mapMails[$key] ?? '';

        return [
            'rut_completo' => $row['clirut'] . '-' . $row['clidv'],
            'clirut' => (string)$row['clirut'],
            'clidv' => (string)$row['clidv'],
            'razon_social' => $razonSocial,
            'email_cliente' => $emailCliente,
            'total_facturas' => (int)$row['total_facturas'],
            'total_deuda' => (float)$row['total_deuda']
        ];
    }, $rawClients);

    echo json_encode([
        'success' => true,
        'vendedor_id' => $vendedor_id,
        'vendedor_nombre' => $sellerName ?: 'Vendedor ID ' . $vendedor_id,
        'count' => count($clientes),
        'data' => $clientes
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar clientes: ' . $e->getMessage()]);
}
