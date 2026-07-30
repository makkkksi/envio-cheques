<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();

    // 1. Crear login_attempts si no existe
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
          id INT AUTO_INCREMENT PRIMARY KEY,
          ip_address VARCHAR(45) NOT NULL,
          email VARCHAR(150) NOT NULL,
          attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY idx_login_attempts_ip_email (ip_address, email, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo " -> Tabla login_attempts creada o verificada.\n";

    // 2. Crear audit_logs si no existe
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS audit_logs (
          id INT AUTO_INCREMENT PRIMARY KEY,
          usuario_id INT NOT NULL,
          email VARCHAR(150) NOT NULL,
          accion VARCHAR(100) NOT NULL,
          detalles TEXT NULL,
          ip_address VARCHAR(45) NULL,
          user_agent VARCHAR(255) NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY idx_audit_logs_user (usuario_id),
          KEY idx_audit_logs_action (accion)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo " -> Tabla audit_logs creada o verificada.\n";

    echo "✅ TABLAS DE AUDITORÍA Y RATE-LIMITING INSTALADAS CON ÉXITO.\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
