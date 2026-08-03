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
| **Fase 5** | Integración WebView App Eclipse & Rediseño Seleccionador Cliente/Multi-Factura | x inco,pleto| 0% |

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
- [x] Actualización de Reglas de Negocio (Cheques & Descuadre): Traslado de digitación de Banco/Cheque hacia Tesorería (`modalCompletarCheques`) y requerimiento obligatorio de texto de justificación (`justificacion_descuadre`) en caso de diferencias de montos.
- [x] `admin/api/editar_cheques.php` — API para corrección y modificación manual de cheques por parte de Tesorería.
- [x] Fragmentación Dinámica de Despachos — El botón "Despachar Resumen" ahora divide las cobranzas según el campo `emitido_a` de cada cheque y distribuye correos distintos a múltiples digitadoras simultáneamente con asuntos categorizados.
- [x] Refactorización Visual de Facturas (`admin.js`) — Agrupación estética de facturas múltiples con cuotas en el detalle del portal de Tesorería.
- [x] `services/GoogleSheetsService.php` — Integración ultra-liviana con la API v4 de Google Sheets (autenticación JWT nativa con `openssl_sign` y cURL sin Composer) que inserta automáticamente cada cheque validado al Excel corporativo de Tesorería.
- [x] Re-validación Backend de Saldos (`SEC-04`): Implementado escudo protector en `api/guardar_cobranza.php` que consulta `bd_automarco.tbl_cobranza` antes de guardar, bloqueando cualquier manipulación o sobrepago fraudulento de saldos desde el navegador.
- [x] Flujo Extendido de Notificaciones por Correo: Doble notificación inicial automática (Tesorería + Cuentas Corrientes) en el registro de cobranza y notificación directa al Vendedor con motivo de rechazo cuando Tesorería rechaza una cobranza.

## Próximo trabajo inmediato

1. Probar el flujo completo en ambiente de pruebas (Tesorería completando cheques).
2. Revisión de despliegue en servidor.
3. Finalizar conexiones de envío SMTP si se requiere pase a producción.
