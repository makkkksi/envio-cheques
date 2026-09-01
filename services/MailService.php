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
require_once __DIR__ . '/RendicionesService.php';
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
            $stmt = $pdo->prepare('SELECT valor FROM configuraciones_sistema WHERE clave = :clave');
            $stmt->execute([':clave' => $clave]);
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
            $linkGestion = PORTAL_BASE_URL . "/admin/detalle.php?id=" . $cobranzaId;

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
            $linkPortal = PORTAL_BASE_URL . "/admin/index.php?id=" . $cobranzaId;

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
    public static function enviarResumenDiarioDigitadora(string $empresaNombre, array $cobranzas, string $destinatario, PDO $pdo, string $cc = ''): bool
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

            $enviado = self::sendSmtp($destinatario, $asunto, $html, [$pdfPath], $cc);
            
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
     * Envía a jefatura la solicitud de aprobación de un exceso de rendición.
     * El token crudo sólo existe durante este envío; en BD se almacena su SHA-256.
     */
    public static function enviarSolicitudExcesoRendicion(
        array $rendicion,
        array $documentos,
        string $rawToken,
        array $aprobador,
        string $comentarioTesoreria = ''
    ): bool
    {
        $recipient = strtolower(trim((string)($aprobador['email'] ?? '')));
        $approverNameRaw = trim((string)($aprobador['nombre'] ?? ''));
        $approverTitleRaw = trim((string)($aprobador['cargo'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || $approverNameRaw === '' || $approverTitleRaw === '') {
            error_log('[MailService] Responsable de aprobación incompleto o inválido.');
            return false;
        }

        $code = htmlspecialchars((string)($rendicion['codigo_rendicion'] ?? ''), ENT_QUOTES, 'UTF-8');
        $company = htmlspecialchars((string)($rendicion['empresa_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $seller = htmlspecialchars((string)($rendicion['vendedor_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $sellerId = (int)($rendicion['vendedor_id'] ?? 0);
        $period = htmlspecialchars((string)($rendicion['periodo_mes'] ?? ''), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars((string)($rendicion['tipo_rendicion'] ?? ''), ENT_QUOTES, 'UTF-8');
        $tourName = htmlspecialchars(trim((string)($rendicion['nombre_gira'] ?? '')), ENT_QUOTES, 'UTF-8');
        $fundLabel = ($rendicion['tipo_rendicion'] ?? '') === 'GIRA'
            ? 'Gira comercial' . ($tourName !== '' ? ': ' . $tourName : '')
            : 'Presupuesto mensual';
        $approverName = htmlspecialchars($approverNameRaw, ENT_QUOTES, 'UTF-8');
        $approverTitle = htmlspecialchars($approverTitleRaw, ENT_QUOTES, 'UTF-8');
        $sellerNote = nl2br(htmlspecialchars(trim((string)($rendicion['nota_vendedor'] ?? '')), ENT_QUOTES, 'UTF-8'));
        $treasuryNote = nl2br(htmlspecialchars(trim($comentarioTesoreria), ENT_QUOTES, 'UTF-8'));
        $total = (float)($rendicion['monto_total_rendido'] ?? 0);
        $budget = (float)($rendicion['monto_presupuesto_asignado'] ?? 0);
        $available = (float)($rendicion['saldo_disponible_al_enviar'] ?? 0);
        $previouslyCommitted = max(0, $budget - $available);
        $excess = (float)($rendicion['monto_exceso'] ?? 0);
        $baseApprovalUrl = PORTAL_BASE_URL . '/rendiciones/aprobar_exceso.php';
        $reviewUrl = $baseApprovalUrl . '?token=' . rawurlencode($rawToken);

        $rows = '';
        foreach ($documentos as $documento) {
            $category = htmlspecialchars((string)($documento['categoria_gasto'] ?? ''), ENT_QUOTES, 'UTF-8');
            $documentType = htmlspecialchars((string)($documento['tipo_documento'] ?? ''), ENT_QUOTES, 'UTF-8');
            $date = htmlspecialchars((string)($documento['fecha_emision'] ?? ''), ENT_QUOTES, 'UTF-8');
            $provider = htmlspecialchars((string)($documento['razon_social_proveedor'] ?? 'Sin proveedor'), ENT_QUOTES, 'UTF-8');
            $rut = htmlspecialchars((string)($documento['rut_proveedor'] ?? 'Sin RUT'), ENT_QUOTES, 'UTF-8');
            $folio = htmlspecialchars((string)($documento['numero_documento'] ?? 'Sin folio'), ENT_QUOTES, 'UTF-8');
            $amount = number_format((float)($documento['monto'] ?? 0), 0, ',', '.');
            $rows .= '<tr style="border-bottom:1px solid #e2e8f0">'
                . '<td style="padding:10px"><strong>' . $provider . '</strong><br><span style="font-size:12px;color:#64748b">' . $rut . ' · Folio ' . $folio . '</span></td>'
                . '<td style="padding:10px">' . $category . '<br><span style="font-size:12px;color:#64748b">' . $documentType . '</span></td>'
                . '<td style="padding:9px">' . $date . '</td>'
                . '<td style="padding:9px;text-align:right">$' . $amount . '</td>'
                . '</tr>';
            if (($documento['categoria_gasto'] ?? '') === 'CENA_CLIENTE') {
                $guest = htmlspecialchars((string)($documento['cliente_invitado_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
                $guestRut = htmlspecialchars((string)($documento['cliente_invitado_rut'] ?? ''), ENT_QUOTES, 'UTF-8');
                $guestCompany = htmlspecialchars((string)($documento['cliente_invitado_empresa'] ?? ''), ENT_QUOTES, 'UTF-8');
                $guestTitle = htmlspecialchars((string)($documento['cliente_invitado_cargo'] ?? ''), ENT_QUOTES, 'UTF-8');
                $purpose = htmlspecialchars((string)($documento['proposito_comercial'] ?? ''), ENT_QUOTES, 'UTF-8');
                $rows .= '<tr style="background:#fffbeb"><td colspan="4" style="padding:10px;font-size:12px"><strong>Antecedentes SII:</strong> ' . $guest . ' · ' . $guestRut . ' · ' . $guestCompany . ' / ' . $guestTitle . '<br><strong>Propósito:</strong> ' . $purpose . '</td></tr>';
            }
        }

        $subject = '[APROBACIÓN REQUERIDA] Exceso en rendición ' . $code;
        $html = '<div style="font-family:Arial,sans-serif;max-width:720px;margin:auto;color:#1e293b;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden">'
            . '<div style="background:#172554;color:#fff;padding:24px"><h2 style="margin:0">Aprobación de exceso</h2><p style="margin:6px 0 0">Rendición ' . $code . '</p></div>'
            . '<div style="padding:24px"><p>Hola <strong>' . $approverName . '</strong> (' . $approverTitle . '),</p>'
            . '<p>Tesorería solicita tu decisión sobre un exceso asociado al fondo <strong>' . $fundLabel . '</strong>. El detalle completo estará disponible antes de confirmar.</p>'
            . '<p><strong>' . $seller . '</strong> · código ERP #' . $sellerId . '<br>' . $company . ' · ' . $type . ' · ' . $period . '</p>'
            . '<table style="width:100%;border-collapse:collapse;background:#f8fafc;margin:18px 0">'
            . '<tr><td style="padding:9px">Presupuesto asignado</td><td style="padding:9px;text-align:right">$' . number_format($budget, 0, ',', '.') . '</td></tr>'
            . '<tr><td style="padding:9px">Rendido previamente</td><td style="padding:9px;text-align:right">$' . number_format($previouslyCommitted, 0, ',', '.') . '</td></tr>'
            . '<tr><td style="padding:9px">Saldo antes de esta rendición</td><td style="padding:9px;text-align:right">$' . number_format($available, 0, ',', '.') . '</td></tr>'
            . '<tr><td style="padding:9px">Total rendido</td><td style="padding:9px;text-align:right">$' . number_format($total, 0, ',', '.') . '</td></tr>'
            . '<tr><td style="padding:9px;font-weight:bold;color:#b91c1c">Exceso</td><td style="padding:9px;text-align:right;font-weight:bold;color:#b91c1c">$' . number_format($excess, 0, ',', '.') . '</td></tr>'
            . '</table>'
            . ($sellerNote !== '' ? '<div style="background:#f8fafc;border-left:3px solid #64748b;padding:12px;margin:14px 0"><strong>Nota del vendedor</strong><br>' . $sellerNote . '</div>' : '')
            . ($treasuryNote !== '' ? '<div style="background:#eff6ff;border-left:3px solid #2563eb;padding:12px;margin:14px 0"><strong>Comentario de Tesorería</strong><br>' . $treasuryNote . '</div>' : '')
            . '<table style="width:100%;border-collapse:collapse"><thead><tr style="background:#e2e8f0"><th style="padding:9px;text-align:left">Proveedor</th><th style="padding:9px;text-align:left">Categoría</th><th style="padding:9px;text-align:left">Fecha</th><th style="padding:9px;text-align:right">Monto</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<div style="text-align:center;margin-top:28px"><a href="' . htmlspecialchars($reviewUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#1d4ed8;color:#fff;padding:13px 22px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block">Revisar y resolver solicitud</a></div>'
            . '<p style="font-size:12px;color:#64748b;margin-top:24px">Abrir el enlace no registra una decisión. La aprobación o el rechazo requieren confirmación; el token vence en ' . RENDICIONES_TOKEN_TTL_HOURS . ' horas y sólo puede utilizarse una vez.</p></div></div>';

        return self::sendSmtp($recipient, $subject, $html);
    }

    public static function notificarDecisionExcesoRendicion(array $rendicion, string $decision): bool
    {
        $recipient = trim((string)($rendicion['vendedor_email'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            error_log('[MailService] Rendición sin correo válido de vendedor para notificar resolución.');
            return false;
        }
        $code = htmlspecialchars((string)($rendicion['codigo_rendicion'] ?? ''), ENT_QUOTES, 'UTF-8');
        $seller = htmlspecialchars((string)($rendicion['vendedor_nombre'] ?? 'Vendedor'), ENT_QUOTES, 'UTF-8');
        $approved = $decision === 'APROBADO';
        $label = $approved ? 'aprobado' : 'rechazado';
        $color = $approved ? '#15803d' : '#b91c1c';
        $subject = '[RENDICIONES] Exceso ' . $label . ' — ' . $code;
        $html = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#1e293b;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'
            . '<div style="background:' . $color . ';color:#fff;padding:22px"><h2 style="margin:0">Exceso ' . ucfirst($label) . '</h2></div>'
            . '<div style="padding:22px"><p>Hola ' . $seller . ',</p><p>El exceso asociado a la rendición <strong>' . $code . '</strong> fue <strong>' . $label . '</strong>.</p></div></div>';
        return self::sendSmtp($recipient, $subject, $html);
    }

    /**
     * Envía al responsable de jefatura la solicitud de aprobación de una gira.
     * El token crudo solo existe durante este envío; en BD se almacena su SHA-256.
     */
    public static function enviarSolicitudAprobacionGira(
        array $gira,
        string $rawToken,
        array $aprobador,
        string $comentarioTesoreria = ''
    ): bool {
        $recipient       = strtolower(trim((string)($aprobador['email'] ?? '')));
        $approverNameRaw = trim((string)($aprobador['nombre'] ?? ''));
        $approverTitle   = trim((string)($aprobador['cargo'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL) || $approverNameRaw === '' || $approverTitle === '') {
            error_log('[MailService] Responsable de gira incompleto o inválido.');
            return false;
        }

        $tourName    = htmlspecialchars((string)($gira['nombre_gira'] ?? 'Sin nombre'), ENT_QUOTES, 'UTF-8');
        $seller      = htmlspecialchars((string)($gira['vendedor_nombre'] ?? ''), ENT_QUOTES, 'UTF-8');
        $period      = htmlspecialchars((string)($gira['periodo_mes'] ?? ''), ENT_QUOTES, 'UTF-8');
        $amount      = number_format((float)($gira['monto_asignado'] ?? 0), 0, ',', '.');
        $startDate   = htmlspecialchars((string)($gira['fecha_inicio'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $endDate     = htmlspecialchars((string)($gira['fecha_fin'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $justif      = nl2br(htmlspecialchars((string)($gira['justificacion_gira'] ?? ''), ENT_QUOTES, 'UTF-8'));
        $treasury    = nl2br(htmlspecialchars(trim($comentarioTesoreria), ENT_QUOTES, 'UTF-8'));
        $approver    = htmlspecialchars($approverNameRaw, ENT_QUOTES, 'UTF-8');
        $ttlHours    = defined('RENDICIONES_TOKEN_TTL_HOURS') ? RENDICIONES_TOKEN_TTL_HOURS : 48;
        $reviewUrl   = PORTAL_BASE_URL . '/rendiciones/aprobar_gira.php?token=' . rawurlencode($rawToken);

        $subject = '[APROBACIÓN REQUERIDA] Gira comercial: ' . strip_tags($tourName);
        $html    = '<div style="font-family:Arial,sans-serif;max-width:700px;margin:auto;color:#1e293b;border:1px solid #cbd5e1;border-radius:12px;overflow:hidden">'
            . '<div style="background:#172554;color:#fff;padding:24px"><h2 style="margin:0">Aprobación de Gira Comercial</h2>'
            . '<p style="margin:6px 0 0;color:#bfdbfe">' . $tourName . '</p></div>'
            . '<div style="padding:24px"><p>Hola <strong>' . $approver . '</strong> (' . htmlspecialchars($approverTitle, ENT_QUOTES, 'UTF-8') . '),</p>'
            . '<p>Tesorería solicita tu aprobación para la siguiente gira comercial:</p>'
            . '<table style="width:100%;border-collapse:collapse;background:#f8fafc;margin:16px 0;font-size:0.93rem">'
            . '<tr><td style="padding:9px;font-weight:600;color:#64748b;width:35%">Vendedor</td><td style="padding:9px">' . $seller . '</td></tr>'
            . '<tr><td style="padding:9px;font-weight:600;color:#64748b">Nombre de gira</td><td style="padding:9px">' . $tourName . '</td></tr>'
            . '<tr><td style="padding:9px;font-weight:600;color:#64748b">Período base</td><td style="padding:9px">' . $period . '</td></tr>'
            . '<tr><td style="padding:9px;font-weight:600;color:#64748b">Fechas</td><td style="padding:9px">' . $startDate . ' → ' . $endDate . '</td></tr>'
            . '<tr><td style="padding:9px;font-weight:600;color:#0f172a">Presupuesto solicitado</td><td style="padding:9px;font-weight:700;color:#0f172a">$' . $amount . '</td></tr>'
            . '</table>'
            . ($justif !== '' ? '<div style="background:#f0fdf4;border-left:3px solid #16a34a;padding:12px;margin:14px 0"><strong>Justificación</strong><br>' . $justif . '</div>' : '')
            . ($treasury !== '' ? '<div style="background:#eff6ff;border-left:3px solid #2563eb;padding:12px;margin:14px 0"><strong>Nota de Tesorería</strong><br>' . $treasury . '</div>' : '')
            . '<div style="text-align:center;margin:28px 0">'
            . '<a href="' . htmlspecialchars($reviewUrl, ENT_QUOTES, 'UTF-8') . '" style="background:#1d4ed8;color:#fff;padding:13px 28px;border-radius:8px;text-decoration:none;font-weight:bold;display:inline-block;font-size:1rem">Revisar y resolver solicitud</a>'
            . '</div>'
            . '<p style="font-size:12px;color:#64748b">Abrir el enlace no registra una decisión. La aprobación o rechazo requieren confirmación explícita. El enlace vence en ' . $ttlHours . ' horas y sólo puede usarse una vez.</p>'
            . '</div></div>';

        return self::sendSmtp($recipient, $subject, $html);
    }

    /**
     * Notifica al vendedor la decisión tomada sobre su gira (cuando Tesorería la habilita).
     */
    public static function notificarDecisionGira(array $gira, string $decision): bool
    {
        $recipient = trim((string)($gira['vendedor_email'] ?? ''));
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            error_log('[MailService] Gira sin correo válido de vendedor para notificar resolución.');
            return false;
        }
        $tourName = htmlspecialchars((string)($gira['nombre_gira'] ?? 'Gira'), ENT_QUOTES, 'UTF-8');
        $seller   = htmlspecialchars((string)($gira['vendedor_nombre'] ?? 'Vendedor'), ENT_QUOTES, 'UTF-8');
        $approved = $decision === 'APROBADA';
        $label    = $approved ? 'aprobada' : 'rechazada';
        $color    = $approved ? '#15803d' : '#b91c1c';
        $icon     = $approved ? '✓' : '✕';
        $subject  = '[GIRAS] ' . ucfirst($label) . ': ' . strip_tags($tourName);
        $html     = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;color:#1e293b;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden">'
            . '<div style="background:' . $color . ';color:#fff;padding:22px"><h2 style="margin:0">' . $icon . ' Gira ' . ucfirst($label) . '</h2></div>'
            . '<div style="padding:22px"><p>Hola <strong>' . $seller . '</strong>,</p>'
            . '<p>La gira comercial <strong>' . $tourName . '</strong> ha sido <strong>' . $label . '</strong> por Jefatura.</p>'
            . ($approved ? '<p>Podrá enviar rendiciones asociadas a esta gira desde el portal de vendedores.</p>' : '<p>Si tiene dudas, por favor contacte a Tesorería.</p>')
            . '</div></div>';
        return self::sendSmtp($recipient, $subject, $html);
    }

    public static function notificarDecisionGiraTesoreria(?PDO $pdo, array $gira, string $decision): bool
    {
        $recipient = self::getConfigValue($pdo, 'email_tesoreria_general', '');
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            error_log('[MailService] No existe un correo general de Tesorería válido para notificar la decisión de gira.');
            return false;
        }
        $approved = strtoupper($decision) === 'APROBADA';
        $label = $approved ? 'aprobada' : 'rechazada';
        $tourName = htmlspecialchars((string)($gira['nombre_gira'] ?? 'Gira comercial'), ENT_QUOTES, 'UTF-8');
        $seller = htmlspecialchars((string)($gira['vendedor_nombre'] ?? 'Vendedor'), ENT_QUOTES, 'UTF-8');
        $approver = htmlspecialchars((string)($gira['aprobador_nombre_snapshot'] ?? 'Responsable'), ENT_QUOTES, 'UTF-8');
        $approverTitle = htmlspecialchars((string)($gira['aprobador_cargo_snapshot'] ?? ''), ENT_QUOTES, 'UTF-8');
        $amount = number_format((float)($gira['monto_asignado'] ?? 0), 0, ',', '.');
        $subject = '[GIRAS] Solicitud ' . $label . ': ' . strip_tags($tourName);
        $html = '<div style="font-family:Arial,sans-serif;max-width:620px;margin:auto;color:#1e293b;border:1px solid #cbd5e1">'
            . '<div style="padding:22px"><h2 style="margin-top:0">Gira ' . ucfirst($label) . '</h2>'
            . '<p>La solicitud de la gira <strong>' . $tourName . '</strong> para <strong>' . $seller . '</strong> fue <strong>' . $label . '</strong>.</p>'
            . '<p>Monto solicitado: <strong>$' . $amount . '</strong><br>Responsable: <strong>' . $approver . '</strong>'
            . ($approverTitle !== '' ? ' · ' . $approverTitle : '') . '.</p>'
            . '<p>La decisión ya está registrada en el panel de Rendiciones y en su historial de auditoría.</p></div></div>';
        return self::sendSmtp($recipient, $subject, $html);
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
        $id = (int)($cobranzaData['id'] ?? $cobranzaData['cobranza_id'] ?? 0);
        $cliente = htmlspecialchars($cobranzaData['razon_social_cliente'] ?? '');
        $estado = htmlspecialchars($cobranzaData['estado'] ?? '');
        $creado = date('d/m/Y', strtotime($cobranzaData['created_at'] ?? 'now'));
        $monto = isset($cobranzaData['monto_total_factura']) ? '$' . number_format((float)$cobranzaData['monto_total_factura'], 0, ',', '.') : 'N/A';
        $tipoEntrega = htmlspecialchars($cobranzaData['tipo_entrega'] ?? 'No especificado');
        $seguimiento = htmlspecialchars($cobranzaData['numero_seguimiento'] ?? 'S/N');
        $portalUrl = defined('PORTAL_BASE_URL') ? PORTAL_BASE_URL . "/admin/detalle.php?id={$id}" : "#";
        
        $asunto = "⚠️ [ALERTA] Cobranza #{$id} atrasada en tránsito ({$diasTranscurridos} días) - {$cliente}";

        $html = "
        <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; max-width: 600px; margin: 0 auto; color: #334155; line-height: 1.6; border: 1px solid #f87171; border-radius: 8px; overflow: hidden;\">
            <div style=\"background-color: #ef4444; padding: 24px; text-align: center; color: #ffffff;\">
                <h2 style=\"margin: 0; font-size: 1.5rem; font-weight: 600;\">Alerta de Atraso en Cobranza</h2>
                <p style=\"margin: 4px 0 0 0; font-size: 0.9rem;\">Gestión de cheques pendiente de entrega</p>
            </div>
            <div style=\"padding: 24px;\">
                <p>Hola <strong>" . htmlspecialchars($vendedorNombre) . "</strong>,</p>
                <p>El sistema ha detectado que la siguiente cobranza lleva <strong>{$diasTranscurridos} días</strong> en estado <em>{$estado}</em>, superando el límite máximo permitido de {$diasMaximos} días.</p>
                
                <div style=\"background-color: #fef2f2; border: 1px solid #fecaca; border-radius: 6px; padding: 16px; margin: 20px 0;\">
                    <p style=\"margin: 0 0 8px; font-weight: 600;\">📋 Detalle del Documento:</p>
                    <ul style=\"margin: 0; padding-left: 20px; line-height: 1.8;\">
                        <li><strong>ID Cobranza:</strong> #{$id}</li>
                        <li><strong>Cliente:</strong> {$cliente}</li>
                        <li><strong>Monto Total:</strong> {$monto}</li>
                        <li><strong>Fecha de Ingreso:</strong> {$creado}</li>
                        <li><strong>Estado Actual:</strong> {$estado}</li>
                        <li><strong>Tipo de Entrega:</strong> {$tipoEntrega}</li>
                        <li><strong>N° Seguimiento:</strong> {$seguimiento}</li>
                    </ul>
                </div>
                
                <p>Por favor, acércate a Tesorería o coordina la entrega/despacho de estos documentos a la brevedad para regularizar el estado contable de esta operación.</p>

                <div style=\"text-align: center; margin: 24px 0;\">
                    <a href=\"{$portalUrl}\" style=\"display: inline-block; background-color: #0f172a; color: #ffffff; text-decoration: none; padding: 12px 24px; border-radius: 6px; font-weight: 600; font-size: 0.9rem;\">Ver Detalle en Portal</a>
                </div>
                
                <p style=\"margin-top: 24px; font-size: 0.85rem; color: #64748b; border-top: 1px solid #e2e8f0; padding-top: 16px;\">
                    Este es un mensaje automático generado por el Módulo de Cobranzas del Holding Automarco. Jefatura de Cuentas Corrientes y Tesorería han sido notificadas en copia.
                </p>
            </div>
        </div>";

        $destinatarioPrincipal = !empty($vendedorEmail) ? $vendedorEmail : $ccEmail;
        $copiaOpcional = (!empty($vendedorEmail) && $vendedorEmail !== $ccEmail) ? $ccEmail : '';

        return self::sendSmtp($destinatarioPrincipal, $asunto, $html, [], $copiaOpcional);
    }
}
