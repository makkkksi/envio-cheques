<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/RendicionesService.php';
require_once __DIR__ . '/../../../services/ErpSellerDirectoryService.php';

try {
    RendicionesService::requireMethod('GET');
    $pdo = Database::getCobranzasConnection();
    requirePermission($pdo, 'rendiciones.manage');

    $companyId = filter_input(INPUT_GET, 'empresa_id', FILTER_VALIDATE_INT) ?: 0;
    $search = trim((string)($_GET['busqueda'] ?? ''));
    $companies = array_map(
        static fn(array $company): array => [
            'empresa_id' => (int)$company['id'],
            'empresa_nombre' => (string)$company['nombre'],
        ],
        ErpSellerDirectoryService::getCompanies($pdo)
    );

    $data = $companyId > 0
        ? ErpSellerDirectoryService::searchByCompany($pdo, $companyId, $search)
        : ErpSellerDirectoryService::getHoldingDirectory($pdo, $search);

    RendicionesService::jsonResponse(true, [
        'data' => $data,
        'empresas' => $companies,
        'alcance' => $companyId > 0 ? 'EMPRESA' : 'HOLDING',
    ]);
} catch (InvalidArgumentException $exception) {
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('[admin.rendiciones.buscar_vendedores] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible consultar el catálogo de vendedores.'], 500);
}
