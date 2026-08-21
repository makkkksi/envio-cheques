<?php
// ============================================================
// AUTOTEC E-COMMERCE - Configuración
// ============================================================

define('DB_HOST', 'dbaws.automarco.cl');
define('DB_USER', 'admingabtec@db-gabtec-server');
define('DB_PASS', '2wkPnhSa4x');
define('DB_NAME', 'autotec_ecom');

define('IVA_PORCENTAJE', 19);
define('EMP_ID', '1');
define('SESSION_TTL', 28800);
define('BASE_URL', 'https://www.autotec.cl/vendedores');

// Código canónico de empresa para interoperabilidad con Cobranzas
function getEmpresaCodigo(): string {
    if (defined('DB_NAME')) {
        if (DB_NAME === 'gabteccl_sitbdd1978') return 'EMP10';
        if (DB_NAME === 'automarc_automarco') return 'EMP01';
        if (DB_NAME === 'autohd_automarcohd') return 'EMP06';
        if (DB_NAME === 'autotec_ecom') return 'EMP03';
    }
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    if (strpos($host, 'gabtec') !== false) return 'EMP10';
    if (strpos($host, 'automarcohd') !== false || strpos($host, 'hdautomarco') !== false) return 'EMP06';
    if (strpos($host, 'automarco') !== false) return 'EMP01';
    if (strpos($host, 'autotec') !== false) return 'EMP03';
    return 'EMP03';
}
define('EMPRESA_CODIGO', getEmpresaCodigo());

// ============================================================
// Correo (replica GmailSender.java de la app Android)
// ============================================================
define('SMTP_HOST', 'mail.holdingautomarco.com');
define('SMTP_PORT', 26);
define('SMTP_USER', 'pedidos_autotec@holdingautomarco.com');
define('SMTP_PASS', 'coam20002000');
define('SMTP_FROM_NAME', 'AUTOTEC');

// Interruptor temporal: si es false, NO se envía el correo de confirmación
// de PEDIDO (EN PROCESO) — el de COTIZACIÓN no se ve afectado por esto.
define('ENVIAR_CORREO_PEDIDO_CONFIRMADO', true);

// ============================================================
// Webservice de inyección de pedidos (replica Persistencia.java)
// ============================================================
define('WS_ENDPOINT', 'http://dmz.automarco.cl/autotec/webservice/servidor_tablet.php');

date_default_timezone_set('America/Santiago');

// ============================================================
// Conexión PDO global
// ============================================================
function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $e) {
            error_log('[Autotec DB] ' . $e->getMessage());
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'data' => null, 'msg' => 'Error de conexión a base de datos']);
            exit;
        }
    }
    return $pdo;
}

// ============================================================
// Respuesta JSON helper
// ============================================================
function jsonResponse(bool $ok, $data = null, string $msg = ''): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode(['ok' => $ok, 'data' => $data, 'msg' => $msg]);
    exit;
}

// ============================================================
// Autenticación por token
// ============================================================
function requireAuth(): array {
    $token = $_SERVER['HTTP_X_TOKEN'] ?? ($_COOKIE['at_token'] ?? '');
    if (!$token) jsonResponse(false, null, 'No autenticado');

    $pdo  = getDB();
    $stmt = $pdo->prepare("
        SELECT u.*, s.token 
        FROM web_sesiones s 
        JOIN web_usuarios u ON u.id = s.usuario_id
        WHERE s.token = ? AND s.expira_en > NOW() AND u.activo = 1
    ");
    $stmt->execute([$token]);
    $user = $stmt->fetch();
    if (!$user) jsonResponse(false, null, 'Sesión inválida o expirada');
    return $user;
}

function formatoPrecio(int $valor): string {
    return '$' . number_format($valor, 0, ',', '.');
}

// ============================================================
// ENVÍO DE CORREO — vía PHPMailer (misma librería y configuración
// que ya funciona en la app de Cobranza: mismo host/puerto, con
// SMTPOptions para no verificar el certificado). El remitente es
// el de pedidos (Android), no el de cobranza.
// ============================================================
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

function enviarCorreoSMTP(string $to, string $subject, string $htmlBody): array {
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->SMTPAuth = true;
        $mail->Host     = SMTP_HOST;
        $mail->Username = SMTP_USER;
        $mail->Password = SMTP_PASS;
        $mail->Port     = SMTP_PORT;

        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];

        $mail->From     = SMTP_USER;
        $mail->FromName = SMTP_FROM_NAME;

        $destinatarios = array_filter(array_map('trim', explode(',', $to)));
        foreach ($destinatarios as $rcpt) {
            if (filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
                $mail->addAddress($rcpt);
            }
        }
        if (!$mail->getAllRecipientAddresses()) {
            return ['ok' => false, 'msg' => 'No hay destinatarios con correo válido'];
        }

        $mail->CharSet = 'UTF-8';
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;

        if (!$mail->send()) {
            error_log('[SMTP] Envío falló: ' . $mail->ErrorInfo);
            return ['ok' => false, 'msg' => 'El servidor de correo rechazó el envío: ' . $mail->ErrorInfo];
        }
        return ['ok' => true, 'msg' => 'Correo enviado'];
    } catch (\Exception $e) {
        error_log('[SMTP] Excepción: ' . $mail->ErrorInfo . ' / ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Error al enviar correo: ' . $mail->ErrorInfo];
    }
}

// ============================================================
// LLAMADA SOAP CRUDA (sin WSDL) — replica HttpTransportSE de
// ksoap2 en Android: arma el envelope a mano y hace POST directo
// a .../servidor_tablet.php/{metodo}
// ============================================================
function llamarSoapWS(string $metodo, array $params): ?string {
    $paramsXml = '';
    foreach ($params as $k => $v) {
        $paramsXml .= "<$k>" . htmlspecialchars((string)$v, ENT_XML1) . "</$k>";
    }
    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">'
        . '<soap:Body><' . $metodo . ' xmlns="">' . $paramsXml . '</' . $metodo . '></soap:Body>'
        . '</soap:Envelope>';

    $ch = curl_init(WS_ENDPOINT . '/' . $metodo);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $envelope,
        CURLOPT_HTTPHEADER     => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: ""'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
    ]);
    $response = curl_exec($ch);
    $err      = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Guardar traza completa (request + response) para diagnóstico — se
    // incluye en la respuesta JSON al confirmar el pedido, y también en
    // el log de PHP.
    $GLOBALS['_soap_trace'][] = [
        'metodo'    => $metodo,
        'url'       => WS_ENDPOINT . '/' . $metodo,
        'request'   => $envelope,
        'http_code' => $httpCode,
        'response'  => $response === false ? "[cURL error: $err]" : $response,
    ];

    error_log("[WS $metodo] URL: " . WS_ENDPOINT . '/' . $metodo);
    error_log("[WS $metodo] Request: " . $envelope);
    error_log("[WS $metodo] HTTP $httpCode — Response: " . ($response === false ? "cURL error: $err" : $response));

    if ($response === false) {
        return null;
    }
    return $response;
}

// ============================================================
// Extrae el primer valor de la respuesta SOAP — equivalente a
// result.getProperty(0) de ksoap2 en Android (toma el primer
// elemento hijo dentro de <Body>, y de ese, su primer valor).
// ============================================================
function extraerPrimerValorSoap(?string $xml): ?string {
    if (!$xml) return null;
    libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) return null;

    $bodies = $doc->getElementsByTagName('Body');
    if ($bodies->length === 0) return null;

    foreach ($bodies->item(0)->childNodes as $child) {
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        // Buscar el primer nieto con contenido (el valor de retorno real)
        foreach ($child->childNodes as $grandchild) {
            if ($grandchild->nodeType === XML_ELEMENT_NODE) {
                return trim($grandchild->textContent);
            }
        }
        $text = trim($child->textContent);
        if ($text !== '') return $text;
    }
    return null;
}