<?php
/**
 * Cron: Resumen Diario de Cuentas Corrientes (Hora de Corte Configurable)
 * 
 * Se ejecuta vía programador de tareas (cron o task scheduler).
 * Sincroniza timezone dinámicamente, lee hora de corte de la BD y previene envíos duplicados.
 */

// Sincronizar zona horaria de Chile
date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/MailService.php';

// Control de acceso seguro: solo permitir ejecución vía CLI o con token secreto vía Web
if (php_sapi_name() !== 'cli') {
    $providedToken = $_GET['cron_token'] ?? $_POST['cron_token'] ?? '';
    $expectedToken = defined('CRON_SECRET_KEY') ? CRON_SECRET_KEY : 'cobranzas_cron_secret_2026';
    if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso no autorizado al Cron Job.']);
        exit;
    }
}

function logDespacho(string $mensaje) {
    $formatted = "[" . date('Y-m-d H:i:s') . "] " . $mensaje . "\n";
    echo $formatted;
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logDir . '/cron_despacho_cc.log', $formatted, FILE_APPEND);
}

try {
    $pdo = Database::getCobranzasConnection();
    
    // Sincronizar timezone en la sesión de MySQL de forma dinámica con PHP
    $offset = date('P'); // Retorna ej: -04:00 o -03:00
    $pdo->exec("SET time_zone = '$offset';");

    // 1. Validar si el despacho automático está activo desde la configuración en BD (Panel de Control)
    $stmtAuto = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'despacho_automatico_activado'");
    $stmtAuto->execute();
    $auto_activado = $stmtAuto->fetchColumn();
    
    if ($auto_activado !== '1') {
        logDespacho("El despacho automático por hora está DESACTIVADO desde el panel de control.");
        exit;
    }

    // 2. Obtener la hora de corte configurada
    $stmtConfig = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'hora_despacho_diario'");
    $stmtConfig->execute();
    $hora_despacho_diario = $stmtConfig->fetchColumn() ?: '16:00';

    $hora_actual = date('H:i');

    // Si la hora actual es menor que la hora de corte configurada, no ejecutar
    if ($hora_actual < $hora_despacho_diario) {
        logDespacho("Omitiendo ejecución: Hora actual ($hora_actual) es menor que la hora de corte configurada ($hora_despacho_diario).");
        exit;
    }

    // Correo de la supervisora de Cuentas Corrientes (CC)
    $stmtSup = $pdo->prepare("SELECT email FROM usuarios WHERE rol = 'SUPERVISORA_CC' LIMIT 1");
    $stmtSup->execute();
    $ccEmail = $stmtSup->fetchColumn() ?: 'cuentascorrientes@automarco.cl';

    // 3. Obtener todas las cobranzas en RECIBIDO_TESORERIA
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
        logDespacho("Sin movimientos validados hoy (Regla Anti-Spam). Omitiendo.");
        exit;
    }

    // Obtener mapa de empresas por ID y por Nombre
    $stmtEmp = $pdo->prepare("SELECT id, nombre, email_tesoreria_defecto FROM empresas");
    $stmtEmp->execute();
    $todasEmpresas = $stmtEmp->fetchAll(PDO::FETCH_ASSOC);
    $empresasPorId = [];
    foreach ($todasEmpresas as $e) {
        $empresasPorId[$e['id']] = $e;
    }

    // Helper para resolver el ID de empresa exacto por cualquier variacion de texto
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

        // 2. HD Automarco
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

    // Agrupar cobranzas y cheques según la empresa de emisión real del cheque (emitido_a)
    $agrupado = [];
    foreach ($cobranzasHoy as $c) {
        $stmtCheques = $pdo->prepare("SELECT * FROM cheques WHERE cobranza_id = ?");
        $stmtCheques->execute([$c['id']]);
        $cheques = $stmtCheques->fetchAll(PDO::FETCH_ASSOC);

        if (empty($cheques)) {
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

    foreach ($agrupado as $empId => $data) {
        $destinatario = $data['email'];

        if (empty($destinatario)) {
            logDespacho("Empresa ID {$empId} ({$data['nombre']}) no tiene correo de digitadora asignado. Omitiendo.");
            continue;
        }

        // 4. CONTROL DE IDEMPOTENCIA (Locking): ¿Ya se envió el resumen hoy para esta empresa?
        $stmtCheck = $pdo->prepare("
            SELECT COUNT(*) 
            FROM log_envios_informes 
            WHERE empresa_id = ? 
            AND tipo_informe = 'RESUMEN_DIARIO_16HRS' 
            AND estado_envio = 'ENVIADO' 
            AND DATE(fecha_envio) = CURDATE()
        ");
        $stmtCheck->execute([$empId]);
        if ($stmtCheck->fetchColumn() > 0) {
            logDespacho("Resumen de hoy ya fue enviado con éxito para {$data['nombre']}. Omitiendo duplicados.");
            continue;
        }

        $totalCobranzas = count($data['cobranzas']);
        $asunto = "[PARA DIGITADORAS] Resumen Diario Cuentas Corrientes - " . $data['nombre'] . " (" . date('d/m/Y') . ")";

        // Enviar usando el layout unificado y ultra-ordenado con copia a Supervisora de CC
        $enviado = MailService::enviarResumenDiarioDigitadora($data['nombre'], $data['cobranzas'], $destinatario, $pdo, $ccEmail);
        $estado = $enviado ? 'ENVIADO' : 'FALLIDO';
        $errorMensaje = $enviado ? null : 'Falla en el envío SMTP';

        // Registrar en bitácora
        $stmtLog = $pdo->prepare("
            INSERT INTO log_envios_informes 
            (empresa_id, tipo_informe, destinatario, copia_cc, asunto, estado_envio, error_mensaje, cantidad_cobranzas)
            VALUES (?, 'RESUMEN_DIARIO_16HRS', ?, ?, ?, ?, ?, ?)
        ");
        $stmtLog->execute([
            $empId, $destinatario, $ccEmail, $asunto, $estado, $errorMensaje, $totalCobranzas
        ]);

        // Si el envío fue exitoso, actualizar estado final a DEPOSITADO e insertar historial de auditoría
        if ($enviado) {
            $stmtUpd = $pdo->prepare("UPDATE cobranzas SET estado = 'DEPOSITADO', updated_at = NOW() WHERE id = ?");
            $stmtHist = $pdo->prepare("INSERT INTO historial_estados (cobranza_id, usuario_id, estado_anterior, estado_nuevo, comentario) VALUES (?, 1, 'RECIBIDO_TESORERIA', 'DEPOSITADO', ?)");

            foreach ($data['cobranzas'] as $cobranzaItem) {
                $stmtUpd->execute([$cobranzaItem['id']]);
                $stmtHist->execute([$cobranzaItem['id'], 'Liberado por Cron Cuentas Corrientes y despachado a digitadora para ingreso en Optimus ERP']);
            }
        }

        logDespacho("Resumen enviado para {$data['nombre']} a {$destinatario} (CC: {$ccEmail}). Estado: {$estado}");
    }

} catch (Exception $e) {
    logDespacho("ERROR CRÓNICO: " . $e->getMessage());
}

