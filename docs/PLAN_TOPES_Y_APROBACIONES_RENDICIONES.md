# Plan de Implementación — Topes Mensuales, Excepciones y Aprobación de Giras

> **Estado:** Decisiones de negocio documentadas; implementación pendiente de confirmación explícita.  
> **Fecha:** 28 de agosto de 2026.  
> **Alcance:** Módulo 3 — Rendiciones de Gastos y Viáticos.  
> **Importante:** Este documento describe el comportamiento objetivo. El sistema productivo conserva el flujo vigente hasta que se apruebe y ejecute este plan.

---

## 1. Objetivo directivo

El presupuesto mensual constituye el máximo ordinario que un vendedor puede recibir durante el período. El sistema debe permitir documentar un último informe que cruce el saldo disponible, pero sólo podrá reembolsar hasta el tope. Una vez agotado o reservado el saldo, no se aceptarán nuevos informes mensuales.

Las únicas autorizaciones ordinarias de Gerencia/Jefatura serán las asignaciones de fondos de gira. El exceso mensual conserva un mecanismo excepcional y opcional, iniciado exclusivamente por Tesorería, para no eliminar la capacidad ya desarrollada.

---

## 2. Decisiones de negocio aprobadas

1. **Tope mensual ordinario:** el máximo reembolsable es el presupuesto asignado al período.
2. **Último informe permitido:** si aún existe saldo, se permite enviar un informe que lo exceda; el vendedor debe aceptar una advertencia con el máximo reembolsable.
3. **Bloqueo posterior:** cuando el saldo operativo sea cero, no se permiten nuevos informes mensuales.
4. **Consumo contable:** el fondo se considera utilizado por el monto aprobado. Los montos pendientes se muestran y reservan por separado para impedir doble imputación.
5. **Aplicación FIFO:** los documentos se imputan por `fecha_emision ASC, id ASC`. El último documento puede recibir un reembolso parcial.
6. **Cierre del lote:** todas las boletas incluidas quedan procesadas; una boleta parcialmente reembolsada no queda pendiente ni bloquea el cierre del informe.
7. **Excepción mensual:** Tesorería puede solicitar opcionalmente la aprobación del exceso. El responsable decide sólo sobre el exceso, no sobre el monto cubierto por el presupuesto.
8. **Rechazo de excepción:** rechazar el exceso no rechaza la rendición; ésta continúa pagable hasta el tope ordinario.
9. **Responsables:** existen dos responsables configurados con la misma autoridad. Para cada solicitud Tesorería selecciona sólo uno.
10. **Gira no visible:** una gira pendiente o rechazada no se muestra al vendedor. Sólo una gira aprobada y vigente puede recibir informes.
11. **Aprobación de gira:** autoriza el fondo, no los comprobantes futuros. Cada rendición de gira mantiene la revisión documental de Tesorería.
12. **Token:** vigencia recomendada de 48 horas. La solicitud continúa pendiente si el enlace vence y Tesorería puede reenviarla, rotando el token.
13. **Correo fallido:** la gira permanece pendiente y se habilita reenvío o cambio de responsable.
14. **Cambios posteriores:** disminuir el monto o corregir metadatos de una gira puede hacerse con auditoría. Aumentar el monto invalida la autorización monetaria y exige una nueva aprobación.
15. **Giras anteriores:** no existe información histórica relevante que migrar; la implementación puede normalizar los datos de prueba.
16. **Fecha de inicio sin aprobación:** la gira permanece bloqueada y se alerta a Tesorería.
17. **Justificación de gira:** se incorpora un motivo comercial breve, máximo 500 caracteres, visible en el correo de aprobación.
18. **Cierre financiero con tope:** una rendición cuyo monto pagado sea inferior al presentado conserva internamente la diferencia y puede finalizar en `PAGADA`, mostrándose como **Pagada con tope presupuestario**.

### 2.1 Regla derivada para giras

El monto de una gira aprobada también se considera un tope. Si se necesita más fondo, Tesorería aumenta la gira y solicita una nueva aprobación. No se abre una segunda aprobación de “exceso de gira” desde una rendición.

Esta interpretación evita autorizar una gira por un monto y luego pagar una cifra superior sin nueva decisión. Debe quedar ratificada junto con el resto del plan.

---

## 3. Definiciones financieras

```text
monto_aprobado_real = suma de montos validados de rendiciones aprobadas/pagadas
monto_pendiente_reservado = máximo pagable de rendiciones aún en proceso
saldo_operativo = monto_asignado - monto_aprobado_real - monto_pendiente_reservado
máximo_pagable_informe = MIN(monto_rendido, MAX(0, saldo_operativo_al_enviar))
exceso_no_reembolsable = monto_rendido - máximo_pagable_informe
```

- `monto_aprobado_real` es el consumo contable presentado en Dashboard.
- `monto_pendiente_reservado` no es gasto aprobado, pero bloquea disponibilidad para evitar dobles envíos.
- Los cálculos se ejecutan dentro de transacciones con bloqueo del presupuesto mediante `SELECT ... FOR UPDATE`.
- El máximo pagable queda almacenado como snapshot; cambios posteriores del presupuesto no alteran retroactivamente una rendición enviada.

### Ejemplo

```text
Presupuesto mensual                 $200.000
Aprobado previamente                $150.000
Pendiente reservado                       $0
Saldo operativo                      $50.000
Nuevo informe                        $80.000
Máximo pagable                       $50.000
Exceso ordinario                     $30.000
```

Después del envío se reservan $50.000 y el saldo operativo queda en cero.

---

## 4. Estados independientes

La rendición y su autorización excepcional no deben compartir un único estado bloqueante.

### 4.1 Estado operativo de la rendición

```text
EN_REVISION_TESORERIA
    → DOCUMENTOS_FISICOS_RECIBIDOS
    → APROBADA | APROBADA_PARCIAL | RECHAZADA
    → PAGADA
```

### 4.2 Estado de solicitud de aprobación

```text
NO_APLICA | PENDIENTE_ENVIO | PENDIENTE_DECISION
APROBADA | RECHAZADA | VENCIDA | CANCELADA | ENVIO_FALLIDO
```

La recepción física puede registrarse aunque exista una excepción pendiente. El pago final debe esperar su resolución o la decisión de Tesorería de continuar sólo hasta el tope.

---

## 5. Flujo completo — Presupuesto mensual

### Caso M01 — Vendedor sin presupuesto mensual

- Puede cargar documentos a la bolsa.
- No puede consolidar una rendición mensual.
- Mensaje: “No puedes enviar rendiciones porque no tienes un presupuesto mensual asignado. Comunícate con Tesorería si necesitas presupuesto o una gira comercial.”

### Caso M02 — Presupuesto disponible e informe dentro del saldo

- Se permite el envío.
- Se reserva el total del informe.
- No se genera exceso ni solicitud gerencial.
- Pasa a revisión normal de Tesorería.

### Caso M03 — Existe saldo, pero el informe lo supera

- Se permite el envío una sola vez.
- Antes de confirmar se informa monto presentado, máximo reembolsable y diferencia.
- Se reserva únicamente el máximo reembolsable.
- El exceso queda registrado como no reembolsable por defecto.
- La rendición pasa a revisión, no a espera gerencial automática.
- Después del envío, el saldo operativo queda en cero y se bloquean nuevos informes mensuales.

### Caso M04 — Saldo agotado por montos aprobados

- El backend rechaza la consolidación.
- El frontend deshabilita la acción.
- Se ofrece contactar a Tesorería o solicitar una gira.

### Caso M05 — Saldo completamente reservado por informes pendientes

- Se aplica el mismo bloqueo del caso M04.
- La UI distingue “Aprobado” de “Pendiente reservado”.
- El vendedor no puede aprovechar el tiempo de revisión para duplicar el uso del fondo.

### Caso M06 — Dos envíos concurrentes intentan consumir el último saldo

- El primer request bloquea el presupuesto y reserva el saldo.
- El segundo recalcula después del bloqueo.
- Si ya no queda saldo, responde con conflicto controlado y no enlaza documentos.

### Caso M07 — Tesorería aprueba dentro del tope

- Se procesan documentos elegibles por FIFO.
- Cada documento recibe `monto_validado` entre cero y su monto declarado.
- El documento que cruza el límite puede aprobarse parcialmente.
- Los posteriores pueden quedar con monto validado cero, pero procesados dentro del lote.
- La diferencia queda auditada como “Ajuste por tope presupuestario”, no como error del vendedor.

### Caso M08 — Tesorería rechaza una boleta antes de aplicar FIFO

- La boleta rechazada recibe motivo obligatorio y monto validado cero.
- FIFO se recalcula sobre los documentos restantes.
- Un documento posterior puede ocupar el saldo liberado.

### Caso M09 — Tesorería corrige un monto hacia abajo

- Se usa el monto validado corregido para la liquidación.
- Se libera la reserva que ya no será necesaria.
- La modificación queda auditada.

### Caso M10 — Tesorería rechaza toda la rendición

- Todos los documentos pasan a rechazados con motivo.
- Se libera toda la reserva.
- La rendición termina en `RECHAZADA`.
- Cualquier solicitud excepcional relacionada se cancela e invalida.

### Caso M11 — No se solicita excepción mensual

- Tesorería aprueba hasta el tope.
- La diferencia permanece no reembolsable.
- La rendición puede avanzar a pagada con tope.

### Caso M12 — Tesorería solicita excepción

- Selecciona uno de los dos responsables.
- Ingresa un comentario obligatorio explicando la excepción.
- El sistema solicita exclusivamente el exceso.
- La rendición puede seguir recibiendo control documental y físico, pero no se paga hasta resolver o renunciar a la excepción.

### Caso M13 — Excepción aprobada

- El exceso autorizado aumenta el máximo pagable sólo de esa rendición.
- No modifica automáticamente el presupuesto mensual base del vendedor.
- Se registra responsable, cargo, fecha, monto y comprobante PDF.
- Tesorería continúa la revisión de documentos; la autorización no aprueba boletas inválidas.

### Caso M14 — Excepción rechazada

- Se conserva el máximo ordinario.
- La rendición no se rechaza.
- Tesorería puede aprobar y pagar hasta el tope.

### Caso M15 — Token de excepción vencido

- La solicitud permanece pendiente.
- El enlace vencido no admite decisiones.
- Tesorería puede reenviar al mismo responsable o cambiarlo; el token anterior queda invalidado.

### Caso M16 — Correo de excepción fallido

- La solicitud queda en `ENVIO_FALLIDO`, sin perder la rendición.
- Tesorería puede reenviar o cambiar responsable.
- No se asume aprobación por falta de respuesta.

### Caso M17 — Tesorería cancela la excepción

- Se invalida el token.
- La rendición vuelve a la política ordinaria de pago hasta el tope.
- Se conserva auditoría de quién canceló y por qué.

### Caso M18 — Solicitud enviada al responsable incorrecto

- Mientras no exista decisión final, Tesorería cancela/rota el token y selecciona al otro responsable.
- El primer enlace queda inutilizable.

### Caso M19 — Excepción resuelta después de aprobación documental

- Si la rendición aún no está pagada, se recalcula el máximo liquidable respetando documentos validados.
- Una rendición pagada es final y no admite autorización retroactiva; cualquier ajuste posterior requiere un proceso contable externo y auditado.

### Caso M20 — Pago final con tope

- La rendición conserva `monto_total_rendido` completo y `monto_total_aprobado` real.
- Puede usar internamente `APROBADA_PARCIAL` antes del pago.
- Al pagar pasa a `PAGADA` con indicador `aplico_tope_presupuestario = 1`.
- La UI presenta “Pagada con tope presupuestario”, no “pendiente” ni “rechazada”.

---

## 6. Flujo completo — Asignación y uso de giras

### Caso G01 — Tesorería crea una gira válida

- Selecciona empresa, vendedor ERP, fechas, monto, justificación y uno de los dos responsables.
- La gira se guarda como `PENDIENTE`.
- No se muestra al vendedor.
- Se genera un token de un solo uso y se intenta enviar el correo después del commit.

### Caso G02 — Correo enviado

- La solicitud queda `PENDIENTE_DECISION`.
- Tesorería ve responsable, fecha, vencimiento y estado.

### Caso G03 — Correo fallido

- La gira continúa pendiente y oculta.
- Tesorería puede reenviar al mismo responsable o seleccionar al otro.

### Caso G04 — Responsable aprueba

- El token se consume atómicamente.
- La solicitud y la gira pasan a `APROBADA`.
- La gira aparece al vendedor si además está dentro de sus fechas operativas.
- La aprobación autoriza el fondo, no los comprobantes.

### Caso G05 — Responsable rechaza

- El token se consume.
- La gira queda `RECHAZADA` y no se muestra al vendedor.
- Tesorería puede corregirla y generar una nueva versión de aprobación.

### Caso G06 — Token vencido

- La gira sigue pendiente y oculta.
- Tesorería reenvía; cada reenvío rota el token anterior.

### Caso G07 — Tesorería cancela antes de la decisión

- Se invalida el token y se marca la solicitud `CANCELADA`.
- La gira no se habilita.
- La cancelación exige comentario de auditoría.

### Caso G08 — Cambio de responsable pendiente

- Se invalida el token actual.
- Se crea/reemite la solicitud al nuevo responsable.
- Ambos responsables mantienen la misma autoridad, pero sólo uno puede decidir la versión activa.

### Caso G09 — Disminución de monto después de aprobar

- Se permite con auditoría, siempre que no quede por debajo de lo ya aprobado/comprometido.
- No exige nueva aprobación.

### Caso G10 — Aumento de monto después de aprobar

- La gira vuelve a `PENDIENTE` y deja de aceptar nuevas rendiciones.
- Los informes ya enviados conservan su trazabilidad.
- El aumento exige una nueva aprobación del monto actualizado.

### Caso G11 — Corrección de nombre, justificación o fechas

- Se permite con auditoría para evitar burocracia innecesaria.
- Si ya existen documentos imputados, las fechas no pueden excluir comprobantes existentes.
- Un cambio material que afecte la naturaleza o viabilidad de la gira puede ser reenviado voluntariamente por Tesorería.

### Caso G12 — Llega la fecha de inicio sin aprobación

- La gira permanece bloqueada.
- Dashboard y bandeja de Tesorería muestran alerta.
- No se habilita automáticamente por fecha ni por silencio del responsable.

### Caso G13 — Vendedor intenta usar gira pendiente/rechazada/vencida

- El frontend no la ofrece.
- El backend rechaza cualquier `presupuesto_id` que no esté aprobado, activo y vigente.

### Caso G14 — Gira aprobada e informe dentro del saldo

- Se permite consolidar documentos dentro de las fechas de la gira.
- Se reserva el monto y continúa la revisión normal de Tesorería.

### Caso G15 — Informe de gira cruza el saldo restante

- Se aplica el mismo tope FIFO hasta el saldo aprobado de la gira.
- No se inicia una aprobación de “exceso de gira”.
- Para disponer de más fondo, Tesorería aumenta la gira y obtiene una nueva aprobación.

### Caso G16 — Fondo de gira agotado

- No se permiten nuevas rendiciones contra esa gira.
- El vendedor puede usar otro fondo aprobado que corresponda o contactar a Tesorería.

### Caso G17 — Documento fuera de las fechas

- El backend rechaza la consolidación completa antes de enlazar documentos.
- El vendedor debe retirar el documento del lote o elegir un fondo válido.

### Caso G18 — Tesorería rechaza documentos de una gira

- Se libera la reserva correspondiente.
- El fondo vuelve a mostrar saldo operativo, siempre que la gira siga vigente y aprobada.

### Caso G19 — Gira desactivada

- Sólo puede desactivarse sin fondos comprometidos o después de resolver sus rendiciones.
- La baja es lógica; no se elimina la gira ni sus aprobaciones.

---

## 7. Seguridad, atomicidad y auditoría

- Todas las mutaciones requieren sesión, permiso y CSRF según el actor.
- Los tokens se generan con 32 bytes aleatorios y sólo se persiste SHA-256.
- Cada token pertenece a una solicitud, versión y responsable determinados.
- Aprobar/rechazar usa `POST`, bloqueo de fila y consumo único.
- Correos, tokens y decisiones se generan fuera de datos libres del navegador; nombre/cargo/email se vuelven a cargar desde `aprobadores_rendiciones` y se guardan como snapshot.
- No se usan `DELETE` físicos.
- Cada reserva, liberación, cambio de responsable, reenvío, decisión, ajuste FIFO y pago genera historial/auditoría.
- Las respuestas mantienen `{ "success": true/false, ... }` sin rutas ni excepciones.

---

## 8. Modelo de datos planificado

### Nueva tabla `solicitudes_aprobacion` — implementada en Fase A

Una solicitud referencia exactamente una gira o una excepción mensual y un responsable seleccionado. Contendrá:

- tipo de solicitud (`GIRA`, `EXCEPCION_MENSUAL`);
- `presupuesto_id` o `rendicion_id`;
- versión;
- aprobador y snapshots de nombre/cargo/correo;
- monto base, monto solicitado y justificación;
- token hash, expiración y uso;
- estado de solicitud y correo;
- decisión, comentario y fechas;
- solicitante y auditoría temporal;
- baja lógica.

### Cambios implementados en `presupuestos_vendedores`

- `estado_aprobacion`;
- `solicitud_aprobacion_id`;
- `justificacion_gira`;
- `aprobado_at`.

### Cambios implementados en `rendiciones_gastos`

- `monto_maximo_aprobable`;
- `monto_exceso_no_reembolsable`;
- `aplico_tope_presupuestario`;
- `solicitud_excepcion_id`.

También se incorporó `solicitud_aprobacion_historial` como bitácora append-only. Los nombres definitivos están alineados en la migración `2026_08_28_topes_y_flujo_aprobaciones.sql`, ambos setup SQL y `docs/DATABASE.md`. La migración quedó aplicada únicamente en Laragon local; producción requiere importación manual por phpMyAdmin.

---

## 9. Plan de implementación por fases

### Fase A — DDL y contratos

- [x] Crear migración idempotente para phpMyAdmin.
- [x] Actualizar ambos setup SQL y documentación de BD.
- [x] Crear tablas/columnas, FKs e índices.
- [x] Aplicar y verificar el esquema en Laragon local.

### Fase B — Dominio y servicio de aprobaciones

- [x] Crear `services/ApprovalWorkflowService.php`.
- [x] Centralizar tokens, versiones, selección de responsable, expiración, reenvío, cancelación y resolución.
- [x] Mantener intactos los campos del flujo anterior para compatibilidad histórica.
- [x] Validar gira, excepción aprobada/rechazada, restricciones SQL, token único, fallo/reenvío, vencimiento y cancelación con 28 pruebas transaccionales y `ROLLBACK`.

### Fase C — Consolidación y reservas

- Modificar `api/rendiciones/guardar_rendicion.php`.
- Calcular saldo aprobado/pendiente y máximo pagable.
- Bloquear saldo cero y permitir el último informe que cruza el tope.
- Reservar sólo el máximo pagable.

### Fase D — Revisión de Tesorería y FIFO

- Modificar `admin/api/rendiciones/cambiar_estado.php`.
- Incorporar aprobación hasta tope, asignación FIFO y liberación de diferencias.
- Separar rechazo de excepción y rechazo de rendición.

### Fase E — Aprobación de giras y excepciones

- Modificar `admin/api/rendiciones/gestion_presupuestos.php`.
- Crear endpoints públicos y páginas de resolución.
- Incorporar cancelación, cambio de responsable y reenvío.

### Fase F — Correos y comprobantes

- Extender `services/MailService.php`.
- Diferenciar claramente autorización de gira y autorización excepcional.
- Adaptar comprobantes PDF y firma textual.

### Fase G — Interfaces

- Actualizar vendedor: advertencia de último informe, bloqueo y fondos de gira sólo aprobados.
- Actualizar Tesorería: máximo pagable, exceso, acción excepcional y estados de gira.
- Mantener Vanilla JS/CSS y selectores/API existentes cuando sea posible.

### Fase H — Dashboard

- Separar presentado, aprobado, reservado y exceso no reembolsable.
- Incorporar solicitudes de excepción y tiempos de aprobación de giras.
- Excluir rechazados y diferencias no pagadas de la ejecución real.

### Fase I — QA y despliegue

- Pruebas transaccionales con rollback para todos los casos M01–M20 y G01–G19.
- Pruebas de tokens, concurrencia, CSRF, RBAC y correo fallido.
- `php -l`, JS syntax, `scripts/verify_release.php` y paridad SHA-256.
- Aplicar migración primero en Laragon y entregar el SQL exacto para phpMyAdmin productivo.
- Actualizar `PROJECT_STATUS`, `CHANGELOG`, `DATABASE`, `API`, `BUSINESS_RULES` y diseño.

---

## 10. Archivos previstos

### Crear

- `config/migrations/2026_08_28_topes_y_aprobaciones_rendiciones.sql`
- `services/ApprovalWorkflowService.php`
- `api/rendiciones/resolver_solicitud.php`
- `rendiciones/aprobar_solicitud.php`
- JS/CSS de la página pública si se desacopla del flujo existente.

### Modificar

- `config/setup.sql`
- `config/setup_rendiciones.sql`
- `services/RendicionesService.php`
- `services/MailService.php`
- `api/rendiciones/guardar_rendicion.php`
- `api/rendiciones/get_bolsa_gastos.php`
- `api/rendiciones/get_mis_rendiciones.php`
- `admin/api/rendiciones/gestion_presupuestos.php`
- `admin/api/rendiciones/cambiar_estado.php`
- APIs de listado, detalle y Dashboard.
- `rendiciones/vendedor.php` y `rendiciones/vendedor.js`.
- `admin/rendiciones.php`, `admin/js/rendiciones.js` y `admin/css/rendiciones.css`.
- Réplicas idénticas dentro de `dist/cheques_cobranza/app/`.

---

## 11. Criterios de aceptación

- Nunca se paga ordinariamente más que el fondo mensual o de gira aprobado.
- Un vendedor con saldo cero no puede enviar otro informe contra ese fondo.
- Un informe que cruza el último saldo puede enviarse con advertencia y liquidarse por FIFO.
- Rechazar una excepción no rechaza la rendición base.
- Una gira no aparece al vendedor antes de ser aprobada.
- Sólo decide el responsable elegido y los tokens antiguos no son reutilizables.
- Un aumento de gira aprobada exige nueva autorización.
- Dashboard y presupuesto vendedor separan aprobado real de pendiente reservado.
- Todas las decisiones son auditables y no existen eliminaciones físicas.
