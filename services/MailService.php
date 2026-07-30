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
            return self::sendSmtp($emailTesorería, $asunto, $html, $rutasAdjuntos);
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
            // 1. Obtener la cobranza
            $stmt = $pdo->prepare("
                SELECT c.*, e.nombre AS empresa_nombre, e.email_tesoreria_defecto
                FROM cobranzas c
                INNER JOIN empresas e ON c.empresa_id = e.id
                WHERE c.id = :id
            ");
            $stmt->execute([':id' => $cobranzaId]);
            $cobranza = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$cobranza) return false;

            // 2. Obtener cheques
            $stmtChq = $pdo->prepare("SELECT * FROM cheques WHERE cobranza_id = :id");
            $stmtChq->execute([':id' => $cobranzaId]);
            $cheques = $stmtChq->fetchAll(PDO::FETCH_ASSOC);

            // 3. Obtener facturas/cuotas
            $stmtFac = $pdo->prepare("SELECT * FROM cobranza_facturas WHERE cobranza_id = :id");
            $stmtFac->execute([':id' => $cobranzaId]);
            $facturas = $stmtFac->fetchAll(PDO::FETCH_ASSOC);

            $empresa = htmlspecialchars($cobranza['empresa_nombre'] ?? '');
            $vendedorNombre = htmlspecialchars($cobranza['vendedor_nombre'] ?? 'Vendedor');
            $vendedorEmail = $cobranza['vendedor_email'] ?? '';
            $rut = htmlspecialchars($cobranza['rut_cliente'] ?? '');
            $razonSocial = htmlspecialchars($cobranza['razon_social_cliente'] ?? '');
            $emailCuentasCorrientes = $cobranza['email_tesoreria_defecto'] ?: (defined('MAIL_FROM') ? MAIL_FROM : 'cuentascorrientes@automarco.cl');
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

            // --- B) ENVIAR NOTIFICACIÓN AL VENDEDOR ---
            if (!empty($vendedorEmail)) {
                $asuntoVendedor = "[PARA VENDEDOR] [CHEQUE APROBADO] Cobranza N° {$cobranzaId} para {$razonSocial}";
                $htmlVendedor = "
                <div style=\"font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; max-width: 600px; margin: 0 auto; color: #334155; line-height: 1.6; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden;\">
                    <div style=\"background-color: #0f172a; padding: 20px; text-align: center; color: #ffffff;\">
                        <h2 style=\"margin: 0; font-size: 1.35rem;\">¡Cobranza Aprobada!</h2>
                        <p style=\"margin: 4px 0 0 0; color: #94a3b8; font-size: 0.875rem;\">Módulo de Gestión de Cheques</p>
                    </div>
                    <div style=\"padding: 24px;\">
                        <p style=\"margin-top: 0;\">Hola <strong>{$vendedorNombre}</strong>,</p>
                        <p>Te informamos que Tesorería ha verificado y **aprobado la recepción física** de los cheques de tu cobranza:</p>
                        
                        <div style=\"background-color: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 16px; margin: 16px 0;\">
                            <p style=\"margin: 0 0 6px 0; color: #166534; font-weight: 700;\">Cliente: {$razonSocial}</p>
                            <p style=\"margin: 0 0 6px 0; color: #166534;\">RUT: {$rut}</p>
                            <p style=\"margin: 0; color: #166534;\">Monto Total: <strong>\$" . number_format((float)$cobranza['total_cheques'], 0, ',', '.') . "</strong></p>
                        </div>

                        <p style=\"font-size: 0.9rem; color: #64748b;\">La información fue enviada exitosamente a Cuentas Corrientes para su registro en Optimus.</p>
                    </div>
                </div>";

                self::sendSmtp($vendedorEmail, $asuntoVendedor, $htmlVendedor);
            }

            return true;

        } catch (Exception $e) {
            error_log('[MailService] Error en notificarValidacionTesorería: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía el consolidado de cobranzas diario a la digitadora correspondiente de la empresa.
     * Genera un diseño limpio, sin imágenes y optimizado para la lectura rápida.
     */
    public static function enviarResumenDiarioDigitadora(string $empresaNombre, array $cobranzas, string $destinatario, string $ccEmail, PDO $pdo): bool
    {
        try {
            $totalCobranzas = count($cobranzas);
            $asunto = "[PARA DIGITADORAS] Resumen Diario Cuentas Corrientes - " . $empresaNombre . " (" . date('d/m/Y') . ")";

            // Cabecera principal estilo corporativo
            $html = "
            <div style=\"font-family: 'Segoe UI', Arial, sans-serif; max-width: 750px; margin: 0 auto; color: #1e293b; line-height: 1.5; border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; background-color: #ffffff;\">
                <div style=\"background-color: #0f172a; padding: 24px; color: #ffffff;\">
                    <h2 style=\"margin: 0; font-size: 1.4rem; font-weight: 600; letter-spacing: -0.025em;\">Resumen Diario de Cobranzas</h2>
                    <p style=\"margin: 4px 0 0 0; color: #94a3b8; font-size: 0.875rem;\">Empresa: <strong>" . htmlspecialchars($empresaNombre) . "</strong> &nbsp;•&nbsp; Fecha: " . date('d/m/Y') . "</p>
                </div>
                <div style=\"padding: 24px;\">
                    <p style=\"margin-top: 0; font-size: 0.95rem; color: #475569;\">Estimada Digitadora, a continuación se detallan las <strong>{$totalCobranzas} cobranzas</strong> validadas por Tesorería para su correcto registro en Optimus ERP:</p>
            ";

            foreach ($cobranzas as $cobranza) {
                // Obtener cheques de esta cobranza (Datos ya corregidos y saneados por Tesorería si aplica)
                $stmtChq = $pdo->prepare("SELECT numero_cheque, banco, monto AS monto_cheque, fecha_vencimiento, comentario FROM cheques WHERE cobranza_id = ?");
                $stmtChq->execute([$cobranza['id']]);
                $cheques = $stmtChq->fetchAll(PDO::FETCH_ASSOC);

                $html .= "
                <div style=\"border: 1px solid #e2e8f0; border-radius: 6px; margin-bottom: 20px; padding: 16px; background-color: #f8fafc;\">
                    <div style=\"border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; margin-bottom: 12px;\">
                        <span style=\"font-size: 0.75rem; font-weight: 700; color: #2563eb; text-transform: uppercase;\">Cobranza N° " . $cobranza['id'] . "</span>
                        <h3 style=\"margin: 4px 0; font-size: 1.05rem; color: #0f172a;\">" . htmlspecialchars($cobranza['razon_social_cliente']) . "</h3>
                        <span style=\"font-size: 0.85rem; color: #64748b;\">RUT: <strong>" . htmlspecialchars($cobranza['rut_cliente']) . "</strong> &nbsp;•&nbsp; Factura/Doc: <strong>" . htmlspecialchars($cobranza['numero_factura']) . "</strong></span>
                        <div style=\"font-size: 0.8rem; color: #64748b; margin-top: 2px;\">Vendedor: " . htmlspecialchars($cobranza['vendedor_nombre']) . "</div>
                    </div>
                ";

                if ($cheques) {
                    $html .= "
                    <table style=\"width: 100%; border-collapse: collapse; font-size: 0.85rem;\">
                        <thead>
                            <tr style=\"background-color: #e2e8f0; color: #334155; text-align: left;\">
                                <th style=\"padding: 8px 10px; border-bottom: 1px solid #cbd5e1;\">Banco</th>
                                <th style=\"padding: 8px 10px; border-bottom: 1px solid #cbd5e1;\">N° Cheque</th>
                                <th style=\"padding: 8px 10px; border-bottom: 1px solid #cbd5e1; text-align: center;\">Vencimiento</th>
                                <th style=\"padding: 8px 10px; border-bottom: 1px solid #cbd5e1; text-align: right;\">Monto</th>
                            </tr>
                        </thead>
                        <tbody>
                    ";

                    $totalLote = 0;
                    foreach ($cheques as $chq) {
                        $montoVal = (float)$chq['monto_cheque'];
                        $totalLote += $montoVal;
                        $montoFmt = '$' . number_format($montoVal, 0, ',', '.');
                        $fechaFmt = date('d/m/Y', strtotime($chq['fecha_vencimiento']));
                        
                        $html .= "
                            <tr style=\"background-color: #ffffff; border-bottom: 1px solid #f1f5f9;\">
                                <td style=\"padding: 8px 10px; color: #0f172a;\">" . htmlspecialchars($chq['banco']) . "</td>
                                <td style=\"padding: 8px 10px; font-weight: 600; font-family: monospace; color: #0f172a;\">" . htmlspecialchars($chq['numero_cheque']) . "</td>
                                <td style=\"padding: 8px 10px; text-align: center; color: #475569;\">" . $fechaFmt . "</td>
                                <td style=\"padding: 8px 10px; text-align: right; font-weight: 700; font-family: monospace; color: #166534;\">" . $montoFmt . "</td>
                            </tr>
                        ";
                        
                        if (!empty($chq['comentario'])) {
                            $html .= "
                            <tr style=\"background-color: #fdfefe;\">
                                <td colspan=\"4\" style=\"padding: 4px 10px 8px 10px; font-size: 0.78rem; color: #64748b; font-style: italic; border-bottom: 1px solid #f1f5f9;\">
                                    Nota cheque: \"" . htmlspecialchars($chq['comentario']) . "\"
                                </td>
                            </tr>
                            ";
                        }
                    }

                    $html .= "
                            <tr style=\"background-color: #f1f5f9; font-weight: 700;\">
                                <td colspan=\"3\" style=\"padding: 8px 10px; text-align: right; color: #475569;\">Total Cobranza:</td>
                                <td style=\"padding: 8px 10px; text-align: right; font-family: monospace; color: #0f172a;\">$" . number_format($totalLote, 0, ',', '.') . "</td>
                            </tr>
                        </tbody>
                    </table>
                    ";
                } else {
                    $html .= "<p style=\"font-size: 0.85rem; color: #dc2626; margin: 0;\">No se encontraron cheques adjuntos en esta cobranza.</p>";
                }

                $html .= "</div>";
            }

            $html .= "
                </div>
                <div style=\"background-color: #f8fafc; padding: 16px; text-align: center; border-top: 1px solid #e2e8f0; font-size: 0.775rem; color: #94a3b8;\">
                    Este es un informe diario generado automáticamente por el Módulo de Finanzas & Cobranzas.
                </div>
            </div>
            ";

            return self::sendSmtp($destinatario, $asunto, $html, [], $ccEmail);
        } catch (Exception $e) {
            error_log('[MailService] Error al enviar resumen a digitadoras: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un correo electrónico utilizando sockets SMTP puros para máxima portabilidad sin dependencias.
     */
    public static function sendSmtp(string $to, string $subject, string $htmlBody, array $attachments = [], string $cc = ''): bool
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
        
        if (!empty($cc)) {
            fwrite($socket, "RCPT TO:<$cc>\r\n");
            if (!$read($socket, 250)) return false;
        }

        fwrite($socket, "DATA\r\n");
        if (!$read($socket, 354)) return false;

        $boundary = "PHP-mixed-" . md5(uniqid(time(), true));
        $headers = "From: $from\r\n" .
                   "To: $to\r\n" .
                   (!empty($cc) ? "Cc: $cc\r\n" : "") .
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
