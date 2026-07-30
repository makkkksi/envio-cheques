<?php
/**
 * Script para reiniciar la base de datos central bd_modulo_cobranzas
 * Recrea todas las tablas desde cero leyendo setup.sql
 */

require_once __DIR__ . '/../config/app.php';

try {
    // Conectar directamente a MySQL sin base de datos seleccionada para poder recrearla
    $dsn = "mysql:host=" . DB_HOST . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    echo "=== REINICIANDO BASE DE DATOS CENTRAL: " . DB_NAME_CENTRAL . " ===\n";

    // 1. Borrar la base de datos si existe
    $pdo->exec("DROP DATABASE IF EXISTS `" . DB_NAME_CENTRAL . "`");
    echo " -> Base de datos eliminada.\n";

    // 2. Leer setup.sql
    $sqlFile = __DIR__ . '/../config/setup.sql';
    if (!file_exists($sqlFile)) {
        throw new Exception("No se encontró el archivo setup.sql en {$sqlFile}");
    }

    $sqlContent = file_get_contents($sqlFile);
    
    // Ejecutar todo el setup.sql
    // El driver PDO::exec no siempre procesa múltiples queries de forma confiable,
    // así que las separaremos o usaremos PDO::exec directamente si lo soporta.
    // Usaremos exec directo que con MySQL soporta múltiples sentencias.
    $pdo->exec($sqlContent);

    echo " -> Base de datos creada y tablas inicializadas con setup.sql.\n";
    echo " -> Datos semilla cargados.\n";
    echo "\n✅ BASE DE DATOS REINICIADA EXITOSAMENTE.\n";

} catch (Exception $e) {
    echo "❌ Error al reiniciar la base de datos: " . $e->getMessage() . "\n";
}
