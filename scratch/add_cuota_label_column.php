<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = Database::getCobranzasConnection();
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM cobranza_facturas LIKE 'cuota_label'");
    if ($stmt->rowCount() === 0) {
        $pdo->exec("ALTER TABLE cobranza_facturas ADD COLUMN cuota_label VARCHAR(20) NULL AFTER numero_factura");
        echo "Column cuota_label added successfully to cobranza_facturas.\n";
    } else {
        echo "Column cuota_label already exists in cobranza_facturas.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
