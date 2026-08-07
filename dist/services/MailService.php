<?php
/**
 * MailService.php — Servicio de Envío de Notificaciones por Correo
 * 
 * Centraliza el envío de correos a Tesorería y Clientes.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/PdfGenerator.php';
require_once __DIR__ . '/../libs/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../libs/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../libs/PHPMailer/src/SMTP.php';

class MailService
{
    private static function getConfigValue(?PDO $pdo, string $clave, string $default): string
    {
        if (!$pdo) {
            try {
                $pdo = Database::getCobranzasConnection();
            } catch (Exception $e) {
                return $default;
            }
        }
        try {
            $stmt = $pdo->prepare("SELECT valor FROM configuraciones_sistema WHERE clave = ?");
            $stmt->execute([$clave]);
            $val = $stmt->fetchColumn();
            return (!empty($val) && trim($val) !== '') ? trim($val) : $default;
        } catch (Exception $e) {
            return $default;
        }
    }
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
            $asunto = "[PARA TESORERIA] Registro de Cobranza - Factura N° {$nFactura} ({$empresa})";
            
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

            $emailTesorería = self::getConfigValue(null, 'email_tesoreria_general', 'tesoreria@automarco.cl');

            $defaultCC = self::getConfigValue(null, 'email_cuentas_corrientes_general', 'cuentascorrientes@automarco.cl');
            $emailCuentasCorrientes = !empty($cobranza['email_tesoreria_defecto']) ? $cobranza['email_tesoreria_defecto'] : $defaultCC;

            $asuntoCC = "[NUEVO REGISTRO] Cobranza Factura N° {$nFactura} ({$empresa})";
            
            // Envío principal a Cuentas Corrientes con Copia (CC) a Tesorería
            $ccDestination = (!empty($emailTesorería) && $emailTesorería !== $emailCuentasCorrientes) ? $emailTesorería : '';
            self::sendSmtp($emailCuentasCorrientes, $asuntoCC, $html, $rutasAdjuntos, $ccDestination);

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
     * Envía notificaciones tras la validación física de Tesorería:
     * 1. Correo a Cuentas Corrientes con la información completa de la cobranza validada.
     * 2. Correo de confirmación al Vendedor avisando que su cheque/cobranza fue aprobada.
     */
    public static function notificarValidacionTesorería(PDO $pdo, int $cobranzaId): bool
    {
        if (!defined('MAIL_HOST') || empty(MAIL_HOST)) {
            error_log('[MailService] MAIL_HOST no configurado. Omitiendo envío de correo de validación.');
            return false;
        }

        try {
            // 1. Obtener la cobranza con JOIN a usuarios para traer vendedor_email
            $stmt = $pdo->prepare("
                SELECT c.*, e.nombre AS empresa_nombre, e.email_tesoreria_defecto, u.email AS vendedor_email
                FROM cobranzas c
                INNER JOIN empresas e ON c.empresa_id = e.id
                LEFT JOIN usuarios u ON c.vendedor_id = u.id
                WHERE c.id = :id
            ");
            $stmt->execute([':id' => $cobranzaId]);
            $cobranza = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cobranza) return false;

            // Obtener cheques (usar cheques_filtrados si existe, para soportar la fragmentación por empresa)
            if (isset($cobranza['cheques_filtrados'])) {
                $cheques = $cobranza['cheques_filtrados'];
            } else {
                $stmtCheques = $pdo->prepare("SELECT * FROM cheques WHERE cobranza_id = ?");
                $stmtCheques->execute([$cobranzaId]);
                $cheques = $stmtCheques->fetchAll(PDO::FETCH_ASSOC);
            }

            // 3. Obtener facturas/cuotas
            $stmtFac = $pdo->prepare("SELECT * FROM cobranza_facturas WHERE cobranza_id = :id");
            $stmtFac->execute([':id' => $cobranzaId]);
            $facturas = $stmtFac->fetchAll(PDO::FETCH_ASSOC);

            $empresa = htmlspecialchars($cobranza['empresa_nombre'] ?? '');
            $vendedorNombre = htmlspecialchars($cobranza['vendedor_nombre'] ?? 'Vendedor');
            $vendedorEmail = $cobranza['vendedor_email'] ?? '';
            $rut = htmlspecialchars($cobranza['rut_cliente'] ?? '');
            $razonSocial = htmlspecialchars($cobranza['razon_social_cliente'] ?? '');
            $defaultCC = self::getConfigValue($pdo, 'email_cuentas_corrientes_general', 'cuentascorrientes@automarco.cl');
            $emailCuentasCorrientes = !empty($cobranza['email_tesoreria_defecto']) ? $cobranza['email_tesoreria_defecto'] : $defaultCC;
            $linkPortal = PORTAL_BASE_URL . "admin/index.php?id=" . $cobranzaId;

            // --- A) ENVIAR CORREO A CUENTAS CORRIENTES ---
            $asuntoCC = "[PARA C.CORRIENTES] [VALIDADO TESORERÍA] Cobranza N° {$cobranzaId} - {$razonSocial} ({$empresa})";
            
            $htmlCheques = '';
            foreach ($cheques as $chq) {
                $montoFmt = number_format((float)$chq['monto'], 0, ',', '.');
                $htmlCheques .= "<tr>
                    <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0;'>{$chq['banco']}</td>
                    <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0;'><strong>{$chq['numero_cheque']}</strong></td>
                    <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: right;'>\${$montoFmt}</td>
                    <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: center;'>{$chq['fecha_vencimiento']}</td>
                </tr>";
            }

            $htmlFacturas = '';
            foreach ($facturas as $fac) {
                $cuota = !empty($fac['cuota_label']) ? " ({$fac['cuota_label']})" : '';
                $montoFacFmt = number_format((float)($fac['monto_cubierto'] ?? 0), 0, ',', '.');
                $htmlFacturas .= "<tr>
                    <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0;'>Factura N° {$fac['numero_factura']}{$cuota}</td>
                    <td style='padding: 6px 8px; border-bottom: 1px solid #e2e8f0; text-align: right;'>\${$montoFacFmt}</td>
                </tr>";
            }

            $htmlCC = "
            <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #334155; line-height: 1.6; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;\">
                <div style=\"background-color: #166534; padding: 20px; text-align: center; color: #ffffff;\">
                    <h2 style=\"margin: 0; font-size: 1.35rem;\">✓ Cobranza Validada por Tesorería</h2>
                    <p style=\"margin: 4px 0 0 0; color: #bbf7d0; font-size: 0.875rem;\">Información de Cheques Lista para Digitar en Optimus</p>
                </div>
                <div style=\"padding: 24px;\">
                    <p style=\"margin-top: 0;\">Tesorería ha recibido y validado físicamente los cheques correspondientes a la siguiente cobranza:</p>
                    
                    <table style=\"width: 100%; border-collapse: collapse; margin-bottom: 16px; font-size: 0.9rem;\">
                        <tr><td style=\"padding: 4px 0; font-weight: 600; color: #64748b;\">Empresa:</td><td><strong>{$empresa}</strong></td></tr>
                        <tr><td style=\"padding: 4px 0; font-weight: 600; color: #64748b;\">Cliente:</td><td>{$razonSocial} (RUT: {$rut})</td></tr>
                        <tr><td style=\"padding: 4px 0; font-weight: 600; color: #64748b;\">Vendedor:</td><td>{$vendedorNombre}</td></tr>
                    </table>

                    <h4 style=\"margin: 16px 0 8px 0; color: #0f172a;\">Detalle de Cheques Validados</h4>
                    <table style=\"width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 16px;\">
                        <thead>
                            <tr style=\"background-color: #f8fafc; border-bottom: 2px solid #e2e8f0;\">
                                <th style=\"padding: 6px 8px; text-align: left;\">Banco</th>
                                <th style=\"padding: 6px 8px; text-align: left;\">N° Cheque</th>
                                <th style=\"padding: 6px 8px; text-align: right;\">Monto</th>
                                <th style=\"padding: 6px 8px; text-align: center;\">Vencimiento</th>
                            </tr>
                        </thead>
                        <tbody>{$htmlCheques}</tbody>
                    </table>

                    <h4 style=\"margin: 16px 0 8px 0; color: #0f172a;\">Facturas / Cuotas Abonadas</h4>
                    <table style=\"width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-bottom: 20px;\">
                        <tbody>{$htmlFacturas}</tbody>
                    </table>

                    <div style=\"text-align: center; margin-top: 24px;\">
                        <a href=\"{$linkPortal}\" style=\"display: inline-block; background-color: #0f172a; color: #ffffff; padding: 10px 20px; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 0.9rem;\">Abrir en Portal Admin</a>
                    </div>
                </div>
            </div>";

            self::sendSmtp($emailCuentasCorrientes, $asuntoCC, $htmlCC);

            // --- B) ENVIAR NOTIFICACIÓN AL VENDEDOR (DESHABILITADO POR POLÍTICA) ---
            error_log("[MailService] Envío de correo a vendedor omitido de manera segura (deshabilitado por política).");

            return true;

        } catch (Exception $e) {
            error_log('[MailService] Error en notificarValidacionTesorería: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía correo de notificación al Vendedor notificando el rechazo de la cobranza por parte de Tesorería.
     */
    public static function notificarRechazoTesoreria(PDO $pdo, int $cobranzaId, string $motivoRechazo): bool
    {
        error_log("[MailService] Envío de correo de rechazo a vendedor omitido de manera segura (deshabilitado por política).");
        return true;
    }

    /**
     * Envía el consolidado de cobranzas diario a la digitadora correspondiente de la empresa.
     * Genera un diseño limpio, sin imágenes y optimizado para la lectura rápida.
     */
    public static function enviarResumenDiarioDigitadora(string $empresaNombre, array $cobranzas, string $destinatario, PDO $pdo): bool
    {
        try {
            $totalCobranzas = count($cobranzas);
            $asunto = "[PARA DIGITADORAS] Resumen Diario Cuentas Corrientes - " . $empresaNombre . " (" . date('d/m/Y') . ")";

            $html = "
            <div style=\"font-family: 'Segoe UI', Arial, sans-serif; max-width: 750px; margin: 0 auto; color: #1e293b; line-height: 1.5; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background-color: #ffffff;\">
                <div style=\"background-color: #0f172a; padding: 24px; color: #ffffff;\">
                    <h2 style=\"margin: 0; font-size: 1.4rem; font-weight: 600; letter-spacing: -0.025em;\">Resumen Diario de Cobranzas</h2>
                    <p style=\"margin: 4px 0 0 0; color: #94a3b8; font-size: 0.875rem;\">Empresa: <strong>" . htmlspecialchars($empresaNombre ?? '') . "</strong> &nbsp;•&nbsp; Fecha: " . date('d/m/Y') . "</p>
                </div>
                <div style=\"padding: 24px;\">
                    <p style=\"margin-top: 0; font-size: 0.95rem; color: #475569;\">Estimada Digitadora, a continuación se adjunta en formato <strong>PDF</strong> el detalle de las <strong>{$totalCobranzas} cobranzas</strong> validadas por Tesorería para su correcto registro en Optimus ERP.</p>
                    <p style=\"margin-top: 15px; font-size: 0.85rem; color: #64748b;\">Por favor, revise el documento adjunto para ver el desglose completo de facturas, cuotas y cheques asociados.</p>
                </div>
            </div>
            ";

            // Generar PDF
            $pdfPath = PdfGenerator::generateResumenDiario($empresaNombre, $cobranzas, $pdo);

            $enviado = self::sendSmtp($destinatario, $asunto, $html, [$pdfPath]);
            
            // Limpiar PDF temporal
            if (file_exists($pdfPath)) {
                @unlink($pdfPath);
            }
            
            return $enviado;
        } catch (Exception $e) {
            error_log('[MailService] Error al enviar resumen a digitadoras: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un correo electrónico utilizando PHPMailer.
     */
    public static function sendSmtp(string $to, string $subject, string $htmlBody, array $attachments = [], string $cc = ''): bool
    {
        if (!defined('MAIL_HOST') || empty(MAIL_HOST)) {
            error_log('[MailService] MAIL_HOST no configurado.');
            return false;
        }

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = MAIL_HOST;
            $mail->SMTPAuth   = true;
            $mail->Username   = MAIL_USER;
            $mail->Password   = MAIL_PASS;
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = MAIL_PORT;

            $mail->setFrom(MAIL_FROM, 'Módulo de Cobranzas');
            
            $toArray = array_filter(array_map('trim', explode(',', $to)));
            foreach ($toArray as $address) {
                if (filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $mail->addAddress($address);
                }
            }

            if (!empty($cc)) {
                $ccArray = array_filter(array_map('trim', explode(',', $cc)));
                foreach ($ccArray as $ccAddress) {
                    if (filter_var($ccAddress, FILTER_VALIDATE_EMAIL)) {
                        $mail->addCC($ccAddress);
                    }
                }
            }

            foreach ($attachments as $adjunto) {
                if (file_exists($adjunto)) {
                    $mail->addAttachment($adjunto);
                }
            }

            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $htmlBody));

            $mail->send();
            return true;
        } catch (Exception $e) {
            error_log('[MailService] Error al enviar correo vía PHPMailer: ' . $mail->ErrorInfo);
            return false;
        }
    }
    /**
     * Envía una alerta de cobranza demorada (Cron Job).
     */
    public static function enviarAlertaDemora(string $vendedorNombre, string $vendedorEmail, array $cobranzaData, int $diasTranscurridos, int $diasMaximos, string $ccEmail): bool
    {
        $id = $cobranzaData['id'];
        $cliente = htmlspecialchars($cobranzaData['razon_social_cliente']);
        $estado = htmlspecialchars($cobranzaData['estado']);
        $creado = date('d/m/Y', strtotime($cobranzaData['created_at']));
        
        $asunto = "⚠️ [ALERTA] Cobranza {$id} atrasada en tránsito ($diasTranscurridos días)";

        $html = "
        <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #334155; line-height: 1.6; border: 1px solid #f87171; border-radius: 8px; overflow: hidden;\">
            <div style=\"background-color: #ef4444; padding: 24px; text-align: center; color: #ffffff;\">
                <h2 style=\"margin: 0; font-size: 1.5rem; font-weight: 600;\">Alerta de Atraso en Cobranza</h2>
                <p style=\"margin: 4px 0 0 0; font-size: 0.9rem;\">Gestión de cheques pendiente de entrega</p>
            </div>
            <div style=\"padding: 24px;\">
                <p>Hola <strong>{$vendedorNombre}</strong>,</p>
                <p>El sistema ha detectado que la siguiente cobranza lleva <strong>{$diasTranscurridos} días</strong> en estado <em>{$estado}</em>, superando el límite máximo de {$diasMaximos} días.</p>
                
                <div style=\"background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 16px; margin: 20px 0;\">
                    <p style=\"margin: 0 0 8px; font-weight: 600;\">📋 Detalle del Documento:</p>
                    <ul style=\"margin: 0; padding-left: 20px;\">
                        <li><strong>ID Cobranza:</strong> #{$id}</li>
                        <li><strong>Cliente:</strong> {$cliente}</li>
                        <li><strong>Fecha de Ingreso:</strong> {$creado}</li>
                        <li><strong>Estado Actual:</strong> {$estado}</li>
                    </ul>
                </div>
                
                <p>Por favor, acércate a Tesorería o coordina la entrega/despacho de estos documentos a la brevedad para regularizar el estado contable de esta operación.</p>
                
                <p style=\"margin-top: 24px; font-size: 0.85rem; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 16px;\">
                    Este es un mensaje automático generado por el Módulo de Cobranzas. Cuentas Corrientes ha sido notificado en copia.
                </p>
            </div>
        </div>";

        return self::sendSmtp($ccEmail, $asunto, $html);
    }
}
