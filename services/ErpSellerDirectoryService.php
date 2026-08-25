<?php

declare(strict_types=1);

/**
 * Catálogo de vendedores ERP de solo lectura.
 *
 * Los identificadores SQL se resuelven exclusivamente mediante este mapa fijo;
 * ningún nombre de base de datos o columna proviene del request HTTP.
 */
final class ErpSellerDirectoryService
{
    private const SEARCH_QUERIES = [
        'automarc_automarco' => "SELECT cli_vendedor, nombre_vendedor, ven_mail
            FROM tbl_vendedores
            WHERE CAST(cli_vendedor AS CHAR) LIKE :busqueda_codigo
               OR nombre_vendedor LIKE :busqueda_nombre
               OR ven_mail LIKE :busqueda_email
            ORDER BY nombre_vendedor ASC, cli_vendedor ASC
            LIMIT 100",
        'autohd_automarcohd' => "SELECT cli_vendedor, nombre_vendedor, ven_mail
            FROM tbl_vendedores
            WHERE CAST(cli_vendedor AS CHAR) LIKE :busqueda_codigo
               OR nombre_vendedor LIKE :busqueda_nombre
               OR ven_mail LIKE :busqueda_email
            ORDER BY nombre_vendedor ASC, cli_vendedor ASC
            LIMIT 100",
        'autotec_ecom' => "SELECT cli_vendedor, nombre_vendedor, ven_mail
            FROM tbl_vendedores
            WHERE CAST(cli_vendedor AS CHAR) LIKE :busqueda_codigo
               OR nombre_vendedor LIKE :busqueda_nombre
               OR ven_mail LIKE :busqueda_email
            ORDER BY nombre_vendedor ASC, cli_vendedor ASC
            LIMIT 100",
        'gabteccl_sitbdd1978' => "SELECT cli_vendedor, ven_nombre AS nombre_vendedor, ven_mail
            FROM tbl_vendedores
            WHERE CAST(cli_vendedor AS CHAR) LIKE :busqueda_codigo
               OR ven_nombre LIKE :busqueda_nombre
               OR ven_mail LIKE :busqueda_email
            ORDER BY ven_nombre ASC, cli_vendedor ASC
            LIMIT 100",
    ];

    private const EXACT_QUERIES = [
        'automarc_automarco' => 'SELECT cli_vendedor, nombre_vendedor, ven_mail FROM tbl_vendedores WHERE cli_vendedor = :vendedor_id LIMIT 1',
        'autohd_automarcohd' => 'SELECT cli_vendedor, nombre_vendedor, ven_mail FROM tbl_vendedores WHERE cli_vendedor = :vendedor_id LIMIT 1',
        'autotec_ecom' => 'SELECT cli_vendedor, nombre_vendedor, ven_mail FROM tbl_vendedores WHERE cli_vendedor = :vendedor_id LIMIT 1',
        'gabteccl_sitbdd1978' => 'SELECT cli_vendedor, ven_nombre AS nombre_vendedor, ven_mail FROM tbl_vendedores WHERE cli_vendedor = :vendedor_id LIMIT 1',
    ];

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
        $erpPdo = Database::getErpConnection($company['nombre_bd']);
        $stmt = $erpPdo->prepare(self::SEARCH_QUERIES[$company['nombre_bd']]);
        $searchPattern = '%' . substr(trim($search), 0, 80) . '%';
        $stmt->execute([
            ':busqueda_codigo' => $searchPattern,
            ':busqueda_nombre' => $searchPattern,
            ':busqueda_email' => $searchPattern,
        ]);

        return array_map(
            static fn(array $seller): array => self::normalizeSeller($seller, $company),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    public static function findByCompanyAndId(PDO $centralPdo, int $companyId, int $sellerId): ?array
    {
        $company = self::resolveCompany($centralPdo, $companyId);
        $erpPdo = Database::getErpConnection($company['nombre_bd']);
        $stmt = $erpPdo->prepare(self::EXACT_QUERIES[$company['nombre_bd']]);
        $stmt->execute([':vendedor_id' => $sellerId]);
        $seller = $stmt->fetch(PDO::FETCH_ASSOC);

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
        if (!isset(self::SEARCH_QUERIES[$company['nombre_bd']], self::EXACT_QUERIES[$company['nombre_bd']])) {
            throw new InvalidArgumentException('El catálogo de vendedores no está configurado para la empresa seleccionada.');
        }

        return $company;
    }

    private static function normalizeSeller(array $seller, array $company): array
    {
        $sellerId = (int)$seller['cli_vendedor'];
        $name = trim((string)($seller['nombre_vendedor'] ?? ''));
        $email = trim((string)($seller['ven_mail'] ?? ''));

        return [
            'empresa_id' => (int)$company['id'],
            'empresa_nombre' => (string)$company['nombre'],
            'vendedor_id' => $sellerId,
            'vendedor_nombre' => $name !== '' ? $name : 'Vendedor ERP #' . $sellerId,
            'vendedor_email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null,
        ];
    }

    private static function normalizeEmail(?string $email): string
    {
        return strtolower(trim((string)$email));
    }
}
