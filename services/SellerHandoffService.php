<?php

declare(strict_types=1);

/**
 * services/SellerHandoffService.php
 *
 * Servicio centralizado para resolución de empresas y verificación de sesión
 * en el handoff seguro desde los portales comerciales hacia Cheques y Rendiciones.
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/db.php';

final class SellerHandoffService
{
    private static ?Closure $sessionVerifier = null;

    /**
     * Permite inyectar un verificador de sesión para pruebas automatizadas
     * sin necesidad de escribir en las bases ERP de solo lectura.
     */
    public static function setSessionVerifier(?Closure $verifier): void
    {
        if ($verifier !== null && defined('APP_ENV') && APP_ENV === 'production') {
            throw new RuntimeException('No se permite inyectar verificadores de prueba en entorno de producción.');
        }
        self::$sessionVerifier = $verifier;
    }

    public static function reset(): void
    {
        self::$sessionVerifier = null;
    }

    /**
     * Resuelve el código o identificador de empresa contra la tabla central `empresas`
     * y la allowlist ALLOWED_DATABASES.
     *
     * @param PDO $centralPdo
     * @param string $empresaInput (ej: 'EMP01', 'EMP03', 'EMP10', 'EMP24', 'AUTOMARCO', '1', etc.)
     * @return array{id: int, nombre: string, nombre_bd: string}
     * @throws InvalidArgumentException
     */
    public static function resolveEmpresa(PDO $centralPdo, string $empresaInput): array
    {
        $input = strtoupper(trim($empresaInput));
        if ($input === '') {
            throw new InvalidArgumentException('El identificador de empresa es obligatorio.');
        }

        $aliasMap = [
            'EMP01' => 'automarc_automarco',
            'AUTOMARCO' => 'automarc_automarco',
            'AUTOMARCO LTDA' => 'automarc_automarco',
            'EMP03' => 'autotec_ecom',
            'AUTOTEC' => 'autotec_ecom',
            'AUTOTEC S.A' => 'autotec_ecom',
            'EMP24' => 'autotec_ecom',
            'TOP_REPUESTOS' => 'autotec_ecom',
            'TOP REPUESTOS' => 'autotec_ecom',
            'EMP06' => 'autohd_automarcohd',
            'HD' => 'autohd_automarcohd',
            'AUTOMARCO HD' => 'autohd_automarcohd',
            'HD AUTOMARCO S.A' => 'autohd_automarcohd',
            'EMP10' => 'gabteccl_sitbdd1978',
            'GABTEC' => 'gabteccl_sitbdd1978',
            'GABTEC S.A' => 'gabteccl_sitbdd1978',
        ];

        $targetDatabase = $aliasMap[$input] ?? null;

        if ($targetDatabase !== null) {
            $stmt = $centralPdo->prepare('SELECT id, nombre, nombre_bd FROM empresas WHERE nombre_bd = :nombre_bd LIMIT 1');
            $stmt->execute([':nombre_bd' => $targetDatabase]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
        } elseif (ctype_digit($input)) {
            $stmt = $centralPdo->prepare('SELECT id, nombre, nombre_bd FROM empresas WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$input]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            $stmt = $centralPdo->prepare('SELECT id, nombre, nombre_bd FROM empresas WHERE UPPER(nombre) = :nombre OR nombre_bd = :nombre_bd LIMIT 1');
            $stmt->execute([':nombre' => $input, ':nombre_bd' => strtolower($input)]);
            $company = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$company || !in_array((string)$company['nombre_bd'], ALLOWED_DATABASES, true)) {
            throw new InvalidArgumentException('Empresa no autorizada o no encontrada.');
        }

        return [
            'id' => (int)$company['id'],
            'nombre' => (string)$company['nombre'],
            'nombre_bd' => (string)$company['nombre_bd'],
        ];
    }

    /**
     * Valida de manera estricta que vend_cod sea un número entero canónico positivo:
     * - No acepta 0, negativos, decimales, notación científica, signos (+ o -),
     *   espacios internos, ceros a la izquierda (0012) ni caracteres alfanuméricos.
     * - Rango válido: 1 a 2147483647 (32-bit signed INT).
     */
    public static function validateVendCod(mixed $value): ?int
    {
        $raw = trim((string)$value);
        if (!preg_match('/^[1-9][0-9]*$/D', $raw)) {
            return null;
        }
        if (strlen($raw) > 10) {
            return null;
        }
        $val = (int)$raw;
        if ($val <= 0 || $val > 2147483647) {
            return null;
        }
        return $val;
    }

    /**
     * Consulta exclusivamente en modo lectura web_sesiones JOIN web_usuarios
     * dentro de la BD ERP correspondiente.
     *
     * @param string $nombreBd Base de datos ERP verificada en ALLOWED_DATABASES
     * @param string $sessionToken Token de sesión provisto por el portal comercial
     * @return array{vend_cod: int, nombre: string, email: ?string, rol: string, activo: int}|null
     */
    public static function verifySessionToken(string $nombreBd, string $sessionToken): ?array
    {
        if (self::$sessionVerifier !== null) {
            if (defined('APP_ENV') && APP_ENV === 'production') {
                throw new RuntimeException('Verificador de prueba no permitido en entorno de producción.');
            }
            $verified = (self::$sessionVerifier)($nombreBd, $sessionToken);
            if ($verified !== null) {
                $validatedCod = self::validateVendCod($verified['vend_cod'] ?? null);
                if ($validatedCod === null) {
                    return null;
                }
                $verified['vend_cod'] = $validatedCod;
            }
            return $verified;
        }

        // HD no cuenta con portal web ni web_sesiones
        if ($nombreBd === 'autohd_automarcohd') {
            return null;
        }

        $erpPdo = Database::getErpConnection($nombreBd);

        $sql = "SELECT 
                    u.vend_cod,
                    u.nombre AS vendedor_nombre,
                    NULLIF(TRIM(u.email), '') AS vendedor_email,
                    u.rol,
                    u.activo
                FROM web_sesiones s
                JOIN web_usuarios u ON u.id = s.usuario_id
                WHERE s.token = :token
                  AND s.expira_en > NOW()
                  AND u.activo = :activo
                  AND u.rol = :rol
                  AND u.vend_cod IS NOT NULL
                  AND TRIM(u.vend_cod) REGEXP '^[1-9][0-9]*$'
                LIMIT 1";

        try {
            $stmt = $erpPdo->prepare($sql);
            $stmt->execute([
                ':token' => $sessionToken,
                ':activo' => 1,
                ':rol' => 'vendedor',
            ]);

            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $validatedCod = self::validateVendCod($row['vend_cod'] ?? null);
            if ($validatedCod === null) {
                return null;
            }

            $rawEmail = trim((string)($row['vendedor_email'] ?? ''));

            return [
                'vend_cod' => $validatedCod,
                'nombre' => trim((string)$row['vendedor_nombre']),
                'email' => filter_var($rawEmail, FILTER_VALIDATE_EMAIL) ? strtolower($rawEmail) : null,
                'rol' => (string)$row['rol'],
                'activo' => (int)$row['activo'],
            ];
        } catch (Throwable $e) {
            error_log('[SellerHandoffService::verifySessionToken] Error en BD ERP ' . $nombreBd . ': ' . $e->getMessage());
            return null;
        }
    }
}
