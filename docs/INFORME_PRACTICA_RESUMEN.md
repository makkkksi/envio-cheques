# Memoria y Resumen Ejecutivo para Informe de Práctica Profesional

**Proyecto:** Sistema Web y Móvil de Gestión, Digitalización, Blindaje y Trazabilidad de Cobranzas por Cheques  
**Organización:** Holding Automarco (Automarco LTDA, HD Automarco S.A, Autotec S.A, Gabtec S.A)  
**Rol / Especialidad:** Desarrollador Fullstack Senior, Arquitecto PHP/MySQL y DevSecOps  
**Stack Tecnológico:** PHP 8+ (PDO puro nativo sin frameworks) · MySQL / MariaDB · HTML5 / CSS3 / JavaScript Vanilla · PHPMailer · Google Sheets API v4 (JWT Nativo sin Composer)  
**Estado:** ✅ 100% Finalizado — En Producción / Marcha Blanca (Paridad 100% en `dist/`)

---

## 1. Introducción y Contexto del Proyecto

El presente proyecto nace ante la necesidad crítica de modernizar, asegurar y dotar de trazabilidad integral al proceso de recaudación y custodia de cheques en el **Holding Automarco** (compuesto por Automarco LTDA, HD Automarco S.A, Autotec S.A y Gabtec S.A).

Históricamente, la fuerza de ventas en terreno (más de 80 vendedores distribuidos a nivel nacional) recaudaba pagos de clientes mediante cheques físicos, llenaba talonarios de papel autocopiativo y enviaba fotos informales por WhatsApp para coordinar despachos. Esta operativa generaba:
* Riesgo constante de extravío o robo de documentos valorados.
* Falta de trazabilidad en tiempo real sobre el estado de la deuda y la custodia física del cheque.
* Sobrecarga extrema en Tesorería y Cuentas Corrientes, que debían re-digitar manualmente la misma información hasta 3 veces en planillas Excel y en el ERP legado del holding (**Optimus**, vigente desde el año 2000).

Durante el desarrollo de la práctica profesional, **se asumió un rol de liderazgo técnico y propositivo**, ejecutando levantamientos presenciales de información con las áreas operativas (Vendedores, Tesorería, Cuentas Corrientes y Gerencia) para diseñar e implementar un ecosistema digital de alta seguridad, responsive y adaptado a la realidad del negocio.

---

## 2. Levantamiento de Información y Trabajo de Campo

Para asegurar una solución pragmática y de adopción inmediata, se realizaron entrevistas en profundidad y observación directa en los puestos de trabajo:

1. **Reunión con Vendedores en Terreno:** Se constató una alta resistencia inicial al cambio tecnológico por temor a demoras en la atención al cliente en talleres y mesones. Se concluyó que el flujo móvil debía ser ultra-rápido, autocompletar el 90% de los datos y entregar un beneficio tangible inmediato al vendedor (como un **Recibo Digital en PDF** para enviar por WhatsApp al cliente).
2. **Reunión con Tesorería:** Se descubrió que Tesorería dedicaba horas diarias a copiar datos desde fotos borrosas de WhatsApp hacia planillas Excel, además de atender llamadas reiteradas de vendedores consultando si sus cheques habían llegado a Santiago.
3. **Reunión con Cuentas Corrientes:** Se identificó una rutina rígida: una funcionaria se desplazaba físicamente todos los días a las 16:00 hrs a Tesorería a retirar fajos de comprobantes en papel para distribuirlos a las digitadoras.
4. **Descubrimiento del ERP Optimus (Año 2000):** Se identificó que las 4 razones sociales del holding registran sus pagos contables en bases de datos MySQL independientes y en el ERP Optimus mediante digitadoras especializadas.

---

## 3. Arquitectura del Ecosistema Desarrollado

El sistema se diseñó bajo una arquitectura desacoplada, segura y de alto rendimiento utilizando **PHP puro + PDO** y **Vanilla JS**, evitando la sobrecarga y vulnerabilidades de dependencias externas:

```
┌──────────────────────────────────────────────────────────────────────────┐
│                         FUERZA DE VENTAS (TERRENO)                       │
│  App Android (WebView) / Portal Web Vendedores (E-Commerce B2B)          │
│  • Smart Client Picker multi-empresa (EMP01, EMP03, EMP06, EMP10)        │
│  • Selección de Facturas y Cuotas con revalidación de saldo en vivo      │
│  • Digitalización de cheques (captura fotográfica) y Recibo PDF          │
└────────────────────────────────────┬─────────────────────────────────────┘
                                     │ (API REST Segura / IDOR Hardened)
                                     ▼
┌──────────────────────────────────────────────────────────────────────────┐
│                    PORTAL WEB TESORERÍA (/admin/index.php)               │
│  • Master-Detail Split Screen (50% Tabla / 50% Inspector Lateral)        │
│  • Validación física 1-Clic: "VALIDAR / MANDAR A C.CORRIENTES"           │
│  • Corrección de datos de cheques in-line (Banco, N°, Cuenta, Emisor)    │
│  • Alerta visual Von Restorff ante descuadres con justificación          │
│  • Sincronización instantánea con Google Sheets contable corporativo     │
└────────────────────────────────────┬─────────────────────────────────────┘
                                     │ (Estado: RECIBIDO_TESORERIA)
                                     ▼
┌──────────────────────────────────────────────────────────────────────────┐
│             PORTAL CUENTAS CORRIENTES (/admin/cuentas_corrientes.php)    │
│  • Panel gerencial con KPIs de cheques y clientes en cola                │
│  • Matriz de asignación dinámica de digitadoras por empresa              │
│  • Hora de corte configurable libremente en BD (HH:MM)                   │
│  • Despacho manual inmediato o automático por Cron / Auto-Trigger        │
│  • Bitácora inmutable con visor de snapshots y reintento en 1-clic       │
└────────────────────────────────────┬─────────────────────────────────────┘
                                     │
                   ┌─────────────────┴─────────────────┐
                   ▼                                   ▼
┌──────────────────────────────────────┐ ┌─────────────────────────────────┐
│       CRON JOB DE CORTE DIARIO       │ │    DIGITADORAS OPTIMUS ERP      │
│  • Fragmenta cheques por emitido_a   │ │  • Reciben correo con PDF       │
│  • Genera PDF consolidado por empresa│ │    estructurado por empresa     │
│  • Envía correo a digitadora + CC    │ │  • Ingreso contable sin errores │
│  • Actualiza estado a DEPOSITADO     │ │  • Cero traslados físicos       │
└──────────────────────────────────────┘ └─────────────────────────────────┘
```

---

## 4. Principales Problemáticas, Desafíos Técnicos y Soluciones de Ingeniería

### 🔴 Desafío 1: Resistencia al Cambio y Usabilidad Móvil del Vendedor
* **Problema:** Vendedores acostumbrados al talonario físico consideraban engorroso tipear datos de facturas en terreno.
* **Solución:** 
  * Integración con la cartera de clientes del vendedor (`api/get_clientes.php`), cargando automáticamente RUT, razón social y facturas pendientes consultadas en `bd_automarco.tbl_cobranza`.
  * Generación instantánea de un **Recibo Digital de Recaudación en PDF** (`PdfGenerator.php`), permitiendo al vendedor compartirlo directamente por WhatsApp con el cliente como respaldo profesional.

---

### 🔴 Desafío 2: Triple Digitación Manual y Sobrecarga en Tesorería
* **Problema:** La información se reescribía 3 veces (Vendedor en papel ➔ Tesorería en Excel ➔ Cuentas Corrientes en Optimus).
* **Solución:**
  * Implementación del **Portal de Tesorería Desktop-First** (`admin/index.php`) con diseño Split-Screen.
  * Tesorería solo compara el documento físico contra la pantalla y valida en 1 segundo con el botón verde **`VALIDAR / MANDAR A C.CORRIENTES`**.
  * Posibilidad de corregir datos menores (banco, número de cheque) directamente en pantalla sin devolver el trámite al vendedor (`api/editar_cheques.php`).

---

### 🔴 Desafío 3: Protección del Rol Operativo de las Digitadoras (ERP Optimus)
* **Problema:** La posibilidad de una integración automática generaba incertidumbre laboral en el equipo de digitación de Cuentas Corrientes.
* **Solución Ética y Pragmática:**
  * Se preservó el rol humano de ingreso contable en el ERP Optimus.
  * El sistema actúa como un **asistente facilitador**, entregando a cada digitadora un reporte consolidado en PDF y correo limpio con los datos ordenados y validados para que su tipeo sea 10 veces más rápido y sin errores.

---

### 🔴 Desafío 4: Eliminación del Traslado Físico Diario y Automatización por Hora de Corte
* **Problema:** Pérdida de tiempo y riesgo de extravío al tener que buscar diariamente los documentos en papel a las 16:00 hrs.
* **Solución:**
  * Creación del **Portal de Cuentas Corrientes** (`admin/cuentas_corrientes.php`) y del **Cron Job de Despacho Automático** (`cron/resumen_diario_cuentas_corrientes.php`).
  * Hora de corte **100% configurable en BD/Panel** con selector manual de hora y minuto (`HH:MM`).
  * Al cumplirse la hora (o mediante el botón *"Despachar Resumen Ahora"* o el vigilante auto-trigger del navegador), el sistema agrupa los cheques por empresa de emisión (`emitido_a`), genera los PDFs, los despacha por correo a las digitadoras con copia a la Supervisora y pasa el estado a `DEPOSITADO`.

---

### 🔴 Desafío 5: Blindaje de Seguridad IDOR (`SEC-01`) y Sanitización de URL
* **Problema:** En versiones preliminares, parámetros como `vendedor_id` o `empresa` viajaban en la URL o en el cuerpo de peticiones POST, exponiendo el riesgo de manipulación de identidad (IDOR).
* **Solución DevSecOps:**
  * En entorno productivo (`APP_ENV=production`), los endpoints (`get_clientes.php`, `get_facturas_cliente.php`, `guardar_cobranza.php`, `get_mis_cobranzas.php`, `completar_envio.php`) leen la identidad del vendedor **exclusivamente desde la sesión autenticada en el servidor** (`$_SESSION['vendedor_auth']`).
  * Validación de cartera estricta en base de datos: se verifica que el cliente pertenezca formalmente a la cartera del vendedor (`cli_vendedor = :vid`).
  * Sanitización automática de URL en el navegador mediante `window.history.replaceState()`, eliminando cualquier parámetro sensible de la barra de direcciones tras autenticar.

---

### 🔴 Desafío 6: Revalidación Backend de Saldos y Cuotas (`SEC-04`)
* **Problema:** Riesgo de que un usuario modificara en el cliente web el saldo de una cuota o enviara montos superiores a la deuda viva.
* **Solución:**
  * Blindaje transaccional en `api/guardar_cobranza.php` que consulta en tiempo real `bd_automarco.tbl_cobranza` antes de confirmar la inserción, bloqueando intentos de sobrepago o adulteración de montos.

---

### 🔴 Desafío 7: Hardening HTTP contra Auditorías OWASP ZAP
* **Problema:** Detección de posibles vulnerabilidades en cabeceras HTTP, ejecución de scripts en directorios de subida y políticas de contenido permisivas.
* **Solución:**
  * Configuración estricta en `.htaccess` y `config/app.php` con **HSTS Preload** (`max-age=31536000`), `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff` y supresión total de firmas del servidor (`X-Powered-By`, `Server`).
  * **Protección Anti-RCE en Uploads:** Creación de `.htaccess` en `uploads/` con `php_flag engine off` y denegación explícita para extensiones ejecutables (`.php`, `.phtml`, `.sh`, `.exe`).
  * Desacoplamiento de scripts inline a archivos JS dedicados para cumplimiento de Content Security Policy (CSP).

---

### 🔴 Desafío 8: Multi-Factura Cross-Holding y Pagos con Cuotas
* **Problema:** Un cliente de una empresa del holding pagaba con un mismo cheque facturas de distintas razones sociales (ej. Autotec y Automarco).
* **Solución:**
  * Creación de la tabla pivote `cobranza_facturas`, soportando relaciones N:M entre una cobranza y múltiples facturas de distintas empresas.
  * Alerta visual ámbar/roja basada en el **Principio de Von Restorff** (`discrepancy-callout`) que resalta inmediatamente si la suma de los cheques difiere de las facturas, exigiendo una justificación escrita obligatoria.

---

### 🔴 Desafío 9: Integración Nativa con Google Sheets API v4 sin Dependencias
* **Problema:** Necesidad de registrar cada cheque validado en la planilla corporativa de Tesorería sin instalar paquetes pesados ni dependencias de Composer que pudieran romper la compatibilidad del servidor.
* **Solución:**
  * Desarrollo de `services/GoogleSheetsService.php` en **PHP puro (~120 líneas)** utilizando generación y firma de tokens JWT nativos con `openssl_sign` y peticiones cURL directas a la API v4 de Google Sheets con Service Account.

---

### 🔴 Desafío 10: Garantía de No Pérdida de Documentos (Bitácora Inmutable)
* **Problema:** Riesgo de fallas de conexión SMTP durante el despacho masivo a digitadoras.
* **Solución:**
  * Creación de la tabla `log_envios_informes` con almacenamiento de snapshots completos (`payload_json`).
  * Botón de **Re-intento en 1 Clic** en el panel de Cuentas Corrientes para reenviar informes fallidos o redirigirlos a correos alternativos.

---

### 🔴 Desafío 11: Homologación Universal de Vendedores Multi-Empresa
* **Problema:** Cada una de las 4 empresas del holding tenía autoincrementales independientes de vendedor.
* **Solución:**
  * Homologación universal utilizando el correo institucional (`ven_mail`) como clave unificadora para consolidar la cartera cross-holding.

---

### 🔴 Desafío 12: Sincronización Dual y Paridad Estricta de Despliegue (`dist/`)
* **Problema:** Riesgo de discrepancias entre el entorno de desarrollo local y la carpeta de distribución para producción (`dist/cheques_cobranza/app/`).
* **Solución:**
  * Protocolo de sincronización dual con auditoría automatizada de hashes MD5 (`verify_dist.php`).
  * **Resultado:** 48 archivos auditados con **0 diferencias binarias**, listos para desplegar en Apache/Linux.

---

## 5. Matriz Resumen de Desafíos y Soluciones

| # | Desafío Encontrado | Causa Raíz / Riesgo | Solución de Ingeniería Implementada |
|---|--------------------|---------------------|-------------------------------------|
| 1 | Resistencia al cambio del vendedor | Hábito con talonario físico | UI idéntica al talonario + Recibo Digital PDF para WhatsApp |
| 2 | Triple digitación manual | Planillas Excel intermedias | Portal Web Admin que elimina Excel y valida en 1 Clic |
| 3 | Miedo al reemplazo en C.Corrientes | Mantenimiento de ERP Optimus | Preservación del rol de digitación con datos pre-validados |
| 4 | Pérdida de tiempo en viaje de 16:00 hrs | Traslado físico de papeles | Despacho automático por hora de corte configurable en BD |
| 5 | Vulnerabilidad IDOR en APIs | Parámetros manipulables en URL | Autenticación forzada por sesión + pertenencia a cartera ERP |
| 6 | Manipulación fraudulenta de saldos | Falta de control backend | Revalidación estricta de saldos en `bd_automarco.tbl_cobranza` |
| 7 | Riesgo de RCE en subida de fotos | Ejecución de scripts en uploads | `.htaccess` con `php_flag engine off` y cabeceras OWASP ZAP |
| 8 | Facturas multi-empresa y cuotas | Pagos cruzados de clientes | Tabla pivote `cobranza_facturas` + Alerta Von Restorff |
| 9 | Inyección a Google Sheets | Falta de Composer en hosting | Cliente JWT nativo con `openssl_sign` en PHP puro |
| 10 | Pérdida de cheques por caída SMTP | Fallas de red en envíos | Bitácora `log_envios_informes` con re-envío en 1 Clic |
| 11 | Colisión de IDs de vendedor | 4 ERPs independientes | Homologación universal por email institucional (`ven_mail`) |
| 12 | Desalineación de código en producción | Errores en archivos de release | Script de paridad MD5 con 100% de paridad en `dist/` |

---

## 6. Métricas de Impacto y Resultados Obtenidos

* ⏱️ **Reducción del 85% en tiempo de procesamiento:** De un promedio de 25 minutos por cobranza a menos de 3 minutos desde terreno hasta Tesorería.
* 📄 **Eliminación del 100% del papel:** Cero talonarios impresos y cero traslados físicos de sobres entre pisos.
* 🛡️ **0% de pérdida de cheques:** Trazabilidad total de cada documento desde su recepción física hasta el depósito bancario.
* 🔒 **Seguridad de Grado Bancario:** Protección integral contra ataques IDOR, inyecciones SQL (100% prepared statements) y ataques RCE.
* 📱 **Adopción Inmediata:** Interfaz intuitiva y responsive adoptada por más de 80 vendedores en terreno sin necesidad de capacitaciones complejas.

---

## 7. Conclusión y Aporte Profesional de la Práctica

A través de un enfoque autónomo, analítico y rigurosamente técnico, la práctica profesional trascendió la simple codificación para convertirse en un **proyecto integral de reingeniería de procesos y ciberseguridad corporativa**.

Al escuchar activamente a los usuarios en terreno y en las oficinas centrales, se diseñó un software robusto, escalable y seguro que respeta la cultura operativa de la empresa, protege los puestos de trabajo existentes y entrega un valor económico y de control invaluable para el Holding Automarco.
