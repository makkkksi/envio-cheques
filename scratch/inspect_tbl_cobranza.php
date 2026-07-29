<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    // Distinct empresas
    $stmtEmp = $pdo->query("SELECT empresa, COUNT(*) as cantidad FROM bd_automarco.tbl_cobranza GROUP BY empresa");
    echo "=== DISTINTO EMPRESAS ===\n";
    print_r($stmtEmp->fetchAll(PDO::FETCH_ASSOC));

    // Sample vendedores non-zero
    $stmtVend = $pdo->query("SELECT vendedor, COUNT(*) as cantidad FROM bd_automarco.tbl_cobranza WHERE vendedor > 0 GROUP BY vendedor LIMIT 10");
    echo "\n=== VENDEDORES CON REGISTROS ===\n";
    print_r($stmtVend->fetchAll(PDO::FETCH_ASSOC));

    // Sample clients for seller with most records
    $stmtClients = $pdo->query("SELECT vendedor, clirut, clidv, COUNT(*) as facturas, SUM(CAST(saldo_cuota AS DECIMAL(15,2))) as total_deuda FROM bd_automarco.tbl_cobranza WHERE vendedor > 0 GROUP BY vendedor, clirut, clidv ORDER BY facturas DESC LIMIT 5");
    echo "\n=== EJEMPLO CLIENTES Y FACTURAS ===\n";
    print_r($stmtClients->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
