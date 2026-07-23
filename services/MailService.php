<?php
/**
 * MailService.php — Servicio de Envío de Notificaciones por Correo
 * 
 * Centraliza el envío de correos a Tesorería y Clientes.
 */

require_once __DIR__ . '/../config/app.php';

class MailService
{
    /**
     * Envía notificaciones de cobranza por correo electrónico.
     * 
     * @param array $cobranza Datos de la cabecera de la cobranza.
     * @param array $cheques Lista de cheques asociados.
     * @param array $rutasAdjuntos Lista de rutas absolutas de archivos adjuntos (opcional).
     * @return bool True si el envío fue exitoso, false en caso contrario.
     */
    public static function enviarNotificacion(array $cobranza, array $cheques, array $rutasAdjuntos = []): bool
    {
        // Si no hay SMTP configurado, no intentar enviar (evita timeout que bloquea la respuesta)
        if (!defined('MAIL_HOST') || empty(MAIL_HOST)) {
            error_log('[MailService] MAIL_HOST no configurado. Omitiendo envío de correo.');
            return false;
        }

        try {
            $emailTesorería = $cobranza['email_tesoreria'] ?? '';
            $emailCliente = $cobranza['email_cliente'] ?? '';

            if (empty($emailTesorería) && empty($emailCliente)) {
                return false;
            }

            // Construcción del cuerpo del mensaje en HTML
            $asunto = "Nueva Cobranza Registrada - Factura N° " . ($cobranza['numero_factura'] ?? '');
            
            $html = "<h2>Resumen de Cobranza Registrada</h2>";
            $html .= "<p><strong>Empresa:</strong> " . htmlspecialchars($cobranza['empresa_nombre'] ?? '') . "</p>";
            $html .= "<p><strong>N° Factura:</strong> " . htmlspecialchars($cobranza['numero_factura'] ?? '') . "</p>";
            $html .= "<p><strong>RUT Cliente:</strong> " . htmlspecialchars($cobranza['rut_cliente'] ?? '') . "</p>";
            $html .= "<p><strong>Razón Social:</strong> " . htmlspecialchars($cobranza['razon_social_cliente'] ?? '') . "</p>";
            $html .= "<p><strong>Tipo de Entrega:</strong> " . htmlspecialchars($cobranza['tipo_entrega'] ?? '') . "</p>";
            
            if (!empty($cobranza['numero_seguimiento'])) {
                $html .= "<p><strong>N° Seguimiento (OT):</strong> " . htmlspecialchars($cobranza['numero_seguimiento']) . "</p>";
            }

            $html .= "<h3>Cheques Registrados</h3>";
            $html .= "<table border='1' cellpadding='5' cellspacing='0'>";
            $html .= "<tr><th>Banco</th><th>N° Cheque</th><th>Monto</th><th>Vencimiento</th><th>Comentario</th></tr>";

            foreach ($cheques as $cheque) {
                $html .= "<tr>";
                $html .= "<td>" . htmlspecialchars($cheque['banco'] ?? '') . "</td>";
                $html .= "<td>" . htmlspecialchars($cheque['numero_cheque'] ?? '') . "</td>";
                $html .= "<td>$" . number_format((float)($cheque['monto'] ?? 0), 0, ',', '.') . "</td>";
                $html .= "<td>" . htmlspecialchars($cheque['fecha_vencimiento'] ?? '') . "</td>";
                $html .= "<td>" . htmlspecialchars($cheque['comentario'] ?? '-') . "</td>";
                $html .= "</tr>";
            }

            $html .= "</table>";

            // Headers para envío de correo HTML vía mail() nativo de PHP (local / host)
            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-type: text/html; charset=utf-8';
            $headers[] = 'From: ' . (defined('MAIL_FROM') ? MAIL_FROM : 'cobranzas@dominio.cl');

            $exitoTesorería = true;
            if (!empty($emailTesorería)) {
                $exitoTesorería = @mail($emailTesorería, $asunto, $html, implode("\r\n", $headers));
            }

            $exitoCliente = true;
            if (!empty($emailCliente)) {
                $exitoCliente = @mail($emailCliente, $asunto, $html, implode("\r\n", $headers));
            }

            return $exitoTesorería && $exitoCliente;
        } catch (Exception $e) {
            error_log('[MailService] Error al enviar notificación: ' . $e->getMessage());
            return false;
        }
    }
}
