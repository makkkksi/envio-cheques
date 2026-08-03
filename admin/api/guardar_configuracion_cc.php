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
        $valorHora = $input['hora_despacho_diario'];
        $stmtHora = $pdo->prepare("
            INSERT INTO configuraciones_sistema (clave, valor) 
            VALUES ('hora_despacho_diario', :valor1)
            ON DUPLICATE KEY UPDATE valor = :valor2
        ");
        $stmtHora->execute([':valor1' => $valorHora, ':valor2' => $valorHora]);
    }

    if (isset($input['despacho_automatico_activado'])) {
        $valorAuto = $input['despacho_automatico_activado'] ? '1' : '0';
        $stmtAuto = $pdo->prepare("
            INSERT INTO configuraciones_sistema (clave, valor) 
            VALUES ('despacho_automatico_activado', :valor1)
            ON DUPLICATE KEY UPDATE valor = :valor2
        ");
        $stmtAuto->execute([':valor1' => $valorAuto, ':valor2' => $valorAuto]);
    }

    if (isset($input['email_digitadora_1'])) {
        $stmtDig1 = $pdo->prepare("
            INSERT INTO configuraciones_sistema (clave, valor) 
            VALUES ('email_digitadora_1', :valor1)
            ON DUPLICATE KEY UPDATE valor = :valor2
        ");
        $stmtDig1->execute([':valor1' => $input['email_digitadora_1'], ':valor2' => $input['email_digitadora_1']]);
    }

    if (isset($input['email_digitadora_2'])) {
        $stmtDig2 = $pdo->prepare("
            INSERT INTO configuraciones_sistema (clave, valor) 
            VALUES ('email_digitadora_2', :valor1)
            ON DUPLICATE KEY UPDATE valor = :valor2
        ");
        $stmtDig2->execute([':valor1' => $input['email_digitadora_2'], ':valor2' => $input['email_digitadora_2']]);
    }

    // 2. Actualizar correos asignados por empresa
    if (isset($input['asignaciones_empresas']) && is_array($input['asignaciones_empresas'])) {
        $stmtEmpresa = $pdo->prepare("
            UPDATE empresas 
            SET email_tesoreria_defecto = :email 
            WHERE id = :id
        ");
        foreach ($input['asignaciones_empresas'] as $asignacion) {
            if (isset($asignacion['id']) && isset($asignacion['email'])) {
                $email = trim($asignacion['email']);
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stmtEmpresa->bindParam(':email', $email);
                    $stmtEmpresa->bindParam(':id', $asignacion['id'], PDO::PARAM_INT);
                    $stmtEmpresa->execute();
                }
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
