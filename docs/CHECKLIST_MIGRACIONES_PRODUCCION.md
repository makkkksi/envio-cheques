# Checklist de Migraciones para Producción (phpMyAdmin)

Este documento detalla el procedimiento operativo estándar para la ejecución manual y controlada de las migraciones de base de datos en el servidor de producción (`dbaws.automarco.cl` / `bd_modulo_cobranzas`).

---

## 1. Reglas Operativas Obligatorias

1. **PROHIBIDO ejecutar migraciones automatizadas en caliente sin respaldo previo.**
2. **Respaldo Previo (Dump):** Generar un respaldo completo de la base de datos `bd_modulo_cobranzas` antes de ejecutar cualquier script DDL.
3. **Idempotencia y No Destructivo:** Todos los scripts de migración son 100% aditivos e idempotentes (`IF NOT EXISTS`, conditional schema procedures). No contienen `DROP TABLE`, `TRUNCATE` ni `DELETE`.
4. **Verificación de Diagnóstico Preflight:** Si existen inconsistencias o IDs huérfanos preexistentes, los procedimientos de preflight abortarán la ejecución mediante `SIGNAL SQLSTATE '45000'` para prevenir corrupción de claves foráneas.

---

## 2. Orden Secuencial de Migraciones

Acceder a phpMyAdmin en el servidor de base de datos, seleccionar la base `bd_modulo_cobranzas`, e importar o ejecutar pestaña SQL en el siguiente orden estricto:

### Paso 1: Migración de Índices, Columnas y FKs de Auditoría (Rendiciones)
- **Archivo:** `config/migrations/2026_09_02_indices_y_fks_auditoria.sql`
- **Objetivo:**
  - Añade `numero_documento_original` en `rendicion_documentos`.
  - Agrega índices `idx_rendicion_verificado_por` y `idx_documento_editado_por`.
  - Ejecuta diagnóstico preflight de huérfanos con `SIGNAL SQLSTATE '45000'`.
  - Establece claves foráneas `fk_rendicion_verificado_usuario` y `fk_documento_editado_usuario` apuntando a `usuarios(id) ON DELETE RESTRICT`.

### Paso 2: Migración de Reutilización de Documentos Rechazados (Rendiciones)
- **Archivo:** `config/migrations/2026_09_02_reutilizacion_documentos_rechazados.sql`
- **Objetivo:**
  - Reemplaza el índice único global estricto por una columna generada almacenada (`document_hash_bloqueante`) que ignora documentos en estado `RECHAZADO`.
  - Crea índice único `uq_rendicion_document_hash_bloqueante` permitiendo que los vendedores re-ingresen boletas o facturas válidas tras un rechazo formal sin colisión de unicidad.

### Paso 3: Migración de Baja Lógica Zero Delete (Cheques de Cobranzas)
- **Archivo:** `config/migrations/2026_09_04_baja_logica_cheques.sql`
- **Objetivo:**
  - Añade columnas `activo` (TINYINT(1) DEFAULT 1), `descartado_at` (TIMESTAMP NULL), `descartado_por` (INT NULL) y `motivo_descarte` (VARCHAR(255) NULL) en `cheques`.
  - Crea índices `idx_cheques_activo` y `idx_cheques_descartado_por`.
  - Crea clave foránea `fk_cheques_descartado_usuario` referenciando `usuarios(id) ON DELETE RESTRICT`.
  - Garantiza trazabilidad financiera absoluta sin eliminación física (`DELETE FROM cheques`).

### Paso 4: Migración a web_usuarios y Handoff Seguro por POST
- **Migraciones DDL Requeridas:** Ninguna (0).
- La tabla `audit_logs` preexistente en `bd_modulo_cobranzas` es 100% compatible para auditar eventos `SELLER_HANDOFF` sin alteraciones de esquema.
- Las bases ERP son consultadas estrictamente en modo **SOLO LECTURA** (`SELECT`). No se ejecuta ningún DDL ni DML en ellas.

---

## 3. Consultas de Verificación Post-Migración

Tras ejecutar los 3 scripts en phpMyAdmin, ejecutar la siguiente consulta SQL de verificación para confirmar que la estructura productiva es 100% conforme:

```sql
USE `bd_modulo_cobranzas`;

-- 1. Verificar columnas de baja lógica en cheques
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'cheques'
  AND COLUMN_NAME IN ('activo', 'descartado_at', 'descartado_por', 'motivo_descarte')
ORDER BY ORDINAL_POSITION;

-- 2. Verificar índices y claves foráneas en cheques
SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'cheques'
  AND CONSTRAINT_NAME IN ('fk_cheques_descartado_usuario');

-- 3. Verificar columnas y generated column en rendicion_documentos
SELECT COLUMN_NAME, COLUMN_TYPE, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'rendicion_documentos'
  AND COLUMN_NAME IN ('numero_documento_original', 'editado_por', 'document_hash_bloqueante');

-- 4. Verificar claves foráneas en rendiciones_gastos
SELECT CONSTRAINT_NAME, CONSTRAINT_TYPE
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'rendiciones_gastos'
  AND CONSTRAINT_NAME IN ('fk_rendicion_verificado_usuario');
```

---

## 4. Estado de Salida Esperado

- **Columnas añadidas:** 4 en `cheques`, 2 en `rendicion_documentos`.
- **Claves foráneas añadidas:** 3 (`fk_cheques_descartado_usuario`, `fk_rendicion_verificado_usuario`, `fk_documento_editado_usuario`).
- **Registros afectados:** 0 mutaciones de datos históricos.
- **Rollback Plan:** En caso de error o aborto preflight, revisar los IDs listados por el procedimiento preflight en `usuarios` antes de reintentar.
