<?php

require_once __DIR__ . '/../libs/fpdf/fpdf.php';

final class RendicionApprovalPdf extends FPDF
{
    private string $companyName = 'Grupo Automarco';

    public function Header(): void
    {
        $this->SetXY(16, 10);
        $this->SetFont('Arial', 'B', 8);
        $this->SetTextColor(55, 55, 55);
        $this->Cell(0, 4, $this->encode('GRUPO AUTOMARCO - GESTIÓN FINANCIERA'), 0, 1);
        $this->SetX(16);
        $this->SetFont('Arial', 'B', 15);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 7, $this->encode('Comprobante de aprobación de exceso'), 0, 1);
        $this->SetDrawColor(45, 45, 45);
        $this->SetLineWidth(0.35);
        $this->Line(16, 23, 194, 23);
        $this->Ln(10);
    }

    public function Footer(): void
    {
        $this->SetY(-13);
        $this->SetDrawColor(155, 155, 155);
        $this->Line(16, $this->GetY(), 194, $this->GetY());
        $this->SetY(-10);
        $this->SetFont('Arial', '', 7.5);
        $this->SetTextColor(90, 90, 90);
        $this->Cell(145, 5, $this->encode('Documento generado desde la pista de auditoría de Rendiciones.'), 0, 0, 'L');
        $this->Cell(33, 5, $this->encode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    public static function build(array $rendition, array $documents): string
    {
        $pdf = new self('P', 'mm', 'A4');
        $pdf->companyName = (string)($rendition['empresa_nombre'] ?? 'Grupo Automarco');
        $pdf->SetMargins(16, 34, 16);
        $pdf->SetAutoPageBreak(true, 19);
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->renderCertificate($rendition, $documents);
        return $pdf->Output('S');
    }

    private function renderCertificate(array $rendition, array $documents): void
    {
        $approvedAt = self::formatDateTime($rendition['aprobado_exceso_at'] ?? null);
        $assigned = (float)($rendition['monto_presupuesto_asignado'] ?? 0);
        $availableBefore = (float)($rendition['saldo_disponible_al_enviar'] ?? 0);
        $previouslyCommitted = max(0, $assigned - $availableBefore);
        $traceCode = strtoupper(substr(hash('sha256', implode('|', [
            (string)($rendition['id'] ?? ''),
            (string)($rendition['codigo_rendicion'] ?? ''),
            (string)($rendition['aprobado_exceso_at'] ?? ''),
            (string)($rendition['aprobador_email_snapshot'] ?? ''),
        ])), 0, 20));

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 7, $this->encode('Resolución registrada'), 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(65, 65, 65);
        $this->MultiCell(0, 5, $this->encode('Este comprobante acredita la aprobación gerencial del exceso presupuestario. La rendición continúa su revisión operativa en Tesorería; no acredita pago ni aprobación final.'), 0, 'L');
        $this->Ln(3);

        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.55);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 12, $this->encode('APROBADO - EXCESO PRESUPUESTARIO'), 1, 1, 'C');
        $this->SetLineWidth(0.2);
        $this->Ln(5);

        $this->sectionTitle('Identificación de la rendición');
        $this->infoPair('Código', (string)($rendition['codigo_rendicion'] ?? '-'), 'Fecha de aprobación', $approvedAt);
        $seller = trim((string)($rendition['vendedor_nombre'] ?? '')) . ' - ERP #' . (int)($rendition['vendedor_id'] ?? 0);
        $this->infoPair('Vendedor', $seller, 'Empresa', (string)($rendition['empresa_nombre'] ?? '-'));
        $type = (string)($rendition['tipo_rendicion'] ?? '-');
        if ($type === 'GIRA' && trim((string)($rendition['nombre_gira'] ?? '')) !== '') {
            $type .= ' - ' . trim((string)$rendition['nombre_gira']);
        }
        $this->infoPair('Tipo', $type, 'Período', (string)($rendition['periodo_mes'] ?? '-'));
        $this->Ln(5);

        $this->sectionTitle('Resumen financiero');
        $this->moneyRow([
            ['Asignado', $assigned],
            ['Rendido previamente', $previouslyCommitted],
            ['Saldo anterior', $availableBefore],
        ]);
        $this->moneyRow([
            ['Total rendido', (float)($rendition['monto_total_rendido'] ?? 0)],
            ['Exceso aprobado', (float)($rendition['monto_exceso'] ?? 0)],
            ['Documentos', count($documents), false],
        ]);
        $this->Ln(5);

        $this->sectionTitle('Comprobantes incluidos');
        if (!$documents) {
            $this->SetFont('Arial', '', 9);
            $this->SetTextColor(85, 85, 85);
            $this->Cell(0, 7, $this->encode('No hay comprobantes activos asociados.'), 0, 1);
        }
        foreach ($documents as $index => $document) {
            $this->ensureSpace(($document['categoria_gasto'] ?? '') === 'CENA_CLIENTE' ? 28 : 18);
            $this->SetDrawColor(170, 170, 170);
            $y = $this->GetY();
            $this->Rect(16, $y, 178, ($document['categoria_gasto'] ?? '') === 'CENA_CLIENTE' ? 26 : 16, 'D');
            $this->SetXY(19, $y + 3);
            $this->SetFont('Arial', 'B', 9);
            $provider = trim((string)($document['razon_social_proveedor'] ?? '')) ?: str_replace('_', ' ', (string)($document['categoria_gasto'] ?? 'Comprobante'));
            $this->Cell(125, 5, $this->encode(($index + 1) . '. ' . self::clip($provider, 54)), 0, 0);
            $this->SetFont('Arial', 'B', 9.5);
            $this->Cell(47, 5, $this->encode(self::money((float)($document['monto'] ?? 0))), 0, 1, 'R');
            $this->SetX(19);
            $this->SetFont('Arial', '', 7.5);
            $this->SetTextColor(65, 65, 65);
            $meta = implode(' | ', array_filter([
                str_replace('_', ' ', (string)($document['tipo_documento'] ?? '')),
                (string)($document['fecha_emision'] ?? ''),
                !empty($document['rut_proveedor']) ? 'RUT ' . $document['rut_proveedor'] : null,
                !empty($document['numero_documento']) ? 'Folio ' . $document['numero_documento'] : null,
            ]));
            $this->Cell(172, 4, $this->encode(self::clip($meta, 104)), 0, 1);
            if (($document['categoria_gasto'] ?? '') === 'CENA_CLIENTE') {
                $this->SetX(19);
                $guest = 'SII: ' . trim((string)($document['cliente_invitado_nombre'] ?? '-'))
                    . ' | ' . trim((string)($document['cliente_invitado_empresa'] ?? '-'))
                    . ' - ' . trim((string)($document['cliente_invitado_cargo'] ?? '-'));
                $this->Cell(172, 4, $this->encode(self::clip($guest, 104)), 0, 1);
                $this->SetX(19);
                $this->Cell(172, 4, $this->encode('Propósito: ' . self::clip((string)($document['proposito_comercial'] ?? '-'), 92)), 0, 1);
            }
            $this->SetY($y + (($document['categoria_gasto'] ?? '') === 'CENA_CLIENTE' ? 29 : 19));
            $this->SetTextColor(0, 0, 0);
        }

        $this->ensureSpace(52);
        $this->Ln(3);
        $this->sectionTitle('Firma de aprobación');
        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(65, 65, 65);
        $this->MultiCell(0, 5, $this->encode('La decisión fue registrada electrónicamente mediante un enlace personal, temporal y de un solo uso.'), 0, 'L');
        $this->Ln(11);
        $this->SetDrawColor(0, 0, 0);
        $this->Line(58, $this->GetY(), 152, $this->GetY());
        $this->Ln(3);
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 5, $this->encode((string)($rendition['aprobador_nombre_snapshot'] ?? 'Responsable no informado')), 0, 1, 'C');
        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(65, 65, 65);
        $this->Cell(0, 5, $this->encode((string)($rendition['aprobador_cargo_snapshot'] ?? 'Cargo no informado')), 0, 1, 'C');
        $this->Cell(0, 5, $this->encode('Aprobación electrónica registrada el ' . $approvedAt), 0, 1, 'C');
        $this->Ln(5);
        $this->SetFont('Arial', '', 7.5);
        $this->Cell(0, 4, $this->encode('Código de verificación: ' . $traceCode), 0, 1, 'C');
    }

    private function sectionTitle(string $title): void
    {
        $this->SetFont('Arial', 'B', 10);
        $this->SetTextColor(0, 0, 0);
        $this->Cell(0, 7, $this->encode($title), 0, 1);
        $this->SetDrawColor(155, 155, 155);
        $this->Line(16, $this->GetY(), 194, $this->GetY());
        $this->Ln(2);
    }

    private function infoPair(string $labelA, string $valueA, string $labelB, string $valueB): void
    {
        $x = $this->GetX();
        $y = $this->GetY();
        $this->infoCell($x, $y, 87, $labelA, $valueA);
        $this->infoCell($x + 91, $y, 87, $labelB, $valueB);
        $this->SetY($y + 13);
    }

    private function infoCell(float $x, float $y, float $width, string $label, string $value): void
    {
        $this->SetXY($x, $y);
        $this->SetFont('Arial', 'B', 7);
        $this->SetTextColor(90, 90, 90);
        $this->Cell($width, 4, $this->encode(strtoupper($label)), 0, 1);
        $this->SetX($x);
        $this->SetFont('Arial', 'B', 9);
        $this->SetTextColor(0, 0, 0);
        $this->Cell($width, 6, $this->encode(self::clip($value, 48)), 0, 1);
    }

    private function moneyRow(array $items): void
    {
        $width = 178 / count($items);
        $y = $this->GetY();
        foreach ($items as $index => $item) {
            $x = 16 + ($width * $index);
            $this->SetXY($x, $y);
            $this->SetDrawColor(170, 170, 170);
            $this->Cell($width, 15, '', 1, 0, 'L');
            $this->SetXY($x + 3, $y + 3);
            $this->SetFont('Arial', 'B', 7);
            $this->SetTextColor(90, 90, 90);
            $this->Cell($width - 6, 4, $this->encode(strtoupper((string)$item[0])), 0, 1);
            $this->SetX($x + 3);
            $this->SetFont('Arial', 'B', 10);
            $this->SetTextColor(0, 0, 0);
            $formatted = ($item[2] ?? true) ? self::money((float)$item[1]) : (string)$item[1];
            $this->Cell($width - 6, 5, $this->encode($formatted), 0, 1);
        }
        $this->SetY($y + 17);
    }

    private function ensureSpace(float $height): void
    {
        if ($this->GetY() + $height > 278) {
            $this->AddPage();
        }
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

    private static function clip(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($value) > $max ? mb_substr($value, 0, $max - 1) . '…' : $value;
        }
        return strlen($value) > $max ? substr($value, 0, $max - 3) . '...' : $value;
    }

    private static function formatDateTime(mixed $value): string
    {
        if (!$value) {
            return '-';
        }
        try {
            return (new DateTimeImmutable((string)$value))->format('d/m/Y H:i');
        } catch (Throwable) {
            return (string)$value;
        }
    }
}
