# Módulo Digital de Cobranza y Trazabilidad de Cheques

**Holding:** Automarco / Autotec / Gabtec / HD Automarco  
**Solicitante:** Miguel Martínez / S. Valenzuela (`svalenzuela@automarco.com`)  
**Versión Documentada:** 1.3  
**Stack:** PHP 8+ (PDO puro) · MySQL/MariaDB · HTML/CSS/JS Vanilla  
**Entorno local:** Laragon (Windows)

---

## ¿Qué es este módulo?

Aplicación web *responsive* (optimizada para tablet y móvil) embebida dentro de una **app Android ya existente**. Digitaliza el proceso de cobranza física de cheques por parte de la fuerza de ventas del holding, reemplazando el flujo informal de fotos por WhatsApp y conectando Tesorería con Cuentas Corrientes para el ingreso al ERP Optimus.

---

## Actores del Sistema

| Actor | Canal de acceso | Herramienta | Qué puede hacer |
|-------|----------------|-------------|-----------------|
| **Vendedor** | App Android (WebView embebida) | Esta app web (`/form/`) | Registrar cobranzas, adjuntar fotos/comprobantes, validar cheques duplicados y ver su historial |
| **Tesorería** | Email → link / Portal | Portal web (`/admin/index.php`) | Validar recepción física, corregir datos erróneos de cheques, solicitar rechazos y auditar |
| **Cuentas Corrientes** | Portal web | Portal web (`/admin/cuentas_corrientes.php`) | Reasignar digitadoras por ausencias, controlar hora de corte (16:00) y despachar resúmenes diarios |
| **Digitadoras** | Email diario (16:00 hrs) | Correo HTML ultra-ordenado | Recibir datos procesados (sin imágenes) para digitación masiva en Optimus ERP |
| **Administrador** | Portal web | Portal web (`/admin/`) | Configuración global, matriz de ausencias, bitácora de envíos y trazabilidad completa |

---

## Fases del Proyecto

| Fase | Descripción | Estado |
|------|-------------|--------|
| **Fase 1** | Backend PHP: BD central, endpoints API, integración real del frontend | ✅ Completado |
| **Fase 2** | Portal de Tesorería (`/admin/`) web desktop-first | ✅ Completado |
| **Fase 3** | Portal de Cuentas Corrientes y Notificaciones por Correo SMTP | ✅ Completado |
| **Fase 4** | Cron diario de despacho a las 16:00, Idempotencia y Bitácora de Auditoría | ✅ Completado |
| **Fase 5** | Integración WebView App Android, Auth Strategy y Validaciones de Terreno | ✅ Completado |

Ver detalle en [`ROADMAP.md`](./ROADMAP.md).

---

## Estructura de Carpetas

```
/form/
├── index.html                  Vista principal (vendedor)
├── index.php                   Entry point PHP
├── script.js                   Lógica frontend (validación duplicados, formato montos, fetch real)
├── styles.css                  Estilos responsive y UI Tokens
│
├── config/
│   ├── app.php                 Configuración global (DB, SMTP Mailtrap, uploads, whitelist)
│   ├── db.php                  Clase Database — conexiones PDO central y ERPs
│   ├── auth.php                Middleware de autenticación y verificación de roles
│   └── setup.sql               DDL completo + seeders + tablas audit/login_attempts
│
├── api/
│   ├── auth_seller.php         POST/GET — Inicialización de sesión vendedor WebView
│   ├── get_factura.php         GET  — Busca factura en ERP
│   ├── guardar_cobranza.php    POST — Guarda cobranza + cheques + imágenes + validación duplicados
│   ├── completar_envio.php     POST — Completa el despacho de una cobranza pendiente
│   ├── get_mis_cobranzas.php   GET  — Historial del vendedor autenticado
│   └── auth/
│       └── logout_vendedor.php POST — Cierre de sesión seguro del vendedor
│
├── admin/
│   ├── index.php               Portal de Tesorería (Bandeja, validación física, modal rechazo)
│   ├── cuentas_corrientes.php  Portal Standalone de Cuentas Corrientes (Despacho diario, ausencias)
│   ├── login.php               Pantalla de inicio de sesión segura (CSRF + Rate Limiting)
│   ├── admin.js                Lógica de inspección, edición inline de cheques y modales
│   └── api/
│       ├── editar_cobranza_tesoreria.php  POST — Corrección manual de datos de cheques
│       ├── despachar_resumen_cc.php       POST — Despacho manual del consolidado diario
│       ├── get_gestion_cc.php             GET  — Métricas y lista de digitadoras
│       ├── guardar_configuracion_cc.php   POST — Reasignación de correos y hora de corte
│       ├── reenviar_informe_cc.php        POST — Reenvío de informes históricos
│       └── auth/
│           └── logout.php                 POST — Cierre de sesión de administración
│
├── cron/
│   └── resumen_diario_cuentas_corrientes.php CLI — Cron de despacho de las 16:00 (Timezone + Locking)
│
├── services/
│   ├── MailService.php         Envío de correo SMTP puros (con prefijos [PARA ...] y layout unificado)
│   └── AuditService.php        Log transaccional de auditoría
│
├── uploads/
│   └── {empresa_id}/
│       └── {YYYY-MM}/
│           ├── cheques/
│           └── comprobantes/
│
├── docs/                       ← Especificaciones y documentación técnica
│   ├── ADAPTACION_FLUJO_REAL.md
│   ├── SPEC_PORTAL_CUENTAS_CORRIENTES.md
│   ├── INFORME_PRACTICA_RESUMEN.md
│   ├── BUSINESS_RULES.md
│   └── CHANGELOG.md
│
└── scratch/                    Scripts de verificación e integridad
```

---

## Quick Start (Entorno Local — Laragon)

```bash
# 1. Proyecto ubicado en:
C:\laragon\www\form\

# 2. Crear la base de datos central
# phpMyAdmin → importar: config/setup.sql

# 3. Verificar configuración
# config/app.php → DB_HOST=localhost, DB_USER=root, DB_PASS=''

# 4. Acceder al módulo
http://localhost/form/
```

---

## Referencias de Documentación

| Documento | Contenido |
|-----------|-----------|
| [`ARCHITECTURE.md`](./ARCHITECTURE.md) | Topología multi-BD, flujo de datos, auth strategy |
| [`DATABASE.md`](./DATABASE.md) | DDL, tablas, relaciones, queries template |
| [`API.md`](./API.md) | Endpoints, request/response, códigos HTTP |
| [`BUSINESS_RULES.md`](./BUSINESS_RULES.md) | Máquina de estados, validaciones, reglas de negocio |
| [`SPEC_PORTAL_CUENTAS_CORRIENTES.md`](./SPEC_PORTAL_CUENTAS_CORRIENTES.md) | Especificación del portal de Cuentas Corrientes y matriz de ausencias |
| [`ADAPTACION_FLUJO_REAL.md`](./ADAPTACION_FLUJO_REAL.md) | Adaptación al flujo operativo real del holding |
| [`SECURITY.md`](./SECURITY.md) | Whitelist BD, auth middleware, validación de archivos |
| [`ROADMAP.md`](./ROADMAP.md) | Hoja de ruta por fases |
| [`CHANGELOG.md`](./CHANGELOG.md) | Historial detallado de cambios y versiones |

---

## Credenciales de Acceso (Desarrollo y Pruebas)

Para realizar pruebas en el entorno de desarrollo local, se pueden utilizar las siguientes cuentas creadas en el seeder de base de datos:

| Rol | Correo / Usuario | Contraseña | Destino / URL |
|-----|------------------|------------|---------------|
| **Administrador** | `sistema@app.local` | `sistema123` | `/admin/login.php` (Acceso total) |
| **Vendedor** | `vendedor@app.local` | `vendedor123` | `/form/` (Formulario de cobro) |
| **Tesorería** | `tesoreria@automarco.cl` | `tesoreria123` | `/admin/login.php` (Bandeja y validación) |
| **Cuentas Corrientes** | `cuentascorrientes@automarco.cl` | `tesoreria123` | `/admin/cuentas_corrientes.php` (Distribuidor y reenvíos) |
