<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    $email = 'tesoreria@automarco.cl';
    $plain = 'tesoreria123';
    $hash = password_hash($plain, PASSWORD_BCRYPT);
    
    // 1. Actualizar usuario ID 3 o insertar si no existe
    $stmt = $pdo->prepare("
        INSERT INTO usuarios (id, nombre, email, password_hash, rol, activo) 
        VALUES (3, 'Tesorero Automarco', :email, :hash, 'TESORERIA', 1)
        ON DUPLICATE KEY UPDATE 
            email = VALUES(email),
            password_hash = VALUES(password_hash),
            activo = 1
    ");
    $stmt->execute([':email' => $email, ':hash' => $hash]);
    
    // Limpiar intentos fallidos para este email
    $stmtDel = $pdo->prepare("DELETE FROM login_attempts WHERE email = :email");
    $stmtDel->execute([':email' => $email]);

    echo "✅ Usuario Tesorería actualizado a: {$email} / {$plain}\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
