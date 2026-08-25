# Diseño Conceptual: Sistema de Rendiciones de Gastos y Viáticos

> **Estado:** Fase de Levantamiento & Diseño Conceptual Validado  
> **Fecha:** Agosto 2026  
> **Ubicación en Ecosistema:** Módulo 3 (Extensión de Tesorería & Vendedores)

---

## 1. Resumen Ejecutivo y Problemática a Resolver

Actualmente, el proceso de rendición de gastos por concepto de viáticos, combustible, peajes, alojamientos, alimentación y giras comerciales opera de forma descentralizada y manual:
- Vendedores completan planillas Excel manuales.
- Envían respaldos físicos por transportistas/valijas (especialmente regiones) o por correo electrónico.
- Las aprobaciones de excesos de presupuesto se solicitan informalmente a jefatura (**Francisco J.**) vía **WhatsApp**, careciendo de validez legal, trazabilidad histórica y pistas de auditoría.
- Dificultades para acreditar ante el SII los gastos de representación comercial (*Cenas con Clientes*).

### Objetivo del Proyecto
Centralizar, digitalizar y blindar el flujo de rendiciones de gastos dentro del mismo ecosistema web del sistema de cobranza, garantizando:
1. **Presupuestos Mensuales y Fondos por Gira:** Gestión directa y segmentada por Tesorería.
2. **Carga Progresiva en Terreno (Estilo Rindegastos):** El vendedor digitaliza gastos al instante durante sus viajes y selecciona cuáles consolidar al rendir.
3. **Aprobación de Excesos Formal y Digital:** Eliminación de WhatsApp mediante correos con enlaces de acción rápida de un solo uso (Magic Token).
4. **Respaldo Tributario Estricto (SII):** Captura obligatoria de invitados y propósito comercial en Cenas con Clientes.
5. **Atomicidad Bancaria y Prevención de Fraude:** Trazabilidad estricta e imposibilidad técnica de duplicar o reutilizar boletas/facturas (`document_hash`).
6. **Recepción Física Auditada:** Control de recepción de valijas físicas en oficina central previo al reembolso.
7. **Separación Modular:** Coexistencia armónica en la suite de Tesorería (Cheques de Cobranza, Cuentas Corrientes y Rendiciones de Gastos).

---

## 2. Arquitectura del Ecosistema (La Tríada de Portales)

El acceso administrativo de Tesorería se estructura en tres módulos independientes pero integrados:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        SUITE TESORERÍA / ADMIN                         │
├───────────────────┬───────────────────────┬────────────────────────────┤
│ 1. Cheques de     │ 2. Cuentas Corrientes │ 3. Rendiciones de Gastos   │
│    Cobranza       │    (Despacho Diario   │    (Viáticos, Presupuestos │
│    (Recaudación)  │     y Digitadoras)    │     y Aprobación Excesos)  │
└───────────────────┴───────────────────────┴────────────────────────────┘
```

### 2.1. Acceso del Vendedor: Integración en la App Vendedores (Preexistente)

El acceso de los vendedores a este módulo **no requiere una aplicación separada ni nuevas credenciales**:

1. **Punto de Entrada Unificado en App Vendedores:**
   - La **App Vendedores** (aplicación corporativa preexistente que la fuerza de ventas ya utiliza en terreno para pedidos, clientes y cobranzas) incorpora un botón dedicado:  
     👉 **`"Rendir Gastos / Viáticos"`** (ubicado en el menú principal y sección de gestión comercial).
2. **Traspaso Seguro de Contexto e Identidad:**
   - Al pulsar el botón, se consume automáticamente el `vendedor_id` (`vend_cod`) y la `empresa` desde la sesión activa de la App Vendedores, transfiriendo el contexto de forma transparente y autenticada (idéntico a la arquitectura documentada en `docs/INTEGRATION.md` para la recaudación de cheques).
   - Inmediatamente tras la autenticación, se aplica sanitización de URL (`history.replaceState`) para no exponer identificadores sensibles en la barra de direcciones.

3. **Dashboard de Presupuestos del Vendedor (Métricas en Tiempo Real):**
   Al ingresar a la vista de rendición, el vendedor visualiza un panel ejecutivo con sus fondos disponibles:
   - 🎯 **Presupuesto Mensual Activo:** Cupo ordinario del mes (`monto_asignado`).
   - ✈️ **Presupuestos de Giras Activas:** Fondos extraordinarios asignados a giras específicas (ej: *"Gira Antofagasta"*).
   - 📊 **Monto Rendido / Comprometido:** Total de comprobantes ya presentados en el periodo.
   - 🟢 **Saldo Disponible en Vivo:** Cupo restante calculado al segundo.

4. **Bolsa de Documentos y Carga Progresiva (Workflow Rindegastos):**
   - El vendedor no tiene que esperar a fin de mes para ingresar boletas; puede fotografiarlas y guardarlas en su **"Bolsa de Gastos en Borrador"** apenas realiza el consumo.
   - Al momento de rendir, el vendedor selecciona mediante checkboxes cuáles boletas de su bolsa desea imputar al **Presupuesto Mensual** o a una **Gira Específica**.

---

## 3. Reglas de Negocio Validadas

| Componente | Regla de Negocio Definida |
|---|---|
| **Acceso Vendedor** | **Botón en App Vendedores:** Acceso directo desde la aplicación comercial existente, consumiendo `vendedor_id` y `empresa` de la sesión activa de terreno. |
| **Presupuestos Ordinarios (Mensuales)** | **Mensual y Tope:** Asignado por mes (`periodo_mes`). Si no se utiliza completo, el saldo restante **no es acumulable**. |
| **Presupuestos Extraordinarios (Giras)** | **Fondos Asignados por Gira:** Tesorería puede asignar presupuestos especiales por Gira comercial (ej: *"Gira Antofagasta: $300.000"*). Las rendiciones imputadas a una Gira **se descuentan exclusivamente de ese fondo**, sin mermar el presupuesto mensual estándar del vendedor. |
| **Carga Progresiva de Boletas** | Los vendedores pueden ingresar boletas de manera progresiva día a día durante sus viajes. Al momento de formalizar el envío, seleccionan qué comprobantes rendir en ese corte. |
| **Flexibilidad Intermensual** | Si un vendedor agota su presupuesto en un mes (ej: Agosto), puede guardar comprobantes legítimos para imputarlos en la rendición del mes siguiente (Septiembre), siempre que la fecha de emisión esté dentro de un rango razonable que Tesorería verificará. |
| **Simplificación en Peajes** | Los comprobantes de **Peajes** solo requieren **Fecha**, **Monto** y **Foto del ticket/comprobante**. No es obligatorio digitar RUT del proveedor ni razón social. |
| **Cenas con Clientes (Respaldo SII)** | Categoría especial de gastos de representación. Para cumplir con exigencias tributarias del SII y justificar la causal comercial, el formulario **exige obligatoriamente**: <br>1. **Identificación del Invitado:** Nombre completo, RUT y Empresa/Cargo del cliente.<br>2. **Propósito Comercial:** Motivo concreto de la reunión registrado en el detalle. |
| **Taxonomía de Categorías** | **Lista Estandarizada Obligatoria:** `Bencina`, `Colación (Comidas)`, `Hospedaje`, `Peajes`, `Estacionamiento`, `Cena Cliente` y `Otros`. |
| **Aprobación de Excesos** | Si la sumatoria de una rendición sobrepasa el presupuesto disponible (mensual o de gira), el flujo se detiene y se envía un correo a **Francisco J.** con un **Magic Token de 1 solo uso** para Aprobar o Rechazar el exceso con 1 clic. |
| **Control Antifraude / No-Duplicidad** | Ninguna boleta, factura o ticket puede ingresarse dos veces en el holding (control estricto por hash criptográfico `document_hash`). |
| **Gestión de Discrepancias / Rechazo** | Si hay un error de digitación, Tesorería contacta al vendedor para confirmar el monto real. Ocasionalmente se puede rechazar una boleta puntual sin anular el resto de ítems válidos de la rendición. |
| **Recepción Documental Física** | Punto de control obligatorio en Tesorería para marcar **"Documentos Físicos Recibidos en Oficina"** para vendedores que envían valijas antes de autorizar el pago/reembolso. |
| **Seguridad y Stack** | Mantener stack PHP puro + PDO, Prepared Statements obligatorios, zero frameworks pesados, separación estricta de responsabilidades. |

---

## 4. Mecanismo Antifraude & Atomicidad (Inspiración Bancaria)

Para erradicar la duplicidad de gastos y asegurar integridad total:

1. **Huella Digital Única de Documento (Hash / Unique Index):**
   * Creación de un índice único en base de datos con hash SHA-256:
     - Para Facturas / Boletas: $\text{SHA256}(\text{RUT\_Proveedor} + \text{Tipo\_Doc} + \text{Numero\_Folio})$
     - Para Peajes: $\text{SHA256}(\text{"PEAJE"} + \text{Fecha} + \text{Monto} + \text{Vendedor\_ID})$
   * Si un vendedor intenta ingresar un documento cuyo identificador ya existe en el histórico del holding, el sistema **bloquea el ingreso en tiempo real**.

2. **Trazabilidad de Estados Inmutable:**
   * Toda transición de estado quedará registrada en una tabla de auditoría con fecha, hora exacta, IP, usuario responsable y comentarios (análogo a un log contable).

---

## 5. Flujo de Aprobación de Excesos con Magic Token (Francisco J.)

Para sustituir el "WhatsApp como firma":

```
[Vendedor rinde $450.000 (Presupuesto asignado: $350.000)]
                           │
                           ▼
[Exceso detectado: +$100.000] ──► [Rendición en estado PENDIENTE_APROBACION_EXCESO]
                                                       │
                                                       ▼
                                   [Se genera Magic Token Criptográfico (48 hrs)]
                                                       │
                                                       ▼
                                   [Correo a Francisco J. con tabla y desglose de gastos]
                                                       │
                           ┌───────────────────────────┴───────────────────────────┐
                           ▼                                                       ▼
                  [Botón: APROBAR EXCESO]                                 [Botón: RECHAZAR EXCESO]
                           │                                                       │
                           ▼                                                       ▼
              [Token se consume (Uso Único)]                          [Token se consume (Uso Único)]
              [Se registra firma digital y fecha]                     [Se notifica rechazo a Vendedor]
              [Pasa a EN_REVISION_TESORERIA]                          [Rendición pasa a RECHAZADA]
```

---

## 6. Propuesta de Modelo de Datos Conceptual

```sql
-- 1. Presupuestos asignados a vendedores (Mensuales y por Gira)
CREATE TABLE presupuestos_vendedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendedor_id INT NOT NULL,
    empresa_id INT NOT NULL,
    tipo_presupuesto ENUM('MENSUAL', 'GIRA') NOT NULL DEFAULT 'MENSUAL',
    nombre_gira VARCHAR(100) NULL, -- Ej: 'Gira Antofagasta - Calama'
    periodo_mes VARCHAR(7) NOT NULL, -- Ej: '2026-08'
    fecha_inicio DATE NULL,
    fecha_fin DATE NULL,
    monto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    monto_utilizado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    activo TINYINT(1) DEFAULT 1,
    creado_por INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vendedor_tipo (vendedor_id, tipo_presupuesto, activo)
);

-- 2. Rendiciones de gastos (Cabecera consolidada)
CREATE TABLE rendiciones_gastos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_rendicion VARCHAR(20) NOT NULL UNIQUE, -- Ej: 'RND-2026-0001'
    vendedor_id INT NOT NULL,
    empresa_id INT NOT NULL,
    presupuesto_id INT NOT NULL, -- Apunta al presupuesto mensual o de gira
    periodo_mes VARCHAR(7) NOT NULL,
    tipo_rendicion ENUM('MENSUAL', 'GIRA') NOT NULL DEFAULT 'MENSUAL',
    monto_total_rendido DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    monto_presupuesto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    monto_exceso DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    requiere_aprobacion_exceso TINYINT(1) DEFAULT 0,
    token_aprobacion_exceso VARCHAR(128) NULL UNIQUE,
    token_exceso_expira DATETIME NULL,
    aprobado_exceso_at DATETIME NULL,
    aprobado_exceso_por VARCHAR(100) NULL,
    estado ENUM(
        'BORRADOR',
        'ENVIADA',
        'PENDIENTE_APROBACION_EXCESO',
        'EN_REVISION_TESORERIA',
        'DOCUMENTOS_FISICOS_RECIBIDOS',
        'APROBADA',
        'APROBADA_PARCIAL',
        'RECHAZADA',
        'PAGADA'
    ) NOT NULL DEFAULT 'ENVIADA',
    documentos_fisicos_recibidos TINYINT(1) DEFAULT 0,
    fecha_recepcion_fisica DATETIME NULL,
    recibido_fisico_por INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (presupuesto_id) REFERENCES presupuestos_vendedores(id) ON DELETE RESTRICT
);

-- 3. Detalle de Documentos / Boletas / Facturas / Peajes / Cenas
CREATE TABLE rendicion_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendedor_id INT NOT NULL, -- Permite bolsa de gastos en borrador previa a la rendición
    rendicion_id INT NULL,    -- NULL mientras esté en bolsa borrador del vendedor
    tipo_documento ENUM('BOLETA_ELECTRONICA', 'FACTURA_ELECTRONICA', 'PEAJE', 'PASAJES', 'OTRO') NOT NULL,
    categoria_gasto ENUM(
        'BENCINA',
        'COLACION',
        'HOSPEDAJE',
        'PEAJES',
        'ESTACIONAMIENTO',
        'CENA_CLIENTE',
        'OTROS'
    ) NOT NULL DEFAULT 'OTROS',
    rut_proveedor VARCHAR(20) NULL, -- Opcional en PEAJES
    razon_social_proveedor VARCHAR(150) NULL,
    numero_documento VARCHAR(50) NULL, -- Opcional en PEAJES
    fecha_emision DATE NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    descripcion VARCHAR(255) NULL,
    foto_documento_url VARCHAR(255) NOT NULL,
    document_hash VARCHAR(64) NOT NULL UNIQUE, -- SHA-256 para prevenir duplicidad

    -- Campos específicos para CENA_CLIENTE (Respaldo Tributario SII)
    cliente_invitado_nombre VARCHAR(150) NULL,
    cliente_invitado_rut VARCHAR(20) NULL,
    cliente_invitado_empresa VARCHAR(150) NULL,
    cliente_invitado_cargo VARCHAR(100) NULL,
    proposito_comercial TEXT NULL,

    estado_item ENUM('BORRADOR', 'PENDIENTE', 'APROBADO', 'RECHAZADO') NOT NULL DEFAULT 'BORRADOR',
    motivo_rechazo VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE SET NULL,
    INDEX idx_vendedor_bolsa (vendedor_id, estado_item)
);

-- 4. Pistas de Auditoría y Trazabilidad (Inmutable)
CREATE TABLE rendicion_historial_estados (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rendicion_id INT NOT NULL,
    usuario_id INT NULL,
    actor_nombre VARCHAR(100) NOT NULL,
    estado_anterior VARCHAR(50),
    estado_nuevo VARCHAR(50) NOT NULL,
    comentario TEXT,
    ip_origen VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE RESTRICT
);
```

---

## 7. Resolución de Inquietudes y Acuerdos de Negocio

Los aspectos de negocio han sido formalmente definidos y acordados para la implementación:

### ✅ 1. Manejo Operativo del Rechazo Parcial y Rectificación de Montos
* **Definición:**  
  - No es el flujo habitual cancelar boletas, pero el sistema debe permitir **cancelar/rechazar una boleta puntual** del total si corresponde (cambiando `estado_item` a `RECHAZADO` con `motivo_rechazo`).
  - Si el vendedor digitó erróneamente el monto de una boleta o el total del comprobante, el procedimiento operativo es **contactarlo para confirmar el monto real y conciliar la discrepancia**.
  - Tesorería tendrá la facultad de corregir el monto auditado o rechazar la boleta específica, recalculando el total neto a liquidar sin necesidad de anular toda la rendición del vendedor.

---

### ✅ 2. Taxonomía Estandarizada de Categorías de Gastos
* **Definición:**  
  Se implementa una **lista cerrada estándar obligatoria** en el selector del vendedor y en los filtros de administración para garantizar consistencia y reportería analítica:
  1. ⛽ **Bencina** (`BENCINA`) — Combustible vehicular.
  2. 🍽️ **Colación** (`COLACION`) — Alimentación, almuerzos y viáticos diarios individuales.
  3. 🏨 **Hospedaje** (`HOSPEDAJE`) — Alojamiento y estadías en ruta.
  4. 🛣️ **Peajes** (`PEAJES`) — Tags, peajes interurbanos y boletas de concesionarias (carga rápida: solo fecha, monto y foto).
  5. 🅿️ **Estacionamiento** (`ESTACIONAMIENTO`) — Parkings y parquímetros.
  6. 🥂 **Cena Cliente** (`CENA_CLIENTE`) — Gastos de representación comercial con clientes clave.
  7. 📦 **Otros** (`OTROS`) — Gastos varios justificados (por flexibilidad operativa).

---

### ✅ 3. Requisitos Tributarios para "Cena Cliente" (Normativa SII)
* **Definición:**  
  Para que el gasto sea tributariamente aceptado y defendible ante auditorías del SII (Art. 31 LIR):
  - No basta la boleta del restaurante; se exige vincular la causal y efectividad de la reunión comercial.
  - **Campos obligatorios:**
    1. `cliente_invitado_nombre`: Nombre completo del cliente agasajado.
    2. `cliente_invitado_rut`: RUT de la empresa o del cliente.
    3. `cliente_invitado_empresa` / `cliente_invitado_cargo`: Razón social y cargo del contacto.
    4. `proposito_comercial`: Objetivo concreto de la reunión (ej: *"Cierre de acuerdo comercial de repuestos para flota Minera"*).

---

### ✅ 4. Presupuestos por Gira vs Presupuestos Mensuales
* **Definición:**  
  - **Presupuesto Ordinario:** Fondo mensual habitual para su zona local.
  - **Presupuesto de Gira:** Fondo extraordinario asignado a una gira de trabajo fuera de su zona base (ej: *"Gira Antofagasta: $300.000"*).
  - Al rendir, el vendedor elige si el lote de gastos pertenece a su cupo mensual o a una Gira activa. La rendición de gira se descuenta únicamente del fondo de dicha gira.

---

### ✅ 5. Carga Progresiva y Flexibilidad Intermensual
* **Definición:**  
  - **Bolsa de Gastos (Workflow Rindegastos):** Los vendedores van guardando boletas de manera progresiva durante sus giras. Cuando deciden rendir, marcan las boletas que desean consolidar.
  - **Traspaso de Boletas:** Si un vendedor agotó su presupuesto mensual en agosto, puede guardar comprobantes legítimos para imputarlos en la rendición de septiembre, siempre dentro de un límite razonable que Tesorería auditará.

---

## 8. Próximos Pasos para Fase 3 (Desarrollo del Módulo)

1. **Creación del Script DDL de Base de Datos:**
   - Crear las 4 tablas en MySQL (`presupuestos_vendedores`, `rendiciones_gastos`, `rendicion_documentos`, `rendicion_historial_estados`) con soporte para Giras, Cenas Clientes y Bolsa de Gastos.
2. **Desarrollo de Endpoints API Backend (PHP + PDO):**
   - `guardar_documento_bolsa.php`: Carga progresiva individual de boletas con cálculo SHA-256.
   - `guardar_rendicion.php`: Consolidación y envío de lote de boletas seleccionadas contra presupuesto mensual o de gira.
   - `aprobar_exceso.php`: Endpoint protegido por Magic Token para Francisco J.
   - `admin/api/presupuestos.php`: CRUD de presupuestos mensuales y giras para Tesorería.
3. **Desarrollo del Frontend Administrativo y Móvil/Vendedor:**
   - Vista de Tesorería (`admin/rendiciones.php` + `admin/js/rendiciones.js`) integrada al App Switcher.
   - Vista de Vendedor (`vendedores/rendicion_gastos.html`) con bolsa de comprobantes, strip de saldos (mensual vs gira) y formulario adaptativo (con campos SII para Cenas y modo rápido para Peajes).
   - Plantilla de Correo de Aprobación para Francisco J. (diseño responsivo móvil y desktop con botones protegidos).
4. **Sincronización Dual y Auditoría:**
   - Sincronización exacta en `dist/` y pruebas de integración.
