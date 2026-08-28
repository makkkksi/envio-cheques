# SQL para Producción — Registro de Migraciones

Este documento indica qué SQL debe ejecutarse manualmente en phpMyAdmin cuando una versión productiva ya existe. `config/setup.sql` continúa siendo la fuente completa para instalaciones nuevas, pero **actualizar ese archivo no modifica por sí solo la base productiva**.

## Procedimiento obligatorio

1. Respaldar `bd_modulo_cobranzas` desde phpMyAdmin.
2. Confirmar que la base seleccionada sea `bd_modulo_cobranzas`.
3. Importar el archivo de migración indicado para la versión.
4. Ejecutar la consulta de verificación incluida al final del mismo archivo.
5. No ejecutar la migración sobre ninguna de las cuatro bases ERP de sólo lectura.

## 2026-08-28 — Topes y flujo unificado de aprobaciones (Fases A/B)

Antes de desplegar cualquier integración de las Fases C–H, importar **completo y sin editar** en `bd_modulo_cobranzas`:

```text
config/migrations/2026_08_28_topes_y_flujo_aprobaciones.sql
```

La migración es aditiva e idempotente. Crea `solicitudes_aprobacion` y `solicitud_aprobacion_historial`; agrega cuatro columnas a `presupuestos_vendedores` y cuatro a `rendiciones_gastos`, junto con índices, `CHECK` y claves foráneas. No contiene `DROP`, `TRUNCATE` ni `DELETE`, y no escribe en las bases ERP.

Verificación posterior mínima:

```sql
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'bd_modulo_cobranzas'
  AND TABLE_NAME IN ('solicitudes_aprobacion', 'solicitud_aprobacion_historial')
ORDER BY TABLE_NAME;

SELECT TABLE_NAME, COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'bd_modulo_cobranzas'
  AND (
    (TABLE_NAME = 'presupuestos_vendedores' AND COLUMN_NAME IN (
      'estado_aprobacion', 'justificacion_gira', 'solicitud_aprobacion_id', 'aprobado_at'
    ))
    OR
    (TABLE_NAME = 'rendiciones_gastos' AND COLUMN_NAME IN (
      'monto_maximo_aprobable', 'monto_exceso_no_reembolsable',
      'aplico_tope_presupuestario', 'solicitud_excepcion_id'
    ))
  )
ORDER BY TABLE_NAME, COLUMN_NAME;
```

La primera consulta debe devolver dos filas y la segunda ocho. En Laragon esta migración ya fue ejecutada dos veces para comprobar idempotencia. En el servidor todavía debe importarse manualmente cuando se autorice el despliegue de la integración completa.

## 2026-08-26 — Dos responsables configurables para excesos

Importar **completo y sin editar** el siguiente archivo en `bd_modulo_cobranzas`:

```text
config/migrations/2026_08_26_aprobadores_rendiciones.sql
```

El archivo contiene exactamente el SQL incremental necesario: crea `aprobadores_rendiciones`, agrega a `rendiciones_gastos` las columnas snapshot del destinatario y los índices/foreign keys correspondientes. Es aditivo e idempotente; no contiene `DROP`, `TRUNCATE` ni `DELETE`.

Verificación posterior mínima:

```sql
SELECT id, orden, nombre, cargo, email, activo
FROM aprobadores_rendiciones
ORDER BY orden ASC;

SELECT COLUMN_NAME
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'bd_modulo_cobranzas'
  AND TABLE_NAME = 'rendiciones_gastos'
  AND COLUMN_NAME IN (
    'aprobador_solicitado_id',
    'aprobador_nombre_snapshot',
    'aprobador_cargo_snapshot',
    'aprobador_email_snapshot',
    'solicitud_exceso_enviada_at',
    'solicitud_exceso_enviada_por'
  )
ORDER BY COLUMN_NAME;
```

La primera consulta devuelve cero filas hasta que un Administrador configure las dos personas desde el portal. La segunda debe devolver seis filas.

## 2026-08-25 — Desglose aprobado y pendiente en presupuestos

No se requiere ejecutar SQL en phpMyAdmin. `monto_aprobado` y `monto_pendiente` se calculan al consultar usando `rendiciones_gastos.monto_total_aprobado`, el estado de la rendición y `presupuestos_vendedores.monto_utilizado`; no se agregan columnas ni índices.

## 2026-08-21 — Módulo 3, Fases 1 y 2

Archivo SQL productivo:

```text
config/setup_rendiciones.sql
```

Este archivo crea, de manera aditiva e idempotente:

- `presupuestos_vendedores`
- `aprobadores_rendiciones`
- `rendiciones_gastos`
- `rendicion_documentos`
- `rendicion_historial_estados`

No contiene `DROP`, `TRUNCATE` ni `DELETE`. Debe ejecutarse completo antes de desplegar las APIs de Rendiciones.

Verificación esperada después de importarlo:

```sql
SELECT TABLE_NAME
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'bd_modulo_cobranzas'
  AND TABLE_NAME IN (
    'presupuestos_vendedores',
    'aprobadores_rendiciones',
    'rendiciones_gastos',
    'rendicion_documentos',
    'rendicion_historial_estados',
    'solicitudes_aprobacion',
    'solicitud_aprobacion_historial'
  )
ORDER BY TABLE_NAME;
```

La consulta debe retornar exactamente siete filas con la versión actual del archivo.

Configuración no SQL requerida antes de probar el correo de exceso en producción:

```apache
SetEnv RENDICIONES_TOKEN_TTL_HOURS "48"
```

Los correos y nombres de responsables ya no se configuran en Apache ni en el repositorio: se mantienen desde el portal administrativo después de aplicar la migración del 2026-08-26.

## 2026-08-21 — Módulo 3, Fases 3 y 4

Estas fases incorporan únicamente interfaces PHP/HTML/CSS/JS y consumen el modelo/API de las Fases 1 y 2.

**SQL adicional que se debe ejecutar en phpMyAdmin: ninguno.**

La refactorización visual `/impeccable polish` + `/impeccable bolder` de la vista administrativa tampoco modifica tablas, columnas, índices ni datos; no agrega sentencias SQL para producción.

Si `config/setup_rendiciones.sql` aún no se ejecutó en producción, debe importarse completo antes de desplegar estas interfaces. Una instalación previa de cuatro tablas debe aplicar además la migración incremental del 2026-08-26.

## 2026-08-25 — Nota del vendedor a Tesorería

Esta migración aditiva corrige la pérdida de la observación ingresada por el vendedor al enviar una rendición. Ejecutar una sola vez en la base productiva `bd_modulo_cobranzas` si ya se habían creado las tablas de Rendiciones:

```sql
ALTER TABLE rendiciones_gastos
  ADD COLUMN nota_vendedor TEXT NULL
  COMMENT 'Observacion general enviada por el vendedor a Tesoreria'
  AFTER vendedor_email;
```

En una instalación donde se importe la versión actual de `config/setup_rendiciones.sql`, no es necesario ejecutar esta sentencia por separado: el archivo comprueba la columna y la agrega sólo si falta.

Verificación posterior:

```sql
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'bd_modulo_cobranzas'
  AND TABLE_NAME = 'rendiciones_gastos'
  AND COLUMN_NAME = 'nota_vendedor';
```

Debe retornar una fila con `TEXT` y `YES`.

## 2026-08-25 — Selector ERP y flujo Presupuesto + Gira

**SQL que se debe ejecutar en phpMyAdmin: ninguno.**

Este cambio consulta `empresas` y las cuatro tablas ERP `tbl_vendedores` en modo de sólo lectura, y continúa persistiendo en las columnas existentes de `presupuestos_vendedores`. No crea tablas, columnas, índices ni valores de catálogo; por lo tanto, tampoco modifica `config/setup.sql` ni `config/setup_rendiciones.sql`.

## Regla para cambios futuros

Todo cambio posterior de columnas, índices, ENUM o tablas deberá añadir:

- La definición final en `config/setup.sql`.
- Una migración incremental nueva, apta para phpMyAdmin y sin destruir datos.
- La entrada correspondiente en este documento con orden y verificación.
