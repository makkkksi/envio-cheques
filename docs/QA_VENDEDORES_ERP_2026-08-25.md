# Certificación QA — Selector ERP y Presupuestos de Rendiciones

**Fecha:** 2026-08-25  
**Entorno:** Laragon local · PHP 8.3.30 · MySQL 8.4.3  
**Estado general:** **REQUIERE AJUSTES**

## Resumen

La integración cross-DB, homologación por correo, revalidación backend, RBAC, CSRF, restricciones transaccionales y selección móvil de presupuesto se comportan correctamente. La suite automatizada obtuvo **13 PASS / 1 FAIL** en 14 casos. El recorrido visual autenticado añadió un segundo hallazgo funcional.

No se detectaron errores de consola ni se persistieron fixtures: todas las inserciones de mensual/gira fueron revertidas mediante `ROLLBACK`. Los helpers de sesión HTTP se eliminaron automáticamente al finalizar.

## Matriz solicitada

| Caso | Resultado | Evidencia |
|---|---|---|
| Búsqueda/autocompletado Autotec | PASS con observación | Búsqueda parcial “Juan” entregó tres vendedores únicamente de Autotec; Flechas + Enter seleccionaron código y nombre. El campo oculto `vendedor_email` quedó vacío pese a mostrarse el correo en la tarjeta verificada. |
| Gabtec `ven_nombre` | PASS | Servicio y UI recuperaron “Angel Fereira” como `nombre_vendedor`, código local `#1`, sin error SQL ni consola. |
| `+ Agregar gira` | FAIL | Empresa, vendedor y tipo quedan bloqueados y GIRA se preselecciona, pero el campo editable **Período** continúa visible. El contrato exige solicitar sólo nombre, fechas y monto. |
| Mensual + Gira concurrentes | PASS | Dos presupuestos activos coexistieron para el mismo vendedor/período; el duplicado mensual fue bloqueado por `periodo_clave`; rollback confirmado. |
| Pruebas negativas y seguridad | PASS | Empresa 999 → 422; vendedor inexistente/manipulado → 422; sin CSRF → 403; `SUPERVISORA_CC` → 403; presupuesto utilizado → 409; payload SQL tratado como búsqueda. |
| Selector móvil Mensual/Gira | PASS | La consulta retorna ambos presupuestos y `populateReportBudgetSelector()` crea opciones diferenciadas; el envío conserva el `presupuesto_id` elegido. |

## Hallazgos

### QA-ERP-01 — Período redundante en acceso rápido de gira

- **Severidad:** Media.
- **Archivo:** `admin/rendiciones.php` / `admin/js/rendiciones.js`.
- **Actual:** el acceso rápido fija GIRA, identidad y empresa, pero deja editable `budgetPeriod`.
- **Esperado:** derivar el período desde la fecha de inicio o mantenerlo internamente; solicitar sólo nombre, inicio, término y monto.

### QA-ERP-02 — Correo oculto no conserva el valor seleccionado

- **Severidad:** Baja funcional / sin impacto de integridad.
- **Archivo:** `admin/js/rendiciones.js`.
- **Actual:** tras seleccionar por clic o teclado, `budgetSellerId` y `budgetSellerName` contienen valores, la tarjeta muestra el correo, pero `budgetSellerEmail.value` queda vacío.
- **Mitigación existente:** `gestion_presupuestos.php` ignora los datos descriptivos del cliente y vuelve a obtener nombre/correo desde el ERP antes de persistir.

## Rendimiento HTTP de `buscar_vendedores.php`

| Consulta | Tiempo |
|---|---:|
| Automarco | 45.86 ms |
| HD Automarco | 76.58 ms |
| Autotec | 60.45 ms |
| Gabtec | 48.84 ms |
| Holding homologado | 70.19 ms |
| Payload con caracteres SQL | 60.72 ms |

Todos los tiempos quedaron bajo 100 ms en el entorno local.

## Integridad de release

- `php scripts/test_rendiciones.php`: **19/19 comprobaciones superadas**.
- `php scripts/verify_release.php`: **89 PHP válidos**.
- Contrato Rendiciones: **14 archivos sin DELETE físico ni placeholders posicionales**.
- Paridad raíz/dist: **203 archivos SHA-256 idénticos**; `.htaccess` validado según entorno.
- Consola del recorrido Autotec/Gabtec/Gira: **0 errores y 0 warnings**.

## SQL

**SQL nuevo para phpMyAdmin: ninguno.** La auditoría no modificó el esquema ni persistió datos de prueba.
