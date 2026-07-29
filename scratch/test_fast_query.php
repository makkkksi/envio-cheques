<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();

$t0 = microtime(true);

// Paso 1: Obtener clientes resumidos de tbl_cobranza (rápido)
$stmt1 = $pdo->prepare("
    SELECT 
        c.clirut,
        c.clidv,
        COUNT(*) AS total_facturas,
        SUM(CAST(c.saldo_cuota AS DECIMAL(15,2))) AS total_deuda
    FROM bd_automarco.tbl_cobranza c
    WHERE c.vendedor = 2 AND c.empresa = 'EMP03' AND c.empresa != 'EMP07'
    GROUP BY c.clirut, c.clidv
    ORDER BY total_deuda DESC
");
$stmt1->execute();
$cobranzaClients = $stmt1->fetchAll(PDO::FETCH_ASSOC);

if (!empty($cobranzaClients)) {
    $ruts = array_column($cobranzaClients, 'clirut');
    
    // Paso 2: Buscar datos de razón social en tbl_clientes solo para los RUTs del vendedor
    // Crear mapa de RUTs
    $rutLikeConditions = array_map(function($r) { return "'" . $r . "-%'"; }, $ruts);
    $inLikeClause = implode(',', $rutLikeConditions);

    $sqlCli = "
        SELECT cli_rut, cli_razon_social, cli_mail 
        FROM autotec_ecom.tbl_clientes 
        WHERE cli_rut IN (" . implode(',', array_map(function($r) use ($pdo) { return $pdo->quote($r); }, array_map(function($c) { return $c['clirut'] . '-' . $c['clidv']; }, $cobranzaClients))) . ")
    ";
    $stmtCli = $pdo->query($sqlCli);
    $cliData = $stmtCli->fetchAll(PDO::FETCH_ASSOC);
}

$t1 = microtime(true);

echo "Tiempo transcurrido: " . round(($t1 - $t0) * 1000, 2) . " ms\n";
echo "Total clientes: " . count($cobranzaClients) . "\n";
