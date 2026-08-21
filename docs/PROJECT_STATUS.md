El proyecto cuenta con la **Fase 2 (Portal de Tesorería)** y la **Fase 5 (Integración WebView App Eclipse & Rediseño Multi-Factura/Cliente)** totalmente operacionales e implementadas en el código.

---

> **Nota sobre la Documentación:** Este documento (`PROJECT_STATUS.md`) refleja el estado operativo **actual** del proyecto (qué está terminado hoy y cuál es el bloqueo o próximo paso inmediato). Para ver la planificación a largo plazo, alcances detallados de cada fase y dependencias, consulte `ROADMAP.md`.

## Avance por Fases

| Fase | Descripción | Estado | Avance |
|------|-------------|--------|--------|
| **Fase 1** | Backend PHP: BD central, endpoints API, integración real con frontend | ✅ Completado | 100% |
| **Fase 2** | Portal de Tesorería (`/admin/`) con UI compacta, sub-filtros y tiempo relativo | ✅ Completado | 100% |
| **Fase 3** | Notificaciones por correo SMTP host y Portal Cuentas Corrientes | ✅ Completado | 100% |
| **Fase 4** | Motor de alertas por días transcurridos y Despacho Automático Diario (Cron Job) | ✅ Completado | 100% |
| **Fase 5** | Integración WebView App Android / Portal Vendedores Web & Multi-Factura | ✅ Completado | 100% |

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
- [x] Potenciación de Bitacora y Trazabilidad (`admin/cuentas_corrientes.php`) — Inserción de la columna "Clientes Afectados" en el historial de despachos e inspección estructurada de cheques digitalizados/registrados (banco, número, monto y vencimiento) para control y auditoría de extravíos sin imágenes.
- [x] **Unificación y Sincronización `dist/`** — Consolidación del 100% de los archivos entre la raíz del proyecto y la carpeta `dist/` (hashes MD5 idénticos), dejándola en un estado 100% listo para despliegue directo en producción.
- [x] **Parche de Integridad ERP (Foreign Keys)** — Solucionada incompatibilidad de `vendedor_id` nativo del ERP en `api/guardar_cobranza.php`, `api/completar_envio.php` y `api/editar_cheques.php`, usando fallback a Usuario Sistema (`ID 1`) en `historial_estados` cuando el ID del vendedor no existe en la tabla de usuarios web local.
- [x] **Trazabilidad de Nombre Vendedor** — Ajustada la prioridad en `get_cobranzas.php` y `get_detalle_cobranza.php` para dar precedencia absoluta a `c.vendedor_nombre` capturado desde terreno.
- [x] **Sincronización Google Sheets** — Inyección automática de `WEB#{cobranza_id}` en la Columna G (Nº Recibo) de los 4 excels y adición de `BCIPREMIER` a la lista de bancos.
- [x] **Auditoría Pre-Despliegue AWS** — Revisión de seguridad y variables de entorno para Producción (`PORTAL_BASE_URL` a nivel raíz, `.htaccess` configurado, validación de rutas relativas de API).
- [x] **Hardening de Seguridad HTTP (OWASP ZAP)** — Implementación de CSP balanceada y compatible (`style-src`/`font-src` Google Fonts y data/blob), HSTS con `preload` y `includeSubDomains`, Permissions-Policy, X-Frame-Options, X-Content-Type-Options y mitigación de fugas de servidor.
- [x] **Blindaje de Directorio de Uploads** — Creación de `.htaccess` en `uploads/` y `dist/uploads/` con desactivación de motores PHP y bloqueo total de scripts ejecutables (`RCE Protection`).
- [x] **Integridad de Fuentes Web** — Enlace directo a Google Fonts (`Outfit` y `Plus Jakarta Sans`) en todas las vistas HTML/PHP y eliminación de imports locales inexistentes en `styles.css`.
- [x] **Fragmentación y Despacho Multi-Empresa de Cheques Físicos** — Inclusión de `emitido_a` y `cuenta_corriente` en las consultas de `get_detalle_cobranza.php` y `get_cobranzas.php`, sanitización estricta de empresa en `cambiar_estado.php`, pre-selección y validación obligatoria en modal de Tesorería (`admin.js`), badges de auditoría en drawer y fragmentación exacta en la Matriz de Despacho de Cuentas Corrientes (`cuentas_corrientes.js`, `despachar_resumen_cc.php`, `cron/resumen_diario_cuentas_corrientes.php`).
- [x] **Visualización de Cheque Digitalizado en Cuentas Corrientes** — Integración de miniaturas de cheques digitalizados y Visor Lightbox interactivo (zoom, rotación, alto contraste y descarga) en los modales de Detalle de Cobranza e Historial de Despachos y Trazabilidad (`cuentas_corrientes.js` y `get_gestion_cc.php`).

- [x] **Remediación OWASP ZAP (Erradicación `unsafe-inline`)** — Desacoplamiento total de código JS y estilos CSS inline en `dist/admin/` a archivos dedicados (`dist/admin/css/` y `dist/admin/js/`), permitiendo una CSP estricta `script-src 'self'` y `style-src 'self' https://fonts.googleapis.com` sin alertas Medium.
- [x] **Emisión Dual de Headers HSTS y Supresión Server** — Blindaje en `.htaccess` y en `config/app.php` de `Strict-Transport-Security`, `X-Content-Type-Options`, `X-Frame-Options` y eliminación de firmas `Server`/`X-Powered-By`.
- [x] **Integración Portal Vendedores Web ↔ Cobranza Cheques** — Implementado botón de recaudación en `dist/vendedores/pages/cobranza.html` consumiendo el `vend_cod` de la sesión web activa y transfiriendo contexto (`vendedor_id` + `empresa`) vía URL canónica hacia `cobranza_cheques/index.html`. Documentación completa en `docs/INTEGRATION.md`.
- [x] **Blindaje Integral contra IDOR en Producción (`SEC-01`) & Limpieza de URL** — Implementada validación forzada de identidad desde `$_SESSION['vendedor_auth']` en los 5 endpoints de vendedor (`get_clientes.php`, `get_facturas_cliente.php`, `guardar_cobranza.php`, `get_mis_cobranzas.php`, `completar_envio.php`), verificación de pertenencia a cartera ERP en `get_facturas_cliente.php` y eliminación estética y segura de query params en el navegador (`history.replaceState`). Sincronización dual completa en `dist/`.
- [x] **Cron Jobs de Producción: Despacho Automático CC & Motor de Alertas (`Fase 4`)** — Implementación robusta de `cron/resumen_diario_cuentas_corrientes.php` (despacho automático diario con hora de corte configurable en BD, fragmentación por empresa y control anti-duplicados) y `cron/check_alertas.php` (motor de alertas por días transcurridos para cobranzas demoradas con notificación a Vendedor, Tesorería y CC). Ambos protegidos con validación CLI / token web (`CRON_SECRET_KEY`) y documentados en `cron/README.md`.
- [x] **CRON de Purga Segura de Fotos Post-Vencimiento (>3 Meses)** — Implementación del script de mantenimiento preventivo `cron/purgar_fotos_cheques_vencidos.php` (y su réplica dual en `dist/`) para liberación automática de espacio en disco. Elimina físicamente fotos de cheques y comprobantes de cobranzas cuyos cheques tengan más de 3 meses de vencimiento, manteniendo el 100% de la integridad de datos en MySQL (`ZERO DELETE`, solo actualiza URLs a `NULL` y marca `foto_purgada_at`/`comprobante_purgado_at`), con protección estricta anti Path-Traversal (`realpath`), soporte para simulación `--dry-run`, procesamiento en bloques de 200 y registro de auditoría.
- [x] **Fase 1: Conversión a SaaS Shell ERP Modular (Zero-Breakage)** — Implementación del App Switcher unificado (`admin/includes/app_header.php`, `admin/css/shell.css`), centralización del visor Lightbox, Toasts y modales (`admin/js/shared_ui.js`), creación del stub preparado para el Módulo 3 (`admin/rendiciones.php`), eliminación de código duplicado en `admin.js` y `cuentas_corrientes.js`, y sincronización dual exacta de todos los archivos en `dist/cheques_cobranza/app/admin/`.

## Próximo trabajo inmediato

1. **Subida y Prueba Interna en Host:** Desplegar el sistema al host de pruebas (en la nube usando el directorio `dist/` o la raíz). 
   - *Importante:* Recordar editar el archivo `.htaccess` subido con los datos reales de BD.
2. Probar el flujo completo en ambiente de pruebas (Tesorería validando y modificando cobranzas).
3. **Paso a Producción Final (Correos SMTP):**
   - Actualmente existe una **Barrera de Seguridad (Safety Guard)** en `services/MailService.php` que intercepta todos los correos si la variable de entorno `APP_ENV` es igual a `local`.
   - Con el nuevo `.htaccess` que setea `APP_ENV production`, los correos empezarán a salir una vez configurado el `MAIL_PASS`.
4. Configurar las 3 tareas en el Crontab del servidor Linux según las instrucciones detalladas en `cron/README.md`.

