# SECURITY.md — Seguridad del Sistema

**Propósito:** Documentar todas las medidas de seguridad implementadas y los patrones que deben respetarse para evitar vulnerabilidades.  
**Audiencia:** Desarrolladores, auditores, agentes de IA.  
**Referencias:** [`CODING_STANDARDS.md §2.2`](./CODING_STANDARDS.md) para PDO seguro · [`ARCHITECTURE.md §5`](./ARCHITECTURE.md) para auth strategy.

---

## 1. Superficie de Ataque y Mitigaciones

| Vector | Riesgo | Mitigación implementada |
|--------|--------|------------------------|
| Inyección SQL | Alto | PDO prepared statements. **Sin concatenación de variables en queries.** |
| BD ERP dinámica | Medio | Whitelist `ALLOWED_DATABASES` en `config/app.php` |
| Subida de archivos | Alto | Validación MIME real (`mime_content_type()`), extensión y tamaño máximo |
| Acceso no autorizado a endpoints | Alto | Middleware auth en `config/auth.php` (bypass en local, JWT en producción) |
| Exposición de errores | Medio | `error_log()` interno, mensaje genérico al cliente |
| Path traversal en uploads | Medio | Nombre de archivo sanitizado con `preg_replace('/[^a-zA-Z0-9._-]/', '', ...)` |
| CORS abierto | Bajo (dev) | `Access-Control-Allow-Origin: *` solo en local; restringir a dominio en producción |

---

## 2. Whitelist de Bases de Datos ERP

El nombre de la BD ERP **nunca** se interpola directamente del input del usuario. El flujo de validación es:

```
HTTP Request: empresa_id = 3
     ↓
BD: SELECT nombre_bd FROM empresas WHERE id = 3
     ↓ resultado: 'autotec_ecom'
     ↓
PHP: in_array('autotec_ecom', ALLOWED_DATABASES)
     ↓ true → continúa
     ↓ false → HTTP 403 + exit
     ↓
Query: "SELECT ... FROM autotec_ecom.tbl_ventas_devoluciones ..."
```

```php
// Implementación en config/db.php
public static function getErpConnection(string $nombre_bd): PDO
{
    if (!in_array($nombre_bd, ALLOWED_DATABASES, true)) {
        throw new InvalidArgumentException("Base de datos no autorizada: {$nombre_bd}");
    }
    // Solo aquí se usa el string en la conexión
    return new PDO("mysql:host=" . DB_HOST . ";dbname={$nombre_bd};charset=utf8mb4", DB_USER, DB_PASS);
}
```

**Bases de datos en la whitelist:**
```php
define('ALLOWED_DATABASES', [
    'automarc_automarco',
    'autohd_automarcohd',
    'autotec_ecom',
    'gabteccl_sitbdd1978'
]);
```

---

### 3.1 Middleware `config/auth.php` y Funciones de Seguridad

El middleware implementa funciones de validación de tokens, control de acceso basado en roles (RBAC) y mitigación de fuerza bruta (Rate Limiting).

```php
// config/auth.php

// Requerir autenticación válida, hasheada por SHA-256, expiración y rol asignado
function requireAuth(PDO $pdo, array $allowedRoles = []): array {
    if (defined('APP_ENV') && APP_ENV === 'local') {
        return [
            'id' => 1,
            'nombre' => 'Sistema',
            'email' => 'sistema@app.local',
            'rol' => 'ADMINISTRADOR',
            'activo' => 1
        ];
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado. Token no provisto.']);
        exit;
    }
    
    $rawToken = $matches[1];
    $hashedToken = hash('sha256', $rawToken);
    
    $stmt = $pdo->prepare("
        SELECT id, nombre, email, rol, activo 
        FROM usuarios 
        WHERE api_token = :token 
          AND (token_expires_at > NOW() OR token_expires_at IS NULL)
        LIMIT 1
    ");
    $stmt->execute([':token' => $hashedToken]);
    $user = $stmt->fetch();
    
    if (!$user || !$user['activo']) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Sesión expirada o token inválido.']);
        exit;
    }
    
    if (!empty($allowedRoles) && !in_array($user['rol'], $allowedRoles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado. Privilegios insuficientes.']);
        exit;
    }
    
    return $user;
}

// Obtener IP del cliente sanitizando proxies
function getClientIp(): string {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// Control de intentos de inicio de sesión fallidos (Fuerza Bruta)
function checkRateLimit(PDO $pdo, string $email): void {
    $ip = getClientIp();
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM login_attempts 
        WHERE (ip_address = :ip OR email = :email) 
          AND attempted_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)
    ");
    $stmt->execute([':ip' => $ip, ':email' => $email]);
    $attempts = (int)$stmt->fetchColumn();
    
    if ($attempts >= 5) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Demasiados intentos fallidos. Por favor, espere 15 minutos.']);
        exit;
    }
}
```

### 3.2 Generación de Token Segura y Expiración

```php
// api/auth/login.php
// 1. Generar token criptográficamente seguro
$token = bin2hex(random_bytes(32)); 

// 2. Hashear token con SHA-256 para guardar en BD
$hashedToken = hash('sha256', $token);

// 3. Persistir con expiración de 24 horas
$stmtUpdate = $pdo->prepare('
    UPDATE usuarios 
    SET api_token = :token, 
        token_expires_at = DATE_ADD(NOW(), INTERVAL 24 HOUR) 
    WHERE id = :id
');
$stmtUpdate->execute([':token' => $hashedToken, ':id' => $usuario['id']]);
```

### 3.3 Auditoría de Acciones Críticas (`services/AuditService.php`)

Todas las acciones administrativas u operativas sensibles en Tesorería son auditadas a nivel de base de datos bajo transacciones SQL.

```php
// services/AuditService.php
class AuditService {
    public static function log(PDO $pdo, int $userId, string $email, string $accion, string $detalles): void {
        $ip = getClientIp();
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (usuario_id, email, accion, detalles, ip_address, user_agent) 
            VALUES (:uid, :email, :accion, :detalles, :ip, :ua)
        ");
        $stmt->execute([':uid' => $userId, ':email' => $email, ':accion' => $accion, ':detalles' => $detalles, ':ip' => $ip, ':ua' => $ua]);
    }
}
```

---

## 4. Validación de Archivos Subidos

El backend **siempre** re-valida los archivos en el servidor, independiente de la validación del frontend:

```php
// ✅ Validar MIME real del archivo (no confiar en la extensión declarada)
$mime = mime_content_type($fileData['tmp_name']);
$tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic'];

if (!in_array($mime, $tiposPermitidos, true)) {
    throw new InvalidArgumentException('Tipo de archivo no permitido: ' . $mime);
}

// ✅ Validar tamaño (máx 10 MB)
if ($fileData['size'] > 10 * 1024 * 1024) {
    throw new InvalidArgumentException('Archivo supera el tamaño máximo de 10MB');
}

// ✅ Sanitizar nombre de archivo
$ext    = strtolower(pathinfo($fileData['name'], PATHINFO_EXTENSION));
$nombre = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', basename($fileData['name']));
```

---

## 5. Protección del Directorio de Uploads

El directorio `uploads/` **no debe ser ejecutable**. Agregar un `.htaccess` en la raíz de `uploads/`:

```apache
# uploads/.htaccess
Options -Indexes
<FilesMatch "\.(php|php3|php4|php5|phtml|pl|py|jsp|asp|sh|cgi)$">
    Deny from all
</FilesMatch>
```

Esto previene la ejecución de archivos PHP disfrazados de imágenes.

---

## 6. Protección de Archivos de Configuración

```apache
# .htaccess en la raíz del proyecto
<Files "config/*.php">
    Deny from all
</Files>
<Files "*.sql">
    Deny from all
</Files>
```

Los archivos en `config/` y `.sql` no deben ser accesibles directamente por HTTP.

---

## 7. Configuración CORS

```php
// Desarrollo local — permisivo
header('Access-Control-Allow-Origin: *');

// Producción — solo el dominio de la app
header('Access-Control-Allow-Origin: https://dominio.cl');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
```

---

## 8. Consideraciones para Producción

| Acción | Estado | Prioridad |
|--------|--------|-----------|
| Cambiar `APP_ENV = 'production'` | ⏳ Pendiente | Alta |
| Asignar credenciales de BD dedicadas (no `root`) | ⏳ Pendiente | Alta |
| Restringir CORS al dominio real | ⏳ Pendiente | Alta |
| Agregar `.htaccess` en `uploads/` | ⏳ Pendiente | Alta |
| Agregar `.htaccess` para proteger `config/` | ⏳ Pendiente | Alta |
| HTTPS obligatorio (Let's Encrypt o hosting SSL) | ⏳ Pendiente | Alta |
| Rotar credenciales y tokens antes de go-live | ⏳ Pendiente | Alta |
| Validar que `error_reporting` esté en `0` en producción | ⏳ Pendiente | Media |
