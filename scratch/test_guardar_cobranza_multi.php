<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();

    // Create fake photo for test in uploads/1/2026-07/cheques/
    $uploadDir = UPLOADS_BASE_PATH . '/1/' . date('Y-m') . '/cheques';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_img');
    file_put_contents($tmpFile, 'fake_image_content');

    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['rut_cliente'] = '77891200-7';
    $_POST['razon_social_cliente'] = 'BALEO REPUESTOS LTDA.';
    $_POST['vendedor_id'] = '2';
    
    $_POST['facturas'] = json_encode([
        [
            'empresa_id' => 4,
            'codigo_empresa' => 'EMP10',
            'numero_factura' => '003163',
            'total_cuota' => 3023727,
            'saldo_cuota' => 3023727,
            'monto_cubierto' => 3023727
        ],
        [
            'empresa_id' => 4,
            'codigo_empresa' => 'EMP10',
            'numero_factura' => '003164',
            'total_cuota' => 6848237,
            'saldo_cuota' => 6848237,
            'monto_cubierto' => 6848237
        ]
    ]);

    $_POST['banco'] = ['Banco de Chile'];
    $_POST['numero_cheque'] = ['CHQ-990011'];
    $_POST['monto_cheque'] = [9871964];
    $_POST['fecha_vencimiento'] = ['2026-08-15'];
    $_POST['comentario_cheque'] = ['Pago multi-factura prueba'];

    $_FILES['foto_cheque'] = [
        'name' => ['test_chq.jpg'],
        'type' => ['image/jpeg'],
        'tmp_name' => [$tmpFile],
        'error' => [UPLOAD_ERR_OK],
        'size' => [100]
    ];

    include __DIR__ . '/../api/guardar_cobranza.php';

} catch (Exception $e) {
    echo "Error en test: " . $e->getMessage() . "\n";
}
