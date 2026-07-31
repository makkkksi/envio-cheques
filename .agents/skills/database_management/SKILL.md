---
name: database_management
description: Use this skill whenever you need to create, modify, or audit database schemas, tables, indexes, or SQL structure.
---

# Gestión de Base de Datos

Estás modificando el modelo de datos o los esquemas del proyecto.

## Carga Selectiva de Contexto
- **Para entender el esquema actual:** Lee `docs/DATABASE.md`.
- **Para entender la arquitectura de R/W vs Solo Lectura:** Lee `docs/ARCHITECTURE.md`.
- **Para ver cómo se usan las tablas en la lógica de negocio:** Lee `docs/BUSINESS_RULES.md`.

## Protocolo de Modificación
1. Actualiza `docs/DATABASE.md` para reflejar tu cambio (si alteras esquemas, índices o tablas).
2. Modifica el archivo DDL central en `config/setup.sql` añadiendo tu nueva tabla, columna o índice.
3. Asegúrate de nunca cambiar los nombres de las tablas de los ERPs documentados (ej. `tbl_ventas_devoluciones`, `tbl_clientes`).
4. **Importante:** Después de realizar los cambios, debes validar la integridad. Si existe el archivo, ejecuta:
   `php scratch/verify_schema_integrity.php`
