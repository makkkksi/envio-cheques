<?php
/**
 * app.php — Configuración Global de Entorno
 * 
 * Único archivo de configuración donde se definen las constantes del sistema.
 * 
 * CREDENCIALES SENSIBLES: En producción (AWS), las credenciales se leen
 * desde variables de entorno del servidor (Apache SetEnv, .htaccess o AWS Parameter Store).
 * En local, se usan los valores por defecto (fallback) para desarrollo.
 * 
 * Para configurar en Apache (VirtualHost o .htaccess del proyecto):
 *   SetEnv APP_ENV production
 *   SetEnv DB_HOST localhost
 *   SetEnv DB_USER usuario_produccion
 *   SetEnv DB_PASS contraseña_segura
 *   SetEnv MAIL_PASS contraseña_smtp
 */

// Entorno de ejecución: 'local' | 'production'
define('APP_ENV', getenv('APP_ENV') ?: 'local');

// Encabezados de Seguridad a Nivel Aplicación (OWASP ZAP Hardening)
if (!headers_sent()) {
    header_remove('X-Powered-By');
    header_remove('Server');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Configuración de Base de Datos Central y Servidor MySQL
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME_CENTRAL', getenv('DB_NAME_CENTRAL') ?: 'bd_modulo_cobranzas');

// Autenticación en desarrollo local (Modo Bypass)
define('AUTH_BYPASS_USER_ID', 1);

// Sesiones web. El TTL físico de PHP debe ser mayor que los TTL lógicos para
// evitar que el recolector elimine sesiones activas antes de tiempo.
define('SESSION_SERVER_TTL_SECONDS', max(86400, (int)(getenv('SESSION_SERVER_TTL_SECONDS') ?: 86400)));
define('ADMIN_SESSION_IDLE_SECONDS', max(3600, (int)(getenv('ADMIN_SESSION_IDLE_SECONDS') ?: 43200)));
define('ADMIN_SESSION_ABSOLUTE_SECONDS', max(ADMIN_SESSION_IDLE_SECONDS, (int)(getenv('ADMIN_SESSION_ABSOLUTE_SECONDS') ?: 57600)));
define('SELLER_SESSION_IDLE_SECONDS', max(3600, (int)(getenv('SELLER_SESSION_IDLE_SECONDS') ?: 43200)));
define('SELLER_SESSION_ABSOLUTE_SECONDS', max(SELLER_SESSION_IDLE_SECONDS, (int)(getenv('SELLER_SESSION_ABSOLUTE_SECONDS') ?: 86400)));
define('SESSION_COOKIE_REFRESH_SECONDS', max(60, (int)(getenv('SESSION_COOKIE_REFRESH_SECONDS') ?: 300)));

// Rutas de Almacenamiento de Archivos (Uploads)
define('UPLOADS_BASE_PATH', getenv('UPLOADS_BASE_PATH') ?: __DIR__ . '/../uploads');
define('UPLOADS_BASE_URL', getenv('UPLOADS_BASE_URL') ?: 'https://www.autotec.cl/cobranza_cheques/uploads');
define('PORTAL_BASE_URL', rtrim(getenv('PORTAL_BASE_URL') ?: 'https://www.autotec.cl/cobranza_cheques', '/'));

// Módulo de Rendiciones de Gastos
define('RENDICIONES_TOKEN_TTL_HOURS', max(1, (int)(getenv('RENDICIONES_TOKEN_TTL_HOURS') ?: 48)));
define('RENDICIONES_MAX_UPLOAD_BYTES', 10 * 1024 * 1024);

// Whitelist de Bases de Datos ERP Autorizadas (Seguridad Cross-DB)
define('ALLOWED_DATABASES', [
    'automarc_automarco',
    'autohd_automarcohd',
    'autotec_ecom',
    'gabteccl_sitbdd1978'
]);

// Configuración del Servicio de Correo SMTP Host
define('MAIL_HOST', getenv('MAIL_HOST') ?: 'mail.holdingautomarco.com');
define('MAIL_PORT', (int)(getenv('MAIL_PORT') ?: 587));
define('MAIL_USER', getenv('MAIL_USER') ?: 'envio@holdingautomarco.com');
define('MAIL_PASS', getenv('MAIL_PASS') ?: 'seba_.161214');
define('MAIL_FROM', getenv('MAIL_FROM') ?: 'envio@holdingautomarco.com');

// La configuración de Despacho Automático ahora se controla desde el Panel (BD)
define('CRON_SECRET_KEY', getenv('CRON_SECRET_KEY') ?: 'cobranzas_cron_secret_2026');

// Configuración de Google Sheets API
define('GOOGLE_SHEETS_CREDENTIALS', getenv('GOOGLE_SHEETS_CREDENTIALS') ?: __DIR__ . '/../rising-sector-504512-t7-95f008fca270.json');
define('GOOGLE_SHEETS_SPREADSHEET_ID', getenv('GOOGLE_SHEETS_SPREADSHEET_ID') ?: '1HrOyRhkuR9ULAckv_VC3S_RDwcGXgc5RNruGpKcCVJM');
define('GOOGLE_SHEETS_RANGE', 'A:K');
