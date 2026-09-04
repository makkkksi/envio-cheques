<?php
require_once __DIR__ . '/../libs/fpdf/fpdf.php';

class PdfGenerator extends FPDF
{
    private $empresaNombre;

    public function __construct(string $empresaNombre)
    {
        parent::__construct('P', 'mm', 'A4');
        $this->empresaNombre = $empresaNombre;
    }

    private function toIso($str)
    {
        return iconv('UTF-8', 'windows-1252//TRANSLIT', $str ?? '');
    }

    // Cabecera de página
    function Header()
    {
        // Título principal
        $this->SetFont('Arial', 'B', 15);
        $this->SetFillColor(15, 23, 42); // Azul oscuro
        $this->SetTextColor(255, 255, 255);
        $this->Cell(0, 15, $this->toIso('  Resumen Diario de Cobranzas'), 0, 1, 'L', true);
        
        // Subtítulo
        $this->SetFont('Arial', '', 10);
        $this->SetTextColor(148, 163, 184); // Gris claro
        $this->SetY($this->GetY() - 10);
        $this->SetX($this->GetX() + 80);
        $this->Cell(0, 10, $this->toIso("Empresa: {$this->empresaNombre}  |  Fecha: " . date('d/m/Y')), 0, 1, 'R');
        $this->Ln(6);
    }

    // Pie de página
    function Footer()
    {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->SetTextColor(128, 128, 128);
        $this->Cell(0, 10, $this->toIso('Generado automáticamente por el Módulo de Cobranzas - Página ') . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    /**
     * Genera el archivo PDF localmente y devuelve la ruta absoluta del archivo generado.
     */
    public static function generateResumenDiario(string $empresaNombre, array $cobranzas, PDO $pdo): string
    {
        $pdf = new self($empresaNombre);
        $pdf->AliasNbPages();
        $pdf->AddPage();

        $pdf->SetFont('Arial', '', 10);
        $pdf->SetTextColor(71, 85, 105);
        $totalCob = count($cobranzas);
        $pdf->Cell(0, 8, $pdf->toIso("A continuación se detallan {$totalCob} cobranzas validadas por Tesorería:"), 0, 1, 'L');
        $pdf->Ln(2);

        $isFirst = true;
        foreach ($cobranzas as $cobranza) {
            // Check for page break
            if ($pdf->GetY() > 220) {
                $pdf->AddPage();
                $isFirst = true;
            } else if (!$isFirst) {
                // Separador visual grueso entre cobranzas
                $pdf->Ln(4);
                $pdf->SetDrawColor(148, 163, 184); // Gris más oscuro para el separador
                $pdf->SetLineWidth(0.6);
                $pdf->Line(10, $pdf->GetY(), 200, $pdf->GetY());
                $pdf->Ln(6);
            }
            $isFirst = false;

            // --- CABECERA COBRANZA ---
            $pdf->SetFillColor(241, 245, 249); // Gris muy claro
            $pdf->SetDrawColor(203, 213, 225);
            $pdf->SetLineWidth(0.3);

            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(37, 99, 235); // Azul
            $pdf->Cell(95, 6, $pdf->toIso('COBRANZA N° ' . $cobranza['id']), 'LT', 0, 'L', true);
            
            $pdf->SetTextColor(22, 101, 52); // Verde oscuro
            $pdf->Cell(95, 6, $pdf->toIso('EMPRESA: ' . strtoupper($empresaNombre)), 'TR', 1, 'R', true);

            $pdf->SetFont('Arial', 'B', 11);
            $pdf->SetTextColor(15, 23, 42);
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->Cell(0, 6, '', 'LR', 0, 'L', true); // Background and borders
            $pdf->SetXY($x + 2, $y);
            $pdf->Cell(120, 6, $pdf->toIso(substr($cobranza['razon_social_cliente'] ?? '', 0, 50)), 0, 0, 'L');
            
            $pdf->SetFont('Arial', '', 9);
            $pdf->SetTextColor(100, 116, 139);
            $pdf->Cell(65, 6, $pdf->toIso('RUT: ' . ($cobranza['rut_cliente'] ?? '')), 0, 1, 'R');
            
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            $pdf->Cell(0, 6, '', 'LBR', 0, 'L', true);
            $pdf->SetXY($x + 2, $y);
            $pdf->SetFont('Arial', '', 8);
            $pdf->Cell(0, 6, $pdf->toIso('Vendedor: ' . ($cobranza['vendedor_nombre'] ?? '')), 0, 1, 'L');
            $pdf->Ln(2);

            // --- FACTURAS ---
            $stmtFac = $pdo->prepare("SELECT numero_factura, cuota_label, monto_cubierto FROM cobranza_facturas WHERE cobranza_id = :cobranza_id ORDER BY CAST(numero_factura AS UNSIGNED) ASC, cuota_label ASC");
            $stmtFac->execute([':cobranza_id' => $cobranza['id']]);
            $facturas = $stmtFac->fetchAll(PDO::FETCH_ASSOC);

            // Agrupar facturas por número
            $facturasAgrupadas = [];
            foreach ($facturas as $fac) {
                $num = $fac['numero_factura'];
                if (!isset($facturasAgrupadas[$num])) {
                    $facturasAgrupadas[$num] = [];
                }
                $facturasAgrupadas[$num][] = $fac;
            }

            $pdf->SetFont('Arial', 'B', 8);
            $pdf->SetTextColor(71, 85, 105);
            $pdf->Cell(0, 5, $pdf->toIso('Facturas / Docs Abonados:'), 0, 1);
            
            if (!empty($facturasAgrupadas)) {
                foreach ($facturasAgrupadas as $numFac => $cuotas) {
                    $pdf->SetFont('Arial', 'B', 8);
                    $pdf->Cell(5);
                    $pdf->Cell(0, 5, $pdf->toIso("- Factura $numFac"), 0, 1);
                    
                    $pdf->SetFont('Arial', '', 8);
                    foreach ($cuotas as $fac) {
                        $mCub = '$' . number_format((float)$fac['monto_cubierto'], 0, ',', '.');
                        $lbl = $fac['cuota_label'] ? "Cuota " . $fac['cuota_label'] : "Única";
                        $textoFac = "  - $lbl - cubre: $mCub";
                        $pdf->Cell(10);
                        $pdf->Cell(0, 4, $pdf->toIso($textoFac), 0, 1);
                    }
                }
            } else {
                $pdf->SetFont('Arial', '', 8);
                $pdf->Cell(5);
                $pdf->Cell(0, 4, $pdf->toIso("- Factura " . ($cobranza['numero_factura'] ?? '')), 0, 1);
            }
            $pdf->Ln(2);

            // --- CHEQUES ---
            if (isset($cobranza['cheques_filtrados'])) {
                $cheques = array_map(function($chq) {
                    $chq['monto_cheque'] = $chq['monto'] ?? $chq['monto_cheque'] ?? 0;
                    return $chq;
                }, $cobranza['cheques_filtrados']);
            } else {
                $stmtChq = $pdo->prepare("SELECT numero_cheque, banco, monto AS monto_cheque, fecha_vencimiento, comentario FROM cheques WHERE cobranza_id = :cobranza_id AND (activo = 1 OR activo IS NULL)");
                $stmtChq->execute([':cobranza_id' => $cobranza['id']]);
                $cheques = $stmtChq->fetchAll(PDO::FETCH_ASSOC);
            }

            if ($cheques && count($cheques) > 0) {
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetFillColor(226, 232, 240); // Gris headers
                $pdf->SetTextColor(51, 65, 85);
                $pdf->Cell(50, 6, $pdf->toIso('Banco'), 1, 0, 'C', true);
                $pdf->Cell(50, 6, $pdf->toIso('N° Cheque'), 1, 0, 'C', true);
                $pdf->Cell(45, 6, $pdf->toIso('Vencimiento'), 1, 0, 'C', true);
                $pdf->Cell(45, 6, $pdf->toIso('Monto'), 1, 1, 'C', true);

                $pdf->SetFont('Arial', '', 8);
                $totalLote = 0;
                foreach ($cheques as $chq) {
                    $montoVal = (float)$chq['monto_cheque'];
                    $totalLote += $montoVal;
                    $montoFmt = '$' . number_format($montoVal, 0, ',', '.');
                    $fechaFmt = date('d/m/Y', strtotime($chq['fecha_vencimiento']));
                    
                    $pdf->Cell(50, 6, $pdf->toIso($chq['banco'] ?? ''), 1, 0, 'L');
                    $pdf->Cell(50, 6, $pdf->toIso($chq['numero_cheque'] ?? ''), 1, 0, 'C');
                    $pdf->Cell(45, 6, $fechaFmt, 1, 0, 'C');
                    $pdf->Cell(45, 6, $montoFmt, 1, 1, 'R');
                    
                    if (!empty($chq['comentario'])) {
                        $pdf->SetFont('Arial', 'I', 7);
                        $pdf->SetTextColor(100, 116, 139);
                        $pdf->Cell(190, 5, $pdf->toIso('  Nota: ' . $chq['comentario']), 'LRB', 1, 'L');
                        $pdf->SetFont('Arial', '', 8);
                        $pdf->SetTextColor(51, 65, 85);
                    }
                }
                $pdf->SetFont('Arial', 'B', 8);
                $pdf->SetFillColor(241, 245, 249);
                $pdf->Cell(145, 6, 'Total Cobranza:', 1, 0, 'R', true);
                $pdf->Cell(45, 6, '$' . number_format($totalLote, 0, ',', '.'), 1, 1, 'R', true);
            } else {
                $pdf->SetTextColor(220, 38, 38);
                $pdf->Cell(0, 5, 'No se encontraron cheques adjuntos en esta cobranza.', 0, 1);
            }
            $pdf->Ln(2);
        }

        $tmpPath = sys_get_temp_dir() . '/Resumen_' . preg_replace('/[^a-zA-Z0-9]/', '_', $empresaNombre) . '_' . time() . '.pdf';
        $pdf->Output('F', $tmpPath);
        return $tmpPath;
    }
}
