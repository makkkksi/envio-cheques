# CHANGELOG.md — Registro de Cambios

Todos los cambios notables realizados en este proyecto se documentan en este archivo.

## [Unreleased] - 2026-08-21

### Resolución de P0s — Validación Canónica de vend_cod y Suite HTTP Real de Integración (2026-09-04)
- **P0-1 — Validación Estricta de `vend_cod` sin Coerción Implícita (`services/ErpSellerDirectoryService.php`, `services/SellerHandoffService.php`):**
  - Implementada condición SQL previa a cualquier `CAST`: `TRIM(vend_cod) REGEXP '^[1-9][0-9]*$'` en `WebUsuariosSellerRepository` (`search` y `findById`) y en `SellerHandoffService::verifySessionToken`.
  - Comparación en SQL libre de coerción de tipos: `TRIM(vend_cod) = :vend_cod` donde `:vend_cod` es la cadena normalizada.
  - Rechazo expreso de `0012` y cualquier valor con ceros a la izquierda para garantizar una **representación canónica única** por vendedor sin ambigüedades de formato.
  - Validación defensiva centralizada en PHP (`validateVendCod`): expresión regular `/^[1-9][0-9]*$/D` y rango 32-bit signed INT (1 a 2147483647).
  - Rechazo exhaustivo verificado de: `0`, `-12`, `+12`, `12.5`, `1e2`, `0012`, `12abc`, `abc12`, `""`, `"   "`, `99999999999999999999` y `2147483648`.
  - Certificado con 26 pruebas PASS en `scratch/test_seller_directory.php`.
- **P0-2 — Suite HTTP Real de Integración (`scratch/test_http_handoff.php`, `scratch/test_http_router.php`):**
  - Servidor PHP CLI local efímero ejecutado en puerto dinámico con protección de entorno de proceso (`TEST_HTTP_SERVER=1`, `APP_ENV=local`).
  - Prohibición irrevocable de inyectar o ejecutar verificadores de prueba en `APP_ENV=production` (`RuntimeException` inmediata).
  - Certificación completa del ciclo HTTP:
    - Petición POST real responde código **HTTP 303 (See Other)**.
    - Cabecera `Location` fija y limpia hacia cheques (`/form/index.html`) y rendiciones (`/form/rendiciones/vendedor.php`), sin tokens, nombres ni datos en query string.
    - Emisión obligatoria de cookie `AUTOMARCO_SELLER_SID` (HttpOnly, SameSite=Lax).
    - Regeneración comprobada del ID de sesión durante el handoff (mitigación de fijación de sesión).
    - Segunda petición GET con cookie a `api/auth/session_vendedor.php` responde 200 OK y valida el contexto autenticado del vendedor (ID, empresa, nombre, correo).
    - Reutilización exitosa de la sesión comercial activa (sin bloqueo artificial).
    - Respuestas de error validadas: GET -> 405; token inválido o expirado -> 401; destino no permitido -> 400; Origin no autorizado -> 403.
    - Confidencialidad total: ninguna cabecera, URL ni cuerpo expone el token comercial.
- **Transaccionalidad Estricta y Cero Persistencia en Pruebas:**
  - Toda escritura originada en pruebas (`AuditService::log`) se ejecuta en una transacción activa en `bd_modulo_cobranzas` y se revierte automáticamente mediante `ROLLBACK` en el shutdown handler.
  - Cero registros de prueba residuales en `audit_logs` (conteo antes: 107, conteo después: 107). Cero `DELETE` físicos.
  - Cero escrituras en las cuatro bases ERP de solo lectura (`automarc_automarco`, `autohd_automarcohd`, `autotec_ecom`, `gabteccl_sitbdd1978`).
- **Paridad SHA-256 Sincronizada:** Réplica idéntica en `dist/cheques_cobranza/app/`, certificada al 100% por `scripts/verify_release.php` (235 archivos idénticos).

### Migración a web_usuarios y Handoff Seguro por POST (2026-09-04)
- **Directorio de Vendedores con Adaptadores Inyectables (`services/ErpSellerDirectoryService.php`):** Refactorizado el servicio central de vendedores incorporando `WebUsuariosSellerRepository` (para Automarco, Autotec y Gabtec consultando `web_usuarios`) y `LegacySellerRepository` (para HD Automarco consultando `tbl_vendedores` como integración temporal). Todos los accesos se ejecutan en modo estrictamente de solo lectura con prepared statements y parámetros nombrados.
- **Validación de Identidad y Ambigüedad ERP:** Filtro estricto `rol = 'vendedor'`, `activo = 1`, y `vend_cod` numérico positivo (`> 0`). Detección automática de duplicados: si existen múltiples registros activos con el mismo `vend_cod` dentro de una empresa, se aborta con `DomainException`.
- **Soporte Completo para Vendedores sin Correo:** Tolerancia en todo el flujo de vendedores con `vendedor_email = null` (ej. 100% de los 31 vendedores en Autotec). La interfaz administrativa `admin/js/rendiciones.js` rotula como "Sin correo corporativo", permitiendo crear presupuestos y giras comerciales sin exigir correos ficticios.
- **Handoff Web Seguro por POST (`api/seller_handoff.php`):** Nuevo endpoint dedicado exclusivo para navegación POST desde portales comerciales hacia Cheques y Rendiciones. Rechaza GET con HTTP 405, valida empresa contra catálogo central y allowlist, verifica sesión contra `web_sesiones JOIN web_usuarios` de solo lectura en la BD ERP, regenera el session ID (`session_regenerate_id(true)`), genera token CSRF y redirige mediante HTTP 303 (See Other) a rutas fijas (`/index.html` o `/rendiciones/vendedor.php`) sin exponer tokens, identidades ni datos en URLs ni en cabeceras `Location`. Cabeceras de seguridad anti-caché y `Referrer-Policy: no-referrer`.
- **Reutilización Idempotente:** El token de sesión comercial activo del portal de vendedores puede utilizarse repetidamente para navegar hacia los módulos sin bloqueos artificiales ni consumo único destructivo.
- **Auditoría Transaccional Segura:** Evento `SELLER_HANDOFF` registrado en `audit_logs` con `empresa_id`, `vendedor_id`, `destino` y `resultado: SUCCESS`, prohibiendo de forma absoluta el guardado de tokens crudos, contraseñas o hashes reutilizables.
- **Bloqueo de Acceso Legacy en Producción:** En `APP_ENV=production`, `api/auth_seller.php` bloquea con HTTP 401 el envío de parámetros de identidad en la query string (`vendedor_id`, `vendedor`, `vendedor_nombre`, `empresa`). En `APP_ENV=local`, se preserva para agilizar pruebas de desarrollo.
- **Actualización de Portales Comerciales (`vendedores/` y `vendedores_DEV/`):** Sustituidos los enlaces con parámetros en la URL por la función JavaScript `enviarHandoffSeguro(destino)`, la cual auto-envía un formulario POST dinámico e invisible con `session_token`, `empresa` y `destino`, sin emojis en la UI.
- **Alcance Aclarado de Android:** La aplicación `android/Autotec_Grande` queda formalmente fuera del alcance de esta entrega, sin modificaciones y sin representar bloqueo para el despliegue de los portales web.
- **Paridad y Verificación de Release:** Replicada la totalidad de cambios de la aplicación hacia `dist/cheques_cobranza/app/`, certificando 235 archivos idénticos en SHA-256 (`scripts/verify_release.php`), 12 pruebas de directorio PASS (`test_seller_directory.php`) y 23 pruebas de handoff PASS (`test_seller_handoff.php`).

### Cierre Local al 100% — Correos a Vendedores Deshabilitados, Inmutabilidad de Cheques y QA Exhaustivo (2026-09-04)
- **P0 — Política Centralizada de Correos a Vendedores Deshabilitada:** Deshabilitadas todas las notificaciones por correo dirigidas a vendedores en todos los entornos mediante `MAIL_SELLER_NOTIFICATIONS_ENABLED = false` (`config/app.php` y réplica `dist`). Métodos auditados (`notificarDecisionExcesoRendicion`, `notificarDecisionGira`, `notificarRechazoTesoreria`, `notificarValidacionTesorería`, `enviarAlertaDemora`) omiten el despacho SMTP cuando la política está deshabilitada, retornan `true` controlado para no romper flujos financieros y registran un aviso neutro en log sin exponer datos sensibles (sin email, token, link ni HTML). Correos a aprobadores, Tesorería, Cuentas Corrientes y destinatarios administrativos permanecen plenamente operativos.
- **P1 — Inmutabilidad Real de Cheques Dados de Baja:** Blindaje integral en consultas operacionales de cheques (`api/editar_cheques.php`, `admin/api/cambiar_estado.php`, `admin/api/editar_cobranza_tesoreria.php`) exigiendo `AND activo = 1` o `AND (activo = 1 OR activo IS NULL)`. Un cheque inactivo no puede modificarse en monto, foto ni vencimiento, no recibe datos de depósito y no suma en totales activos. Resolución segura del actor `descartado_por`: verifica existencia en `usuarios(id)`; para códigos ERP no presentes en usuarios, aplica fallback seguro al ID 1 (Sistema) y preserva el código ERP en el motivo y bitácora, evitando violaciones de clave foránea.
- **P1 — Idempotencia Estricta de Migraciones:** Añadido `USE \`bd_modulo_cobranzas\`;` y guardas basadas en `information_schema` en todas las migraciones SQL pendientes. Certificada doble ejecución consecutiva sobre bases temporales aisladas sin errores DDL ni duplicaciones. Documentado que las migraciones pendientes no deben ejecutarse aún en producción.
- **P2 — Endurecimiento de `scratch/test_clean_setup.php`:** Generación de base temporal con `bin2hex(random_bytes(6))`, aborto sin eliminación preventiva ante colisiones, eliminación en `finally` condicionada a creación por la ejecución actual, y comparador exhaustivo de schema drift (17 tablas, columnas, orden, tipos, nullability, defaults, extras, generated expressions, índices, unicidad, foreign keys con ON UPDATE/DELETE y restricciones CHECK).
- **P2 — Ampliación del Verificador `scripts/verify_release.php`:** Incorporada validación de 10 contratos estrictos que incluyen la inmutabilidad de cheques en queries backend, deshabilitación de correos a vendedores, idempotencia de migraciones y completitud de comparación de schema drift.
- **Batería de Pruebas Automatizadas Certificada:** 8 suites de prueba aprobadas secuencialmente (109 archivos de sintaxis PHP, 17 tablas de esquema, 56 archivos backend, 37 archivos frontend sin emojis, 148 checks de rendiciones, 40 checks de aprobación, 18 checks de política de correos, 18 checks de inmutabilidad de cheques, 22 checks de doble migración, y 233 archivos idénticos en paridad SHA-256).

### Corrección Integral Preproducción — Seguridad, Zero Delete, Migraciones y QA (2026-09-04)
- **Zero Delete en Cheques (`api/editar_cheques.php`):** Eliminación física (`DELETE FROM cheques`) sustituida por baja lógica aditiva (`activo = 0`, `descartado_at = NOW()`, `descartado_por = :user_id`, `motivo_descarte = :motivo`). Eliminada la destrucción prematura de archivos físicos (`unlink`), preservando las imágenes para el ciclo de vida del cron de purga (`cron/purgar_fotos_cheques_vencidos.php`). Incorporado filtro retrocompatible `AND (activo = 1 OR activo IS NULL)` en todas las lecturas y subconsultas del sistema.
- **Migración Aditiva de Cheques (`config/migrations/2026_09_04_baja_logica_cheques.sql`):** Script DDL idempotente que añade columnas de descarte, índices `idx_cheques_activo`, `idx_cheques_descartado_por` y clave foránea `fk_cheques_descartado_usuario` a `usuarios(id)`. Sincronizado `config/setup.sql` para nuevas instalaciones.
- **Migración 100% a Parámetros Nombrados en PDO:** Eliminados todos los placeholders posicionales `?` restantes en `services/PdfGenerator.php`, `services/MailService.php`, `api/get_mis_cobranzas.php`, `cron/resumen_diario_cuentas_corrientes.php` y `cron/check_alertas.php`, consolidando parámetros nombrados (`:param`) en todo el backend.
- **Sanitización Integral de Respuestas API (Error 500):** Blindados los bloques catch en `api/guardar_cobranza.php`, `api/completar_envio.php`, `api/get_factura.php`, `api/get_mis_cobranzas.php`, `api/get_facturas_cliente.php`, `api/get_clientes.php`, `api/auth/login.php` y `api/editar_cheques.php`, impidiendo la exposición de `$e->getMessage()`, rutas del servidor o stack traces al cliente y canalizando el detalle a `error_log()`.
- **Preflight `SIGNAL SQLSTATE '45000'` en Migraciones:** En `config/migrations/2026_09_02_indices_y_fks_auditoria.sql`, reemplazadas las sentencias destructivas `UPDATE ... SET ... = NULL` por procedimientos almacenados preflight que abortan con error descriptivo si detectan IDs huérfanos antes de aplicar las claves foráneas.
- **Hardening de Prueba de Instalación Limpia (`scratch/test_clean_setup.php`):** Nombres aleatorios de base de datos (`bd_modulo_cobranzas_qa_<hash>`), validación estricta de prefijos y verificación explícita de columnas de Zero Delete en `cheques`, certificando cero schema drift contra la BD central.
- **Erradicación Total de Emojis y Dingbats en Frontend:** Limpiados emojis en `admin/admin.js`, `admin/detalle.php`, `admin/index.php`, `admin/js/cuentas_corrientes.js`, `rendiciones/aprobar_gira.php`, `rendiciones/aprobar_rendicion.php`, `rendiciones/vendedor.php`, `index.html` y `script.js`, reemplazándolos por SVGs vectoriales y entidades HTML.
- **Blindaje de Consultas Cross-DB:** En `api/get_clientes.php` y `api/get_facturas_cliente.php`, los esquemas cruzados dinámicos (`{$db_origen}`) se envuelven estrictamente con backticks.
- **Expansión del Verificador de Despliegue (`scripts/verify_release.php`):** Auditoría automatizada de sintaxis PHP (109 archivos), contrato de esquema (17 tablas), contrato de seguridad global (56 archivos backend sin `?`, sin `DELETE` en tablas de negocio y con sanitización de errores), contrato de frontend (37 archivos sin emojis) y paridad SHA-256 exacta (233 archivos idénticos).
- **Checklist de Migraciones de Producción:** Creado `docs/CHECKLIST_MIGRACIONES_PRODUCCION.md` para ejecución controlada vía phpMyAdmin con consultas de verificación post-migración.

### Cierre Final Pre-Producción — Rendiciones de Gastos (2026-09-02)
- **Alineación Total de DDL y Esquema (`setup.sql` y `setup_rendiciones.sql`):** Alineadas completamente las definiciones de tablas centrales con el esquema productivo consolidado: `rendiciones_gastos` (soporte `PENDIENTE_APROBACION_RESPONSABLE`, `verificado_tesoreria_at`, `verificado_tesoreria_por`, `pdf_planilla_url`, índice y FK a usuarios), `rendicion_documentos` (`numero_documento_original`, `monto_original`, `editado_por`, `editado_at`, `motivo_edicion`, `document_hash_bloqueante` STORED GENERATED, `uq_rendicion_document_hash_bloqueante`, índice y FK a usuarios), `solicitudes_aprobacion` (`APROBACION_RENDICION`, `APROBADA_TOPE` y actualización de constraint `chk_solicitud_objetivo`).
- **Migración Incremental Idempotente:** Creada `config/migrations/2026_09_02_indices_y_fks_auditoria.sql` con limpieza preventiva de registros huérfanos, creación condicional mediante `information_schema` de columnas, índices y claves foráneas (`fk_rendicion_verificado_usuario`, `fk_documento_editado_usuario`) para bases de datos migradas.
- **Preservación Inmutable de Folio Original (`numero_documento_original`):** En `admin/api/rendiciones/corregir_documento.php` y `get_detalle_rendicion.php`, la primera corrección de folio persiste `numero_documento_original = COALESCE(numero_documento_original, numero_documento)`. Correcciones sucesivas y correcciones de monto conservan el folio original digitado por el vendedor. Respuestas JSON y auditorías exponen el valor original.
- **Auditoría Individual Append-Only por Comprobante en `validarDocumentos()`:** En `services/RendicionesService.php`, cada comprobante validado genera un registro individual append-only en `rendicion_historial_estados` con acción `VALIDAR_DOCUMENTO`, enlazando `documento_id`, estados anterior/nuevo, montos anterior/nuevo, decisión, motivo y folios. Se preserva el evento de resumen de cabecera; cualquier falla detiene la operación y ejecuta `ROLLBACK` total.
- **Aislamiento Seguro de Artefactos QA y Limpieza en Disco:** Propiedad estática `RendicionPlanillaPdf::$testUploadDir` restringida exclusivamente a entornos CLI (`php_sapi_name() === 'cli'`). Limpieza en `finally` de directorios temporales en suites de prueba. Eliminación íntegra de 67 archivos PDF residuales de QA en `uploads/` (cero archivos de prueba remanentes).
- **Prueba de Instalación Limpia y Detección de Schema Drift:** Implementada prueba automatizada contra base temporal `bd_modulo_cobranzas_qa_setup` sustituyendo exclusivamente nombres de base, con validación estricta de `SELECT DATABASE()`. Confirmada la creación de las 17 tablas centrales y cero schema drift contra la base migrada.
- **Suite de Pruebas Automatizadas Extendida (148 Comprobaciones):** 148 comprobaciones PASS en `scripts/test_rendiciones.php` (incorporando las 15 pruebas obligatorias de cierre) y 40 comprobaciones PASS en `scripts/test_approval_workflow.php`, con transaccionalidad reversible y `ROLLBACK`.
- **Paridad 100% SHA-256:** Réplica idéntica de todos los componentes en `dist/cheques_cobranza/app/`, certificada al 100% por `scripts/verify_release.php` (232 archivos auditados).

### Reutilización Controlada de Boletas Rechazadas y Endurecimientos P1 (2026-09-02)
- **Reutilización Controlada de Boletas:** Reemplazado `uq_rendicion_document_hash` por la columna generada STORED nullable `document_hash_bloqueante` y el índice único `uq_rendicion_document_hash_bloqueante`. Los estados `BORRADOR`, `PENDIENTE` y `APROBADO` bloquean el comprobante en todo el holding (incluso con rendición `PAGADA`). Los estados `RECHAZADO` y `DESCARTADO` generan `NULL` en la columna generada, permitiendo al vendedor volver a presentar el comprobante en una nueva rendición con registro independiente y sin mutar el histórico (Zero Delete).
- **Control Antifraude en Carga del Vendedor:** En `api/rendiciones/guardar_documento_bolsa.php`, añadida pre-verificación SELECT de estados bloqueantes y captura atómica de `isDuplicateKey`, respondiendo en ambos casos HTTP 409 con el mensaje controlado `"Este comprobante ya fue registrado en una rendición activa, aprobada o pendiente."`.
- **Corrección Administrativa de Folio:** En `admin/api/rendiciones/corregir_documento.php`, validación previa contra estados bloqueantes (`BORRADOR`, `PENDIENTE`, `APROBADO`) respondiendo HTTP 409 controlado ante colisión. Permite corrección si el folio coincide únicamente con registros en `RECHAZADO` o `DESCARTADO`.
- **Endurecimiento P1 en Aprobación con Tope:** En `services/ApprovalWorkflowService.php` (`resolveByToken()` y `applyRenditionApproval()`), se eliminó definitivamente el fallback `$baseDocs`. Se valida estrictamente antes de tocar la solicitud o consumir el token que `monto_maximo_aprobable > 0`, `totalDocsAprobados > 0` y `cappedAmount > 0`. Si cualquiera de estas condiciones no se cumple, lanza `DomainException` sin consumir el token (`token_usado_at` en NULL, solicitud en `PENDIENTE_DECISION`). El monto aprobado con tope se calcula exclusivamente como `$cappedAmount = min($maxAprobable, $totalDocsAprobados, $totalRendido)`.
- **Endurecimiento P1 en Estados Documentales:** En `services/RendicionesService.php` (`verificarYEnviar()`), se comprueba estrictamente que cada comprobante activo esté exactamente en `['APROBADO', 'RECHAZADO']`. Se bloquea con `DomainException` si existe algún ítem en `BORRADOR`, `DESCARTADO`, `PENDIENTE` o desconocido. Exige al menos un documento aprobado y suma validada mayor a cero.
- **Migración DDL Independiente:** Creado `config/migrations/2026_09_02_reutilizacion_documentos_rechazados.sql` para ejecución manual en phpMyAdmin con verificación y rollback documentado.
- **Batería de Pruebas Automatizadas:** 133 comprobaciones en `scripts/test_rendiciones.php` y 40 comprobaciones en `scripts/test_approval_workflow.php`, cubriendo los 23 casos mínimos obligatorios y el ciclo completo con rollback.

### Corrección Urgente P0 — Blindaje de Aprobaciones de Rendiciones (2026-09-02)
- **P0-1: Eliminación del Bypass en `VERIFICAR_Y_ENVIAR`:** Las decisiones documentales ahora se procesan exclusivamente mediante la acción canónica `VALIDAR_DOCUMENTOS`. `VERIFICAR_Y_ENVIAR` en `admin/api/rendiciones/cambiar_estado.php` y `services/RendicionesService.php` rechaza con HTTP 422 si recibe el campo `decisiones`. Comprueba obligatoriamente que todos los comprobantes activos estén resueltos en `APROBADO` o `RECHAZADO` (rechaza si hay alguno en `PENDIENTE`), que exista al menos un comprobante aprobado y que el total validado sea mayor a cero. Se eliminó completamente el fallback peligroso que sustituía un total validado de cero por el `monto_total_rendido`. No altera documentos ni presupuestos.
- **P0-2: Validación Estricta de Decisiones Documentales:** Implementado método canónico `RendicionesService::validarDocumentos()`. Solo acepta decisiones `APROBAR` o `RECHAZAR` (cualquier otro valor o valor vacío responde HTTP 422). Sin `continue` silencioso: rechaza explícitamente `documento_id` ausente o inválido, inexistente, de otra rendición, duplicado en el payload, montos validados menores o iguales a cero, montos superiores al importe rendido original y rechazos sin motivo obligatorio. Conserva orden FIFO (`ORDER BY fecha_emision ASC, id ASC`), atomicidad con `FOR UPDATE`, y recalcula atómicamente el total validado, reserva comprometida, máximo aprobable, exceso y `monto_utilizado` del presupuesto, manteniendo la cabecera en `EN_REVISION_TESORERIA`.
- **P0-3: Blindaje de `APROBADA_TOPE` (Tope Cero y Límites):** En `services/ApprovalWorkflowService.php`, se eliminó el fallback que transformaba un `monto_maximo_aprobable` menor o igual a cero en el pago de la totalidad de la rendición. Si el máximo aprobable es `<= 0`, lanza `DomainException` antes de modificar la solicitud, garantizando que el token NO se consuma (`token_usado_at` permanece nulo, estado `PENDIENTE_DECISION`). El monto aprobado se calcula como el menor valor entre el máximo aprobable, el total vigente de documentos aprobados y el total rendido, almacenando el remanente en `monto_exceso_no_reembolsable`.
- **P1 Asociado: Cancelación Auditable en Rechazos Directos de Tesorería:** Centralizado en `ApprovalWorkflowService::cancelRequest($pdo, $solicitudId, $actor, $motivo, false)`. Al rechazar por Tesorería, se cancela quirúrgicamente dentro de la misma transacción únicamente la solicitud abierta vinculada en `solicitud_excepcion_id` perteneciente a la rendición, dejando intactas solicitudes históricas ya resueltas y registrando el evento en `solicitud_aprobacion_historial`. Se libera siempre exactamente la reserva almacenada en `monto_maximo_aprobable`.
- **Suite de Pruebas Automatizadas:** 104 comprobaciones superadas en `scripts/test_rendiciones.php` (cubriendo explícitamente los 22 casos obligatorios) y 38 comprobaciones superadas en `scripts/test_approval_workflow.php` con transacciones reversibles y `ROLLBACK`.
- **Paridad SHA-256:** Réplica idéntica en `dist/cheques_cobranza/app/` y verificación al 100% con `scripts/verify_release.php`.

### Corrección Urgente P0 — Integridad Financiera y Aprobación Obligatoria (2026-09-02)
- **Ajuste de Presupuesto en Corrección de Comprobantes:** Corrección del cálculo financiero en `admin/api/rendiciones/corregir_documento.php` para calcular el impacto en `presupuestos_vendedores.monto_utilizado` estrictamente por la diferencia de reservas comprometidas (`reserva_nueva - reserva_anterior`), donde `reserva = min(total_rendido, saldo_disponible_al_enviar)`. Bloqueo `FOR UPDATE` en cascada (documento → rendición → presupuesto), eliminación de interpolaciones de SQL y auditoría inmutable en historial y `audit_logs`.
- **Liberación Exacta en Rechazos:** En `services/ApprovalWorkflowService.php` y `admin/api/rendiciones/cambiar_estado.php`, al rechazar se libera siempre exactamente `monto_maximo_aprobable` (la reserva real comprometida) mediante `GREATEST(0, monto_utilizado - :monto)` sin depender de flags booleanos. En rechazos directos por Tesorería, se exige motivo obligatorio y se cancela quirúrgicamente únicamente la solicitud abierta vinculada en `solicitud_excepcion_id` perteneciente a la rendición, preservando intactas solicitudes históricas resueltas.
- **Erradicación del Bypass de Aprobación Gerencial:** Desactivadas las transiciones directas a `APROBADA` y `APROBADA_PARCIAL` desde Tesorería en backend (`APROBAR_TOTAL` y `APROBAR_PARCIAL` devuelven HTTP 409 `DomainException`). La aprobación a `APROBADA` solo puede ocurrir a través de la decisión gerencial con Magic Link en `ApprovalWorkflowService::resolveByToken()`. Se introdujo la acción `VALIDAR_DOCUMENTOS` para revisión documental previa, la cual recalcula montos validados y reservas en la misma transacción manteniendo la cabecera en `EN_REVISION_TESORERIA`. Deprecados `DOCUMENTOS_FISICOS_RECIBIDOS` y `APROBADA_PARCIAL` para rendiciones nuevas. Auditoría de `APROBADA_TOPE` corregida a `APROBAR_SOLICITUD_HASTA_TOPE`.
- **Limpieza de Emojis en Frontend:** Erradicados el 100% de los emojis en `admin/rendiciones.php`, `admin/js/rendiciones.js` y `rendiciones/vendedor.js`, sustituyéndolos por iconos vectoriales SVG limpios y terminología técnica profesional.
- **Suite de Pruebas Automatizadas con ROLLBACK y Multi-PDO:** Implementados 10 casos obligatorios con transacciones y 2 conexiones PDO independientes para concurrencia en `scripts/test_rendiciones.php` (80 comprobaciones PASS) y `scripts/test_approval_workflow.php` (35 comprobaciones PASS).
- **Paridad 100% SHA-256:** Sincronizados todos los archivos modificados a `dist/cheques_cobranza/app/` y validados con `scripts/verify_release.php` (230 archivos con SHA-256 idéntico).

### Módulo 3 — Flujo Integral de Aprobación con Responsable y Planilla PDF (2026-09-01)
- **Formato Nativo Tamaño Carta (Letter Landscape):** La planilla PDF se redimensionó a formato Carta (279.4mm x 215.9mm con ancho imprimible de 259mm y márgenes de 10mm), garantizando que no se corte el borde derecho ni las firmas al imprimir en impresoras estándar configuradas en papel Carta o A4.
- **Opción Intermedia de Aprobación hasta el Tope para el Responsable:** Al autorizar rendiciones con exceso, el responsable dispone ahora de una opción intermedia ("Aprobar hasta el Tope"), permitiendo autorizar únicamente el monto ordinario asignado y marcar el exceso como no reembolsable sin rechazar la rendición completa.
- **Desglose Financiero Explícito en la Grilla de la Planilla PDF:** En caso de existir exceso presupuestario (aprobado o no reembolsable), la tabla de comprobantes del PDF incluye debajo del "TOTAL GENERAL DE GASTOS" filas dedicadas con el descuento del exceso no reembolsable o adición de exceso autorizado y el "TOTAL APROBADO A PAGO (LÍQUIDO A REEMBOLSAR)".
- **Corrección de Número de Boleta por Tesorería:** Tesorería puede corregir el número de documento y folio desde el modal de edición. El sistema recalcula el `document_hash` con el nuevo número, impidiendo que el vendedor reingrese el mismo comprobante duplicado.
- **Nuevo Flujo de Aprobación Unificado:** Cuando el vendedor envía la rendición a revisión de Tesorería (`EN_REVISION_TESORERIA`), Tesorería verifica las fotos de las boletas contra los montos digitados. El botón principal de aprobación ejecuta la acción "Verificar y Enviar a Responsable", abriendo un selector para escoger cuál de los dos responsables configurados autorizará la rendición.
- **Solicitud Unificada con Magic Token:** Se despacha un correo HTML responsive al Responsable con el desglose de los comprobantes, fotos, datos SII (en cenas de clientes), y en caso de haber un exceso presupuestario, una alerta destacada que autoriza simultáneamente la rendición y la cobertura de dicho exceso en 1 solo clic.
- **Resolución y Emisión Automática de Planilla PDF (Tipo Excel):** Al aprobar la rendición desde el Magic Link (`/rendiciones/aprobar_rendicion.php`), la rendición transiciona a estado `APROBADA` y se genera de inmediato una Planilla Oficial en formato PDF apaisado tipo Excel (`services/RendicionPlanillaPdf.php`), estructurada con cuadrícula, columnas detalladas de documentos, resumen por categoría y recuadros de firma (Vendedor, Verificación Tesorería y Aprobador).
- **Edición Rápida de Comprobantes por Digitaciones Erróneas:** Tesorería cuenta con un modal y botón inline ("Editar monto") en cada comprobante para corregir discrepancias entre la foto y lo digitado. Se audita el `monto_original`, usuario, fecha y motivo, recalculando los totales de la rendición y el presupuesto en tiempo real (`admin/api/rendiciones/corregir_documento.php`).
- **Aprobación Parcial Opcional:** Se mantiene como acción secundaria la posibilidad de resolver ítems puntuales (aprobar/rechazar boletas individuales con motivo obligatorio) antes de remitir a Gerencia.
- **Eliminación de Traba de Documentos Físicos:** Se habilitó el paso directo de `APROBADA` a `PAGADA` sin exigir la marcación previa obligatoria de recepción de documentos físicos originales.
- **Migración de Esquema:** Aplicada migración `config/migrations/2026_09_01_flujo_aprobacion_responsable.sql` añadiendo el estado `PENDIENTE_APROBACION_RESPONSABLE`, campos de verificación, auditoría de edición de montos y almacenamiento de la URL de la planilla PDF.

### Módulo 3 — Flujo de 3 opciones de Tesorería ante excesos (2026-09-01)
- **Opciones de Decisión:** En rendiciones con exceso de presupuesto, Tesorería dispone de 3 acciones inmediatas: (1) Aprobar hasta el tope ordinario disponible con liquidación FIFO, (2) Solicitar aprobación de exceso a Gerencia mediante Magic Link, y (3) Rechazar con motivo obligatorio y liberación de fondos.
- **Transición Directa:** Se actualizó `RendicionesService::TRANSICIONES` para admitir `EN_REVISION_TESORERIA -> APROBADA`, permitiendo resolver la rendición sin forzar el paso intermedio de recepción física.
- **Confirmación con Montos Claros:** El diálogo de confirmación en `admin/js/rendiciones.js` desglosa exactamente el monto aprobado hasta el tope y el saldo no reembolsable.

### Módulo 3 — Acreditación PDF de Giras y Responsive Tablet (2026-09-01)
- **Comprobante de Gira Aprobada:** Creado `services/GiraApprovalPdf.php` y `admin/reportes/comprobante_aprobacion_gira.php` para emitir en demanda el PDF oficial que certifica la gira comercial autorizada con su resumen financiero, justificación, firma electrónica y hash SHA-256 inmutable.
- **Acceso Directo en Presupuestos:** Integrado botón "Certificado PDF" en cada fila de presupuesto de gira en estado `APROBADA` dentro de `admin/js/rendiciones.js`.
- **Responsive Tablet:** Unificación de media queries desde `min-width: 640px` en `rendiciones/vendedor.css`, ajustando el contenedor principal a un ancho óptimo de 940px en iPads y Surface Pro.
- **Cachebuster Dinámico:** Implementado `filemtime` en la carga de CSS de `rendiciones/vendedor.php` para evitar que navegadores sirvan hojas de estilo obsoletas tras actualizaciones de diseño.

### Módulo 3 — Dashboard de aprobaciones y certificación local (2026-09-01)
- El Dashboard agrega una lectura ejecutiva de solicitudes de Gira y Excepción mensual: pendientes, correos fallidos, aprobadas, rechazadas, vencidas, canceladas, tasa de aprobación, respuesta promedio y antigüedad máxima.
- Las señales de negocio alertan solicitudes sin entregar y esperas superiores a 48 horas, sin exponer correos, responsables ni nombres libres de giras.
- La prueba oficial `scripts/test_approval_workflow.php` ejecuta 34 comprobaciones transaccionales con rollback, incluyendo fallo/reenvío SMTP simulado y exactitud de métricas; se complementa con 38 pruebas funcionales.
- Smoke local del Shell: login administrativo, Dashboard HTTP 200, panel y versión JS correctos. El SMTP real no se disparó para evitar mensajes a responsables configurados sin un destinatario de prueba autorizado.
- SQL nuevo para phpMyAdmin: ninguno; se mantiene como prerrequisito `config/migrations/2026_08_28_topes_y_flujo_aprobaciones.sql`.

### Módulo 3 — Estabilización de topes y aprobaciones A–G (2026-09-01)
- El alta y edición de giras solicita justificación comercial y uno de los dos responsables configurados; el botón rápido “Agregar gira” conserva la identidad ERP y evita el error 422 por datos ausentes.
- `gestion_presupuestos.php`, `resolver_gira.php` y `aprobar_exceso.php` consumen el servicio transaccional común para crear, rotar, cancelar y resolver solicitudes versionadas. El resultado real del correo queda en `PENDIENTE_DECISION` o `ENVIO_FALLIDO` y Tesorería recibe la decisión de la gira mediante su correo configurable.
- Un aumento de monto en una gira aprobada invalida la autorización monetaria anterior y crea una nueva solicitud; una reducción auditada conserva la aprobación vigente.
- Tesorería puede solicitar opcionalmente sólo el exceso mensual desde una rendición en revisión. Rechazar esa excepción mantiene la rendición disponible para pago hasta su tope ordinario.
- Las rutas públicas de Magic Link son relativas al directorio desplegado, por lo que funcionan bajo `/form` y `/cobranza_cheques/app`; las páginas resueltas dejan un estado final inequívoco.
- Se eliminó la dependencia obligatoria de `mbstring` mediante helpers Unicode con fallback y se reforzó el verificador para detectar placeholders posicionales sólo dentro de `prepare()`.
- QA local: 28 pruebas del workflow con rollback, 38 pruebas funcionales del módulo, sintaxis PHP y contratos de seguridad aprobados. SQL nuevo para esta corrección: ninguno; utiliza la migración `2026_08_28_topes_y_flujo_aprobaciones.sql`.

### Módulo 3 — Fases A y B de topes y aprobaciones (2026-08-28)
- Se incorporó el contrato aditivo para topes: máximo aprobable, exceso no reembolsable y marca de aplicación del tope en cada rendición; las giras incorporan estado de aprobación, justificación y solicitud vigente.
- Nueva entidad `solicitudes_aprobacion` para versionar por separado autorizaciones de gira y excepciones mensuales, con selección de un responsable, snapshots, tokens SHA-256 de 48 horas, reenvío, fallo de correo, cancelación lógica y decisión de uso único.
- Nueva bitácora append-only `solicitud_aprobacion_historial` para registrar creación, envío, fallo, rotación, vencimiento, cancelación y resolución.
- `ApprovalWorkflowService` concentra el flujo transaccional y preserva la regla clave: rechazar una excepción no rechaza la rendición base; aprobarla sólo amplía su máximo pagable. Autorizar una gira habilita el fondo, no sus comprobantes.
- Migración `config/migrations/2026_08_28_topes_y_flujo_aprobaciones.sql` aplicada e idempotente en Laragon. QA reforzado: 28 comprobaciones transaccionales PASS con rollback, instalación limpia, doble ejecución y migración sobre un clon temporal del esquema anterior conservando 41 presupuestos, 44 rendiciones, 46 documentos, 53 hitos y 2 aprobadores. Integración visible de endpoints/UI queda en Fases C–H.

### Documentación — Política futura de topes y giras (2026-08-28)
- Se documentó, sin modificar aún el comportamiento operativo, la política directiva de tope mensual, reserva de pendientes, último informe con exceso, liquidación FIFO y excepción gerencial opcional.
- El plan incorpora aprobación previa de fondos de gira por uno de dos responsables equivalentes, seleccionado por Tesorería, con token de 48 horas, reenvío y gira oculta hasta aprobación.
- Se agregó la matriz integral M01–M20 y G01–G19 en `docs/PLAN_TOPES_Y_APROBACIONES_RENDICIONES.md`, junto con fases, archivos, seguridad y criterios de aceptación.
- El plan fue aprobado; Fases A y B completadas. Las Fases C–H permanecen pendientes y el flujo operativo actual todavía no consume el nuevo servicio.

### Suite ERP — Estabilización integral de sesiones (2026-08-28)
- Admin y vendedores dejan de compartir `PHPSESSID`: usan cookies independientes (`AUTOMARCO_ADMIN_SID` y `AUTOMARCO_SELLER_SID`), por lo que cerrar el portal vendedor ya no destruye la sesión administrativa abierta en el mismo navegador.
- El almacenamiento físico de PHP se amplía a 24 horas y queda por encima de los límites lógicos: Admin admite 12 horas de inactividad con máximo absoluto de 16 horas; vendedor, 12 horas de inactividad y máximo de 24 horas.
- El Shell y ambos formularios de vendedor incorporan heartbeat cada cinco minutos, renovación controlada de cookie y recuperación automática ante un primer `401`, con un solo reintento para evitar bucles.
- La identidad recuperable del vendedor conserva código ERP **y empresa** en `sessionStorage`; ya no usa el antiguo ID aislado en `localStorage`, que podía cruzar empresas o fallar tras limpiar la URL.
- El login administrativo conserva la página solicitada mediante `return_to` seguro y muestra una salida clara cuando la sesión realmente terminó.
- El portal comercial legado extiende su token activo hasta 12 horas y lo renueva de forma deslizante durante el uso, manteniendo cookie `HttpOnly` y `SameSite=Lax`.
- Nuevos endpoints de estado: `GET /admin/api/auth/session_status.php` y `GET /api/auth/session_vendedor.php`. SQL nuevo para phpMyAdmin: ninguno.

### Módulo 3 — Flujo integral de giras y analítica estandarizada (2026-08-26)
- La creación de giras deja de depender de un período mensual oculto: `fecha_inicio` determina `periodo_mes`, y nombre, inicio, término y orden cronológico entregan errores separados y precisos.
- El formulario solicita únicamente identidad ERP, nombre operativo, fechas y monto; el nombre queda limitado a 100 caracteres según el esquema vigente.
- Al consolidar, el backend bloquea comprobantes fuera del rango de la gira y calcula saldo/exceso exclusivamente contra el fondo seleccionado, sin afectar el presupuesto mensual concurrente.
- Portal vendedor, detalle de Tesorería, correo y página Magic Token identifican explícitamente cuando un exceso corresponde a una gira.
- El Dashboard agrega comparación estandarizada `Presupuestos mensuales` vs. `Giras comerciales` con fondos activos, promedio, asignado, aprobado, pendiente, ejecución y excesos aprobados; no agrupa ni expone nombres libres de giras.
- Seeder local ampliado a 39 fondos demo, incluidos 3 de gira. Validación: 38 comprobaciones PASS; SQL nuevo para phpMyAdmin: ninguno.

### Módulo 3 — Analítica histórica por vendedor (2026-08-26)
- El Dashboard incorpora un analizador de 6/12 meses por identidad `(empresa_id, vendedor_id ERP)`, con presupuesto, gasto aprobado real, monto pendiente, ejecución, ticket promedio, excesos y rechazos.
- La comparación permite seleccionar un vendedor y revisar su trayectoria mensual contra el tope asignado; rechazados no suman gasto y pendientes se muestran separados.
- Se agregan señales transparentes para decisiones: fondos sin ejecución, montos pendientes, concentración del gasto, excesos recurrentes, alta tasa de rechazo y cupos próximos al límite.
- Nuevo endpoint consolidado de sólo lectura `get_dashboard_analitico.php`, protegido por `rendiciones.view`, que evita el patrón N+1 para la historia temporal.
- Seeder local idempotente `scripts/seed_rendiciones_dashboard_demo.php`: consulta seis identidades ERP reales en modo lectura y genera seis meses de escenarios demostrativos únicamente con `APP_ENV=local`.
- Validación local: 36 presupuestos, 38 rendiciones/documentos/hitos históricos demo; una segunda ejecución creó cero duplicados. SQL nuevo para phpMyAdmin: ninguno.

### Suite ERP — Recarga interna y métricas aprobadas (2026-08-26)
- El Shell incorpora “Recargar”, que actualiza por API el módulo activo sin navegación, sin F5 y conservando sesión, filtros, pestaña y selección cuando corresponde.
- El Dashboard de Rendiciones excluye pendientes y rechazadas: monto, ejecución, categorías y empresas se calculan sólo con `APROBADA`, `APROBADA_PARCIAL` y `PAGADA`, usando `monto_total_aprobado`/`monto_validado`.
- La píldora “Aprobadas” incluye rendiciones pagadas, ya que representan aprobaciones cerradas.
- SQL nuevo para phpMyAdmin: ninguno.

### Módulo 3 — Cancelación de excesos por Tesorería (2026-08-26)
- Una rendición en `PENDIENTE_APROBACION_EXCESO` incorpora “Rechazar sin enviar” antes del correo y “Cancelar solicitud y rechazar” si ya existía una emisión.
- El motivo es obligatorio; la transacción rechaza documentos, libera el total comprometido, invalida el Magic Token y registra historial/auditoría sin enviar una nueva notificación.
- SQL nuevo para phpMyAdmin: ninguno.

### Suite ERP — Identidad visual del holding (2026-08-26)
- La cabecera compartida reemplaza la cápsula textual “AUTOMARCO” por el logotipo oficial `LOGO-HOLDING-AUTOMARCO.png`, con dimensiones responsive y sin alterar el App Switcher.

### Módulo 3 — Comprobante de aprobación gerencial (2026-08-26)
- **Resolución pública inequívoca**: después de aprobar o rechazar, la página Magic Token retira textarea y botones y deja un único estado final con responsable y continuidad operativa.
- **PDF imprimible**: toda aprobación de exceso dispone en el detalle de Tesorería de un comprobante generado en demanda con rendición, montos, documentos, fecha, código de verificación y firma textual del responsable (nombre y cargo).
- **Semántica preservada**: el PDF certifica la autorización del exceso, no el pago ni la aprobación final; la rendición continúa en revisión de Tesorería.
- **SQL nuevo para phpMyAdmin**: ninguno; utiliza los snapshots y campos de auditoría existentes.
- **Impresión económica**: el comprobante adopta una composición documental monocromática, sin fondos sólidos ni rectángulos rellenos, con líneas finas y contraste optimizado para impresoras láser o tinta en blanco y negro.

### Módulo 3 — Responsables configurables y correo de exceso (2026-08-26)
- **Cero destinatarios hardcodeados**: el Administrador configura exactamente dos responsables con nombre, cargo y correo; Tesorería elige a cuál enviar cada solicitud.
- **Emisión controlada por Tesorería**: el vendedor sólo deja el exceso pendiente. El Magic Token se genera al seleccionar responsable, y cada reenvío invalida el enlace anterior.
- **Decisión informada**: correo y página segura muestran empresa, vendedor/código ERP, presupuesto, comprometido previo, saldo, rendido, exceso, notas, comprobantes y antecedentes SII de cenas.
- **Auditoría histórica**: la rendición conserva snapshots del responsable, usuario emisor y fecha; la resolución atribuye al destinatario seleccionado, incluso si la configuración cambia después.
- **SQL para phpMyAdmin**: importar `config/migrations/2026_08_26_aprobadores_rendiciones.sql`; migración aditiva e idempotente, sin eliminaciones.
- **Certificación local**: migración aplicada y reejecutada en MySQL 8.4.3 de Laragon; 28 pruebas transaccionales PASS, POST sin CSRF rechazado con HTTP 403 y release con 205 archivos root/dist idénticos.
- **Configuración global restaurada**: “Configuración” vuelve a abrir en toda la Suite el panel histórico de corte, correos internos, digitadoras, asignaciones por empresa y Google Sheets. Rendiciones incorpora “Aprobadores” como acceso independiente, además del disponible en Vendedores.
- **Modal compartido reparado**: `modal_config_cc.css` ahora muestra `#modalConfigCC` cuando JavaScript retira `hidden`; anteriormente la regla base `display:none` prevalecía y el diálogo quedaba invisible aunque el botón y la API funcionaran. Se renovó la versión CSS en Cheques, Cuentas Corrientes y Rendiciones.

### Módulo 3 — Bloqueo claro sin presupuesto (2026-08-25)
- **Consolidación protegida**: un vendedor sin presupuesto activo ya no puede abrir ni confirmar el envío de una rendición; recibe un aviso que indica solicitar la asignación a Gerencia.
- **Privacidad operativa**: el aviso de exceso deja de identificar al aprobador por nombre y comunica únicamente que la solicitud será enviada a Gerencia.
- **SQL nuevo para phpMyAdmin**: ninguno.

### Módulo 3 — Presupuesto aprobado vs. pendiente (2026-08-25)
- **Lectura financiera corregida**: el portal vendedor separa el monto realmente aprobado del importe enviado que todavía espera resolución de Tesorería.
- **Saldo prudente preservado**: tanto lo aprobado como lo pendiente permanecen comprometidos y reducen el saldo para nuevas boletas, evitando doble imputación.
- **Barra segmentada accesible**: mensual y giras muestran proporción aprobada en verde, pendiente en ámbar y disponible en neutro, con importes y porcentajes textuales.
- **Contrato API ampliado**: `get_bolsa_gastos.php` deriva `monto_aprobado` desde rendiciones resueltas y entrega `monto_pendiente` sin cambiar el esquema.
- **SQL nuevo para phpMyAdmin**: ninguno; todos los valores nuevos son derivados de columnas existentes.

### Módulo 3 — Identidad ERP y asignación de presupuestos (2026-08-25)
- **Selector oficial de vendedores**: el modal elimina la digitación manual de código, nombre y correo; empresa y vendedor se cargan desde los cuatro ERP mediante un combobox accesible con búsqueda y navegación por teclado.
- **Revalidación backend**: `gestion_presupuestos.php` ya no confía en datos descriptivos enviados por el navegador. Resuelve nuevamente `(empresa_id, cli_vendedor)` y persiste nombre/correo canónicos del ERP.
- **Homologación multiempresa visible**: la tabla muestra, por correo, en qué empresas existe cada vendedor y su código ERP local en cada una.
- **Gira sin recaptura**: presupuestos mensuales activos incorporan `+ Agregar gira`; abre el formulario con identidad y empresa bloqueadas y solicita sólo datos de gira, fechas, período y monto.
- **Pruebas locales**: `scripts/test_rendiciones.php` valida catálogo ERP, identidad canónica y coexistencia transaccional de presupuesto mensual + gira; los fixtures se revierten por completo.
- **SQL nuevo para phpMyAdmin**: ninguno. Se reutilizan `empresas`, las tablas ERP de sólo lectura y el esquema vigente de `presupuestos_vendedores`.

### Módulo 3 — Nota a Tesorería (2026-08-25)
- **Persistencia y auditoría de la nota**: `nota_vendedor` se guarda al consolidar una rendición, se incorpora a la bitácora inmutable `ENVIAR_RENDICION` y se muestra a Tesorería en el panel de detalle.
- **Migración aditiva documentada**: `rendiciones_gastos.nota_vendedor` queda definida en ambos DDL y con SQL exacto para phpMyAdmin en `docs/SQL_PRODUCCION.md`; no hay eliminaciones ni cambios destructivos.
- **Campo conectado en interfaz**: el drawer de consolidación del vendedor incorpora “Nota para Tesorería” (máximo 500 caracteres) y la envía explícitamente a `guardar_rendicion.php`.
- **Prueba E2E local completada**: validado Peaje con fotografía, exceso, nota, Magic Token de un uso, recepción física y aprobación total contra Laragon; no se enviaron correos reales.
- **Correcciones detectadas por E2E**: la cookie de vendedor se adapta a HTTP local/HTTPS productivo y la expiración de Magic Token se compara con `NOW()` de MySQL, evitando descalces de zona horaria entre PHP y la BD.
- **Tablet como superficie híbrida**: entre 600 y 1199 px, el portal de vendedor evita columnas vacías mediante grillas `auto-fit`; una sola rendición o presupuesto se presenta con ancho útil y varias tarjetas se equilibran en dos columnas. Se amplían controles, métricas, tarjetas y navegación inferior sin modificar el comportamiento móvil.
- **Presupuesto con lectura financiera**: se reemplazó la tarjeta KPI genérica por un resumen de asignado, utilizado, saldo y porcentaje de uso. El frontend consume `monto_utilizado` —campo que entrega la API— y presenta los saldos negativos como “Exceso comprometido” con estado explícito rojo, eliminando la señal incorrecta de disponible verde. Las giras usan la misma estructura y semántica.

### Módulo 3 — Rediseño Mobile-First Vendedor (Estilo Rindegastos & Hardening)
- **Edición y Descarte de Gastos en Borrador**: Al tocar cualquier tarjeta en la Bolsa de Gastos que permanezca en borrador (`rendicion_id IS NULL`), se abre el Drawer en modo edición (`Editar Gasto`) permitiendo modificar todos sus datos, sustituir o conservar la fotografía y/o descartar el comprobante mediante eliminación lógica segura.
- **Bloqueo de Modificación en Gastos Rendidos**: Comprobantes que ya forman parte de una rendición consolidada enviada a Tesorería quedan protegidos en modo solo lectura para garantizar inmutabilidad.
- **Experiencia Mobile-First Integral**: Refactorización de `rendiciones/vendedor.php`, `rendiciones/vendedor.css` y `rendiciones/vendedor.js` imitando la arquitectura de Rindegastos mobile app.
- **Corrección Contrato Cena Cliente (SII)**: Separación de `cliente_invitado_empresa` y `cliente_invitado_cargo` en el formulario y validación estricta de los 5 campos exigidos por backend antes del envío.
- **Claridad de Flujo en Consolidación**: Eliminación del falso botón "Guardar" borrador en el drawer de informe (que ejecutaba el despacho inmediato), dejando el flujo explícito hacia el botón "Enviar Rendición a Tesorería" con modal de confirmación.
- **Ergonomía Táctil y Accesibilidad (Impeccable)**: Targets táctiles >= 44x44px en toda la interfaz móvil, checklist accesible con botones `role="checkbox"` y navegación por teclado, e iconografía lineal SVG consistente con la Suite.
- **Bottom Navigation Bar fija (64px)**: Pestañas de navegación directas con soporte `safe-area-inset-bottom` para `Gastos` (con badge dinámico de borradores pendientes), `Informes` y `Presupuesto`.
- **Sincronización Dual y Paridad**: Sincronización idéntica en `dist/cheques_cobranza/app/rendiciones/` verificada con `scripts/verify_release.php` (87 archivos PHP válidos, 201 archivos SHA-256 idénticos).

### Entorno local Laragon
- **Migración local aplicada**: ejecutado `config/setup_rendiciones.sql` sobre `bd_modulo_cobranzas` en MySQL 8.4.3 de Laragon; quedaron disponibles las cuatro tablas del Módulo 3.
- **Separación local/producción**: el `.htaccess` de la raíz fuerza `APP_ENV=local`, MySQL `localhost` y URLs bajo `http://localhost/form`; `dist/cheques_cobranza/app/.htaccess` conserva exclusivamente los valores productivos.
- **Verificación de release**: `scripts/verify_release.php` valida explícitamente ambos entornos y excluye únicamente `.htaccess` de la igualdad SHA-256; los demás archivos continúan exigiendo paridad exacta.
- **SQL nuevo**: ninguno. Se aplicó localmente la migración aditiva ya documentada, sin `DROP`, `TRUNCATE` ni `DELETE`.

### Módulo 3 — Rendiciones, Fases 3 y 4
- **Bandeja unificada de revisión**: los estados `PENDIENTE_APROBACION_EXCESO` y `DOCUMENTOS_FISICOS_RECIBIDOS` se incorporan al filtro principal “Bandeja por revisar”, reduciendo filtros operativos sin perder trazabilidad; las filas mantienen badges explícitos de exceso y su estado de recepción física.
- **Sidebar colapsable**: el panel de submódulos puede contraerse a iconos en escritorio, conserva títulos accesibles y recuerda la preferencia durante la sesión del navegador; en móvil se preserva la navegación horizontal completa.
- **Navegación por submódulos**: Rendiciones incorpora sidebar propia con Bandeja, Dashboard y Vendedores; la selección es instantánea, accesible y persistente mediante `#bandeja`, `#dashboard` y `#vendedores`.
- **Bandeja alineada con Cheques Cobranza**: se adoptó el patrón operativo de píldoras de estado, búsqueda/filtros compactos, tabla maestra y detalle lateral con alerta de exceso, metadatos, comprobantes SII, trazabilidad y acciones de Tesorería.
- **Analítica y control presupuestario**: nuevo Dashboard con ejecución global, tasa de excesos, distribución por categoría y comparativa por empresa; el submódulo Vendedores concentra presupuestos mensuales y giras con alta, edición y baja lógica sobre las APIs existentes.
- **Refactor UI/UX Impeccable**: pulido integral de `admin/rendiciones.php` con Outfit para títulos/cifras, Plus Jakarta Sans para operación densa, strip ejecutivo de KPIs, filtros compactos, CTA corporativo y split-view con estados laterales claramente diferenciados.
- **Detalle accesible por pestañas**: separación de Comprobantes, Datos Tributarios SII y Auditoría mediante `tablist`/`tabpanel`, selección visible y navegación de teclado con flechas, Inicio y Fin, sin alterar APIs ni contratos de datos.
- **Responsive y accesibilidad**: KPIs 2×2 en móvil, controles mínimos de 44 px, foco visible, tabulares financieros, selección y scrollbars tematizados, estados hover/active/disabled y ausencia de overflow horizontal en 390 px.
- **Portal vendedor integrado**: creada `rendiciones/vendedor.php` con CSS/JS Vanilla, KPIs de mensual/giras, bolsa de borradores seleccionable, envío por presupuesto y seguimiento de estados.
- **Carga adaptativa**: Peaje Rápido solicita sólo fecha, monto y fotografía; Cena Cliente activa los cinco campos tributarios obligatorios para respaldo SII.
- **Semáforo presupuestario**: calcula el total seleccionado contra el saldo disponible y anticipa el flujo de Magic Token cuando existe exceso.
- **Integración portal terreno**: agregada entrada “Rendir Gastos / Viáticos” en los portales `vendedores` y `vendedores_DEV`, transfiriendo `vend_cod`, empresa y nombre al punto unificado de autenticación.
- **Bandeja Tesorería**: `admin/rendiciones.php` deja de ser placeholder e incorpora KPIs, filtros, patrón maestro–detalle, documentos, información de cenas, historial y acciones según transición/RBAC.
- **Lightbox y notificaciones**: comprobantes y Toasts reutilizan exclusivamente `shared_ui.js` y `shell.css`, sin duplicar visor ni manejadores comunes.
- **Presupuestos**: modal administrativo para crear, editar y desactivar lógicamente presupuestos mensuales o giras, con validación adaptativa de fechas y monto.
- **SQL productivo**: SQL nuevo: ninguno. Esta refactorización reutiliza el esquema y las APIs existentes; se mantiene como único prerequisito productivo pendiente la migración de Fases 1 y 2 registrada en `config/setup_rendiciones.sql`.
- **Validación local**: sintaxis PHP/JS, sesión vendedor/admin, responsive móvil, formulario Peaje/Cena SII, bandeja vacía y modal de giras comprobados sin errores de consola.

### Módulo 3 — Rendiciones, Fases 1 y 2
- **DDL y migración productiva**: agregadas `presupuestos_vendedores`, `rendiciones_gastos`, `rendicion_documentos` y `rendicion_historial_estados` tanto a `config/setup.sql` como a `config/setup_rendiciones.sql`, archivo idempotente listo para phpMyAdmin. El procedimiento productivo queda registrado en `docs/SQL_PRODUCCION.md`.
- **Bolsa del vendedor**: carga segura de fotografías, campos adaptativos de Peaje/Cena Cliente, descarte lógico, historial propio y consolidación de lotes sin aceptar identidad desde el payload.
- **Presupuestos y antifraude**: fondos mensuales/giras bloqueados transaccionalmente, saldos comprometidos y `document_hash` SHA-256 único.
- **Magic Token**: token hasheado, expiración de 48 horas, consumo único por `POST` tras confirmación humana y notificaciones SMTP responsivas.
- **Tesorería/Admin**: listado, detalle SII, gestión de presupuestos, recepción física, aprobación total/parcial, rechazo y pago con RBAC, CSRF, historial y `audit_logs`.
- **Pruebas**: `scripts/test_rendiciones.php` valida esquema local, hashes, transiciones, duplicidad y uso único; `verify_release.php` exige las 14 tablas centrales y la migración productiva.

### RBAC, sesión unificada y administración de usuarios
- **Matriz de permisos central (`config/auth.php`)**: permisos granulares para Cheques, Cuentas Corrientes, Rendiciones, usuarios y empresas; las sesiones administrativas se revalidan contra `usuarios` para aplicar inmediatamente cambios de rol o desactivaciones.
- **Hardening de sesión**: cookies `HttpOnly` + `SameSite=Strict`, modo estricto, regeneración al autenticar, expiración por inactividad, email administrativo en sesión y CSRF en operaciones mutables y logout.
- **Gestión de usuarios (`admin/usuarios.php`)**: listado, alta con Bcrypt, cambio de rol, baja lógica y reset de contraseña, con transacciones y `audit_logs`. Se evita desactivar la propia cuenta y eliminar el último administrador activo.
- **RBAC de portales y APIs**: `TESORERIA` consulta CC sin despachar/configurar; `SUPERVISORA_CC` opera CC y consulta Cheques sin mutarlos; Rendiciones queda limitada a `ADMINISTRADOR`/`TESORERIA`. Todos los endpoints administrativos usan permisos backend granulares.
- **Reenvío trazable de informes CC**: el reenvío reconstruye el correo desde `payload_json` del despacho original, preservando el snapshot aunque las cobranzas ya hayan avanzado a `DEPOSITADO`; mantiene fallback para logs antiguos.
- **Google Sheets en segundo plano**: el modal de configuración permite visualizar/editar `empresas.google_sheet_id` sólo a `ADMINISTRADOR`; otros roles no reciben el valor desde la API ni pueden sobrescribirlo.
- **UI compartida**: Lightbox, Toasts y logout usan una única implementación en `shared_ui.js`/`shell.css`; se eliminaron `console.error`, prompts/confirmaciones bloqueantes y handlers duplicados en los flujos modificados.
- **Verificación de release**: agregado `scripts/verify_release.php` para ejecutar `php -l` y comprobar paridad SHA-256 integral entre raíz y `dist/cheques_cobranza/app/`.

## [Unreleased] - 2026-08-19

### Arquitectura Suite Modular SaaS (Fase 1 — Zero-Breakage)
- **Header Unificado y App Switcher (`admin/includes/app_header.php`, `admin/css/shell.css`)**:
  - Implementada barra de navegación SaaS modular compartida entre los portales de Tesorería, Cuentas Corrientes y Rendición de Gastos.
  - Pestañas dinámicas con clase `.shell-tab--active` según la variable `$CURRENT_MODULE` (`cheques`, `cuentas_corrientes`, `rendiciones`).
  - Control de visibilidad por rol de sesión (`ADMINISTRADOR`, `TESORERIA`, `SUPERVISORA_CC`).
  - Centralización del modal de cierre de sesión (`#modalLogout`) en el componente común.
- **Centralización de Utilidades UI Compartidas (`admin/js/shared_ui.js`)**:
  - Unificado el visor de imágenes **Lightbox** (zoom con rueda y botones, rotación 90°, arrastre mouse/touch, alto contraste y descarga de comprobantes).
  - Centralizado el helper global `showToast()` para notificaciones y alertas en tiempo real.
  - Vinculación universal de listeners de teclado (`Escape`) y eventos de logout.
- **Limpieza de Código Duplicado (`admin/admin.js`, `admin/js/cuentas_corrientes.js`)**:
  - Eliminadas las definiciones redundantes de Lightbox, Toasts y bindings de modales de las lógicas específicas de cada módulo.
- **Stub Preparatorio Módulo 3 (`admin/rendiciones.php`)**:
  - Creada vista inicial con UI informativa para el futuro módulo de Rendiciones de Gastos y Viáticos.
- **Sincronización Dual Exhaustiva**:
  - Sincronizados y verificados por hash SHA256 todos los archivos creados y modificados en `dist/cheques_cobranza/app/admin/`.

## [Unreleased] - 2026-08-18

### Experiencia de Usuario & Flujo Tesorería / Cuentas Corrientes
- **Permanencia en Bandeja al Validar (`admin.js`)**: Modificado `ejecutarCambioEstado` para evitar la redirección automática a la pestaña *Enviados a C.Corrientes* al validar una cobranza (`RECIBIDO_TESORERIA`). El usuario permanece en su bandeja de trabajo actual, se limpia el panel lateral de detalle y se emite un toast de confirmación: *"Cobranza validada y enviada a Cuentas Corrientes correctamente."*
- **Ocultación de Tab "Por Enviar" (`admin/index.php`)**: Ocultada visualmente la pestaña `PENDIENTE_ENVIO` en el panel de Tesorería preservando los contadores internos.
- **Hora de Corte Manual (`HH:MM`) (`modal_config_cc.php` / `modal_config_cc.js`)**: Reemplazado el selector estático por un `<input type="time">` nativo con validación regex para permitir cualquier hora y minuto exacto de corte.
- **Auto-Trigger en Vivo de Despacho (`cuentas_corrientes.js`)**: Vigilante del navegador cada 10 segundos que ejecuta automáticamente el despacho a digitadoras si el portal está abierto y se alcanza la hora configurada.

### Seguridad: Blindaje Integral contra IDOR (SEC-01) & Sanitización de URL
- **Blindaje IDOR en Endpoints API de Vendedores (`api/` y `dist/cheques_cobranza/app/api/`)**:
  - `get_clientes.php`: En entorno `production`, la identidad del vendedor (`$vendedor_id`) y su empresa (`$empresa_param`) se leen exclusivamente desde `$_SESSION['vendedor_auth']`. Si no existe sesión activa, responde `401 Unauthorized`.
  - `get_facturas_cliente.php`: Incorporada autenticación y validación estricta de pertenencia a cartera ERP en producción (`{$db_origen}.tbl_clientes WHERE cli_vendedor = :vid`). Si el cliente no pertenece al vendedor en sesión, responde `403 Forbidden`.
  - `guardar_cobranza.php`: La autoría (`$vendedor_id`, `$vendedor_nombre`) se asigna estrictamente desde `$_SESSION['vendedor_auth']`, neutralizando intentos de suplantación vía `$_POST['vendedor_id']`.
  - `get_mis_cobranzas.php`: El filtro de historial en producción fuerza `c.vendedor_id = :vendedor_id` utilizando el ID de la sesión autenticada.
  - `completar_envio.php`: Reforzada la comprobación IDOR verificando que la cobranza pertenezca al `vendedor_id` de la sesión activa antes de adjuntar comprobantes o avanzar estados.
- **Sanitización de URL en Frontend (`script.js` y `dist/.../script.js`)**:
  - Implementada llamada a `window.history.replaceState({}, document.title, window.location.pathname)` inmediatamente tras la autenticación exitosa en `auth_seller.php`, removiendo de forma transparente los parámetros sensibles (`vendedor_id`, `empresa`, `vendedor_nombre`) de la barra de direcciones del navegador.
- **Sincronización Dual Completa**:
  - Sincronizados al 100% los 6 archivos modificados entre la raíz y `dist/cheques_cobranza/app/`.

### Automatización y Tareas Programadas (Fase 4 del Roadmap)
- **Despacho Automático Diario por Hora de Corte (`cron/resumen_diario_cuentas_corrientes.php`)**:
  - Lectura dinámica de la hora de corte (`hora_despacho_diario`) e interruptor maestro (`despacho_automatico_activado`) desde la tabla `configuraciones_sistema`.
  - Fragmentación inteligente de cheques validados (`RECIBIDO_TESORERIA`) por empresa de emisión (`emitido_a`), generando reportes PDF consolidados con adjunto hacia digitadoras y copia a Supervisora de CC.
  - Cierre transaccional: actualización a `DEPOSITADO` e inserción en `historial_estados` y `log_envios_informes` con control anti-duplicados por fecha.
- **Motor de Alertas Automáticas por Días Transcurridos (`cron/check_alertas.php`)**:
  - Detección proactiva de cobranzas en estados iniciales (`PENDIENTE_ENVIO`, `EN_TRANSITO`, `ENTREGADO_SANTIAGO`) que superan los días límite (`usuarios.dias_alerta_personalizado` o `empresas.dias_maximos_envio`).
  - Envío de alerta corporativa con plantilla HTML, resumen de facturas/cheques, días de retraso y botón directo al detalle en el portal de Tesorería.
- **Seguridad en Crons**:
  - Implementada guardia de acceso dual: ejecución nativa CLI y ejecución HTTP protegida con token secreto `CRON_SECRET_KEY` (`config/app.php`).
  - Documentada la configuración de Crontab Linux / cPanel y WebCron en `cron/README.md`.

## [Unreleased] - 2026-08-14

### Integración Portal Vendedores Web (E-Commerce ↔ Cobranzas)
- **Botón Recaudación de Cheques en `dist/vendedores/pages/cobranza.html`**: Agregado botón directo que extrae `vend_cod` de la sesión del vendedor (`api/auth.php?action=check`) y valida la empresa seleccionada en el filtro para abrir `https://www.autotec.cl/cobranza_cheques/index.html?vendedor_id={vend_cod}&empresa={empresa}`.
- **Documentación de Integración (`docs/INTEGRATION.md`)**: Creada especificación técnica completa con diagramas de secuencia, mapeo de códigos ERP (`EMP01`, `EMP03`, `EMP06`, `EMP10`), flujo de autenticación y consideraciones de seguridad.

### Seguridad y Hardening (Remediación OWASP ZAP Fase 2)
- **Ajuste de Compatibilidad CSP Dinámica**: Restablecido `'unsafe-inline'` balanceado en `script-src` y `style-src` en `.htaccess` y `dist/.htaccess` para admitir atributos inline (`display: none`, variables CSS, micro-interacciones) y manipulación dinámica de propiedades DOM (`element.style.display`) sin violaciones de seguridad en el navegador.
- **Rediseño y Blindaje de Botones Superiores**: Creadas las clases CSS `.btn-header-config`, `.btn-header-portal` y `.btn-header-logout` en `admin/styles.css` y `admin/css/cuentas_corrientes.css`.
- **Manejador de Eventos Modal Configuración**: Vinculado `abrirModalConfigCC` de forma dual (`onclick` y `DOMContentLoaded` listener) en `admin/js/modal_config_cc.js`, garantizando la apertura inmediata del modal en Tesorería y Cuentas Corrientes.
- **Desacoplamiento de Assets**: Mantenida la extracción modular de estilos y scripts en `admin/css/` y `admin/js/` sincronizados entre `root` y `dist/`.
- **Emisión Estricta de Cabeceras HTTP desde PHP**: Añadido bloque de seguridad en `dist/config/app.php` para emitir `Strict-Transport-Security` (HSTS max-age 31536000), `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN` y supresión activa de `Server` y `X-Powered-By`.
- **Protección RCE en Uploads**: Configurado `dist/uploads/.htaccess` con desactivación de motores PHP (`php_flag engine off`) y bloqueo estricto de ejecución de scripts.

## [Unreleased] - 2026-08-07

### Sincronización y Despliegue Producción
- **Sincronización Total Root <-> Dist**: Unificación y sincronización completa de la carpeta `dist/` con la raíz del proyecto. Verificación automatizada mediante hashes MD5 confirmando 100% de coincidencia en archivos PHP, JS, CSS, HTML y SQL.

### Cambiado / Corregido (Parches de Terreno & ERP)
- **Fix Foreign Keys Integridad ERP**: Solucionados crasheos `SQLSTATE[23000]` (Error 1452) en `api/guardar_cobranza.php`, `api/completar_envio.php` y `api/editar_cheques.php`. Ahora el sistema maneja de forma segura las IDs nativas del ERP (ej. vendedor 86) usando `NULL` en `cobranzas.vendedor_id` y `1` (Usuario Sistema) en `historial_estados`.
- **Trazabilidad Nombre Vendedor**: Modificado `COALESCE` en `admin/api/get_cobranzas.php` y `admin/api/get_detalle_cobranza.php` para priorizar `c.vendedor_nombre` sobre la tabla de usuarios locales.
- **Inyección Google Sheets (Nº Recibo)**: Actualizada la sincronización en `admin/api/cambiar_estado.php` para inyectar automáticamente `WEB#{Id_cobranza}` en la columna G (Nº Recibo) de los 4 excels corporativos.
- **Catálogo de Bancos**: Incorporado `BCIPREMIER` al array de bancos en `admin/admin.js`.
- **UI Modal Configuración**: Ocultada la columna "ID Google Sheet" en `admin/components/modal_config_cc.php` manteniendo los inputs funcionales en segundo plano.

## [Unreleased] - 2026-08-04

### Seguridad (Auditoría Go-Live)
- **CRIT-02**: Migrada la lectura de credenciales sensibles (BD, SMTP, Google Sheets) desde valores hardcodeados a `getenv()` con fallbacks locales en `config/app.php`. La contraseña SMTP ya no aparece en texto plano en el código fuente.
- **CRIT-03**: Habilitada la verificación SSL (`CURLOPT_SSL_VERIFYPEER = true`, `CURLOPT_SSL_VERIFYHOST = 2`) en ambas llamadas cURL de `services/GoogleSheetsService.php` (autenticación JWT y envío de datos). Se añadieron timeouts de conexión (10s) y ejecución (30s).
- **CRIT-07**: Corregida llamada a método inexistente `self::sendMailCore()` → `self::sendSmtp()` en `services/MailService.php::enviarAlertaDemora()` que causaría un Fatal Error en el cron de alertas.
- **WARN-02**: Añadido `requireAuth($pdo, ['ADMINISTRADOR', 'TESORERIA', 'SUPERVISORA_CC'])` en `admin/api/get_detalle_cobranza.php`. El endpoint estaba sin protección — cualquier persona con la URL podía ver RUTs, montos y historial completo de cualquier cobranza.
- **WARN-03**: Reemplazado `getUsuarioActual()` por `requireAuth()` con roles en `admin/api/get_cobranzas.php`. Un vendedor autenticado podía ver todas las cobranzas de todas las empresas del holding.
- **WARN-05**: Eliminada la exposición del mensaje de excepción crudo en `api/get_clientes.php` y `api/get_facturas_cliente.php`. Los errores ahora se loguean en el servidor y se devuelve un mensaje genérico en producción.
- **WARN-07**: Corregida la invocación de `MailService::enviarResumenDiarioDigitadora()` en `cron/resumen_diario_cuentas_corrientes.php`. Se pasaban 5 argumentos cuando la firma solo acepta 4, causando un `TypeError` al ejecutar el cron.

## [Unreleased] - 2026-07-31

### Agregado
- **Flujo Extendido de Correos (Iteración de Notificaciones)**: 
  - Implementada la **Doble Notificación Inicial**: Al completar un envío (`api/completar_envio.php`), se notifica simultáneamente a Tesorería y a Cuentas Corrientes (`[PARA C.CORRIENTES] [NUEVO REGISTRO]`).
  - Implementada la **Notificación de Rechazo al Vendedor**: Creado método `MailService::notificarRechazoTesoreria()` para alertar por correo al vendedor cuando Tesorería marque una cobranza como `RECHAZADO`, adjuntando el motivo formateado en rojo.
  - Saneado `LEFT JOIN usuarios` en la consulta de validación de Tesorería para obtener correctamente el correo del vendedor.
- **Ajustes en Google Sheets & Campo Cuenta Corriente**:
  - Añadida la columna `cuenta_corriente` a la tabla `cheques` e integrada en el modal de Tesorería (`admin/admin.js`).
  - Lógica dinámica de pestañas por año en `GoogleSheetsService.php`: el sistema ahora detecta automáticamente el año actual (`2026!A:K`, `2027!A:K`, etc.) e inyecta los cheques en la hoja correspondiente al año sin requerir cambios de código en el futuro.
  - Formato exacto de 11 columnas: `FECHA`, `NCHEQUE`, `BANCO`, `Nombre Girador` (duplicado nombre cliente), `MONTO` (con prefijo `$` ej: `$637.945`), `Rut Cliente`, `NºRecibo` (vacío), `Nombre cliente`, `Fecha de ingreso`, `CTA.NUMERO` y `COMENTARIOS` (multicomentario).
- **Re-validación Backend de Saldos (`SEC-04`)**: Implementada verificación estricta en `api/guardar_cobranza.php` que valida el payload contra `bd_automarco.tbl_cobranza` antes de iniciar la transacción SQL, impidiendo la alteración manual de saldos o sobrepagos fraudulentos desde el cliente web.
- **Integración Google Sheets (Punto 4)**: Creado `services/GoogleSheetsService.php` para la sincronización automática de cheques validados hacia el Excel corporativo de Tesorería vía API REST v4 con OAuth2 JWT nativo en PHP. Integrado el disparo automático al validar en `admin/api/cambiar_estado.php`.
- **Fragmentación Dinámica de Despachos**: El botón "Despachar Resumen" (`admin/api/despachar_resumen_cc.php`) ahora divide las cobranzas según el campo `emitido_a` de cada cheque y distribuye correos distintos a múltiples digitadoras simultáneamente.
- **Edición Manual de Cheques**: API `admin/api/editar_cheques.php` y UI en `modalCompletarCheques` de `admin/admin.js` para corrección de cheques por parte de Tesorería.
- `admin/index.php` & `admin/cuentas_corrientes.php`: Botón de navegación rápida ("Ir a C.Corrientes" / "Ir a Tesorería") en el encabezado exclusivo para usuarios con rol `ADMINISTRADOR`, permitiendo saltar entre portales sin tener que modificar la URL manualmente.
- `config/auth.php`: Registro de advertencia `[SECURITY WARNING]` en el log del servidor cuando se activa el bypass de autenticación de desarrollo local.
- `docs/SECURITY.md`: Sección 10 detallando las medidas de mitigación y hardening aplicadas en la auditoría general de Julio de 2026.

### Cambiado / Corregido
- **Hotfix PDF**: Reemplazado uso de `utf8_decode()` (deprecado en PHP 8.2) por `iconv()` en la generación de PDF para evitar que los warnings corrompan la respuesta JSON de la API (`services/PdfGenerator.php`).
- **Formateo de Correo (Punto 3 - PDF)**: Refactorizado el envío de correos en `services/MailService.php`. Ahora se utiliza `services/PdfGenerator.php` (basado en FPDF) para construir un informe estructurado y paginado en PDF, reemplazando el excesivo HTML anterior. El PDF se adjunta y se limpia del servidor automáticamente.
- **Formateo de Correo (Asuntos)**: Los asuntos de correos a Cuentas Corrientes ahora dicen `[PARA Digitadora A]` o `[PARA Digitadora B]` explícitamente (`services/MailService.php`).
- **Refactorización Visual de Facturas**: Agrupación estética de facturas en el detalle del portal de Tesorería (`admin/admin.js`), mostrando cuotas consolidadas por factura/empresa.
- **Fix de Cruce de Sesiones**: Identificado y diagnosticado problema de priorización de sesión de administrador (`admin_user_id`) cuando se testeaba el WebView Vendedor (`index.html`) en el mismo navegador, forzando un nombre de vendedor incorrecto.

### Cambiado / Corregido
- **Reglas de Negocio (Punto 1 y 2)**: 
  * Trasladada la digitación de `banco` y `numero_cheque` del portal del Vendedor (`script.js`, `index.html`) al portal de Tesorería (`admin/index.php`, `admin/admin.js`). Ahora la Tesorería debe tipiar estos datos en un popup (`modalCompletarCheques`) al momento de validar y enviar a Cuentas Corrientes.
  * Añadida obligatoriedad de justificación en caso de descuadre. Cuando el Vendedor (o Tesorería en edición) envía cheques cuya suma no calza con el total de la factura, se obliga a llenar un campo de `justificacion_descuadre` que se persiste en la BD y se muestra como alerta (Callout) en el inspector de cobranzas.
  * Modificadas las consultas e inserciones en `api/guardar_cobranza.php`, `api/editar_cheques.php`, `admin/api/cambiar_estado.php` y `admin/api/get_detalle_cobranza.php` para soportar las nuevas lógicas sin fallar.
- `admin/cuentas_corrientes.php`: Rediseño completo del modal de Configuración del Distribuidor Diario. Se unificaron los paneles, se reemplazó el checkbox de despacho automático por un switch toggle moderno y se implementó un sistema de asignación excluyente por radio buttons, permitiendo definir 2 correos globales de digitadoras y asignar a cada empresa mutuamente a una de ellas.
- `admin/api/guardar_configuracion_cc.php`: Corregido error de base de datos (`SQLSTATE HY093: Invalid parameter number`) causado por el uso duplicado de parámetros nombrados en las sentencias `INSERT ... ON DUPLICATE KEY UPDATE`.
- `api/completar_envio.php`: Corrección crítica de pérdida de datos. Se aislaron las fotos de cheques de la lógica de limpieza física en disco en caso de fallos SMTP post-commit. Las notificaciones por correo ahora operan en modo best-effort (no fatal).
- `api/get_facturas_cliente.php`: Optimización de rendimiento. Se agregó el filtro `AND c.rut_cliente = :rut_cliente` a la consulta de facturas en proceso, reduciendo los tiempos de respuesta y el uso de memoria en PHP al evitar la carga completa de cobranzas activas del holding.
- `index.html`: Eliminado el botón "Salir" en el encabezado del portal de Vendedor a solicitud de WebView Android (la navegación y cierre son manejados por la app nativa).
- `script.js` & `admin/admin.js` & `admin/cuentas_corrientes.php`: Implementación de mecanismos de prevención de doble click en botones críticos de envío de formularios, confirmaciones y despachos manuales.

## [Unreleased] - 2026-07-30

### Agregado
- `admin/api/auth/logout.php` & `api/auth/logout_vendedor.php`: Nuevos endpoints seguros para destrucción de sesiones en los portales del Vendedor y de Administración.
- Botones y modales de confirmación de "Cerrar Sesión" en los portales de Vendedor, Tesorería y Cuentas Corrientes, solicitando validación explícita para evitar cierres accidentales.
- `admin/api/editar_cobranza_tesoreria.php`: Nuevo endpoint para edición y corrección manual de datos de cheques de cobranzas activas por parte del equipo de Tesorería.
- Control de edición inline interactivo en `admin/admin.js` para modificar banco, número, monto y vencimiento directamente desde el panel de inspección.

### Cambiado / Corregido
- `script.js` & `api/guardar_cobranza.php`: Implementada validación en frontend y backend para prevenir que un vendedor ingrese números de cheque duplicados en el mismo envío, bloqueando el envío si hay coincidencias.
- `services/MailService.php`, `despachar_resumen_cc.php` & `resumen_diario_cuentas_corrientes.php`: Añadidos prefijos de identificación al inicio de los asuntos de los correos (`[PARA TESORERIA]`, `[PARA C.CORRIENTES]`, `[PARA VENDEDOR]`, `[PARA DIGITADORAS]`) para facilitar el filtrado y organización.
- `admin/index.php`, `admin/admin.js`, `script.js`, `index.html`: Renombrados los badges y pestañas del portal de Tesorería y Vendedor. La pestaña `Depositados` cambió a **`Enviados a C.Corrientes`**, el estado `RECIBIDO_TESORERIA` muestra **`En Cola C.Corrientes`** y el estado `DEPOSITADO` muestra **`Enviado a C.Corrientes`**.
- `api/auth_seller.php` & `api/guardar_cobranza.php`: Corregida la evaluación del parámetro de vendedor en URLs WebView (`?vendedor=0` o `?vendedor_id=0`) para evitar que el valor `0` sea interpretado como `falsy` en PHP, garantizando la captura del nombre correcto y eliminando el fallback a `'Sin Asignar'`.
- `script.js` (UX de Montos): Simplificados los mensajes en la barra de validación en tiempo real eliminando emojis y lenguaje confuso de descalces. Ahora se expone el estado de cuadre con claridad ("Cheques cuadran...", "Falta $X para cubrir...").
- `admin/api/get_gestion_cc.php`, `admin/api/guardar_configuracion_cc.php` & `admin/api/despachar_resumen_cc.php`: Añadida persistencia y lógica para el parámetro `despacho_automatico_activado` en la tabla `configuraciones_sistema` para desactivar el despacho automático por hora.
- `README.md`: Creado archivo en la raíz con la documentación de accesos y credenciales operativas de prueba.
- `config/setup.sql`: Incorporadas las definiciones de las tablas `login_attempts` (Rate Limiting y Fuerza Bruta) y `audit_logs` (Auditoría Transaccional), junto con los hashes `bcrypt` de prueba actualizados para evitar errores de autenticación.
- `scratch/verify_schema_integrity.php`: Script automatizado de Verificación de Integridad de Esquema (Anti-Schema Drift). Escanea los queries del código PHP y valida la concordancia 1:1 entre las tablas del código, la base de datos activa MySQL y `setup.sql` previa producción.
- `docs/ADAPTACION_FLUJO_REAL.md`: Documentado el flujo de Cuentas Corrientes con correos simulados (`digitadora1@app.local`, `digitadora2@app.local`), rol de la Supervisora (CC), formato en HTML Limpio y regla Anti-Spam (omisión de envío a las 16:00 hrs en días sin movimiento).
- `docs/INFORME_PRACTICA_RESUMEN.md`: Generado el resumen de memoria para el Informe de Práctica Profesional, documentando la iniciativa autodidacta en el levantamiento de información, las 8 problemáticas reales identificadas en terreno y sus soluciones pragmáticas.
- `docs/SPEC_PORTAL_CUENTAS_CORRIENTES.md`: Documento de especificación funcional y técnica (SPEC) del Módulo Gerencial de Cuentas Corrientes para control de distribución, matriz de ausencias y bitácora de envíos.
- `configuraciones_sistema` (BD & `config/setup.sql`): Tabla y seeders para almacenar dinámicamente configuraciones globales como la hora de corte diario sin hardcodear.
- `cron/resumen_diario_cuentas_corrientes.php`: Script autónomo CLI para despacho de correos consolidados diarios por empresa, con sincronización de Timezone (América/Santiago) y bloqueo de idempotencia basado en base de datos (evita duplicación dentro del mismo minuto).
- `admin/cuentas_corrientes.php`: Portal standalone exclusivo para Cuentas Corrientes con redirección autónoma al hacer login, gestión de ausencias/licencias de digitadoras, control de hora de despacho y bitácora de auditoría.
- `admin/api/despachar_resumen_cc.php` & `cron/resumen_diario_cuentas_corrientes.php`: Actualización de estado de cobranzas a `DEPOSITADO` / `INGRESADO_OPTIMUS` e inserción en `historial_estados` al liberar y enviar los resúmenes diarios, garantizando trazabilidad 100% consistente.

### Cambiado / Corregido
- `admin/login.php` & `config/auth.php`: Corregido error fatal en login de Tesorería provocado por la ausencia de la tabla `login_attempts` en instalaciones o reinicios limpios.
- `admin/admin.js`: Rediseñados los botones de acción del inspector lateral:
  * Para cobranzas entrantes (`EN_TRANSITO` / `PENDIENTE_ENVIO`): Se muestran los botones **"✓ Validar y Enviar a Cuentas Corrientes"** (verde) y **"Rechazar"** (rojo con motivo obligatorio).
  * Para cobranzas validadas (`RECIBIDO_TESORERIA`): Se muestran los botones **"Registrar Depósito en Banco (Optimus)"** (azul) y **"Rechazar"** (rojo).

## [Unreleased] - 2026-07-29

### Agregado
- `uploads/.htaccess`: Protección de directorio contra ejecución de código PHP/scripts en archivos subidos (`Require all denied` para extensiones de script).
- `api/get_facturas_cliente.php`:
  * Función `_parseCuotaLabel()` para extraer números de cuota (`N/M`) desde la `glosa` ERP (Softland) y exponer `cuota_label` en el JSON.
  * Filtro automático de cuotas activas: Oculta automáticamente del selector de facturas aquellas cuotas/facturas que ya tengan una cobranza registrada en proceso (estados `PENDIENTE_ENVIO`, `EN_TRANSITO`, `RECIBIDO_TESORERIA`, `DEPOSITADO`). Se liberan solo si Tesorería las marca como `RECHAZADO`.
- `script.js`:
  * Sistema de selección de facturas con jerarquía visual en 3 niveles: **Empresa ➔ Documento ➔ Cuotas** con checkboxes en cascada.
  * Parser `parseFechaVto()` para interpretar vencimientos en formato `DD-MM-YYYY`.
  * Pantalla de error bloqueante (*Guardia de Acceso*) cuando la URL no provee `vendedor_id`, `vendedor` o `vendedor_email`.
- `styles.css`: Estilos `.factura-doc` (nivel 2), `.factura-row--cuota` (nivel 3 con sangría de 44px) y badges para desglose de cuotas.

### Cambiado / Corregido
- `script.js`:
  * Separada la tarjeta del cliente activo en dos métricas claras: **Deuda ERP** (monto total del cliente) y **A Pagar** (monto dinámico según cuotas marcadas).
- `api/guardar_cobranza.php`: Recálculo obligatorio de `$monto_total_factura` en el servidor a partir de las cuotas enviadas en `facturasLista`, desestimando el total enviado desde el cliente.
- `admin/index.php`: Reordenadas las secciones del inspector: **Cheques Adjuntos** se muestra en 3er lugar y **Comprobante de Despacho** en 4to lugar.
- `admin/admin.js`: Eliminado el botón "Copiar Lista" y su emoji del footer de facturas para cumplir con las guías de diseño sin emojis.

## [Unreleased] - 2026-07-27

### Agregado
- `admin/login.php`: Nueva pantalla de inicio de sesión segura para el portal de Tesorería. Incorpora CSRF tokens, Session Fixation protection, y Rate Limiting / Fuerza Bruta vinculando `checkRateLimit`.
- Botón "📥 Descargar" en el Lightbox: Descarga nativa y directa de la imagen en inspección en el visualizador.
- Iconos vectoriales en formato SVG: Agregados a los botones de filtros segmentados y a todas las etiquetas de estados (badges) del sistema en ambas interfaces (Vendedor y Tesorería), eliminando dependencias externas.
- CSS `.badge-mismatch`: Estilo y badge dedicados para alertar sobre discrepancias en la tabla maestro.
- CSS `.detail-info-grid`: Grid unificada de 3 columnas para la información general del cliente y la factura.

### Cambiado / Corregido
- `admin/index.php`:
  * Protegido el acceso directo mediante chequeo de sesiones seguras (`httponly`, `samesite=Strict`) con redirección a `login.php`.
  * Rediseñada la visual de detalle: se eliminó el botón "X" de cierre y la sección redundante vertical "Trazabilidad del Cheque".
  * Reincorporada la grid general `.detail-info-grid` al inicio del panel.
  * Cambiado el nombre de clase a `.admin-detail-footer` en la barra inferior fija.
  * Removido el botón de "Reajustar Filtros" del bloque de Empty State y el contenedor de depuración móvil (`#debugConsoleWrapper`) del pie de página.
- `admin/admin.js`:
  * Implementada la auto-selección de la primera fila al cargar o filtrar la tabla de cobranzas si no hay una selección activa.
  * Modificada la columna "Total Cheques" para estructurarse en dos líneas: Monto + Cantidad (sin paréntesis y forzado a una sola línea con `white-space: nowrap`) en la primera línea, y el badge de diferencia si corresponde en la segunda.
  * Reemplazados los contenedores `.cheque-16by9-box` por `.cheque-card-img-wrapper` compactando el canvas a 200px de altura y agregando una barra flotante de herramientas con accesos directos `[ 🔍 Lightbox ]` y `[ 🔄 Rotar ]`.
  * Modificada la actualización de badges a `innerHTML` para soportar la renderización de SVGs y cambiada la etiqueta del estado a "Recibido Fisicamente".
- `admin/styles.css`: Rediseñados el canvas de cheques a formato de 200px, el footer fixed `.admin-detail-footer` con sombras de elevación, y aplicados colores de éxito/alerta en CTAs de depósitos y rechazos.
- `admin/api/get_cobranzas.php`: Corregida la lógica de filtros para que al solicitar `estado=TODOS` se salte el filtro de estados y muestre todo correctamente, usando `BANDEJA_TRABAJO` como fallback por defecto.
- `docs/ANDROID_INTEGRATION.md`: Actualizada la especificación técnica con las decisiones del entorno unificado (WebView GET, IDs enteros, hosting de AWS común).

## [Unreleased] - 2026-07-24

### Cambiado / Corregido
- `config/setup.sql`: Se alineó el DDL con el flujo dividido de cobranzas. `tipo_entrega` ahora permite `NULL`, `estado` inicia en `PENDIENTE_ENVIO`, y los enums de `cobranzas` e `historial_estados` incluyen `ENTREGADO_SANTIAGO`.
- `config/update_schema_flujo_dividido.sql`: Se agregó script puntual para actualizar una BD central local ya creada, convirtiendo registros antiguos `INGRESADO` a `PENDIENTE_ENVIO` antes de cerrar el enum final.
- Documentación: se formalizó el flujo dividido en dos pasos (`PENDIENTE_ENVIO` → envío) y la política del entorno cerrado: la BD local podrá recrearse al actualizar el DDL, sin migración de preservación de datos.
- `script.js`: Se integró completamente con los endpoints backend reales (`api/get_factura.php`, `api/get_mis_cobranzas.php`, `api/guardar_cobranza.php`) eliminando los datos simulados (mock).
- `index.html`: Se corrigieron los `value` del selector de empresas para coincidir con los ID numéricos de la base de datos (1 a 4) en lugar de nombres de texto, y se agregó input hidden para `razon_social_cliente`.
- `api/get_factura.php`: Se modificó la consulta SQL de `INNER JOIN` a `LEFT JOIN` y se implementó `REPLACE()` para emparejar RUTs eliminando guiones. Esto corrige la discrepancia histórica en los ERPs donde `tbl_ventas_devoluciones` guarda el RUT sin guion y `tbl_clientes` con guion, evitando que la consulta fallara silenciosamente al no encontrar coincidencias exactas.

### Agregado
- `api/auth/login.php`: Endpoint POST que autentica credenciales de usuario (`email` y `password`), genera un token Bearer criptográficamente seguro de 64 caracteres mediante `bin2hex(random_bytes(32))`, lo actualiza en la columna `api_token` de la tabla `usuarios` y retorna la estructura del token y datos del usuario.
- `api/get_mis_cobranzas.php`: Endpoint GET para consultar la lista de cobranzas del vendedor autenticado (o todas en modo bypass local), soportando filtros opcionales (`estado`, `empresa_id`, `busqueda` libre por RUT/factura/razón social) con sus cheques anidados.
- `api/guardar_cobranza.php`: Endpoint POST `multipart/form-data` para el guardado atómico y transaccional de la cobranza, cheques e historial de estado inicial. Incluye validaciones de imágenes en servidor (MIME real, peso máx 10MB y sanitización de nombres), subida a carpetas estructuradas `/uploads/{empresa_id}/{YYYY-MM}/`, rollback automático en disco y BD en caso de error, y disparo de notificaciones vía `MailService`.
- `services/MailService.php`: Clase encargada del envío de notificaciones HTML a Tesorería y Cliente relativas al registro de la cobranza y sus cheques.
- `api/get_factura.php`: Endpoint GET que recibe `empresa_id` y `numero_factura`, obtiene el nombre de la BD desde la BD central, convalida la whitelist de seguridad mediante `Database::getErpConnection()`, consulta las tablas `tbl_ventas_devoluciones` y `tbl_clientes` del ERP correspondiente y calcula el monto total con IVA (`ROUND(SUM(neto_item * 1.19))`), retornando la estructura JSON definida en `API.md`.
- `config/auth.php`: Se creó la función middleware `getUsuarioActual()`, la cual retorna `AUTH_BYPASS_USER_ID` (1) cuando `APP_ENV = 'local'` y valida el token Bearer (`Authorization: Bearer {token}`) contra la tabla `usuarios` en la BD central cuando `APP_ENV = 'production'`.
- `config/db.php`: Se creó la clase `Database` para manejar la conexión estática singleton a la BD central `bd_modulo_cobranzas` y la conexión dinámica a los ERPs mediante `getErpConnection()`, la cual valida estrictamente el nombre de la BD contra la lista blanca `ALLOWED_DATABASES`.
- `config/app.php`: Se creó el archivo de configuración global del sistema en el cual se definen las constantes de entorno (`APP_ENV`), credenciales de la BD central, modo bypass de autenticación, directorio/URL pública de uploads, constantes SMTP de correo y la lista blanca (`ALLOWED_DATABASES`) de las 4 bases de datos ERP autorizadas.
- `config/setup.sql`: Se creó el script SQL DDL para la base de datos central `bd_modulo_cobranzas`, definiendo las tablas `empresas`, `usuarios`, `cobranzas`, `cheques` e `historial_estados`, junto con sus seeders y restricciones de claves foráneas.
- `docs/PROJECT_STATUS.md`: Documento de seguimiento de estado del proyecto.
- `docs/CHANGELOG.md`: Historial y registro de cambios del proyecto.
- `docs/CHANGELOG.md`: Historial y registro de cambios del proyecto.
