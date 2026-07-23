# ARCHITECTURE.md — Arquitectura del Sistema

**Propósito:** Describir la topología completa del sistema: bases de datos, capas de la aplicación, flujo de datos entre actores y estrategia de autenticación.  
**Audiencia:** Desarrolladores backend, agentes de IA, arquitectos.  
**Referencias:** [`DATABASE.md`](./DATABASE.md) para esquema de tablas · [`SECURITY.md`](./SECURITY.md) para detalles de auth.

---

## 1. Visión General

El sistema opera bajo un esquema **Multi-Tenant / Multi-Base de Datos**:

- **4 bases de datos ERP** (una por empresa del holding) — solo lectura.
- **1 base de datos central** `bd_modulo_cobranzas` — lectura/escritura, propiedad del módulo.
- **1 app web** para vendedores, embebida en WebView de app Android.
- **1 portal web futuro** para Tesorería y Administración (Fase 2+).

```
┌─────────────────────────────────────────────────────────────────┐
│               BASES DE DATOS ERP (Solo lectura)                 │
├──────────────────┬──────────────────┬───────────────────────────┤
│ automarc_automarco │ autohd_automarcohd │ autotec_ecom │ gabteccl_sitbdd1978 │
└────────┬─────────┴────────┬──────────┴───────┬─────────────────┘
         │                  │                  │
         └──────────────────┼──────────────────┘
                            │ consultas cross-DB (PHP PDO)
                            ▼
           ┌───────────────────────────────────────┐
           │     bd_modulo_cobranzas (R/W)          │
           │  empresas · usuarios · cobranzas       │
           │  cheques · historial_estados           │
           └───────────────┬───────────────────────┘
                           │
         ┌─────────────────┴─────────────────┐
         │                                   │
         ▼                                   ▼
┌─────────────────┐                ┌──────────────────────┐
│  App Vendedor   │                │  Portal Tesorería     │
│  /form/         │                │  /admin/ [FASE 2]     │
│  (WebView Android)              │  (web desktop-first)  │
└─────────────────┘                └──────────────────────┘
```

---

## 2. Capas de la Aplicación

```
┌──────────────────────────────────────┐
│  FRONTEND (HTML/CSS/JS Vanilla)      │  index.html · script.js · styles.css
├──────────────────────────────────────┤
│  API LAYER (PHP puro)                │  api/*.php  — JSON endpoints
├──────────────────────────────────────┤
│  CONFIG / MIDDLEWARE                 │  config/app.php · db.php · auth.php
├──────────────────────────────────────┤
│  SERVICES                            │  services/MailService.php
├──────────────────────────────────────┤
│  DATA LAYER                          │  MySQL/MariaDB vía PDO
└──────────────────────────────────────┘
```

---

## 3. Topología Multi-Base de Datos

### 3.1 Bases de datos ERP (lectura)

Cada empresa del holding tiene su propia BD con **estructura idéntica de tablas**:

| Empresa (UI) | Nombre BD (servidor) | Acceso |
|---|---|---|
| Automarco LTDA | `automarc_automarco` | Solo lectura |
| HD Automarco S.A | `autohd_automarcohd` | Solo lectura |
| Autotec S.A | `autotec_ecom` | Solo lectura |
| Gabtec S.A | `gabteccl_sitbdd1978` | Solo lectura |

Las tablas relevantes son `tbl_clientes` y `tbl_ventas_devoluciones`. Ver esquema en [`DATABASE.md §2`](./DATABASE.md).

### 3.2 Selección dinámica de BD ERP

El backend determina qué BD ERP consultar en tiempo de ejecución:

```
1. Recibe empresa_id desde el frontend
2. Consulta bd_modulo_cobranzas.empresas WHERE id = empresa_id → obtiene nombre_bd
3. Valida nombre_bd contra ALLOWED_DATABASES (whitelist en config/app.php)
4. Abre conexión PDO a {nombre_bd}
5. Ejecuta query con nombre de BD interpolado (solo si pasó la whitelist)
```

Ver implementación en [`SECURITY.md §2`](./SECURITY.md).

---

## 4. Flujo de Datos Principal

### Flujo 1: Registro de Cobranza (Vendedor)

```
Vendedor (tablet)
  │
  ├─[1]─► Selecciona empresa + escribe N° factura
  │        ↓ GET api/get_factura.php?empresa_id=X&numero_factura=Y
  │        ↓ [Backend consulta BD ERP → devuelve cliente + monto]
  │        ↓ Frontend autocompleta card de cliente
  │
  ├─[2]─► Agrega cheques (banco, N°, monto, fecha, foto, comentario)
  │
  ├─[3]─► Selecciona modalidad (Chilexpress / Santiago) + adjunta foto comprobante
  │
  └─[4]─► Submit formulario
           ↓ POST api/guardar_cobranza.php (multipart/form-data)
           ↓ [Backend: sube fotos → transacción SQL → INSERT cobranza + cheques + historial]
           ↓ [Backend: llama MailService → envía correo a Tesorería + cliente]
           ↓ Frontend: toast de éxito → redirige a historial
```

### Flujo 2: Consulta de Historial (Vendedor)

```
Vendedor (pestaña "Ver Cheques Enviados")
  │
  └─► GET api/get_mis_cobranzas.php (con filtros opcionales)
      ↓ [Backend: consulta cobranzas WHERE vendedor_id = usuario_actual]
      ↓ Devuelve array con cobranzas + cheques anidados
      ↓ Frontend: renderiza tarjetas (read-only, sin acciones de estado)
```

### Flujo 3: Gestión de Estado (Tesorería) — FASE 2

```
Tesorería recibe email → clic en link
  └─► Portal /admin/ → GET admin/api/get_cobranzas.php
      └─► POST admin/api/cambiar_estado.php → INSERT historial_estados
```

---

## 5. Estrategia de Autenticación (3 Niveles)

La auth está diseñada para integrarse con la app Android sin bloquear el desarrollo del MVP.

### Nivel 1 — MVP local (activo ahora)

- Modo bypass activado con `APP_ENV = 'local'` en `config/app.php`.
- Todos los endpoints usan `usuario_id = 1` (usuario "Sistema").
- `cobranzas.vendedor_id = NULL`, `vendedor_nombre = 'Pendiente'`.
- No se valida ningún header de autenticación.

### Nivel 2 — Preparación para Android (código ready, no activo)

- `config/auth.php` contiene la función `getUsuarioActual(): int`.
- Cuando `APP_ENV = 'production'`, la función lee el header `Authorization: Bearer {token}`, valida el token contra `usuarios.api_token` en la BD.
- Si el token es inválido: responde `HTTP 401` con JSON de error.
- La app Android hace `POST api/auth/login.php` → recibe el token → lo adjunta en cada request.

### Nivel 3 — Producción con Android integrada

- La app Android autentica al vendedor en su sistema propio.
- Pasa el token Bearer al módulo web.
- El backend registra `vendedor_id` real en `cobranzas` e `historial_estados`.

```
APP_ENV = 'local'       → bypass, usuario_id = AUTH_BYPASS_USER_ID (1)
APP_ENV = 'production'  → valida header Authorization: Bearer {token}
```

Ver detalles en [`SECURITY.md §3`](./SECURITY.md).

---

## 6. Servicio de Correo

- **Proveedor:** SMTP del hosting de producción (no Mailtrap).
- **Clase:** `services/MailService.php` — único punto de envío.
- **Librería:** PHPMailer (archivo incluido manualmente en `services/vendor/`).
- **Configuración:** Constantes `MAIL_*` en `config/app.php`.
- **Comportamiento ante fallo:** Si el correo falla, **no** se hace rollback de la cobranza. El error se registra en `error_log()`.
- **Destinatarios:** Tesorería de la empresa correspondiente + cliente (si tiene email).
- `enviar_mailtrap.php` es archivo legacy, no forma parte del flujo actual.

---

## 7. Almacenamiento de Archivos

```
uploads/
└── {empresa_id}/         ← separación por empresa para backups
    └── {YYYY-MM}/        ← separación mensual para mantenimiento
        ├── cheques/       ← fotos de cheques (array por cobranza)
        └── comprobantes/  ← foto Chilexpress OT o firma Santiago
```

- Nombre de archivo: `uniqid() . '_' . nombre_original.ext`
- El campo en BD almacena la ruta relativa: `uploads/1/2026-07/cheques/abc123_cheque.jpg`
- Directorio raíz definido en `UPLOADS_BASE_PATH` (ruta absoluta del servidor).

---

## 8. Entorno Local vs Producción

| Parámetro | Local (Laragon) | Producción |
|-----------|-----------------|------------|
| `APP_ENV` | `local` | `production` |
| `DB_HOST` | `localhost` | IP/hostname del hosting |
| `DB_USER` | `root` | usuario dedicado |
| `DB_PASS` | *(vacío)* | contraseña segura |
| Auth middleware | Bypass (usuario Sistema) | Validación JWT activa |
| Correo | `mail()` PHP nativo / MailHog | SMTP autenticado del hosting |
| URL base | `http://localhost/form/` | `https://dominio.cl/form/` |
