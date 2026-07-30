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
    $user = requireAuth($pdo, ['ADMINISTRADOR', 'TESORERIA']);
    $usuario_id = $user['id'];

    $cobranza_id = filter_input(INPUT_POST, 'cobranza_id', FILTER_VALIDATE_INT);
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

    // Consultar estado actual
    $stmtCob = $pdo->prepare("SELECT id, estado FROM cobranzas WHERE id = :id");
    $stmtCob->execute([':id' => $cobranza_id]);
    $cobranza = $stmtCob->fetch(PDO::FETCH_ASSOC);

    if (!$cobranza) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cobranza no encontrada']);
        exit;
    }

    $estado_anterior = $cobranza['estado'];

    // Iniciar transacción atómica
    $pdo->beginTransaction();

    // 1. Actualizar estado de la cobranza
    $stmtUpd = $pdo->prepare("UPDATE cobranzas SET estado = :nuevo_estado, updated_at = NOW() WHERE id = :id");
    $stmtUpd->execute([
        ':nuevo_estado' => $nuevo_estado,
        ':id'           => $cobranza_id
    ]);

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
    if ($nuevo_estado === 'RECIBIDO_TESORERIA') {
        require_once __DIR__ . '/../../services/MailService.php';
        MailService::notificarValidacionTesorería($pdo, $cobranza_id);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Estado de cobranza actualizado con éxito y notificaciones enviadas',
        'nuevo_estado' => $nuevo_estado
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[admin/api/cambiar_estado.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al procesar el cambio de estado']);
}
