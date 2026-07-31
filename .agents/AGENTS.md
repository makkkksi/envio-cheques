# Reglas Globales (Workspace Rules)

Estas reglas son absolutas y aplican a **todas** las tareas en este proyecto. No son negociables.

## 1. Nomenclatura Intocable (R1 y R2)
- **Tablas y Columnas:** Los nombres de tablas y columnas provienen de un esquema productivo ERP (ver `docs/DATABASE.md`). NUNCA renombrar, hacer alias o "mejorar" los nombres existentes (ej. `cli_razon_social` NO debe cambiarse a `company_name`).
- **Nombres de BD ERP:** Las 4 bases de datos ERP tienen nombres exactos (`automarc_automarco`, `autohd_automarcohd`, `autotec_ecom`, `gabteccl_sitbdd1978`). NUNCA inventar o cambiar estos nombres. Toda query cross-DB debe pasar por la validación `ALLOWED_DATABASES`.

## 2. Tecnologías y Frameworks (R3 y R4)
- **Backend:** El proyecto usa **PHP puro + PDO**. NUNCA agregar Laravel, Symfony, Slim ni ningún framework sin instrucción explícita.
- **Frontend:** El frontend usa **CSS vanilla** con variables definidas en `styles.css`. NUNCA reemplazar clases con utilidades de Tailwind u otros frameworks CSS externos.

## 3. Seguridad y PDO (R5)
- **SQL Injection:** NUNCA concatenar variables en queries SQL (Ej. `$sql = "SELECT * FROM tbl WHERE id = $id"` está estrictamente prohibido).
- **Prepared Statements:** SIEMPRE usar prepared statements con parámetros nombrados (`$stmt->execute([':id' => $id])`).

## 4. Respuestas API (R6)
- **Formato JSON:** Todos los endpoints DEBEN retornar el formato estándar: `{ "success": true/false, ... }`.
- NUNCA exponer stack traces ni rutas del servidor (`paths`) en el JSON de respuesta.

## 5. Comportamiento ante Ambigüedad
1. **Señalar la ambigüedad** antes de generar código.
2. **Proponer la interpretación conservadora** (la que menos cambia lo existente).
3. **No inventar funcionalidades** no descritas en la documentación.
4. NUNCA alterar esquemas de BD, añadir endpoints o instalar dependencias sin confirmación explícita del usuario.

## 6. Actualización de Documentación
- Al finalizar una tarea que altere el estado operativo del proyecto, DEBES actualizar `docs/PROJECT_STATUS.md`.
- Solo actualiza `docs/ROADMAP.md` si cambia el alcance, las fases o la planificación a largo plazo del proyecto.
- Si hay cambios notables, regístralos en `docs/CHANGELOG.md`.
