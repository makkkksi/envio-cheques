<?php
require_once __DIR__ . '/config.php';
$user   = requireAuth();
$action = $_GET['action'] ?? '';
$pdo    = getDB();

// Conexión a bd_automarco (donde está tbl_cobranza)
function getDBCobranza(): PDO {
    static $pdo2 = null;
    if ($pdo2 === null) {
        $dsn = "mysql:host=dbaws.automarco.cl;dbname=bd_automarco;charset=utf8mb4";
        try {
            $pdo2 = new PDO($dsn, 'admin2', 'auto.,2013', [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[Cobranza DB] ' . $e->getMessage());
            jsonResponse(false, null, 'Error conexión cobranza');
        }
    }
    return $pdo2;
}

function empresaLabel(string $emp): string {
    $map = [
        'EMP01' => 'Automarco', 'EMP03' => 'Autotec',
        'EMP06' => 'HD Automarco', 'EMP10' => 'Gabtec',
        'EMP24' => 'Top Repuestos',
    ];
    return $map[$emp] ?? $emp;
}

// Obtener RUTs de clientes del vendedor
function getRutsVendedor(PDO $pdo, string $vend_cod): string {
    $stmt = $pdo->prepare("
        SELECT CLIRUT FROM bd_autotec.tbl_clientes
        WHERE CLIVENCOD = ? OR CAST(CLIFAX AS SIGNED) = ?
    ");
    $stmt->execute([$vend_cod, $vend_cod]);
    $ruts = array_column($stmt->fetchAll(), 'CLIRUT');
    return $ruts ? implode("','", array_map('addslashes', $ruts)) : '';
}

// ============================================================
// GET ?action=buscar&rut=X&nombre=X&page=0
// ============================================================
if ($action === 'buscar') {
    $rut      = trim($_GET['rut']    ?? '');
    $nombre   = trim($_GET['nombre'] ?? '');
    $page     = max(0, (int)($_GET['page'] ?? 0));
    $limit    = 50;
    $offset   = $page * $limit;

    $vend_cod = $user['vend_cod'] ?? '';
    $es_admin = ($user['rol'] === 'admin');

    $pdoCob = getDBCobranza();

    // Obtener lista de RUTs del vendedor
    $rutsStr = '';
    if (!$es_admin && $vend_cod !== '') {
        $rutsStr = getRutsVendedor($pdo, $vend_cod);
        if (!$rutsStr) jsonResponse(true, ['cobranza' => [], 'total' => 0, 'page' => 0]);
    }

    $where  = "tipo_doc IN (1,4,5,7)";
    $params = [];

    if ($rutsStr) {
        $where .= " AND c.CLIRUT IN ('$rutsStr')";
    }
    $empresa = trim($_GET['empresa'] ?? '');
    if ($empresa !== '') {
        $where .= " AND c.EMPRESA = ?";
        $params[] = $empresa;
    }
    if ($rut !== '') {
        $where .= " AND c.CLIRUT LIKE ?";
        $params[] = "%$rut%";
    }

    // JOIN con nombre desde bd_autotec
    $sql = "
        SELECT c.EMPRESA, c.CLIRUT, c.CLIDV, c.CLISEC,
               c.DOCTO, c.VENCTO, c.EMISION, c.GLOSA,
               c.TOTAL_CUOTA, c.SALDO_CUOTA,
               cl.CLINOMBRE, cl.CLICOM
        FROM tbl_cobranza c
        LEFT JOIN (
            SELECT CLIRUT, MIN(CLINOMBRE) AS CLINOMBRE, MIN(CLICOM) AS CLICOM
            FROM bd_autotec.tbl_clientes
            GROUP BY CLIRUT
        ) cl ON c.CLIRUT = cl.CLIRUT
        WHERE $where
    ";

    if ($nombre !== '') {
        $sql   .= " AND cl.CLINOMBRE LIKE ?";
        $params[] = "%$nombre%";
    }

    // COUNT
    $stmtC = $pdoCob->prepare("SELECT COUNT(*) FROM ($sql) sub");
    $stmtC->execute($params);
    $total = (int)$stmtC->fetchColumn();

    $sql .= " ORDER BY cl.CLICOM, c.CLIRUT, c.CLISEC, c.EMPRESA,
              SUBSTR(c.VENCTO,7), SUBSTR(c.VENCTO,4,2), SUBSTR(c.VENCTO,1,2)
              LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;

    $stmt = $pdoCob->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // Agregar label empresa y estado fecha
    foreach ($rows as &$r) {
        $r['EMPRESA_LABEL'] = empresaLabel($r['EMPRESA']);
        $r['ESTADO_FECHA']  = estadoFecha($r['VENCTO']);
    }

    jsonResponse(true, ['cobranza' => $rows, 'total' => $total, 'page' => $page]);
}

// ============================================================
// GET ?action=verificar_mora&rut=X&sec=X
// ============================================================
if ($action === 'verificar_mora') {
    $rut = trim($_GET['rut'] ?? '');
    $sec = trim($_GET['sec'] ?? '');
    if (!$rut) jsonResponse(false, null, 'Falta rut');

    $pdoCob = getDBCobranza();
    // Condición exacta Android: vencto.before(hoy - 1 día)
    $stmt   = $pdoCob->prepare("
        SELECT CLIRUT, CLIDV, CLISEC, DOCTO, VENCTO, GLOSA, TOTAL_CUOTA, SALDO_CUOTA, EMPRESA
        FROM tbl_cobranza
        WHERE CLIRUT = ? AND tipo_doc IN (1,4,5,7)
          AND STR_TO_DATE(VENCTO, '%d-%m-%Y') < DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ORDER BY STR_TO_DATE(VENCTO, '%d-%m-%Y')
    ");
    $stmt->execute([$rut]);
    $docs = $stmt->fetchAll();

    foreach ($docs as &$d) {
        $d['EMPRESA_LABEL'] = empresaLabel($d['EMPRESA']);
        $d['ESTADO_FECHA']  = estadoFecha($d['VENCTO']);
    }

    jsonResponse(true, [
        'tiene_mora' => count($docs) > 0,
        'documentos' => $docs,
    ]);
}

// Calcular estado de fecha igual que Android: compararFechasConDate
// 1=rojo(>25 días), 4=amarillo(vencido<25días), 2/0=verde(vigente)
function estadoFecha(string $vencto): string {
    if (!$vencto) return 'verde';
    try {
        $fmt   = 'd-m-Y';
        $fVenc = DateTime::createFromFormat($fmt, $vencto);
        if (!$fVenc) return 'verde';
        $hoy   = new DateTime();
        $fVenc25 = clone $fVenc;
        $fVenc25->modify('+25 days');
        if ($fVenc < $hoy) {
            return ($fVenc25 < $hoy) ? 'rojo' : 'amarillo';
        }
        return 'verde';
    } catch (Exception $e) {
        return 'verde';
    }
}

jsonResponse(false, null, 'Acción no válida');