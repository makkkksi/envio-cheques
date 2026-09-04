# DATABASE.md — Modelo de Datos

**Propósito:** Documentar el esquema completo de la base de datos central `bd_modulo_cobranzas`, las tablas de los ERPs y los queries template de consulta cross-DB.  
**Audiencia:** Desarrolladores backend, DBAs, agentes de IA.  
**Referencias:** [`ARCHITECTURE.md §3`](./ARCHITECTURE.md) para topología · [`API.md`](./API.md) para uso en endpoints.

---

## 1. Base de Datos Central: `bd_modulo_cobranzas`

Propiedad del módulo. Lectura y escritura. Creada con el script [`config/setup.sql`](../config/setup.sql).

### 1.1 Diagrama de Relaciones

```
empresas ──< cobranzas >── usuarios
                │
                └──< cheques
                │
                └──< historial_estados >── usuarios

empresas ──< presupuestos_vendedores ──< rendiciones_gastos
usuarios ──< aprobadores_rendiciones ──< solicitudes_aprobacion
presupuestos_vendedores ──< solicitudes_aprobacion >── rendiciones_gastos
solicitudes_aprobacion ──< solicitud_aprobacion_historial
                                                │
                                                ├──< rendicion_documentos
                                                └──< rendicion_historial_estados
```

### 1.2 ENUMs globales

```sql
-- Rol de usuario
ENUM('VENDEDOR', 'TESORERIA', 'ADMINISTRADOR', 'SUPERVISORA_CC')

-- Tipo de entrega logística
ENUM('CHILEXPRESS', 'PRESENCIAL_SANTIAGO')

-- Estado del ciclo de vida del cheque
ENUM('PENDIENTE_ENVIO', 'EN_TRANSITO', 'ENTREGADO_SANTIAGO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO')
```

---

### 1.3 Tabla: `empresas`

Mapeo entre las empresas del holding y sus bases de datos ERP reales.

```sql
CREATE TABLE empresas (
  id                      INT AUTO_INCREMENT PRIMARY KEY,
  nombre                  VARCHAR(100) NOT NULL,
  nombre_bd               VARCHAR(100) NOT NULL UNIQUE,  -- nombre real en MySQL
  email_tesoreria_defecto VARCHAR(150) NOT NULL,
  google_sheet_id         VARCHAR(255) NULL,
  dias_maximos_envio      INT DEFAULT 3,
  created_at              TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | INT PK | ID numérico usado en el frontend (value del select) |
| `nombre` | VARCHAR(100) | Nombre comercial mostrado en la UI |
| `nombre_bd` | VARCHAR(100) UNIQUE | Nombre exacto de la BD ERP en MySQL |
| `email_tesoreria_defecto` | VARCHAR(150) | Email al que se notifica al guardar cobranza |
| `google_sheet_id` | VARCHAR(255) NULL | ID del documento de Google Sheets para Tesorería. Sólo `ADMINISTRADOR` puede visualizarlo o editarlo. |
| `dias_maximos_envio` | INT | Días tolerados en tránsito antes de alerta |

**Datos semilla:**

| id | nombre | nombre_bd | email_tesoreria_defecto |
|----|--------|-----------|-------------------------|
| 1 | Automarco LTDA | `automarc_automarco` | tesoreria@automarco.cl |
| 2 | HD Automarco S.A | `autohd_automarcohd` | tesoreria@hdautomarco.cl |
| 3 | Autotec S.A | `autotec_ecom` | tesoreria@autotec.cl |
| 4 | Gabtec S.A | `gabteccl_sitbdd1978` | tesoreria@gabtec.cl |

---

### 1.4 Tabla: `usuarios`

Usuarios del sistema (vendedores, tesorería, admins).

```sql
CREATE TABLE usuarios (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  nombre                   VARCHAR(100) NOT NULL,
  email                    VARCHAR(150) NOT NULL UNIQUE,
  password_hash            VARCHAR(255),
  api_token                VARCHAR(64) NULL,            -- token Bearer para app Android
  rol                      ENUM('VENDEDOR','TESORERIA','ADMINISTRADOR','SUPERVISORA_CC') DEFAULT 'TESORERIA',
  dias_alerta_personalizado INT NULL,                   -- override de dias_maximos_envio
  activo                   BOOLEAN DEFAULT TRUE,
  created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
```

| Campo | Descripción |
|-------|-------------|
| `api_token` | Token generado con `bin2hex(random_bytes(32))`, usado para auth Bearer |
| `dias_alerta_personalizado` | Si tiene valor, sobreescribe `empresas.dias_maximos_envio` para ese vendedor |
| `activo` | Permite desactivar un usuario sin eliminarlo |

**Usuario semilla obligatorio (id=1):**
```sql
INSERT INTO usuarios (id, nombre, email, rol)
VALUES (1, 'Sistema', 'sistema@app.local', 'ADMINISTRADOR');
```
> Este usuario es el placeholder para `historial_estados.usuario_id` durante el desarrollo local (auth en bypass).

---

### 1.5 Tabla: `cobranzas`

Cabecera del registro de cobranza. Una cobranza puede tener N cheques.

```sql
CREATE TABLE cobranzas (
  id                   INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id           INT NOT NULL,
  vendedor_id          INT NULL,                        -- NULL en modo bypass
  vendedor_nombre      VARCHAR(100),                    -- desnormalizado para display
  numero_factura       VARCHAR(50) NOT NULL,
  rut_cliente          VARCHAR(20) NOT NULL,
  razon_social_cliente VARCHAR(200),
  monto_total_factura  DECIMAL(12,0),
  email_cliente        VARCHAR(150),
  email_tesoreria      VARCHAR(150),
  tipo_entrega         ENUM('CHILEXPRESS','PRESENCIAL_SANTIAGO') NULL,
  numero_seguimiento   VARCHAR(100),                    -- OT Chilexpress (si aplica)
  comprobante_url      VARCHAR(255) NULL,               -- ruta relativa a uploads/ (NULL si fue purgada)
  comprobante_purgado_at TIMESTAMP NULL,                -- fecha en que se eliminó el archivo físico
  justificacion_descuadre TEXT NULL,
  estado               ENUM('PENDIENTE_ENVIO','EN_TRANSITO','ENTREGADO_SANTIAGO',
                            'RECIBIDO_TESORERIA','DEPOSITADO','RECHAZADO') DEFAULT 'PENDIENTE_ENVIO',
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;
```

| Campo | Descripción |
|-------|-------------|
| `comprobante_url` | Ruta relativa: `uploads/{empresa_id}/{YYYY-MM}/comprobantes/archivo.jpg` (se setea a `NULL` tras la purga automática) |
| `comprobante_purgado_at` | Timestamp de auditoría cuando el CRON semanal de purga eliminó el archivo físico |
| `numero_seguimiento` | Aplica solo cuando `tipo_entrega = 'CHILEXPRESS'` |
| `justificacion_descuadre` | Razón dada por el vendedor si los montos no coinciden |
| `estado` | Inicia en `PENDIENTE_ENVIO`. El vendedor solo puede avanzar al estado de envío mediante `completar_envio.php`; Tesorería gestiona los estados posteriores. |

**Flujo de entrega:** al registrar la cobranza, `tipo_entrega` permanece `NULL` y el estado es `PENDIENTE_ENVIO`. Al completar el envío, `CHILEXPRESS` cambia a `EN_TRANSITO` y `PRESENCIAL_SANTIAGO` a `ENTREGADO_SANTIAGO`.

---

### 1.6 Tabla: `cheques`

Detalle de cada cheque dentro de una cobranza. Relación 1:N con `cobranzas`.

```sql
CREATE TABLE cheques (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id              INT NOT NULL,
  banco                    VARCHAR(100) NULL,
  numero_cheque            VARCHAR(50) NULL,
  cuenta_corriente         VARCHAR(50) NULL,
  monto                    DECIMAL(12,0) NOT NULL,
  fecha_vencimiento        DATE NOT NULL,
  emitido_a                VARCHAR(200) NULL,
  foto_cheque_url          VARCHAR(255) NULL,           -- ruta relativa a uploads/ (NULL si fue purgada)
  foto_purgada_at          TIMESTAMP NULL,              -- fecha en que se eliminó el archivo físico
  comentario               TEXT NULL,                   -- observación opcional del vendedor
  numero_papeleta_deposito VARCHAR(50) NULL,            -- registrado por Tesorería
  fecha_deposito_real      TIMESTAMP NULL,              -- registrado por Tesorería
  activo                   TINYINT(1) NOT NULL DEFAULT 1, -- Zero Delete: 1 = Activo, 0 = Baja lógica
  descartado_at            TIMESTAMP NULL,              -- Fecha y hora del descarte
  descartado_por           INT NULL,                    -- ID del usuario que descartó
  motivo_descarte          VARCHAR(255) NULL,           -- Motivo o contexto del descarte
  created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cheques_cobranza (cobranza_id),
  KEY idx_cheques_vencimiento_purga (fecha_vencimiento, foto_purgada_at),
  KEY idx_cheques_activo (activo),
  KEY idx_cheques_descartado_por (descartado_por),
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE,
  CONSTRAINT fk_cheques_descartado_usuario FOREIGN KEY (descartado_por) REFERENCES usuarios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

| Campo | Quién lo escribe | Cuándo |
|-------|-----------------|--------|
| `monto`, `fecha_vencimiento`, `foto_cheque_url`, `comentario` | Vendedor | Al registrar la cobranza |
| `foto_purgada_at` | Cron Purga | Al cumplir >3 meses post-vencimiento |
| `banco`, `numero_cheque`, `cuenta_corriente`, `emitido_a` | Tesorería | Al validar (RECIBIDO_TESORERIA) |
| `numero_papeleta_deposito` | Tesorería | Al marcar como `DEPOSITADO` |
| `fecha_deposito_real` | Tesorería | Al marcar como `DEPOSITADO` |
| `activo`, `descartado_at`, `descartado_por`, `motivo_descarte` | Tesorería | Al descartar cheque durante edición (Zero Delete) |

---

### 1.7 Tabla: `historial_estados`

Bitácora inmutable de todos los cambios de estado. Solo se insertan registros, nunca se modifican.

```sql
CREATE TABLE historial_estados (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id    INT NOT NULL,
  usuario_id     INT NOT NULL,
  estado_anterior ENUM('PENDIENTE_ENVIO','EN_TRANSITO','ENTREGADO_SANTIAGO',
                       'RECIBIDO_TESORERIA','DEPOSITADO','RECHAZADO') NULL,  -- NULL en el primer registro
  estado_nuevo   ENUM('PENDIENTE_ENVIO','EN_TRANSITO','ENTREGADO_SANTIAGO',
                      'RECIBIDO_TESORERIA','DEPOSITADO','RECHAZADO') NOT NULL,
  comentario     TEXT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id),
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)
) ENGINE=InnoDB;
```

> `estado_anterior = NULL` indica el registro inicial (creación de la cobranza).

### 1.8 Subsistema: Rendiciones de Gastos y Viáticos

El DDL completo está disponible en [`config/setup_rendiciones.sql`](../config/setup_rendiciones.sql). Ese archivo es también la migración aditiva que debe ejecutarse en phpMyAdmin para incorporar el módulo a una instalación productiva existente.

#### Identidad de vendedor

`vendedor_id` almacena el código nativo `vend_cod` / `cli_vendedor` del ERP. Como esos códigos pueden repetirse entre empresas, la identidad operativa siempre se valida mediante el par `(empresa_id, vendedor_id)`. Estas columnas no referencian `usuarios.id`; los actores administrativos sí lo hacen.

#### Tabla: `presupuestos_vendedores`

| Campo | Regla |
|-------|-------|
| `tipo_presupuesto` | `MENSUAL` o `GIRA`. |
| `periodo_mes` | Periodo contable `YYYY-MM`; el saldo mensual no se acumula. |
| `monto_asignado` | Cupo autorizado por Tesorería. |
| `monto_utilizado` | Monto comprometido por rendiciones activas; se actualiza dentro de transacciones con bloqueo de fila. |
| `estado_aprobacion` | `NO_APLICA` para mensual; una gira transita por `PENDIENTE`, `APROBADA` o `RECHAZADA`. Sólo `APROBADA` podrá exponerse al vendedor cuando se integre el nuevo flujo. |
| `justificacion_gira` | Motivo comercial obligatorio, máximo 500 caracteres, enviado al responsable seleccionado. |
| `solicitud_aprobacion_id` | Solicitud vigente de la gira. Versiones anteriores permanecen en `solicitudes_aprobacion`. |
| `aprobado_at` | Fecha en que el responsable autorizó el fondo; no certifica gastos ni pago. |
| `periodo_clave` | Clave canónica única que impide duplicar un presupuesto equivalente. |
| `activo` | Baja lógica. Nunca se elimina físicamente un presupuesto. |

El saldo disponible se deriva siempre como `monto_asignado - monto_utilizado`; no se persiste como un tercer valor mutable.

Para presentación al vendedor, `monto_aprobado` se deriva de `rendiciones_gastos.monto_total_aprobado` en estados `APROBADA`, `APROBADA_PARCIAL` o `PAGADA`; el monto pendiente se calcula como `monto_utilizado - monto_aprobado`. Son valores derivados y no requieren columnas adicionales.

#### Tabla: `aprobadores_rendiciones`

Catálogo central de los dos responsables habilitados para resolver excesos. El Administrador configura `nombre`, `cargo` y `email`; `orden` identifica las posiciones 1 y 2 y `activo` permite baja lógica. Los correos nunca se definen en código ni en variables de entorno.

Las solicitudes nuevas conservan el responsable elegido y sus snapshots en `solicitudes_aprobacion`. Los campos equivalentes de `rendiciones_gastos` se mantienen temporalmente para lectura histórica del flujo anterior; no son el contrato del nuevo dominio.

#### Tabla: `rendiciones_gastos`

Cabecera consolidada vinculada obligatoriamente a un presupuesto. Mantiene snapshots del presupuesto y saldo disponibles al momento del envío, montos rendido/aprobado y toda la información del flujo de exceso.

| Campo | Regla |
|-------|-------|
| `nota_vendedor` | Observación opcional de hasta 500 caracteres, enviada al consolidar la rendición. Se almacena en la cabecera y en la bitácora `ENVIAR_RENDICION`; Tesorería la visualiza en el detalle. |
| `monto_maximo_aprobable` | Máximo pagable de esa rendición según saldo ordinario reservado y excepciones aprobadas. |
| `monto_exceso_no_reembolsable` | Diferencia presentada que queda fuera de pago mientras no exista una excepción aprobada. |
| `aplico_tope_presupuestario` | Indica que la liquidación fue limitada por el tope del fondo. |
| `solicitud_excepcion_id` | Solicitud excepcional o de aprobación general vinculada; su resolución controla el flujo financiero. |
| `verificado_tesoreria_at` | Timestamp en que Tesorería verificó documentalmente todos los comprobantes y envió a aprobación responsable. |
| `verificado_tesoreria_por` | ID de usuario de Tesorería que verificó la rendición (FK a `usuarios(id)`). |
| `pdf_planilla_url` | Ruta relativa del PDF oficial de la planilla de rendición generado tras la aprobación final. |

Estados válidos:

```text
BORRADOR
ENVIADA
PENDIENTE_APROBACION_EXCESO
EN_REVISION_TESORERIA
PENDIENTE_APROBACION_RESPONSABLE
DOCUMENTOS_FISICOS_RECIBIDOS
APROBADA
APROBADA_PARCIAL
RECHAZADA
PAGADA
```

El Magic Token nunca se almacena en texto plano. `token_aprobacion_exceso_hash` contiene exclusivamente SHA-256; `token_exceso_expira` y `token_exceso_usado_at` controlan las 48 horas de vigencia y el uso único.

Los campos de token anteriores permanecen sólo por compatibilidad. Las solicitudes creadas por el flujo unificado usan `solicitudes_aprobacion.token_hash`, `token_expira_at`, `token_usado_at` y `token_version`.

#### Tabla: `solicitudes_aprobacion`

Entidad transaccional común para `GIRA`, `EXCEPCION_MENSUAL` y `APROBACION_RENDICION`. Una fila apunta exactamente a `presupuesto_id` (`GIRA`) o `rendicion_id` (`EXCEPCION_MENSUAL`, `APROBACION_RENDICION`), con restricción `chk_solicitud_objetivo`. Cada nueva autorización incrementa `solicitud_version`; cada reenvío invalida el enlace anterior incrementando `token_version` y rotando el hash SHA-256.

| Grupo | Campos y regla |
|-------|----------------|
| Objetivo | `tipo_solicitud` (`GIRA`, `EXCEPCION_MENSUAL`, `APROBACION_RENDICION`), `presupuesto_id`, `rendicion_id`, con `CHECK` de exclusividad. |
| Responsable | `aprobador_id` y snapshots inmutables de nombre, cargo y correo. Sólo un responsable resuelve cada versión. |
| Montos | `monto_base_aprobable` y `monto_solicitado`; una excepción decide exclusivamente sobre el exceso; una aprobación general decide sobre el total validado. |
| Token | 32 bytes aleatorios entregados una vez; BD guarda sólo `token_hash`, vigencia, uso y versión. |
| Estados | `PENDIENTE_ENVIO`, `PENDIENTE_DECISION`, `ENVIO_FALLIDO`, `VENCIDA`, `APROBADA`, `RECHAZADA`, `CANCELADA`. |
| Decisión | `decision`: `APROBADA`, `RECHAZADA`, `APROBADA_TOPE`. |
| Auditoría | Solicitante, decisión, comentarios, correo, cancelación y timestamps; `activo = 0` sólo para cancelación lógica. |

Una decisión de gira habilita o rechaza el fondo, pero no aprueba comprobantes. Aprobar una excepción mensual amplía `monto_maximo_aprobable` sólo por el importe solicitado; rechazarla conserva el tope y la rendición continúa en revisión. Una aprobación general de rendición (`APROBACION_RENDICION`) aprueba formalmente la liquidación por el responsable.

#### Tabla: `solicitud_aprobacion_historial`

Bitácora append-only del ciclo de la solicitud: creación, resultado de correo, rotación de token/responsable, vencimiento, cancelación y decisión. Guarda actor, transición, comentario, metadatos, IP y user-agent. No admite eliminación física.

#### Tabla: `rendicion_documentos`

Un documento con `rendicion_id = NULL` y `estado_item = 'BORRADOR'` pertenece a la bolsa del vendedor. Quitar un documento cambia su estado a `DESCARTADO`, fija `activo = 0` y conserva el registro y la fotografía.

Campos de auditoría y corrección administrativa:
- `numero_documento_original VARCHAR(50) NULL`: Almacena el número de folio digitado originalmente por el vendedor. Se asigna con `COALESCE(numero_documento_original, numero_documento)` en la primera corrección de folio por Tesorería y permanece inalterado en correcciones sucesivas o de monto.
- `monto_original DECIMAL(12,2) NULL`: Almacena el monto digitado originalmente si Tesorería ajusta el valor en revisión.
- `editado_por INT NULL`: ID del usuario administrativo que realizó la última corrección (FK a `usuarios(id)`).
- `editado_at DATETIME NULL`: Timestamp de la última edición administrativa.
- `motivo_edicion VARCHAR(255) NULL`: Razón justificada de la corrección documental.

La columna generada STORED nullable `document_hash_bloqueante` aplica la regla definitiva contra duplicados:
```sql
document_hash_bloqueante CHAR(64) GENERATED ALWAYS AS (
    CASE
        WHEN activo = 1 AND estado_item IN ('BORRADOR', 'PENDIENTE', 'APROBADO')
        THEN document_hash
        ELSE NULL
    END
) STORED
```
El índice único `uq_rendicion_document_hash_bloqueante (document_hash_bloqueante)` bloquea el comprobante si está en `BORRADOR` (bolsa activa), `PENDIENTE` (rendición enviada en revisión) o `APROBADO` (rendición aprobada/pagada). Si el ítem está en `RECHAZADO` o `DESCARTADO`, el valor generado es `NULL` y no bloquea, permitiendo que el vendedor vuelva a presentar la boleta en una nueva rendición con un registro independiente. La protección opera cross-vendedor y cross-empresa en todo el holding.

`document_hash` calcula:

```text
Documento normal: SHA256(RUT_PROVEEDOR|TIPO_DOCUMENTO|NUMERO_DOCUMENTO)
Peaje:           SHA256(PEAJE|FECHA|MONTO|VENDEDOR_ID|EMPRESA_ID)
```

Para `CENA_CLIENTE` son obligatorios nombre, RUT, empresa, cargo y propósito comercial. Para `PEAJES` sólo son obligatorios fecha, monto y fotografía.

#### Tabla: `rendicion_historial_estados`

Bitácora append-only de cabeceras e ítems. Registra actor, acción, estados, comentario, IP, user-agent y metadatos. Todas sus claves foráneas usan `ON DELETE RESTRICT`.
- Al ejecutar `VALIDAR_DOCUMENTOS`, se registra tanto un evento general de resumen como un evento granular `VALIDAR_DOCUMENTO` por cada comprobante procesado, vinculando `documento_id`, estados anterior y nuevo, montos anterior y nuevo, decisión, motivo y folio (actual y original).

#### Índices críticos

```sql
UNIQUE KEY uq_presupuesto_periodo_clave (periodo_clave);
UNIQUE KEY uq_rendicion_codigo (codigo_rendicion);
UNIQUE KEY uq_rendicion_token_hash (token_aprobacion_exceso_hash);
UNIQUE KEY uq_rendicion_document_hash_bloqueante (document_hash_bloqueante);
UNIQUE KEY uq_solicitud_token_hash (token_hash);
UNIQUE KEY uq_solicitud_presupuesto_version (tipo_solicitud, presupuesto_id, solicitud_version);
UNIQUE KEY uq_solicitud_rendicion_version (tipo_solicitud, rendicion_id, solicitud_version);
KEY idx_rendicion_verificado_por (verificado_tesoreria_por);
KEY idx_documento_editado_por (editado_por);
```

> **Entorno actual:** la migración aditiva `config/migrations/2026_08_28_topes_y_flujo_aprobaciones.sql` está aplicada en Laragon. No modifica datos ERP ni elimina registros; en servidores debe importarse manualmente desde phpMyAdmin antes de desplegar el código de las Fases A–H.

---

---

## 2. Bases de Datos ERP (Solo Lectura)

Cuatro bases de datos independientes con estructuras correspondientes a las empresas del holding. Las 4 bases son estrictamente **SOLO LECTURA** (prohibido INSERT, UPDATE, DELETE, ALTER, CREATE, DROP).

### 2.1 Fuentes de Identidad de Vendedores por Empresa

A partir de la migración de identidad, la fuente oficial de vendedores se desacopla mediante adaptadores en `ErpSellerDirectoryService`:

| Empresa | Base de Datos ERP | Tabla Fuente | Campo ID Vendedor | Estado / Adaptador |
|---------|-------------------|--------------|-------------------|--------------------|
| Automarco LTDA | `automarc_automarco` | `web_usuarios` | `vend_cod` | `WebUsuariosSellerRepository` (Oficial) |
| Autotec S.A | `autotec_ecom` | `web_usuarios` | `vend_cod` | `WebUsuariosSellerRepository` (Oficial) |
| Gabtec S.A | `gabteccl_sitbdd1978` | `web_usuarios` | `vend_cod` | `WebUsuariosSellerRepository` (Oficial) |
| HD Automarco S.A | `autohd_automarcohd` | `tbl_vendedores` | `cli_vendedor` | `LegacySellerRepository` (Fallback legacy temporal) |

#### Estructura de `web_usuarios`:
```sql
CREATE TABLE web_usuarios (
  id           INT NOT NULL,
  usuario      VARCHAR(100) NOT NULL,
  password     VARCHAR(255) NOT NULL, -- NUNCA LEÍDO NI EXPUESTO
  nombre       VARCHAR(200) DEFAULT NULL,
  email        VARCHAR(200) DEFAULT NULL,
  rol          ENUM('admin','vendedor','cliente') DEFAULT 'cliente',
  activo       TINYINT(1) DEFAULT 1,
  cli_rut      VARCHAR(20) DEFAULT NULL,
  cli_sec      VARCHAR(10) DEFAULT NULL,
  creado_en    DATETIME DEFAULT CURRENT_TIMESTAMP,
  ultimo_login DATETIME DEFAULT NULL,
  vend_cod     VARCHAR(20) DEFAULT NULL COMMENT 'Código de vendedor (numérico)'
);
```

#### Reglas de Selección para `web_usuarios`:
1. **Filtro Estricto:** `rol = 'vendedor'` AND `activo = 1` AND `vend_cod IS NOT NULL`.
2. **Validación Numérica:** `vend_cod` debe ser numérico entero estrictamente positivo (`> 0`).
3. **Privacidad:** `password` y credenciales nunca se consultan ni retornan.
4. **Tolerancia de Email NULL:** Vendedores sin correo (ej. 100% de Autotec) se procesan con `vendedor_email = null` sin ser descartados.
5. **Ambigüedad:** Si existen múltiples usuarios activos con el mismo `vend_cod` en una empresa, la resolución se rechaza arrojando `DomainException`.
6. **Identidad Operativa:** La clave primaria funcional es `(empresa_id, vend_cod)`.

### 2.2 Tabla: `tbl_clientes` (Catálogo de Clientes por Empresa)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `cli_id` | `int unsigned` | PK Autoincremental |
| `cli_rut` | `varchar(15)` | RUT formateado del cliente (ej: `76516950-K` o `76.516.950-K`) |
| `cli_razon_social` | `varchar(200)` | Razón social / Nombre del cliente |
| `cli_mail` | `varchar(100)` | Email registrado del cliente |
| `cli_direccion` | `varchar(200)` | Dirección comercial |
| `cli_vendedor` | `smallint` | Código del vendedor asociado a este cliente |

### 2.3 Tabla Legacy: `tbl_vendedores` (Catálogo de Vendedores por Empresa)

Utilizada exclusivamente por Automarco HD (`autohd_automarcohd`) como integración legacy temporal.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `cli_vendedor` | `bigint` | PK / Código único local de la empresa |
| `nombre_vendedor` | `varchar(255)` | Nombre real del vendedor |
| `ven_mail` | `varchar(255)` | Correo electrónico del vendedor (puede ser NULL o vacío) |

> ⚠️ **Manejo de Colisión de IDs de Vendedor Multi-Empresa:**  
> Como cada ERP tiene sus propios códigos en `web_usuarios` o `tbl_vendedores`, un mismo vendedor puede tener IDs numéricos distintos según la empresa.  
> **Estrategia de Homologación:** El correo electrónico (`email` / `ven_mail`) ayuda a sugerir asociaciones holding cuando está disponible, pero la identidad operativa primaria es siempre el par `(empresa_id, vend_cod)`. Los vendedores sin correo (ej. Autotec) se manejan con identidad local independiente.

### 2.3 Tabla: `tbl_ventas_devoluciones` (Historial de Transacciones por Empresa)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `factura` | `varchar(45)` | Folio/Número de factura |
| `cliente_rut` | `varchar(15)` | RUT del cliente asociado |
| `neto_item` | `decimal` | Monto neto del item |
| `fecha_documento` | `date` | Fecha de emisión |

### 2.4 Tabla Consolidada: `bd_automarco.tbl_cobranza` (Tabla Maestra de Documentos Impagos)

Esta tabla centraliza todos los documentos pendientes de pago de los vendedores del holding en todas las razones sociales.

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `empresa` | `varchar(45)` | Código de empresa: `EMP01` (Automarco LTDA), `EMP10` (Gabtec S.A), `EMP03` (Autotec S.A), `EMP06` (HD Automarco S.A), `EMP07` (ITC - Omitida) |
| `vendedor` | `bigint unsigned` | ID del Vendedor (`vendedor_id`) asignado al documento (Cruza con `tbl_vendedores.cli_vendedor`) |
| `clirut` | `bigint unsigned` | RUT del cliente (solo número sin dígito verificador) |
| `clidv` | `varchar(45)` | Dígito verificador del cliente |
| `clisec` | `varchar(45)` | Secuencia / Sucursal del cliente (Omitida / No utilizada en este proyecto) |
| `docto` | `varchar(45)` | Número de documento / Factura impaga |
| `vencto` | `varchar(45)` | Fecha de vencimiento del documento (`DD-MM-YYYY`) |
| `emision` | `varchar(45)` | Fecha de emisión del documento (`DD-MM-YYYY`) |
| `glosa` | `varchar(255)` | Descripción o tipo de movimiento comercial |
| `total_cuota` | `varchar(45)` | Monto total original de la cuota / factura |
| `saldo_cuota` | `varchar(45)` | Saldo pendiente por cobrar |
| `tipo_doc` | `decimal(10,0)` | Tipo de documento contable |
| `tipo_cliente` | `varchar(255)` | Categorización ABC de riesgo del cliente (No utilizado para efectos de cobranza) |

#### Mapeo de Códigos de Empresa:
* `EMP01` ➔ **Automarco LTDA** (`automarc_automarco`)
* `EMP10` ➔ **Gabtec S.A** (`gabteccl_sitbdd1978`)
* `EMP03` ➔ **Autotec S.A** (`autotec_ecom`)
* `EMP06` ➔ **HD Automarco S.A** (`autohd_automarcohd`)
* `EMP07` ➔ *ITC (Omitida en la lógica operativa)*

---

## 3. Query Templates Cross-DB

### 3.1 Búsqueda de factura + cliente

```sql
-- {nombre_bd} es validado contra ALLOWED_DATABASES antes de interpolarse
SELECT
    v.factura,
    v.cliente_rut,
    c.cli_razon_social,
    c.cli_mail,
    ROUND(SUM(v.neto_item * 1.19)) AS monto_total_factura
FROM {nombre_bd}.tbl_ventas_devoluciones v
LEFT JOIN {nombre_bd}.tbl_clientes c
    ON REPLACE(v.cliente_rut, '-', '') = REPLACE(c.cli_rut, '-', '')
WHERE v.factura = :numero_factura
GROUP BY
    v.factura,
    v.cliente_rut,
    c.cli_razon_social,
    c.cli_mail;
```

> **Nota Técnica sobre el JOIN (Formato de RUTs):**
> En los ERPs existe una discrepancia en cómo se almacena el RUT. En `tbl_ventas_devoluciones`, el RUT se guarda sin el guion (ej: `52752361`), mientras que en `tbl_clientes` se guarda con el guion antes del dígito verificador (ej: `5275236-1`). 
>
> En la tabla consolidada `bd_automarco.tbl_cobranza`, el RUT se separa en dos columnas físicas:
> * `clirut` (ej: `76516950` - tipo `BIGINT`)
> * `clidv` (ej: `K` - tipo `VARCHAR`)
>
> Para realizar la traducción e identificar al cliente en el catálogo ERP (`tbl_clientes`), se debe limpiar el RUT del ERP y compararlo usando la concatenación:
> ```sql
> ON REPLACE(REPLACE(cli.cli_rut, '.', ''), '-', '') = CONCAT(c.clirut, c.clidv)
> ```
>
> En la tabla consolidada `bd_automarco.tbl_cobranza`, el RUT se separa en dos columnas físicas:
> * `clirut` (ej: `76516950` - tipo `BIGINT`)
> * `clidv` (ej: `K` - tipo `VARCHAR`)
>
> Para realizar la traducción e identificar al cliente en el catálogo ERP (`tbl_clientes`), se debe limpiar el RUT del ERP y compararlo usando la concatenación:
> ```sql
> ON REPLACE(REPLACE(cli.cli_rut, '.', ''), '-', '') = CONCAT(c.clirut, c.clidv)
> ```
> Para poder cruzarlos correctamente, **SIEMPRE** se debe usar `LEFT JOIN` con la función `REPLACE()` quitando los guiones a ambos lados de la igualdad. Se usa `LEFT JOIN` para que, en caso de que el cliente haya sido borrado, la factura igual retorne el monto total y no bloquee el sistema de cobranzas.

### 3.2 Historial de cobranzas del vendedor (con cheques anidados)

```sql
SELECT
    c.*,
    e.nombre AS empresa_nombre,
    e.email_tesoreria_defecto
FROM cobranzas c
INNER JOIN empresas e ON c.empresa_id = e.id
WHERE c.vendedor_id = :vendedor_id
ORDER BY c.created_at DESC;

-- Luego por cada cobranza:
SELECT * FROM cheques WHERE cobranza_id = :cobranza_id;
```

---

## 4. Índices Recomendados

```sql
-- Para búsquedas frecuentes en la vista de seguimiento
CREATE INDEX idx_cobranzas_vendedor ON cobranzas(vendedor_id);
CREATE INDEX idx_cobranzas_estado   ON cobranzas(estado);
CREATE INDEX idx_cobranzas_empresa  ON cobranzas(empresa_id);
CREATE INDEX idx_cheques_cobranza   ON cheques(cobranza_id);
CREATE INDEX idx_historial_cobranza ON historial_estados(cobranza_id);

-- Para el cron de alertas (Fase 4)
CREATE INDEX idx_cobranzas_created  ON cobranzas(created_at, estado);

---

## 5. Prevención de Schema Drift e Integridad de Despliegue

### 5.1 Tablas de Seguridad y Auditoría Incorporadas en `setup.sql`

| Tabla | Propósito | Campos Clave |
|-------|-----------|--------------|
| `login_attempts` | Control de Fuerza Bruta / Rate-Limiting | `ip_address`, `email`, `attempted_at` |
| `audit_logs` | Auditoría de Acciones Críticas | `usuario_id`, `email`, `accion`, `detalles`, `ip_address`, `user_agent` |

### 5.2 Protocolo de Verificación de Integridad de Esquema

Para evitar errores en Producción por tablas o columnas faltantes tras la adición de funcionalidades, existe un script de verificación automatizado en el proyecto:

```bash
php scratch/verify_schema_integrity.php
```

**Comprobaciones del Verificador:**
1. Escanea todos los queries PDO del código PHP en búsqueda de nombres de tabla (`FROM`, `INTO`, `UPDATE`, `JOIN`).
2. Valida que cada tabla usada exista en la base de datos activa MySQL (`SHOW TABLES`).
3. Valida que la estructura completa de tablas esté formalmente documentada y presente en `config/setup.sql`.

> ⚠️ **Regla de Producción:** Antes de cualquier despliegue o reinicio de esquema, ejecutar `php scratch/verify_schema_integrity.php` para asegurar 100% de coherencia entre el código backend y las tablas creadas.

### 5.3 Inmutabilidad de Cheques Dados de Baja (Zero Delete)

Los cheques marcados con `activo = 0` representan bajas lógicas irrevocables para el flujo ordinario:
1. **Regla de Consulta y Actualización:** Toda selección o actualización operativa de cheques debe exigir `AND activo = 1` o `AND (activo = 1 OR activo IS NULL)`.
2. **Prohibiciones para Cheques Inactivos:**
   - No pueden cambiar de monto, fecha de vencimiento ni fotografía.
   - No reciben número de papeleta ni fecha de depósito al depositar una cobranza.
   - No se envían a planillas ni integraciones externas.
   - No se suman en conteos ni totales activos de cobranzas.
   - Su fotografía permanece almacenada y referenciada para fines de auditoría legal y financiera hasta su purga cronológica autorizada (>3 meses post-vencimiento).
3. **Resolución de Identidad de Actor (`descartado_por`):**
   - El campo `descartado_por` mantiene una clave foránea hacia `usuarios(id)` (`ON DELETE RESTRICT`).
   - Si la baja es solicitada por un vendedor cuyo código ERP no existe en la tabla central `usuarios`, el sistema asigna el usuario `1` (Sistema) como titular de la FK y añade la identidad ERP original al campo `motivo_descarte` y al historial, preservando la integridad referencial sin crear usuarios ficticios ni debilitar la restricción.

### 5.4 Estado de Migraciones y Protocolo de Producción

- **Las migraciones pendientes todavía no deben ejecutarse en el servidor.**
- El orden definitivo y la ventana de ejecución en producción se revisarán en una fase posterior una vez validado y aprobado el release.
- Todas las migraciones en `config/migrations/` han sido certificadas como **100% idempotentes** y cuentan con:
  - Selección explícita de base de datos (`USE \`bd_modulo_cobranzas\`;`).
  - Guardas condicionales basadas en `information_schema` que impiden duplicación de columnas, índices, llaves foráneas o restricciones CHECK.
  - Certificación de doble ejecución consecutiva sin errores en bases de datos temporales controladas.

