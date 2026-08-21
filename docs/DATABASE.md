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
```

### 1.2 ENUMs globales

```sql
-- Rol de usuario
ENUM('VENDEDOR', 'TESORERIA', 'ADMINISTRADOR')

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
  google_sheet_id         VARCHAR(150) NULL,
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
| `google_sheet_id` | VARCHAR(150) NULL | ID del documento de Google Sheets para Tesorería |
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
  rol                      ENUM('VENDEDOR','TESORERIA','ADMINISTRADOR') DEFAULT 'TESORERIA',
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
  created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

| Campo | Quién lo escribe | Cuándo |
|-------|-----------------|--------|
| `monto`, `fecha_vencimiento`, `foto_cheque_url`, `comentario` | Vendedor | Al registrar la cobranza |
| `foto_purgada_at` | Cron Purga | Al cumplir >3 meses post-vencimiento |
| `banco`, `numero_cheque`, `cuenta_corriente`, `emitido_a` | Tesorería | Al validar (RECIBIDO_TESORERIA) |
| `numero_papeleta_deposito` | Tesorería | Al marcar como `DEPOSITADO` |
| `fecha_deposito_real` | Tesorería | Al marcar como `DEPOSITADO` |

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

> **Entorno actual:** como no hay datos reales, al alinear el esquema con el flujo dividido se recreará la base local desde `config/setup.sql`; no se requiere migración de preservación de datos.

---

---

## 2. Bases de Datos ERP (Solo Lectura)

Cuatro bases de datos independientes con estructuras idénticas correspondientes a las empresas del holding.

### 2.1 Tabla: `tbl_clientes` (Catálogo de Clientes por Empresa)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `cli_id` | `int unsigned` | PK Autoincremental |
| `cli_rut` | `varchar(15)` | RUT formateado del cliente (ej: `76516950-K` o `76.516.950-K`) |
| `cli_razon_social` | `varchar(200)` | Razón social / Nombre del cliente |
| `cli_mail` | `varchar(100)` | Email registrado del cliente |
| `cli_direccion` | `varchar(200)` | Dirección comercial |
| `cli_vendedor` | `smallint` | Código del vendedor asociado a este cliente |

### 2.2 Tabla: `tbl_vendedores` (Catálogo de Vendedores por Empresa)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `cli_vendedor` | `bigint` | PK / Código único local de la empresa |
| `nombre_vendedor` | `varchar(255)` | Nombre real del vendedor |
| `ven_mail` | `varchar(255)` | Correo electrónico único del vendedor (Clave de Homologación) |

> ⚠️ **Manejo de Colisión de IDs de Vendedor Multi-Empresa:**  
> Como cada ERP tiene sus propios autoincrementales en `tbl_vendedores`, un mismo vendedor puede tener IDs numéricos distintos según la empresa (ej: *Angel Fereira* es ID `25` en Automarco LTDA y es ID `1` en Gabtec S.A).  
> **Estrategia de Homologación:** El backend utiliza el correo electrónico del vendedor (`ven_mail`) como identificador universal. Al consultar los clientes asignados en `api/get_clientes.php`, el sistema busca el correo de la persona y unifica dinámicamente todos los folios asociados a sus distintos IDs de vendedor en las 4 empresas.

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
```
