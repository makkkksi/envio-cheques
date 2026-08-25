<?php
/**
 * admin/api/cambiar_estado.php
 * 
 * Permite a Tesorería o Administradores cambiar el estado de una cobranza:
 * - RECIBIDO_TESORERIA: Confirma recepción física de los documentos.
 * - DEPOSITADO: Registra el cobro efectivo (recibe N° de papeleta y fecha opcional).
 * - RECHAZADO: Marca el cheque como protestado o devuelto (comentario obligatorio).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/AuditService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

try {
    $pdo = Database::getCobranzasConnection();
    
    // RBAC estricto en el servidor
    $user = requirePermission($pdo, 'cheques.manage');
    requireCsrfToken();
    $usuario_id = $user['id'];

    $cobranza_id = filter_input(INPUT_POST, 'cobranza_id', FILTER_VALIDATE_INT) ?: filter_var($_POST['cobranza_id'] ?? null, FILTER_VALIDATE_INT);
    $nuevo_estado = trim($_POST['nuevo_estado'] ?? '');
    $comentario = trim($_POST['comentario'] ?? '');
    $numero_papeleta = trim($_POST['numero_papeleta_deposito'] ?? '');
    $fecha_deposito = trim($_POST['fecha_deposito_real'] ?? '');

    if (!$cobranza_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'cobranza_id es requerido']);
        exit;
    }

    $estadosValidos = ['RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO'];
    if (!in_array($nuevo_estado, $estadosValidos, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Estado no permitido para Tesorería']);
        exit;
    }

    if ($nuevo_estado === 'RECHAZADO' && empty($comentario)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Debe ingresar un motivo para el rechazo del cheque']);
        exit;
    }

    if ($nuevo_estado === 'DEPOSITADO' && empty($numero_papeleta)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Debe ingresar el número de la papeleta de depósito']);
        exit;
    }

    if ($nuevo_estado === 'RECIBIDO_TESORERIA') {
        $chequesCompletadosJson = $_POST['cheques_completados'] ?? '[]';
        $chequesCompletados = json_decode($chequesCompletadosJson, true);
        if (!is_array($chequesCompletados) || empty($chequesCompletados)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Debe completar la información de banco y N° de cheque para los documentos']);
            exit;
        }
    }

    // Consultar estado actual y empresa
    $stmtCob = $pdo->prepare("
        SELECT c.id, c.estado, c.empresa_id, e.nombre as empresa_nombre 
        FROM cobranzas c 
        LEFT JOIN empresas e ON c.empresa_id = e.id 
        WHERE c.id = :id
    ");
    $stmtCob->execute([':id' => $cobranza_id]);
    $cobranza = $stmtCob->fetch(PDO::FETCH_ASSOC);

    if (!$cobranza) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cobranza no encontrada']);
        exit;
    }

    $estado_anterior = $cobranza['estado'];
    $empresaFallback = $cobranza['empresa_nombre'] ?: 'Automarco LTDA';

    $transicionesPermitidas = [
        'EN_TRANSITO' => ['RECIBIDO_TESORERIA'],
        'ENTREGADO_SANTIAGO' => ['RECIBIDO_TESORERIA'],
        'RECIBIDO_TESORERIA' => ['DEPOSITADO', 'RECHAZADO'],
    ];
    if (!isset($transicionesPermitidas[$estado_anterior])
        || !in_array($nuevo_estado, $transicionesPermitidas[$estado_anterior], true)) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'La transición de estado solicitada no está permitida.']);
        exit;
    }

    // Iniciar transacción atómica
    $pdo->beginTransaction();

    // 1. Actualizar estado de la cobranza
    $stmtUpd = $pdo->prepare("UPDATE cobranzas SET estado = :nuevo_estado, updated_at = NOW() WHERE id = :id");
    $stmtUpd->execute([
        ':nuevo_estado' => $nuevo_estado,
        ':id'           => $cobranza_id
    ]);

    // 1.5. Si el estado es RECIBIDO_TESORERIA, actualizar banco, número de cheques, cuenta corriente y emitido_a
    if ($nuevo_estado === 'RECIBIDO_TESORERIA') {
        $stmtUpdChq = $pdo->prepare("UPDATE cheques SET banco = :banco, numero_cheque = :numero_cheque, cuenta_corriente = :cuenta_corriente, monto = :monto, emitido_a = :emitido_a WHERE id = :id AND cobranza_id = :cob_id");
        foreach ($chequesCompletados as $chq) {
            if (isset($chq['id'], $chq['banco'], $chq['numero_cheque'], $chq['monto'])) {
                $emitido_a_val = !empty(trim($chq['emitido_a'] ?? '')) ? trim($chq['emitido_a']) : $empresaFallback;
                $stmtUpdChq->execute([
                    ':banco' => $chq['banco'],
                    ':numero_cheque' => $chq['numero_cheque'],
                    ':cuenta_corriente' => $chq['cuenta_corriente'] ?? null,
                    ':monto' => $chq['monto'],
                    ':emitido_a' => $emitido_a_val,
                    ':id' => $chq['id'],
                    ':cob_id' => $cobranza_id
                ]);
            }
        }
    }

    // 2. Si el estado es DEPOSITADO, actualizar datos de depósito en la tabla cheques
    if ($nuevo_estado === 'DEPOSITADO') {
        $fechaDepVal = (!empty($fecha_deposito)) ? $fecha_deposito . ' 00:00:00' : date('Y-m-d H:i:s');
        $stmtChq = $pdo->prepare("UPDATE cheques SET 
                                    numero_papeleta_deposito = :papeleta,
                                    fecha_deposito_real = :fecha
                                  WHERE cobranza_id = :id");
        $stmtChq->execute([
            ':papeleta' => $numero_papeleta,
            ':fecha'    => $fechaDepVal,
            ':id'       => $cobranza_id
        ]);
    }

    // 3. Registrar en la bitácora de historial
    $comentarioAudit = $comentario !== '' ? $comentario : null;
    if ($nuevo_estado === 'DEPOSITADO' && $numero_papeleta !== '') {
        $comentarioAudit = "Papeleta N° {$numero_papeleta}" . ($comentario ? " - {$comentario}" : "");
    }

    $stmtHist = $pdo->prepare("INSERT INTO historial_estados (
                                cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario
                              ) VALUES (
                                :cobranza_id, :usuario_id, :estado_anterior, :estado_nuevo, :comentario
                              )");
    $stmtHist->execute([
        ':cobranza_id'     => $cobranza_id,
        ':usuario_id'      => $usuario_id,
        ':estado_anterior' => $estado_anterior,
        ':estado_nuevo'    => $nuevo_estado,
        ':comentario'      => $comentarioAudit
    ]);

    // 4. Registrar Log de Auditoría obligatorio (Si falla, gatilla rollBack)
    AuditService::log($pdo, $usuario_id, $user['email'], 'CAMBIO_ESTADO', "Cobranza ID $cobranza_id transicionó de $estado_anterior a $nuevo_estado. Detalles: $comentarioAudit");

    $pdo->commit();

    // 5. Notificar automáticamente a Cuentas Corrientes y al Vendedor al validar
    try {
        if ($nuevo_estado === 'RECIBIDO_TESORERIA') {
            require_once __DIR__ . '/../../services/MailService.php';
            MailService::notificarValidacionTesorería($pdo, $cobranza_id);

            // 6. Inyectar cheques validados a Google Sheets de Tesorería (después del commit, no bloqueante)
            try {
                require_once __DIR__ . '/../../services/GoogleSheetsService.php';
                
                $stmtCobData = $pdo->prepare("
                    SELECT c.rut_cliente, c.razon_social_cliente, c.justificacion_descuadre, c.empresa_id, e.google_sheet_id 
                    FROM cobranzas c
                    LEFT JOIN empresas e ON c.empresa_id = e.id
                    WHERE c.id = :id
                ");
                $stmtCobData->execute([':id' => $cobranza_id]);
                $cobData = $stmtCobData->fetch(PDO::FETCH_ASSOC);

                $stmtChequesData = $pdo->prepare("SELECT * FROM cheques WHERE cobranza_id = :id");
                $stmtChequesData->execute([':id' => $cobranza_id]);
                $chequesCompletos = $stmtChequesData->fetchAll(PDO::FETCH_ASSOC);

                $stmtEmpresas = $pdo->query("SELECT id, nombre, google_sheet_id FROM empresas");
                $empresasDb = $stmtEmpresas->fetchAll(PDO::FETCH_ASSOC);
                $empresasMap = [];
                foreach ($empresasDb as $emp) {
                    $key = trim(str_replace('.', '', strtolower($emp['nombre'])));
                    $empresasMap[$key] = $emp;
                }

                $chequesPorSheet = [];

                foreach ($chequesCompletos as $chq) {
                    $emitidoA = !empty($chq['emitido_a']) ? $chq['emitido_a'] : '';
                    $emitidoKey = trim(str_replace('.', '', strtolower($emitidoA)));
                    
                    $sheetId = $cobData['google_sheet_id']; // fallback
                    $empresaId = (int)($cobData['empresa_id'] ?? 0);
                    
                    if (!empty($emitidoKey) && isset($empresasMap[$emitidoKey])) {
                        $empresaData = $empresasMap[$emitidoKey];
                        if (!empty($empresaData['google_sheet_id'])) {
                            $sheetId = $empresaData['google_sheet_id'];
                        }
                        $empresaId = (int)$empresaData['id'];
                    }

                    if (empty($sheetId)) {
                        error_log('[GoogleSheets] Empresa sin google_sheet_id configurado para cheque ID ' . ($chq['id'] ?? '') . '. Se omite inserción.');
                        continue;
                    }

                    // Multicomentario: unir comentario del cheque y justificación de descuadre general
                    $comentariosArr = [];
                    if (!empty($chq['comentario'])) {
                        $comentariosArr[] = trim($chq['comentario']);
                    }
                    if (!empty($cobData['justificacion_descuadre'])) {
                        $comentariosArr[] = 'Descuadre: ' . trim($cobData['justificacion_descuadre']);
                    }
                    $comentarioFinal = implode(' | ', $comentariosArr);

                    // Formato del monto: solo Autotec (ID 3) lleva '$' antes del monto
                    $montoFormateado = number_format((float)($chq['monto'] ?? 0), 0, ',', '.');
                    if ($empresaId === 3) {
                        $montoFormateado = '$' . $montoFormateado;
                    }

                    if (!isset($chequesPorSheet[$sheetId])) {
                        $chequesPorSheet[$sheetId] = [];
                    }

                    $chequesPorSheet[$sheetId][] = [
                        $chq['fecha_vencimiento'] ?? '',                             // [0] FECHA
                        $chq['numero_cheque'] ?? '',                                 // [1] NCHEQUE
                        $chq['banco'] ?? '',                                        // [2] BANCO
                        $cobData['razon_social_cliente'] ?? '',                     // [3] Nombre Girador (repite nombre cliente)
                        $montoFormateado,                                           // [4] MONTO
                        $cobData['rut_cliente'] ?? '',                              // [5] Rut Cliente
                        'WEB#' . $cobranza_id,                                      // [6] NºRecibo (WEB#id)
                        $cobData['razon_social_cliente'] ?? '',                     // [7] Nombre cliente (repite nombre cliente)
                        date('d-m-Y'),                                              // [8] Fecha de ingreso (formato dd-mm-yyyy sin hora)
                        $chq['cuenta_corriente'] ?? '',                             // [9] CTA.NUMERO
                        $comentarioFinal                                            // [10] COMENTARIOS (multicomentarios)
                    ];
                }

                foreach ($chequesPorSheet as $sheetId => $filasExcel) {
                    if (!empty($filasExcel)) {
                        GoogleSheetsService::appendRows($filasExcel, $sheetId);
                    }
                }
            } catch (Throwable $eSheets) {
                error_log('[GoogleSheets] Advertencia post-commit: ' . $eSheets->getMessage());
            }
        } elseif ($nuevo_estado === 'RECHAZADO') {
            require_once __DIR__ . '/../../services/MailService.php';
            MailService::notificarRechazoTesoreria($pdo, $cobranza_id, $comentario);
        }
    } catch (Throwable $eNotif) {
        error_log('[admin/api/cambiar_estado.php] Advertencia en servicios post-commit: ' . $eNotif->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Estado de cobranza actualizado con éxito y notificaciones enviadas',
        'nuevo_estado' => $nuevo_estado
    ]);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[admin/api/cambiar_estado.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al procesar el cambio de estado']);
}
