<?php
/**
 * Reglas de negocio compartidas del Módulo 3 de Rendiciones.
 */

require_once __DIR__ . '/../config/auth.php';

class RendicionesService
{
    public const TIPOS_DOCUMENTO = ['BOLETA_ELECTRONICA', 'FACTURA_ELECTRONICA', 'PEAJE', 'PASAJES', 'OTRO'];
    public const CATEGORIAS = ['BENCINA', 'COLACION', 'HOSPEDAJE', 'PEAJES', 'ESTACIONAMIENTO', 'CENA_CLIENTE', 'OTROS'];
    public const TIPOS_PRESUPUESTO = ['MENSUAL', 'GIRA'];
    public const DECISIONES_EXCESO = ['APROBADO', 'RECHAZADO'];

    private const TRANSICIONES = [
        'ENVIADA' => ['PENDIENTE_APROBACION_EXCESO', 'EN_REVISION_TESORERIA'],
        'PENDIENTE_APROBACION_EXCESO' => ['EN_REVISION_TESORERIA', 'RECHAZADA'],
        'EN_REVISION_TESORERIA' => ['DOCUMENTOS_FISICOS_RECIBIDOS', 'RECHAZADA'],
        'DOCUMENTOS_FISICOS_RECIBIDOS' => ['APROBADA', 'APROBADA_PARCIAL', 'RECHAZADA'],
        'APROBADA' => ['PAGADA'],
        'APROBADA_PARCIAL' => ['PAGADA'],
    ];

    public static function jsonResponse(bool $success, array $payload = [], int $status = 200): void
    {
        http_response_code($status);
        echo json_encode(array_merge(['success' => $success], $payload), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function requireMethod(string $method): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
            self::jsonResponse(false, ['message' => 'Método no permitido.'], 405);
        }
    }

    public static function readJsonBody(): array
    {
        $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
        if (strpos($contentType, 'application/json') === false) {
            return $_POST;
        }

        $rawBody = file_get_contents('php://input');
        $data = json_decode($rawBody ?: '{}', true);
        if (!is_array($data)) {
            throw new InvalidArgumentException('El cuerpo JSON no es válido.');
        }
        return $data;
    }

    public static function normalizeRut(string $rut): string
    {
        return strtoupper((string)preg_replace('/[^0-9Kk]/', '', trim($rut)));
    }

    public static function normalizeDocumentNumber(string $number): string
    {
        $normalized = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', trim($number)));
        $withoutLeadingZeros = ltrim($normalized, '0');
        return $normalized !== '' && $withoutLeadingZeros === '' ? '0' : $withoutLeadingZeros;
    }

    public static function normalizeTextKey(string $value): string
    {
        $value = strtoupper(trim($value));
        $converted = function_exists('iconv') ? iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
        $safeValue = $converted !== false ? $converted : $value;
        return trim((string)preg_replace('/[^A-Z0-9]+/', '-', $safeValue), '-');
    }

    public static function normalizeMoney($value): string
    {
        if (is_string($value)) {
            $value = str_replace(['$', ' '], '', trim($value));
            if (strpos($value, ',') !== false && strpos($value, '.') !== false) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } elseif (strpos($value, ',') !== false) {
                $value = str_replace(',', '.', $value);
            } elseif (substr_count($value, '.') > 1) {
                $value = str_replace('.', '', $value);
            }
        }
        if (!is_numeric($value) || (float)$value <= 0) {
            throw new InvalidArgumentException('El monto debe ser mayor que cero.');
        }
        return number_format((float)$value, 2, '.', '');
    }

    public static function validatePeriod(string $period): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
            throw new InvalidArgumentException('El periodo debe usar el formato YYYY-MM.');
        }
        return $period;
    }

    public static function createBudgetKey(
        int $empresaId,
        int $vendedorId,
        string $tipo,
        string $periodo,
        ?string $nombreGira,
        ?string $fechaInicio,
        ?string $fechaFin
    ): string {
        $tipo = strtoupper($tipo);
        if (!in_array($tipo, self::TIPOS_PRESUPUESTO, true)) {
            throw new InvalidArgumentException('Tipo de presupuesto no válido.');
        }
        self::validatePeriod($periodo);

        if ($tipo === 'MENSUAL') {
            return "MENSUAL|{$empresaId}|{$vendedorId}|{$periodo}";
        }

        $giraKey = self::normalizeTextKey((string)$nombreGira);
        if ($giraKey === '' || !$fechaInicio || !$fechaFin || $fechaInicio > $fechaFin) {
            throw new InvalidArgumentException('La gira requiere nombre y un rango de fechas válido.');
        }
        return "GIRA|{$empresaId}|{$vendedorId}|{$periodo}|{$giraKey}|{$fechaInicio}|{$fechaFin}";
    }

    public static function createDocumentHash(array $document, int $vendedorId, int $empresaId): string
    {
        $type = strtoupper(trim((string)($document['tipo_documento'] ?? '')));
        $category = strtoupper(trim((string)($document['categoria_gasto'] ?? '')));
        if (!in_array($type, self::TIPOS_DOCUMENTO, true) || !in_array($category, self::CATEGORIAS, true)) {
            throw new InvalidArgumentException('Tipo de documento o categoría no válida.');
        }

        if ($type === 'PEAJE' || $category === 'PEAJES') {
            $date = (string)($document['fecha_emision'] ?? '');
            $amount = self::normalizeMoney($document['monto'] ?? null);
            if (!self::isValidDate($date)) {
                throw new InvalidArgumentException('La fecha de emisión no es válida.');
            }
            return hash('sha256', "PEAJE|{$date}|{$amount}|{$vendedorId}|{$empresaId}");
        }

        $rut = self::normalizeRut((string)($document['rut_proveedor'] ?? ''));
        $number = self::normalizeDocumentNumber((string)($document['numero_documento'] ?? ''));
        if ($rut === '' || $number === '') {
            throw new InvalidArgumentException('RUT del proveedor y número de documento son obligatorios.');
        }
        return hash('sha256', "{$rut}|{$type}|{$number}");
    }

    public static function isValidDate(string $date): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    public static function validateDinnerFields(array $document): void
    {
        if (($document['categoria_gasto'] ?? '') !== 'CENA_CLIENTE') {
            return;
        }
        $required = [
            'cliente_invitado_nombre',
            'cliente_invitado_rut',
            'cliente_invitado_empresa',
            'cliente_invitado_cargo',
            'proposito_comercial',
        ];
        foreach ($required as $field) {
            if (trim((string)($document[$field] ?? '')) === '') {
                throw new InvalidArgumentException('Complete todos los datos tributarios de la Cena Cliente.');
            }
        }
    }

    public static function generateRenditionCode(): string
    {
        return 'RND-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }

    public static function canTransition(string $from, string $to): bool
    {
        return isset(self::TRANSICIONES[$from]) && in_array($to, self::TRANSICIONES[$from], true);
    }

    public static function assertTransition(string $from, string $to): void
    {
        if (!self::canTransition($from, $to)) {
            throw new DomainException("Transición no permitida: {$from} → {$to}.");
        }
    }

    public static function logHistory(PDO $pdo, array $entry): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO rendicion_historial_estados (
                rendicion_id, documento_id, usuario_id, actor_tipo, actor_nombre,
                actor_email, accion, estado_anterior, estado_nuevo, comentario,
                metadata_json, ip_origen, user_agent
             ) VALUES (
                :rendicion_id, :documento_id, :usuario_id, :actor_tipo, :actor_nombre,
                :actor_email, :accion, :estado_anterior, :estado_nuevo, :comentario,
                :metadata_json, :ip_origen, :user_agent
             )'
        );
        $metadata = $entry['metadata'] ?? null;
        $stmt->execute([
            ':rendicion_id' => (int)$entry['rendicion_id'],
            ':documento_id' => isset($entry['documento_id']) ? (int)$entry['documento_id'] : null,
            ':usuario_id' => isset($entry['usuario_id']) ? (int)$entry['usuario_id'] : null,
            ':actor_tipo' => $entry['actor_tipo'],
            ':actor_nombre' => $entry['actor_nombre'],
            ':actor_email' => $entry['actor_email'] ?? null,
            ':accion' => $entry['accion'],
            ':estado_anterior' => $entry['estado_anterior'] ?? null,
            ':estado_nuevo' => $entry['estado_nuevo'],
            ':comentario' => $entry['comentario'] ?? null,
            ':metadata_json' => $metadata !== null ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':ip_origen' => getClientIp(),
            ':user_agent' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
        ]);
    }

    public static function isDuplicateKey(Throwable $exception): bool
    {
        return $exception instanceof PDOException && (string)$exception->getCode() === '23000';
    }
}
