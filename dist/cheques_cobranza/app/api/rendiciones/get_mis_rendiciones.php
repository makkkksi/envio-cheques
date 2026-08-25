<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../services/RendicionesService.php';

RendicionesService::requireMethod('GET');

try {
    $pdo = Database::getCobranzasConnection();
    $seller = requireSellerContext($pdo);
    $allowedStates = ['TODOS', 'PENDIENTE_APROBACION_EXCESO', 'EN_REVISION_TESORERIA', 'DOCUMENTOS_FISICOS_RECIBIDOS', 'APROBADA', 'APROBADA_PARCIAL', 'RECHAZADA', 'PAGADA'];
    $state = strtoupper(trim((string)($_GET['estado'] ?? 'TODOS')));
    if (!in_array($state, $allowedStates, true)) {
        throw new InvalidArgumentException('Filtro de estado no válido.');
    }
    $page = max(1, (int)($_GET['pagina'] ?? 1));
    $limit = min(50, max(1, (int)($_GET['limite'] ?? 20)));
    $offset = ($page - 1) * $limit;

    $stmt = $pdo->prepare(
        'SELECT r.id, r.codigo_rendicion, r.periodo_mes, r.tipo_rendicion,
                r.monto_total_rendido, r.monto_total_aprobado, r.monto_exceso,
                r.estado, r.documentos_fisicos_recibidos, r.enviada_at,
                r.created_at, p.nombre_gira,
                COUNT(d.id) AS cantidad_documentos
         FROM rendiciones_gastos r
         INNER JOIN presupuestos_vendedores p ON p.id = r.presupuesto_id
         LEFT JOIN rendicion_documentos d ON d.rendicion_id = r.id AND d.activo = :documento_activo
         WHERE r.empresa_id = :empresa_id
           AND r.vendedor_id = :vendedor_id
           AND r.activo = :rendicion_activa
           AND (:todos = 1 OR r.estado = :estado)
         GROUP BY r.id, r.codigo_rendicion, r.periodo_mes, r.tipo_rendicion,
                  r.monto_total_rendido, r.monto_total_aprobado, r.monto_exceso,
                  r.estado, r.documentos_fisicos_recibidos, r.enviada_at,
                  r.created_at, p.nombre_gira
         ORDER BY r.created_at DESC
         LIMIT :limite OFFSET :offset'
    );
    $stmt->bindValue(':documento_activo', 1, PDO::PARAM_INT);
    $stmt->bindValue(':empresa_id', $seller['empresa_id'], PDO::PARAM_INT);
    $stmt->bindValue(':vendedor_id', $seller['vendedor_id'], PDO::PARAM_INT);
    $stmt->bindValue(':rendicion_activa', 1, PDO::PARAM_INT);
    $stmt->bindValue(':todos', $state === 'TODOS' ? 1 : 0, PDO::PARAM_INT);
    $stmt->bindValue(':estado', $state === 'TODOS' ? 'ENVIADA' : $state, PDO::PARAM_STR);
    $stmt->bindValue(':limite', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $renditions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($renditions as &$rendition) {
        $rendition['id'] = (int)$rendition['id'];
        $rendition['cantidad_documentos'] = (int)$rendition['cantidad_documentos'];
        $rendition['monto_total_rendido'] = (float)$rendition['monto_total_rendido'];
        $rendition['monto_total_aprobado'] = (float)$rendition['monto_total_aprobado'];
        $rendition['monto_exceso'] = (float)$rendition['monto_exceso'];
        $rendition['documentos_fisicos_recibidos'] = (bool)$rendition['documentos_fisicos_recibidos'];
    }
    unset($rendition);

    RendicionesService::jsonResponse(true, ['data' => [
        'rendiciones' => $renditions,
        'pagina' => $page,
        'limite' => $limit,
    ]]);
} catch (InvalidArgumentException $exception) {
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('[rendiciones.get_mis_rendiciones] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible cargar las rendiciones.'], 500);
}
