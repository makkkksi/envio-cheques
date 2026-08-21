<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/MailService.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // Este endpoint puede ser llamado por cron (sin sesión) o manualmente (con sesión Admin)
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => true,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }
    $isManual = isset($_SESSION['admin_user_id']);
    if ($isManual) {
        requireRole(['ADMINISTRADOR', 'SUPERVISORA_CC']);
    }

    $pdo = Database::getCobranzasConnection();

    // Obtener la hora configurada si se llama por cron
    $stmtConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'hora_despacho_diario'");
    $stmtConfig->execute();
    $hora_despacho_diario = $stmtConfig->fetchColumn() ?: '16:00';

    if (!$isManual) {
        $stmtAutoConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'despacho_automatico_activado'");
        $stmtAutoConfig->execute();
        $auto_dispatch_val = $stmtAutoConfig->fetchColumn();
        if ($auto_dispatch_val !== false && $auto_dispatch_val === '0') {
            echo json_encode(['success' => false, 'message' => 'El despacho automático por hora está desactivado en la configuración.']);
            exit;
        }
        $current_time = date('H:i');
        // Tolerancia de 5 minutos
        if ($current_time < $hora_despacho_diario) {
            echo json_encode(['success' => false, 'message' => "Aún no es la hora de despacho ($hora_despacho_diario). Actual: $current_time"]);
            exit;
        }
    }

    // Correo supervisor
    $stmtSup = $pdo->prepare("SELECT email FROM usuarios WHERE rol = 'SUPERVISORA_CC' LIMIT 1");
    $stmtSup->execute();
    $ccEmail = $stmtSup->fetchColumn() ?: 'cuentascorrientes@automarco.cl';

    // Obtener emails de digitadoras para personalizar el asunto
    $stmtDig1 = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'email_digitadora_1'");
    $stmtDig1->execute();
    $emailDig1 = $stmtDig1->fetchColumn();

    $stmtDig2 = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'email_digitadora_2'");
    $stmtDig2->execute();
    $emailDig2 = $stmtDig2->fetchColumn();

    // Obtener todas las cobranzas aprobadas
    $stmtCobranzas = $pdo->prepare("
        SELECT 
            c.id, c.empresa_id, e.nombre as empresa_nombre, e.email_tesoreria_defecto,
            c.vendedor_nombre, c.rut_cliente, c.razon_social_cliente, c.numero_factura
        FROM cobranzas c
        JOIN empresas e ON c.empresa_id = e.id
        WHERE c.estado = 'RECIBIDO_TESORERIA'
    ");
    $stmtCobranzas->execute();
    $cobranzasHoy = $stmtCobranzas->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cobranzasHoy)) {
        echo json_encode(['success' => true, 'message' => 'No hay cobranzas validadas hoy. No se envía correo (Anti-Spam).']);
        exit;
    }

    // Obtener empresas para el mapeo
    $stmtEmp = $pdo->prepare("SELECT id, nombre, email_tesoreria_defecto FROM empresas");
    $stmtEmp->execute();
    $todasEmpresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
    $empresasPorId = [];
    foreach ($todasEmpresas as $e) {
        $empresasPorId[$e['id']] = $e;
    }

    // Helper para resolver el ID de empresa exacto por cualquier variacion de texto (Automarco, HD, Autotec, Gabtec)
    $resolverEmpresaIdPorTexto = function(string $emitido, array $empresas): int {
        $clean = strtoupper(trim($emitido));
        if ($clean === '') return 0;

        // 1. Coincidencia exacta
        foreach ($empresas as $emp) {
            $empNombre = strtoupper(trim($emp['nombre']));
            if ($clean === $empNombre) {
                return (int)$emp['id'];
            }
        }

        // 2. HD Automarco (debe chequearse antes de Automarco genérico)
        if (strpos($clean, 'HD') !== false || strpos($clean, 'AUTOMARCOHD') !== false || strpos($clean, 'EMP06') !== false) {
            foreach ($empresas as $emp) {
                if (stripos($emp['nombre'], 'HD') !== false || (int)$emp['id'] === 2) {
                    return (int)$emp['id'];
                }
            }
            return 2;
        }

        // 3. Gabtec
        if (strpos($clean, 'GABTEC') !== false || strpos($clean, 'EMP10') !== false) {
            foreach ($empresas as $emp) {
                if (stripos($emp['nombre'], 'GABTEC') !== false || (int)$emp['id'] === 4) {
                    return (int)$emp['id'];
                }
            }
            return 4;
        }

        // 4. Autotec
        if (strpos($clean, 'AUTOTEC') !== false || strpos($clean, 'EMP03') !== false || strpos($clean, 'EMP24') !== false) {
            foreach ($empresas as $emp) {
                if (stripos($emp['nombre'], 'AUTOTEC') !== false || (int)$emp['id'] === 3) {
                    return (int)$emp['id'];
                }
            }
            return 3;
        }

        // 5. Automarco LTDA
        if (strpos($clean, 'AUTOMARCO') !== false || strpos($clean, 'AUTOMARC') !== false || strpos($clean, 'EMP01') !== false) {
            foreach ($empresas as $emp) {
                if (stripos($emp['nombre'], 'HD') === false && (stripos($emp['nombre'], 'AUTOMARCO') !== false || (int)$emp['id'] === 1)) {
                    return (int)$emp['id'];
                }
            }
            return 1;
        }

        return 0;
    };

    // Agrupar por empresa (basado en cheques)
    $agrupado = [];
    foreach ($cobranzasHoy as $c) {
        $stmtCheques = $pdo->prepare("SELECT * FROM cheques WHERE cobranza_id = ?");
        $stmtCheques->execute([$c['id']]);
        $cheques = $stmtCheques->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cheques)) {
            // Sin cheques, usar la empresa original
            $empId = $c['empresa_id'];
            if (!isset($agrupado[$empId])) {
                $agrupado[$empId] = [
                    'nombre' => $c['empresa_nombre'],
                    'email' => $c['email_tesoreria_defecto'],
                    'cobranzas' => []
                ];
            }
            $c_copy = $c;
            $c_copy['cheques_filtrados'] = [];
            $agrupado[$empId]['cobranzas'][] = $c_copy;
        } else {
            // Agrupar cheques por emitido_a con resolucion inteligente de empresas
            $chequesAgrupados = [];
            foreach ($cheques as $chq) {
                $emitido = !empty($chq['emitido_a']) ? trim($chq['emitido_a']) : $c['empresa_nombre'];
                
                $targetEmpId = $c['empresa_id'];
                $resolvedId = $resolverEmpresaIdPorTexto($emitido, $todasEmpresas);
                if ($resolvedId > 0 && isset($empresasPorId[$resolvedId])) {
                    $targetEmpId = $resolvedId;
                }

                if (!isset($chequesAgrupados[$targetEmpId])) {
                    $chequesAgrupados[$targetEmpId] = [];
                }
                $chequesAgrupados[$targetEmpId][] = $chq;
            }

            // Insertar cobranza fragmentada
            foreach ($chequesAgrupados as $targetEmpId => $listaCheques) {
                if (!isset($agrupado[$targetEmpId])) {
                    $agrupado[$targetEmpId] = [
                        'nombre' => $empresasPorId[$targetEmpId]['nombre'],
                        'email' => $empresasPorId[$targetEmpId]['email_tesoreria_defecto'],
                        'cobranzas' => []
                    ];
                }
                $c_copy = $c;
                $c_copy['cheques_filtrados'] = $listaCheques;
                $agrupado[$targetEmpId]['cobranzas'][] = $c_copy;
            }
        }
    }

    // Validar que todas las empresas tengan un email destino configurado antes de enviar nada
    foreach ($agrupado as $empId => $data) {
        if (empty($data['email'])) {
            echo json_encode([
                'success' => false,
                'message' => "La empresa '{$data['nombre']}' no tiene un correo asignado. Ve a Configuración de Cuentas Corrientes y asigna el correo de la digitadora."
            ]);
            exit;
        }
    }

    $resultados = [];

    // Enviar un correo por cada empresa
    foreach ($agrupado as $empId => $data) {
        $destinatario = $data['email'];
        
        $totalCobranzas = count($data['cobranzas']);
        
        $nombreDigitadora = "DIGITADORA";
        if ($destinatario === $emailDig1) {
            $nombreDigitadora = "Digitadora A";
        } elseif ($destinatario === $emailDig2) {
            $nombreDigitadora = "Digitadora B";
        }

        $asunto = "[PARA {$nombreDigitadora}] Resumen Diario Cuentas Corrientes - " . $data['nombre'] . " (" . date('d/m/Y') . ")";

        // Enviar usando el layout unificado y ultra-ordenado
        $enviado = MailService::enviarResumenDiarioDigitadora($data['nombre'], $data['cobranzas'], $destinatario, $pdo);
        $estado = $enviado ? 'ENVIADO' : 'FALLIDO';
        $errorMensaje = $enviado ? null : 'Falla en el envío SMTP';

        // Registrar en bitácora con snapshot (payload_json) de lo enviado
        $payload_json = json_encode($data['cobranzas']);

        $stmtLog = $pdo->prepare("
            INSERT INTO log_envios_informes 
            (empresa_id, tipo_informe, destinatario, copia_cc, asunto, estado_envio, error_mensaje, cantidad_cobranzas, payload_json)
            VALUES (?, 'RESUMEN_DIARIO_16HRS', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtLog->execute([
            $empId, $destinatario, $ccEmail, $asunto, $estado, $errorMensaje, $totalCobranzas, $payload_json
        ]);

        // Si el envío fue exitoso, actualizar estado final a DEPOSITADO e insertar historial de auditoría
        if ($enviado) {
            $stmtUpd = $pdo->prepare("UPDATE cobranzas SET estado = 'DEPOSITADO', updated_at = NOW() WHERE id = ?");
            $stmtHist = $pdo->prepare("INSERT INTO historial_estados (cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario) VALUES (?, ?, 'RECIBIDO_TESORERIA', 'DEPOSITADO', ?)");
            $userAuditId = $_SESSION['admin_user_id'] ?? 1;

            foreach ($data['cobranzas'] as $cobranza) {
                $stmtUpd->execute([$cobranza['id']]);
                $stmtHist->execute([$cobranza['id'], $userAuditId, 'Liberado por Cuentas Corrientes y despachado a digitadora para ingreso en Optimus ERP']);
            }
        }

        $resultados[] = [
            'empresa' => $data['nombre'],
            'estado' => $estado
        ];
    }

    echo json_encode([
        'success' => true,
        'message' => 'Proceso de despacho finalizado.',
        'data' => $resultados
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
