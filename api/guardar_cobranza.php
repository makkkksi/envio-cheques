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
    $mime = function_exists('mime_content_type') ? mime_content_type($fileData['tmp_name']) : ($fileData['type'] ?? 'image/jpeg');
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
    $moved = is_uploaded_file($fileData['tmp_name']) 
        ? move_uploaded_file($fileData['tmp_name'], $rutaAbsolutaCompleta)
        : @copy($fileData['tmp_name'], $rutaAbsolutaCompleta);

    if (!$moved) {
        throw new RuntimeException("No se pudo mover el archivo subido");
    }

    return $dirRelativo . '/' . $nombreGuardado;
}

// 5. Captura y validación de entradas de cabecera
$errors = [];

$empresa_id = filter_input(INPUT_POST, 'empresa_id', FILTER_VALIDATE_INT) ?: null;
$numero_factura = trim($_POST['numero_factura'] ?? '');
$rut_cliente = trim($_POST['rut_cliente'] ?? '');
$razon_social_cliente = trim($_POST['razon_social_cliente'] ?? '');
$monto_total_factura = filter_input(INPUT_POST, 'monto_total_factura', FILTER_VALIDATE_FLOAT);
$email_cliente = trim($_POST['email_cliente'] ?? '');
$email_tesoreria = trim($_POST['email_tesoreria'] ?? '');
$justificacion_descuadre = trim($_POST['justificacion_descuadre'] ?? '');

// Captura de facturas múltiples (si vienen en el payload JSON o Form)
$facturasInput = $_POST['facturas'] ?? null;
$facturasLista = [];

if ($facturasInput) {
    if (is_string($facturasInput)) {
        $facturasLista = json_decode($facturasInput, true) ?: [];
    } elseif (is_array($facturasInput)) {
        $facturasLista = $facturasInput;
    }
}

if (empty($rut_cliente)) $errors[] = 'rut_cliente es requerido';
if (empty($razon_social_cliente)) $errors[] = 'razon_social_cliente es requerida';

// Validar arreglos de cheques
$montos_cheque = $_POST['monto_cheque'] ?? [];
$fechas_vencimiento = $_POST['fecha_vencimiento'] ?? [];
$comentarios_cheque = $_POST['comentario_cheque'] ?? [];
$fotos_cheque = $_FILES['foto_cheque'] ?? null;

if (!is_array($montos_cheque) || count($montos_cheque) === 0) {
    $errors[] = 'Se requiere al menos un cheque';
}

$numCheques = is_array($montos_cheque) ? count($montos_cheque) : 0;

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan campos requeridos', 'errors' => $errors]);
    exit;
}

// 6. Lógica de negocio transaccional
$archivosFisicosSubidos = [];
try {
    $pdo = Database::getCobranzasConnection();

    // Procesar fotos de cheques individuales
    $chequesParaInsertar = [];
    for ($i = 0; $i < $numCheques; $i++) {
        $monto = (float) ($montos_cheque[$i] ?? 0);
        $fechaVec = trim($fechas_vencimiento[$i] ?? '');
        $comentario = trim($comentarios_cheque[$i] ?? '');

        if ($monto <= 0 || empty($fechaVec)) {
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

        $subfolderEmpresa = $empresa_id ?: 1;
        $fotoChequeUrl = procesarSubidaArchivo($fileDataIndiv, $subfolderEmpresa, 'cheques');
        $archivosFisicosSubidos[] = UPLOADS_BASE_PATH . '/' . preg_replace('/^uploads\//', '', $fotoChequeUrl);

        $chequesParaInsertar[] = [
            'monto' => $monto,
            'fecha_vencimiento' => $fechaVec,
            'foto_cheque_url' => $fotoChequeUrl,
            'comentario' => $comentario !== '' ? $comentario : null
        ];
    }

    // Estado inicial para el Paso 1
    $estadoInicial = 'PENDIENTE_ENVIO';

    // Obtener e identificar el vendedor (ignorar POST, usar solo identidad validada)
    $vendedor_id = $_SESSION['vendedor_auth']['vendedor_id'] ?? $usuario_id;
    $vendedor_nombre = null;

    if (!empty($_SESSION['vendedor_auth']['nombre']) && $_SESSION['vendedor_auth']['nombre'] !== 'Sin Asignar') {
        $vendedor_nombre = $_SESSION['vendedor_auth']['nombre'];
    } elseif ($usuario_id) {
        $stmtUsr = $pdo->prepare('SELECT nombre FROM usuarios WHERE id = :id');
        $stmtUsr->execute([':id' => $usuario_id]);
        $usrRow = $stmtUsr->fetch();
        if ($usrRow && !empty($usrRow['nombre'])) {
            $vendedor_nombre = $usrRow['nombre'];
        }
    }

    if (!$vendedor_nombre || $vendedor_nombre === 'Sin Asignar') {
        $vendedor_nombre = ($vendedor_id !== null && $vendedor_id !== '') ? "Vendedor ID {$vendedor_id}" : "Vendedor Terreno";
    }

    // Normalizar lista de facturas a insertar
    if (empty($facturasLista) && $numero_factura !== '') {
        $facturasLista[] = [
            'empresa_id' => $empresa_id ?: 1,
            'codigo_empresa' => 'EMP01',
            'numero_factura' => $numero_factura,
            'total_cuota' => $monto_total_factura ?: 0,
            'saldo_cuota' => $monto_total_factura ?: 0,
            'monto_cubierto' => $monto_total_factura ?: 0
        ];
    }

    // Si viene lista de facturas, derivar empresa_id y numero_factura primario y recalcular el total en servidor
    if (!empty($facturasLista)) {
        $primeraFactura = $facturasLista[0];
        $empresa_id = $empresa_id ?: ($primeraFactura['empresa_id'] ?? 1);
        $numero_factura = $numero_factura ?: ($primeraFactura['numero_factura'] ?? '');
        
        // Recalcular el total siempre en el servidor desde la suma de cuotas seleccionadas
        $monto_total_factura = array_reduce($facturasLista, function($sum, $item) {
            return $sum + (float)($item['monto_cubierto'] ?? $item['saldo_cuota'] ?? 0);
        }, 0.0);
    }
    
    // Obtener el nombre de la empresa para usarlo como valor por defecto en emitido_a
    $stmtEmpresa = $pdo->prepare('SELECT nombre FROM empresas WHERE id = :id');
    $stmtEmpresa->execute([':id' => $empresa_id]);
    $empresaRow = $stmtEmpresa->fetch();
    $empresa_nombre_default = $empresaRow ? $empresaRow['nombre'] : null;

    // --- [INICIO] SEGURIDAD SEC-04: Re-Validación de Saldos contra el ERP ---
    if (!empty($facturasLista)) {
        $clirutVal = explode('-', $rut_cliente)[0];
        $clirutVal = preg_replace('/[^0-9]/', '', $clirutVal);

        if (!empty($clirutVal)) {
            // Traer la deuda real del ERP para este cliente
            $stmtDeudaErp = $pdo->prepare("
                SELECT 
                    c.empresa AS codigo_empresa, 
                    c.docto AS numero_factura, 
                    CAST(c.saldo_cuota AS DECIMAL(12,0)) AS saldo_cuota
                FROM bd_automarco.tbl_cobranza c
                WHERE c.clirut = :clirut
                  AND c.empresa != 'EMP07'
            ");
            $stmtDeudaErp->execute([':clirut' => $clirutVal]);
            $deudaErpRaw = $stmtDeudaErp->fetchAll(PDO::FETCH_ASSOC);

            $deudaErpMap = [];
            foreach ($deudaErpRaw as $row) {
                $key = trim($row['codigo_empresa']) . '_' . trim($row['numero_factura']);
                $deudaErpMap[$key][] = [
                    'saldo_cuota' => (float)$row['saldo_cuota'],
                    'usada' => false
                ];
            }

            // Contrastar payload contra realidad ERP
            foreach ($facturasLista as $fItem) {
                $codEmp = trim($fItem['codigo_empresa'] ?? 'EMP01');
                $numDoc = trim($fItem['numero_factura']);
                $montoCubierto = (float)($fItem['monto_cubierto'] ?? $fItem['saldo_cuota'] ?? 0);
                $saldoCuotaFrontend = (float)($fItem['saldo_cuota'] ?? 0);
                $key = $codEmp . '_' . $numDoc;

                if (!isset($deudaErpMap[$key])) {
                    throw new InvalidArgumentException("Rechazado por Seguridad: La factura {$numDoc} ({$codEmp}) no presenta deuda activa en el ERP o no pertenece al cliente seleccionado.");
                }

                $cuotaMatch = false;
                foreach ($deudaErpMap[$key] as &$cuotaErp) {
                    if (!$cuotaErp['usada'] && abs($cuotaErp['saldo_cuota'] - $saldoCuotaFrontend) < 0.01) {
                        $cuotaErp['usada'] = true;
                        $cuotaMatch = true;

                        if ($montoCubierto > ($cuotaErp['saldo_cuota'] + 0.01)) {
                            throw new InvalidArgumentException("Rechazado por Seguridad: El monto a cubrir (\$" . number_format($montoCubierto, 0, ',', '.') . ") supera el saldo adeudado real en el ERP para la factura {$numDoc} (\$" . number_format($cuotaErp['saldo_cuota'], 0, ',', '.') . ").");
                        }
                        break;
                    }
                }

                if (!$cuotaMatch) {
                    throw new InvalidArgumentException("Rechazado por Seguridad: Manipulación detectada. El saldo indicado (\$" . number_format($saldoCuotaFrontend, 0, ',', '.') . ") para la factura {$numDoc} no coincide con el registro del ERP o la cuota ya fue pagada.");
                }
            }
        }
    }
    // --- [FIN] SEGURIDAD SEC-04 ---

    // Iniciar transacción SQL
    $pdo->beginTransaction();

    // 1. Insertar cobranza
    $stmtCobranza = $pdo->prepare('INSERT INTO cobranzas (
        empresa_id, vendedor_id, vendedor_nombre, numero_factura, rut_cliente,
        razon_social_cliente, monto_total_factura, email_cliente, email_tesoreria,
        tipo_entrega, numero_seguimiento, comprobante_url, estado, justificacion_descuadre
    ) VALUES (
        :empresa_id, :vendedor_id, :vendedor_nombre, :numero_factura, :rut_cliente,
        :razon_social_cliente, :monto_total_factura, :email_cliente, :email_tesoreria,
        NULL, NULL, NULL, :estado, :justificacion_descuadre
    )');

    $stmtCobranza->execute([
        ':empresa_id' => $empresa_id,
        ':vendedor_id' => $vendedor_id,
        ':vendedor_nombre' => $vendedor_nombre,
        ':numero_factura' => $numero_factura,
        ':rut_cliente' => $rut_cliente,
        ':razon_social_cliente' => $razon_social_cliente,
        ':monto_total_factura' => $monto_total_factura ?: null,
        ':email_cliente' => $email_cliente !== '' ? $email_cliente : null,
        ':email_tesoreria' => $email_tesoreria !== '' ? $email_tesoreria : 'tesoreria@automarco.cl',
        ':estado' => $estadoInicial,
        ':justificacion_descuadre' => $justificacion_descuadre !== '' ? $justificacion_descuadre : null
    ]);

    $cobranza_id = (int) $pdo->lastInsertId();

    // 2. Insertar facturas asociadas en cobranza_facturas
    $stmtFacturaPivot = $pdo->prepare('INSERT INTO cobranza_facturas (
        cobranza_id, empresa_id, codigo_empresa, numero_factura, cuota_label, total_cuota, saldo_cuota, monto_cubierto
    ) VALUES (
        :cobranza_id, :empresa_id, :codigo_empresa, :numero_factura, :cuota_label, :total_cuota, :saldo_cuota, :monto_cubierto
    )');

    foreach ($facturasLista as $fItem) {
        $stmtFacturaPivot->execute([
            ':cobranza_id' => $cobranza_id,
            ':empresa_id' => $fItem['empresa_id'] ?? 1,
            ':codigo_empresa' => $fItem['codigo_empresa'] ?? 'EMP01',
            ':numero_factura' => $fItem['numero_factura'],
            ':cuota_label' => isset($fItem['cuota_label']) && $fItem['cuota_label'] !== '' ? $fItem['cuota_label'] : null,
            ':total_cuota' => $fItem['total_cuota'] ?? 0,
            ':saldo_cuota' => $fItem['saldo_cuota'] ?? 0,
            ':monto_cubierto' => $fItem['monto_cubierto'] ?? $fItem['saldo_cuota'] ?? 0
        ]);
    }

    // 2. Insertar cheques
    $stmtCheque = $pdo->prepare('INSERT INTO cheques (
        cobranza_id, banco, emitido_a, numero_cheque, monto, fecha_vencimiento, foto_cheque_url, comentario
    ) VALUES (
        :cobranza_id, NULL, :emitido_a, NULL, :monto, :fecha_vencimiento, :foto_cheque_url, :comentario
    )');

    foreach ($chequesParaInsertar as $chq) {
        $stmtCheque->execute([
            ':cobranza_id' => $cobranza_id,
            ':emitido_a' => $empresa_nombre_default,
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
