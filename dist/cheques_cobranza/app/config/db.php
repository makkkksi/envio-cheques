<?php
/**
 * db.php — Clase Database para Conexiones PDO
 * 
 * Gestiona la conexión a la base de datos central `bd_modulo_cobranzas`
 * y las conexiones dinámicas a las bases de datos ERP previa validación
 * contra la lista blanca ALLOWED_DATABASES.
 */

require_once __DIR__ . '/app.php';

class Database
{
    private static ?PDO $cobranzasPdo = null;

    /**
     * Retorna la conexión PDO a la base de datos central `bd_modulo_cobranzas`.
     */
    public static function getCobranzasConnection(): PDO
    {
        if (self::$cobranzasPdo === null) {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME_CENTRAL . ";charset=utf8mb4";
            self::$cobranzasPdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        }

        return self::$cobranzasPdo;
    }

    /**
     * Retorna una conexión PDO a una base de datos ERP específica.
     * Valida estrictamente el nombre de la BD contra la whitelist ALLOWED_DATABASES.
     * 
     * @param string $nombre_bd
     * @return PDO
     * @throws InvalidArgumentException Si la BD no está autorizada.
     */
    public static function getErpConnection(string $nombre_bd): PDO
    {
        if (!in_array($nombre_bd, ALLOWED_DATABASES, true)) {
            throw new InvalidArgumentException("Base de datos no autorizada: {$nombre_bd}");
        }

        $dsn = "mysql:host=" . DB_HOST . ";dbname={$nombre_bd};charset=utf8mb4";
        return new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
}
