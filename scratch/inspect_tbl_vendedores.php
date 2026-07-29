<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    // Inspect columns of automarc_automarco.tbl_vendedores
    $stmt = $pdo->query("DESCRIBE automarc_automarco.tbl_vendedores");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "=== COLUMNS OF automarc_automarco.tbl_vendedores ===\n";
    print_r($columns);

    // Inspect sample 5 rows
    $stmtSample = $pdo->query("SELECT * FROM automarc_automarco.tbl_vendedores LIMIT 5");
    $samples = $stmtSample->fetchAll(PDO::FETCH_ASSOC);
    echo "\n=== SAMPLE 5 ROWS ===\n";
    print_r($samples);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
