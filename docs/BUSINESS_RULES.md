# BUSINESS_RULES.md — Reglas de Negocio

**Propósito:** Documentar las reglas de negocio del dominio: estados del cheque, validaciones, permisos por rol, cálculos y flujos operativos.  
**Audiencia:** Desarrolladores, Product Owners, agentes de IA.  
**Referencias:** [`DATABASE.md §1.5`](./DATABASE.md) para estados · [`API.md`](./API.md) para validaciones por endpoint.

---

## 1. Máquina de Estados de la Cobranza

Cada cobranza tiene un estado único que evoluciona en una sola dirección. Los estados son **inmutables hacia atrás**.

> **Descubrimiento clave (feedback del jefe de cobranza):** El registro del cheque y la gestión del envío son **dos eventos separados en el tiempo**. El vendedor primero tiene el cheque (y lo fotografía), pero **no tiene aún el comprobante de Chilexpress** porque recién enviará el sobre después. Este gap temporal requiere un estado intermedio para el vendedor.

```
 ┌─────────────────────┐
 │  PENDIENTE_ENVIO    │  ← Cheque registrado, envío físico aún no gestionado
 └──────────┬──────────┘
            │ Vendedor: completa el envío (adjunta comprobante + N° OT)
            │
            ├─────────────────────────────────────────────────────┐
            ▼                                                     ▼
   ┌────────────────┐                                  ┌──────────────────────┐
   │  EN_TRANSITO   │  ← Chilexpress (con comprobante) │  ENTREGADO_SANTIAGO  │ ← Presencial
   └───────┬────────┘                                  └──────────┬───────────┘
           │                                                      │
           └──────────────────────┬───────────────────────────────┘
                                  ▼
                        RECIBIDO_TESORERIA  ← Tesorería confirma recepción física
                                  │
                    ┌─────────────┴─────────────┐
                    ▼                           ▼
                DEPOSITADO               RECHAZADO
           (cheque cobrado)        (cheque protestado/inválido)
```

### 1.1 Estado inicial por acción del vendedor

| Acción del Vendedor | Estado Resultante | Quién lo asigna |
|---|---|---|
| Registra cheque (sin envío aún) | `PENDIENTE_ENVIO` | Sistema automático al guardar |
| Completa gestión Chilexpress (adjunta comprobante + N° OT) | `EN_TRANSITO` | Sistema al completar el envío |
| Completa entrega presencial Santiago (adjunta firma) | `ENTREGADO_SANTIAGO` | Sistema al completar el envío |

### 1.2 Transiciones permitidas

| Estado actual | Estado siguiente | Quién puede ejecutar |
|---|---|---|
| `PENDIENTE_ENVIO` | `EN_TRANSITO` | Vendedor (App — al completar gestión Chilexpress) |
| `PENDIENTE_ENVIO` | `ENTREGADO_SANTIAGO` | Vendedor (App — al completar entrega presencial) |
| `EN_TRANSITO` | `RECIBIDO_TESORERIA` | Tesorería (Portal Fase 2) |
| `ENTREGADO_SANTIAGO` | `RECIBIDO_TESORERIA` | Tesorería (Portal Fase 2) |
| `RECIBIDO_TESORERIA` | `DEPOSITADO` | Tesorería (Portal Fase 2) |
| `RECIBIDO_TESORERIA` | `RECHAZADO` | Tesorería (Portal Fase 2) |

> **El vendedor no puede cambiar estados hacia atrás.** La app vendedor permite avanzar del estado `PENDIENTE_ENVIO` al de envío correspondiente. Todos los cambios se registran en `historial_estados` (bitácora inmutable).

---

## 2. Reglas de Validación por Entidad

### 2.1 Cobranza

| Campo | Regla |
|-------|-------|
| `empresa_id` | Requerido. Debe existir en `empresas`. |
| `numero_factura` | Requerido. Solo dígitos. Mínimo 4 caracteres. |
| `rut_cliente` | Requerido. Se obtiene del ERP al buscar la factura. |
| `email_tesoreria` | Requerido. Campo oculto en la UI auto-populado con valor predeterminado (`tesoreria@automarco.cl`). Se notifica al completar el envío. |
| `email_cliente` | Opcional. Habilitado mediante una casilla de verificación. Si está activa, muestra el input y permite el autocompletado desde el ERP. |
| Cheques | Se requiere **al menos 1 cheque** por cobranza. |

**Paso 1 — Registro del Cheque (`PENDIENTE_ENVIO`):**
- Solo requiere los datos de la factura y al menos un cheque con su foto.
- El tipo de envío, N° de seguimiento y comprobante **no son requeridos** en este paso.

**Paso 2 — Completar Gestión del Envío:**
- Si es `CHILEXPRESS`: N° OT es opcional, foto del comprobante de Chilexpress es requerida para avanzar a `EN_TRANSITO`.
- Si es `PRESENCIAL_SANTIAGO`: foto de la firma/comprobante de recepción requerida para avanzar a `ENTREGADO_SANTIAGO`.

### 2.2 Cheque individual

| Campo | Regla |
|-------|-------|
| `banco` | Requerido. Debe ser uno de los bancos listados en el select de la UI. |
| `numero_cheque` | Requerido. Solo dígitos. Debe ser único dentro del mismo lote/envío del vendedor (se valida en frontend y backend). |
| `monto` | Requerido. Entero positivo mayor a 0. Se maneja libre e independiente del monto de la factura (UX simplificada). |
| `fecha_vencimiento` | Requerido. Formato `YYYY-MM-DD`. No puede ser fecha pasada. |
| `foto_cheque` | Requerido. Archivo de imagen (`image/*`). |
| `comentario` | Opcional. Texto libre, máximo 1000 caracteres. |

### 2.3 Archivos de imagen

| Regla | Valor |
|-------|-------|
| Tipos permitidos | `image/jpeg`, `image/jpg`, `image/png`, `image/webp`, `image/heic` |
| Tamaño máximo | 10 MB por archivo |
| Nombre generado | `uniqid() . '_' . nombre_sanitizado.ext` |
| Ruta de almacenamiento | `uploads/{empresa_id}/{YYYY-MM}/cheques/` o `/comprobantes/` |

### 2.4 Formato de RUT y Relación ERP

Existe una discrepancia histórica en cómo los ERPs almacenan los RUT de los clientes en sus tablas:
- **Tabla Ventas** (`tbl_ventas_devoluciones`): Guarda el RUT completo sin guion (ej: `52752361`).
- **Tabla Clientes** (`tbl_clientes`): Guarda el RUT con el guion antes del dígito verificador (ej: `5275236-1`).

**Regla de Negocio:** Toda consulta (JOIN) entre estas dos tablas debe ignorar el formato utilizando la función `REPLACE(rut, '-', '')` para asegurar que el cruce de datos sea exitoso. Además, se debe usar siempre `LEFT JOIN` para que una factura sin cliente válido no interrumpa el proceso de cobranza.

### 2.5 Validación de Coincidencia de Montos y Doble Confirmación

Al intentar registrar la cobranza (o al guardar cambios desde el modal de edición de cheques), el sistema realiza una comparación entre el monto total acumulado de los cheques y el monto total de la factura:

- **Monto Mismatch (Diferencia):** Si los montos no coinciden, se despliega una ventana modal de **Diferencia en Montos** sugiriendo al usuario justificar la diferencia agregando un comentario detallado en el cheque. Cuenta con las opciones **"Cerrar y Revisar"** y **"Enviar Igualmente"** (o **"Guardar Igualmente"** al editar).
- **Monto Match (Coincidencia):** Si los montos coinciden perfectamente, se despliega una ventana modal de **Confirmación de Registro** (o **Confirmar Cambios** al editar) indicando la coincidencia y preguntando al usuario si está seguro de registrar/guardar para evitar envíos accidentales rápidos. Cuenta con las opciones **"Cancelar"** y **"Confirmar"**.
- **UX de Montos Simplificada:** El monto de la factura cargado desde el ERP es puramente informativo para el vendedor. Los montos de los cheques se digitan libremente con formateador en tiempo real. Los mensajes de estado del calce eliminan tecnicismos y emojis, indicando si los montos coinciden o cuánto es el monto exacto faltante.

### 2.6 Confirmación al Descartar/Quitar Cheques

Cualquier acción del usuario destinada a remover o quitar un cheque (tanto en el formulario de creación como en el modal de edición) requiere una confirmación de seguridad interactiva (`confirm()`) por parte del vendedor para evitar pérdidas accidentales de datos e imágenes ya capturadas.

### 2.7 Corrección y Edición Manual por Tesorería

En caso de que el vendedor haya ingresado datos incorrectos (monto erróneo, fecha de vencimiento equivocada, número de cheque equivocado o banco erróneo) al digitalizar los documentos, Tesorería dispone de un control en el inspector lateral para **"Corregir datos de cheques"**.
- Solo es aplicable a cobranzas que no se encuentren en estado final (`DEPOSITADO` o `RECHAZADO`).
- Toda modificación requiere autenticación como `TESORERIA` o `ADMINISTRADOR`.
- Las ediciones persisten en la tabla `cheques` y generan automáticamente una traza en la bitácora de `historial_estados` indicando la corrección junto con los datos de auditoría del usuario que ejecutó el cambio. Esto garantiza que la información sea correcta antes de ser distribuida por Cuentas Corrientes.

---

## 3. Cálculo de Monto Total con IVA

El monto de la factura **no se almacena directamente** en los ERPs. Se calcula sumando los ítems y aplicando IVA:

```
Monto Total = ROUND( SUM(neto_item) × 1.19 )
```

- El cálculo lo ejecuta el backend en el endpoint `get_factura.php`.
- El resultado se almacena en `cobranzas.monto_total_factura` al guardar.
- El monto de los cheques individuales los ingresa el vendedor manualmente.
- **No se valida** que la suma de cheques iguale el monto de la factura (puede haber pagos parciales).

---

## 4. Reglas de Notificación por Correo

Al ejecutar `completar_envio.php` exitosamente:

| Destinatario | Condición | Contenido |
|---|---|---|
| Tesorería | **Siempre** (campo `email_tesoreria` de la cobranza) | Resumen de cobranza + tabla de cheques con comentarios + fotos adjuntas |
| Cliente | Solo si `email_cliente` no está vacío | Comprobante de recepción de documentos |

- Si el envío de correo **falla**, la cobranza **no se revierte**. El error se registra en `error_log()`.
- El correo de Tesorería (Fase 2+) incluirá un link directo al detalle de la cobranza en el portal `/admin/`.

---

## 5. Motor de Alertas por Días Transcurridos (Fase 4)

Un proceso programado (Cron Job) evaluará periódicamente las cobranzas pendientes.

**Condición de alerta:**

```
Días Transcurridos = Fecha actual − cobranzas.created_at
Estado ∈ {PENDIENTE_ENVIO, EN_TRANSITO, ENTREGADO_SANTIAGO}
Días Transcurridos > dias_maximos_envio (de la empresa o del vendedor)
```

**Prioridad del parámetro de días:**

```
usuarios.dias_alerta_personalizado (si tiene valor)
    ↓ sino
empresas.dias_maximos_envio (valor por defecto de la empresa)
```

**Acción al detectar alerta:**
- Envía correo urgente al vendedor (`usuarios.email`).
- Envía correo urgente a la jefatura de cobranza.
- La alerta no cambia el estado de la cobranza.

---

## 6. Reglas de Agrupación Logística (Chilexpress)

- Múltiples cobranzas pueden tener el **mismo `numero_seguimiento`**.
- Esto representa cheques de distintos clientes enviados en el mismo sobre/OT.
- En el Portal de Tesorería (Fase 2), se agruparán por `numero_seguimiento` para gestión conjunta.
- La app del vendedor no gestiona esta agrupación; simplemente ingresa el mismo N° de seguimiento.

---

## 7. Permisos por Rol

| Acción | VENDEDOR | TESORERIA | ADMINISTRADOR |
|--------|----------|-----------|---------------|
| Registrar cobranza | ✅ | ❌ | ✅ |
| Ver historial propio | ✅ | ❌ | ✅ |
| Ver todas las cobranzas | ❌ | ✅ | ✅ |
| Completar envío pendiente | ✅ | ❌ | ✅ |
| Cambiar estados posteriores a la entrega | ❌ | ✅ | ✅ |
| Registrar papeleta depósito | ❌ | ✅ | ✅ |
| Gestionar usuarios | ❌ | ❌ | ✅ |
| Configurar empresas | ❌ | ❌ | ✅ |

> Los roles `TESORERIA` y `ADMINISTRADOR` solo acceden desde el portal `/admin/` (Fase 2). La app vendedor no expone estas acciones.

---

## 8. Diseño de la Interfaz de Seguimiento (Vendedor)

Para priorizar la operatividad del vendedor, la vista de seguimiento está dividida en dos componentes con las siguientes características:

### 8.1 Bandeja Principal (Por Enviar)
- **Propósito:** Mostrar únicamente los cheques registrados que aún no han sido despachados físicamente (estado `PENDIENTE_ENVIO`).
- **Ubicación:** Visible directamente en la pestaña de seguimiento.
- **Acciones:**
  - Buscar por Factura, RUT o Razón Social.
  - Ordenar por Fecha (ascendente/descendente) y Estado de proceso.
  - Botón directo para "Completar Envío".

### 8.2 Ventana Modal (Historial de Enviados)
- **Propósito:** Mostrar los registros de cobranzas que ya iniciaron su tránsito o fueron recibidos/procesados (cualquier estado distinto a `PENDIENTE_ENVIO`).
- **Acceso:** Mediante el botón "Ver Cobranzas Enviadas / Historial" en la bandeja principal.
- **Acciones:**
  - Buscar registros de historial.
  - Filtrar por estado de proceso (En Tránsito, Entregado, Recibido, Depositado, Rechazado).
  - Ordenar por Fecha (ascendente/descendente) y Estado.
  - Visualizar el detalle de cheques y ruta logística de cada cobranza.

### 8.3 Visualización de Montos en Tarjetas
- **Monto de la Factura:** Cada tarjeta (tanto en la bandeja principal como en el historial) muestra el monto total de la factura original (`monto_total_factura`) recuperado del ERP.
- **Total en Cheques:** Se expone la sumatoria total del dinero de los cheques adjuntos a la cobranza junto con la cantidad de cheques que contiene, facilitando la comparación rápida de saldos a simple vista.

### 8.4 Comportamiento en Dispositivos Táctiles / Tablets
- **Bloqueo de Desplazamiento del Fondo:** Al abrir cualquier modal overlay, el scroll del fondo de la aplicación (`<body>`) se congela automáticamente para evitar desplazamientos accidentales de la página trasera y mejorar la usabilidad táctil del modal activo.

