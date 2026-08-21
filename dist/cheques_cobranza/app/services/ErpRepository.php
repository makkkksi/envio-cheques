<?php
/**
 * services/ErpRepository.php
 * 
 * Capa de abstracción para consultar datos directamente al ERP.
 * Evita el acoplamiento fuerte de nombres de bases de datos en los controladores API.
 */

require_once __DIR__ . '/../config/db.php';

class ErpRepository
{
    /**
     * Obtiene la deuda activa de un cliente en el ERP Automarco.
     * 
     * @param PDO $pdo Conexión a la base de datos (central o la que tenga visibilidad)
     * @param string $rutCliente RUT del cliente (sin dígito verificador ni guión)
     * @return array Mapa de deudas indexado por "CodigoEmpresa_NumFactura"
     */
    public static function obtenerDeudaClienteAutomarco(PDO $pdo, string $rutCliente): array
    {
        $clirutVal = explode('-', $rutCliente)[0];
        $clirutVal = preg_replace('/[^0-9]/', '', $clirutVal);

        if (empty($clirutVal)) {
            return [];
        }

        // Se aísla el nombre de la BD. A futuro, esto puede venir de configuración o usar getErpConnection().
        // Por las reglas del proyecto (Nombres de BD ERP inmutables), se respeta bd_automarco o el alias que corresponda,
        // pero se saca del archivo de lógica de negocio.
        $stmtDeudaErp = $pdo->prepare("
            SELECT 
                c.empresa AS codigo_empresa, 
                c.docto AS numero_factura, 
                CAST(c.saldo_cuota AS DECIMAL(12,0)) AS saldo_cuota
            FROM bd_automarco.tbl_cobranza c
            WHERE c.clirut = :clirut
              AND c.empresa != 'EMP07'
        ");
        
        $stmtDeudaErp->execute([':clirut' => $clirutVal]);
        $deudaErpRaw = $stmtDeudaErp->fetchAll(PDO::FETCH_ASSOC);

        $deudaErpMap = [];
        foreach ($deudaErpRaw as $row) {
            $key = trim($row['codigo_empresa']) . '_' . trim($row['numero_factura']);
            $deudaErpMap[$key][] = [
                'saldo_cuota' => (float)$row['saldo_cuota'],
                'usada' => false
            ];
        }

        return $deudaErpMap;
    }

    /**
     * Valida si el payload de facturas coincide con la realidad del ERP.
     * Lanza excepción si hay un descuadre.
     */
    public static function validarFacturasContraErp(array $facturasLista, array $deudaErpMap): void
    {
        foreach ($facturasLista as $fItem) {
            $codEmp = trim($fItem['codigo_empresa'] ?? 'EMP01');
            $numDoc = trim($fItem['numero_factura']);
            $montoCubierto = (float)($fItem['monto_cubierto'] ?? $fItem['saldo_cuota'] ?? 0);
            $saldoCuotaFrontend = (float)($fItem['saldo_cuota'] ?? 0);
            $key = $codEmp . '_' . $numDoc;

            if (!isset($deudaErpMap[$key])) {
                throw new InvalidArgumentException("Rechazado por Seguridad: La factura {$numDoc} ({$codEmp}) no presenta deuda activa en el ERP o no pertenece al cliente seleccionado.");
            }

            $cuotaMatch = false;
            foreach ($deudaErpMap[$key] as &$cuotaErp) {
                if (!$cuotaErp['usada'] && abs($cuotaErp['saldo_cuota'] - $saldoCuotaFrontend) < 0.01) {
                    $cuotaErp['usada'] = true;
                    $cuotaMatch = true;

                    if ($montoCubierto > ($cuotaErp['saldo_cuota'] + 0.01)) {
                        throw new InvalidArgumentException("Rechazado por Seguridad: El monto a cubrir (\$" . number_format($montoCubierto, 0, ',', '.') . ") supera el saldo adeudado real en el ERP para la factura {$numDoc} (\$" . number_format($cuotaErp['saldo_cuota'], 0, ',', '.') . ").");
                    }
                    break;
                }
            }

            if (!$cuotaMatch) {
                throw new InvalidArgumentException("Rechazado por Seguridad: Manipulación detectada. El saldo indicado (\$" . number_format($saldoCuotaFrontend, 0, ',', '.') . ") para la factura {$numDoc} no coincide con el registro del ERP o la cuota ya fue pagada.");
            }
        }
    }
}
