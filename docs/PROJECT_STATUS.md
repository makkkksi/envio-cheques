
El proyecto se encuentra en **Fase 2 (Portal de Tesorería)** completada y asegurada con hardening de seguridad. Se inicia la **Fase 3 (Notificaciones SMTP)**.

---

## Avance por Fases

| Fase | Descripción | Estado | Avance |
|------|-------------|--------|--------|
| **Fase 1** | Backend PHP: BD central, endpoints API, integración real con frontend | ✅ Completado | 100% |
| **Fase 2** | Portal de Tesorería (`/admin/`) con Hardening de Seguridad | ✅ Completado | 100% |
| **Fase 3** | Notificaciones por correo SMTP host | INCLONCLUSO | 80% | FALTA CONECTAR A SMPT DE PRODUCCION
| **Fase 4** | Motor de alertas por días transcurridos (Cron Job) | ⏳ Pendiente | 0% |
| **Fase 5** | Integración de autenticación con App Android | ⏳ Pendiente | 0% |

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
- [x] `api/completar_envio.php` — Segundo paso del vendedor asegurado contra IDOR.
- [x] `admin/index.php` & `admin/admin.js` — Portal web de Tesorería integrado.
- [x] `admin/login.php` — Formulario de acceso seguro para el portal de Tesorería.
- [x] `admin/api/cambiar_estado.php` — Cambio de estado transaccional y auditado con RBAC estricto.
- [x] `services/MailService.php` — Implementación de cliente SMTP por sockets robusto con adjuntos de cheques.

## Próximo trabajo inmediato

Implementar el motor de alertas por días transcurridos mediante Cron Job (Fase 4).
