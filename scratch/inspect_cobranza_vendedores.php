<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();

    // Check sample rows from bd_automarco.tbl_cobranza grouped by empresa and vendedor
    $stmt = $pdo->query("
        SELECT empresa, vendedor, COUNT(*) as cant, MIN(clirut) as sample_rut
        FROM bd_automarco.tbl_cobranza
        GROUP BY empresa, vendedor
        ORDER BY vendedor ASC, empresa ASC
        LIMIT 20
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "=== EMPRESA + VENDEDOR IN bd_automarco.tbl_cobranza ===\n";
    print_r($rows);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
