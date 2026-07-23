# Especificación Funcional y Técnica - MVP Módulo de Gestión y Seguimiento de Cobranzas

**Proyecto:** Módulo Digital de Cobranza de Cheques  
**Solicitante:** Miguel Martínez / S. Valenzuela (`svalenzuela@automarco.com`)  
**Fecha de Documentación:** Julio 2026  
**Versión:** 1.0 (MVP)

---

## 1. Resumen Ejecutivo y Diagnóstico

### 1.1. Diagnóstico del Problema Actual
Actualmente, el proceso de cobranza y trazabilidad de cheques emitidos por clientes presenta graves brechas operativas:
* **Falta de Trazabilidad:** No existe un registro centralizado ni seguimiento desde que el vendedor en terreno (Santiago o Regiones) recibe un cheque físico hasta que este llega a la empresa, es recepcionado por Tesorería y finalmente archivado/depositado en fecha.
* **Proceso Informal ("Parche"):** La gestión de respaldos opera mediante fotos informales de cheques y guías de Chilexpress enviadas por WhatsApp personal a la tesorera.
* **Riesgo de Pérdida o Extravío:** Documentos de alto valor quedan en tránsito logístico sin alertas ni controles formales sobre días transcurridos.

### 1.2. Objetivo de la Solución
Desarrollar una aplicación web responsive (optimizada para tablets y dispositivos móviles en terreno) que digitalice y automatice la captura, validación ERP, notificación al cliente y seguimiento logístico/bancario de los cheques cobrados por la fuerza de ventas.

---

## 2. Alcance y Funcionalidades del MVP

### 2.1. Funcionalidades Core (Requeridas)

#### F1. Captura Digital y Validación ERP de Facturas
* **Captura Fotográfica con Cámara:** Integración directa con la cámara del dispositivo móvil/tablet para fotografiar el anverso/reverso del cheque.
* **Detalle Dinámico por Cheque:** Posibilidad de registrar múltiples cheques dentro de un mismo proceso de cobranza.
* **Comentarios/Observaciones por Cheque:** Cada cheque cuenta con un campo de texto independiente para observaciones adicionales (ej: *cheque cruzado, requiere confirmación de firma, detalle al reverso*).
* **Conexión Dinámica con ERP por Empresa:** Al seleccionar la empresa del Holding, la app consulta en tiempo real en la base de datos ERP correspondiente para traer el RUT, Nombre del Cliente y Monto de la Factura digitada.

#### F2. Notificaciones Automáticas por Correo Electrónico
* **Envío al Cliente (Comprobante de Recepción):** Emisión automática de un correo de respaldo con el detalle de los documentos entregados, la(s) factura(s) extinguidas y las imágenes/fotografías adjuntas de los cheques.
* **Envío a Tesorería / Cobranzas:** Correo de aviso inmediato al departamento de tesorería de la empresa correspondiente con el desglose de lo registrado.

#### F3. Gestión Logística Multimodal
* **Modalidad Regiones (Chilexpress):** Permite ingresar el N° de Seguimiento / OT y tomar la fotografía de la orden de flete. Permite agrupar múltiples cobranzas/cheques bajo un único N° de seguimiento.
* **Modalidad Santiago (Entrega Presencial):** Captura de fotografía del comprobante de recepción firmado manualmente por el encargado en las oficinas centrales.

### 2.2. Funcionalidades Adicionales (Alertas y Control)

#### FA1. Motor de Alertas por Tiempo de Tránsito (Días Transcurridos)
* **Parámetro Configurable:** Configuración de umbral máximo de días transcurridos (ej: 2 días para Santiago, 5 días para Regiones) parametrizable globalmente o por vendedor.
* **Alertas Automáticas:** Un proceso programado (Cron Job/Worker) evalúa los cheques en estado `INGRESADO` o `EN_TRANSITO`. Si superan el límite de días sin ser marcados como `RECIBIDO_TESORERIA`, envía un correo de alerta al vendedor y a la jefatura de cobranza.

---

## 3. Arquitectura del Sistema y Multiempresa (Multi-Tenant)

El holding opera con **4 bases de datos independientes** correspondientes a cada razón social. La nueva aplicación centraliza la gestión logitudinal sin alterar las tablas operativas de los ERPs existentes.

### 3.1. Mapa de Bases de Datos del Holding (Lectura ERP)

| Empresa (UI Formulario) | Nombre BD Servidor Real | Tabla Clientes | Tabla Facturas/Ventas |
| :--- | :--- | :--- | :--- |
| **Automarco LTDA** | `automarc_automarco` | `tbl_clientes` | `tbl_ventas_devoluciones` |
| **HD Automarco S.A** | `autohd_automarcohd` | `tbl_clientes` | `tbl_ventas_devoluciones` |
| **Autotec S.A** | `autotec_ecom` | `tbl_clientes` | `tbl_ventas_devoluciones` |
| **Gabtec S.A** | `gabteccl_sitbdd1978` | `tbl_clientes` | `tbl_ventas_devoluciones` |

### 3.2. Base de Datos Centralizada del Módulo (`bd_modulo_cobranzas`)
Almacena el estado global de los cheques, imágenes subidas, usuarios, cuentas bancarias de depósito y la trazabilidad histórica de estados.

```
┌─────────────────────────────────────────────────────────────────┐
│                     BASES DE DATOS ERP (Lectura)                │
├──────────────────┬──────────────────┬───────────────────────────┤
│ automarc_automarco │ autohd_automarcohd│ autotec_ecom | gabteccl..│
└────────┬─────────┴────────┬─────────┴─────────┬─────────────────┘
         │                  │                   │
         └──────────────────┼───────────────────┘
                            ▼
           ┌──────────────────────────────────┐
           │   MÓDULO NUEVO (Lectura/Escritura)│
           │   bd_modulo_cobranzas            │
           │  - empresas                      │
           │  - usuarios                      │
           │  - cobranzas                     │
           │  - cheques (incluye comentarios) │
           │  - historial_estados             │
           │  - alertas_config                │
           └──────────────────────────────────┘
```

---

## 4. Flujos del Sistema Paso a Paso

> **Nota importante (feedback jefatura):** El flujo del vendedor ocurre en **dos momentos temporales distintos**. El vendedor recibe el cheque, lo registra, y en otro momento (generalmente el día que va a Chilexpress o a Santiago) completa la gestión del envío. No es posible que tenga el cheque y el comprobante al mismo tiempo: si tiene el cheque, aún no fue a Chilexpress; si fue a Chilexpress, ya entregó el cheque.

### Flujo 1 — Paso 1: Registro del Cheque (Estado: `PENDIENTE_ENVIO`)
1. El vendedor accede al módulo web (`Nuevo Envío`).
2. Selecciona la **Empresa** (ej: *Autotec S.A*) e ingresa el **N° Factura**.
3. El backend consulta de manera transparente la base de datos ERP y autocompleta:
   - Nombre/Razón Social del Cliente.
   - RUT del Cliente.
   - Monto Total de la Factura.
4. Agrega uno o más cheques:
   - Selecciona Banco, N° Cheque, Monto y Fecha Vencimiento.
   - Fotografa el cheque con la cámara del dispositivo.
   - *(Opcional)* Escribe un comentario individual por cheque.
5. Completa el correo de Tesorería (y opcionalmente el del cliente).
6. Presiona `Registrar Cobranza`.
7. La cobranza queda guardada en estado **`PENDIENTE_ENVIO`**. El vendedor aún no tiene el comprobante porque no ha gestionado el envío.

### Flujo 2 — Paso 2: Gestión del Envío (Estado: `EN_TRANSITO` o `ENTREGADO_SANTIAGO`)
En un **momento posterior** (cuando el vendedor lleva el sobre físico a despachar):
1. El vendedor entra a la vista `Ver Cheques Enviados` y localiza la cobranza en estado `PENDIENTE_ENVIO`.
2. Presiona el botón `Completar Envío` en la tarjeta de esa cobranza.
3. Selecciona el tipo de envío y adjunta el comprobante:
   - **Chilexpress:** Fotografa el comprobante/OT de Chilexpress y opcionalmente ingresa el N° de seguimiento.
   - **Presencial Santiago:** Fotografa la firma del encargado como comprobante de recepción.
4. El sistema actualiza el estado:
   - `EN_TRANSITO` (Chilexpress) o `ENTREGADO_SANTIAGO` (presencial).
5. Se dispara la **notificación por correo** a Tesorería y al cliente (si tiene email), confirmando que los documentos están en camino.

### Flujo 3: Agrupación Logística de Envíos
* Si el vendedor realiza múltiples cobros en una gira regional, registra cada cobranza asociando al **mismo N° de Seguimiento / OT de Chilexpress**.
* En el módulo de seguimiento, todos los cheques pertenecientes a esa OT se agrupan en una única vista logística.

### Flujo 4: Notificación y Confirmación por Correo
* Al guardar la cobranza:
  1. Se genera un comprobante digital en formato PDF/HTML.
  2. Se envía un e-mail al **Cliente** confirmando la recepción física de los cheques.
  3. Se envía un e-mail al equipo de **Tesorería** correspondiente (`tesoreria@autotec.cl`) adjuntando el detalle y fotos para que preparen la recepción en el acordeón contable.

### Flujo 4: Recepción y Conciliación Bancaria (Tesorería)
1. El personal de Tesorería ingresa a la vista `Ver Cheques Enviados` / `Consola Web`.
2. Filtra por estado (`EN_TRANSITO` o `RECIBIDO_TESORERIA`).
3. Al recibir el sobre físico con los cheques:
   * Cambia el estado a **`RECIBIDO_TESORERIA`**.
   * Ubica el documento en el acordeón físico por fecha de vencimiento.
4. En la fecha de vencimiento:
   * Registra el depósito indicando la Cuenta Bancaria de Destino y el N° de Papeleta/Transacción.
   * Cambia el estado a **`DEPOSITADO`** (o **`RECHAZADO`** con motivo específico en caso de protesto).

### Flujo 5: Motor de Alertas por Días Transcurridos
1. Un proceso programado (*Cron job* ejecutable cada medianoche) revisa las cobranzas pendientes.
2. Calcula: `Días Transcurridos = Fecha Actual - Fecha de Creación`.
3. Si `Días Transcurridos > Días Máximos Permitidos` y el estado no es `RECIBIDO_TESORERIA`:
   * Dispara un correo de alerta urgente al vendedor y al supervisor de cobranzas notificando la demora en la entrega física de los cheques.

---

## 5. Modelo de Datos Unificado (DBML)

```dbml
// ======================================================
// MÓDULO CENTRAL DE COBRANZA Y SEGUIMIENTO (v5 - Final)
// ======================================================

Enum rol_enum { 
  VENDEDOR
  TESORERIA
  ADMINISTRADOR
}

Enum tipo_entrega_enum { 
  CHILEXPRESS
  PRESENCIAL_SANTIAGO
}

Enum estado_cobranza_enum { 
  PENDIENTE_ENVIO    // Cheque registrado, envío físico aún no gestionado (NUEVO)
  EN_TRANSITO        // Gestionado por Chilexpress (con comprobante adjunto)
  ENTREGADO_SANTIAGO // Entregado presencialmente en oficinas Santiago (NUEVO)
  RECIBIDO_TESORERIA
  DEPOSITADO
  RECHAZADO
}

Table empresas {
  id integer [pk, increment]
  nombre varchar(100) [not null, note: 'Nombre comercial (ej: Autotec S.A)']
  nombre_bd varchar(100) [not null, unique, note: 'Nombre BD real (ej: autotec_ecom, automarc_automarco)']
  email_tesoreria_defecto varchar(150) [not null]
  dias_maximos_envio integer [default: 3, note: 'Días tolerados en tránsito antes de alerta']
  created_at timestamp [default: `now()`]
}

Table usuarios {
  id integer [pk, increment]
  nombre varchar(100) [not null]
  email varchar(150) [not null, unique]
  password_hash varchar(255)
  rol rol_enum [not null, default: 'TESORERIA']
  dias_alerta_personalizado integer [note: 'Override de días de alerta por vendedor']
  activo boolean [default: true]
  created_at timestamp [default: `now()`]
}

Table cobranzas {
  id integer [pk, increment]
  empresa_id integer [not null, ref: > empresas.id]
  vendedor_id integer [ref: > usuarios.id]
  vendedor_nombre varchar(100)
  
  numero_factura varchar(50) [not null]
  rut_cliente varchar(20) [not null]
  razon_social_cliente varchar(200)
  monto_total_factura decimal(12,0)
  
  email_cliente varchar(150)
  email_tesoreria varchar(150)
  
  tipo_entrega tipo_entrega_enum [not null]
  numero_seguimiento varchar(100)
  comprobante_url varchar(255)
  
  estado estado_cobranza_enum [not null, default: 'INGRESADO']
  created_at timestamp [default: `now()`]
}

Table cheques {
  id integer [pk, increment]
  cobranza_id integer [not null, ref: > cobranzas.id]
  banco varchar(100) [not null]
  numero_cheque varchar(50) [not null]
  monto decimal(12,0) [not null]
  fecha_vencimiento date [not null]
  foto_cheque_url varchar(255) [not null]
  
  comentario text [note: 'Comentario opcional por cheque']
  
  numero_papeleta_deposito varchar(50)
  fecha_deposito_real timestamp
  created_at timestamp [default: `now()`]
}

Table historial_estados {
  id integer [pk, increment]
  cobranza_id integer [not null, ref: > cobranzas.id]
  usuario_id integer [not null, ref: > usuarios.id]
  estado_anterior estado_cobranza_enum
  estado_nuevo estado_cobranza_enum [not null]
  comentario text
  created_at timestamp [default: `now()`]
}
```

---

## 6. Hoja de Ruta de Implementación (MVP)

1. **Fase 1: Configuración de Base de Datos Central y Vistas ERP**
   * Crear esquema `bd_modulo_cobranzas`.
   * Insertar registros maestros en `empresas` vinculando los nombres reales de las 4 BDs (`automarc_automarco`, `autotec_ecom`, etc.).
2. **Fase 2: Backend de Consultas Dinámicas (API/PHP)**
   * Endpoint de búsqueda de factura que consulta la BD del ERP de la empresa seleccionada.
   * Endpoint de guardado multipart (múltiples imágenes de cheques + respaldos).
3. **Fase 3: Frontend Responsive y Cámara (HTML/JS/CSS)**
   * Implementación de la vista dinámica de agregado de cheques con campo de comentario.
   * Integración de la cámara del navegador (`capture="environment"`).
4. **Fase 4: Servicio de Correo y Notificaciones**
   * Integración PHPMailer / SMTP para enviar comprobante con adjuntos a Cliente y Tesorería.
5. **Fase 5: Consola de Tesorería y Worker de Alertas**
   * Vista de seguimiento con filtros por empresa, estado y barra de búsqueda.
   * Script Cron automatizado para alertas por demora en tránsito.
