<?php
/**
 * api/editar_cheques.php
 * 
 * Permite al vendedor modificar los cheques de una cobranza que está
 * en estado PENDIENTE_ENVIO. Permite editar campos, cambiar la foto,
 * agregar nuevos cheques o eliminar cheques existentes.
 */

// 1. Headers obligatorios
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// 2. Imports
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

// 3. Solo aceptar el método POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// 4. Autenticación (middleware)
$usuario_id = getUsuarioActual();

// Auxiliar para subir imágenes de forma segura
function procesarSubidaArchivo(array $fileData, int $empresa_id, string $subcarpeta): string {
    $phpUploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'Archivo supera upload_max_filesize en php.ini',
        UPLOAD_ERR_FORM_SIZE  => 'Archivo supera MAX_FILE_SIZE del formulario',
        UPLOAD_ERR_PARTIAL    => 'Archivo subido parcialmente',
        UPLOAD_ERR_NO_FILE    => 'No se recibió ningún archivo',
        UPLOAD_ERR_NO_TMP_DIR => 'Falta directorio temporal en servidor',
        UPLOAD_ERR_CANT_WRITE => 'No se pudo escribir en disco',
        UPLOAD_ERR_EXTENSION  => 'Extensión PHP bloqueó la subida',
    ];
    if (!isset($fileData['error']) || $fileData['error'] !== UPLOAD_ERR_OK) {
        $code = $fileData['error'] ?? -1;
        $desc = $phpUploadErrors[$code] ?? "Código desconocido: {$code}";
        throw new InvalidArgumentException("Error al subir archivo de {$subcarpeta}: {$desc}");
    }

    $tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic'];
    $mime = mime_content_type($fileData['tmp_name']);
    if (!in_array($mime, $tiposPermitidos, true)) {
        throw new InvalidArgumentException("Tipo de archivo no permitido en {$subcarpeta}: {$mime}");
    }

    if ($fileData['size'] > 10 * 1024 * 1024) {
        throw new InvalidArgumentException("El archivo de {$subcarpeta} supera los 10MB permitidos");
    }

    $ext = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
    $nombreOriginalSanitizado = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($fileData['name']));
    $nombreGuardado = uniqid() . '_' . $nombreOriginalSanitizado;

    $mesAno = date('Y-m');
    $dirRelativo = "uploads/{$empresa_id}/{$mesAno}/{$subcarpeta}";
    $dirAbsoluto = UPLOADS_BASE_PATH . "/{$empresa_id}/{$mesAno}/{$subcarpeta}";

    if (!is_dir($dirAbsoluto)) {
        if (!mkdir($dirAbsoluto, 0755, true)) {
            throw new RuntimeException("No se pudo crear el directorio de destino");
        }
    }

    $rutaAbsolutaCompleta = $dirAbsoluto . '/' . $nombreGuardado;
    if (!move_uploaded_file($fileData['tmp_name'], $rutaAbsolutaCompleta)) {
        throw new RuntimeException("No se pudo mover el archivo subido");
    }

    return $dirRelativo . '/' . $nombreGuardado;
}

// 5. Captura y validación de entradas
$errors = [];
$cobranza_id = filter_input(INPUT_POST, 'cobranza_id', FILTER_VALIDATE_INT);

if (!$cobranza_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'cobranza_id es requerido y debe ser entero']);
    exit;
}

$cheque_ids = $_POST['cheque_id'] ?? [];
$montos_cheque = $_POST['monto_cheque'] ?? [];
$fechas_vencimiento = $_POST['fecha_vencimiento'] ?? [];
$comentarios_cheque = $_POST['comentario_cheque'] ?? [];
$eliminados_ids = $_POST['eliminados_ids'] ?? []; // Array con IDs de cheques a eliminar
$justificacion_descuadre = trim($_POST['justificacion_descuadre'] ?? '');

if (!is_array($montos_cheque) || count($montos_cheque) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Se requiere al menos un cheque en la cobranza']);
    exit;
}

$numCheques = count($montos_cheque);
$fotos_cheque = $_FILES['foto_cheque'] ?? null;

$archivosFisicosSubidos = [];
$archivosFisicosPorEliminar = [];

try {
    $pdo = Database::getCobranzasConnection();

    // Iniciar transacción ANTES de validar el estado para bloquear la fila contra concurrencia
    $pdo->beginTransaction();

    // 1. Obtener datos de la cobranza y validar pertenencia / estado
    $stmtCob = $pdo->prepare('SELECT id, empresa_id, vendedor_id, estado FROM cobranzas WHERE id = :id FOR UPDATE');
    $stmtCob->execute([':id' => $cobranza_id]);
    $cobranza = $stmtCob->fetch();

    if (!$cobranza) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cobranza no encontrada']);
        exit;
    }

    // Validar pertenencia
    if (!(defined('APP_ENV') && APP_ENV === 'local' && $usuario_id === 1) && (int)$cobranza['vendedor_id'] !== (int)$usuario_id) {
        $pdo->rollBack();
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'No tiene permisos para modificar esta cobranza']);
        exit;
    }

    // Validar estado
    if ($cobranza['estado'] !== 'PENDIENTE_ENVIO') {
        $pdo->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Solo se pueden editar cobranzas en estado Pendiente Envío']);
        exit;
    }

    $empresa_id = (int)$cobranza['empresa_id'];

    // 2. Procesar Eliminación de Cheques
    if (is_array($eliminados_ids) && !empty($eliminados_ids)) {
        // Filtrar IDs para evitar inyecciones
        $eliminadosValidos = array_filter($eliminados_ids, 'is_numeric');
        if (!empty($eliminadosValidos)) {
            // Obtener rutas de fotos de cheques a eliminar
            $inClause = implode(',', array_fill(0, count($eliminadosValidos), '?'));
            $stmtGetPhotos = $pdo->prepare("SELECT id, foto_cheque_url FROM cheques WHERE id IN ($inClause) AND cobranza_id = ?");
            
            $params = array_merge($eliminadosValidos, [$cobranza_id]);
            $stmtGetPhotos->execute($params);
            $chequesEliminar = $stmtGetPhotos->fetchAll();

            foreach ($chequesEliminar as $chequeElim) {
                if ($chequeElim['foto_cheque_url']) {
                    $archivosFisicosPorEliminar[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $chequeElim['foto_cheque_url']);
                }
            }

            // Eliminar de base de datos
            $stmtDel = $pdo->prepare("DELETE FROM cheques WHERE id IN ($inClause) AND cobranza_id = ?");
            $stmtDel->execute($params);
        }
    }

    // 3. Procesar Actualizaciones e Inserciones
    for ($i = 0; $i < $numCheques; $i++) {
        $chqId = $cheque_ids[$i] ?? '';
        $monto = (float)($montos_cheque[$i] ?? 0);
        $fechaVec = trim($fechas_vencimiento[$i] ?? '');
        $comentario = trim($comentarios_cheque[$i] ?? '');
        $comentarioVal = ($comentario !== '') ? $comentario : null;

        if ($monto <= 0 || empty($fechaVec)) {
            throw new InvalidArgumentException("Campos incompletos en el cheque N° " . ($i + 1));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaVec)) {
            throw new InvalidArgumentException("El formato de fecha de vencimiento es inválido para el cheque N° " . ($i + 1));
        }
        $parts = explode('-', $fechaVec);
        $year = (int)$parts[0];
        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException("El año de vencimiento debe estar entre 1000 y 9999 para el cheque N° " . ($i + 1));
        }

        $fotoSubida = false;
        $fileDataIndiv = null;

        // Comprobar si se cargó un archivo para esta posición
        if ($fotos_cheque && isset($fotos_cheque['name'][$i]) && $fotos_cheque['error'][$i] === UPLOAD_ERR_OK) {
            $fileDataIndiv = [
                'name' => $fotos_cheque['name'][$i],
                'type' => $fotos_cheque['type'][$i],
                'tmp_name' => $fotos_cheque['tmp_name'][$i],
                'error' => $fotos_cheque['error'][$i],
                'size' => $fotos_cheque['size'][$i],
            ];
            $fotoSubida = true;
        }

        if (empty($chqId) || strpos($chqId, 'nuevo_') === 0 || !is_numeric($chqId)) {
            // --- INSERCIÓN DE NUEVO CHEQUE ---
            if (!$fotoSubida) {
                throw new InvalidArgumentException("Se requiere foto para el cheque nuevo N° " . ($i + 1));
            }

            $fotoChequeUrl = procesarSubidaArchivo($fileDataIndiv, $empresa_id, 'cheques');
            $archivosFisicosSubidos[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $fotoChequeUrl);

            $stmtIns = $pdo->prepare('INSERT INTO cheques (
                cobranza_id, banco, numero_cheque, monto, fecha_vencimiento, foto_cheque_url, comentario
            ) VALUES (
                :cobranza_id, NULL, NULL, :monto, :fecha_vencimiento, :foto_cheque_url, :comentario
            )');

            $stmtIns->execute([
                ':cobranza_id' => $cobranza_id,
                ':monto' => $monto,
                ':fecha_vencimiento' => $fechaVec,
                ':foto_cheque_url' => $fotoChequeUrl,
                ':comentario' => $comentarioVal
            ]);
        } else {
            // --- ACTUALIZACIÓN DE CHEQUE EXISTENTE ---
            $chqIdInt = (int)$chqId;

            // Obtener foto vieja por si se reemplaza
            $stmtOld = $pdo->prepare('SELECT foto_cheque_url FROM cheques WHERE id = :id AND cobranza_id = :cobranza_id');
            $stmtOld->execute([':id' => $chqIdInt, ':cobranza_id' => $cobranza_id]);
            $oldCheque = $stmtOld->fetch();
            
            if (!$oldCheque) {
                throw new InvalidArgumentException("El cheque especificado no pertenece a esta cobranza");
            }

            if ($fotoSubida) {
                // Si sube foto nueva, procesar y encolar la anterior para borrado
                $fotoChequeUrl = procesarSubidaArchivo($fileDataIndiv, $empresa_id, 'cheques');
                $archivosFisicosSubidos[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $fotoChequeUrl);

                if ($oldCheque['foto_cheque_url']) {
                    $archivosFisicosPorEliminar[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $oldCheque['foto_cheque_url']);
                }

                $stmtUpd = $pdo->prepare('UPDATE cheques SET
                    monto = :monto,
                    fecha_vencimiento = :fecha_vencimiento, foto_cheque_url = :foto_cheque_url, comentario = :comentario
                    WHERE id = :id AND cobranza_id = :cobranza_id');

                $stmtUpd->execute([
                    ':monto' => $monto,
                    ':fecha_vencimiento' => $fechaVec,
                    ':foto_cheque_url' => $fotoChequeUrl,
                    ':comentario' => $comentarioVal,
                    ':id' => $chqIdInt,
                    ':cobranza_id' => $cobranza_id
                ]);
            } else {
                // Actualizar datos sin cambiar la foto
                $stmtUpd = $pdo->prepare('UPDATE cheques SET
                    monto = :monto,
                    fecha_vencimiento = :fecha_vencimiento, comentario = :comentario
                    WHERE id = :id AND cobranza_id = :cobranza_id');

                $stmtUpd->execute([
                    ':monto' => $monto,
                    ':fecha_vencimiento' => $fechaVec,
                    ':comentario' => $comentarioVal,
                    ':id' => $chqIdInt,
                    ':cobranza_id' => $cobranza_id
                ]);
            }
        }
    }

    // 3.5. Actualizar justificación de descuadre si fue enviada
    if ($justificacion_descuadre !== '') {
        $stmtUpdCobranza = $pdo->prepare('UPDATE cobranzas SET justificacion_descuadre = :just_desc WHERE id = :id');
        $stmtUpdCobranza->execute([
            ':just_desc' => $justificacion_descuadre,
            ':id' => $cobranza_id
        ]);
    }

    // 4. Registrar evento en historial
    $hist_usuario_id = $usuario_id;
    if ($hist_usuario_id !== null && $hist_usuario_id !== '') {
        $stmtCheckHistUser = $pdo->prepare('SELECT id FROM usuarios WHERE id = :id');
        $stmtCheckHistUser->execute([':id' => $hist_usuario_id]);
        if (!$stmtCheckHistUser->fetchColumn()) {
            $hist_usuario_id = 1; // Fallback a Usuario Sistema
        }
    } else {
        $hist_usuario_id = 1;
    }

    $stmtHist = $pdo->prepare('INSERT INTO historial_estados (
        cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario
    ) VALUES (
        :cobranza_id, :usuario_id, :estado_anterior, :estado_nuevo, :comentario
    )');

    $stmtHist->execute([
        ':cobranza_id' => $cobranza_id,
        ':usuario_id' => $hist_usuario_id,
        ':estado_anterior' => 'PENDIENTE_ENVIO',
        ':estado_nuevo' => 'PENDIENTE_ENVIO',
        ':comentario' => 'Cheques modificados/actualizados por el vendedor'
    ]);

    $pdo->commit();

    // Eliminar fotos obsoletas del almacenamiento físico
    foreach ($archivosFisicosPorEliminar as $fileToDelete) {
        if (file_exists($fileToDelete)) {
            @unlink($fileToDelete);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Cobranza modificada con éxito']);

} catch (InvalidArgumentException $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    foreach ($archivosFisicosSubidos as $pathFile) {
        if (file_exists($pathFile)) @unlink($pathFile);
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    foreach ($archivosFisicosSubidos as $pathFile) {
        if (file_exists($pathFile)) @unlink($pathFile);
    }
    error_log('[editar_cheques.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de servidor interno al guardar modificaciones']);
}
