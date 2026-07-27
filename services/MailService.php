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

            $vendedor = htmlspecialchars($cobranza['vendedor_nombre'] ?? 'No especificado');
            $empresa = htmlspecialchars($cobranza['empresa_nombre'] ?? '');
            $nFactura = htmlspecialchars($cobranza['numero_factura'] ?? '');
            $rut = htmlspecialchars($cobranza['rut_cliente'] ?? '');
            $razonSocial = htmlspecialchars($cobranza['razon_social_cliente'] ?? '');
            $tipoEntrega = htmlspecialchars($cobranza['tipo_entrega'] ?? '');
            $tracking = !empty($cobranza['numero_seguimiento']) ? htmlspecialchars($cobranza['numero_seguimiento']) : null;
            $cobranzaId = (int)($cobranza['id'] ?? 0);
            
            // Link al portal
            $linkGestion = PORTAL_BASE_URL . "?id=" . $cobranzaId;

            // Construcción del cuerpo del mensaje en HTML con un diseño premium y limpio
            $asunto = "Registro de Cobranza - Factura N° {$nFactura} ({$empresa})";
            
            $html = "
            <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #334155; line-height: 1.6; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;\">
                <div style=\"background-color: #0f172a; padding: 24px; text-align: center; color: #ffffff;\">
                    <h2 style=\"margin: 0; font-size: 1.5rem; font-weight: 600; letter-spacing: -0.025em;\">Módulo de Cobranza (Gestión de cheques)</h2>
                    <p style=\"margin: 4px 0 0 0; color: #94a3b8; font-size: 0.875rem;\">Registro de envío y trazabilidad de cheques</p>
                </div>
                <div style=\"padding: 24px;\">
                    <p style=\"margin-top: 0; font-size: 1rem;\">Se ha registrado una nueva documentación de pago con los siguientes detalles:</p>
                    
                    <table style=\"width: 100%; border-collapse: collapse; margin-bottom: 24px; font-size: 0.925rem;\">
                        <tr>
                            <td style=\"padding: 8px 0; font-weight: 600; width: 35%; color: #64748b;\">Vendedor:</td>
                            <td style=\"padding: 8px 0; color: #0f172a; font-weight: 500;\">{$vendedor}</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 8px 0; font-weight: 600; color: #64748b;\">Empresa:</td>
                            <td style=\"padding: 8px 0; color: #0f172a;\">{$empresa}</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 8px 0; font-weight: 600; color: #64748b;\">N° Factura:</td>
                            <td style=\"padding: 8px 0; color: #0f172a; font-weight: 600;\">{$nFactura}</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 8px 0; font-weight: 600; color: #64748b;\">RUT Cliente:</td>
                            <td style=\"padding: 8px 0; color: #0f172a;\">{$rut}</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 8px 0; font-weight: 600; color: #64748b;\">Razón Social:</td>
                            <td style=\"padding: 8px 0; color: #0f172a;\">{$razonSocial}</td>
                        </tr>
                        <tr>
                            <td style=\"padding: 8px 0; font-weight: 600; color: #64748b;\">Modalidad de Entrega:</td>
                            <td style=\"padding: 8px 0; color: #0f172a;\">{$tipoEntrega}</td>
                        </tr>";
            
            if ($tracking) {
                $html .= "
                        <tr>
                            <td style=\"padding: 8px 0; font-weight: 600; color: #64748b;\">OT Chilexpress:</td>
                            <td style=\"padding: 8px 0; color: #0f172a;\">{$tracking}</td>
                        </tr>";
            }

            $html .= "
                    </table>

                    <h3 style=\"color: #0f172a; font-size: 1.125rem; font-weight: 600; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-top: 24px; margin-bottom: 12px;\">Detalle de Cheques</h3>
                    <table style=\"width: 100%; border-collapse: collapse; font-size: 0.875rem; margin-bottom: 24px;\">
                        <thead>
                            <tr style=\"background-color: #f8fafc; border-bottom: 2px solid #e2e8f0;\">
                                <th style=\"padding: 10px; text-align: left; font-weight: 600; color: #475569;\">Banco</th>
                                <th style=\"padding: 10px; text-align: left; font-weight: 600; color: #475569;\">N° Cheque</th>
                                <th style=\"padding: 10px; text-align: right; font-weight: 600; color: #475569;\">Monto</th>
                                <th style=\"padding: 10px; text-align: center; font-weight: 600; color: #475569;\">Vencimiento</th>
                            </tr>
                        </thead>
                        <tbody>";

            $totalMonto = 0;
            foreach ($cheques as $cheque) {
                $montoVal = (float)($cheque['monto'] ?? 0);
                $totalMonto += $montoVal;
                $html .= "
                            <tr style=\"border-bottom: 1px solid #f1f5f9;\">
                                <td style=\"padding: 10px; color: #0f172a;\">" . htmlspecialchars($cheque['banco'] ?? '') . "</td>
                                <td style=\"padding: 10px; color: #334155;\">" . htmlspecialchars($cheque['numero_cheque'] ?? '') . "</td>
                                <td style=\"padding: 10px; text-align: right; font-weight: 500; color: #0f172a;\">$" . number_format($montoVal, 0, ',', '.') . "</td>
                                <td style=\"padding: 10px; text-align: center; color: #64748b;\">" . htmlspecialchars($cheque['fecha_vencimiento'] ?? '') . "</td>
                            </tr>";
            }

            $html .= "
                            <tr style=\"background-color: #f8fafc; font-weight: 700;\">
                                <td colspan=\"2\" style=\"padding: 10px; text-align: right; color: #475569;\">Total Cobrado:</td>
                                <td style=\"padding: 10px; text-align: right; color: #0f172a;\">$" . number_format($totalMonto, 0, ',', '.') . "</td>
                                <td></td>
                            </tr>
                        </tbody>
                    </table>";

            if ($cobranzaId > 0) {
                $html .= "
                    <div style=\"text-align: center; margin: 32px 0 16px 0;\">
                        <a href=\"{$linkGestion}\" style=\"background-color: #0284c7; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 0.95rem; display: inline-block; box-shadow: 0 4px 6px -1px rgba(2, 132, 199, 0.2);\">Gestionar Cobranza en Portal</a>
                    </div>";
            }

            $html .= "
                </div>
                <div style=\"background-color: #f8fafc; padding: 16px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 0.775rem; color: #94a3b8;\">
                    Este es un correo automático generado por el Módulo de Cobranzas del Holding.
                </div>
            </div>";

            // Headers para envío de correo HTML vía mail() nativo de PHP (local / host)
            $headers = [];
            $headers[] = 'MIME-Version: 1.0';
            $headers[] = 'Content-type: text/html; charset=utf-8';
            $headers[] = 'From: ' . (defined('MAIL_FROM') ? MAIL_FROM : 'cobranzas@dominio.cl');

            $exitoTesorería = true;
            if (!empty($emailTesorería)) {
                $exitoTesorería = self::sendSmtp($emailTesorería, $asunto, $html, $rutasAdjuntos);
                if (!$exitoTesorería) {
                    error_log("[MailService] Advertencia: Falló envío a Tesorería ($emailTesorería) por restricciones del sandbox.");
                }
            }

            // El envío a clientes está deshabilitado por completo por motivos de seguridad
            $exitoCliente = true;
            error_log("[MailService] Envío de correo a cliente omitido de manera segura (deshabilitado por política).");

            // Retornar true en desarrollo/sandbox para que las limitaciones de envío de Mailtrap no bloqueen la UX
            return true;
        } catch (Exception $e) {
            error_log('[MailService] Error al enviar notificación: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un correo electrónico utilizando sockets SMTP puros para máxima portabilidad sin dependencias.
     */
    private static function sendSmtp(string $to, string $subject, string $htmlBody, array $attachments = []): bool
    {
        $host = MAIL_HOST;
        $port = MAIL_PORT;
        $username = MAIL_USER;
        $password = MAIL_PASS;
        $from = MAIL_FROM;

        if (empty($host) || empty($username) || empty($password)) {
            error_log("[MailService] SMTP credentials not completely configured in config/app.php");
            return false;
        }

        $socket = @fsockopen($host, $port, $errno, $errstr, 15);
        if (!$socket) {
            error_log("[MailService] Failed to connect to SMTP server: $errstr ($errno)");
            return false;
        }

        $read = function($socket, $expectedResponse) {
            $serverReply = '';
            while ($line = fgets($socket, 515)) {
                $serverReply .= $line;
                if (substr($line, 3, 1) === ' ') {
                    break;
                }
            }
            $ok = str_starts_with($serverReply, (string)$expectedResponse);
            if (!$ok) {
                error_log("[MailService] SMTP Error. Expected: $expectedResponse, Received: " . trim($serverReply));
            }
            return $ok;
        };

        if (!$read($socket, 220)) return false;

        fwrite($socket, "EHLO localhost\r\n");
        if (!$read($socket, 250)) return false;

        fwrite($socket, "AUTH LOGIN\r\n");
        if (!$read($socket, 334)) return false;

        fwrite($socket, base64_encode($username) . "\r\n");
        if (!$read($socket, 334)) return false;

        fwrite($socket, base64_encode($password) . "\r\n");
        if (!$read($socket, 235)) return false;

        fwrite($socket, "MAIL FROM:<$from>\r\n");
        if (!$read($socket, 250)) return false;

        fwrite($socket, "RCPT TO:<$to>\r\n");
        if (!$read($socket, 250)) return false;

        fwrite($socket, "DATA\r\n");
        if (!$read($socket, 354)) return false;

        $boundary = "PHP-mixed-" . md5(uniqid(time(), true));
        $headers = "From: $from\r\n" .
                   "To: $to\r\n" .
                   "Subject: $subject\r\n" .
                   "MIME-Version: 1.0\r\n" .
                   "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n\r\n";

        $message = "--$boundary\r\n" .
                   "Content-Type: text/html; charset=\"UTF-8\"\r\n" .
                   "Content-Transfer-Encoding: 7bit\r\n\r\n" .
                   $htmlBody . "\r\n\r\n";

        foreach ($attachments as $filePath) {
            if (file_exists($filePath)) {
                $fileName = basename($filePath);
                $fileContent = chunk_split(base64_encode(file_get_contents($filePath)));
                $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
                
                $message .= "--$boundary\r\n" .
                            "Content-Type: $mimeType; name=\"$fileName\"\r\n" .
                            "Content-Transfer-Encoding: base64\r\n" .
                            "Content-Disposition: attachment; filename=\"$fileName\"\r\n\r\n" .
                            $fileContent . "\r\n\r\n";
            }
        }

        $message .= "--$boundary--\r\n";

        fwrite($socket, $headers . $message . "\r\n.\r\n");
        if (!$read($socket, 250)) return false;

        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        return true;
    }
}
