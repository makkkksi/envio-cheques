# AI_RULES.md — Reglas para Agentes de IA

**Propósito:** Instrucciones explícitas que deben seguir los agentes de IA (Claude, Codex, ChatGPT, Gemini) cuando trabajen en este proyecto. Define qué está permitido, qué está prohibido y qué contexto debe cargarse antes de cualquier tarea.  
**Audiencia:** Agentes de IA exclusivamente.  
**Actualizar este documento:** Solo el equipo técnico humano puede modificar estas reglas.

---

## Contexto Obligatorio — Leer ANTES de Cualquier Tarea

Antes de generar o modificar cualquier código, el agente **DEBE** haber leído los siguientes documentos en este orden:

1. `docs/README.md` — visión general y actores
2. `docs/ARCHITECTURE.md` — topología de BD y flujo de datos
3. `docs/DATABASE.md` — esquema de tablas y nombres exactos
4. `docs/API.md` — contratos de endpoints
5. `docs/BUSINESS_RULES.md` — reglas de negocio y estados
6. `docs/CODING_STANDARDS.md` — convenciones de código
7. `docs/SECURITY.md` — patrones de seguridad

Si la tarea es de seguridad, leer también `SECURITY.md` como primera prioridad.

---

## Reglas Absolutas (No negociables)

### R1 — Nombres de tablas y columnas: NO cambiar

Los nombres de tablas y columnas están definidos en `DATABASE.md` y provienen de un esquema productivo. **Nunca** renombrar, alias o "mejorar" los nombres existentes.

```
✅ empresas.nombre_bd
❌ empresas.database_name
❌ empresas.bd_name

✅ tbl_ventas_devoluciones
❌ sales_returns
❌ ventas

✅ cli_razon_social
❌ razon_social
❌ company_name
```

### R2 — Nombres de BD ERP: No inventar ni modificar

Las 4 bases de datos ERP tienen nombres exactos que deben respetarse:

```
automarc_automarco
autohd_automarcohd
autotec_ecom
gabteccl_sitbdd1978
```

Cualquier query cross-DB debe pasar por la validación `ALLOWED_DATABASES` antes de usar el nombre de BD.

### R3 — Sin frameworks PHP externos sin autorización

El proyecto usa **PHP puro + PDO**. No agregar Laravel, Symfony, Slim ni ningún framework sin instrucción explícita del equipo.

### R4 — Sin TailwindCSS ni librerías CSS externas

El frontend usa **CSS vanilla** con las variables de `styles.css`. No reemplazar las clases existentes con utilidades de Tailwind u otros frameworks.

### R5 — Sin concatenación de variables en queries SQL

```php
// ❌ NUNCA hacer esto
$sql = "SELECT * FROM {$nombre_bd}.tbl_clientes WHERE cli_rut = '{$rut}'";

// ✅ SIEMPRE prepared statements para datos de usuario
// ✅ Solo el nombre de BD (validado por whitelist) puede interpolarse
$sql = "SELECT * FROM {$nombre_bd}.tbl_clientes WHERE cli_rut = :rut";
$stmt->execute([':rut' => $rut]);
```

### R6 — Formato de respuesta JSON: respetar el contrato

Todos los endpoints DEBEN retornar exactamente el formato definido en `API.md §Convenciones`:
- `{ "success": true/false, ... }` — siempre presente
- Nunca exponer stack traces ni paths al cliente

### R7 — La app vendedor es read-only para estados de cheques

El vendedor **no puede cambiar estados**. Nunca agregar lógica de cambio de estado en:
- `api/get_factura.php`
- `api/guardar_cobranza.php`
- `api/get_mis_cobranzas.php`
- El frontend `index.html` / `script.js`

### R8 — El directorio `/admin/` no existe aún (Fase 2)

No crear archivos en `admin/` durante la Fase 1. Si se recibe una tarea que requiere funcionalidad de Tesorería, señalar que pertenece a Fase 2 y no implementarla.

---

## Patrones Establecidos — Seguir Siempre

### Patrón de endpoint PHP

Ver `CODING_STANDARDS.md §1` para la estructura completa. El orden es:

1. Headers HTTP
2. `require_once` de config
3. Validación del método HTTP
4. `getUsuarioActual()` (auth)
5. Validación de inputs
6. Lógica de negocio en `try/catch`
7. `echo json_encode(...)` + `exit`

### Patrón de transacción SQL

```
beginTransaction → operaciones → commit → MailService (post-commit)
                ↘ error → rollBack → borrar archivos subidos → log error
```

### Patrón de subida de archivos

1. Validar `UPLOAD_ERR_OK`
2. Validar MIME real con `mime_content_type()` (no extensión)
3. Validar tamaño ≤ 10MB
4. Sanitizar nombre con `preg_replace('/[^a-zA-Z0-9._-]/', '', ...)`
5. Crear directorio con `mkdir($path, 0755, true)`
6. Guardar ruta relativa en BD

---

## Comportamiento ante Ambigüedad

Cuando la tarea sea ambigua, el agente DEBE:

1. **Señalar la ambigüedad** antes de generar código.
2. **Proponer la interpretación más conservadora** (la que menos cambia lo existente).
3. **No inventar funcionalidades** no descritas en la documentación.
4. **No cambiar nombres** de tablas, endpoints, columnas ni variables del DOM existentes.
5. **Preguntar al usuario** si la decisión tiene impacto en la BD o en la arquitectura.

---

## Qué Está Permitido sin Consultar

- Agregar comentarios de documentación al código
- Mejorar manejo de errores sin cambiar el comportamiento
- Agregar validaciones adicionales en el backend (siempre permisivas, nunca restrictivas sin razón)
- Agregar índices SQL recomendados en `DATABASE.md §4`
- Formatear código siguiendo `CODING_STANDARDS.md`

---

## Qué Requiere Confirmación Explícita

- Cualquier cambio al esquema de BD (`ALTER TABLE`, `ADD COLUMN`, etc.)
- Agregar nuevos endpoints no documentados en `API.md`
- Cambiar el formato de respuesta JSON de un endpoint existente
- Cambiar el flujo de autenticación
- Modificar `ALLOWED_DATABASES`
- Cualquier acción que requiera `rollback` de datos en producción
- Instalar librerías externas (Composer, npm)

---

## Estado del Proyecto al Iniciar una Sesión

Si el agente no tiene contexto de sesiones anteriores, asumir:

| Parámetro | Valor |
|-----------|-------|
| `APP_ENV` | `local` |
| Credenciales DB | `root` sin contraseña (Laragon) |
| Auth | Bypass activo (usuario_id = 1) |
| Fase activa | **Fase 1** |
| Portal admin | No existe todavía |
| SMTP host | Pendiente de configurar |

---

## Referencias de Documentación

| Doc | Cuándo leerlo |
|-----|---------------|
| `DATABASE.md` | Antes de cualquier query o `INSERT` |
| `API.md` | Antes de crear o modificar un endpoint |
| `BUSINESS_RULES.md` | Antes de implementar validaciones o lógica de estados |
| `SECURITY.md` | Antes de implementar auth, uploads o queries cross-BD |
| `CODING_STANDARDS.md` | Antes de generar cualquier archivo PHP o JS |
| `ROADMAP.md` | Para verificar si una funcionalidad pertenece a la fase actual |
