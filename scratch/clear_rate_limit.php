<?php
require_once __DIR__ . '/../config/db.php';
try {
    $pdo = Database::getCobranzasConnection();
    $pdo->exec("TRUNCATE TABLE login_attempts");
    echo "✅ Tabla login_attempts limpiada. Se han desbloqueado todos los intentos.\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
