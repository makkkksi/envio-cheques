# Módulo Digital de Cobranza y Trazabilidad de Cheques

**Holding:** Automarco / Autotec / Gabtec / HD Automarco  
**Solicitante:** Miguel Martínez / S. Valenzuela (`svalenzuela@automarco.com`)  
**Versión Documentada:** 1.4 (Agosto 2026)  
**Stack:** PHP 8+ (PDO puro) · MySQL/MariaDB · HTML/CSS/JS Vanilla  
**Entorno local:** Laragon (Windows) · **Carpeta Producción:** `dist/`

---

## ¿Qué es este módulo?

Aplicación web *responsive* (optimizada para tablet y móvil) embebida dentro de una **app Android ya existente**. Digitaliza el proceso de cobranza física de cheques por parte de la fuerza de ventas del holding, reemplazando el flujo informal de fotos por WhatsApp y conectando Tesorería con Cuentas Corrientes para el ingreso al ERP Optimus y la sincronización automática con Google Sheets.

---

## Actores del Sistema

| Actor | Canal de acceso | Herramienta | Qué puede hacer |
|-------|----------------|-------------|-----------------|
| **Vendedor** | App Android (WebView embebida) | Esta app web (`/form/`) | Registrar cobranzas por ID ERP o email, adjuntar fotos/comprobantes, validar cheques duplicados y ver su historial |
| **Tesorería** | Email → link / Portal | Portal web (`/admin/index.php`) | Validar recepción física, corregir datos erróneos de cheques, solicitar rechazos, inyectar datos a Google Sheets y auditar |
| **Cuentas Corrientes** | Portal web | Portal web (`/admin/cuentas_corrientes.php`) | Reasignar digitadoras por ausencias, controlar hora de corte (16:00) y despachar resúmenes diarios |
| **Digitadoras** | Email diario (16:00 hrs) | Correo HTML ultra-ordenado | Recibir datos procesados (sin imágenes) para digitación masiva en Optimus ERP |
| **Administrador** | Portal web | Portal web (`/admin/`) | Configuración global, matriz de ausencias, bitácora de envíos, configuración de Google Sheets por empresa y trazabilidad completa |

---

## Fases del Proyecto

| Fase | Descripción | Estado |
|------|-------------|--------|
| **Fase 1** | Backend PHP: BD central, endpoints API, integración real del frontend | ✅ Completado |
| **Fase 2** | Portal de Tesorería (`/admin/`) web desktop-first | ✅ Completado |
| **Fase 3** | Portal de Cuentas Corrientes, Notificaciones SMTP e Integración Google Sheets v4 | ✅ Completado |
| **Fase 4** | Cron diario de despacho a las 16:00, Idempotencia y Bitácora de Auditoría | ✅ Completado |
| **Fase 5** | Integración WebView App Android, Auth Strategy, Parches Integridad ERP y Sincronización `dist/` | ✅ Completado |

Ver detalle en [`ROADMAP.md`](./ROADMAP.md).

---

## Estructura de Carpetas

```
/form/
├── dist/                       ← PAQUETE DE PRODUCCIÓN (Clon 100% sincronizado y listo para desplegar)
├── index.html                  Vista principal (vendedor)
├── index.php                   Entry point PHP
├── script.js                   Lógica frontend (validación duplicados, formato montos, fetch real)
├── styles.css                  Estilos responsive y UI Tokens
├── .htaccess                   Protección Apache, headers HTTP y variables de entorno
│
├── config/
│   ├── app.php                 Configuración global (DB, SMTP Mailtrap, uploads, whitelist)
│   ├── db.php                  Clase Database — conexiones PDO central y ERPs
│   ├── auth.php                Middleware de autenticación y verificación de roles / IP real
│   └── setup.sql               DDL completo + seeders + tablas audit/login_attempts
│
├── api/
│   ├── auth_seller.php         POST/GET — Inicialización de sesión vendedor WebView
│   ├── get_factura.php         GET  — Busca factura en ERP
│   ├── guardar_cobranza.php    POST — Guarda cobranza + cheques + imágenes + parches FK ERP
│   ├── completar_envio.php     POST — Completa el despacho de una cobranza pendiente
│   ├── editar_cheques.php      POST — Modificación de cheques por vendedor
│   ├── get_mis_cobranzas.php   GET  — Historial del vendedor autenticado
│   └── auth/
│       └── logout_vendedor.php POST — Cierre de sesión seguro del vendedor
│
├── admin/
│   ├── index.php               Portal de Tesorería (Bandeja, validación física, modal rechazo)
│   ├── cuentas_corrientes.php  Portal Standalone de Cuentas Corrientes (Despacho diario, ausencias)
│   ├── login.php               Pantalla de inicio de sesión segura (CSRF + Rate Limiting)
│   ├── admin.js                Lógica de inspección, edición inline de cheques y catálogo de bancos
│   ├── components/
│   │   └── modal_config_cc.php Modal de configuración de distribución y Google Sheets
│   └── api/
│       ├── cambiar_estado.php             POST — Validación y disparo automático a Google Sheets (WEB#)
│       ├── editar_cobranza_tesoreria.php  POST — Corrección manual de datos de cheques (Auditoría)
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
│   ├── AuditService.php        Log transaccional de auditoría
│   ├── GoogleSheetsService.php Integración API REST v4 Google Sheets (JWT nativo sin composer)
│   ├── PdfGenerator.php        Generador de reportes PDF adjuntos en correos
│   └── ErpRepository.php       Repositorio de consultas e integridad con el ERP
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
│   ├── PROJECT_STATUS.md
│   └── CHANGELOG.md
│
└── scratch/                    Scripts de verificación e integridad (compare_dist.php)
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

> **Nota para Administradores**: El rol `ADMINISTRADOR` (`admin@automarco.cl` o `sistema@app.local`) tiene acceso a ambos portales administrativos. Al iniciar sesión, verá un botón en el encabezado (&#8646; Ir a C.Corrientes / &#8646; Ir a Tesorería) que le permite navegar fácilmente entre la vista de Tesorería y la de Cuentas Corrientes.






## PLANTILLA PARA INICIAR PROMPS (ESTO NO LO ANALIZES SI ERES IA, SOLO PARA LECTURA HUMANA):
Quiero trabajar en la siguiente tarea:

[TAREA]

Antes de modificar cualquier archivo:

1. Analiza la implementación actual.
2. Identifica qué Skill(s) son relevantes.
3. Identifica los archivos que probablemente se verán afectados.
4. Consulta únicamente la documentación necesaria para esta tarea.
5. Explícame brevemente cómo funciona actualmente la parte involucrada.
6. Identifica posibles impactos o riesgos.
7. Propón un plan de implementación paso a paso.

NO modifiques ningún archivo todavía.

Espera mi aprobación del plan antes de implementar.



