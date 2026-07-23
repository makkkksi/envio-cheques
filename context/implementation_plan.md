# Backend PHP — Módulo de Cobranzas (Fase 1) — Plan Actualizado

Implementar la capa backend en PHP puro (PDO) que conecte el frontend ya maquetado con las bases de datos del holding, reemplazando todos los datos mock del JS por llamadas `fetch()` reales.

---

## 🗺️ Mapa de Actores y Acceso (Decisión Confirmada)

| Actor | Acceso | Herramienta | Alcance |
|-------|--------|--------------|---------|
| **Vendedor** | App Android (embebida) | Esta app web | Registrar cobranzas, ver su historial propio |
| **Tesorería** | Email → Link | Portal web separado | Gestionar estados, registrar depósitos, rechazos |
| **Admin** | Portal web | Portal web separado | Configuración, usuarios, reportes |

> [!IMPORTANT]
> **La app actual es exclusivamente para vendedores.** La pestaña "Ver Cheques Enviados" mostrará únicamente el historial del vendedor logueado (read-only). Tesorería NO accede a esta app.

> [!NOTE]
> **Portal de Tesorería = Fase Final.** El correo que recibe Tesorería incluirá un link único (o al portal) para gestionar el estado del cheque. Ese portal se construye en una fase posterior independiente de este desarrollo.

---

## Decisiones de Arquitectura Confirmadas

| # | Propuesta | Estado | Decisión |
|---|-----------|--------|----------|
| 1 | Whitelist de BDs para seguridad | ✅ Aceptada | `ALLOWED_DATABASES` en `config/app.php` |
| 2 | Carpetas de uploads por empresa/fecha | ✅ Aceptada | `/uploads/{empresa_id}/{YYYY-MM}/` |
| 3 | Usuario sistema / auth | ✅ Definida | Ver sección "Estrategia de Autenticación" abajo |
| 4 | Servicio de correo | ✅ Redefinida | SMTP del host (no Mailtrap). `enviar_mailtrap.php` se convierte en `services/MailService.php` con PHP `mail()` / SMTP nativo |

---

## Estrategia de Autenticación (Prop. 3 — Redefinida)

> [!IMPORTANT]
> El módulo **se consumirá desde una app Android ya existente**. La arquitectura de auth debe diseñarse para esa integración futura sin bloquear el desarrollo actual.

**Estrategia en 3 niveles:**

**Nivel 1 — Ahora (Fase 1, desarrollo local):**
- Se inserta un usuario semilla `id=1` llamado `"Sistema / Guest"` con rol `ADMINISTRADOR` en `usuarios`.
- Todos los registros de `cobranzas` se crean con `vendedor_id = NULL` y `vendedor_nombre = 'Pendiente'`.
- `historial_estados` usa `usuario_id = 1` (usuario Sistema) como placeholder.

**Nivel 2 — Preparación para integración (sin bloquear el MVP):**
- Se crea `api/auth/` con un endpoint `login.php` que recibe `email + password`, valida contra la tabla `usuarios`, y retorna un **JWT** (o token simple en columna `api_token` de la tabla `usuarios`).
- Todos los endpoints `api/` leen el header `Authorization: Bearer {token}` y lo validan. Si no hay token, en desarrollo local se usa el usuario Sistema. En producción, el endpoint rechaza con `401`.
- La app Android solo necesita hacer `POST /api/auth/login.php` con las credenciales del vendedor y guardar el token para adjuntarlo en cada request.

**Nivel 3 — Producción (integración con app Android):**
- La app Android pasa el token JWT del usuario autenticado en su sistema.
- El backend extrae `usuario_id` del token y lo registra correctamente en `cobranzas.vendedor_id` e `historial_estados.usuario_id`.

> [!NOTE]
> **Para el MVP actual:** La columna `api_token VARCHAR(64)` se agrega a `usuarios` en el `setup.sql`. El middleware de auth se crea como función en `config/auth.php` pero **no se activa** (modo bypass). Cuando la app Android esté lista para integrar, se activa con un solo cambio de constante.

---

## Servicio de Correo (Prop. 4 — Redefinida)

> [!NOTE]
> **Se elimina Mailtrap.** El correo se envía usando el servicio SMTP del hosting de producción vía PHP. La estrategia es la siguiente:

- **Capa de abstracción:** `services/MailService.php` es el único punto de envío de correo. Internamente puede cambiar entre backends sin tocar el código del endpoint.
- **En desarrollo local:** Se usa `mail()` de PHP (Laragon/MailHog captura los mails localmente en `http://localhost:8025` si está activo) **o** se configuran las credenciales SMTP reales del hosting en `config/app.php`.
- **En producción:** Se configuran `MAIL_HOST`, `MAIL_USER`, `MAIL_PASS`, `MAIL_PORT` del hosting en `config/app.php`. PHP envía via SMTP autenticado usando **PHPMailer** (librería estándar, instalación vía `composer require phpmailer/phpmailer` o copia manual del archivo si no hay Composer).
- `enviar_mailtrap.php` existente **se conserva pero se marca como legacy** — no se elimina para no romper nada, pero deja de ser el punto de entrada.

> [!IMPORTANT]
> **Pregunta pendiente de producción:** ¿El hosting usa cPanel/Plesk con SMTP autenticado? Cuando migremos a producción necesitaremos: `host`, `puerto` (generalmente 465/587), `usuario` y `contraseña` del correo. En desarrollo local se puede omitir y usar el `mail()` nativo de Laragon.

---

## Estructura de Carpetas Final

```
/form/
├── index.html                    (frontend — sin cambios de estructura)
├── index.php
├── script.js                     [MODIFY] fetch() reales
├── styles.css
├── enviar_mailtrap.php           [LEGACY] se conserva, no se usa en el flujo nuevo
│
├├── context/
│   ├── CONTEXT.md
│   └── MVP_Gestion_Cobranza_Cheques.md
│
└── [FASE FINAL — Portal Tesorería]
    └── admin/                        ← aún no se construye
        ├── index.php                 (consola web desktop-first)
        ├── api/cambiar_estado.php    (POST — actualiza historial_estados)
        └── api/get_cobranzas.php    (GET — listado completo con filtros)
│
├── config/
│   ├── app.php                   [NEW] Constantes: DB, SMTP, uploads, whitelist BDs
│   ├── db.php                    [NEW] Clase Database — PDO central + multi-ERP
│   ├── auth.php                  [NEW] Middleware JWT (bypass en dev, activo en prod)
│   └── setup.sql                 [NEW] DDL completo + seeders + usuario Sistema
│
├── api/
│   ├── get_factura.php           [NEW] GET — busca factura en ERP + retorna JSON
│   ├── guardar_cobranza.php      [NEW] POST multipart — guarda cobranza completa
│   └── auth/
│       └── login.php             [NEW] POST — autenticación, retorna token
│
├── services/
│   └── MailService.php           [NEW] Clase SMTP — reemplaza enviar_mailtrap.php
│
└── uploads/
    └── {empresa_id}/
        └── {YYYY-MM}/
            ├── cheques/          fotos de cheques
            └── comprobantes/     fotos de comprobantes y firmas
```

---

## Proposed Changes

### Config Layer

---

#### [NEW] [config/app.php](file:///c:/laragon/www/form/config/app.php)
Único archivo de configuración de entorno. Contiene:
- Credenciales DB: `DB_HOST = 'localhost'`, `DB_USER = 'root'`, `DB_PASS = ''`
- `UPLOADS_BASE_PATH` → ruta absoluta al directorio `uploads/`
- `UPLOADS_BASE_URL` → URL pública para construir links a imágenes
- `ALLOWED_DATABASES` → array whitelist con los 4 nombres de BDs ERP
- Credenciales SMTP: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS`, `MAIL_FROM`
- `APP_ENV = 'local'` → controla si el middleware auth está activo o en bypass
- `AUTH_BYPASS_USER_ID = 1` → id del usuario Sistema usado en modo bypass

#### [NEW] [config/db.php](file:///c:/laragon/www/form/config/db.php)
Clase `Database` con métodos estáticos:
- `getCobranzasConnection(): PDO` → conexión a `bd_modulo_cobranzas`
- `getErpConnection(string $nombre_bd): PDO` → valida contra `ALLOWED_DATABASES` antes de conectar; lanza `InvalidArgumentException` si la BD no está en la whitelist

#### [NEW] [config/auth.php](file:///c:/laragon/www/form/config/auth.php)
Función `getUsuarioActual(): int` que:
- Si `APP_ENV = 'local'` → retorna `AUTH_BYPASS_USER_ID` (modo bypass, sin validación)
- Si `APP_ENV = 'production'` → lee el header `Authorization: Bearer {token}`, valida el token contra la columna `api_token` de `usuarios`, retorna `usuario_id` o responde `401`

#### [NEW] [config/setup.sql](file:///c:/laragon/www/form/config/setup.sql)
Script SQL completo:
1. `CREATE DATABASE bd_modulo_cobranzas`
2. Todas las tablas DDL (del CONTEXT.md)
3. Agrega columna `api_token VARCHAR(64) NULL` a `usuarios`
4. Seeders: 4 empresas del holding
5. Usuario Sistema: `INSERT INTO usuarios (id=1, nombre='Sistema', email='sistema@app.local', rol='ADMINISTRADOR')`

---

### API Endpoints

---

#### [NEW] [api/get_factura.php](file:///c:/laragon/www/form/api/get_factura.php)
- **Método:** `GET`
- **Parámetros:** `empresa_id` (int), `numero_factura` (string, mínimo 4 dígitos)
- **Flujo:**
  1. Valida parámetros → `400` si faltan
  2. Consulta `bd_modulo_cobranzas.empresas` por `empresa_id` → obtiene `nombre_bd`
  3. Valida `nombre_bd` contra whitelist → `403` si no está permitida
  4. Ejecuta query cross-DB:
     ```sql
     SELECT v.factura, v.cliente_rut, c.cli_razon_social,
            c.cli_mail, ROUND(SUM(v.neto_item * 1.19)) AS monto_total_factura
     FROM {nombre_bd}.tbl_ventas_devoluciones v
     INNER JOIN {nombre_bd}.tbl_clientes c ON v.cliente_rut = c.cli_rut
     WHERE v.factura = :numero_factura
     GROUP BY v.factura, v.cliente_rut, c.cli_razon_social, c.cli_mail
     ```
  5. Retorna `{ "success": true, "data": { ... } }` o `{ "success": false, "message": "Factura no encontrada" }`

#### [NEW] [api/guardar_cobranza.php](file:///c:/laragon/www/form/api/guardar_cobranza.php)
- **Método:** `POST multipart/form-data`
- **Payload:**
  - Cabecera: `empresa_id`, `numero_factura`, `rut_cliente`, `razon_social_cliente`, `monto_total_factura`, `email_cliente`, `email_tesoreria`, `tipo_entrega`, `numero_seguimiento`
  - Arrays: `banco[]`, `numero_cheque[]`, `monto_cheque[]`, `fecha_vencimiento[]`, `comentario_cheque[]`
  - Files: `foto_cheque[]`, `foto_comprobante` (Chilexpress), `foto_firma` (Santiago)
- **Flujo con transacción:**
  1. `getUsuarioActual()` → `$usuario_id`
  2. Valida campos requeridos
  3. Sube imágenes a `/uploads/{empresa_id}/{YYYY-MM}/cheques/` y `/comprobantes/`
  4. `beginTransaction()`
  5. `INSERT INTO cobranzas` → `$cobranza_id`
  6. Loop cheques: `INSERT INTO cheques` (incluye `comentario`)
  7. `INSERT INTO historial_estados` (estado inicial según `tipo_entrega`)
  8. `commit()` → llama `MailService::enviarNotificacion()`
  9. `rollBack()` + borrado de archivos si hay error
- **Respuesta:** `{ "success": true, "cobranza_id": 123, "message": "..." }`

#### [NEW] [api/auth/login.php](file:///c:/laragon/www/form/api/auth/login.php)
- **Método:** `POST`
- **Body JSON:** `{ "email": "...", "password": "..." }`
- **Flujo:** valida contra `usuarios`, genera token `bin2hex(random_bytes(32))`, guarda en `api_token`, retorna `{ "success": true, "token": "...", "usuario": { id, nombre, rol } }`
- Preparado para la app Android desde el primer día

---

### Services

---

#### [NEW] [services/MailService.php](file:///c:/laragon/www/form/services/MailService.php)
Clase con método estático `enviarNotificacion(array $cobranza, array $cheques, array $rutasAdjuntos): bool`.
- Usa **PHPMailer** (incluido como archivo único en `services/vendor/` si no hay Composer, o vía `require_once`)
- Configura SMTP con las constantes de `config/app.php`
- Envía correo a Tesorería **y** al cliente (si tiene email)
- Si falla → `error_log()` + retorna `false` (no hace rollback de la cobranza)
- HTML del correo: tabla de cheques con comentarios, datos de factura, tipo de entrega

---

### Frontend JS

---

#### [MODIFY] [script.js](file:///c:/laragon/www/form/script.js)
- **Búsqueda de factura:** `debounce` de 600ms sobre el evento `input` de `numFactura` que llama a `fetch('api/get_factura.php?empresa_id=X&numero_factura=Y')` y rellena el card del cliente con la respuesta real
- **Submit del formulario:** construye `FormData` real y hace `fetch('api/guardar_cobranza.php', { method: 'POST', body: formData })`. Muestra estado de carga en el botón submit.
- **Vista de seguimiento (Tab 2):** Cambia de mock data a `fetch('api/get_mis_cobranzas.php')` que retorna **solo las cobranzas del vendedor logueado** (read-only). Sin botones de acción de estado. El vendedor puede ver en qué estado están sus envíos.
- El select `#empresaVendedor` cambiará de `value="Automarco LTDA"` a `value="1"` (empresa_id numérico) para que `get_factura.php` funcione correctamente

#### [NEW] [api/get_mis_cobranzas.php](file:///c:/laragon/www/form/api/get_mis_cobranzas.php)
- **Método:** `GET`
- **Descripción:** Retorna las cobranzas del vendedor autenticado (`vendedor_id` del token). En modo bypass local, retorna todas.
- **Parámetros opcionales:** `estado`, `empresa_id`, `busqueda` (para los filtros del frontend)
- **Respuesta:** Array de cobranzas con sus cheques anidados, estado actual y fecha

---

## Verification Plan

### Automated Tests
- Ninguno automatizado en esta fase.
- Endpoints verificables con `curl` o directamente desde el navegador.

### Manual Verification
1. Ejecutar `config/setup.sql` en phpMyAdmin de Laragon → confirmar `SHOW TABLES` muestra las 5 tablas.
2. `GET http://localhost/form/api/get_factura.php?empresa_id=1&numero_factura=440265` → JSON con datos del cliente o "no encontrado".
3. Enviar formulario completo con foto de prueba → verificar registro en `cobranzas`, `cheques`, `historial_estados` en phpMyAdmin y archivos en `/uploads/`.
4. Verificar correo en MailHog local (`http://localhost:8025`) o en inbox del SMTP de host.
5. `POST http://localhost/form/api/auth/login.php` con credenciales del usuario Sistema → retorna token.
