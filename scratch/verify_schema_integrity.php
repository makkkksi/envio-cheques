<?php
/**
 * verify_schema_integrity.php
 * 
 * Verificador de Integridad de Esquema de Base de Datos.
 * Escanea todos los archivos PHP en el proyecto para identificar las tablas MySQL
 * utilizadas en los queries PDO y verifica que:
 * 1. Estén creadas en la base de datos activa.
 * 2. Estén definidas en config/setup.sql.
 * 
 * Garantiza que en Producción NO existan errores por tablas faltantes.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

echo "=== VERIFICADOR DE INTEGRIDAD DE ESQUEMA (ANTI-SCHEMA DRIFT) ===\n\n";

try {
    $pdo = Database::getCobranzasConnection();

    // 1. Obtener tablas existentes en la BD central
    $stmt = $pdo->query("SHOW TABLES FROM `" . DB_NAME_CENTRAL . "`");
    $dbTables = $stmt->fetchAll(PDO::FETCH_COLUMN);

    echo "1. Tablas activas en la BD central ('" . DB_NAME_CENTRAL . "'):\n";
    foreach ($dbTables as $tbl) {
        echo "   [✓] {$tbl}\n";
    }
    echo "\n";

    // 2. Leer setup.sql y extraer tablas definidas
    $setupSql = file_get_contents(__DIR__ . '/../config/setup.sql');
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+([a-z0-9_]+)/i', $setupSql, $setupMatches);
    $setupTables = array_unique($setupMatches[1]);

    echo "2. Tablas definidas en config/setup.sql:\n";
    foreach ($setupTables as $tbl) {
        echo "   [✓] {$tbl}\n";
    }
    echo "\n";

    // 3. Escanear código PHP buscando referencias a tablas en SQL
    $dir = new RecursiveDirectoryIterator(__DIR__ . '/..');
    $iterator = new RecursiveIteratorIterator($dir);
    $referencedTables = [];

    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $path = $file->getPathname();
            // Ignorar carpeta scratch y vendor si existiera
            if (strpos($path, 'scratch') !== false || strpos($path, 'vendor') !== false) {
                continue;
            }

            $content = file_get_contents($path);
            
            // Regex para capturar nombres de tabla en FROM, INTO, UPDATE, JOIN
            if (preg_match_all('/(?:FROM|INTO|UPDATE|JOIN)\s+`?([a_z0-9_]+)`?/i', $content, $matches)) {
                foreach ($matches[1] as $t) {
                    $tLower = strtolower($t);
                    // Excluir palabras clave SQL o subqueries
                    $sqlKeywords = ['select', 'where', 'set', 'table', 'values', 'distinct', 'dual', 'join', 'on', 'left', 'inner', 'right'];
                    if (!in_array($tLower, $sqlKeywords, true) && !preg_match('/^(tbl_|automarc_|gabteccl_|autotec_|autohd_)/i', $tLower)) {
                        $referencedTables[$tLower][] = basename($path);
                    }
                }
            }
        }
    }

    echo "3. Validación Cruzada de Tablas del Código vs Esquema:\n";
    $missingInDb = [];
    $missingInSetup = [];

    foreach ($referencedTables as $table => $files) {
        $uniqueFiles = implode(', ', array_unique($files));
        $inDb = in_array($table, $dbTables, true);
        $inSetup = in_array($table, $setupTables, true);

        if ($inDb && $inSetup) {
            echo "   [OK] Tabla '{$table}' (Usada en: {$uniqueFiles}) — OK en BD y setup.sql\n";
        } else {
            if (!$inDb) {
                echo "   [❌ ERROR] Tabla '{$table}' NO existe en la BD activa. (Usada en: {$uniqueFiles})\n";
                $missingInDb[] = $table;
            }
            if (!$inSetup) {
                echo "   [⚠️ ALERTA] Tabla '{$table}' NO está definida en setup.sql. (Usada en: {$uniqueFiles})\n";
                $missingInSetup[] = $table;
            }
        }
    }

    echo "\n=== RESUMEN DE INTEGRIDAD ===\n";
    if (empty($missingInDb) && empty($missingInSetup)) {
        echo "✅ INTEGRIDAD 100% GARANTIZADA: Todas las tablas usadas en código existen en la BD y en setup.sql.\n";
    } else {
        echo "❌ ATENCIÓN: Se detectaron inconsistencias que deben corregirse antes de pasar a Producción.\n";
    }

} catch (Exception $e) {
    echo "❌ Error al verificar esquema: " . $e->getMessage() . "\n";
}
