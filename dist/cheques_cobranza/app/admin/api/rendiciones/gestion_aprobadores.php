<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

require_once __DIR__ . '/../../../config/app.php';
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/auth.php';
require_once __DIR__ . '/../../../services/AuditService.php';
require_once __DIR__ . '/../../../services/RendicionesService.php';

$pdo = null;
try {
    $pdo = Database::getCobranzasConnection();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        requirePermission($pdo, 'rendiciones.manage');
        $stmt = $pdo->prepare(
            'SELECT id, orden, nombre, cargo, email, activo, updated_at
             FROM aprobadores_rendiciones
             WHERE activo = :activo
             ORDER BY orden ASC'
        );
        $stmt->execute([':activo' => 1]);
        RendicionesService::jsonResponse(true, [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    }

    if ($method !== 'POST') {
        RendicionesService::jsonResponse(false, ['message' => 'Método no permitido.'], 405);
    }

    $admin = requirePermission($pdo, 'users.manage');
    requireCsrfToken();
    $input = RendicionesService::readJsonBody();
    $items = $input['aprobadores'] ?? null;
    if (!is_array($items) || count($items) !== 2) {
        throw new InvalidArgumentException('Debe configurar exactamente dos responsables de aprobación.');
    }

    $validated = [];
    $emails = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            throw new InvalidArgumentException('La configuración de responsables no es válida.');
        }
        $order = filter_var($item['orden'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($item['nombre'] ?? ''));
        $title = trim((string)($item['cargo'] ?? ''));
        $email = mb_strtolower(trim((string)($item['email'] ?? '')));
        if (!in_array($order, [1, 2], true) || $name === '' || $title === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Nombre, cargo y correo válido son obligatorios para ambos responsables.');
        }
        if (mb_strlen($name) > 150 || mb_strlen($title) > 120 || mb_strlen($email) > 190) {
            throw new InvalidArgumentException('Uno de los datos supera la longitud permitida.');
        }
        if (isset($validated[$order])) {
            throw new InvalidArgumentException('Cada responsable debe ocupar una posición distinta.');
        }
        if (isset($emails[$email])) {
            throw new InvalidArgumentException('Los dos responsables deben tener correos diferentes.');
        }
        $validated[$order] = ['orden' => $order, 'nombre' => $name, 'cargo' => $title, 'email' => $email];
        $emails[$email] = true;
    }
    if (!isset($validated[1], $validated[2])) {
        throw new InvalidArgumentException('Deben configurarse las posiciones 1 y 2.');
    }

    $pdo->beginTransaction();
    $stmtReserveEmails = $pdo->prepare(
        "UPDATE aprobadores_rendiciones
         SET email = CONCAT('actualizando+', id, '@invalid.local'), activo = :inactivo
         WHERE orden IN (:orden_uno, :orden_dos)"
    );
    $stmtReserveEmails->execute([':inactivo' => 0, ':orden_uno' => 1, ':orden_dos' => 2]);

    $stmtSave = $pdo->prepare(
        'INSERT INTO aprobadores_rendiciones (orden, nombre, cargo, email, activo, actualizado_por)
         VALUES (:orden, :nombre, :cargo, :email, :activo, :actualizado_por)
         ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre), cargo = VALUES(cargo), email = VALUES(email),
            activo = VALUES(activo), actualizado_por = VALUES(actualizado_por)'
    );
    foreach ([1, 2] as $order) {
        $item = $validated[$order];
        $stmtSave->execute([
            ':orden' => $item['orden'],
            ':nombre' => $item['nombre'],
            ':cargo' => $item['cargo'],
            ':email' => $item['email'],
            ':activo' => 1,
            ':actualizado_por' => (int)$admin['id'],
        ]);
    }
    AuditService::log(
        $pdo,
        (int)$admin['id'],
        (string)$admin['email'],
        'RENDICIONES_CONFIGURAR_APROBADORES',
        json_encode(['responsables' => array_values($validated)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    $pdo->commit();

    RendicionesService::jsonResponse(true, [
        'message' => 'Responsables de aprobación actualizados correctamente.',
        'data' => array_values($validated),
    ]);
} catch (InvalidArgumentException $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    RendicionesService::jsonResponse(false, ['message' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[admin.rendiciones.gestion_aprobadores] ' . $exception->getMessage());
    RendicionesService::jsonResponse(false, ['message' => 'No fue posible gestionar los responsables de aprobación.'], 500);
}
