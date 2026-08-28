<?php
/**
 * Verifica sintaxis PHP y paridad SHA-256 entre la aplicación raíz y dist.
 * Uso: php scratch/verify_release.php
 */

$projectRoot = realpath(__DIR__ . '/..');
$distRoot = $projectRoot . DIRECTORY_SEPARATOR . 'dist' . DIRECTORY_SEPARATOR . 'cheques_cobranza' . DIRECTORY_SEPARATOR . 'app';
$deploymentEntries = [
    'admin',
    'api',
    'config',
    'cron',
    'services',
    'libs',
    'scripts',
    'rendiciones',
    '.htaccess',
    'LOGO-HOLDING-AUTOMARCO.png',
    'index.php',
    'index.html',
    'seller_session.js',
    'script.js',
    'styles.css',
];

function collectDeploymentFiles(string $basePath, array $entries): array
{
    $files = [];
    foreach ($entries as $entry) {
        $absolutePath = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $entry);
        if (is_file($absolutePath)) {
            $files[$entry] = $absolutePath;
            continue;
        }
        if (!is_dir($absolutePath)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $relative = substr($fileInfo->getPathname(), strlen($basePath) + 1);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $files[$relative] = $fileInfo->getPathname();
        }
    }
    ksort($files);
    return $files;
}

function lintPhpFile(string $filePath): bool
{
    $command = escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($filePath) . ' 2>&1';
    exec($command, $output, $exitCode);
    if ($exitCode !== 0) {
        echo '  [ERROR] ' . basename($filePath) . ': ' . implode(' ', $output) . PHP_EOL;
        return false;
    }
    return true;
}

function readSetEnvValues(string $filePath): array
{
    if (!is_file($filePath)) {
        return [];
    }

    $contents = file_get_contents($filePath);
    preg_match_all(
        '/^\s*SetEnv\s+([A-Z0-9_]+)\s+(?:"([^"]*)"|(\S+))\s*$/mi',
        $contents,
        $matches,
        PREG_SET_ORDER
    );

    $values = [];
    foreach ($matches as $match) {
        $values[strtoupper($match[1])] = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
    }
    return $values;
}

if ($projectRoot === false || !is_dir($distRoot)) {
    fwrite(STDERR, "No se encontró la raíz del proyecto o la réplica dist.\n");
    exit(1);
}

$rootFiles = collectDeploymentFiles($projectRoot, $deploymentEntries);
$distFiles = collectDeploymentFiles($distRoot, $deploymentEntries);
$failed = false;

echo "=== SINTAXIS PHP ===\n";
$phpCount = 0;
foreach ($rootFiles as $relative => $absolute) {
    if (strtolower(pathinfo($relative, PATHINFO_EXTENSION)) !== 'php') {
        continue;
    }
    $phpCount++;
    if (!lintPhpFile($absolute)) {
        $failed = true;
    }
}
echo $failed ? "Sintaxis PHP con errores.\n" : "OK: {$phpCount} archivos PHP válidos.\n";

echo "\n=== CONTRATO ESTÁTICO DE ESQUEMA ===\n";
$setupSqlPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'setup.sql';
$setupSql = file_get_contents($setupSqlPath);
preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $setupSql, $setupMatches);
$setupTables = array_map('strtolower', array_unique($setupMatches[1] ?? []));
$requiredCentralTables = [
    'empresas',
    'usuarios',
    'cobranzas',
    'cobranza_facturas',
    'cheques',
    'historial_estados',
    'login_attempts',
    'audit_logs',
    'log_envios_informes',
    'configuraciones_sistema',
    'presupuestos_vendedores',
    'aprobadores_rendiciones',
    'rendiciones_gastos',
    'rendicion_documentos',
    'rendicion_historial_estados',
    'solicitudes_aprobacion',
    'solicitud_aprobacion_historial',
];
$missingSetupTables = array_values(array_diff($requiredCentralTables, $setupTables));
sort($missingSetupTables);
if ($missingSetupTables) {
    $failed = true;
    foreach ($missingSetupTables as $tableName) {
        echo "  [ERROR] Tabla usada pero no definida en setup.sql: {$tableName}\n";
    }
} else {
    echo 'OK: ' . count($requiredCentralTables) . " tablas centrales requeridas están definidas en setup.sql.\n";
}

$rendicionesSetupPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'setup_rendiciones.sql';
if (!is_file($rendicionesSetupPath)) {
    $failed = true;
    echo "  [ERROR] Falta config/setup_rendiciones.sql para migración productiva.\n";
} else {
    $rendicionesSql = file_get_contents($rendicionesSetupPath);
    preg_match_all('/CREATE TABLE IF NOT EXISTS\s+`?([A-Za-z0-9_]+)`?/i', $rendicionesSql, $rendicionesMatches);
    $rendicionesTables = array_map('strtolower', array_unique($rendicionesMatches[1] ?? []));
    $requiredRendicionesTables = [
        'presupuestos_vendedores',
        'aprobadores_rendiciones',
        'rendiciones_gastos',
        'rendicion_documentos',
        'rendicion_historial_estados',
        'solicitudes_aprobacion',
        'solicitud_aprobacion_historial',
    ];
    $missingRendicionesTables = array_values(array_diff($requiredRendicionesTables, $rendicionesTables));
    if ($missingRendicionesTables) {
        $failed = true;
        foreach ($missingRendicionesTables as $tableName) {
            echo "  [ERROR] Tabla ausente en setup_rendiciones.sql: {$tableName}\n";
        }
} else {
    echo "OK: migración productiva de Rendiciones contiene sus 7 tablas.\n";
}

echo "\n=== CONTRATO ESTÁTICO DE RENDICIONES ===\n";
$rendicionesCodeFiles = [];
foreach ($rootFiles as $relative => $absolute) {
    if (strpos($relative, 'api/rendiciones/') === 0
        || strpos($relative, 'admin/api/rendiciones/') === 0
        || in_array($relative, ['services/RendicionesService.php', 'services/RendicionesDocumentService.php', 'services/ErpSellerDirectoryService.php', 'services/ApprovalWorkflowService.php'], true)) {
        $rendicionesCodeFiles[$relative] = $absolute;
    }
}
$contractErrors = [];
foreach ($rendicionesCodeFiles as $relative => $absolute) {
    $source = file_get_contents($absolute);
    if (preg_match('/\bDELETE\s+FROM\b/i', $source)) {
        $contractErrors[] = "DELETE físico detectado en {$relative}";
    }
    if (preg_match('/prepare\s*\([^;]*\?/is', $source)) {
        $contractErrors[] = "Placeholder posicional detectado en {$relative}";
    }
}
if ($contractErrors) {
    $failed = true;
    foreach ($contractErrors as $contractError) {
        echo "  [ERROR] {$contractError}\n";
    }
} else {
    echo 'OK: ' . count($rendicionesCodeFiles) . " archivos backend sin DELETE físico ni placeholders posicionales.\n";
}
}

echo "\n=== CONFIGURACIÓN DE ENTORNOS ===\n";
$rootHtaccess = $rootFiles['.htaccess'] ?? '';
$distHtaccess = $distFiles['.htaccess'] ?? '';
$rootEnvironment = readSetEnvValues($rootHtaccess);
$distEnvironment = readSetEnvValues($distHtaccess);
$localDatabaseHosts = ['localhost', '127.0.0.1', '::1'];

if (($rootEnvironment['APP_ENV'] ?? '') !== 'local'
    || !in_array($rootEnvironment['DB_HOST'] ?? '', $localDatabaseHosts, true)) {
    $failed = true;
    echo "  [ERROR] .htaccess raíz debe usar APP_ENV=local y una BD local de Laragon.\n";
} else {
    echo "OK: .htaccess raíz usa el entorno local de Laragon.\n";
}

if (($distEnvironment['APP_ENV'] ?? '') !== 'production'
    || in_array($distEnvironment['DB_HOST'] ?? '', $localDatabaseHosts, true)) {
    $failed = true;
    echo "  [ERROR] dist/.htaccess debe conservar APP_ENV=production y un DB_HOST no local.\n";
} else {
    echo "OK: dist/.htaccess conserva la configuración productiva.\n";
}

echo "\n=== PARIDAD SHA-256 ROOT / DIST ===\n";
$allRelativePaths = array_unique(array_merge(array_keys($rootFiles), array_keys($distFiles)));
sort($allRelativePaths);
$environmentSpecificPaths = ['.htaccess'];
$parityPaths = array_values(array_diff($allRelativePaths, $environmentSpecificPaths));
$mismatches = [];
foreach ($parityPaths as $relative) {
    if (!isset($rootFiles[$relative])) {
        $mismatches[] = "Sólo existe en dist: {$relative}";
        continue;
    }
    if (!isset($distFiles[$relative])) {
        $mismatches[] = "Falta en dist: {$relative}";
        continue;
    }
    if (!hash_equals(hash_file('sha256', $rootFiles[$relative]), hash_file('sha256', $distFiles[$relative]))) {
        $mismatches[] = "Hash diferente: {$relative}";
    }
}

if ($mismatches) {
    $failed = true;
    foreach ($mismatches as $mismatch) {
        echo "  [ERROR] {$mismatch}\n";
    }
} else {
    echo 'OK: ' . count($parityPaths) . " archivos con SHA-256 idéntico; .htaccess validado por entorno.\n";
}

exit($failed ? 1 : 0);
