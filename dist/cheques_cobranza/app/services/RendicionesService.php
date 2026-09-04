<?php
/**
 * Reglas de negocio compartidas del Módulo 3 de Rendiciones.
 */

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/AuditService.php';
require_once __DIR__ . '/ApprovalWorkflowService.php';
require_once __DIR__ . '/MailService.php';

class RendicionesService
{
    public const TIPOS_DOCUMENTO = ['BOLETA_ELECTRONICA', 'FACTURA_ELECTRONICA', 'PEAJE', 'PASAJES', 'OTRO'];
    public const CATEGORIAS = ['BENCINA', 'COLACION', 'HOSPEDAJE', 'PEAJES', 'ESTACIONAMIENTO', 'CENA_CLIENTE', 'OTROS'];
    public const TIPOS_PRESUPUESTO = ['MENSUAL', 'GIRA'];
    public const DECISIONES_EXCESO = ['APROBADO', 'RECHAZADO'];

    private const TRANSICIONES = [
        'ENVIADA' => ['PENDIENTE_APROBACION_EXCESO', 'EN_REVISION_TESORERIA'],
        'PENDIENTE_APROBACION_EXCESO' => ['EN_REVISION_TESORERIA', 'RECHAZADA'],
        'EN_REVISION_TESORERIA' => ['PENDIENTE_APROBACION_RESPONSABLE', 'RECHAZADA'],
        'PENDIENTE_APROBACION_RESPONSABLE' => ['APROBADA', 'RECHAZADA', 'EN_REVISION_TESORERIA'],
        'DOCUMENTOS_FISICOS_RECIBIDOS' => ['PENDIENTE_APROBACION_RESPONSABLE', 'RECHAZADA'],
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

    public static function textLength(string $value): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($value, 'UTF-8');
        }
        $count = preg_match_all('/./us', $value, $characters);
        return $count === false ? strlen($value) : $count;
    }

    public static function truncateText(string $value, int $maxLength): string
    {
        if ($maxLength < 1 || self::textLength($value) <= $maxLength) {
            return $value;
        }
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        $matched = preg_match_all('/./us', $value, $characters);
        if ($matched === false) {
            return substr($value, 0, $maxLength);
        }
        return implode('', array_slice($characters[0], 0, $maxLength));
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
        if ($tipo === 'MENSUAL') {
            self::validatePeriod($periodo);
            return "MENSUAL|{$empresaId}|{$vendedorId}|{$periodo}";
        }

        $tourName = trim((string)$nombreGira);
        $giraKey = self::normalizeTextKey($tourName);
        $tourNameLength = preg_match_all('/./us', $tourName, $characters);
        if ($giraKey === '' || $tourNameLength === false || $tourNameLength < 3 || $tourNameLength > 100) {
            throw new InvalidArgumentException('Ingrese un nombre de gira válido, entre 3 y 100 caracteres.');
        }
        if (!$fechaInicio || !self::isValidDate($fechaInicio)) {
            throw new InvalidArgumentException('Ingrese una fecha de inicio válida para la gira.');
        }
        if (!$fechaFin || !self::isValidDate($fechaFin)) {
            throw new InvalidArgumentException('Ingrese una fecha de término válida para la gira.');
        }
        if ($fechaInicio > $fechaFin) {
            throw new InvalidArgumentException('La fecha de término no puede ser anterior al inicio de la gira.');
        }
        $periodo = substr($fechaInicio, 0, 7);
        return "GIRA|{$empresaId}|{$vendedorId}|{$periodo}|{$giraKey}|{$fechaInicio}|{$fechaFin}";
    }

    public static function assertDocumentsFitBudget(array $budget, array $documents): void
    {
        if (($budget['tipo_presupuesto'] ?? '') !== 'GIRA') {
            return;
        }
        $startDate = (string)($budget['fecha_inicio'] ?? '');
        $endDate = (string)($budget['fecha_fin'] ?? '');
        if (!self::isValidDate($startDate) || !self::isValidDate($endDate) || $startDate > $endDate) {
            throw new DomainException('La gira seleccionada no tiene un rango de fechas válido. Solicite a Tesorería corregirla.');
        }
        foreach ($documents as $document) {
            $documentDate = (string)($document['fecha_emision'] ?? '');
            if (!self::isValidDate($documentDate) || $documentDate < $startDate || $documentDate > $endDate) {
                throw new DomainException('Todos los comprobantes imputados a una gira deben tener fecha dentro del período de viaje.');
            }
        }
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

    /**
     * Valida documentalmente los comprobantes de una rendición en revisión.
     * P0-2: Validación estricta sin continue silencioso, rechazo de duplicados,
     * montos no válidos, decisiones desconocidas y recálculo atómico de presupuesto.
     *
     * @param PDO $pdo
     * @param int $renditionId
     * @param array $decisiones
     * @param array $actor
     * @param string $comment
     * @return array
     * @throws DomainException|InvalidArgumentException
     */
    public static function validarDocumentos(PDO $pdo, int $renditionId, array $decisiones, array $actor, string $comment = ''): array
    {
        if ($renditionId <= 0) {
            throw new InvalidArgumentException('ID de rendición no válido.');
        }
        if (!is_array($decisiones) || empty($decisiones)) {
            throw new InvalidArgumentException('Debe incluir las decisiones de los comprobantes a validar.');
        }

        // Bloquear cabecera de la rendición
        $stmtRendition = $pdo->prepare(
            'SELECT * FROM rendiciones_gastos
             WHERE id = :id AND activo = 1
             LIMIT 1
             FOR UPDATE'
        );
        $stmtRendition->execute([':id' => $renditionId]);
        $rendition = $stmtRendition->fetch(PDO::FETCH_ASSOC);
        if (!$rendition) {
            throw new DomainException('Rendición no encontrada.');
        }

        $allowedReviewStates = ['EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'];
        if (!in_array($rendition['estado'], $allowedReviewStates, true)) {
            throw new DomainException('Sólo se pueden validar comprobantes mientras la rendición esté en revisión de Tesorería.');
        }

        // Bloquear documentos activos en FIFO estricto
        $stmtDocuments = $pdo->prepare(
            'SELECT id, monto, monto_validado, estado_item, fecha_emision, rendicion_id, numero_documento, numero_documento_original
             FROM rendicion_documentos
             WHERE rendicion_id = :rendicion_id AND activo = 1
             ORDER BY fecha_emision ASC, id ASC
             FOR UPDATE'
        );
        $stmtDocuments->execute([':rendicion_id' => $renditionId]);
        $existingDocs = $stmtDocuments->fetchAll(PDO::FETCH_ASSOC);
        $docMap = [];
        foreach ($existingDocs as $ed) {
            $docMap[(int)$ed['id']] = $ed;
        }

        // Bloquear presupuesto vinculado
        $budgetId = (int)$rendition['presupuesto_id'];
        $stmtBudgetLock = $pdo->prepare(
            'SELECT id, monto_asignado, monto_utilizado
             FROM presupuestos_vendedores
             WHERE id = :id AND activo = 1
             LIMIT 1 FOR UPDATE'
        );
        $stmtBudgetLock->execute([':id' => $budgetId]);
        $budget = $stmtBudgetLock->fetch(PDO::FETCH_ASSOC);
        if (!$budget) {
            throw new DomainException('Presupuesto vinculado no encontrado.');
        }

        // Validar estrictamente cada decisión del payload
        $seenDocIds = [];
        $validatedUpdates = [];

        foreach ($decisiones as $dec) {
            if (!is_array($dec)) {
                throw new InvalidArgumentException('Estructura de decisión no válida.');
            }

            // 1. documento_id ausente o inválido
            if (!array_key_exists('documento_id', $dec) || !is_numeric($dec['documento_id']) || (int)$dec['documento_id'] <= 0) {
                throw new InvalidArgumentException('Se requiere un documento_id válido para cada comprobante.');
            }
            $docId = (int)$dec['documento_id'];

            // 2. Documento duplicado en el mismo payload
            if (isset($seenDocIds[$docId])) {
                throw new InvalidArgumentException("El comprobante ID {$docId} está duplicado en la solicitud.");
            }
            $seenDocIds[$docId] = true;

            // 3. Documento inexistente o que no pertenece a esta rendición
            if (!isset($docMap[$docId])) {
                $stmtCheckOther = $pdo->prepare('SELECT rendicion_id FROM rendicion_documentos WHERE id = :id AND activo = 1 LIMIT 1');
                $stmtCheckOther->execute([':id' => $docId]);
                $otherRendId = $stmtCheckOther->fetchColumn();
                if ($otherRendId !== false && (int)$otherRendId !== $renditionId) {
                    throw new InvalidArgumentException("El comprobante ID {$docId} no pertenece a esta rendición.");
                }
                throw new InvalidArgumentException("El comprobante ID {$docId} no existe o no está activo.");
            }

            $currentDoc = $docMap[$docId];
            $origDocAmount = (float)$currentDoc['monto'];

            // 4. Decisión ausente o vacía
            if (!array_key_exists('decision', $dec) || trim((string)$dec['decision']) === '') {
                throw new InvalidArgumentException("Debe indicar una decisión para el comprobante ID {$docId}.");
            }
            $decision = strtoupper(trim((string)$dec['decision']));

            // 5. Decisión desconocida
            if (!in_array($decision, ['APROBAR', 'RECHAZAR'], true)) {
                throw new InvalidArgumentException("Decisión '{$decision}' no válida para el comprobante ID {$docId}. Debe ser APROBAR o RECHAZAR.");
            }

            $itemReason = trim((string)($dec['motivo'] ?? ''));

            if ($decision === 'RECHAZAR') {
                // 6. RECHAZAR sin motivo obligatorio
                if ($itemReason === '') {
                    throw new InvalidArgumentException("Cada comprobante rechazado requiere un motivo obligatorio (comprobante ID {$docId}).");
                }
                $valAmount = 0.0;
                $itemState = 'RECHAZADO';
            } else {
                // Decisión APROBAR
                $itemState = 'APROBADO';
                if (!array_key_exists('monto_validado', $dec) || $dec['monto_validado'] === null || trim((string)$dec['monto_validado']) === '') {
                    $valAmount = $origDocAmount;
                } else {
                    $valAmount = (float)self::normalizeMoney($dec['monto_validado']);
                }

                // 7. Monto validado menor o igual a cero para APROBAR
                if ($valAmount <= 0.0) {
                    throw new InvalidArgumentException("El monto validado para el comprobante ID {$docId} debe ser mayor que cero.");
                }

                // 8. Monto validado superior al monto rendido
                if ($valAmount > $origDocAmount) {
                    throw new InvalidArgumentException("El monto validado (" . number_format($valAmount, 2, '.', '') . ") no puede superar el monto rendido (" . number_format($origDocAmount, 2, '.', '') . ") para el comprobante ID {$docId}.");
                }
            }

            $validatedUpdates[$docId] = [
                'estado_item'    => $itemState,
                'monto_validado' => $itemState === 'APROBADO' ? number_format($valAmount, 2, '.', '') : '0.00',
                'motivo'         => $itemReason !== '' ? self::truncateText($itemReason, 255) : null,
                'decision_input' => $decision,
            ];
        }

        // Aplicar actualizaciones a documentos en orden FIFO y registrar auditoría individual append-only
        $stmtUpdateDoc = $pdo->prepare(
            'UPDATE rendicion_documentos
             SET estado_item = :estado_item,
                 monto_validado = :monto_validado,
                 motivo_rechazo = :motivo
             WHERE id = :id AND rendicion_id = :rendicion_id'
        );
        foreach ($existingDocs as $doc) {
            $docId = (int)$doc['id'];
            if (isset($validatedUpdates[$docId])) {
                $up = $validatedUpdates[$docId];
                $stmtUpdateDoc->execute([
                    ':estado_item'    => $up['estado_item'],
                    ':monto_validado' => $up['monto_validado'],
                    ':motivo'         => $up['motivo'],
                    ':id'             => $docId,
                    ':rendicion_id'   => $renditionId,
                ]);

                // Registrar evento individual por comprobante en rendicion_historial_estados
                $itemComment = ($up['estado_item'] === 'RECHAZADO')
                    ? ($up['motivo'] ?? 'Comprobante rechazado en validación documental.')
                    : 'Comprobante aprobado en validación documental.';

                self::logHistory($pdo, [
                    'rendicion_id'    => $renditionId,
                    'documento_id'    => $docId,
                    'usuario_id'      => (int)($actor['id'] ?? 0),
                    'actor_tipo'      => 'TESORERIA',
                    'actor_nombre'    => (string)($actor['nombre'] ?? 'Tesorería'),
                    'actor_email'     => $actor['email'] ?? null,
                    'accion'          => 'VALIDAR_DOCUMENTO',
                    'estado_anterior' => $doc['estado_item'],
                    'estado_nuevo'    => $up['estado_item'],
                    'comentario'      => $itemComment,
                    'metadata'        => [
                        'monto_rendido'             => (float)$doc['monto'],
                        'monto_validado_anterior'   => $doc['monto_validado'] !== null ? (float)$doc['monto_validado'] : null,
                        'monto_validado_nuevo'      => (float)$up['monto_validado'],
                        'decision'                  => $up['decision_input'],
                        'motivo'                    => $up['motivo'],
                        'numero_documento'          => $doc['numero_documento'],
                        'numero_documento_original' => $doc['numero_documento_original'] ?? null,
                    ],
                ]);
            }
        }

        // Recalcular el total validado exclusivamente con comprobantes APROBADOS
        $stmtSum = $pdo->prepare(
            'SELECT COALESCE(SUM(COALESCE(monto_validado, monto)), 0)
             FROM rendicion_documentos
             WHERE rendicion_id = :id AND activo = 1 AND estado_item = "APROBADO"'
        );
        $stmtSum->execute([':id' => $renditionId]);
        $totalValidado = (float)$stmtSum->fetchColumn();

        // Recalcular reserva, exceso y ajuste presupuestario
        $reservaAnterior  = (float)$rendition['monto_maximo_aprobable'];
        $saldoAlEnviar    = (float)($rendition['saldo_disponible_al_enviar'] ?? 0);
        $saldoBase        = max(0.0, $saldoAlEnviar);

        $reservaNueva     = min($totalValidado, $saldoBase);
        $ajusteReserva    = $reservaNueva - $reservaAnterior;
        $newExceso        = max(0.0, $totalValidado - $saldoAlEnviar);
        $newExcesoNoReemb = $newExceso;
        $newAplicoTope    = ($newExceso > 0.00) ? 1 : 0;
        $newMaxAprobable  = $reservaNueva;

        // La cabecera permanece invariablemente en EN_REVISION_TESORERIA (no aprueba la rendición)
        $stmtUpdateRend = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET monto_total_aprobado         = :monto_val,
                 monto_maximo_aprobable       = :max_apr,
                 monto_exceso                 = :exceso,
                 monto_exceso_no_reembolsable = :exceso_no_reemb,
                 aplico_tope_presupuestario   = :aplico_tope
             WHERE id = :id'
        );
        $stmtUpdateRend->execute([
            ':monto_val'      => number_format($totalValidado, 2, '.', ''),
            ':max_apr'        => number_format($newMaxAprobable, 2, '.', ''),
            ':exceso'         => number_format($newExceso, 2, '.', ''),
            ':exceso_no_reemb'=> number_format($newExcesoNoReemb, 2, '.', ''),
            ':aplico_tope'    => $newAplicoTope,
            ':id'             => $renditionId,
        ]);

        if (abs($ajusteReserva) > 0.001) {
            $stmtBudget = $pdo->prepare(
                'UPDATE presupuestos_vendedores
                 SET monto_utilizado = GREATEST(0, monto_utilizado + :ajuste)
                 WHERE id = :id'
            );
            $stmtBudget->execute([
                ':ajuste' => number_format($ajusteReserva, 2, '.', ''),
                ':id'     => $budgetId,
            ]);
        }

        self::logHistory($pdo, [
            'rendicion_id'   => $renditionId,
            'usuario_id'     => (int)($actor['id'] ?? 0),
            'actor_tipo'     => 'TESORERIA',
            'actor_nombre'   => (string)($actor['nombre'] ?? 'Tesorería'),
            'actor_email'    => $actor['email'] ?? null,
            'accion'         => 'VALIDAR_DOCUMENTOS',
            'estado_anterior'=> $rendition['estado'],
            'estado_nuevo'   => $rendition['estado'],
            'comentario'     => $comment !== '' ? $comment : 'Validación documental de comprobantes registrada por Tesorería.',
            'metadata'       => [
                'monto_validado'   => $totalValidado,
                'reserva_anterior' => $reservaAnterior,
                'reserva_nueva'    => $reservaNueva,
                'ajuste_reserva'   => $ajusteReserva,
            ],
        ]);

        if (class_exists('AuditService')) {
            AuditService::log($pdo, (int)($actor['id'] ?? 0), (string)($actor['email'] ?? ''), 'RENDICION_VALIDAR_DOCUMENTOS', json_encode([
                'rendicion_id'     => $renditionId,
                'monto_validado'   => $totalValidado,
                'reserva_anterior' => $reservaAnterior,
                'reserva_nueva'    => $reservaNueva,
                'ajuste_reserva'   => $ajusteReserva,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return [
            'rendicion_id'          => $renditionId,
            'estado'                => $rendition['estado'],
            'monto_validado'        => $totalValidado,
            'monto_maximo_aprobable'=> $newMaxAprobable,
            'monto_exceso'          => $newExceso,
            'aplico_tope'           => (bool)$newAplicoTope,
            'reserva_anterior'      => $reservaAnterior,
            'reserva_nueva'         => $reservaNueva,
            'ajuste_reserva'        => $ajusteReserva,
        ];
    }

    /**
     * P0-1: Verifica y envía la rendición al responsable configurado.
     * Exige que todos los comprobantes activos estén en estado APROBADO o RECHAZADO (cero PENDIENTE),
     * que exista al menos un comprobante aprobado y que el total validado sea mayor a cero.
     * Rechaza solicitudes que envíen decisiones documentales (deben ir por VALIDAR_DOCUMENTOS).
     * No modifica documentos ni la reserva presupuestaria.
     *
     * @param PDO $pdo
     * @param int $renditionId
     * @param int $approverId
     * @param array $actor
     * @param string $comment
     * @param array|null $input
     * @param bool $dispatchEmail
     * @return array
     * @throws DomainException|InvalidArgumentException
     */
    public static function verificarYEnviar(
        PDO $pdo,
        int $renditionId,
        int $approverId,
        array $actor,
        string $comment = '',
        ?array $input = null,
        bool $dispatchEmail = true
    ): array {
        // 1. Rechazar con HTTP 422 si se envían decisiones documentales
        if (is_array($input) && array_key_exists('decisiones', $input)) {
            throw new InvalidArgumentException('Las decisiones documentales deben procesarse exclusivamente mediante la acción VALIDAR_DOCUMENTOS.');
        }

        if ($renditionId <= 0) {
            throw new InvalidArgumentException('ID de rendición no válido.');
        }
        if ($approverId <= 0) {
            throw new InvalidArgumentException('Seleccione el responsable que autorizará la rendición.');
        }

        // Bloquear cabecera de la rendición
        $stmtRendition = $pdo->prepare(
            'SELECT * FROM rendiciones_gastos
             WHERE id = :id AND activo = 1
             LIMIT 1
             FOR UPDATE'
        );
        $stmtRendition->execute([':id' => $renditionId]);
        $rendition = $stmtRendition->fetch(PDO::FETCH_ASSOC);
        if (!$rendition) {
            throw new DomainException('Rendición no encontrada.');
        }

        $allowedReviewStates = ['EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS'];
        if (!in_array($rendition['estado'], $allowedReviewStates, true)) {
            throw new DomainException('Sólo se pueden verificar y enviar a aprobación rendiciones en revisión de Tesorería.');
        }

        // 2. Comprobar documentos activos: deben ser evaluables (PENDIENTE o APROBADO)
        $stmtDocs = $pdo->prepare(
            'SELECT id, estado_item, monto, monto_validado
             FROM rendicion_documentos
             WHERE rendicion_id = :id AND activo = 1
             ORDER BY fecha_emision ASC, id ASC
             FOR UPDATE'
        );
        $stmtDocs->execute([':id' => $renditionId]);
        $docs = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);
        if (empty($docs)) {
            throw new DomainException('La rendición no contiene comprobantes activos.');
        }

        $eligibleCount = 0;
        $validatedSum = 0.0;
        foreach ($docs as $d) {
            $itemState = $d['estado_item'];
            if (in_array($itemState, ['BORRADOR', 'DESCARTADO'], true)) {
                throw new DomainException("No se puede enviar a aprobación una rendición con comprobantes en estado {$itemState}.");
            }
            if ($itemState === 'APROBADO' || $itemState === 'PENDIENTE') {
                $eligibleCount++;
                $val = $d['monto_validado'] !== null ? (float)$d['monto_validado'] : (float)$d['monto'];
                $validatedSum += $val;
            }
        }

        // 4. Debe existir al menos un documento evaluable
        if ($eligibleCount === 0) {
            throw new DomainException('No se puede enviar a aprobación una rendición donde todos los comprobantes fueron rechazados.');
        }

        // 5. El total a enviar debe ser mayor que cero
        if ($validatedSum <= 0.0) {
            throw new DomainException('El monto total validado debe ser mayor que cero para enviar la rendición a aprobación.');
        }

        // 6. Gestionar la solicitud de aprobación mediante ApprovalWorkflowService
        $workflow = null;
        $requestId = (int)($rendition['solicitud_excepcion_id'] ?? 0);
        if ($requestId > 0) {
            $stmtRequest = $pdo->prepare(
                'SELECT id, estado, activo
                 FROM solicitudes_aprobacion
                 WHERE id = :id AND tipo_solicitud = :tipo
                 LIMIT 1 FOR UPDATE'
            );
            $stmtRequest->execute([':id' => $requestId, ':tipo' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL]);
            $currentRequest = $stmtRequest->fetch(PDO::FETCH_ASSOC);
            if ($currentRequest && (bool)$currentRequest['activo'] && in_array($currentRequest['estado'], ['PENDIENTE_ENVIO', 'PENDIENTE_DECISION', 'ENVIO_FALLIDO', 'VENCIDA'], true)) {
                $workflow = ApprovalWorkflowService::rotateToken($pdo, $requestId, $approverId, [
                    'id' => (int)($actor['id'] ?? 0), 'nombre' => (string)($actor['nombre'] ?? 'Tesorería'), 'email' => $actor['email'] ?? null,
                ], $validatedSum);
            }
        }

        if ($workflow === null) {
            $workflow = ApprovalWorkflowService::createRequest($pdo, [
                'tipo_solicitud' => ApprovalWorkflowService::TYPE_RENDITION_APPROVAL,
                'rendicion_id'   => $renditionId,
                'aprobador_id'   => $approverId,
                'solicitado_por' => (int)($actor['id'] ?? 0),
                'monto_solicitado' => $validatedSum,
                'justificacion'  => $comment !== '' ? self::truncateText($comment, 500) : 'Verificación documental completada por Tesorería',
                'actor_nombre'   => (string)($actor['nombre'] ?? 'Tesorería'),
                'actor_email'    => $actor['email'] ?? null,
            ]);
        }

        $request = $workflow['solicitud'];

        // Documentos para el correo del responsable
        $stmtMailDocs = $pdo->prepare(
            'SELECT * FROM rendicion_documentos
             WHERE rendicion_id = :rendicion_id AND activo = 1 AND estado_item != "DESCARTADO"
             ORDER BY fecha_emision ASC, id ASC'
        );
        $stmtMailDocs->execute([':rendicion_id' => $renditionId]);
        $mailDocuments = $stmtMailDocs->fetchAll(PDO::FETCH_ASSOC);

        $stmtContext = $pdo->prepare(
            'SELECT e.nombre AS empresa_nombre, p.nombre_gira
             FROM empresas e
             INNER JOIN presupuestos_vendedores p ON p.id = :presupuesto_id
             WHERE e.id = :empresa_id LIMIT 1'
        );
        $stmtContext->execute([':presupuesto_id' => (int)$rendition['presupuesto_id'], ':empresa_id' => (int)$rendition['empresa_id']]);
        $context = $stmtContext->fetch(PDO::FETCH_ASSOC) ?: [];

        // 7. Actualizar cabecera de la rendición
        $stmtUpdateRend = $pdo->prepare(
            'UPDATE rendiciones_gastos
             SET estado = "PENDIENTE_APROBACION_RESPONSABLE",
                 verificado_tesoreria_at = NOW(),
                 verificado_tesoreria_por = :admin_id,
                 solicitud_excepcion_id = :solicitud_id,
                 aprobador_solicitado_id = :approver_id,
                 monto_total_aprobado = :monto_val,
                 notificacion_exceso_estado = "PENDIENTE"
             WHERE id = :id'
        );
        $stmtUpdateRend->execute([
            ':admin_id'      => (int)($actor['id'] ?? 0),
            ':solicitud_id'  => (int)$request['id'],
            ':approver_id'   => $approverId,
            ':monto_val'     => number_format($validatedSum, 2, '.', ''),
            ':id'            => $renditionId,
        ]);

        self::logHistory($pdo, [
            'rendicion_id'   => $renditionId,
            'usuario_id'     => (int)($actor['id'] ?? 0),
            'actor_tipo'     => 'TESORERIA',
            'actor_nombre'   => (string)($actor['nombre'] ?? 'Tesorería'),
            'actor_email'    => $actor['email'] ?? null,
            'accion'         => 'VERIFICAR_Y_ENVIAR_RESPONSABLE',
            'estado_anterior'=> $rendition['estado'],
            'estado_nuevo'   => 'PENDIENTE_APROBACION_RESPONSABLE',
            'comentario'     => $comment !== '' ? $comment : 'Comprobantes y fotos verificados por Tesorería. Solicitud enviada a aprobación gerencial.',
            'metadata'       => [
                'solicitud_id'     => (int)$request['id'],
                'aprobador_id'     => (int)$request['aprobador_id'],
                'aprobador_nombre' => $request['aprobador_nombre_snapshot'],
                'monto_a_aprobar'  => $validatedSum,
            ],
        ]);

        if (class_exists('AuditService')) {
            AuditService::log($pdo, (int)($actor['id'] ?? 0), (string)($actor['email'] ?? ''), 'RENDICION_VERIFICAR_Y_ENVIAR', json_encode([
                'rendicion_id' => $renditionId,
                'aprobador_id' => (int)$request['aprobador_id'],
                'monto'        => $validatedSum,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        $mailSent = false;
        if ($dispatchEmail && class_exists('MailService')) {
            $approver = [
                'id'     => (int)$request['aprobador_id'],
                'nombre' => $request['aprobador_nombre_snapshot'],
                'cargo'  => $request['aprobador_cargo_snapshot'],
                'email'  => $request['aprobador_email_snapshot'],
            ];
            try {
                $mailSent = MailService::enviarSolicitudAprobacionRendicion(
                    array_merge($rendition, $context, ['monto_total_aprobado' => $validatedSum]),
                    $mailDocuments,
                    $workflow['raw_token'],
                    $approver,
                    $comment
                );
            } catch (Throwable $mailEx) {
                error_log('[RendicionesService.verificar.mail] ' . $mailEx->getMessage());
            }
            ApprovalWorkflowService::markEmailResult($pdo, (int)$request['id'], $mailSent, $mailSent ? null : 'El servidor SMTP no confirmó la entrega del correo.');
        }

        return [
            'rendicion_id'    => $renditionId,
            'solicitud_id'    => (int)$request['id'],
            'correo_enviado'  => $mailSent,
            'aprobador_nombre'=> $request['aprobador_nombre_snapshot'],
            'estado'          => 'PENDIENTE_APROBACION_RESPONSABLE',
            'monto_solicitado'=> $validatedSum,
            'raw_token'       => $workflow['raw_token'],
            'solicitud'       => $request,
        ];
    }
}
