<?php
/**
 * api/completar_envio.php — Paso 2 del flujo de cobranza
 * 
 * Permite al vendedor adjuntar el comprobante físico de envío y 
 * cambiar el estado de la cobranza a EN_TRANSITO o ENTREGADO_SANTIAGO.
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
require_once __DIR__ . '/../services/MailService.php';

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
$tipo_entrega = trim($_POST['tipo_entrega'] ?? '');
$numero_seguimiento = trim($_POST['numero_seguimiento'] ?? '');

if (!$cobranza_id) $errors[] = 'cobranza_id es requerido y debe ser entero';
if (!in_array($tipo_entrega, ['CHILEXPRESS', 'PRESENCIAL_SANTIAGO'], true)) {
    $errors[] = 'tipo_entrega debe ser CHILEXPRESS o PRESENCIAL_SANTIAGO';
}

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos', 'errors' => $errors]);
    exit;
}

$archivosFisicosSubidos = [];
try {
    $pdo = Database::getCobranzasConnection();

    // 1. Validar que la cobranza exista, esté PENDIENTE_ENVIO y pertenezca al vendedor (IDOR check)
    //    En producción: vendedor_id = :uid siempre.
    //    En local (bypass): si uid es el usuario 1 (Sistema), se acepta cualquier cobranza.
    $sql = '
        SELECT c.*, e.nombre AS empresa_nombre 
        FROM cobranzas c 
        JOIN empresas e ON c.empresa_id = e.id
        WHERE c.id = :id
    ';
    $params = [':id' => $cobranza_id];

    if (APP_ENV !== 'local') {
        $sql .= ' AND (c.vendedor_id = :uid OR c.vendedor_id IS NULL)';
        $params[':uid'] = $usuario_id;
    }

    $stmtCob = $pdo->prepare($sql);
    $stmtCob->execute($params);
    $cobranza = $stmtCob->fetch(PDO::FETCH_ASSOC);

    if (!$cobranza) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cobranza no encontrada o sin permisos de acceso']);
        exit;
    }

    if ($cobranza['estado'] !== 'PENDIENTE_ENVIO') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Esta cobranza ya ha sido gestionada anteriormente']);
        exit;
    }

    // 2. Procesar el comprobante de envío según el tipo
    $comprobante_url = null;
    if ($tipo_entrega === 'CHILEXPRESS') {
        if (!isset($_FILES['foto_comprobante'])) {
            throw new InvalidArgumentException('Se requiere la foto del comprobante de Chilexpress');
        }
        $comprobante_url = procesarSubidaArchivo($_FILES['foto_comprobante'], (int)$cobranza['empresa_id'], 'comprobantes');
        $archivosFisicosSubidos[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $comprobante_url);
    } else {
        if (!isset($_FILES['foto_firma'])) {
            throw new InvalidArgumentException('Se requiere la foto de la firma de recepción de Santiago');
        }
        $comprobante_url = procesarSubidaArchivo($_FILES['foto_firma'], (int)$cobranza['empresa_id'], 'comprobantes');
        $archivosFisicosSubidos[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $comprobante_url);
    }

    // Determinar nuevo estado
    $nuevoEstado = ($tipo_entrega === 'CHILEXPRESS') ? 'EN_TRANSITO' : 'ENTREGADO_SANTIAGO';

    // Iniciar transacción
    $pdo->beginTransaction();

    // 3. Actualizar cobranza
    $stmtUpdate = $pdo->prepare('
        UPDATE cobranzas SET 
            tipo_entrega = :tipo_entrega,
            numero_seguimiento = :numero_seguimiento,
            comprobante_url = :comprobante_url,
            estado = :estado
        WHERE id = :id
    ');
    $stmtUpdate->execute([
        ':tipo_entrega' => $tipo_entrega,
        ':numero_seguimiento' => ($tipo_entrega === 'CHILEXPRESS' && $numero_seguimiento !== '') ? $numero_seguimiento : null,
        ':comprobante_url' => $comprobante_url,
        ':estado' => $nuevoEstado,
        ':id' => $cobranza_id
    ]);

    // 4. Agregar historial de estado
    $stmtHist = $pdo->prepare('
        INSERT INTO historial_estados (cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario)
        VALUES (:cobranza_id, :usuario_id, \'PENDIENTE_ENVIO\', :estado_nuevo, :comentario)
    ');
    $stmtHist->execute([
        ':cobranza_id' => $cobranza_id,
        ':usuario_id' => $usuario_id,
        ':estado_nuevo' => $nuevoEstado,
        ':comentario' => 'Envío completado por el vendedor'
    ]);

    $pdo->commit();

    // 5. Obtener lista de cheques para la notificación de correo
    $stmtChq = $pdo->prepare('SELECT banco, numero_cheque, monto, fecha_vencimiento, foto_cheque_url, comentario FROM cheques WHERE cobranza_id = :id');
    $stmtChq->execute([':id' => $cobranza_id]);
    $cheques = $stmtChq->fetchAll(PDO::FETCH_ASSOC);

    // Agregar las fotos de los cheques a la lista de archivos para adjuntar
    foreach ($cheques as $chq) {
        $archivosFisicosSubidos[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $chq['foto_cheque_url']);
    }

    // Preparar datos para MailService
    $cobranzaDataMail = [
        'id' => $cobranza['id'],
        'vendedor_nombre' => $cobranza['vendedor_nombre'],
        'empresa_nombre' => $cobranza['empresa_nombre'],
        'numero_factura' => $cobranza['numero_factura'],
        'rut_cliente' => $cobranza['rut_cliente'],
        'razon_social_cliente' => $cobranza['razon_social_cliente'],
        'tipo_entrega' => $tipo_entrega,
        'numero_seguimiento' => $numero_seguimiento,
        'email_tesoreria' => $cobranza['email_tesoreria'],
        'email_cliente' => $cobranza['email_cliente']
    ];

    MailService::enviarNotificacion($cobranzaDataMail, $cheques, $archivosFisicosSubidos);

    echo json_encode([
        'success' => true,
        'message' => 'Envío registrado y notificado con éxito',
        'nuevo_estado' => $nuevoEstado
    ]);

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
    error_log('[completar_envio.php] Error: ' . $e->getMessage());
    http_response_code(500);
    $msg = (defined('APP_ENV') && APP_ENV === 'local') ? $e->getMessage() : 'Error al registrar el envío. Intente nuevamente.';
    echo json_encode(['success' => false, 'message' => $msg]);
}
