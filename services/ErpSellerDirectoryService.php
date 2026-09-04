<?php

declare(strict_types=1);

/**
 * services/ErpSellerDirectoryService.php
 *
 * Catálogo de vendedores ERP de solo lectura.
 * Utiliza web_usuarios para Automarco, Autotec y Gabtec,
 * y tbl_vendedores para Automarco HD (integración heredada).
 *
 * Los identificadores SQL se resuelven exclusivamente mediante allowlists y mapas fijos;
 * ningún nombre de base de datos o columna proviene del request HTTP.
 */

interface SellerRepositoryInterface
{
    public function search(PDO $erpPdo, string $searchPattern): array;
    public function findById(PDO $erpPdo, int $sellerId): ?array;
}

class WebUsuariosSellerRepository implements SellerRepositoryInterface
{
    public function search(PDO $erpPdo, string $searchPattern): array
    {
        $sql = "SELECT 
                    vend_cod,
                    nombre AS vendedor_nombre,
                    NULLIF(TRIM(email), '') AS vendedor_email
                FROM web_usuarios
                WHERE rol = :rol
                  AND activo = :activo
                  AND vend_cod IS NOT NULL
                  AND TRIM(vend_cod) REGEXP '^[1-9][0-9]*$'
                  AND (
                      vend_cod LIKE :busqueda_codigo
                      OR nombre LIKE :busqueda_nombre
                      OR (email IS NOT NULL AND email LIKE :busqueda_email)
                  )
                ORDER BY nombre ASC, CAST(vend_cod AS UNSIGNED) ASC
                LIMIT 100";

        $stmt = $erpPdo->prepare($sql);
        $stmt->execute([
            ':rol' => 'vendedor',
            ':activo' => 1,
            ':busqueda_codigo' => $searchPattern,
            ':busqueda_nombre' => $searchPattern,
            ':busqueda_email' => $searchPattern,
        ]);

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rawVendCod = trim((string)($row['vend_cod'] ?? ''));
            if (!preg_match('/^[1-9][0-9]*$/D', $rawVendCod)) {
                continue;
            }
            if (strlen($rawVendCod) > 10 || (int)$rawVendCod > 2147483647) {
                continue;
            }
            $vid = (int)$rawVendCod;
            if ($vid > 0) {
                $results[] = [
                    'cli_vendedor' => $vid,
                    'nombre_vendedor' => $row['vendedor_nombre'],
                    'ven_mail' => $row['vendedor_email'],
                ];
            }
        }
        return $results;
    }

    public function findById(PDO $erpPdo, int $sellerId): ?array
    {
        if ($sellerId <= 0 || $sellerId > 2147483647) {
            return null;
        }

        $sellerIdStr = (string)$sellerId;
        if (!preg_match('/^[1-9][0-9]*$/D', $sellerIdStr)) {
            return null;
        }

        $sql = "SELECT 
                    vend_cod,
                    nombre AS vendedor_nombre,
                    NULLIF(TRIM(email), '') AS vendedor_email
                FROM web_usuarios
                WHERE rol = :rol
                  AND activo = :activo
                  AND vend_cod IS NOT NULL
                  AND TRIM(vend_cod) REGEXP '^[1-9][0-9]*$'
                  AND TRIM(vend_cod) = :vend_cod";

        $stmt = $erpPdo->prepare($sql);
        $stmt->execute([
            ':rol' => 'vendedor',
            ':activo' => 1,
            ':vend_cod' => $sellerIdStr,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) {
            return null;
        }
        if (count($rows) > 1) {
            throw new DomainException("Identidad de vendedor ambigua: existen múltiples registros activos con el código {$sellerId}.");
        }

        $row = $rows[0];
        $rawVendCod = trim((string)($row['vend_cod'] ?? ''));
        if (!preg_match('/^[1-9][0-9]*$/D', $rawVendCod)) {
            return null;
        }
        if (strlen($rawVendCod) > 10 || (int)$rawVendCod > 2147483647 || (int)$rawVendCod !== $sellerId) {
            return null;
        }

        $vid = (int)$rawVendCod;

        return [
            'cli_vendedor' => $vid,
            'nombre_vendedor' => $row['vendedor_nombre'],
            'ven_mail' => $row['vendedor_email'],
        ];
    }
}

class LegacySellerRepository implements SellerRepositoryInterface
{
    public function search(PDO $erpPdo, string $searchPattern): array
    {
        $sql = "SELECT 
                    cli_vendedor, 
                    nombre_vendedor, 
                    NULLIF(TRIM(ven_mail), '') AS ven_mail
                FROM tbl_vendedores
                WHERE CAST(cli_vendedor AS CHAR) LIKE :busqueda_codigo
                   OR nombre_vendedor LIKE :busqueda_nombre
                   OR (ven_mail IS NOT NULL AND ven_mail LIKE :busqueda_email)
                ORDER BY nombre_vendedor ASC, cli_vendedor ASC
                LIMIT 100";

        $stmt = $erpPdo->prepare($sql);
        $stmt->execute([
            ':busqueda_codigo' => $searchPattern,
            ':busqueda_nombre' => $searchPattern,
            ':busqueda_email' => $searchPattern,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(PDO $erpPdo, int $sellerId): ?array
    {
        if ($sellerId <= 0) {
            return null;
        }

        $sql = "SELECT 
                    cli_vendedor, 
                    nombre_vendedor, 
                    NULLIF(TRIM(ven_mail), '') AS ven_mail
                FROM tbl_vendedores
                WHERE cli_vendedor = :vendedor_id
                LIMIT 1";

        $stmt = $erpPdo->prepare($sql);
        $stmt->execute([':vendedor_id' => $sellerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}

final class ErpSellerDirectoryService
{
    private static ?array $customRepositories = null;
    private static ?Closure $connectionResolver = null;

    /**
     * Permite inyectar repositorios para pruebas automatizadas sin tocar bases ERP reales.
     */
    public static function setRepository(string $nombreBd, ?SellerRepositoryInterface $repo): void
    {
        if ($repo === null) {
            unset(self::$customRepositories[$nombreBd]);
        } else {
            self::$customRepositories[$nombreBd] = $repo;
        }
    }

    /**
     * Permite inyectar un resolvedor de conexión PDO para pruebas con esquemas temporales.
     */
    public static function setConnectionResolver(?Closure $resolver): void
    {
        self::$connectionResolver = $resolver;
    }

    public static function resetCustomRepositories(): void
    {
        self::$customRepositories = null;
        self::$connectionResolver = null;
    }

    public static function getRepositoryForDatabase(string $nombreBd): SellerRepositoryInterface
    {
        if (isset(self::$customRepositories[$nombreBd])) {
            return self::$customRepositories[$nombreBd];
        }

        if ($nombreBd === 'autohd_automarcohd') {
            return new LegacySellerRepository();
        }

        return new WebUsuariosSellerRepository();
    }

    private static function getErpConnection(string $nombreBd): PDO
    {
        if (self::$connectionResolver !== null) {
            return (self::$connectionResolver)($nombreBd);
        }
        return Database::getErpConnection($nombreBd);
    }

    public static function getCompanies(PDO $centralPdo): array
    {
        $stmt = $centralPdo->prepare('SELECT id, nombre, nombre_bd FROM empresas ORDER BY id ASC');
        $stmt->execute();

        return array_values(array_filter(
            $stmt->fetchAll(PDO::FETCH_ASSOC),
            static fn(array $company): bool => in_array((string)$company['nombre_bd'], ALLOWED_DATABASES, true)
        ));
    }

    public static function searchByCompany(PDO $centralPdo, int $companyId, string $search = ''): array
    {
        $company = self::resolveCompany($centralPdo, $companyId);
        $erpPdo = self::getErpConnection($company['nombre_bd']);
        $repo = self::getRepositoryForDatabase($company['nombre_bd']);
        $searchPattern = '%' . substr(trim($search), 0, 80) . '%';
        $sellers = $repo->search($erpPdo, $searchPattern);

        return array_map(
            static fn(array $seller): array => self::normalizeSeller($seller, $company),
            $sellers
        );
    }

    public static function findByCompanyAndId(PDO $centralPdo, int $companyId, int $sellerId): ?array
    {
        $company = self::resolveCompany($centralPdo, $companyId);
        $erpPdo = self::getErpConnection($company['nombre_bd']);
        $repo = self::getRepositoryForDatabase($company['nombre_bd']);
        $seller = $repo->findById($erpPdo, $sellerId);

        return $seller ? self::normalizeSeller($seller, $company) : null;
    }

    public static function getHoldingDirectory(PDO $centralPdo, string $search = ''): array
    {
        $groups = [];
        foreach (self::getCompanies($centralPdo) as $company) {
            foreach (self::searchByCompany($centralPdo, (int)$company['id']) as $seller) {
                $emailKey = self::normalizeEmail($seller['vendedor_email']);
                $identityKey = $emailKey !== ''
                    ? 'email:' . $emailKey
                    : 'local:' . $seller['empresa_id'] . ':' . $seller['vendedor_id'];

                if (!isset($groups[$identityKey])) {
                    $groups[$identityKey] = [
                        'identity_key' => $identityKey,
                        'vendedor_nombre' => $seller['vendedor_nombre'],
                        'vendedor_email' => $seller['vendedor_email'],
                        'empresas' => [],
                    ];
                }
                $groups[$identityKey]['empresas'][] = [
                    'empresa_id' => $seller['empresa_id'],
                    'empresa_nombre' => $seller['empresa_nombre'],
                    'vendedor_id' => $seller['vendedor_id'],
                ];
            }
        }

        $directory = array_values($groups);
        if ($search !== '') {
            $needle = strtolower(substr(trim($search), 0, 80));
            $directory = array_values(array_filter($directory, static function (array $seller) use ($needle): bool {
                $companyText = implode(' ', array_map(
                    static fn(array $company): string => $company['empresa_nombre'] . ' ' . $company['vendedor_id'],
                    $seller['empresas']
                ));
                $haystack = strtolower($seller['vendedor_nombre'] . ' ' . ($seller['vendedor_email'] ?? '') . ' ' . $companyText);
                return str_contains($haystack, $needle);
            }));
        }
        usort($directory, static fn(array $a, array $b): int => strcasecmp($a['vendedor_nombre'], $b['vendedor_nombre']));
        return $directory;
    }

    private static function resolveCompany(PDO $centralPdo, int $companyId): array
    {
        $stmt = $centralPdo->prepare('SELECT id, nombre, nombre_bd FROM empresas WHERE id = :empresa_id LIMIT 1');
        $stmt->execute([':empresa_id' => $companyId]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$company || !in_array((string)$company['nombre_bd'], ALLOWED_DATABASES, true)) {
            throw new InvalidArgumentException('La empresa seleccionada no está autorizada para consultar el ERP.');
        }

        return $company;
    }

    private static function normalizeSeller(array $seller, array $company): array
    {
        $sellerId = (int)$seller['cli_vendedor'];
        $name = trim((string)($seller['nombre_vendedor'] ?? ''));
        $rawEmail = trim((string)($seller['ven_mail'] ?? ''));

        return [
            'empresa_id' => (int)$company['id'],
            'empresa_nombre' => (string)$company['nombre'],
            'vendedor_id' => $sellerId,
            'vend_cod' => $sellerId,
            'vendedor_nombre' => $name !== '' ? $name : 'Vendedor ERP #' . $sellerId,
            'vendedor_email' => filter_var($rawEmail, FILTER_VALIDATE_EMAIL) ? strtolower($rawEmail) : null,
        ];
    }

    private static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string)$email));
    }
}
