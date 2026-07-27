<?php
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

// 5. Captura y validación de entradas de cabecera
$errors = [];

$empresa_id = filter_input(INPUT_POST, 'empresa_id', FILTER_VALIDATE_INT);
$numero_factura = trim($_POST['numero_factura'] ?? '');
$rut_cliente = trim($_POST['rut_cliente'] ?? '');
$razon_social_cliente = trim($_POST['razon_social_cliente'] ?? '');
$monto_total_factura = filter_input(INPUT_POST, 'monto_total_factura', FILTER_VALIDATE_FLOAT);
$email_cliente = trim($_POST['email_cliente'] ?? '');
$email_tesoreria = trim($_POST['email_tesoreria'] ?? '');

if (!$empresa_id) $errors[] = 'empresa_id es requerido y debe ser entero';
if (empty($numero_factura)) $errors[] = 'numero_factura es requerido';
if (empty($rut_cliente)) $errors[] = 'rut_cliente es requerido';
if (empty($razon_social_cliente)) $errors[] = 'razon_social_cliente es requerida';
if (empty($email_tesoreria)) $errors[] = 'email_tesoreria es requerido';

// Validar arreglos de cheques
$bancos = $_POST['banco'] ?? [];
$numeros_cheque = $_POST['numero_cheque'] ?? [];
$montos_cheque = $_POST['monto_cheque'] ?? [];
$fechas_vencimiento = $_POST['fecha_vencimiento'] ?? [];
$comentarios_cheque = $_POST['comentario_cheque'] ?? [];
$fotos_cheque = $_FILES['foto_cheque'] ?? null;

if (!is_array($bancos) || count($bancos) === 0) {
    $errors[] = 'Se requiere al menos un cheque';
}

$numCheques = is_array($bancos) ? count($bancos) : 0;

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos', 'errors' => $errors]);
    exit;
}

// 6. Lógica de negocio transaccional
$archivosFisicosSubidos = [];
try {
    $pdo = Database::getCobranzasConnection();

    // Validar existencia de la empresa
    $stmtEmp = $pdo->prepare('SELECT nombre FROM empresas WHERE id = :id');
    $stmtEmp->execute([':id' => $empresa_id]);
    $empresaRow = $stmtEmp->fetch();
    if (!$empresaRow) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'La empresa especificada no existe']);
        exit;
    }
    $empresa_nombre = $empresaRow['nombre'];

    // Procesar fotos de cheques individuales
    $chequesParaInsertar = [];
    for ($i = 0; $i < $numCheques; $i++) {
        $banco = trim($bancos[$i] ?? '');
        $numChq = trim($numeros_cheque[$i] ?? '');
        $monto = (float) ($montos_cheque[$i] ?? 0);
        $fechaVec = trim($fechas_vencimiento[$i] ?? '');
        $comentario = trim($comentarios_cheque[$i] ?? '');

        if (empty($banco) || empty($numChq) || $monto <= 0 || empty($fechaVec)) {
            throw new InvalidArgumentException("Datos incompletos en el cheque N° " . ($i + 1));
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fechaVec)) {
            throw new InvalidArgumentException("El formato de fecha de vencimiento es inválido para el cheque N° " . ($i + 1));
        }
        $parts = explode('-', $fechaVec);
        $year = (int)$parts[0];
        if ($year < 1000 || $year > 9999) {
            throw new InvalidArgumentException("El año de vencimiento debe estar entre 1000 y 9999 para el cheque N° " . ($i + 1));
        }

        // Estructura $_FILES para arreglos múltiples
        if (!isset($fotos_cheque['name'][$i])) {
            throw new InvalidArgumentException("Falta la foto para el cheque N° " . ($i + 1));
        }

        $fileDataIndiv = [
            'name' => $fotos_cheque['name'][$i],
            'type' => $fotos_cheque['type'][$i],
            'tmp_name' => $fotos_cheque['tmp_name'][$i],
            'error' => $fotos_cheque['error'][$i],
            'size' => $fotos_cheque['size'][$i],
        ];

        $fotoChequeUrl = procesarSubidaArchivo($fileDataIndiv, $empresa_id, 'cheques');
        $archivosFisicosSubidos[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $fotoChequeUrl);

        $chequesParaInsertar[] = [
            'banco' => $banco,
            'numero_cheque' => $numChq,
            'monto' => $monto,
            'fecha_vencimiento' => $fechaVec,
            'foto_cheque_url' => $fotoChequeUrl,
            'comentario' => $comentario !== '' ? $comentario : null
        ];
    }

    // Estado inicial para el Paso 1
    $estadoInicial = 'PENDIENTE_ENVIO';

    // Iniciar transacción SQL
    $pdo->beginTransaction();

    // 1. Insertar cobranza
    $stmtCobranza = $pdo->prepare('INSERT INTO cobranzas (
        empresa_id, vendedor_id, vendedor_nombre, numero_factura, rut_cliente,
        razon_social_cliente, monto_total_factura, email_cliente, email_tesoreria,
        tipo_entrega, numero_seguimiento, comprobante_url, estado
    ) VALUES (
        :empresa_id, :vendedor_id, :vendedor_nombre, :numero_factura, :rut_cliente,
        :razon_social_cliente, :monto_total_factura, :email_cliente, :email_tesoreria,
        NULL, NULL, NULL, :estado
    )');

    $stmtCobranza->execute([
        ':empresa_id' => $empresa_id,
        ':vendedor_id' => (APP_ENV === 'local') ? 1 : $usuario_id,
        ':vendedor_nombre' => (APP_ENV === 'local') ? 'Sistema' : null,
        ':numero_factura' => $numero_factura,
        ':rut_cliente' => $rut_cliente,
        ':razon_social_cliente' => $razon_social_cliente,
        ':monto_total_factura' => $monto_total_factura ?: null,
        ':email_cliente' => $email_cliente !== '' ? $email_cliente : null,
        ':email_tesoreria' => $email_tesoreria,
        ':estado' => $estadoInicial
    ]);

    $cobranza_id = (int) $pdo->lastInsertId();

    // 2. Insertar cheques
    $stmtCheque = $pdo->prepare('INSERT INTO cheques (
        cobranza_id, banco, numero_cheque, monto, fecha_vencimiento, foto_cheque_url, comentario
    ) VALUES (
        :cobranza_id, :banco, :numero_cheque, :monto, :fecha_vencimiento, :foto_cheque_url, :comentario
    )');

    foreach ($chequesParaInsertar as $chq) {
        $stmtCheque->execute([
            ':cobranza_id' => $cobranza_id,
            ':banco' => $chq['banco'],
            ':numero_cheque' => $chq['numero_cheque'],
            ':monto' => $chq['monto'],
            ':fecha_vencimiento' => $chq['fecha_vencimiento'],
            ':foto_cheque_url' => $chq['foto_cheque_url'],
            ':comentario' => $chq['comentario']
        ]);
    }

    // 3. Insertar historial de estado inicial (estado_anterior NULL)
    $stmtHist = $pdo->prepare('INSERT INTO historial_estados (
        cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario
    ) VALUES (
        :cobranza_id, :usuario_id, NULL, :estado_nuevo, :comentario
    )');

    $stmtHist->execute([
        ':cobranza_id' => $cobranza_id,
        ':usuario_id' => $usuario_id,
        ':estado_nuevo' => $estadoInicial,
        ':comentario' => 'Cobranza ingresada desde la App Vendedor'
    ]);

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Cobranza registrada con éxito',
        'cobranza_id' => $cobranza_id
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
    error_log('[guardar_cobranza.php] Error: ' . $e->getMessage());
    http_response_code(500);
    $msg = (defined('APP_ENV') && APP_ENV === 'local') ? $e->getMessage() : 'Error al guardar la cobranza. Intente nuevamente.';
    echo json_encode(['success' => false, 'message' => $msg]);
}
