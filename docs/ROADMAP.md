# ROADMAP.md — Hoja de Ruta del Proyecto

**Propósito:** Describir las fases de implementación del módulo, su alcance, dependencias y criterios de completitud.  
**Audiencia:** Product Owners, desarrolladores, agentes de IA.  
**Referencias:** [`ARCHITECTURE.md`](./ARCHITECTURE.md) para visión técnica · [`API.md`](./API.md) para endpoints por fase.

---

## Estado Actual del Proyecto

```
Fase 1 ██████████ 100% — Backend base + flujo dividido + DDL alineado
Fase 2 ██████████ 100% — Portal Tesorería con Hardening & Desglose Multi-Factura
Fase 4 ██████████ 100% — Cron Jobs: Despacho Automático CC por Hora de Corte & Alertas por Días
Fase 5 ██████████ 100% — WebView App Eclipse, Smart Client Picker & Multi-Factura Cross-Empresa
```

---

## Fase 1 — Backend Base y Conexión con Frontend

**Objetivo:** Reemplazar los datos mock del JS por llamadas reales a la BD y persistir las cobranzas en MySQL.

**Entregables:**

| Archivo | Estado | Descripción |
|---------|--------|-------------|
| `config/setup.sql` | ✅ | DDL alineado al flujo dividido + seeders de empresas + usuario Sistema |
| `config/update_schema_flujo_dividido.sql` | ✅ | Script puntual para actualizar una BD local ya creada |
| `config/app.php` | ✅ | Constantes globales de entorno |
| `config/db.php` | ✅ | Clase Database — conexiones PDO central y ERP |
| `config/auth.php` | ✅ | Middleware auth (bypass en local) |
| `api/get_factura.php` | ✅ | Búsqueda de factura en BD ERP |
| `api/guardar_cobranza.php` | ✅ | Guardado transaccional + fotos |
| `api/completar_envio.php` | ✅ | Completa el despacho y cambia al estado de envío |
| `api/get_mis_cobranzas.php` | ✅ | Historial del vendedor (read-only) |
| `api/auth/login.php` | ✅ | Autenticación con token Bearer |
| `services/MailService.php` | ✅ | Servicio de correo SMTP |
| `script.js` (modificación) | ✅ | Fetch() reales, listado separado y modal de completar envío |

**Criterio de completitud:**
- El formulario guarda una cobranza real en la BD con fotos en `uploads/`
- La pestaña de historial muestra datos reales de la BD
- El correo llega a Tesorería y al cliente
- El endpoint de login retorna un token válido

**Nota de entorno:** No hay información real que preservar. El DDL ya está alineado con el flujo dividido; la BD local puede recrearse desde `config/setup.sql` o actualizarse con `config/update_schema_flujo_dividido.sql`.

---

## Fase 2 — Portal de Tesorería

**Objetivo:** Crear una interfaz web desktop-first separada (`/admin/`) para que Tesorería gestione el ciclo de vida de los cheques.

**Actores:** Solo roles `TESORERIA` y `ADMINISTRADOR`.

**Entregables:**

| Archivo | Descripción |
|---------|-------------|
| `admin/index.php` | Vista principal — tabla de cobranzas con filtros y acciones inline |
| `admin/detalle.php` | Vista de detalle — historial de estados + fotos + datos completos |
| `admin/api/get_cobranzas.php` | GET — listado completo con filtros (todas las empresas) |
| `admin/api/cambiar_estado.php` | POST — cambia estado + inserta en `historial_estados` |
| `admin/api/get_detalle_cobranza.php` | GET — detalle + historial completo de una cobranza |

**Funciones de la vista:**
- Listado con filtros por empresa, estado y búsqueda libre
- Botones de acción inline: "Marcar Recibido", "Registrar Depósito", "Marcar Rechazado"
- Formulario de depósito: ingreso de N° papeleta y fecha real
- Formulario de rechazo: campo de motivo obligatorio
- Vista de fotos de cheques y comprobantes

**Dependencias:** Fase 1 completada + SMTP de correo configurado para incluir link al portal en el email.

---

## Fase 3 — Notificaciones por Correo (SMTP Host)

**Objetivo:** Reemplazar el envío provisional de correo y configurar el SMTP real del hosting de producción.

**Entregables:**

| Tarea | Descripción |
|-------|-------------|
| Obtener credenciales SMTP del hosting | `MAIL_HOST`, `MAIL_PORT`, `MAIL_USER`, `MAIL_PASS` |
| Configurar `config/app.php` | Completar las constantes `MAIL_*` |
| Validar PHPMailer en producción | Prueba de envío a Tesorería y cliente |
| Incluir link al portal en email de Tesorería | URL directa a `admin/detalle.php?id={cobranza_id}` |
| Plantilla HTML mejorada del correo | Diseño responsive, logo del holding, tabla de cheques |

**Dependencias:** Fase 2 completada (para incluir el link al portal en el email).

---

## Fase 4 — Cron Jobs: Despacho Automático CC & Motor de Alertas

**Objetivo:** Automatizar el despacho diario hacia Cuentas Corrientes según hora de corte configurable en panel/BD y detectar cobranzas en tránsito demoradas con alertas por correo.

**Entregables:**

| Archivo | Estado | Descripción |
|---------|--------|-------------|
| `cron/resumen_diario_cuentas_corrientes.php` | ✅ | Script PHP ejecutado cada 15 min. Evalúa hora de corte en BD (`configuraciones_sistema.hora_despacho_diario`), fragmenta por empresa emisora, adjunta PDF consolidado y despacha a digitadoras con CC a Supervisora. |
| `cron/check_alertas.php` | ✅ | Script PHP diario (08:00 AM) que detecta cobranzas demoradas (`dias_transcurridos > dias_maximos`) y notifica al vendedor y CC. |
| `cron/README.md` | ✅ | Guía completa de configuración para Crontab Linux / cPanel y WebCron protegido por token. |
| `services/MailService.php` | ✅ | Métodos `enviarResumenDiarioDigitadora()` con CC y `enviarAlertaDemora()` con enlace al portal. |

**Configuración en Crontab Linux:**
```bash
# Despacho automático por hora de corte (Lun-Vie 08:00 a 19:00 hrs):
*/15 8-19 * * 1-5 /usr/bin/php /var/www/html/autotec/cobranza_cheques/app/cron/resumen_diario_cuentas_corrientes.php >> /var/www/html/autotec/cobranza_cheques/app/logs/cron_despacho_cc.log 2>&1

# Motor de alertas por demora (Diario 08:00 AM):
0 8 * * 1-6 /usr/bin/php /var/www/html/autotec/cobranza_cheques/app/cron/check_alertas.php >> /var/www/html/autotec/cobranza_cheques/app/logs/cron_alertas.log 2>&1
```

---

## Fase 5 — WebView App Eclipse & Rediseño Cliente/Multi-Factura

**Objetivo:** Adaptar el portal móvil de cobranza para integrarse mediante un WebView dentro de la App Android legada (Eclipse) del holding y migrar del registro manual de facturas a un flujo de selección guiado por cartera de clientes y multi-facturas.

### Flujo del Usuario (Vendedor) en la App:

```
┌────────────────────────┐
│  1. Lee vendedor_id    │ ◄─── (Parámetro GET desde WebView de Android legada)
└───────────┬────────────┘
            ▼
┌────────────────────────┐
│  2. Selector Cliente   │ ◄─── (Carga cartera filtrando vendedor en tbl_cobranza)
└───────────┬────────────┘
            ▼
┌────────────────────────┐
│ 3. Checkbox Facturas   │ ◄─── (Lista facturas impagas del cliente cross-empresa)
└───────────┬────────────┘
            ▼
┌────────────────────────┐
│  4. Ingreso Cheque(s)  │ ◄─── (Ingresa cheques, fotos y envía cobranza)
└────────────────────────┘
```

**Entregables:**

| Tarea | Descripción |
|-------|-------------|
| Endpoint `api/get_clientes.php` | Retorna los RUTs y nombres de clientes asociados al `vendedor_id` que tienen deuda activa. |
| Endpoint `api/get_facturas.php` | Retorna todas las facturas abiertas del RUT cliente consultadas en `bd_automarco.tbl_cobranza` indicando empresa de origen. |
| Tabla `cobranza_facturas` (BD central) | Tabla pivot para relacionar N:M una cobranza con múltiples facturas cubiertas de distintas empresas. |
| Refactorización de `api/guardar_cobranza.php` | Inserta cabecera y desglose de facturas canceladas en `cobranza_facturas`. |
| Rediseño de interfaz `index.html` | Cambiar ingreso manual de Folio/Empresa por dropdown de Clientes y grilla interactiva de selección de Facturas. |

**Dependencias:** Fases 1, 2 y 3 completadas. Conexión de lectura a la base consolidada `bd_automarco` habilitada.

---

## Fase 6 — Hardening de Seguridad & Preparación Go-Live

**Objetivo:** Eliminar las brechas de seguridad identificadas en el análisis previo a producción, unificar esquemas de autenticación y proteger la integridad del flujo de datos en coexistencia con la App Android (Eclipse) legada.

**Entregables:**

| Tarea | ID Ref | Prioridad | Descripción |
|---|---|---|---|
| Unificación Auth Admin | SEC-02 | 🔴 Alta (Bloqueante) | Adaptar `config/auth.php` y APIs de `/admin/api/` para aceptar Sesiones de PHP (`$_SESSION`) o Bearer Tokens indistintamente en producción. |
| Firma / Validación Identity | SEC-01 | 🔴 Alta | Validar origen de peticiones `vendedor_id` para evitar consultas/envíos no autorizados de carteras ajenas. |
| Protección `uploads/` | SEC-03 | 🟡 Media-Alta | Crear `uploads/.htaccess` impidiendo ejecución de scripts (`php_flag engine off`). |
| Re-Validación Backend de Cuotas | SEC-04 | 🟡 Media | Verificar en `guardar_cobranza.php` que los saldos y cuotas coincidan exactamente con la deuda viva en `bd_automarco.tbl_cobranza`. |
| Cierre de Entorno | ENV-01 | 🔴 Alta | Cambiar `APP_ENV` de `'local'` a `'production'`, eliminar usuarios bypass y forzar HTTPS. |

---

## Fase 7 — Integración Google Sheets + Flujo de Correos Extendido

**Objetivo:** Automatizar la inyección de cheques validados al Excel corporativo de Tesorería (Google Sheets) y completar el mapa de notificaciones por correo del sistema.

**Estado:** ⏳ Pendiente de prerequisitos manuales del usuario.

**Entregables:**

| Tarea | Estado | Descripción |
|---|---|---|
| **Prerequisito:** Service Account de Google Cloud | ⏳ | El usuario debe crear la cuenta, habilitar la API de Sheets, descargar el JSON de credenciales y compartir el Sheet. |
| `services/GoogleSheetsService.php` | 🔲 | Clase PHP pura (~120 líneas) que autentica vía JWT (`openssl_sign` + `curl`) y llama al endpoint `values:append` de Sheets API v4. Sin Composer ni SDK. |
| `config/google_credentials.json` | 🔲 | Archivo JSON de credenciales de Service Account (provisto por el usuario, excluido de Git). |
| Modificación de `admin/api/cambiar_estado.php` | 🔲 | Al transicionar a `RECIBIDO_TESORERIA`, inyectar cada cheque como fila nueva al Google Sheet. |
| Correo de rechazo al Vendedor | 🔲 | Método `MailService::notificarRechazoTesorería()` + disparo en `cambiar_estado.php` cuando estado = `RECHAZADO`. |
| Correo de registro a C.Corrientes | 🔲 | Modificar `MailService::enviarNotificacion()` para enviar copia a C.Corrientes al registrar una cobranza. |

**Columnas del Excel (Mapeo planificado):**

| Columna Excel | Fuente en BD |
|---|---|
| Fecha | `cheques.fecha_vencimiento` |
| Nombre girador | `cheques.emitido_a` |
| Monto | `cheques.monto` |
| Rut cliente | `cobranzas.rut_cliente` |
| nRecibo | `cheques.numero_cheque` |
| Nombre cliente | `cobranzas.razon_social_cliente` |
| Fecha ingreso | `NOW()` (momento de validación) |
| CTANUMERO | `cheques.banco` |
| comentario | `cheques.comentario` |

**Dependencias:** Service Account de Google Cloud configurada + Sheet compartido con el correo de la cuenta de servicio.

**Para implementar:** Abrir un chat nuevo y decir: *"Implementa la Fase 7 del ROADMAP (Google Sheets). Aquí está el JSON de credenciales y el Spreadsheet ID: ..."*

---

## Fase 8 — Topes Presupuestarios y Aprobación Previa de Giras

**Estado:** 🟨 Plan documentado; pendiente de confirmación explícita para implementar.

**Objetivo:** Convertir el presupuesto mensual en tope ordinario de reembolso, conservar una excepción gerencial opcional y exigir aprobación previa de un responsable para habilitar fondos de gira.

**Fuente de verdad del alcance:** [`PLAN_TOPES_Y_APROBACIONES_RENDICIONES.md`](./PLAN_TOPES_Y_APROBACIONES_RENDICIONES.md).

**Entregables previstos:**

- Modelo reusable de solicitudes de aprobación con token de uso único.
- Reserva de saldo pendiente separada del gasto aprobado real.
- Liquidación FIFO y pago con tope presupuestario.
- Excepción mensual que no bloquea ni rechaza la rendición base.
- Giras ocultas hasta su aprobación por el responsable elegido.
- Reenvío, cambio de responsable, cancelación, auditoría y Dashboard actualizado.
- Migración local/productiva, sincronización dual y QA de la matriz completa.

---

## Decisiones Diferidas (No en Roadmap Actual)

Estas funcionalidades fueron identificadas pero excluidas del alcance actual:

| Funcionalidad | Motivo de exclusión |
|---------------|---------------------|
| Agrupación visual de OTs Chilexpress | Baja prioridad para MVP |
| App Android nativa de fotografía | Ya se usa `capture="environment"` en WebView |
| Firma digital electrónica | Complejidad legal (Ley 19.799 Chile) |
| Integración bancaria para depósitos | Fuera del alcance, se hace manualmente |

