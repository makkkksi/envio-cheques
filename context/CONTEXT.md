# Especificación Técnica, Arquitectura de Datos y Contexto del Proyecto - Módulo de Cobranzas

**Proyecto:** Módulo Digital de Cobranza y Trazabilidad de Cheques  
**Holding:** Automarco / Autotec / Gabtec / HD Automarco  
**Solicitante:** Miguel Martínez / S. Valenzuela (`svalenzuela@automarco.com`)  
**Fecha:** Julio 2026  
**Versión:** 1.2 (Contexto Completo de Desarrollo Local/Producción)

---

## 1. Contexto del Proyecto y Diagnóstico

### 1.1. Diagnóstico Operativo
Actualmente, el proceso de cobranza física de cheques por parte de la fuerza de ventas presenta brechas de control:
* **Falta de Trazabilidad Centralizada:** No existe un registro unificado desde que el vendedor en terreno (Santiago o Regiones) recibe un cheque físico hasta que este llega a Tesorería para su archivo en el acordeón contable o su posterior depósito.
* **Proceso Informal ("Parche"):** La gestión se apoya en fotografías enviadas por WhatsApp personal a Tesorería junto con fotos del boleto de Chilexpress (si aplica).
* **Riesgo de Extravío:** No existen alertas sobre cheques en tránsito que superen un tiempo prudente de entrega.

### 1.2. Objetivo del Módulo
Desarrollar una aplicación web *responsive* (optimizada para tablets y dispositivos móviles) que digitalice, valide con el ERP, notifique por correo y audite el ciclo de vida completo de cada cheque cobrado.

---

## 2. Topología de Bases de Datos (Entorno Local y Producción)

El sistema opera bajo un esquema **Multi-Tenant / Multi-Base de Datos**. Existen **4 bases de datos pertenecientes a los ERPs** (de solo lectura para esta app) y **1 base de datos central** propia del módulo (lectura/escritura).

### 2.1. Bases de Datos de Lectura ERP (Holding)

Todas las bases de datos de los ERPs comparten exactamente la misma estructura interna de tablas:

| Empresa (UI Formulario) | Nombre Exacto de BD | Tabla Clientes | Tabla Facturas/Ventas |
| :--- | :--- | :--- | :--- |
| **Automarco LTDA** | `automarc_automarco` | `tbl_clientes` | `tbl_ventas_devoluciones` |
| **HD Automarco S.A** | `autohd_automarcohd` | `tbl_clientes` | `tbl_ventas_devoluciones` |
| **Autotec S.A** | `autotec_ecom` | `tbl_clientes` | `tbl_ventas_devoluciones` |
| **Gabtec S.A** | `gabteccl_sitbdd1978` | `tbl_clientes` | `tbl_ventas_devoluciones` |

#### Esquema de Tablas ERP:
* **`tbl_clientes`:** Contiene la información referencial del cliente (`cli_rut`, `cli_razon_social`, `cli_mail`, `cli_direccion`, `cli_vendedor`).
* **`tbl_ventas_devoluciones`:** Contiene los ítems y facturas emitidas (`factura`, `cliente_rut`, `neto_item`, `fecha_documento`).

---

### 2.2. Base de Datos Central del Módulo (`bd_modulo_cobranzas`)

Base de datos relacional creada en el entorno local (MySQL/MariaDB) que centraliza la operación, trazabilidad y archivos multimedia.

#### DDL / Script SQL de Creación:

```sql
CREATE DATABASE IF NOT EXISTS bd_modulo_cobranzas CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bd_modulo_cobranzas;

-- 1. Mapeo de Bases de Datos de las Empresas del Holding
CREATE TABLE empresas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  nombre_bd VARCHAR(100) NOT NULL UNIQUE, -- Nombre real de la BD en MySQL
  email_tesoreria_defecto VARCHAR(150) NOT NULL,
  dias_maximos_envio INT DEFAULT 3,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 2. Tabla de Usuarios del Sistema
CREATE TABLE usuarios (
  id INT AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255),
  rol ENUM('VENDEDOR', 'TESORERIA', 'ADMINISTRADOR') DEFAULT 'TESORERIA',
  dias_alerta_personalizado INT NULL,
  activo BOOLEAN DEFAULT TRUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- 3. Cabecera del Registro de Cobranza
CREATE TABLE cobranzas (
  id INT AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT NOT NULL,
  vendedor_id INT NULL,
  vendedor_nombre VARCHAR(100),
  numero_factura VARCHAR(50) NOT NULL,
  rut_cliente VARCHAR(20) NOT NULL,
  razon_social_cliente VARCHAR(200),
  monto_total_factura DECIMAL(12,0),
  email_cliente VARCHAR(150),
  email_tesoreria VARCHAR(150),
  tipo_entrega ENUM('CHILEXPRESS', 'PRESENCIAL_SANTIAGO') NOT NULL,
  numero_seguimiento VARCHAR(100),
  comprobante_url VARCHAR(255),
  estado ENUM('INGRESADO', 'EN_TRANSITO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO') DEFAULT 'INGRESADO',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (empresa_id) REFERENCES empresas(id),
  FOREIGN KEY (vendedor_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- 4. Detalle de Cheques (Soporta múltiples cheques por cobranza)
CREATE TABLE cheques (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id INT NOT NULL,
  banco VARCHAR(100) NOT NULL,
  numero_cheque VARCHAR(50) NOT NULL,
  monto DECIMAL(12,0) NOT NULL,
  fecha_vencimiento DATE NOT NULL,
  foto_cheque_url VARCHAR(255) NOT NULL,
  comentario TEXT NULL, -- Observación individual por cheque
  numero_papeleta_deposito VARCHAR(50) NULL,
  fecha_deposito_real TIMESTAMP NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 5. Bitácora e Historial de Auditoría (Trazabilidad Inmutable)
CREATE TABLE historial_estados (
  id INT AUTO_INCREMENT PRIMARY KEY,
  cobranza_id INT NOT NULL,
  usuario_id INT NOT NULL,
  estado_anterior ENUM('INGRESADO', 'EN_TRANSITO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO') NULL,
  estado_nuevo ENUM('INGRESADO', 'EN_TRANSITO', 'RECIBIDO_TESORERIA', 'DEPOSITADO', 'RECHAZADO') NOT NULL,
  comentario TEXT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (cobranza_id) REFERENCES cobranzas(id),
  FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
) ENGINE=InnoDB;

-- Datos Semilla (Seeders)
INSERT INTO empresas (nombre, nombre_bd, email_tesoreria_defecto) VALUES
('Automarco LTDA', 'automarc_automarco', 'tesoreria@automarco.cl'),
('HD Automarco S.A', 'autohd_automarcohd', 'tesoreria@hdautomarco.cl'),
('Autotec S.A', 'autotec_ecom', 'tesoreria@autotec.cl'),
('Gabtec S.A', 'gabteccl_sitbdd1978', 'tesoreria@gabtec.cl');


3. Lógica de Consultas Dinámicas entre Bases de Datos (Cross-DB)
Debido a que cada empresa reside en su propia BD, el backend ejecuta consultas dinámicas utilizando el nombre de la BD almacenado en empresas.nombre_bd.

Consulta de Búsqueda de Factura e Información de Cliente (Template SQL):
SQL
-- Ejemplo cuando el usuario selecciona "Autotec S.A" (nombre_bd = 'autotec_ecom')
SELECT 
    v.factura,
    v.cliente_rut,
    c.cli_razon_social,
    c.cli_mail,
    ROUND(SUM(v.neto_item * 1.19)) AS monto_total_factura
FROM autotec_ecom.tbl_ventas_devoluciones v
INNER JOIN autotec_ecom.tbl_clientes c 
    ON v.cliente_rut = c.cli_rut
WHERE v.factura = :numero_factura
GROUP BY v.factura, v.cliente_rut, c.cli_razon_social, c.cli_mail;


4. Flujos del Sistema y Reglas de NegocioFlujo 1: Captura y Registro de Cobranza (Vendedor / Tablet)Selección de Empresa: El vendedor elige la empresa del grupo empresarial en la interfaz (empresa_id).Ingreso de Factura: Digita el número de factura. El backend ejecuta la consulta dinámicamente sobre la BD correspondiente de la empresa y devuelve cliente y monto con IVA.Carga de Cheques: El vendedor agrega 1 o N cheques ingresando: Banco, N° Cheque, Monto, Fecha de Vencimiento, Foto (vía cámara) y opcionalmente un Comentario/Observación.Logística de Entrega:Regiones (Chilexpress): Ingresa N° de Seguimiento/OT y adjunta foto de la orden de flete. Permite asociar múltiples cobranzas al mismo N° de OT.Santiago (Presencial): Captura foto del comprobante firmado de recepción en oficinas.Guardado: Se inserta en cobranzas, cheques e historial_estados.Flujo 2: Sistema de Notificaciones AutomáticasAl procesar el guardado:Email al Cliente: Envía un correo con el resumen de la cobranza, facturas asociadas y las fotos de los cheques recibidos como respaldo.Email a Tesorería: Envía una alerta a la tesorera de la empresa correspondiente (email_tesoreria_defecto) notificando que hay documentos en camino.Flujo 3: Gestión y Conciliación (Tesorería)Tesorería visualiza todas las cobranzas del holding o filtra por empresa específica.Al recibir el sobre físico, cambia el estado a RECIBIDO_TESORERIA y archiva en el acordeón físico según fecha de vencimiento.En la fecha de depósito, registra el N° de papeleta/comprobante bancario y marca como DEPOSITADO (o RECHAZADO indicando motivo si el cheque fue rebotado).Flujo 4: Motor de Alertas por Días TranscurridosUn proceso automatizado (Cron Job) evalúa periódicamente las cobranzas en tránsito:$$\text{Días Transcurridos} = \text{Fecha Actual} - \text{Fecha de Creación}$$Si $\text{Días Transcurridos} > \text{dias\_maximos\_envio}$ y el estado es diferente de RECIBIDO_TESORERIA, emite un correo de alerta urgente al vendedor y a la jefatura.5. Esquema Visor DBML (dbdiagram.io)Fragmento de códigoEnum rol_enum { VENDEDOR; TESORERIA; ADMINISTRADOR }
Enum tipo_entrega_enum { CHILEXPRESS; PRESENCIAL_SANTIAGO }
Enum estado_cobranza_enum { INGRESADO; EN_TRANSITO; RECIBIDO_TESORERIA; DEPOSITADO; RECHAZADO }

Table empresas {
  id int [pk, increment]
  nombre varchar
  nombre_bd varchar [unique]
  email_tesoreria_defecto varchar
  dias_maximos_envio int
}

Table usuarios {
  id int [pk, increment]
  nombre varchar
  email varchar [unique]
  rol rol_enum
  activo boolean
}

Table cobranzas {
  id int [pk, increment]
  empresa_id int [ref: > empresas.id]
  vendedor_id int [ref: > usuarios.id]
  numero_factura varchar
  rut_cliente varchar
  monto_total_factura decimal
  tipo_entrega tipo_entrega_enum
  numero_seguimiento varchar
  estado estado_cobranza_enum
}

Table cheques {
  id int [pk, increment]
  cobranza_id int [ref: > cobranzas.id]
  banco varchar
  numero_cheque varchar
  monto decimal
  fecha_vencimiento date
  foto_cheque_url varchar
  comentario text
  numero_papeleta_deposito varchar
}

Table historial_estados {
  id int [pk, increment]
  cobranza_id int [ref: > cobranzas.id]
  usuario_id int [ref: > usuarios.id]
  estado_anterior estado_cobranza_enum
  estado_nuevo estado_cobranza_enum
  comentario text
}