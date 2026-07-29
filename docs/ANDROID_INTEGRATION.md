# ANDROID_INTEGRATION.md — Plan de Integración con App Android Existente (Eclipse / WebView)

Este documento describe la arquitectura y especificaciones para integrar el Módulo de Cobranza y Cheques (Web PWA/Backend) con la aplicación Android nativa legada del holding (desarrollada en Eclipse), operando bajo un entorno de servidor y base de datos unificado en Amazon Web Services (AWS).

---

## 🏛️ 1. Arquitectura de Integración (WebView en App Legada Eclipse)

Dado que la aplicación Android fue desarrollada sobre el entorno **Eclipse** y posee una arquitectura legada, la estrategia de integración se basa en la invocación de un **WebView interno**:

1. **Botonera en Apps Android (4 Apps por Empresa):**
   - Dado que existen 4 aplicaciones Android independientes (una por cada razón social del holding), cada App conoce la empresa a la que pertenece.
2. **Transferencia de Contexto vía GET (Desambiguación Multi-Empresa):**
   - Al instanciar el WebView, cada aplicación Android pasa el ID del vendedor y el código de empresa (o el correo corporativo del vendedor) mediante parámetros GET en la URL:
     - **App Automarco LTDA:** `index.html?vendedor_id=25&empresa=EMP01` (o `vendedor_email=afereira@automarco.com`)
     - **App Gabtec S.A:** `index.html?vendedor_id=1&empresa=EMP10`
     - **App Autotec S.A:** `index.html?vendedor_id=1&empresa=EMP03`
     - **App HD Automarco S.A:** `index.html?vendedor_id=29&empresa=EMP06`
   - El portal Web extrae automáticamente estos parámetros para identificar unívocamente al vendedor y unificar toda su cartera asignada sin colisiones de IDs.
3. **Servidor Unificado (AWS):** Todo el backend de cheques y las bases de datos de lectura de los ERPs se alojan en la misma infraestructura de AWS, garantizando baja latencia y acceso SQL directo.

---

## 💾 2. Nuevo Flujo de Dominio: Vendedor ➔ Cliente ➔ Multi-Facturas (Multi-Empresa)

### Relación de Dominio:
* **Vendedor (`vendedor_id`):** Mantiene una cartera de **Clientes** asignados.
* **Cliente:** Puede poseer facturas pendientes generadas en **diferentes empresas del holding** (Automarco LTDA, Autotec S.A, Gabtec S.A, HD Automarco S.A), ya que un mismo cliente adquiere productos desde múltiples razones sociales.
* **Cobranza / Cheques:** Un cheque (o grupo de cheques) ingresado en una misma sesión puede **cubrir múltiples facturas** pertenecientes al mismo cliente, incluso si corresponden a distintas empresas.

### Nuevo Flujo del Formulario Vendedor:
1. **Selección de Cliente:** El vendedor selecciona el cliente de su lista asignada (filtrada por `vendedor_id`).
2. **Carga de Facturas Pendientes (Cross-Empresa):** El backend consulta en los 4 ERPs y despliega todas las facturas impagas del cliente seleccionado, indicando la empresa de origen de cada una.
3. **Selección Multi-Factura:** El vendedor marca mediante checkboxes las facturas que están siendo canceladas en esta gestión. El sistema calcula la suma total de las facturas seleccionadas.
4. **Registro de Cheques:** El vendedor ingresa uno o más cheques (banco, número, monto, vencimiento, foto) cuya suma total debe cubrir el monto de las facturas seleccionadas.

---

## 🛠️ 3. Hoja de Ruta de Integración (Fase 5)

1. **Endpoint `api/get_clientes.php`:** Crear endpoint que retorne los clientes asignados al `vendedor_id`.
2. **Endpoint `api/get_facturas_cliente.php`:** Crear endpoint que consulte en las 4 BDs de ERP las facturas impagas asociadas al RUT del cliente.
3. **Rediseño del Formulario `index.html` & `script.js`:** Actualizar el Paso 1 para selección de Cliente y marcado múltiple de facturas.
4. **Persistencia Multi-Factura (`guardar_cobranza.php`):** Adaptar el esquema de base de datos (`cobranza_facturas` o tabla pivot) para respaldar la relación N:M entre una cobranza/cheque y sus facturas cubiertas.