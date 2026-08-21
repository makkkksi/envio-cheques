# Diseño Conceptual: Sistema de Rendiciones de Gastos y Viáticos

> **Estado:** Fase de Levantamiento & Diseño Conceptual  
> **Fecha:** Agosto 2026  
> **Ubicación en Ecosistema:** Módulo 3 (Extensión de Tesorería & Vendedores)

---

## 1. Resumen Ejecutivo y Problemática a Resolver

Actualmente, el proceso de rendición de gastos por concepto de viáticos, combustible, peajes, alojamientos y alimentación de los vendedores opera de forma descentralizada y manual:
- Vendedores completan planillas Excel manuales.
- Envían respaldos físicos por transportistas/valijas (especialmente regiones) o por correo electrónico.
- Las aprobaciones de excesos de presupuesto se solicitan informalmente a jefatura (**Francisco J.**) vía **WhatsApp**, careciendo de validez legal, trazabilidad histórica y pistas de auditoría.

### Objetivo del Proyecto
Centralizar, digitalizar y blindar el flujo de rendiciones de gastos dentro del mismo ecosistema web del sistema de cobranza, garantizando:
1. **Presupuestos Mensuales Controlados:** Gestión directa por Tesorería.
2. **Aprobación de Excesos Formal y Digital:** Eliminación del WhatsApp mediante correos con enlaces de acción rápida de un solo uso (Magic Token).
3. **Atomicidad Bancaria y Prevención de Fraude:** Trazabilidad estricta e imposibilidad técnica de duplicar o reutilizar boletas/facturas.
4. **Recepción Física Auditada:** Control de recepción de valijas físicas en oficina central previo al pago final.
5. **Separación Modular:** Coexistencia armónica en la suite de Tesorería (Cheques de Cobranza, Cuentas Corrientes y Rendiciones de Gastos).

---

## 2. Arquitectura del Ecosistema (La Tríada de Portales)

El acceso administrativo de Tesorería se estructurará en tres módulos independientes pero integrados:

```
┌────────────────────────────────────────────────────────────────────────┐
│                        SUITE TESORERÍA / ADMIN                         │
├───────────────────┬───────────────────────┬────────────────────────────┤
│ 1. Cheques de     │ 2. Cuentas Corrientes │ 3. Rendiciones de Gastos   │
│    Cobranza       │    (Despacho Diario   │    (Viáticos, Presupuestos │
│    (Recaudación)  │     y Digitadoras)    │     y Aprobación Excesos)  │
└───────────────────┴───────────────────────┴────────────────────────────┘
```

---

## 3. Reglas de Negocio Validadas

| Componente | Regla de Negocio Definida |
|---|---|
| **Presupuesto Vendedor** | **Mensual y Tope:** Cada vendedor tiene asignado un monto límite para el mes en curso. Puede rendir menos de su cupo. Los saldos no utilizados a fin de mes **no son acumulables**. |
| **Frecuencia de Rendición** | **1 Rendición Consolidada al Mes:** Cada vendedor envía **una única rendición mensual** que agrupa todas las boletas del periodo, cuadrando directamente contra su presupuesto del mes (`periodo_mes`). |
| **Aprobación de Excesos** | Si la sumatoria de documentos de una rendición sobrepasa el presupuesto disponible del vendedor, se congela el flujo y se envía un correo a **Francisco J.** con un **token de un solo uso** para Aprobar o Rechazar el exceso con 1 clic. |
| **Control Antifraude / No-Duplicidad** | Ninguna boleta o factura puede ser ingresada dos veces en el sistema (control estricto por combinación de `RUT Proveedor` + `Tipo Documento` + `Folio / N° Documento` + `Monto`). |
| **Recepción Documental Física** | Para vendedores que envían comprobantes físicos por valija/transportista, Tesorería debe contar con un punto de control para marcar **"Documentos Físicos Recibidos en Oficina"** antes de liberar el reembolso. |
| **Seguridad y Stack** | Mantener el stack PHP puro + PDO, Prepared Statements obligatorios, zero frameworks pesados, separación estricta de responsabilidades. |

---

## 4. Mecanismo Antifraude & Atomicidad (Inspiración Bancaria)

Para erradicar la duplicidad de gastos y asegurar integridad total:

1. **Huella Digital Única de Documento (Hash / Unique Index):**
   * Creación de un índice único en base de datos o hash SHA-256 basado en:
     $$\text{DocumentFingerprint} = \text{SHA256}(\text{RUT\_Proveedor} + \text{Tipo\_Doc} + \text{Numero\_Folio})$$
   * Si un vendedor intenta ingresar un documento cuyo folio y emisor ya existe en el histórico (incluso de meses anteriores o de otro vendedor), el sistema **rechaza el ingreso en tiempo real**.

2. **Trazabilidad de Estados Inmutable:**
   * Toda transición de estado quedará registrada en una tabla de auditoría con fecha, hora exacta, IP, usuario responsable y comentarios (análogo a un log contable).

---

## 5. Flujo de Aprobación de Excesos con Magic Token (Francisco J.)

Para sustituir el "WhatsApp como firma":

```
[Vendedor rinde $450.000 (Presupuesto: $350.000)]
                     │
                     ▼
[Exceso detectado: +$100.000] ──► [Rendición en estado PENDIENTE_APROBACION_EXCESO]
                                                 │
                                                 ▼
                             [Se genera Token Criptográfico (Expira en 48 hrs)]
                                                 │
                                                 ▼
                             [Correo a Francisco J. con tabla resumen de gastos]
                                                 │
                     ┌───────────────────────────┴───────────────────────────┐
                     ▼                                                       ▼
            [Botón: APROBAR EXCESO]                                 [Botón: RECHAZAR EXCESO]
                     │                                                       │
                     ▼                                                       ▼
       [Token se consume (Uso Único)]                          [Token se consume (Uso Único)]
       [Se registra firma digital y fecha]                     [Se notifica rechazo a Vendedor]
       [Pasa a REVISION_TESORERIA]                             [Rendición pasa a RECHAZADA]
```

---

## 6. Propuesta de Modelo de Datos Conceptual

```sql
-- 1. Presupuestos mensuales asignados a vendedores
CREATE TABLE presupuestos_vendedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vendedor_id INT NOT NULL,
    empresa_id INT NOT NULL,
    periodo_mes VARCHAR(7) NOT NULL, -- Ej: '2026-08'
    monto_asignado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    monto_utilizado DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    activo TINYINT(1) DEFAULT 1,
    creado_por INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vendedor_periodo (vendedor_id, empresa_id, periodo_mes)
);

-- 2. Rendiciones de gastos (Cabecera)
CREATE TABLE rendiciones_gastos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo_rendicion VARCHAR(20) NOT NULL UNIQUE, -- Ej: 'RND-2026-0001'
    vendedor_id INT NOT NULL,
    empresa_id INT NOT NULL,
    periodo_mes VARCHAR(7) NOT NULL,
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
    UNIQUE KEY uq_rendicion_vendedor_periodo (vendedor_id, empresa_id, periodo_mes)
);

-- 3. Detalle de Documentos / Boletas / Facturas
CREATE TABLE rendicion_documentos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rendicion_id INT NOT NULL,
    tipo_documento ENUM('BOLETA_ELECTRONICA', 'FACTURA_ELECTRONICA', 'PEAJE', 'PASAJES', 'OTRO') NOT NULL,
    rut_proveedor VARCHAR(20) NOT NULL,
    razon_social_proveedor VARCHAR(150),
    numero_documento VARCHAR(50) NOT NULL,
    fecha_emision DATE NOT NULL,
    monto DECIMAL(12,2) NOT NULL,
    categoria_gasto VARCHAR(50) NULL, -- Ej: Combustible, Alojamiento, etc.
    descripcion VARCHAR(255) NULL,
    foto_documento_url VARCHAR(255) NOT NULL,
    document_hash VARCHAR(64) NOT NULL UNIQUE, -- SHA-256 para prevenir duplicidad
    estado_item ENUM('PENDIENTE', 'APROBADO', 'RECHAZADO') NOT NULL DEFAULT 'PENDIENTE',
    motivo_rechazo VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rendicion_id) REFERENCES rendiciones_gastos(id) ON DELETE RESTRICT
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

## 7. Inquietudes y Puntos Pendientes por Definir

A continuación se listan los aspectos de negocio que quedan abiertos para definir antes del desarrollo:

### ⚠️ Inquietud 1: Manejo Operativo del Rechazo Parcial
* **Escenario:** Un vendedor rinde $180.000 en 4 boletas y Tesorería detecta que 1 boleta de $30.000 no corresponde al giro de la empresa.
* **Opciones a evaluar:**
  * **Opción A (Liquidación Inmediata de lo Válido):** Tesorería aprueba las 3 boletas válidas ($150.000) y rechaza la de $30.000. Se paga $150.000 de inmediato y se cierra la rendición con desglose claro al vendedor.
  * **Opción B (Devolución a Subsanación):** La rendición se devuelve al vendedor con observaciones para que elimine el ítem objetado o adjunte el comprobante correcto antes de volver a enviar.

### ⚠️ Inquietud 2: Taxonomía de Categorías de Gastos
* Definir si se utilizará una lista cerrada estándar (ej: *Combustible / Bencina*, *Peajes y Estacionamientos*, *Alojamiento / Hotelería*, *Alimentación / Viático Diario*, *Pasajes Aéreos / Buses*, *Materiales de Oficina / Muestras*) o si se permitirá campo de texto libre.
* *Recomendación:* Se sugiere lista cerrada fija para alimentar dashboards e informes analíticos por tipo de gasto a lo largo del tiempo.

### ⚠️ Inquietud 3: Política de Cierres de Mes y Fechas Límite
* ¿Existe un día límite en el mes para que los vendedores envíen sus rendiciones (ej: día 25 de cada mes) o es libre durante todo el mes?

---

## 8. Próximos Pasos Recomendados

1. **Revisión y Validación de Inquietudes:** Resolver los puntos 1 al 3 de la sección de inquietudes con el equipo de Tesorería / Administración.
2. **Diseño de Interfaz (UI/UX Mockups):**
   * Pantalla de Vendedor: Formulario ágil de subida de boletas con totalizador automático en tiempo real vs presupuesto restante.
   * Pantalla de Tesorería: Bandeja de entrada con filtros, visor de comprobantes (similar al Lightbox de cheques), gestión de presupuestos por vendedor y módulo de recepción física.
   * Plantilla de Correo de Aprobación para Francisco J. (diseño responsivo móvil y desktop con botones protegidos).
3. **Plan de Implementación:** Creación de tablas, endpoints API REST/JSON y vistas sin afectar los módulos de Cheques y Cuentas Corrientes.
