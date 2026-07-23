<?php
// Configurar cabecera para responder a JavaScript en formato JSON
header('Content-Type: application/json; charset=utf-8');

// 1. CONFIGURACIÓN DE MAILTRAP (SANDBOX)
$apiToken = '62ce644b5ee678f63d2d1ed3927cb8a3'; 
$inboxId  = '4335946'; // Reemplaza con tu ID de Inbox de Mailtrap Sandbox

// 2. CAPTURAR DATOS RECIBIDOS DEL FORMULARIO
$vendedor       = 'Vendedor_Nombre'; // Temporal (luego se obtendrá del perfil logueado)
$empresa        = $_POST['empresa_vendedor'] ?? '';
$nFactura       = $_POST['numero_factura']    ?? '';
$rut            = $_POST['rut_cliente']        ?? '';
$emailCliente   = $_POST['email_cliente']      ?? '';
$emailTesoreria = $_POST['email_tesoreria']    ?? '';
$entrega        = $_POST['tipo_entrega']       ?? '';
$seguimiento    = $_POST['numero_seguimiento'] ?? '';

// Capturar arrays de cheques
$bancos             = $_POST['banco'] ?? [];
$numCheques         = $_POST['numero_cheque'] ?? [];
$montosCheques      = $_POST['monto_cheque'] ?? [];
$fechasVenc         = $_POST['fecha_vencimiento'] ?? [];
$comentariosCheques = $_POST['comentario_cheque'] ?? [];

// 3. ESTRUCTURA DE ADJUNTOS (Convertir fotos subidas a Base64)
$attachments = [];

// Función auxiliar para agregar adjunto
function agregarAdjunto(&$attachments, $fileData, $customName = null) {
    if (isset($fileData['error']) && $fileData['error'] === UPLOAD_ERR_OK) {
        $fileContent = file_get_contents($fileData['tmp_name']);
        $attachments[] = [
            'content'     => base64_encode($fileContent),
            'filename'    => $customName ?: $fileData['name'],
            'type'        => $fileData['type'],
            'disposition' => 'attachment'
        ];
    }
}

// Procesar fotos de cheques (que vienen en un array)
if (isset($_FILES['foto_cheque'])) {
    $files = $_FILES['foto_cheque'];
    if (is_array($files['name'])) {
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] === UPLOAD_ERR_OK) {
                $fileContent = file_get_contents($files['tmp_name'][$i]);
                $attachments[] = [
                    'content'     => base64_encode($fileContent),
                    'filename'    => "cheque_" . ($i + 1) . "_" . $files['name'][$i],
                    'type'        => $files['type'][$i],
                    'disposition' => 'attachment'
                ];
            }
        }
    } else {
        // En caso de que se suba una sola no-array (fallback)
        agregarAdjunto($attachments, $files);
    }
}

// Procesar foto de Chilexpress (comprobante)
if (isset($_FILES['foto_comprobante'])) {
    agregarAdjunto($attachments, $_FILES['foto_comprobante'], 'comprobante_chilexpress_' . ($_FILES['foto_comprobante']['name'] ?? ''));
}

// Procesar foto de Firma/Recepción Santiago
if (isset($_FILES['foto_firma'])) {
    agregarAdjunto($attachments, $_FILES['foto_firma'], 'comprobante_recepcion_santiago_' . ($_FILES['foto_firma']['name'] ?? ''));
}

// 4. CONSTRUIR DETALLE HTML DE CHEQUES PARA EL CORREO
$htmlCheques = '';
$totalCheques = 0;
if (!empty($bancos)) {
    $htmlCheques .= '
    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; border-color: #e2e8f0; font-family: sans-serif;">
        <thead style="background-color: #f1f5f9;">
            <tr>
                <th align="left">#</th>
                <th align="left">Banco</th>
                <th align="left">N° Cheque</th>
                <th align="right">Monto</th>
                <th align="left">Vencimiento</th>
                <th align="left">Comentario</th>
            </tr>
        </thead>
        <tbody>';
    
    for ($i = 0; $i < count($bancos); $i++) {
        $montoVal = floatval($montosCheques[$i] ?? 0);
        $totalCheques += $montoVal;
        $comentarioVal = htmlspecialchars($comentariosCheques[$i] ?? '');
        
        $htmlCheques .= '
            <tr>
                <td>' . ($i + 1) . '</td>
                <td>' . htmlspecialchars($bancos[$i] ?? '') . '</td>
                <td>' . htmlspecialchars($numCheques[$i] ?? '') . '</td>
                <td align="right">$' . number_format($montoVal, 0, ',', '.') . '</td>
                <td>' . htmlspecialchars($fechasVenc[$i] ?? '') . '</td>
                <td>' . $comentarioVal . '</td>
            </tr>';
    }
    
    $htmlCheques .= '
            <tr style="font-weight: bold; background-color: #f8fafc;">
                <td colspan="3" align="right">Total Cheques:</td>
                <td align="right">$' . number_format($totalCheques, 0, ',', '.') . '</td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>';
} else {
    $htmlCheques = '<p>No se registraron detalles de cheques.</p>';
}

// 5. CONFIGURAR DESTINATARIOS
$destinatarios = [];
// Correo obligatorio de Tesorería/Cobranza
if (!empty($emailTesoreria)) {
    $destinatarios[] = ["email" => $emailTesoreria, "name" => "Tesoreria y Cobranza"];
} else {
    // Fallback por defecto si viene vacío
    $destinatarios[] = ["email" => "maximiliano.santibanez@mail.udp.cl", "name" => "Cobranza UDP"];
}

// Correo opcional del Cliente (CC o adicional)
if (!empty($emailCliente)) {
    $destinatarios[] = ["email" => $emailCliente, "name" => "Cliente Factura"];
}

// 6. CONSTRUIR EL CUERPO DEL MENSAJE (JSON) EXIGIDO POR MAILTRAP
$payload = [
    "from" => [
        "email" => "hello@demomailtrap.co",
        "name"  => "Módulo de Cobranza"
    ],
    "to"       => $destinatarios,
    "subject"  => "Registro de Cobranza - Factura N° " . $nFactura,
    "text"     => "Se ha registrado un pago para la factura $nFactura.\nVendedor: $vendedor\nEmpresa: $empresa\nRUT Cliente: $rut\nTotal Cobrado: $" . number_format($totalCheques, 0, ',', '.') . "\nModalidad: $entrega",
    "html"     => "
        <div style='font-family: sans-serif; max-width: 600px; margin: 0 auto; color: #1e293b;'>
            <h2 style='color: #1e3a8a; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;'>Registro de Cobranza Exitoso</h2>
            <p>Se ha recibido una nueva documentación de pago con los siguientes detalles:</p>
            
            <p><strong>Vendedor:</strong> " . htmlspecialchars($vendedor) . "</p>
            <p><strong>Empresa:</strong> " . htmlspecialchars($empresa) . "</p>
            <p><strong>N° Factura:</strong> " . htmlspecialchars($nFactura) . "</p>
            <p><strong>RUT Cliente:</strong> " . htmlspecialchars($rut) . "</p>
            <p><strong>Modalidad de Entrega:</strong> " . htmlspecialchars($entrega) . "</p>
            " . (!empty($seguimiento) ? "<p><strong>N° Seguimiento Chilexpress:</strong> " . htmlspecialchars($seguimiento) . "</p>" : "") . "
            
            <h3 style='color: #1e3a8a; margin-top: 24px;'>Detalle de Documentos (Cheques)</h3>
            {$htmlCheques}
            
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 24px 0;'>
            <p style='font-size: 0.85em; color: #64748b;'><em>Las fotos adjuntas se encuentran incluidas en este correo como archivos adjuntos.</em></p>
        </div>
    ",
    "category" => "Cobranza PWA"
];

if (!empty($attachments)) {
    $payload["attachments"] = $attachments;
}

// 7. ENVIAR PETICIÓN cURL A MAILTRAP (SANDBOX)
$ch = curl_init("https://sandbox.api.mailtrap.io/api/send/{$inboxId}");

curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $apiToken,
        'Content-Type: application/json'
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload)
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// 8. RESPONDER AL FRONTEND (script.js)
if ($httpCode === 200 || $httpCode === 201) {
    echo json_encode([
        'status'  => 'success',
        'message' => 'Correo enviado correctamente a Mailtrap'
    ]);
} else {
    echo json_encode([
        'status'   => 'error',
        'httpCode' => $httpCode,
        'message'  => $curlError ?: json_decode($response, true)
    ]);
}