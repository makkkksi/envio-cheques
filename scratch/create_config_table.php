<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = Database::getCobranzasConnection();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS configuraciones_sistema (
          clave VARCHAR(50) PRIMARY KEY,
          valor VARCHAR(255) NOT NULL,
          descripcion VARCHAR(255) NULL,
          actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    $pdo->exec("
        INSERT IGNORE INTO configuraciones_sistema (clave, valor, descripcion) VALUES
        ('hora_despacho_diario', '16:00', 'Hora a la que se envían los correos de resumen a Cuentas Corrientes');
    ");
    echo "✅ Tabla configuraciones_sistema creada y poblada.\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
