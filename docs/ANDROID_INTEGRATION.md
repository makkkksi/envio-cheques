# ANDROID_INTEGRATION.md — Plan de Integración con App Android Existente

Este documento describe la arquitectura y especificaciones para integrar el Módulo de Cobranza y Cheques (Web PWA/Backend) con la aplicación Android nativa del holding, operando bajo un entorno de servidor y base de datos unificado en Amazon Web Services (AWS).

---

## 🏛️ 1. Arquitectura de Integración (WebView unificado en AWS)

Dado que toda la infraestructura (App Android y Módulo de Cheques) se alojará en el **mismo servidor de Amazon (AWS)**, la integración se simplifica significativamente:

1. **Acceso vía WebView:** La App Android cargará el portal web directamente a través de un WebView.
2. **Transferencia de Contexto de Usuario (GET):** 
   * Al cargar el WebView, la aplicación Android pasará el ID del vendedor autenticado por parámetro GET en la URL (ej: `index.html?vendedor_id=12`).
   * El portal extraerá este `vendedor_id` de los parámetros de la URL para registrarlo automáticamente en el formulario.
3. **Seguridad y Acceso:** La comunicación local al estar en el mismo hosting minimiza la exposición externa. El `vendedor_id` es un entero estándar (`INT`).

---

## 💾 2. Sincronización de Datos (Vendedores y Clientes)

Al estar en el mismo entorno de base de datos de Amazon:

### A. Catálogo de Vendedores
* El backend de cheques asocia el ID del vendedor recibido (`vendedor_id`) y lee sus datos directamente de la tabla centralizada de usuarios.

### B. Catálogo de Clientes y Facturas
* **Acceso Directo:** Dado que las bases de datos ERP de lectura residen en el mismo servidor de base de datos, el Módulo de Cheques ejecuta directamente consultas SQL al ERP utilizando el ID del vendedor para filtrar sus clientes asignados.
* El endpoint `api/get_factura.php` consulta las facturas directamente en la tabla de ventas unificada del ERP.

---

## 🛠️ 3. Hoja de Ruta de Integración (Fase 5)

1. **Paso de Parámetros en WebView:** Configurar la app Android para adjuntar el ID de usuario (`vendedor_id`) en la URL al instanciar el WebView del formulario.
2. **Persistencia Directa:** El endpoint `api/guardar_cobranza.php` recibe el `vendedor_id` (tipo `INT` estándar) e inserta el registro asociando la clave foránea directamente.
3. **Pruebas en AWS:** Publicar el backend y realizar pruebas de carga y visualización de imágenes directo en el ambiente de Amazon.