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
}

echo "\n=== CONTRATO ESTÁTICO GLOBAL (SEGURIDAD, ZERO DELETE, NAMED PARAMS) ===\n";
$backendFiles = [];
foreach ($rootFiles as $relative => $absolute) {
    if (strpos($relative, 'api/') === 0
        || strpos($relative, 'admin/api/') === 0
        || strpos($relative, 'services/') === 0
        || strpos($relative, 'cron/') === 0) {
        if (substr($relative, -4) === '.php') {
            $backendFiles[$relative] = $absolute;
        }
    }
}

$contractErrors = [];
$businessTables = [
    'cheques',
    'cobranzas',
    'cobranza_facturas',
    'rendiciones_gastos',
    'rendicion_documentos',
    'solicitudes_aprobacion',
    'solicitud_aprobacion_historial',
    'presupuestos_vendedores',
    'historial_estados',
    'rendicion_historial_estados',
    'audit_logs'
];
$businessDeleteRegex = '/\bDELETE\s+FROM\s+(?:`?(?:' . implode('|', $businessTables) . ')`?)\b/i';

foreach ($backendFiles as $relative => $absolute) {
    $source = file_get_contents($absolute);
    
    // Check 1: Zero physical DELETE on business tables
    if (preg_match($businessDeleteRegex, $source, $m)) {
        $contractErrors[] = "DELETE físico en tabla de negocio detectado en {$relative}";
    }
    
    // Check 2: Zero positional placeholders (?) in PDO prepare
    if (preg_match("/prepare\s*\(\s*'(?:\\\\'|[^'])*\\?/is", $source)
        || preg_match('/prepare\s*\(\s*"(?:\\\\"|[^"])*\\?/is', $source)) {
        $contractErrors[] = "Placeholder posicional (?) detectado en {$relative}";
    }

    // Check 3: Sanitized error responses in client-facing APIs
    if (strpos($relative, 'api/') === 0 || strpos($relative, 'admin/api/') === 0) {
        if (preg_match_all('/catch\s*\(\s*(?:\\\\?Exception|\\\\?Throwable|\\\\?PDOException)\s+\$e\s*\)\s*\{([^}]+)\}/is', $source, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $catchBody = $match[1];
                if (preg_match('/(?:json_encode|\$msg\s*=)[^;]*\$e->getMessage\(\)/i', $catchBody)) {
                    $contractErrors[] = "Posible filtración de \$e->getMessage() en catch de {$relative}";
                }
            }
        }
    }

    // Check 4: Inmutabilidad de cheques (UPDATE cheques debe filtrar registros activos)
    if (preg_match_all('/UPDATE\s+cheques\s+SET\s+([\s\S]+?)(?:\)|;|\$stmt)/i', $source, $updMatches)) {
        foreach ($updMatches[1] as $updSql) {
            // Ignorar purga cronológica autorizada de fotos en cron/purgar_fotos_cheques_vencidos.php
            if (strpos($relative, 'purgar_fotos_cheques_vencidos.php') !== false) {
                continue;
            }
            // Ignorar si la sentencia es la propia baja lógica (SET activo = 0)
            if (preg_match('/activo\s*=\s*0/i', $updSql)) {
                continue;
            }
            // Debe incluir activo = 1 o (activo = 1 OR activo IS NULL)
            if (!preg_match('/activo\s*=\s*1/i', $updSql) && !preg_match('/activo\s+IS\s+NULL/i', $updSql)) {
                $contractErrors[] = "UPDATE cheques sin filtro de registros activos en {$relative}";
            }
        }
    }
}

if ($contractErrors) {
    $failed = true;
    foreach ($contractErrors as $contractError) {
        echo "  [ERROR] {$contractError}\n";
    }
} else {
    echo 'OK: ' . count($backendFiles) . " archivos backend cumplen Zero Delete, Named Parameters, Error Sanitization e Inmutabilidad de Cheques.\n";
}

echo "\n=== CONTRATO DE POLÍTICA DE CORREOS (VENDEDORES DESHABILITADOS) ===\n";
$appConfigPath = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
$appConfigContent = file_get_contents($appConfigPath);
$sellerPolicyErrors = [];

if (!preg_match('/define\s*\(\s*\'MAIL_SELLER_NOTIFICATIONS_ENABLED\'\s*,\s*filter_var\([^,]+,\s*FILTER_VALIDATE_BOOLEAN\)\s*\)/i', $appConfigContent)
    && !preg_match('/define\s*\(\s*\'MAIL_SELLER_NOTIFICATIONS_ENABLED\'\s*,\s*false\s*\)/i', $appConfigContent)) {
    $sellerPolicyErrors[] = "config/app.php debe definir MAIL_SELLER_NOTIFICATIONS_ENABLED por defecto en false";
}

$mailServicePath = $projectRoot . DIRECTORY_SEPARATOR . 'services' . DIRECTORY_SEPARATOR . 'MailService.php';
$mailServiceContent = file_get_contents($mailServicePath);
if (!preg_match('/function\s+isSellerNotificationEnabled\s*\(\s*\)\s*:\s*bool/i', $mailServiceContent)) {
    $sellerPolicyErrors[] = "services/MailService.php debe implementar el método isSellerNotificationEnabled(): bool";
}
if (!preg_match('/notificarDecisionExcesoRendicion[^{]+\{[^}]+isSellerNotificationEnabled/is', $mailServiceContent)) {
    $sellerPolicyErrors[] = "MailService::notificarDecisionExcesoRendicion debe validar isSellerNotificationEnabled()";
}
if (!preg_match('/notificarDecisionGira[^{]+\{[^}]+isSellerNotificationEnabled/is', $mailServiceContent)) {
    $sellerPolicyErrors[] = "MailService::notificarDecisionGira debe validar isSellerNotificationEnabled()";
}

if ($sellerPolicyErrors) {
    $failed = true;
    foreach ($sellerPolicyErrors as $err) {
        echo "  [ERROR] {$err}\n";
    }
} else {
    echo "OK: Política de notificaciones a vendedores centralizada y deshabilitada por defecto.\n";
}

echo "\n=== CONTRATO DE MIGRACIONES IDEMPOTENTES Y SELECCIÓN DE BD ===\n";
$migrationsDir = $projectRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'migrations';
$migrationErrors = [];
if (is_dir($migrationsDir)) {
    $migrationFiles = glob($migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
    foreach ($migrationFiles as $migFile) {
        $migName = basename($migFile);
        $migContent = file_get_contents($migFile);
        
        // Check: explicit USE db selection
        if (!preg_match('/USE\s+`?bd_modulo_cobranzas`?\s*;/i', $migContent)) {
            $migrationErrors[] = "Migración {$migName} no incluye selección explícita: USE `bd_modulo_cobranzas`;";
        }
        
        // Check: unconditional DROP INDEX
        if (preg_match('/ALTER\s+TABLE\s+[a-z0-9_]+\s+DROP\s+INDEX\s+[a-z0-9_]+/i', $migContent)
            && !preg_match('/information_schema\.STATISTICS/i', $migContent)) {
            $migrationErrors[] = "Migración {$migName} posee DROP INDEX incondicional sin verificación de information_schema";
        }
        
        // Check: unconditional DROP CHECK
        if (preg_match('/ALTER\s+TABLE\s+[a-z0-9_]+\s+DROP\s+CHECK\s+[a-z0-9_]+/i', $migContent)
            && !preg_match('/information_schema\.TABLE_CONSTRAINTS/i', $migContent)) {
            $migrationErrors[] = "Migración {$migName} posee DROP CHECK incondicional sin verificación de information_schema";
        }

        // Check: unconditional ADD COLUMN without IF NOT EXISTS or information_schema
        if (preg_match('/ALTER\s+TABLE\s+[a-z0-9_]+\s+ADD\s+COLUMN\s+(?!IF\s+NOT\s+EXISTS)[a-z0-9_]+/i', $migContent)
            && !preg_match('/information_schema\.COLUMNS/i', $migContent)) {
            $migrationErrors[] = "Migración {$migName} posee ADD COLUMN incondicional sin IF NOT EXISTS ni verificación de information_schema";
        }
    }
}
if ($migrationErrors) {
    $failed = true;
    foreach ($migrationErrors as $err) {
        echo "  [ERROR] {$err}\n";
    }
} else {
    echo "OK: Todas las migraciones tienen USE explícito y guardas de idempotencia.\n";
}

echo "\n=== CONTRATO DE VERIFICACIÓN DE SCHEMA DRIFT (TEST_CLEAN_SETUP) ===\n";
$cleanSetupPath = $projectRoot . DIRECTORY_SEPARATOR . 'scratch' . DIRECTORY_SEPARATOR . 'test_clean_setup.php';
$cleanSetupContent = file_get_contents($cleanSetupPath);
$driftCheckErrors = [];
$requiredDriftElements = [
    'random_bytes' => 'Generador criptográfico random_bytes() para BD temporal',
    'SCHEMATA' => 'Verificación previa contra information_schema.SCHEMATA sin DROP preventivo',
    'dbCreatedByCurrentRun' => 'Flag de confirmación de creación antes de DROP',
    'ORDINAL_POSITION' => 'Comparación de posición ordinal de columnas',
    'COLUMN_TYPE' => 'Comparación de tipos de columna',
    'IS_NULLABLE' => 'Comparación de nulabilidad',
    'COLUMN_DEFAULT' => 'Comparación de valores por defecto',
    'EXTRA' => 'Comparación de atributos EXTRA',
    'GENERATION_EXPRESSION' => 'Comparación de expresiones generadas',
    'NON_UNIQUE' => 'Comparación de unicidad de índices',
    'SEQ_IN_INDEX' => 'Comparación de orden de columnas en índices',
    'REFERENTIAL_CONSTRAINTS' => 'Comparación de claves foráneas con ON UPDATE/DELETE',
    'CHECK_CONSTRAINTS' => 'Comparación de restricciones CHECK',
];
foreach ($requiredDriftElements as $needle => $desc) {
    if (strpos($cleanSetupContent, $needle) === false) {
        $driftCheckErrors[] = "scratch/test_clean_setup.php no implementa: {$desc} ({$needle})";
    }
}
if ($driftCheckErrors) {
    $failed = true;
    foreach ($driftCheckErrors as $err) {
        echo "  [ERROR] {$err}\n";
    }
} else {
    echo "OK: test_clean_setup.php cubre comparación estructural exhaustiva de schema drift.\n";
}

// Check 4: Zero emojis in frontend
echo "\n=== CONTRATO ESTÁTICO DE FRONTEND (ZERO EMOJIS) ===\n";
$emojiRegex = '/[\x{1F600}-\x{1F64F}\x{1F300}-\x{1F5FF}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}]/u';
$frontendErrors = [];
$frontendFiles = [];
foreach ($rootFiles as $relative => $absolute) {
    if (preg_match('/\.(php|html|js|css)$/i', $relative) && (
        strpos($relative, 'admin/') === 0
        || strpos($relative, 'rendiciones/') === 0
        || in_array($relative, ['index.html', 'index.php', 'script.js', 'styles.css', 'seller_session.js'], true)
    )) {
        // Exclude backend APIs under admin/api/
        if (strpos($relative, 'admin/api/') === 0) {
            continue;
        }
        $frontendFiles[$relative] = $absolute;
        $content = file_get_contents($absolute);
        if (preg_match_all($emojiRegex, $content, $matches)) {
            $frontendErrors[] = "Emoji/dingbat detectado en {$relative} (" . count($matches[0]) . " ocurrencias)";
        }
    }
}

if ($frontendErrors) {
    $failed = true;
    foreach ($frontendErrors as $err) {
        echo "  [ERROR] {$err}\n";
    }
} else {
    echo 'OK: ' . count($frontendFiles) . " archivos de frontend sin emojis ni dingbats.\n";
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
