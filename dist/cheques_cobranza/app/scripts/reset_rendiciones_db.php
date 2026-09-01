<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

if (!defined('APP_ENV') || APP_ENV !== 'local') {
    fwrite(STDERR, "Este script sólo puede ejecutarse en APP_ENV=local.\n");
    exit(1);
}

$pdo = Database::getCobranzasConnection();

echo "=== ESTADO PREVIO DE TABLAS DE RENDICIONES ===\n";
$tables = [
    'solicitud_aprobacion_historial',
    'solicitudes_aprobacion',
    'rendicion_historial_estados',
    'rendicion_documentos',
    'rendiciones_gastos',
    'presupuestos_vendedores',
    'aprobadores_rendiciones',
];

foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo " - {$table}: {$count} filas\n";
    } catch (Throwable $e) {
        echo " - {$table}: ERROR - " . $e->getMessage() . "\n";
    }
}

echo "\n=== VACIANDO Y REINICIANDO TABLAS DE RENDICIONES ===\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");

// Vaciar en orden de dependencia
$tablesToTruncate = [
    'solicitud_aprobacion_historial',
    'solicitudes_aprobacion',
    'rendicion_historial_estados',
    'rendicion_documentos',
    'rendiciones_gastos',
    'presupuestos_vendedores',
];

foreach ($tablesToTruncate as $table) {
    try {
        $pdo->exec("TRUNCATE TABLE `{$table}`");
        echo " [OK] Truncada tabla {$table}\n";
    } catch (Throwable $e) {
        echo " [ERROR] Al truncar {$table}: " . $e->getMessage() . "\n";
    }
}

// Resetear y asegurar aprobadores por defecto (orden 1 y orden 2) si no existen o se reinician
$stmtAprobadoresCount = (int)$pdo->query("SELECT COUNT(*) FROM aprobadores_rendiciones")->fetchColumn();
if ($stmtAprobadoresCount === 0) {
    $pdo->exec("
        INSERT INTO aprobadores_rendiciones (orden, nombre, cargo, email, activo)
        VALUES 
            (1, 'Patricio Olave', 'Gerente General', 'polave@automarco.cl', 1),
            (2, 'Rodrigo Hernandez', 'Gerente Comercial', 'rhernandez@automarco.cl', 1)
        ON DUPLICATE KEY UPDATE activo = 1;
    ");
    echo " [OK] Aprobadores de rendiciones inicializados (2 responsables).\n";
} else {
    echo " [INFO] Aprobadores_rendiciones conservó sus {$stmtAprobadoresCount} registros de configuración.\n";
}

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

echo "\n=== ESTADO FINAL ===\n";
foreach ($tables as $table) {
    try {
        $count = $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo " - {$table}: {$count} filas\n";
    } catch (Throwable $e) {
        echo " - {$table}: ERROR\n";
    }
}

echo "\nBase de datos local de Rendiciones reiniciada y vaciada exitosamente.\n";
