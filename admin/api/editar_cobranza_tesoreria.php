<?php
/**
 * admin/api/editar_cobranza_tesoreria.php
 *
 * Permite a Tesorería o Administrador corregir datos de cheques
 * de una cobranza que NO esté en estado DEPOSITADO o RECHAZADO.
 * Registra la corrección en historial_estados como auditoría.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');

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
    $user = requirePermission($pdo, 'cheques.manage');
    requireCsrfToken();
    $usuario_id = $user['id'];

    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Datos inválidos']);
        exit;
    }

    $cobranza_id = (int)($input['cobranza_id'] ?? 0);
    $cheques = $input['cheques'] ?? [];

    if (!$cobranza_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'cobranza_id requerido']);
        exit;
    }

    if (empty($cheques) || !is_array($cheques)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No se enviaron datos de cheques para actualizar']);
        exit;
    }

    // Verificar que la cobranza existe, obtener updated_at y bloquear la fila
    $pdo->beginTransaction();

    $stmtCob = $pdo->prepare("SELECT id, estado, updated_at FROM cobranzas WHERE id = :id FOR UPDATE");
    $stmtCob->execute([':id' => $cobranza_id]);
    $cobranza = $stmtCob->fetch(PDO::FETCH_ASSOC);

    if (!$cobranza) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Cobranza no encontrada']);
        exit;
    }

    if (in_array($cobranza['estado'], ['DEPOSITADO', 'RECHAZADO'], true)) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'No se puede editar una cobranza en estado ' . $cobranza['estado']]);
        exit;
    }

    $updated_at_cliente = trim($input['updated_at'] ?? '');
    if ($updated_at_cliente !== '' && $cobranza['updated_at'] !== $updated_at_cliente) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Alguien más modificó esta cobranza recientemente. Por favor, actualice la página e intente de nuevo.']);
        exit;
    }

    $stmtUpd = $pdo->prepare("
        UPDATE cheques SET
            banco = :banco,
            numero_cheque = :numero_cheque,
            monto = :monto,
            emitido_a = :emitido_a,
            fecha_vencimiento = :fecha_vencimiento,
            comentario = :comentario
        WHERE id = :id AND cobranza_id = :cobranza_id
    ");

    $cambiosAplicados = 0;
    foreach ($cheques as $chq) {
        $cheque_id = (int)($chq['id'] ?? 0);
        if (!$cheque_id) continue;

        $banco = trim($chq['banco'] ?? '');
        $numero_cheque = trim($chq['numero_cheque'] ?? '');
        $monto = (float)($chq['monto'] ?? 0);
        $emitido_a = trim($chq['emitido_a'] ?? '');
        $fecha_vencimiento = trim($chq['fecha_vencimiento'] ?? '');
        $comentario = trim($chq['comentario'] ?? '') ?: null;

        if (empty($banco) || empty($numero_cheque) || $monto <= 0 || empty($fecha_vencimiento)) {
            $pdo->rollBack();
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "Datos incompletos en cheque ID $cheque_id"]);
            exit;
        }

        $stmtUpd->execute([
            ':banco'            => $banco,
            ':numero_cheque'    => $numero_cheque,
            ':monto'            => $monto,
            ':emitido_a'        => $emitido_a,
            ':fecha_vencimiento'=> $fecha_vencimiento,
            ':comentario'       => $comentario,
            ':id'               => $cheque_id,
            ':cobranza_id'      => $cobranza_id
        ]);
        $cambiosAplicados++;
    }

    // Registrar en historial como corrección manual
    $stmtHist = $pdo->prepare("
        INSERT INTO historial_estados (cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario)
        VALUES (:cobranza_id, :usuario_id, :estado_anterior, :estado_nuevo, :comentario)
    ");
    $stmtHist->execute([
        ':cobranza_id'     => $cobranza_id,
        ':usuario_id'      => $usuario_id,
        ':estado_anterior' => $cobranza['estado'],
        ':estado_nuevo'    => $cobranza['estado'],
        ':comentario'      => "Corrección manual de datos de cheques por Tesorería ({$user['email']}). $cambiosAplicados cheque(s) actualizados."
    ]);

    // Auditoría
    AuditService::log($pdo, $usuario_id, $user['email'], 'EDICION_CHEQUES_TESORERIA', "Cobranza ID $cobranza_id: $cambiosAplicados cheque(s) corregidos manualmente.");

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => "$cambiosAplicados cheque(s) actualizados correctamente.",
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[editar_cobranza_tesoreria.php] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar los cambios']);
}
