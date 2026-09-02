<?php
declare(strict_types=1);

require_once __DIR__ . '/../libs/fpdf/fpdf.php';

/**
 * Generador de Planilla de Rendición de Gastos en formato tipo Excel (A4 Horizontal).
 * Genera una grilla cuadriculada con todas las boletas, causales SII, subtotales y
 * recuadros de firmas para Vendedor, Tesorería y Responsable (Magic Token).
 */
final class RendicionPlanillaPdf extends FPDF
{
    private array $rendition = [];
    private string $companyName = 'Grupo Automarco';

    public function __construct(array $rendition)
    {
        // Carta (Letter) Horizontal: 279.4mm x 215.9mm (Ancho útil: 259.4mm con margen 10mm)
        parent::__construct('L', 'mm', 'Letter');
        $this->rendition = $rendition;
        $this->companyName = (string)($rendition['empresa_nombre'] ?? 'Grupo Automarco');
        $this->SetMargins(10, 10, 10);
        $this->SetAutoPageBreak(true, 14);
        $this->AliasNbPages();
    }

    public function Header(): void
    {
        $this->SetFont('Arial', 'B', 11.5);
        $this->SetFillColor(30, 41, 59); // Slate-800
        $this->SetTextColor(255, 255, 255);
        $this->Cell(175, 8.5, $this->encode('  PLANILLA DE RENDICIÓN DE GASTOS Y VIÁTICOS'), 0, 0, 'L', true);
        
        $this->SetFont('Arial', 'B', 9.5);
        $this->SetFillColor(51, 65, 85); // Slate-700
        $code = (string)($this->rendition['codigo_rendicion'] ?? 'RND-SIN-CODIGO');
        $this->Cell(84, 8.5, $this->encode("FOLIO: {$code}  "), 0, 1, 'R', true);

        $this->Ln(2.5);
    }

    public function Footer(): void
    {
        $this->SetY(-10);
        $this->SetDrawColor(203, 213, 225);
        $this->Line(10, $this->GetY(), 269, $this->GetY());
        $this->SetY(-8.5);
        $this->SetFont('Arial', '', 7);
        $this->SetTextColor(100, 116, 139);
        $this->Cell(180, 4, $this->encode("Documento oficial generado automáticamente por el Sistema de Rendiciones | {$this->companyName}"), 0, 0, 'L');
        $this->Cell(79, 4, $this->encode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    /**
     * Construye y guarda la planilla en disco en la carpeta uploads.
     * Retorna la ruta relativa del archivo.
     */
    public static function buildAndSave(PDO $pdo, int $renditionId): string
    {
        $stmt = $pdo->prepare(
            'SELECT r.*, e.nombre AS empresa_nombre, p.nombre_gira, p.tipo_presupuesto,
                    u_verif.nombre AS verificado_por_nombre,
                    u_pago.nombre AS pagado_por_nombre
             FROM rendiciones_gastos r
             INNER JOIN empresas e ON e.id = r.empresa_id
             INNER JOIN presupuestos_vendedores p ON p.id = r.presupuesto_id
             LEFT JOIN usuarios u_verif ON u_verif.id = r.verificado_tesoreria_por
             LEFT JOIN usuarios u_pago ON u_pago.id = r.recibido_fisico_por
             WHERE r.id = :id AND r.activo = 1
             LIMIT 1'
        );
        $stmt->execute([':id' => $renditionId]);
        $rendition = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$rendition) {
            throw new RuntimeException("Rendición #{$renditionId} no encontrada para generar planilla PDF.");
        }

        $stmtDocs = $pdo->prepare(
            'SELECT * FROM rendicion_documentos
             WHERE rendicion_id = :rendicion_id AND activo = 1
             ORDER BY fecha_emision ASC, id ASC'
        );
        $stmtDocs->execute([':rendicion_id' => $renditionId]);
        $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

        $pdf = new self($rendition);
        $pdf->AddPage();
        $pdf->renderMetadata();
        $pdf->renderDocumentsTable($documents);
        $pdf->renderSummaryAndSignatures($documents);

        // Directorio de destino: uploads/{empresa_id}/{periodo}/rendiciones/{vendedor_id}/
        $period = (string)($rendition['periodo_mes'] ?? date('Y-m'));
        $empresaId = (int)$rendition['empresa_id'];
        $sellerId = (int)$rendition['vendedor_id'];
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', (string)$rendition['codigo_rendicion']);

        $relativeDir = "uploads/{$empresaId}/{$period}/rendiciones/{$sellerId}";
        $absoluteDir = __DIR__ . '/../' . $relativeDir;
        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        $fileName = "planilla_{$code}.pdf";
        $relativePath = "{$relativeDir}/{$fileName}";
        $absolutePath = "{$absoluteDir}/{$fileName}";

        $pdf->Output('F', $absolutePath);

        // Actualizar pdf_planilla_url en la base de datos
        $stmtUpdate = $pdo->prepare('UPDATE rendiciones_gastos SET pdf_planilla_url = :url WHERE id = :id');
        $stmtUpdate->execute([':url' => $relativePath, ':id' => $renditionId]);

        return $relativePath;
    }

    private function renderMetadata(): void
    {
        $r = $this->rendition;
        $this->SetDrawColor(203, 213, 225); // Slate-300
        $this->SetLineWidth(0.2);

        // Cuadro de metadata de 2 filas
        // Fila 1: Vendedor | Empresa | Período | Tipo de Fondo
        $seller = trim((string)($r['vendedor_nombre'] ?? '')) . " (ERP #{$r['vendedor_id']})";
        $company = (string)($r['empresa_nombre'] ?? '-');
        $period = (string)($r['periodo_mes'] ?? '-');
        $type = (string)($r['tipo_rendicion'] ?? 'MENSUAL');
        if ($type === 'GIRA' && !empty($r['nombre_gira'])) {
            $type .= ' - ' . trim((string)$r['nombre_gira']);
        }

        $y = $this->GetY();
        // Fila 1 (Total 259mm): Vendedor(80) + Empresa(60) + Período(34) + Tipo(85)
        $this->metaCell(10, $y, 80, 11, 'VENDEDOR / COLABORADOR', $seller);
        $this->metaCell(90, $y, 60, 11, 'EMPRESA / FILIAL', $company);
        $this->metaCell(150, $y, 34, 11, 'PERÍODO', $period);
        $this->metaCell(184, $y, 85, 11, 'TIPO DE ASIGNACIÓN', $type);

        $y += 11;
        // Fila 2 (Total 259mm): Asignado(54) + Rendido(52) + Exceso(48) + Aprobado(52) + Estado(53)
        $assigned = (float)($r['monto_presupuesto_asignado'] ?? 0);
        $totalRendido = (float)($r['monto_total_rendido'] ?? 0);
        $excess = (float)($r['monto_exceso'] ?? 0);
        $totalAprobado = (float)($r['monto_total_aprobado'] ?? $totalRendido);
        $estado = (string)($r['estado'] ?? '-');
        $decisionExceso = strtoupper(trim((string)($r['decision_exceso'] ?? '')));
        $excessNoReimb  = (float)($r['monto_exceso_no_reembolsable'] ?? 0);
        // Determinar si el exceso fue cubierto, rechazado o no aplica
        $excesoCubierto = $excess > 0 && $decisionExceso === 'APROBADO';
        $excesoRechazado = $excess > 0 && ($decisionExceso === 'RECHAZADO' || $excessNoReimb > 0);
        $excessLabel = $excess > 0
            ? self::money($excess) . ($excesoCubierto ? ' (AUTORIZADO)' : ($excesoRechazado ? ' (NO REEMBOLSABLE)' : ''))
            : '$0';

        $this->metaCell(10, $y, 54, 11, 'PRESUPUESTO ASIGNADO', self::money($assigned));
        $this->metaCell(64, $y, 52, 11, 'TOTAL RENDIDO DECLARADO', self::money($totalRendido));
        $this->metaCell(116, $y, 48, 11, 'EXCESO PRESUPUESTARIO', $excessLabel);
        $this->metaCell(164, $y, 52, 11, 'TOTAL APROBADO A PAGO', self::money($totalAprobado), true);
        $this->metaCell(216, $y, 53, 11, 'ESTADO ACTUAL', $estado);

        $this->SetY($y + 13.5);
    }

    private function metaCell(float $x, float $y, float $width, float $height, string $label, string $value, bool $highlight = false): void
    {
        $this->SetXY($x, $y);
        $this->SetFillColor($highlight ? 241 : 248, $highlight ? 245 : 250, $highlight ? 249 : 252);
        $this->Cell($width, $height, '', 1, 0, 'L', true);

        $this->SetXY($x + 2, $y + 1.5);
        $this->SetFont('Arial', 'B', 6.5);
        $this->SetTextColor(100, 116, 139);
        $this->Cell($width - 4, 3, $this->encode($label), 0, 1, 'L');

        $this->SetX($x + 2);
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor($highlight ? 15 : 30, $highlight ? 23 : 41, $highlight ? 42 : 59);
        $this->Cell($width - 4, 5, $this->encode(self::clip($value, 45)), 0, 1, 'L');
    }

    private function renderDocumentsTable(array $documents): void
    {
        $this->SetFont('Arial', 'B', 8.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(0, 5.5, $this->encode('DETALLE DE COMPROBANTES Y GASTOS INCURRIDOS'), 0, 1, 'L');

        // Columnas exactas ajustadas para Carta (Total 259mm):
        // N°: 7, Fecha: 18, Tipo Doc: 25, Categ: 24, N° Folio: 20, Proveedor: 49, RUT: 23, Detalle/SII: 53, Rendido: 20, Validado: 20
        $cols = [
            ['#', 7, 'C'],
            ['FECHA', 18, 'C'],
            ['TIPO DOC', 25, 'L'],
            ['CATEGORÍA', 24, 'L'],
            ['N° FOLIO', 20, 'C'],
            ['PROVEEDOR / RAZÓN SOCIAL', 49, 'L'],
            ['RUT PROV.', 23, 'C'],
            ['DETALLE / CAUSAL COMERCIAL (SII)', 53, 'L'],
            ['RENDIDO', 20, 'R'],
            ['VALIDADO', 20, 'R'],
        ];

        // Encabezado de la tabla estilo Excel
        $this->SetFillColor(226, 232, 240); // Slate-200
        $this->SetTextColor(30, 41, 59);
        $this->SetDrawColor(148, 163, 184); // Slate-400
        $this->SetFont('Arial', 'B', 7);

        foreach ($cols as $col) {
            $this->Cell($col[1], 5.5, $this->encode($col[0]), 1, 0, $col[2], true);
        }
        $this->Ln();

        // Filas de comprobantes
        $this->SetFont('Arial', '', 6.8);
        $this->SetTextColor(15, 23, 42);
        $index = 1;
        $totalRendidoSum = 0.0;
        $totalValidadoSum = 0.0;

        foreach ($documents as $doc) {
            if ($this->GetY() > 180) {
                $this->AddPage();
                // Reimprimir encabezado
                $this->SetFillColor(226, 232, 240);
                $this->SetFont('Arial', 'B', 7);
                foreach ($cols as $col) {
                    $this->Cell($col[1], 5.5, $this->encode($col[0]), 1, 0, $col[2], true);
                }
                $this->Ln();
                $this->SetFont('Arial', '', 6.8);
            }

            $montoRendido = (float)($doc['monto'] ?? 0);
            $montoValidado = $doc['monto_validado'] !== null ? (float)$doc['monto_validado'] : $montoRendido;
            if (($doc['estado_item'] ?? '') === 'RECHAZADO') {
                $montoValidado = 0.0;
            }
            $totalRendidoSum += $montoRendido;
            $totalValidadoSum += $montoValidado;

            // Formato de detalle (incluye comensales si es cena de clientes)
            $detalle = (string)($doc['descripcion'] ?? '');
            if (($doc['categoria_gasto'] ?? '') === 'CENA_CLIENTE') {
                $invitado = trim((string)($doc['cliente_invitado_nombre'] ?? ''));
                $empresaCli = trim((string)($doc['cliente_invitado_empresa'] ?? ''));
                $proposito = trim((string)($doc['proposito_comercial'] ?? ''));
                $siiExtra = [];
                if ($invitado !== '') $siiExtra[] = "Inv: {$invitado}" . ($empresaCli ? " ({$empresaCli})" : '');
                if ($proposito !== '') $siiExtra[] = "Motivo: {$proposito}";
                if (!empty($siiExtra)) {
                    $detalle = ($detalle ? $detalle . ' | ' : '') . implode(' - ', $siiExtra);
                }
            }
            if (!empty($doc['motivo_rechazo']) && ($doc['estado_item'] ?? '') === 'RECHAZADO') {
                $detalle .= " [RECHAZADO: {$doc['motivo_rechazo']}]";
            }

            // Alternar fondo tenue
            $isEven = ($index % 2 === 0);
            $this->SetFillColor($isEven ? 248 : 255, $isEven ? 250 : 255, $isEven ? 252 : 255);

            $this->Cell(7, 5.2, (string)$index, 1, 0, 'C', true);
            $this->Cell(18, 5.2, self::formatDate($doc['fecha_emision'] ?? null), 1, 0, 'C', true);
            $this->Cell(25, 5.2, $this->encode(self::clip(self::formatDocType($doc['tipo_documento'] ?? ''), 16)), 1, 0, 'L', true);
            $this->Cell(24, 5.2, $this->encode(self::clip(self::formatCategory($doc['categoria_gasto'] ?? ''), 15)), 1, 0, 'L', true);
            $this->Cell(20, 5.2, $this->encode(self::clip((string)($doc['numero_documento'] ?? '-'), 12)), 1, 0, 'C', true);
            $this->Cell(49, 5.2, $this->encode(self::clip((string)($doc['razon_social_proveedor'] ?? 'Proveedor s/i'), 30)), 1, 0, 'L', true);
            $this->Cell(23, 5.2, $this->encode(self::clip((string)($doc['rut_proveedor'] ?? '-'), 12)), 1, 0, 'C', true);
            $this->Cell(53, 5.2, $this->encode(self::clip($detalle, 40)), 1, 0, 'L', true);
            $this->Cell(20, 5.2, self::money($montoRendido), 1, 0, 'R', true);
            
            // Si el monto fue validado con diferencia, resaltar en negrita
            if (abs($montoValidado - $montoRendido) > 0.01) {
                $this->SetFont('Arial', 'B', 6.8);
                $this->SetTextColor(185, 28, 28); // Rojo
                $this->Cell(20, 5.2, self::money($montoValidado), 1, 0, 'R', true);
                $this->SetFont('Arial', '', 6.8);
                $this->SetTextColor(15, 23, 42);
            } else {
                $this->Cell(20, 5.2, self::money($montoValidado), 1, 0, 'R', true);
            }
            $this->Ln();

            $index++;
        }

        // Fila de Totales de la Tabla (Ancho 219mm + 20mm + 20mm = 259mm)
        $this->SetFillColor(226, 232, 240);
        $this->SetTextColor(30, 41, 59);
        $this->SetFont('Arial', 'B', 7.5);
        $this->Cell(219, 5.5, $this->encode('TOTAL GENERAL DE GASTOS: '), 1, 0, 'R', true);
        $this->Cell(20, 5.5, self::money($totalRendidoSum), 1, 0, 'R', true);
        $this->Cell(20, 5.5, self::money($totalValidadoSum), 1, 0, 'R', true);
        $this->Ln();

        // Si existe exceso (autorizado o no reembolsable), transparentar el cálculo en la grilla
        $r = $this->rendition;
        $excess = (float)($r['monto_exceso'] ?? 0);
        $excessNoReimb = (float)($r['monto_exceso_no_reembolsable'] ?? 0);
        $decisionExceso = strtoupper(trim((string)($r['decision_exceso'] ?? '')));
        $totalAprobadoPago = (float)($r['monto_total_aprobado'] ?? $totalValidadoSum);

        if ($excessNoReimb > 0 || ($excess > 0 && $decisionExceso === 'RECHAZADO')) {
            $montoDescuento = $excessNoReimb > 0 ? $excessNoReimb : $excess;
            // Fila de deducción por exceso no cubierto
            $this->SetFillColor(254, 242, 242); // Red-50
            $this->SetTextColor(185, 28, 28);   // Red-700
            $this->SetFont('Arial', 'B', 7);
            $this->Cell(219, 5.2, $this->encode('(-) EXCESO NO REEMBOLSABLE (TOPE DE PRESUPUESTO): '), 1, 0, 'R', true);
            $this->Cell(20, 5.2, '', 1, 0, 'C', true);
            $this->Cell(20, 5.2, '-' . self::money($montoDescuento), 1, 0, 'R', true);
            $this->Ln();

            // Fila de total líquido aprobado a pago
            $this->SetFillColor(238, 242, 255); // Indigo-50
            $this->SetTextColor(30, 58, 138);   // Blue-900
            $this->SetFont('Arial', 'B', 7.5);
            $this->Cell(219, 5.5, $this->encode('(=) TOTAL APROBADO A PAGO (LÍQUIDO A REEMBOLSAR): '), 1, 0, 'R', true);
            $this->Cell(20, 5.5, '', 1, 0, 'C', true);
            $this->Cell(20, 5.5, self::money($totalAprobadoPago), 1, 0, 'R', true);
            $this->Ln();
        } elseif ($excess > 0 && $decisionExceso === 'APROBADO') {
            // Fila de exceso autorizado por jefatura
            $this->SetFillColor(240, 253, 244); // Green-50
            $this->SetTextColor(22, 101, 52);   // Green-800
            $this->SetFont('Arial', 'B', 7);
            $this->Cell(219, 5.2, $this->encode('(+) EXCESO PRESUPUESTARIO (AUTORIZADO POR JEFATURA): '), 1, 0, 'R', true);
            $this->Cell(20, 5.2, '', 1, 0, 'C', true);
            $this->Cell(20, 5.2, '+' . self::money($excess), 1, 0, 'R', true);
            $this->Ln();

            // Fila de total aprobado a pago
            $this->SetFillColor(240, 253, 244);
            $this->SetTextColor(22, 101, 52);
            $this->SetFont('Arial', 'B', 7.5);
            $this->Cell(219, 5.5, $this->encode('(=) TOTAL APROBADO A PAGO (LÍQUIDO A REEMBOLSAR): '), 1, 0, 'R', true);
            $this->Cell(20, 5.5, '', 1, 0, 'C', true);
            $this->Cell(20, 5.5, self::money($totalAprobadoPago), 1, 0, 'R', true);
            $this->Ln();
        }

        $this->Ln(3.5);
    }

    private function renderSummaryAndSignatures(array $documents): void
    {
        if ($this->GetY() > 145) {
            $this->AddPage();
        }

        $y = $this->GetY();

        // 1. Resumen por Categorías (Lado Izquierdo, ancho 94mm)
        $this->SetXY(10, $y);
        $this->SetFont('Arial', 'B', 7.5);
        $this->SetTextColor(15, 23, 42);
        $this->Cell(94, 4.5, $this->encode('RESUMEN POR CATEGORÍA DE GASTO'), 0, 1, 'L');

        $byCat = [];
        foreach ($documents as $d) {
            $cat = (string)($d['categoria_gasto'] ?? 'OTROS');
            $val = $d['monto_validado'] !== null ? (float)$d['monto_validado'] : (float)($d['monto'] ?? 0);
            if (($d['estado_item'] ?? '') === 'RECHAZADO') $val = 0.0;
            if (!isset($byCat[$cat])) {
                $byCat[$cat] = ['count' => 0, 'total' => 0.0];
            }
            $byCat[$cat]['count']++;
            $byCat[$cat]['total'] += $val;
        }

        $this->SetFillColor(241, 245, 249);
        $this->SetFont('Arial', 'B', 6.5);
        $this->Cell(48, 4.2, $this->encode('Categoría'), 1, 0, 'L', true);
        $this->Cell(18, 4.2, $this->encode('Comprobantes'), 1, 0, 'C', true);
        $this->Cell(28, 4.2, $this->encode('Total Aprobado'), 1, 1, 'R', true);

        $this->SetFont('Arial', '', 6.5);
        foreach ($byCat as $catName => $catData) {
            $this->Cell(48, 3.8, $this->encode(self::formatCategory($catName)), 1, 0, 'L');
            $this->Cell(18, 3.8, (string)$catData['count'], 1, 0, 'C');
            $this->Cell(28, 3.8, self::money($catData['total']), 1, 1, 'R');
        }

        // 2. Cuadro de Trazabilidad y Firmas Digitales (Lado Derecho, ancho 155mm, de X=114 a X=269)
        $this->SetXY(114, $y);
        $this->SetFont('Arial', 'B', 7.5);
        $this->Cell(155, 4.5, $this->encode('FIRMAS Y CERTIFICACIÓN DE AUDITORÍA'), 0, 1, 'L');

        $boxY = $this->GetY();
        $boxW = 49;
        $boxH = 27;

        // Firma 1: Rendidor / Vendedor (X=114)
        $this->SetXY(114, $boxY);
        $this->SetFillColor(250, 250, 250);
        $this->Cell($boxW, $boxH, '', 1, 0, 'L', true);
        $this->SetXY(114 + 2, $boxY + 2);
        $this->SetFont('Arial', 'B', 6.5);
        $this->Cell($boxW - 4, 3.5, $this->encode('1. RENDIDOR / VENDEDOR'), 0, 1, 'C');
        $this->SetXY(114 + 2, $boxY + 6);
        $this->SetFont('Arial', '', 6.5);
        $this->Cell($boxW - 4, 3.5, $this->encode(self::clip((string)($this->rendition['vendedor_nombre'] ?? 'Vendedor'), 25)), 0, 1, 'C');
        $this->SetXY(114 + 2, $boxY + 15);
        $this->SetFont('Arial', 'I', 5.8);
        $this->SetTextColor(100, 116, 139);
        $enviadaAt = self::formatDateTime($this->rendition['enviada_at'] ?? $this->rendition['created_at'] ?? null);
        $this->Cell($boxW - 4, 3, $this->encode("Enviado: {$enviadaAt}"), 0, 1, 'C');
        $this->SetXY(114 + 2, $boxY + 19);
        $this->Cell($boxW - 4, 3, $this->encode('Declaración Jurada Digital'), 0, 1, 'C');

        // Firma 2: Tesorería (Verificación Documental) (X=167)
        $this->SetTextColor(15, 23, 42);
        $this->SetXY(167, $boxY);
        $this->Cell($boxW, $boxH, '', 1, 0, 'L', true);
        $this->SetXY(167 + 2, $boxY + 2);
        $this->SetFont('Arial', 'B', 6.5);
        $this->Cell($boxW - 4, 3.5, $this->encode('2. VERIFICACIÓN TESORERÍA'), 0, 1, 'C');
        $this->SetXY(167 + 2, $boxY + 6);
        $this->SetFont('Arial', '', 6.5);
        $verifPor = !empty($this->rendition['verificado_por_nombre']) ? $this->rendition['verificado_por_nombre'] : 'Tesorería Central';
        $this->Cell($boxW - 4, 3.5, $this->encode(self::clip((string)$verifPor, 25)), 0, 1, 'C');
        $this->SetXY(167 + 2, $boxY + 15);
        $this->SetFont('Arial', 'I', 5.8);
        $this->SetTextColor(100, 116, 139);
        $verifAt = self::formatDateTime($this->rendition['verificado_tesoreria_at'] ?? null);
        $this->Cell($boxW - 4, 3, $this->encode("Verificado: {$verifAt}"), 0, 1, 'C');
        $this->SetXY(167 + 2, $boxY + 19);
        $this->Cell($boxW - 4, 3, $this->encode('Boletas cotejadas vs fotos'), 0, 1, 'C');

        // Firma 3: Responsable / Gerencia (Magic Token) (X=220)
        $this->SetTextColor(15, 23, 42);
        $this->SetXY(220, $boxY);
        $this->Cell($boxW, $boxH, '', 1, 0, 'L', true);
        $this->SetXY(220 + 2, $boxY + 2);
        $this->SetFont('Arial', 'B', 6.5);
        $this->Cell($boxW - 4, 3.5, $this->encode('3. APROBACIÓN GERENCIAL'), 0, 1, 'C');
        $this->SetXY(220 + 2, $boxY + 6);
        $this->SetFont('Arial', '', 6.5);
        $aprobador = !empty($this->rendition['aprobador_nombre_snapshot']) ? $this->rendition['aprobador_nombre_snapshot'] : 'Responsable Autorizado';
        $this->Cell($boxW - 4, 3.5, $this->encode(self::clip((string)$aprobador, 25)), 0, 1, 'C');
        $this->SetXY(220 + 2, $boxY + 10);
        $cargo = (string)($this->rendition['aprobador_cargo_snapshot'] ?? 'Jefatura');
        $this->Cell($boxW - 4, 3, $this->encode(self::clip($cargo, 26)), 0, 1, 'C');
        $this->SetXY(220 + 2, $boxY + 15);
        $this->SetFont('Arial', 'I', 5.8);
        $this->SetTextColor(100, 116, 139);
        $aprobAt = self::formatDateTime($this->rendition['aprobado_exceso_at'] ?? null);
        $this->Cell($boxW - 4, 3, $this->encode("Aprobado: {$aprobAt}"), 0, 1, 'C');
        $this->SetXY(220 + 2, $boxY + 19);
        $this->Cell($boxW - 4, 3, $this->encode('Magic Token Digital OK'), 0, 1, 'C');
    }

    private function encode(string $value): string
    {
        $converted = iconv('UTF-8', 'windows-1252//TRANSLIT//IGNORE', $value);
        return $converted === false ? $value : $converted;
    }

    private static function money(float $amount): string
    {
        return '$' . number_format($amount, 0, ',', '.');
    }

    private static function formatDate(mixed $value): string
    {
        if (!$value) return '-';
        try {
            return (new DateTimeImmutable((string)$value))->format('d/m/Y');
        } catch (Throwable) {
            return (string)$value;
        }
    }

    private static function formatDateTime(mixed $value): string
    {
        if (!$value) return '-';
        try {
            return (new DateTimeImmutable((string)$value))->format('d/m/Y H:i');
        } catch (Throwable) {
            return (string)$value;
        }
    }

    private static function clip(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '...' : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max - 3) . '...' : $value;
    }

    private static function formatDocType(string $type): string
    {
        return match ($type) {
            'BOLETA_ELECTRONICA' => 'Boleta Electrónica',
            'FACTURA_ELECTRONICA' => 'Factura Electrónica',
            'PEAJE' => 'Comprobante Peaje',
            'PASAJES' => 'Pasajes / Traslados',
            default => 'Otro Comprobante',
        };
    }

    private static function formatCategory(string $cat): string
    {
        return match ($cat) {
            'BENCINA' => 'Bencina / Combustible',
            'COLACION' => 'Colación / Alimentación',
            'HOSPEDAJE' => 'Hospedaje / Alojamiento',
            'PEAJES' => 'Peajes y Tags',
            'ESTACIONAMIENTO' => 'Estacionamiento',
            'CENA_CLIENTE' => 'Cena Cliente (SII)',
            default => 'Otros Gastos',
        };
    }
}
