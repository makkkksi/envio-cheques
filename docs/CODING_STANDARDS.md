# CODING_STANDARDS.md — Estándares de Código

**Propósito:** Definir las convenciones de código PHP y JavaScript que deben seguirse en todo el proyecto para mantener coherencia y facilitar el mantenimiento asistido por IA.  
**Audiencia:** Desarrolladores, agentes de IA.  
**Referencias:** [`SECURITY.md`](./SECURITY.md) para patrones seguros · [`API.md`](./API.md) para formato de respuestas.

---

## 1. Estructura de un Endpoint PHP

Todos los archivos en `api/` siguen esta estructura fija en el mismo orden:

```php
<?php
// 1. Headers obligatorios (siempre primero)
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');               // ajustar en producción
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// 2. Imports
require_once '../config/app.php';
require_once '../config/db.php';
require_once '../config/auth.php';

// 3. Solo aceptar el método correcto
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

// 4. Autenticación (siempre antes de lógica de negocio)
$usuario_id = getUsuarioActual(); // de config/auth.php

// 5. Captura y validación de entrada
$empresa_id = filter_input(INPUT_POST, 'empresa_id', FILTER_VALIDATE_INT);
if (!$empresa_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'empresa_id requerido']);
    exit;
}

// 6. Lógica de negocio
try {
    $pdo = Database::getCobranzasConnection();
    // ... operaciones
    echo json_encode(['success' => true, 'data' => $resultado]);
} catch (Exception $e) {
    error_log('[guardar_cobranza] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
}
```

---

## 2. Convenciones PHP

### 2.1 Naming

| Elemento | Convención | Ejemplo |
|----------|-----------|---------|
| Variables | `snake_case` | `$empresa_id`, `$rut_cliente` |
| Funciones | `camelCase` | `getUsuarioActual()`, `subirImagen()` |
| Clases | `PascalCase` | `Database`, `MailService` |
| Constantes | `UPPER_SNAKE_CASE` | `ALLOWED_DATABASES`, `APP_ENV` |
| Archivos PHP | `snake_case.php` | `guardar_cobranza.php`, `get_factura.php` |
| Archivos de clase | `PascalCase.php` | `Database.php`, `MailService.php` |

### 2.2 PDO — Reglas obligatorias

```php
// ✅ SIEMPRE usar prepared statements con parámetros nombrados
$stmt = $pdo->prepare(
    'SELECT * FROM cobranzas WHERE empresa_id = :empresa_id AND estado = :estado'
);
$stmt->execute([':empresa_id' => $empresa_id, ':estado' => $estado]);

// ❌ NUNCA concatenar variables del usuario en queries
$sql = "SELECT * FROM cobranzas WHERE empresa_id = $empresa_id"; // PROHIBIDO
```

```php
// ✅ SIEMPRE usar el modo de error PDO::ERRMODE_EXCEPTION
// (configurado en Database::getCobranzasConnection())
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
```

### 2.3 Transacciones SQL

```php
// Patrón obligatorio para operaciones multi-tabla
$archivosSubidos = [];
try {
    $pdo->beginTransaction();

    // Operación 1
    $stmt = $pdo->prepare('INSERT INTO cobranzas ...');
    $stmt->execute([...]);
    $cobranza_id = $pdo->lastInsertId();

    // Operación 2
    foreach ($cheques as $cheque) {
        $stmt2 = $pdo->prepare('INSERT INTO cheques ...');
        $stmt2->execute([...]);
    }

    $pdo->commit();

    // Notificación post-commit (no revierte si falla)
    MailService::enviarNotificacion($cobranza, $cheques, $archivosSubidos);

    echo json_encode(['success' => true, 'cobranza_id' => $cobranza_id]);

} catch (Exception $e) {
    $pdo->rollBack();
    // Limpiar archivos ya subidos al disco
    foreach ($archivosSubidos as $ruta) {
        if (file_exists($ruta)) unlink($ruta);
    }
    error_log('[guardar_cobranza] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error al guardar']);
}
```

### 2.4 Manejo de errores

```php
// Usar error_log() para errores internos, NUNCA exponer mensajes de excepción al cliente
error_log('[contexto] ' . $e->getMessage());

// Respuesta al cliente: mensaje genérico
echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);

// Solo en APP_ENV=local, se puede incluir detalle para depuración
if (defined('APP_ENV') && APP_ENV === 'local') {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
```

---

## 3. Manejo de Archivos (Uploads)

```php
// Función de subida de imagen — patrón estándar del proyecto
function subirImagen(array $fileData, string $empresa_id, string $tipo): ?string {
    if ($fileData['error'] !== UPLOAD_ERR_OK) return null;

    $tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/heic'];
    $mime = mime_content_type($fileData['tmp_name']);
    if (!in_array($mime, $tiposPermitidos)) {
        throw new InvalidArgumentException('Tipo de archivo no permitido');
    }

    if ($fileData['size'] > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Archivo demasiado grande (máx 10MB)');
    }

    $ext       = pathinfo($fileData['name'], PATHINFO_EXTENSION);
    $nombre    = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $fileData['name']);
    $carpeta   = UPLOADS_BASE_PATH . "/{$empresa_id}/" . date('Y-m') . "/{$tipo}/";

    if (!is_dir($carpeta)) mkdir($carpeta, 0755, true);

    $rutaFinal = $carpeta . $nombre;
    move_uploaded_file($fileData['tmp_name'], $rutaFinal);

    // Retornar ruta relativa (para guardar en BD)
    return "uploads/{$empresa_id}/" . date('Y-m') . "/{$tipo}/{$nombre}";
}
```

---

## 4. Convenciones JavaScript (Frontend)

### 4.1 Naming

| Elemento | Convención |
|----------|-----------|
| Variables y funciones | `camelCase` |
| Constantes | `UPPER_SNAKE_CASE` |
| IDs del DOM | `camelCase` (ej: `btnAgregarCheque`, `lblTotalCheques`) |
| Clases CSS en JS | Solo agregar/remover, nunca escribir estilos inline desde JS |

### 4.2 Patrón fetch() estándar

```javascript
// Todas las llamadas a la API siguen este patrón
async function buscarFactura(empresaId, numeroFactura) {
    try {
        const response = await fetch(
            `api/get_factura.php?empresa_id=${empresaId}&numero_factura=${numeroFactura}`
        );
        const data = await response.json();

        if (!response.ok || !data.success) {
            showToast(data.message || 'Error al buscar la factura', 'error');
            return null;
        }
        return data.data;
    } catch (err) {
        showToast('Error de conexión. Verifique su red.', 'error');
        return null;
    }
}
```

### 4.3 Debounce para búsqueda de factura

```javascript
// Esperar 600ms después de que el usuario deja de escribir
let debounceTimer;
numFacturaInput.addEventListener('input', (e) => {
    clearTimeout(debounceTimer);
    const val = e.target.value.replace(/\D/g, '');
    if (val.length < 4) return;

    debounceTimer = setTimeout(() => {
        buscarFactura(empresaVendedor.value, val);
    }, 600);
});
```

### 4.4 Estado de carga del botón submit

```javascript
// Siempre deshabilitar el botón durante el submit para evitar doble envío
const btnSubmit = formCobranza.querySelector('.btn-submit');
btnSubmit.disabled = true;
btnSubmit.textContent = 'Guardando...';
// Restaurar en el finally
btnSubmit.disabled = false;
btnSubmit.textContent = 'Registrar Cobranza';
```

---

## 5. Formato de Respuesta JSON — Reglas Estrictas

Todos los endpoints deben retornar exactamente uno de estos dos shapes:

```json
// ÉXITO
{
  "success": true,
  "data": { },           // objeto con los datos retornados
  "message": "...",      // mensaje opcional para el usuario
  "cobranza_id": 123     // campos específicos adicionales si aplica
}

// ERROR
{
  "success": false,
  "message": "Mensaje descriptivo para el usuario",
  "errors": [ ]          // array de errores de validación (opcional)
}
```

- `success` es **siempre booleano**.
- `data` solo se incluye en respuestas de éxito.
- `errors` solo se incluye cuando hay múltiples errores de validación.
- Los mensajes deben estar en **español**.
- Nunca retornar stack traces ni paths del servidor en producción.

---

## 6. Archivos de Configuración

```php
// config/app.php — Único lugar donde se definen constantes de entorno
define('APP_ENV', 'local');                  // 'local' | 'production'
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('AUTH_BYPASS_USER_ID', 1);
define('UPLOADS_BASE_PATH', __DIR__ . '/../uploads');
define('UPLOADS_BASE_URL', 'http://localhost/form/uploads');
define('ALLOWED_DATABASES', [
    'automarc_automarco',
    'autohd_automarcohd',
    'autotec_ecom',
    'gabteccl_sitbdd1978'
]);
define('MAIL_HOST', '');
define('MAIL_PORT', 587);
define('MAIL_USER', '');
define('MAIL_PASS', '');
define('MAIL_FROM', 'cobranzas@dominio.cl');
```

> Nunca commitear credenciales reales. En producción, usar variables de entorno o un archivo `.env` excluido del repositorio.
