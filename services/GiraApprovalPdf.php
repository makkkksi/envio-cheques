<?php

require_once __DIR__ . '/../libs/fpdf/fpdf.php';

final class GiraApprovalPdf extends FPDF
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
        $this->Cell(0, 7, $this->encode('Comprobante de aprobación de gira comercial'), 0, 1);
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
        $this->Cell(145, 5, $this->encode('Documento generado desde la pista de auditoría de Giras y Rendiciones.'), 0, 0, 'L');
        $this->Cell(33, 5, $this->encode('Página ') . $this->PageNo() . '/{nb}', 0, 0, 'R');
    }

    public static function build(array $tour): string
    {
        $pdf = new self('P', 'mm', 'A4');
        $pdf->companyName = (string)($tour['empresa_nombre'] ?? 'Grupo Automarco');
        $pdf->SetMargins(16, 34, 16);
        $pdf->SetAutoPageBreak(true, 19);
        $pdf->AliasNbPages();
        $pdf->AddPage();
        $pdf->renderCertificate($tour);
        return $pdf->Output('S');
    }

    private function renderCertificate(array $tour): void
    {
        $approvedAt = self::formatDateTime($tour['aprobado_at'] ?? $tour['resuelto_at'] ?? null);
        $assigned = (float)($tour['monto_asignado'] ?? 0);
        $used = (float)($tour['monto_utilizado'] ?? 0);
        $available = max(0.0, $assigned - $used);

        $traceCode = strtoupper(substr(hash('sha256', implode('|', [
            (string)($tour['id'] ?? ''),
            (string)($tour['nombre_gira'] ?? ''),
            (string)($tour['aprobado_at'] ?? $tour['resuelto_at'] ?? ''),
            (string)($tour['aprobador_email_snapshot'] ?? ''),
            (string)$assigned,
        ])), 0, 20));

        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 13);
        $this->Cell(0, 7, $this->encode('Resolución de gira registrada'), 0, 1);
        $this->SetFont('Arial', '', 9);
        $this->SetTextColor(65, 65, 65);
        $this->MultiCell(0, 5, $this->encode('Este comprobante acredita la autorización gerencial de fondos extraordinarios para gira comercial. El cupo queda disponible en la cuenta del vendedor para rendir gastos conforme a la política interna.'), 0, 'L');
        $this->Ln(3);

        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(0.55);
        $this->SetTextColor(0, 0, 0);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(0, 12, $this->encode('APROBADA - GIRA COMERCIAL AUTORIZADA'), 1, 1, 'C');
        $this->SetLineWidth(0.2);
        $this->Ln(5);

        $this->sectionTitle('Identificación de la gira');
        $this->infoPair('Nombre de la gira', (string)($tour['nombre_gira'] ?? 'Gira Comercial'), 'Fecha de aprobación', $approvedAt);
        $seller = trim((string)($tour['vendedor_nombre'] ?? '')) . ' - ERP #' . (int)($tour['vendedor_id'] ?? 0);
        $this->infoPair('Vendedor', $seller, 'Empresa del cupo', (string)($tour['empresa_nombre'] ?? '-'));

        $dates = '-';
        if (!empty($tour['fecha_inicio']) && !empty($tour['fecha_fin'])) {
            $dates = self::formatDateOnly($tour['fecha_inicio']) . ' al ' . self::formatDateOnly($tour['fecha_fin']);
        }
        $this->infoPair('Vigencia / Fechas', $dates, 'Período contable', (string)($tour['periodo_mes'] ?? '-'));
        $this->Ln(4);

        $justification = trim((string)($tour['justificacion_gira'] ?? $tour['justificacion'] ?? ''));
        if ($justification !== '') {
            $this->sectionTitle('Objetivo y justificación comercial');
            $this->SetFont('Arial', '', 8.5);
            $this->SetTextColor(45, 45, 45);
            $this->MultiCell(0, 5, $this->encode($justification), 0, 'L');
            $this->Ln(3);
        }

        $decisionComment = trim((string)($tour['comentario_decision'] ?? ''));
        if ($decisionComment !== '') {
            $this->sectionTitle('Comentario del aprobador');
            $this->SetFont('Arial', 'I', 8.5);
            $this->SetTextColor(55, 55, 55);
            $this->MultiCell(0, 5, $this->encode('"' . $decisionComment . '"'), 0, 'L');
            $this->Ln(3);
        }

        $this->sectionTitle('Resumen financiero del fondo');
        $this->moneyRow([
            ['Cupo asignado', $assigned],
            ['Monto rendido', $used],
            ['Saldo disponible', $available],
        ]);
        $this->Ln(6);

        $this->ensureSpace(52);
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
        $this->Cell(0, 5, $this->encode((string)($tour['aprobador_nombre_snapshot'] ?? 'Responsable no informado')), 0, 1, 'C');
        $this->SetFont('Arial', '', 8.5);
        $this->SetTextColor(65, 65, 65);
        $this->Cell(0, 5, $this->encode((string)($tour['aprobador_cargo_snapshot'] ?? 'Cargo no informado')), 0, 1, 'C');
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

    private static function formatDateOnly(mixed $value): string
    {
        if (!$value) {
            return '-';
        }
        try {
            return (new DateTimeImmutable((string)$value))->format('d/m/Y');
        } catch (Throwable) {
            return (string)$value;
        }
    }
}
