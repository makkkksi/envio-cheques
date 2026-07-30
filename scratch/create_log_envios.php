<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = Database::getCobranzasConnection();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS log_envios_informes (
          id INT AUTO_INCREMENT PRIMARY KEY,
          empresa_id INT NULL,
          tipo_informe ENUM('INDIVIDUAL_TESORERIA', 'RESUMEN_DIARIO_16HRS') NOT NULL,
          destinatario VARCHAR(150) NOT NULL,
          copia_cc VARCHAR(150) NULL,
          asunto VARCHAR(255) NOT NULL,
          estado_envio ENUM('ENVIADO', 'FALLIDO') NOT NULL DEFAULT 'ENVIADO',
          error_mensaje TEXT NULL,
          cantidad_cobranzas INT DEFAULT 1,
          fecha_envio TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          KEY idx_log_envios_empresa (empresa_id),
          KEY idx_log_envios_estado (estado_envio),
          FOREIGN KEY (empresa_id) REFERENCES empresas(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "✅ Tabla log_envios_informes creada/verificada.\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
