<?php
/**
 * Cron: Purga Segura de Fotos de Cheques y Comprobantes Post-Vencimiento (3 Meses)
 * 
 * Script automatizado para liberar espacio en disco eliminando los archivos físicos
 * de imágenes (fotos de cheques y comprobantes asociados) cuya fecha de vencimiento
 * tenga más de 3 meses de antigüedad respecto a la fecha actual.
 * 
 * REGLAS DE SEGURIDAD ESTRICTAS:
 * - CERO DELETE en base de datos: registros, montos, RUTs y trazabilidad permanecen 100% intactos.
 * - Solo se actualiza foto_cheque_url / comprobante_url a NULL y se registra timestamp de purga.
 * - Validación estricta anti Path-Traversal con realpath() y comprobación de prefijo UPLOADS_BASE_PATH.
 * - Verificación con is_file() antes de unlink().
 * - Limpieza de referencias huérfanas en BD si el archivo ya no existe físicamente.
 * - Soporte para modo simulación (--dry-run / ?dry_run=1).
 * - Procesamiento por lotes (BATCH_SIZE = 200).
 * - Control de acceso CLI o HTTP con token CRON_SECRET_KEY.
 */

// Sincronizar zona horaria de Chile
date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

// -------------------------------------------------------------------------
// 1. CONTROL DE ACCESO Y SEGURIDAD
// -------------------------------------------------------------------------
$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    // Encabezados de seguridad para ejecución web
    header('Content-Type: application/json; charset=utf-8');
    
    $providedToken = $_GET['cron_token'] ?? $_POST['cron_token'] ?? '';
    $expectedToken = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : 'cobranzas_cron_secret_2026';
    
    if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'message' => 'Acceso no autorizado al Cron Job de Purga de Fotos.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -------------------------------------------------------------------------
// 2. DETECCIÓN DE MODO DRY-RUN (SIMULACIÓN)
// -------------------------------------------------------------------------
$isDryRun = false;

if ($isCli) {
    global $argv;
    if (isset($argv) && is_array($argv)) {
        foreach ($argv as $arg) {
            if ($arg === '--dry-run' || $arg === '-d') {
                $isDryRun = true;
                break;
            }
        }
    }
} else {
    $dryRunParam = $_GET['dry_run'] ?? $_POST['dry_run'] ?? '0';
    if ($dryRunParam === '1' || strtolower((string)$dryRunParam) === 'true') {
        $isDryRun = true;
    }
}

// -------------------------------------------------------------------------
// 3. CONSTANTES Y CONFIGURACIÓN
// -------------------------------------------------------------------------
const MESES_ANTIGUEDAD = 3;
const BATCH_SIZE = 200;
const MAX_BATCHES = 250; // Límite de seguridad (hasta 50,000 registros por ejecución)

// -------------------------------------------------------------------------
// 4. FUNCIONES AUXILIARES DE LOGGING Y SEGURIDAD
// -------------------------------------------------------------------------
function logPurga(string $mensaje, bool $isCli = true): void {
    $formatted = "[" . date('Y-m-d H:i:s') . "] " . $mensaje . "\n";
    if ($isCli) {
        echo $formatted;
    }
    
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logDir . '/cron_purga_fotos.log', $formatted, FILE_APPEND);
}

/**
 * Resuelve y valida una ruta de archivo dentro de UPLOADS_BASE_PATH.
 * Protege estrictamente contra ataques de Path Traversal.
 * 
 * @param string|null $urlRelativa Ruta almacenada en base de datos (ej. "uploads/1/2026-05/cheques/foto.jpg")
 * @return string|null Ruta absoluta validada si el archivo existe físicamente dentro de uploads, o null
 */
function resolverRutaSegura(?string $urlRelativa): ?string {
    if (empty($urlRelativa) || !is_string($urlRelativa)) {
        return null;
    }
    
    $urlRelativa = trim($urlRelativa);
    if ($urlRelativa === '') {
        return null;
    }
    
    // Normalizar eliminando prefijo "uploads/" o "/uploads/" inicial
    $limpia = preg_replace('/^\/?uploads\//i', '', $urlRelativa);
    $limpia = ltrim($limpia, '/\\');
    
    $baseUploads = defined('UPLOADS_BASE_PATH') ? UPLOADS_BASE_PATH : (__DIR__ . '/../uploads');
    $baseReal = realpath($baseUploads);
    if ($baseReal === false) {
        return null;
    }
    
    // Construir la ruta tentativa con separadores de directorio consistentes
    $rutaTentativa = $baseReal . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $limpia);
    
    // Resolver ruta real con el sistema de archivos
    $rutaReal = realpath($rutaTentativa);
    if ($rutaReal === false) {
        // El archivo no existe físicamente en disco
        return null;
    }
    
    // Validar que la ruta resuelta resida inequívocamente dentro del directorio permitido
    $prefijoEsperado = $baseReal . DIRECTORY_SEPARATOR;
    if (strpos($rutaReal, $prefijoEsperado) !== 0) {
        // Path Traversal detectado: el archivo resuelto apunta fuera de uploads/
        return null;
    }
    
    if (!is_file($rutaReal)) {
        return null;
    }
    
    return $rutaReal;
}

/**
 * Formatea bytes en una cadena legible (B, KB, MB, GB).
 */
function formatearBytes(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    }
    if ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    }
    return round($bytes / 1073741824, 2) . ' GB';
}

// -------------------------------------------------------------------------
// 5. EJECUCIÓN PRINCIPAL DEL CRON
// -------------------------------------------------------------------------
$inicioEjecucion = microtime(true);

logPurga("==================================================================", $isCli);
logPurga("INICIANDO CRON DE PURGA DE FOTOS DE CHEQUES Y COMPROBANTES VENCIDOS", $isCli);
logPurga("Modo de ejecución: " . ($isDryRun ? "DRY-RUN (SIMULACIÓN - Cero cambios)" : "PRODUCCIÓN REAL"), $isCli);
logPurga("Antigüedad requerida: > " . MESES_ANTIGUEDAD . " meses de vencimiento", $isCli);
logPurga("==================================================================", $isCli);

$stats = [
    'dry_run' => $isDryRun,
    'cheques_procesados' => 0,
    'cheques_fotos_eliminadas' => 0,
    'cheques_fotos_huerfanas_limpiadas' => 0,
    'cobranzas_procesadas' => 0,
    'cobranzas_comprobantes_eliminados' => 0,
    'cobranzas_comprobantes_huerfanos_limpiados' => 0,
    'bytes_liberados' => 0,
    'tiempo_ejecucion_segundos' => 0.0,
    'errores' => []
];

try {
    $pdo = Database::getCobranzasConnection();
    
    // Sincronizar timezone en la sesión MySQL
    $offset = date('P');
    $pdo->exec("SET time_zone = '$offset';");

    // -------------------------------------------------------------------------
    // FASE A: PURGA DE FOTOS DE CHEQUES (`cheques`)
    // -------------------------------------------------------------------------
    logPurga("--- FASE 1: Procesando fotos de cheques vencidos hace > " . MESES_ANTIGUEDAD . " meses ---", $isCli);

    $sqlSelectCheques = "
        SELECT id, cobranza_id, fecha_vencimiento, foto_cheque_url
        FROM cheques
        WHERE foto_cheque_url IS NOT NULL
          AND foto_purgada_at IS NULL
          AND fecha_vencimiento < DATE_SUB(CURDATE(), INTERVAL :meses MONTH)
        ORDER BY fecha_vencimiento ASC
        LIMIT :limit
    ";

    $stmtSelectCheques = $pdo->prepare($sqlSelectCheques);
    $stmtUpdateCheque = $pdo->prepare("
        UPDATE cheques
        SET foto_cheque_url = NULL,
            foto_purgada_at = NOW()
        WHERE id = :id
    ");

    $batchChequesCount = 0;
    $idsChequesProcesadosDryRun = [];

    while ($batchChequesCount < MAX_BATCHES) {
        $batchChequesCount++;
        
        $stmtSelectCheques->bindValue(':meses', MESES_ANTIGUEDAD, PDO::PARAM_INT);
        $stmtSelectCheques->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmtSelectCheques->execute();
        
        $cheques = $stmtSelectCheques->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cheques)) {
            break;
        }

        // Si estamos en dry-run y ya procesamos estos mismos IDs, salimos para evitar loop infinito
        if ($isDryRun) {
            $nuevos = 0;
            foreach ($cheques as $chq) {
                if (!isset($idsChequesProcesadosDryRun[$chq['id']])) {
                    $idsChequesProcesadosDryRun[$chq['id']] = true;
                    $nuevos++;
                }
            }
            if ($nuevos === 0) {
                break;
            }
        }

        foreach ($cheques as $chq) {
            $chqId = (int)$chq['id'];
            $urlRelativa = $chq['foto_cheque_url'];
            $vencimiento = $chq['fecha_vencimiento'];
            $stats['cheques_procesados']++;

            $rutaFisica = resolverRutaSegura($urlRelativa);

            if ($rutaFisica !== null && is_file($rutaFisica)) {
                $tamano = (int)@filesize($rutaFisica);
                
                if ($isDryRun) {
                    logPurga("[DRY-RUN] Cheque ID #{$chqId} (Venc: {$vencimiento}): Se eliminaría archivo {$rutaFisica} (" . formatearBytes($tamano) . ")", $isCli);
                    $stats['cheques_fotos_eliminadas']++;
                    $stats['bytes_liberados'] += $tamano;
                } else {
                    $borradoExitoso = @unlink($rutaFisica);
                    if ($borradoExitoso) {
                        $stats['cheques_fotos_eliminadas']++;
                        $stats['bytes_liberados'] += $tamano;
                        logPurga("Cheque ID #{$chqId} (Venc: {$vencimiento}): Foto eliminada físicamente (" . formatearBytes($tamano) . ")", $isCli);
                    } else {
                        logPurga("ADVERTENCIA: No se pudo eliminar archivo físico de Cheque ID #{$chqId}: {$rutaFisica}", $isCli);
                        $stats['errores'][] = "No se pudo eliminar archivo físico cheque ID #{$chqId}";
                    }
                    
                    // Actualizar BD registrando la purga
                    $stmtUpdateCheque->execute([':id' => $chqId]);
                }
            } else {
                // Archivo huérfano en base de datos (no existe físicamente en disco)
                if ($isDryRun) {
                    logPurga("[DRY-RUN] Cheque ID #{$chqId} (Venc: {$vencimiento}): Referencia huérfana en BD. Se actualizaría a NULL.", $isCli);
                    $stats['cheques_fotos_huerfanas_limpiadas']++;
                } else {
                    logPurga("Cheque ID #{$chqId} (Venc: {$vencimiento}): Archivo no encontrado en disco. Limpiando referencia huérfana en BD.", $isCli);
                    $stmtUpdateCheque->execute([':id' => $chqId]);
                    $stats['cheques_fotos_huerfanas_limpiadas']++;
                }
            }
        }

        // Si es dry-run, no seguimos ejecutando más lotes para evitar un barrido infinito
        if ($isDryRun) {
            break;
        }
    }

    // -------------------------------------------------------------------------
    // FASE B: PURGA DE COMPROBANTES DE COBRANZAS (`cobranzas`)
    // Criterio: Solo se purga el comprobante si TODOS los cheques asociados
    // a la cobranza tienen fecha_vencimiento vencida hace más de 3 meses.
    // -------------------------------------------------------------------------
    logPurga("--- FASE 2: Procesando comprobantes de cobranzas con todos sus cheques vencidos > " . MESES_ANTIGUEDAD . " meses ---", $isCli);

    $sqlSelectCobranzas = "
        SELECT c.id, c.empresa_id, c.comprobante_url
        FROM cobranzas c
        WHERE c.comprobante_url IS NOT NULL
          AND c.comprobante_purgado_at IS NULL
          AND NOT EXISTS (
              SELECT 1 FROM cheques ch
              WHERE ch.cobranza_id = c.id
                AND ch.fecha_vencimiento >= DATE_SUB(CURDATE(), INTERVAL :meses MONTH)
          )
        ORDER BY c.id ASC
        LIMIT :limit
    ";

    $stmtSelectCobranzas = $pdo->prepare($sqlSelectCobranzas);
    $stmtUpdateCobranza = $pdo->prepare("
        UPDATE cobranzas
        SET comprobante_url = NULL,
            comprobante_purgado_at = NOW()
        WHERE id = :id
    ");

    $batchCobranzasCount = 0;
    $idsCobranzasProcesadosDryRun = [];

    while ($batchCobranzasCount < MAX_BATCHES) {
        $batchCobranzasCount++;
        
        $stmtSelectCobranzas->bindValue(':meses', MESES_ANTIGUEDAD, PDO::PARAM_INT);
        $stmtSelectCobranzas->bindValue(':limit', BATCH_SIZE, PDO::PARAM_INT);
        $stmtSelectCobranzas->execute();
        
        $cobranzas = $stmtSelectCobranzas->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cobranzas)) {
            break;
        }

        if ($isDryRun) {
            $nuevos = 0;
            foreach ($cobranzas as $cob) {
                if (!isset($idsCobranzasProcesadosDryRun[$cob['id']])) {
                    $idsCobranzasProcesadosDryRun[$cob['id']] = true;
                    $nuevos++;
                }
            }
            if ($nuevos === 0) {
                break;
            }
        }

        foreach ($cobranzas as $cob) {
            $cobId = (int)$cob['id'];
            $urlRelativa = $cob['comprobante_url'];
            $stats['cobranzas_procesadas']++;

            $rutaFisica = resolverRutaSegura($urlRelativa);

            if ($rutaFisica !== null && is_file($rutaFisica)) {
                $tamano = (int)@filesize($rutaFisica);
                
                if ($isDryRun) {
                    logPurga("[DRY-RUN] Cobranza ID #{$cobId}: Se eliminaría comprobante {$rutaFisica} (" . formatearBytes($tamano) . ")", $isCli);
                    $stats['cobranzas_comprobantes_eliminados']++;
                    $stats['bytes_liberados'] += $tamano;
                } else {
                    $borradoExitoso = @unlink($rutaFisica);
                    if ($borradoExitoso) {
                        $stats['cobranzas_comprobantes_eliminados']++;
                        $stats['bytes_liberados'] += $tamano;
                        logPurga("Cobranza ID #{$cobId}: Comprobante eliminado físicamente (" . formatearBytes($tamano) . ")", $isCli);
                    } else {
                        logPurga("ADVERTENCIA: No se pudo eliminar comprobante físico de Cobranza ID #{$cobId}: {$rutaFisica}", $isCli);
                        $stats['errores'][] = "No se pudo eliminar archivo físico comprobante cobranza ID #{$cobId}";
                    }
                    
                    // Actualizar BD registrando la purga
                    $stmtUpdateCobranza->execute([':id' => $cobId]);
                }
            } else {
                // Archivo huérfano en base de datos
                if ($isDryRun) {
                    logPurga("[DRY-RUN] Cobranza ID #{$cobId}: Referencia huérfana en BD. Se actualizaría a NULL.", $isCli);
                    $stats['cobranzas_comprobantes_huerfanos_limpiados']++;
                } else {
                    logPurga("Cobranza ID #{$cobId}: Comprobante no encontrado en disco. Limpiando referencia huérfana en BD.", $isCli);
                    $stmtUpdateCobranza->execute([':id' => $cobId]);
                    $stats['cobranzas_comprobantes_huerfanos_limpiados']++;
                }
            }
        }

        if ($isDryRun) {
            break;
        }
    }

} catch (Exception $e) {
    $mensajeError = "ERROR FATAL EN CRON DE PURGA: " . $e->getMessage();
    logPurga($mensajeError, $isCli);
    $stats['errores'][] = $mensajeError;
    
    if (!$isCli) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error durante la ejecución del cron de purga.',
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
        exit(1);
    }
    exit(1);
}

// -------------------------------------------------------------------------
// 6. RESUMEN FINAL Y ESTADÍSTICAS
// -------------------------------------------------------------------------
$tiempoTotal = round(microtime(true) - $inicioEjecucion, 3);
$stats['tiempo_ejecucion_segundos'] = $tiempoTotal;
$espacioFormateado = formatearBytes($stats['bytes_liberados']);

logPurga("==================================================================", $isCli);
logPurga("RESUMEN DE EJECUCIÓN DEL CRON DE PURGA", $isCli);
logPurga("Modo: " . ($isDryRun ? "DRY-RUN (SIMULACIÓN)" : "PRODUCCIÓN REAL"), $isCli);
logPurga("Fotos de cheques analizadas: {$stats['cheques_procesados']}", $isCli);
logPurga("Fotos de cheques eliminadas de disco: {$stats['cheques_fotos_eliminadas']}", $isCli);
logPurga("Fotos de cheques huérfanas en BD limpiadas: {$stats['cheques_fotos_huerfanas_limpiadas']}", $isCli);
logPurga("Comprobantes de cobranza analizados: {$stats['cobranzas_procesadas']}", $isCli);
logPurga("Comprobantes eliminados de disco: {$stats['cobranzas_comprobantes_eliminados']}", $isCli);
logPurga("Comprobantes huérfanos en BD limpiados: {$stats['cobranzas_comprobantes_huerfanos_limpiados']}", $isCli);
logPurga("Espacio total liberado en disco: {$espacioFormateado} ({$stats['bytes_liberados']} bytes)", $isCli);
logPurga("Tiempo total de ejecución: {$tiempoTotal} segundos", $isCli);
logPurga("Estado final: " . (empty($stats['errores']) ? "EXITOSO" : "COMPLETADO CON ADVERTENCIAS"), $isCli);
logPurga("==================================================================\n", $isCli);

if (!$isCli) {
    echo json_encode([
        'success' => empty($stats['errores']),
        'dry_run' => $isDryRun,
        'meses_antiguedad' => MESES_ANTIGUEDAD,
        'stats' => [
            'cheques_procesados' => $stats['cheques_procesados'],
            'cheques_fotos_eliminadas' => $stats['cheques_fotos_eliminadas'],
            'cheques_fotos_huerfanas_limpiadas' => $stats['cheques_fotos_huerfanas_limpiadas'],
            'cobranzas_procesadas' => $stats['cobranzas_procesadas'],
            'cobranzas_comprobantes_eliminados' => $stats['cobranzas_comprobantes_eliminados'],
            'cobranzas_comprobantes_huerfanos_limpiados' => $stats['cobranzas_comprobantes_huerfanos_limpiados'],
            'bytes_liberados' => $stats['bytes_liberados'],
            'espacio_liberado_formateado' => $espacioFormateado,
            'tiempo_ejecucion_segundos' => $tiempoTotal
        ],
        'errores' => $stats['errores']
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

exit(empty($stats['errores']) ? 0 : 2);
