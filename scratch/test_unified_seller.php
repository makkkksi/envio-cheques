<?php
require_once __DIR__ . '/../config/db.php';

try {
    $pdo = Database::getCobranzasConnection();

    // Query to resolve seller email for every invoice in tbl_cobranza based on company code:
    $sql = "
        SELECT 
            c.empresa,
            c.vendedor AS local_vendedor_id,
            COALESCE(v1.ven_mail, v2.ven_mail, v3.ven_mail, v4.ven_mail) AS email_vendedor,
            COALESCE(v1.nombre_vendedor, v2.ven_nombre, v3.nombre_vendedor, v4.nombre_vendedor) AS nombre_vendedor,
            COUNT(*) AS total_facturas
        FROM bd_automarco.tbl_cobranza c
        LEFT JOIN automarc_automarco.tbl_vendedores v1 
               ON c.empresa = 'EMP01' AND c.vendedor = v1.cli_vendedor
        LEFT JOIN gabteccl_sitbdd1978.tbl_vendedores v2 
               ON c.empresa = 'EMP10' AND c.vendedor = v2.cli_vendedor
        LEFT JOIN autotec_ecom.tbl_vendedores v3 
               ON c.empresa = 'EMP03' AND c.vendedor = v3.cli_vendedor
        LEFT JOIN autohd_automarcohd.tbl_vendedores v4 
               ON c.empresa = 'EMP06' AND c.vendedor = v4.cli_vendedor
        WHERE c.empresa != 'EMP07'
        GROUP BY email_vendedor, nombre_vendedor, c.empresa, local_vendedor_id
        HAVING email_vendedor IS NOT NULL AND email_vendedor != ''
        ORDER BY email_vendedor ASC, c.empresa ASC
        LIMIT 25
    ";

    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "=== UNIFIED SELLER RESOLUTION BY EMAIL & COMPANY ===\n";
    print_r($rows);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
