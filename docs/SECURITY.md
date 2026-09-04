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

| Action | Estado | Prioridad |
|--------|--------|-----------|
| Cambiar `APP_ENV = 'production'` | ⏳ Pendiente | Alta |
| Asignar credenciales de BD dedicadas (no `root`) | ⏳ Pendiente | Alta |
| Restringir CORS al dominio real | ⏳ Pendiente | Alta |
| Agregar `.htaccess` en `uploads/` | ⏳ Pendiente | Alta |
| Agregar `.htaccess` para proteger `config/` | ⏳ Pendiente | Alta |
| HTTPS obligatorio (Let's Encrypt o hosting SSL) | ⏳ Pendiente | Alta |
| Rotar credenciales y tokens antes de go-live | ⏳ Pendiente | Alta |
| Validar que `error_reporting` esté en `0` en producción | ⏳ Pendiente | Media |

---

## 9. Auditoría de Brechas e Inconsistencias de Datos Internos

**Contexto del Entorno Legado:**  
El sistema se integra con una App Android legada (Eclipse) y bases de datos MySQL directas del ERP Softland. Dichos sistemas legados históricamente operan con bajo nivel de aislamiento (parámetros GET planos sin firma digital). No obstante, para evitar que este nuevo módulo sea el eslabón débil de la infraestructura, se auditan y priorizan los siguientes hallazgos:

| ID | Hallazgo | Gravedad | Impacto / Riesgo | Estado de Mitigación |
|---|---|---|---|---|
| **SEC-01** | **Impersonación de Vendedor (IDOR en `vendedor_id`)** | 🔴 **ALTA** | Endpoints de vendedores (`get_clientes.php`, `get_facturas_cliente.php`, `guardar_cobranza.php`, `get_mis_cobranzas.php`, `completar_envio.php`) aceptaban `vendedor_id` por GET/POST sin forzar la sesión. | ✅ **Implementado.** En `APP_ENV === 'production'`, todos los endpoints extraen estrictamente la identidad desde `$_SESSION['vendedor_auth']`, validan cartera de clientes autorizada y limpian la URL en frontend (`history.replaceState`). |
| **SEC-02** | **Descalce de Auth en Admin (Sesión vs Bearer Token)** | 🔴 **ALTA** *(Bloqueante)* | `admin/index.php` autentica con `$_SESSION['admin_logged_in']`, pero los endpoints de `/admin/api/` invocan `requireAuth()` que exige `Authorization: Bearer`. Funciona solo por el bypass de `APP_ENV='local'`. En `production` el portal dará error `401` en todas las acciones. | ✅ **Implementado.** Middleware unificado en `config/auth.php` con soporte dual para `$_SESSION['admin_logged_in']` y token Bearer. |
| **SEC-03** | **Ejecución Directa en `/uploads`** | 🟡 **MEDIA-ALTA** | Si bien se filtran extensiones y MIME types en PHP, no existía restricción a nivel de servidor web en `uploads/`. Un archivo PHP maliciosamente cargado podría ejecutarse. | ✅ **Implementado.** Archivo `uploads/.htaccess` con `php_flag engine off` y `Deny from all` para scripts ejecutables. |
| **SEC-04** | **Falta de Re-Validación de Cuotas en Backend** | 🟡 **MEDIA** | `guardar_cobranza.php` confiaba en los montos de facturas/cuotas enviados desde el cliente sin contrastar la suma contra la deuda oficial en `bd_automarco.tbl_cobranza`. | ✅ **Implementado.** Re-validación transaccional con `GET_LOCK`, verificación contra `tbl_cobranza` y bloqueo de duplicados activos. |

---

### Estrategia de Mitigación Gradual:

1. **Fase Inmediata (MVP / Local):**  
   - Mantener `APP_ENV = 'local'` durante pruebas de usabilidad y feedback de UI/UX.
   - Mantener la experiencia fluida en la App Eclipse (WebView recibe `vendedor_id` por parámetro URL con fallback de sesión).

2. **Fase Pre-Producción / Producción (Go-Live):**  
   - ✅ Unificado middleware en `config/auth.php` para resolver **SEC-02** (Sesión PHP + Bearer).
   - ✅ Creado `uploads/.htaccess` para resolver **SEC-03** (RCE Protection).
   - ✅ Implementado blindaje integral contra IDOR en todos los endpoints de vendedores (**SEC-01**).
   - ✅ Implementada re-validación de saldos y cuotas ERP en backend (**SEC-04**).

---

## 10. Historial de Ajustes de Auditoría de Código (Julio 2026)

Durante la auditoría general de seguridad y rendimiento de Julio de 2026, se aplicaron y documentaron los siguientes ajustes de hardening:

### 10.1 Protección contra Pérdida de Datos en SMTP (`api/completar_envio.php`)
* **Medida:** Se aislaron las acciones post-commit del bloque `try-catch` principal de base de datos.
* **Comportamiento:** Si la transacción de base de datos se confirma exitosamente (`$pdo->commit()`), cualquier falla posterior en la llamada SMTP de `MailService::enviarNotificacion` no causará la eliminación (`unlink`) de las fotos de los cheques (evitando la pérdida irreversible de archivos físicos). La llamada de correo ahora es de tipo *best-effort* y fallará con advertencias en la respuesta en lugar de códigos de error 500.

### 10.2 Alerta de Bypass de Autenticación en Entorno de Desarrollo (`config/auth.php`)
* **Medida:** Se agregó registro de seguridad explícito en el archivo de log del servidor.
* **Comportamiento:** Cuando la aplicación se ejecuta con `APP_ENV = 'local'` y se invoca un bypass silencioso para otorgar privilegios de `ADMINISTRADOR` sin token Bearer, el sistema imprime un registro de advertencia `[SECURITY WARNING]` en el log de errores. Esto previene que una mala configuración involuntaria de `APP_ENV` en producción pase desapercibida para el departamento de TI.

### 10.3 Hardening de Seguridad HTTP (OWASP ZAP)
* **Medida:** Se añadieron encabezados de seguridad globales en `.htaccess` (CSP, HSTS, X-Frame-Options, X-Content-Type-Options, Referrer-Policy, y se ocultó X-Powered-By/Server).
* **Comportamiento:** Mitiga ataques de Clickjacking, MIME-sniffing, y fuerza conexiones HTTPS seguras. La directiva Content Security Policy (CSP) fue configurada restrictivamente (`default-src 'self'`). Las dependencias de Google Fonts se descargaron localmente para prescindir del SRI, y se removió cualquier uso de `unsafe-inline` en scripts y estilos (refactorizados hacia clases CSS y Event Listeners en JavaScript puro).
* **Cookies de Sesión:** En producción o HTTPS, todas las instancias de inicialización de sesión (`session_set_cookie_params`) exigen `secure=true`. En Laragon HTTP local el flag se adapta a `false` para que el navegador pueda reenviar la cookie durante las pruebas. El portal Admin aplica `SameSite=Strict`; el WebView móvil usa `SameSite=Lax` para navegación intra-app.

## 11. Sesión Administrativa y RBAC Granular (Agosto 2026)

- `startSecureSession()` centraliza cookies `HttpOnly`, `use_only_cookies` y `use_strict_mode`; el flag `Secure` es obligatorio en producción/HTTPS y se adapta únicamente al entorno HTTP local de Laragon.
- Admin y vendedor usan nombres de cookie independientes. Admin aplica `SameSite=Strict`; vendedor usa `SameSite=Lax` para WebView. El logout de un contexto no puede destruir el otro.
- `session.gc_maxlifetime` se fija en 24 horas, por encima de los TTL lógicos. Admin expira tras 12 horas inactivo o 16 horas absolutas; vendedor tras 12 horas inactivo o 24 horas absolutas. Los valores pueden ajustarse con variables de entorno sin modificar código.
- Los heartbeats sólo renuevan sesiones válidas y las cookies se reemiten como máximo cada cinco minutos. El frontend reintenta una única vez el request que recibió `401`, después de verificar o reconstruir la sesión; nunca entra en ciclos de reautenticación.
- Al autenticar se regenera el ID y se almacenan `admin_user_id`, `admin_user_nombre`, `admin_user_email`, `admin_user_rol` y `admin_last_activity`.
- Cada request administrativo vuelve a consultar `usuarios` por ID. Una baja lógica o cambio de rol invalida o actualiza los privilegios de la sesión sin esperar un nuevo login.
- `requirePermission()` aplica la matriz granular también en APIs. Los permisos de interfaz son sólo una ayuda visual, nunca el control de seguridad principal.
- Las mutaciones administrativas y el logout exigen `X-CSRF-Token`, ligado a la sesión y comparado con `hash_equals()`.
- La gestión de usuarios sólo permite `ADMINISTRADOR`, usa `PASSWORD_BCRYPT`, impide la autodesactivación y garantiza al menos un administrador activo.

## 12. Seguridad del Módulo de Rendiciones

- `requireSellerContext()` deriva vendedor y empresa exclusivamente desde `$_SESSION['vendedor_auth']`; los payloads no pueden sobrescribirlos.
- Tras limpiar los parámetros sensibles de la URL, el navegador conserva temporalmente en `sessionStorage` la pareja vendedor/empresa validada. Este respaldo desaparece al cerrar la pestaña y sólo se usa para volver a ejecutar `auth_seller.php`, que revalida la identidad contra ERP.
- La sesión guarda `empresa_id` central además del `vend_cod`, evitando colisiones de códigos ERP entre razones sociales.
- Las mutaciones del vendedor y de administración requieren CSRF ligado a la sesión.
- Las fotos se validan por MIME real con `finfo`, límite de 10 MB, nombre aleatorio y ruta generada por servidor.
- `document_hash` tiene índice único y los errores de duplicidad se transforman en respuesta `409` sin exponer SQL.
- El Magic Token se almacena hasheado, expira y se consume con bloqueo de fila. Los enlaces `GET` sólo abren una confirmación; la decisión usa `POST`.
- Los destinatarios de excesos se administran en `aprobadores_rendiciones`; nunca se incorporan correos personales al código ni a la URL. El backend ignora descriptores enviados por el navegador y vuelve a cargar el responsable activo por `id`.
- Cada envío rota un token aleatorio de 32 bytes, persiste sólo su SHA-256, snapshot del destinatario, emisor y fecha. El `GET` únicamente muestra antecedentes; la decisión se registra por `POST` con CSRF, bloqueo de fila, expiración y consumo único.
- El rechazo de un exceso por Tesorería requiere `rendiciones.manage`, CSRF y motivo obligatorio. Se ejecuta con bloqueo transaccional, libera el compromiso y marca como consumido cualquier token ya emitido antes de pasar a `RECHAZADA`.
- El comprobante PDF administrativo exige `rendiciones.view`, valida el `id`, usa exclusivamente consultas preparadas y sólo existe para decisiones `APROBADO`. No incluye el Magic Token ni correos; su firma se deriva de snapshots inmutables de nombre/cargo y añade un código SHA-256 abreviado de verificación.
- Todas las transiciones críticas insertan historial y las acciones administrativas también se registran en `audit_logs`.

---

## 13. Hardening Preproducción: Zero Delete, Named Parameters y Error Sanitization (Septiembre 2026)

- **Zero Delete en Cheques:** Eliminación física estrictamente prohibida en tablas de negocio. La tabla `cheques` opera mediante baja lógica (`activo = 0`, `descartado_at = NOW()`, `descartado_por = :user_id`, `motivo_descarte = :motivo`). Las fotos de cheques descartados no se eliminan físicamente en el flujo de edición, garantizando trazabilidad y permitiendo que el cron de purga (`cron/purgar_fotos_cheques_vencidos.php`) las procese según su ciclo de vida (>3 meses post-vencimiento).
- **Parámetros Nombrados en PDO:** Todas las consultas preparadas en `api/`, `admin/api/`, `services/` y `cron/` utilizan exclusivamente placeholders con nombre (`:param`), eliminando por completo los placeholders posicionales (`?`) para prevenir descalces de parámetros y aumentar la mantenibilidad.
- **Sanitización Integral de Respuestas API:** Ningún endpoint expone `$e->getMessage()`, rutas del servidor ni trazas de excepción en respuestas HTTP 500. Todos los errores inesperados o de BD se registran en `error_log()` en el servidor y responden al cliente con mensajes neutros y seguros: `{ "success": false, "message": "..." }`.
- **Zero Emojis en Frontend:** Todas las interfaces web (`admin/`, `rendiciones/`, portal público) eliminan el uso de emojis unicode en favor de SVGs vectoriales accesibles, entidades HTML o texto profesional neutro, evitando problemas de renderizado heterogéneo entre plataformas móviles y de escritorio.
- **Verificación Continua de Despliegue (`scripts/verify_release.php`):** Script automatizado de release que audita sintaxis PHP (109 archivos), contrato de esquema (17 tablas), contrato de seguridad global (Zero Delete, Named Parameters, Error Sanitization en 56 archivos backend), validación de frontend sin emojis (37 archivos) y paridad SHA-256 estricta entre la raíz y la réplica `dist/cheques_cobranza/app/`.

## 14. Política Centralizada de Notificaciones por Correo a Vendedores e Inmutabilidad (Septiembre 2026)

- **Notificaciones a Vendedores Deshabilitadas en Todos los Entornos:** Por decisión estricta de negocio, actualmente el sistema no debe enviar correos a vendedores bajo ninguna circunstancia, ni en entorno local ni en producción. Esto se controla mediante la política centralizada `MAIL_SELLER_NOTIFICATIONS_ENABLED = false` definida por defecto en `config/app.php` y replicada en `dist/cheques_cobranza/app/config/app.php`.
- **Correos Internos y Administrativos Habilitados:** Los correos dirigidos a aprobadores, responsables de Gerencia/Jefatura, Tesorería, Cuentas Corrientes, Digitadoras y destinatarios administrativos configurados continúan habilitados y operativos en producción.
- **Guard Local Inquebrantable:** En `APP_ENV=local`, ningún correo SMTP real es despachado hacia el exterior. `MailService::sendSmtp()` simula éxito de forma controlada y registra el evento en logs, garantizando cero tráfico externo involuntario y permitiendo pruebas locales fluidas sin timeouts ni caídas.
- **Omisión Segura y Auditoría sin Fuga de Información:** Cuando se invoca un método de notificación a vendedor estando la política deshabilitada (`isSellerNotificationEnabled() === false`), el sistema omite el despacho SMTP, registra en `error_log` un aviso neutro (`Notificación a vendedor omitida por política`) sin exponer nombre, dirección de correo, token de acceso, enlace mágico ni cuerpo HTML, y retorna `true` para no romper la transacción ni marcar el flujo financiero como fallido.
- **Inmutabilidad de Cheques Dados de Baja:** Los cheques con `activo = 0` son estrictamente inmutables para operaciones ordinarias. Todas las consultas de edición, cambio de estado y cálculo exigen `AND activo = 1` o `AND (activo = 1 OR activo IS NULL)`. Un cheque inactivo no puede cambiar monto, fotografía ni fecha, no recibe número de depósito, no suma en totales y no puede reactivarse accidentalmente.

## 15. Handoff Seguro por POST y Bloqueo de Parámetros Legacy en Producción (Septiembre 2026)

- **Eliminación de Parámetros de Identidad en URL:** La identidad del vendedor (`vendedor_id`, `empresa`, `vendedor_nombre`) ya no se transmite vía query string en las URLs de Cheques ni de Rendiciones.
- **Endpoint Dedicado `api/seller_handoff.php`:**
  - Acepta exclusivamente peticiones `POST` (peticiones `GET` o cualquier otro método son rechazadas con HTTP 405).
  - Recibe `session_token`, `empresa` y `destino`.
  - Valida el destino contra una lista cerrada estricta: `cheques` (`/index.html`) o `rendiciones` (`/rendiciones/vendedor.php`). Cualquier otro valor es rechazado con HTTP 400.
- **Verificación de Sesión Comercial de Solo Lectura:**
  - Consulta `web_sesiones JOIN web_usuarios` en la BD ERP correspondiente según la empresa.
  - Verifica que el usuario tenga `rol = 'vendedor'`, `activo = 1` y un `vend_cod` numérico positivo válido.
  - Si no existe sesión activa o está vencida, responde HTTP 401 sin exponer stack traces ni detalles internos.
- **Establecimiento de Sesión y Redirección Limpia:**
  - Ejecuta `startSellerSession()` y `session_regenerate_id(true)` para mitigar fijación de sesión.
  - Genera o renueva el token CSRF (`$_SESSION['csrf_token']`).
  - Redirige al navegador mediante código **HTTP 303 (See Other)** hacia la ruta fija destino, garantizando que no viajen tokens, identificadores ni nombres en la cabecera `Location` ni en la barra de direcciones.
  - Emite encabezados anti-caché: `Cache-Control: no-store, no-cache, must-revalidate`, `Pragma: no-cache` y `Referrer-Policy: no-referrer`.
- **Reutilización Idempotente:** El mismo token de sesión comercial activo del portal de vendedores puede utilizarse repetidamente para navegar a los módulos sin bloqueo artificial ni consumo de un solo uso.
- **Auditoría Segura en `audit_logs`:**
  - El evento `SELLER_HANDOFF` registra en `detalles` únicamente `empresa_id`, `vendedor_id`, `destino` y `resultado: SUCCESS`.
  - **Prohibición Absoluta:** Nunca se guarda el `session_token` crudo, hashes del token, contraseñas ni datos sensibles en la base de datos ni en archivos de registro.
- **Bloqueo de Acceso Legacy en Producción:**
  - En `APP_ENV=production`, `api/auth_seller.php` bloquea inmediatamente (HTTP 401) cualquier intento de autenticación que contenga `vendedor_id`, `vendedor`, `vendedor_nombre` o `empresa` en `$_GET`.
  - En `APP_ENV=local`, este acceso se permite exclusivamente para facilitar pruebas de desarrollo, emitiendo una advertencia segura en `error_log`.
- **Alcance y Desacople de Android:** La aplicación `android/Autotec_Grande` no forma parte de esta implementación; su autenticación queda fuera de alcance y no constituye bloqueo para el despliegue a producción.
- **Garantía Read-Only sobre Bases ERP:** Ningún componente del handoff ni del directorio de vendedores ejecuta escrituras (`INSERT`, `UPDATE`, `DELETE`, `ALTER`) en las cuatro bases ERP (`automarc_automarco`, `autohd_automarcohd`, `autotec_ecom`, `gabteccl_sitbdd1978`).

---

## 16. Validación Estricta de vend_cod y Pruebas HTTP Reales de Handoff (Septiembre 2026)

### 16.1 Validación Canónica Estricta de `vend_cod`
Para prevenir descalces de identidad, coerción implícita de tipos en MySQL y comportamientos no deterministas en `CAST(vend_cod AS UNSIGNED)`:
- **Validación en SQL antes de cualquier CAST:**
  ```sql
  TRIM(vend_cod) REGEXP '^[1-9][0-9]*$'
  ```
  Asegura que el campo contenga exclusivamente dígitos decimales sin signo, comenzando por un dígito del 1 al 9.
- **Rechazo Expreso de Ceros a la Izquierda (`0012`):**
  Para garantizar una **representación canónica única** por vendedor y evitar que un mismo código se ingrese como `0012` y `12`, los valores con ceros a la izquierda son estrictamente rechazados tanto en SQL como en PHP.
- **Resolución Exacta sin Coerción Implícita de MySQL:**
  En búsquedas y resoluciones por `vendedor_id`, la comparación en SQL utiliza:
  ```sql
  AND TRIM(vend_cod) = :vend_cod
  ```
  donde `:vend_cod` es una cadena canónica normalizada, evitando que MySQL convierta `12abc` o `12.0` en coincidencias automáticas.
- **Validación Defensiva en PHP (`validateVendCod`):**
  A nivel de aplicación se ejecuta:
  ```php
  $raw = trim((string)$value);
  if (!preg_match('/^[1-9][0-9]*$/D', $raw)) {
      return null;
  }
  // Rango 32-bit con signed INT (1 a 2147483647)
  if (strlen($raw) > 10 || (int)$raw > 2147483647) {
      return null;
  }
  ```
- **Matriz de Valores No Admitidos:**
  - `0` (cero): Rechazado.
  - Negativos (`-12`): Rechazados.
  - Signo más (`+12`): Rechazado.
  - Decimales (`12.5`): Rechazados.
  - Notación científica (`1e2`): Rechazada.
  - Ceros a la izquierda (`0012`): Rechazado (mantiene canonicidad única).
  - Alfanuméricos (`12abc`, `abc12`): Rechazados.
  - Cadenas vacías o espacios (`""`, `"   "`): Rechazadas.
  - Enteros desbordados (`99999999999999999999`, `2147483648`): Rechazados.

### 16.2 Prueba HTTP Real de Integración y Transaccionalidad
- **Aislamiento de Entorno de Prueba:** El test de integración (`scratch/test_http_handoff.php`) arranca un servidor PHP CLI dedicado en un puerto efímero libre.
- **Protección Inquebrantable de Mocks:** El router de prueba solo opera si `TEST_HTTP_SERVER=1` y `APP_ENV=local`. Si `APP_ENV=production`, `SellerHandoffService::setSessionVerifier()` y `verifySessionToken()` lanzan una excepción fatal inmediata, imposibilitando cualquier bypass en producción.
- **Ciclo Completo Certificado por HTTP Real:**
  - Petición POST real con cURL/Streams (sin auto-redirect).
  - Respuesta HTTP 303 (See Other).
  - `Location` fija y limpia, sin tokens, nombres ni query string de identidad.
  - Emisión de cookie segura `AUTOMARCO_SELLER_SID`.
  - Regeneración obligatoria de Session ID (defensa contra fijación de sesión).
  - Segunda petición GET a `api/auth/session_vendedor.php` con la cookie emitida para validar el contexto autenticado del vendedor.
  - Reutilización exitosa de la sesión comercial sin bloqueo artificial.
  - Códigos de control: GET -> 405; token inválido -> 401; destino no autorizado -> 400; Origin no permitido -> 403.
- **Garantía de Cero Datos Residuales (ROLLBACK):**
  - Toda escritura originada durante los tests (como `AuditService::log` del handoff) se ejecuta dentro de una transacción activa en `bd_modulo_cobranzas`.
  - Se registra un shutdown handler que ejecuta `rollBack()` indefectiblemente al concluir la petición.
  - No se ejecutan `DELETE` físicos para limpiar; el conteo de filas de `audit_logs` antes y después de los tests es estrictamente idéntico.




