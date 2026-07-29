<?php
require_once __DIR__ . '/../config/db.php';
$pdo = Database::getCobranzasConnection();

$t0 = microtime(true);
$sql = "
    SELECT 
        c.clirut,
        c.clidv,
        COUNT(*) AS total_facturas,
        SUM(CAST(c.saldo_cuota AS DECIMAL(15,2))) AS total_deuda,
        MAX(COALESCE(
            cli1.cli_razon_social,
            cli2.cli_razon_social,
            cli3.cli_razon_social,
            cli4.cli_razon_social,
            CONCAT('CLIENTE RUT ', c.clirut, '-', c.clidv)
        )) AS razon_social,
        MAX(COALESCE(
            cli1.cli_mail,
            cli2.cli_mail,
            cli3.cli_mail,
            cli4.cli_mail,
            ''
        )) AS email_cliente
    FROM bd_automarco.tbl_cobranza c
    LEFT JOIN automarc_automarco.tbl_clientes cli1 
           ON cli1.cli_rut LIKE CONCAT(c.clirut, '-%')
    LEFT JOIN autotec_ecom.tbl_clientes cli2 
           ON cli2.cli_rut LIKE CONCAT(c.clirut, '-%')
    LEFT JOIN autohd_automarcohd.tbl_clientes cli3 
           ON cli3.cli_rut LIKE CONCAT(c.clirut, '-%')
    LEFT JOIN gabteccl_sitbdd1978.tbl_clientes cli4 
           ON cli4.cli_rut LIKE CONCAT(c.clirut, '-%')
    WHERE c.vendedor = 2 AND c.empresa = 'EMP03'
    GROUP BY c.clirut, c.clidv
    ORDER BY total_deuda DESC
";

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$t1 = microtime(true);

echo "Tiempo transcurrido: " . round(($t1 - $t0) * 1000, 2) . " ms\n";
echo "Total clientes: " . count($rows) . "\n";
print_r(array_slice($rows, 0, 3));
