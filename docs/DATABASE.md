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
ENUM('INGRESADO', 'EN_TRANSITO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO')
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
  tipo_entrega         ENUM('CHILEXPRESS','PRESENCIAL_SANTIAGO') NOT NULL,
  numero_seguimiento   VARCHAR(100),                    -- OT Chilexpress (si aplica)
  comprobante_url      VARCHAR(255),                    -- ruta relativa a uploads/
  estado               ENUM('INGRESADO','EN_TRANSITO','RECIBIDO_TESORERIA',
                            'DEPOSITADO','RECHAZADO') DEFAULT 'INGRESADO',
  created_at           TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id)  REFERENCES empresas(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;
```

| Campo | Descripción |
|-------|-------------|
| `comprobante_url` | Ruta relativa: `uploads/{empresa_id}/{YYYY-MM}/comprobantes/archivo.jpg` |
| `numero_seguimiento` | Aplica solo cuando `tipo_entrega = 'CHILEXPRESS'` |
| `estado` | Controlado exclusivamente por Tesorería (Portal Fase 2). El vendedor no puede cambiarlo. |

**Estado inicial por tipo de entrega:**

| `tipo_entrega` | `estado` inicial |
|---|---|
| `CHILEXPRESS` | `EN_TRANSITO` |
| `PRESENCIAL_SANTIAGO` | `INGRESADO` |

---

### 1.6 Tabla: `cheques`

Detalle de cada cheque dentro de una cobranza. Relación 1:N con `cobranzas`.

```sql
CREATE TABLE cheques (
  id                       INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id              INT NOT NULL,
  banco                    VARCHAR(100) NOT NULL,
  numero_cheque            VARCHAR(50) NOT NULL,
  monto                    DECIMAL(12,0) NOT NULL,
  fecha_vencimiento        DATE NOT NULL,
  foto_cheque_url          VARCHAR(255) NOT NULL,       -- ruta relativa a uploads/
  comentario               TEXT NULL,                   -- observación opcional del vendedor
  numero_papeleta_deposito VARCHAR(50) NULL,            -- registrado por Tesorería
  fecha_deposito_real      TIMESTAMP NULL,              -- registrado por Tesorería
  created_at               TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

| Campo | Quién lo escribe | Cuándo |
|-------|-----------------|--------|
| `banco` … `comentario` | Vendedor | Al registrar la cobranza |
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
  estado_anterior ENUM('INGRESADO','EN_TRANSITO','RECIBIDO_TESORERIA',
                       'DEPOSITADO','RECHAZADO') NULL,  -- NULL en el primer registro
  estado_nuevo   ENUM('INGRESADO','EN_TRANSITO','RECIBIDO_TESORERIA',
                      'DEPOSITADO','RECHAZADO') NOT NULL,
  comentario     TEXT NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id),
  FOREIGN KEY (usuario_id)  REFERENCES usuarios(id)
) ENGINE=InnoDB;
```

> `estado_anterior = NULL` indica el registro inicial (creación de la cobranza).

---

## 2. Bases de Datos ERP (Solo Lectura)

Cuatro BDs independientes con estructura idéntica de tablas. El módulo solo las lee.

### 2.1 Tabla: `tbl_clientes`

| Campo | Descripción |
|-------|-------------|
| `cli_rut` | RUT del cliente (PK natural) |
| `cli_razon_social` | Nombre/razón social |
| `cli_mail` | Email del cliente |
| `cli_direccion` | Dirección comercial |
| `cli_vendedor` | Vendedor asignado |

### 2.2 Tabla: `tbl_ventas_devoluciones`

| Campo | Descripción |
|-------|-------------|
| `factura` | Número de factura |
| `cliente_rut` | FK a `tbl_clientes.cli_rut` |
| `neto_item` | Valor neto del ítem (sin IVA) |
| `fecha_documento` | Fecha de emisión |

> El monto con IVA se calcula como `ROUND(SUM(neto_item * 1.19))` agrupado por factura.

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
```
