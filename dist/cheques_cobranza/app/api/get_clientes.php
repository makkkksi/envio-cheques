<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

try {
    $pdo = Database::getCobranzasConnection();

    // Validar autenticación
    $usuario_actual = getUsuarioActual(); // Exige token o sesión de vendedor válida
    
    // --- [SEC-01] Blindaje IDOR: En producción, identidad SOLO desde sesión ---
    if (defined('APP_ENV') && APP_ENV === 'production') {
        if (empty($_SESSION['vendedor_auth']['vendedor_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Sesión de vendedor no iniciada. Acceso denegado.']);
            exit;
        }
        $vendedor_id = (int) $_SESSION['vendedor_auth']['vendedor_id'];
        $empresa_param = $_SESSION['vendedor_auth']['empresa_origen'] ?? '';
        $sellerName = $_SESSION['vendedor_auth']['nombre'] ?? ('Vendedor ID ' . $vendedor_id);
    } else {
        // Local: permitir fallback a GET para testing
        $vendedor_id_get = filter_input(INPUT_GET, 'vendedor_id', FILTER_VALIDATE_INT);
        $vendedor_id = $vendedor_id_get ?: ($_SESSION['vendedor_auth']['vendedor_id'] ?? $usuario_actual);
        $empresa_param = $_REQUEST['empresa'] ?? ($_SESSION['vendedor_auth']['empresa_origen'] ?? '');
        $sellerName = $_SESSION['vendedor_auth']['nombre'] ?? ('Vendedor ID ' . $vendedor_id);
    }

    // 1. Resolver la base de datos según la empresa
    $empresa_code = strtoupper(trim($empresa_param));
    $db_origen = '';
    
    if ($empresa_code === 'EMP01' || $empresa_code === 'AUTOMARCO') {
        $db_origen = 'automarc_automarco';
    } elseif ($empresa_code === 'EMP10' || $empresa_code === 'GABTEC') {
        $db_origen = 'gabteccl_sitbdd1978';
    } elseif ($empresa_code === 'EMP03' || $empresa_code === 'AUTOTEC') {
        $db_origen = 'autotec_ecom';
    } elseif ($empresa_code === 'EMP06' || $empresa_code === 'HD') {
        $db_origen = 'autohd_automarcohd';
    }

    if (empty($db_origen)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Falta o es invalida la empresa de origen para resolver la cartera.']);
        exit;
    }

    // 2. Extraer RUTs asignados al vendedor en su empresa
    $stmtRuts = $pdo->prepare("SELECT cli_rut FROM `{$db_origen}`.tbl_clientes WHERE cli_vendedor = :vid AND cli_rut != ''");
    $stmtRuts->execute([':vid' => $vendedor_id]);
    $rutRows = $stmtRuts->fetchAll(PDO::FETCH_ASSOC);

    $bodies = [];
    foreach ($rutRows as $row) {
        $parts = explode('-', trim($row['cli_rut']));
        if (is_numeric($parts[0])) {
            $bodies[] = (int)$parts[0];
        }
    }

    $bodies = array_unique($bodies);

    if (empty($bodies)) {
        // El vendedor no tiene clientes asignados en esta empresa
        echo json_encode([
            'success' => true,
            'vendedor_id' => $vendedor_id,
            'vendedor_nombre' => $sellerName,
            'count' => 0,
            'data' => []
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $inBodies = implode(',', $bodies);
    $whereVendedor = "c.clirut IN ($inBodies)";

    // Paso 1: Consultar clientes resumidos de tbl_cobranza (Ultrarrápido, ~20ms)
    $sqlCobranza = "
        SELECT 
            c.clirut,
            c.clidv,
            COUNT(*) AS total_facturas,
            SUM(CAST(c.saldo_cuota AS DECIMAL(15,2))) AS total_deuda
        FROM `bd_automarco`.`tbl_cobranza` c
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
            SELECT cli_rut, cli_razon_social, cli_mail FROM `automarc_automarco`.`tbl_clientes` WHERE cli_rut IN ($inClause)
            UNION
            SELECT cli_rut, cli_razon_social, cli_mail FROM `autotec_ecom`.`tbl_clientes` WHERE cli_rut IN ($inClause)
            UNION
            SELECT cli_rut, cli_razon_social, cli_mail FROM `autohd_automarcohd`.`tbl_clientes` WHERE cli_rut IN ($inClause)
            UNION
            SELECT cli_rut, cli_razon_social, cli_mail FROM `gabteccl_sitbdd1978`.`tbl_clientes` WHERE cli_rut IN ($inClause)
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
    error_log('[get_clientes.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al consultar los clientes.']);
}
