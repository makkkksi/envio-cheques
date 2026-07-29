<?php
require_once __DIR__ . '/../config/db.php';

echo "=== INICIANDO PRUEBA END-TO-END DIRECTA (FASE 5) ===\n\n";

try {
    $pdo = Database::getCobranzasConnection();

    // 1. Obtener cliente de vendedor_id=2 (Ariel Jara / Juan Carlos Quiroz)
    $vendedor_id = 2;
    $empresa_code = 'EMP03'; // Autotec

    $stmtMail = $pdo->prepare("SELECT ven_mail FROM autotec_ecom.tbl_vendedores WHERE cli_vendedor = :vid AND ven_mail != ''");
    $stmtMail->execute([':vid' => $vendedor_id]);
    $email = $stmtMail->fetchColumn();

    $stmtCli = $pdo->prepare("
        SELECT 
            c.clirut, c.clidv,
            MAX(COALESCE(cli.cli_razon_social, CONCAT('CLIENTE ', c.clirut))) as razon_social
        FROM bd_automarco.tbl_cobranza c
        LEFT JOIN autotec_ecom.tbl_clientes cli ON REPLACE(REPLACE(cli.cli_rut, '.', ''), '-', '') LIKE CONCAT(c.clirut, '%')
        WHERE c.empresa = :emp AND c.vendedor = :vid
        GROUP BY c.clirut, c.clidv
        LIMIT 1
    ");
    $stmtCli->execute([':emp' => $empresa_code, ':vid' => $vendedor_id]);
    $cliente = $stmtCli->fetch(PDO::FETCH_ASSOC);

    if (!$cliente) {
        die("❌ ERROR: No se encontró cliente de prueba para vendedor 2 en EMP03\n");
    }

    $rutCompleto = $cliente['clirut'] . '-' . $cliente['clidv'];
    echo "1. ✅ Cliente obtenido: {$cliente['razon_social']} (RUT: {$rutCompleto})\n";

    // 2. Consultar facturas impagas del cliente cross-empresa
    $stmtFact = $pdo->prepare("
        SELECT 
            c.empresa AS codigo_empresa,
            CASE c.empresa
                WHEN 'EMP01' THEN 'Automarco LTDA'
                WHEN 'EMP03' THEN 'Autotec S.A'
                WHEN 'EMP06' THEN 'HD Automarco S.A'
                WHEN 'EMP10' THEN 'Gabtec S.A'
                ELSE c.empresa
            END AS empresa_nombre,
            c.docto AS numero_factura,
            CAST(c.saldo_cuota AS DECIMAL(15,2)) AS saldo_cuota,
            c.vencto AS fecha_vencimiento
        FROM bd_automarco.tbl_cobranza c
        WHERE c.clirut = :rut AND c.saldo_cuota > 0
        ORDER BY c.vencto ASC
    ");
    $stmtFact->execute([':rut' => $cliente['clirut']]);
    $facturas = $stmtFact->fetchAll(PDO::FETCH_ASSOC);

    echo "2. ✅ Facturas impagas encontradas: " . count($facturas) . "\n";
    foreach (array_slice($facturas, 0, 5) as $f) {
        echo "   - Doc N° {$f['numero_factura']} [{$f['codigo_empresa']} - {$f['empresa_nombre']}] Saldo: $" . number_format($f['saldo_cuota'], 0, ',', '.') . "\n";
    }

    $facturasAEnviar = array_slice($facturas, 0, 2);
    $totalMontoFacturas = array_sum(array_column($facturasAEnviar, 'saldo_cuota'));

    echo "\n3. 📤 Ejecutando guardado atómico en bd_modulo_cobranzas...\n";
    echo "   Monto Total Facturas Seleccionadas: $" . number_format($totalMontoFacturas, 0, ',', '.') . "\n";

    $pdo->beginTransaction();

    $stmtCob = $pdo->prepare("
        INSERT INTO cobranzas (
            vendedor_id, rut_cliente, razon_social_cliente,
            monto_total_factura, estado, created_at
        ) VALUES (
            :vendedor_id, :rut_cliente, :razon_social_cliente,
            :monto_total_factura, 'PENDIENTE_ENVIO', NOW()
        )
    ");
    $stmtCob->execute([
        ':vendedor_id' => $vendedor_id,
        ':rut_cliente' => $rutCompleto,
        ':razon_social_cliente' => $cliente['razon_social'],
        ':monto_total_factura' => $totalMontoFacturas
    ]);
    $cobranzaId = $pdo->lastInsertId();

    // Mapa de empresa_id central por código
    $empresaIdMap = ['EMP01' => 1, 'EMP06' => 2, 'EMP03' => 3, 'EMP10' => 4];

    // Guardar facturas en la pivote
    $stmtPvt = $pdo->prepare("
        INSERT INTO cobranza_facturas (
            cobranza_id, empresa_id, codigo_empresa,
            numero_factura, total_cuota, saldo_cuota, monto_cubierto
        ) VALUES (
            :cobranza_id, :empresa_id, :codigo_empresa,
            :numero_factura, :total_cuota, :saldo_cuota, :monto_cubierto
        )
    ");

    foreach ($facturasAEnviar as $f) {
        $stmtPvt->execute([
            ':cobranza_id' => $cobranzaId,
            ':empresa_id' => $empresaIdMap[$f['codigo_empresa']] ?? 1,
            ':codigo_empresa' => $f['codigo_empresa'],
            ':numero_factura' => $f['numero_factura'],
            ':total_cuota' => $f['saldo_cuota'],
            ':saldo_cuota' => $f['saldo_cuota'],
            ':monto_cubierto' => $f['saldo_cuota']
        ]);
    }

    // Guardar cheque de prueba
    $stmtChk = $pdo->prepare("
        INSERT INTO cheques (
            cobranza_id, banco, numero_cheque, monto,
            fecha_vencimiento, comentario, foto_cheque_url
        ) VALUES (
            :cobranza_id, 'BANCO DE CHILE', '99887766', :monto,
            :fecha_vencimiento, 'Prueba E2E Multi-Factura con Smart Client Picker', 'uploads/cheque_test_e2e.jpg'
        )
    ");
    $stmtChk->execute([
        ':cobranza_id' => $cobranzaId,
        ':monto' => $totalMontoFacturas,
        ':fecha_vencimiento' => date('Y-m-d', strtotime('+30 days'))
    ]);

    $pdo->commit();

    echo "4. 🎉 Cobranza creada exitosamente con ID: #{$cobranzaId}\n\n";

    // Verificación
    echo "5. 🔍 VERIFICACIÓN DE TABLA PIVOTE (cobranza_facturas):\n";
    $stmtVerPvt = $pdo->prepare("SELECT * FROM cobranza_facturas WHERE cobranza_id = :id");
    $stmtVerPvt->execute([':id' => $cobranzaId]);
    $pvtRows = $stmtVerPvt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($pvtRows as $row) {
        echo "   -> Cobranza #{$row['cobranza_id']} | Empresa: {$row['codigo_empresa']} | Factura N° {$row['numero_factura']} | Saldo: $" . number_format($row['saldo_cuota'], 0, ',', '.') . " | Cubierto: $" . number_format($row['monto_cubierto'], 0, ',', '.') . "\n";
    }

    echo "\n6. 🔍 VERIFICACIÓN DE CHEQUES (cheques):\n";
    $stmtVerChk = $pdo->prepare("SELECT id, banco, numero_cheque, monto, foto_cheque_url FROM cheques WHERE cobranza_id = :id");
    $stmtVerChk->execute([':id' => $cobranzaId]);
    $chkRows = $stmtVerChk->fetchAll(PDO::FETCH_ASSOC);

    foreach ($chkRows as $chk) {
        echo "   -> Cheque #{$chk['id']} | Banco: {$chk['banco']} | N°: {$chk['numero_cheque']} | Monto: $" . number_format($chk['monto'], 0, ',', '.') . " | Foto: {$chk['foto_cheque_url']}\n";
    }

    echo "\n✅ PRUEBA END-TO-END COMPLETADA CON ÉXITO ABSOLUTO.\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    echo "❌ EXCEPCIÓN: " . $e->getMessage() . "\n";
}

