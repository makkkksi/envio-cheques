El proyecto cuenta con la **Fase 2 (Portal de Tesorería)** y la **Fase 5 (Integración WebView App Eclipse & Rediseño Multi-Factura/Cliente)** totalmente operacionales e implementadas en el código.

---

> **Nota sobre la Documentación:** Este documento (`PROJECT_STATUS.md`) refleja el estado operativo **actual** del proyecto (qué está terminado hoy y cuál es el bloqueo o próximo paso inmediato). Para ver la planificación a largo plazo, alcances detallados de cada fase y dependencias, consulte `ROADMAP.md`.

## Avance por Fases

| Fase | Descripción | Estado | Avance |
|------|-------------|--------|--------|
| **Fase 1** | Backend PHP: BD central, endpoints API, integración real con frontend | ✅ Completado | 100% |
| **Fase 2** | Portal de Tesorería (`/admin/`) con UI compacta, sub-filtros y tiempo relativo | ✅ Completado | 100% |
| **Fase 3** | Notificaciones por correo SMTP host | 🟡 En pausa | 80% | FALTA CONECTAR A SMTP DE PRODUCCIÓN
| **Fase 4** | Motor de alertas por días transcurridos (Cron Job) | ⏳ Pendiente | 0% |
| **Fase 5** | Integración WebView App Eclipse & Rediseño Seleccionador Cliente/Multi-Factura | ✅ Completado | 100% |

---

## Componentes Entregados (Fase 1 y 2)

- [x] `config/setup.sql` — Script DDL completo y alineado con columnas de seguridad (`token_expires_at`).
- [x] `config/app.php` — Constantes de entorno y configuración.
- [x] `config/db.php` — Clase Database PDO.
- [x] `config/auth.php` — Middleware de autenticación (`requireAuth`, control de fuerza bruta y resolución de IP real).
- [x] `api/get_factura.php` — Endpoint de búsqueda de facturas ERP con blindaje de backticks en interpolación de BD.
- [x] `api/guardar_cobranza.php` — Endpoint transaccional de cobranzas.
- [x] `api/get_mis_cobranzas.php` — Historial de cobranzas del vendedor ordenable por última modificación.
- [x] `api/auth/login.php` — Autenticación segura con hasheo de tokens, expiración y control de intentos fallidos.
- [x] `services/AuditService.php` — Bitácora de auditoría transaccional.
- [x] `api/completar_envio.php` — Segundo paso del vendedor asegurado contra IDOR y bypass en local.
- [x] `admin/index.php` & `admin/admin.js` — Portal web de Tesorería con sub-filtros dinámicos, columna de tiempo relativo y botón de cierre visible.
- [x] `admin/login.php` — Formulario de acceso seguro para el portal de Tesorería.
- [x] `admin/api/cambiar_estado.php` — Cambio de estado transaccional y auditado con RBAC estricto.
- [x] `services/MailService.php` — Implementación de cliente SMTP por sockets robusto con adjuntos de cheques.
- [x] `docs/ANDROID_INTEGRATION.md` — Documentación y especificación técnica de la App Android (Eclipse) y flujo Vendedor ➔ Cliente ➔ Multi-Factura.

## Próximo trabajo inmediato

1. Recepción de estructura de tablas de Clientes / Cobranzas de ERP por parte del usuario.
2. Adaptación del backend para soportar la selección por cliente y marcado múltiple de facturas (Multi-Empresa).
3. Adaptación final del panel de auditoría de Tesorería (`/admin/`).
