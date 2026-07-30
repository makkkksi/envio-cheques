<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    session_start();
    requireRole(['ADMINISTRADOR', 'SUPERVISORA_CC']);
    $pdo = Database::getCobranzasConnection();

    // Recibir datos JSON
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        throw new Exception("No se enviaron datos válidos.");
    }

    $pdo->beginTransaction();

    // 1. Guardar hora_despacho_diario
    if (isset($input['hora_despacho_diario']) && preg_match('/^(0[0-9]|1[0-9]|2[0-3]):[0-5][0-9]$/', $input['hora_despacho_diario'])) {
        $stmtHora = $pdo->prepare("
            INSERT INTO configuraciones_sistema (clave, valor) 
            VALUES ('hora_despacho_diario', :valor)
            ON DUPLICATE KEY UPDATE valor = :valor
        ");
        $stmtHora->bindParam(':valor', $input['hora_despacho_diario']);
        $stmtHora->execute();
    }

    if (isset($input['despacho_automatico_activado'])) {
        $stmtAuto = $pdo->prepare("
            INSERT INTO configuraciones_sistema (clave, valor) 
            VALUES ('despacho_automatico_activado', :valor)
            ON DUPLICATE KEY UPDATE valor = :valor
        ");
        $stmtAuto->bindParam(':valor', $input['despacho_automatico_activado']);
        $stmtAuto->execute();
    }

    // 2. Actualizar correos asignados por empresa
    if (isset($input['asignaciones_empresas']) && is_array($input['asignaciones_empresas'])) {
        $stmtEmpresa = $pdo->prepare("
            UPDATE empresas 
            SET email_tesoreria_defecto = :email 
            WHERE id = :id
        ");
        foreach ($input['asignaciones_empresas'] as $asignacion) {
            if (isset($asignacion['id']) && isset($asignacion['email']) && filter_var($asignacion['email'], FILTER_VALIDATE_EMAIL)) {
                $stmtEmpresa->bindParam(':email', $asignacion['email']);
                $stmtEmpresa->bindParam(':id', $asignacion['id'], PDO::PARAM_INT);
                $stmtEmpresa->execute();
            }
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Configuración actualizada correctamente.'
    ]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
