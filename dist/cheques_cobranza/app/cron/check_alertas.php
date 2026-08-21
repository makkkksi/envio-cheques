<?php
/**
 * Cron: Motor de Alertas por Días Transcurridos (Fase 4)
 * 
 * Se ejecuta vía programador de tareas (cron o task scheduler).
 * Detecta cobranzas en tránsito o pendientes que superan los días máximos configurados
 * y envía alertas corporativas a Tesorería, Cuentas Corrientes y al Vendedor.
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
        echo json_encode(['success' => false, 'message' => 'Acceso no autorizado al Cron Job de Alertas.']);
        exit;
    }
}

function logAlerta(string $mensaje) {
    $formatted = "[" . date('Y-m-d H:i:s') . "] " . $mensaje . "\n";
    echo $formatted;
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents($logDir . '/cron_alertas.log', $formatted, FILE_APPEND);
}

try {
    $pdo = Database::getCobranzasConnection();
    
    // Sincronizar timezone en la sesión de MySQL de forma dinámica con PHP
    $offset = date('P');
    $pdo->exec("SET time_zone = '$offset';");

    // 1. Validar si el motor de alertas está activo en la configuración (Deshabilitado temporalmente)
    $stmtAlertasCfg = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = 'alertas_vendedor_activadas'");
    $stmtAlertasCfg->execute();
    $alertasActivadas = $stmtAlertasCfg->fetchColumn();

    // Por defecto / mientras tanto, permanecer deshabilitado
    if ($alertasActivadas !== '1') {
        logAlerta("Motor de alertas al vendedor DESHABILITADO temporalmente. Omitiendo escaneo de cobranzas.");
        exit;
    }

    // 2. Cargar configuración de correos internos desde el Panel (BD)
    $stmtConfig = $pdo->prepare("SELECT clave, valor FROM configuraciones_sistema WHERE clave IN ('email_tesoreria_general', 'email_cuentas_corrientes_general')");
    $stmtConfig->execute();
    $configuraciones = $stmtConfig->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $emailTesoreria = $configuraciones['email_tesoreria_general'] ?? 'tesoreria@automarco.cl';
    $emailCC = $configuraciones['email_cuentas_corrientes_general'] ?? 'cuentascorrientes@automarco.cl';

    // 3. Consultar todas las cobranzas que están en estado transitorio
    $stmtCob = $pdo->prepare("
        SELECT 
            c.id, c.vendedor_id, c.vendedor_nombre, c.rut_cliente, c.razon_social_cliente, c.monto_total_factura,
            c.tipo_entrega, c.numero_seguimiento, c.empresa_id,
            c.estado, c.created_at, e.nombre as empresa_nombre, e.email_tesoreria_defecto,
            e.dias_maximos_envio, u.email as vendedor_email, u.dias_alerta_personalizado
        FROM cobranzas c
        JOIN empresas e ON c.empresa_id = e.id
        LEFT JOIN usuarios u ON c.vendedor_id = u.id
        WHERE c.estado IN ('PENDIENTE_ENVIO', 'EN_TRANSITO', 'ENTREGADO_SANTIAGO')
    ");
    $stmtCob->execute();
    $cobranzasTransito = $stmtCob->fetchAll(PDO::FETCH_ASSOC);

    $alertasEnviadas = 0;
    $totalEvaluadas = count($cobranzasTransito);

    foreach ($cobranzasTransito as $cob) {
        // Determinar días máximos (prioridad: personalizado del vendedor, luego de la empresa, fallback 3 días)
        $diasMaximos = (int)($cob['dias_alerta_personalizado'] ?? $cob['dias_maximos_envio'] ?? 3);
        
        // Calcular antigüedad
        $fechaCreacion = new DateTime($cob['created_at']);
        $hoy = new DateTime();
        $interval = $fechaCreacion->diff($hoy);
        $diasTranscurridos = (int)$interval->days;

        if ($diasTranscurridos > $diasMaximos) {
            // Verificar idempotencia: No spamear. ¿Ya se envió alerta hoy por esta cobranza?
            $asuntoAlerta = "Cobranza ID {$cob['id']} - Atraso Detectado";
            $stmtCheck = $pdo->prepare("
                SELECT COUNT(*) FROM log_envios_informes 
                WHERE tipo_informe = 'ALERTA_DEMORA' 
                AND asunto = ? 
                AND DATE(fecha_envio) = CURDATE()
            ");
            $stmtCheck->execute([$asuntoAlerta]);
            
            if ($stmtCheck->fetchColumn() > 0) {
                continue; // Ya se alertó hoy sobre esta cobranza
            }

            // Destinatarios: Vendedor (si tiene correo) o Tesorería de la empresa con copia a Cuentas Corrientes
            $emailDestinoVendedor = $cob['vendedor_email'] ?: ($cob['email_tesoreria_defecto'] ?: $emailTesoreria);
            $nombreDestinatario = $cob['vendedor_nombre'] ?: 'Vendedor Responsable';

            $enviado = MailService::enviarAlertaDemora(
                $nombreDestinatario,
                $emailDestinoVendedor,
                $cob,
                $diasTranscurridos,
                $diasMaximos,
                $emailCC
            );

            if ($enviado) {
                // Registrar en log_envios_informes
                $stmtLog = $pdo->prepare("
                    INSERT INTO log_envios_informes 
                    (empresa_id, tipo_informe, destinatario, copia_cc, asunto, estado_envio)
                    VALUES (?, 'ALERTA_DEMORA', ?, ?, ?, 'ENVIADO')
                ");
                $stmtLog->execute([
                    $cob['empresa_id'], $emailDestinoVendedor, $emailCC, $asuntoAlerta
                ]);
                $alertasEnviadas++;
                
                logAlerta("Alerta enviada para Cobranza #{$cob['id']} a {$emailDestinoVendedor} (Demorada {$diasTranscurridos} días, límite {$diasMaximos} días).");
            } else {
                logAlerta("Fallo al enviar alerta SMTP para Cobranza #{$cob['id']}.");
            }
        }
    }

    if ($alertasEnviadas === 0) {
        logAlerta("Evaluadas {$totalEvaluadas} cobranzas. No se encontraron atrasos pendientes de alertar hoy.");
    } else {
        logAlerta("Procesadas {$totalEvaluadas} cobranzas. Total de alertas de atraso enviadas hoy: {$alertasEnviadas}.");
    }

} catch (Exception $e) {
    logAlerta("ERROR CRÓNICO DE ALERTAS: " . $e->getMessage());
}

