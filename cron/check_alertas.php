<?php
/**
 * Cron: Motor de Alertas por Días Transcurridos (Fase 4)
 * 
 * Se ejecuta vía programador de tareas (cron o task scheduler).
 * Detecta cobranzas en tránsito o pendientes que superan los días máximos configurados.
 */

date_default_timezone_set('America/Santiago');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../services/MailService.php';

try {
    $pdo = Database::getCobranzasConnection();
    
    // Sincronizar timezone MySQL
    $offset = date('P');
    $pdo->exec("SET time_zone = '$offset';");

    // 1. Cargar configuración de correos internos desde el Panel (BD)
    $stmtConfig = $pdo->prepare("SELECT clave, valor FROM configuraciones_sistema WHERE clave IN ('email_tesoreria_general', 'email_cuentas_corrientes_general')");
    $stmtConfig->execute();
    $configuraciones = $stmtConfig->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $emailTesoreria = $configuraciones['email_tesoreria_general'] ?? 'tesoreria@automarco.cl';
    $emailCC = $configuraciones['email_cuentas_corrientes_general'] ?? 'cuentascorrientes@automarco.cl';

    // 2. Consultar todas las cobranzas que están en estado transitorio
    $stmtCob = $pdo->prepare("
        SELECT 
            c.id, c.vendedor_id, c.vendedor_nombre, c.rut_cliente, c.razon_social_cliente, c.empresa_id,
            c.estado, c.created_at, e.nombre as empresa_nombre, e.dias_maximos_envio, u.email as vendedor_email, u.dias_alerta_personalizado
        FROM cobranzas c
        JOIN empresas e ON c.empresa_id = e.id
        LEFT JOIN usuarios u ON c.vendedor_id = u.id
        WHERE c.estado IN ('PENDIENTE_ENVIO', 'EN_TRANSITO', 'ENTREGADO_SANTIAGO')
    ");
    $stmtCob->execute();
    $cobranzasTransito = $stmtCob->fetchAll(PDO::FETCH_ASSOC);

    $alertasEnviadas = 0;

    foreach ($cobranzasTransito as $cob) {
        // Determinar días máximos (prioridad: personalizado del vendedor, luego de la empresa)
        $diasMaximos = $cob['dias_alerta_personalizado'] ?? $cob['dias_maximos_envio'] ?? 3;
        
        // Calcular antigüedad
        $fechaCreacion = new DateTime($cob['created_at']);
        $hoy = new DateTime();
        $interval = $fechaCreacion->diff($hoy);
        $diasTranscurridos = (int)$interval->days;

        if ($diasTranscurridos > $diasMaximos) {
            // Verificar idempotencia: No spamear. ¿Ya se envió alerta hoy por esta cobranza?
            // Usamos log_envios_informes para esto. (empresa_id = cobranza_id, destinatario = vendedor)
            // Hack para reutilizar tabla: Guardaremos 'ALERTA_DEMORA' y en asunto pondremos el ID cobranza.
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

            // Proceder a alertar
            if (empty($cob['vendedor_email'])) {
                continue; // No podemos alertar si el vendedor no tiene correo
            }

            $enviado = MailService::enviarAlertaDemora(
                $cob['vendedor_nombre'],
                $cob['vendedor_email'],
                $cob,
                $diasTranscurridos,
                $diasMaximos,
                $emailCC // CC a Cuentas Corrientes para que hagan seguimiento
            );

            if ($enviado) {
                // Registrar log
                $stmtLog = $pdo->prepare("
                    INSERT INTO log_envios_informes 
                    (empresa_id, tipo_informe, destinatario, copia_cc, asunto, estado_envio)
                    VALUES (?, 'ALERTA_DEMORA', ?, ?, ?, 'ENVIADO')
                ");
                $stmtLog->execute([
                    $cob['empresa_id'], $cob['vendedor_email'], $emailCC, $asuntoAlerta
                ]);
                $alertasEnviadas++;
                
                echo "[" . date('Y-m-d H:i:s') . "] Alerta enviada al vendedor {$cob['vendedor_email']} (Cobranza {$cob['id']} demorada $diasTranscurridos días).\n";
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] Fallo al enviar alerta para cobranza {$cob['id']}.\n";
            }
        }
    }

    if ($alertasEnviadas === 0) {
        echo "[" . date('Y-m-d H:i:s') . "] No se encontraron cobranzas atrasadas hoy o ya fueron alertadas.\n";
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] Total de alertas de atraso enviadas hoy: $alertasEnviadas.\n";
    }

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR CRÓNICO DE ALERTAS: " . $e->getMessage() . "\n";
}
