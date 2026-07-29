<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    // 1. Make empresa_id and numero_factura nullable in cobranzas if they are not
    $pdo->exec("ALTER TABLE cobranzas MODIFY empresa_id INT NULL");
    $pdo->exec("ALTER TABLE cobranzas MODIFY numero_factura VARCHAR(50) NULL");
    
    // 2. Create cobranza_facturas table
    $sql = "
    CREATE TABLE IF NOT EXISTS cobranza_facturas (
      id INT AUTO_INCREMENT PRIMARY KEY,
      cobranza_id INT NOT NULL,
      empresa_id INT NOT NULL,
      codigo_empresa VARCHAR(20) NOT NULL,
      numero_factura VARCHAR(50) NOT NULL,
      total_cuota DECIMAL(12,0) NOT NULL,
      saldo_cuota DECIMAL(12,0) NOT NULL,
      monto_cubierto DECIMAL(12,0) NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      KEY idx_cobranza_facturas_cobranza (cobranza_id),
      FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE,
      FOREIGN KEY (empresa_id) REFERENCES empresas(id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $pdo->exec($sql);

    echo "=== FASE 5 SCHEMA MIGRATION SUCCESSFUL ===\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
