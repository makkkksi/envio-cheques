<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    // Test matching clirut from tbl_cobranza to tbl_clientes
    $sql = "
        SELECT 
            c.empresa,
            c.vendedor,
            c.clirut,
            c.clidv,
            c.docto,
            c.saldo_cuota,
            COALESCE(cli.cli_razon_social, 'Nombre no encontrado') AS razon_social
        FROM bd_automarco.tbl_cobranza c
        LEFT JOIN automarc_automarco.tbl_clientes cli 
               ON REPLACE(REPLACE(cli.cli_rut, '.', ''), '-', '') LIKE CONCAT(c.clirut, '%')
        WHERE c.vendedor = 2
        LIMIT 5
    ";
    $stmt = $pdo->query($sql);
    echo "=== TEST JOIN WITH CLIENTES ===\n";
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
