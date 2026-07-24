# Módulo Digital de Cobranza y Trazabilidad de Cheques

**Holding:** Automarco / Autotec / Gabtec / HD Automarco  
**Solicitante:** Miguel Martínez / S. Valenzuela (`svalenzuela@automarco.com`)  
**Versión Documentada:** 1.2  
**Stack:** PHP 8+ (PDO puro) · MySQL/MariaDB · HTML/CSS/JS Vanilla  
**Entorno local:** Laragon (Windows)

---

## ¿Qué es este módulo?

Aplicación web *responsive* (optimizada para tablet y móvil) embebida dentro de una **app Android ya existente**. Digitaliza el proceso de cobranza física de cheques por parte de la fuerza de ventas del holding, reemplazando el flujo informal de fotos por WhatsApp.

---

## Actores del Sistema

| Actor | Canal de acceso | Herramienta | Qué puede hacer |
|-------|----------------|-------------|-----------------|
| **Vendedor** | App Android (WebView embebida) | Esta app web (`/form/`) | Registrar cobranzas, ver su historial propio |
| **Tesorería** | Email → link | Portal web separado (`/admin/`) | Gestionar estados, registrar depósitos y rechazos |
| **Administrador** | Portal web | Portal web separado (`/admin/`) | Configurar empresas, usuarios y parámetros |

> **La app web actual (`/form/`) es exclusivamente para vendedores.** Tesorería no accede a ella.

---

## Fases del Proyecto

| Fase | Descripción | Estado |
|------|-------------|--------|
| **Fase 1** | Backend PHP: BD central, endpoints API, integración real del frontend | 🔄 En desarrollo |
| **Fase 2** | Portal de Tesorería (`/admin/`) web desktop-first | ⏳ Pendiente |
| **Fase 3** | Notificaciones automáticas por correo SMTP host | ⏳ Pendiente |
| **Fase 4** | Cron de alertas por días transcurridos | ⏳ Pendiente |
| **Fase 5** | Integración completa de autenticación con app Android | ⏳ Pendiente |

Ver detalle en [`ROADMAP.md`](./ROADMAP.md).

---

## Estructura de Carpetas

```
/form/
├── index.html                  Vista principal (vendedor)
├── index.php                   Entry point PHP
├── script.js                   Lógica frontend (fetch() reales desde Fase 1)
├── styles.css                  Estilos
├── enviar_mailtrap.php         [LEGACY] No se usa en flujo nuevo
│
├── config/
│   ├── app.php                 Configuración global (DB, SMTP, uploads, whitelist)
│   ├── db.php                  Clase Database — conexiones PDO
│   ├── auth.php                Middleware de autenticación (bypass en local)
│   └── setup.sql               DDL completo + seeders
│
├── api/
│   ├── get_factura.php         GET  — Busca factura en ERP
│   ├── guardar_cobranza.php    POST — Guarda cobranza + cheques + imágenes
│   ├── completar_envio.php     POST — Completa el despacho de una cobranza pendiente
│   ├── get_mis_cobranzas.php   GET  — Historial del vendedor autenticado
│   └── auth/
│       └── login.php           POST — Autenticación, retorna token
│
├── services/
│   └── MailService.php         Envío de correo vía SMTP host
│
├── uploads/
│   └── {empresa_id}/
│       └── {YYYY-MM}/
│           ├── cheques/
│           └── comprobantes/
│
├── docs/                       ← Esta documentación
│
└── context/                    Especificaciones originales del cliente
    ├── CONTEXT.md
    └── MVP_Gestion_Cobranza_Cheques.md
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

## Política de datos del entorno actual

Este entorno de desarrollo es cerrado y no contiene cheques ni cobranzas reales. Por ello, mientras se mantenga esta condición, los datos de `bd_modulo_cobranzas` pueden eliminarse y la base puede recrearse desde cero al ajustar el esquema.

No se requiere una migración conservadora para los cambios del flujo dividido. El trabajo pendiente es actualizar el DDL de `config/setup.sql` y luego recrear/importar la base local. Antes de usar datos reales o un entorno compartido, esta política debe revisarse y los cambios de esquema deberán ejecutarse mediante una migración que preserve los datos.

---

## Referencias de Documentación

| Documento | Contenido |
|-----------|-----------|
| [`ARCHITECTURE.md`](./ARCHITECTURE.md) | Topología multi-BD, flujo de datos, auth strategy |
| [`DATABASE.md`](./DATABASE.md) | DDL, tablas, relaciones, queries template |
| [`API.md`](./API.md) | Endpoints, request/response, códigos HTTP |
| [`BUSINESS_RULES.md`](./BUSINESS_RULES.md) | Máquina de estados, validaciones, reglas de negocio |
| [`CODING_STANDARDS.md`](./CODING_STANDARDS.md) | Convenciones PHP, patrones de respuesta, manejo de errores |
| [`SECURITY.md`](./SECURITY.md) | Whitelist BD, auth middleware, validación de archivos |
| [`ROADMAP.md`](./ROADMAP.md) | Hoja de ruta por fases |
| [`AI_RULES.md`](./AI_RULES.md) | Instrucciones para agentes de IA que trabajen en este proyecto |
