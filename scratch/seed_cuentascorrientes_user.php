<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    // Alter table to support SUPERVISORA_CC in ENUM
    $pdo->exec("ALTER TABLE usuarios MODIFY COLUMN rol ENUM('VENDEDOR', 'TESORERIA', 'ADMINISTRADOR', 'SUPERVISORA_CC') DEFAULT 'TESORERIA'");
    
    $passHash = password_hash('cuentas123', PASSWORD_BCRYPT);
    
    $stmt = $pdo->prepare("
        INSERT INTO usuarios (id, nombre, email, password_hash, rol, activo)
        VALUES (4, 'Supervisora Cuentas Corrientes', 'cuentascorrientes@automarco.cl', :hash, 'SUPERVISORA_CC', 1)
        ON DUPLICATE KEY UPDATE 
            password_hash = VALUES(password_hash),
            rol = 'SUPERVISORA_CC',
            activo = 1
    ");
    $stmt->execute([':hash' => $passHash]);

    // Limpiar bloqueos de rate limit para este email por si acaso
    $pdo->prepare("DELETE FROM login_attempts WHERE email = 'cuentascorrientes@automarco.cl'")->execute();

    echo "✅ Usuario cuentascorrientes@automarco.cl (Pass: cuentas123) creado/actualizado con éxito.\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
