# SQL para Producción — Registro de Migraciones

Este documento indica qué SQL debe ejecutarse manualmente en phpMyAdmin cuando una versión productiva ya existe. `config/setup.sql` continúa siendo la fuente completa para instalaciones nuevas, pero **actualizar ese archivo no modifica por sí solo la base productiva**.

## Procedimiento obligatorio

1. Respaldar `bd_modulo_cobranzas` desde phpMyAdmin.
2. Confirmar que la base seleccionada sea `bd_modulo_cobranzas`.
3. Importar el archivo de migración indicado para la versión.
4. Ejecutar la consulta de verificación incluida al final del mismo archivo.
5. No ejecutar la migración sobre ninguna de las cuatro bases ERP de sólo lectura.

## 2026-08-25 — Desglose aprobado y pendiente en presupuestos

No se requiere ejecutar SQL en phpMyAdmin. `monto_aprobado` y `monto_pendiente` se calculan al consultar usando `rendiciones_gastos.monto_total_aprobado`, el estado de la rendición y `presupuestos_vendedores.monto_utilizado`; no se agregan columnas ni índices.

## 2026-08-21 — Módulo 3, Fases 1 y 2

Archivo SQL productivo:

```text
config/setup_rendiciones.sql
```

Este archivo crea, de manera aditiva e idempotente:

- `presupuestos_vendedores`
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
    'rendiciones_gastos',
    'rendicion_documentos',
    'rendicion_historial_estados'
  )
ORDER BY TABLE_NAME;
```

La consulta debe retornar exactamente cuatro filas.

Configuración no SQL requerida antes de probar el correo de exceso en producción:

```apache
SetEnv RENDICIONES_APPROVER_EMAIL "correo-corporativo-de-francisco"
SetEnv RENDICIONES_APPROVER_NAME "Francisco J."
SetEnv RENDICIONES_TOKEN_TTL_HOURS "48"
```

No sustituir el valor de ejemplo en el repositorio; debe configurarse directamente en Apache/cPanel con el correo corporativo real.

## 2026-08-21 — Módulo 3, Fases 3 y 4

Estas fases incorporan únicamente interfaces PHP/HTML/CSS/JS y consumen el modelo/API de las Fases 1 y 2.

**SQL adicional que se debe ejecutar en phpMyAdmin: ninguno.**

La refactorización visual `/impeccable polish` + `/impeccable bolder` de la vista administrativa tampoco modifica tablas, columnas, índices ni datos; no agrega sentencias SQL para producción.

Si `config/setup_rendiciones.sql` aún no se ejecutó en producción, debe importarse completo antes de desplegar estas interfaces. No es necesario volver a ejecutarlo si la verificación de cuatro tablas ya fue satisfactoria.

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
