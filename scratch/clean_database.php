<?php
/**
 * Script para limpiar registros de pruebas de bd_modulo_cobranzas
 * Mantiene intactas las tablas de configuración (empresas, usuarios).
 */

require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();

    echo "=== LIMPIANDO TABLAS OPERACIONALES EN bd_modulo_cobranzas ===\n";

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

    $tables = ['cobranza_facturas', 'cheques', 'historial_estados', 'cobranzas'];

    foreach ($tables as $table) {
        $stmtCount = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
        $countBefore = $stmtCount->fetchColumn();

        $pdo->exec("TRUNCATE TABLE `{$table}`");

        echo " -> Tabla `{$table}` limpiada. Registros eliminados: {$countBefore}\n";
    }

    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    echo "\n✅ BASE DE DATOS `bd_modulo_cobranzas` LIMPIADA Y AUTO_INCREMENT REINICIADO A 1.\n";

} catch (Exception $e) {
    echo "❌ Error al limpiar la base de datos: " . $e->getMessage() . "\n";
}
