# ANDROID_INTEGRATION.md — Plan de Integración con App Android Existente

Este documento describe la arquitectura y el plan para integrar el Módulo de Cobranza y Cheques (Web PWA/Backend) con la aplicación Android nativa del holding, considerando que operan en **servidores y bases de datos completamente aislados**.

---

## 🏛️ 1. Arquitectura de Integración (Stateless JWT)

Para evitar la comunicación directa entre bases de datos en distintos servidores (lo cual compromete la seguridad y el rendimiento), utilizaremos **JSON Web Tokens (JWT)**.

```
[ App Android ]              [ Servidor App Android ]         [ Nuestro Servidor Cheques ]
       │                                │                                  │
       │─── 1. Login (User/Pass) ──────►│                                  │
       │◄── 2. Retorna JWT Firmado ─────│                                  │
       │    (Token contiene ID y Rol)   │                                  │
       │                                │                                  │
       │─── 3. Guarda Cobranza con JWT ─┼─────────────────────────────────►│
       │    (Authorization: Bearer)     │                                  │ (Valida firma con clave secreta)
       │◄── 4. OK / Transacción Exitosa ┼──────────────────────────────────│ (No necesita consultar BD externa)
```

### Funcionamiento:
1. El vendedor inicia sesión en la aplicación Android mediante el sistema de autenticación actual.
2. El servidor de la aplicación Android genera un token **JWT** firmado con una clave secreta simétrica (`HS256`).
3. El JWT encapsula los datos mínimos del vendedor (ej: `sub` como ID de usuario, `nombre`, `email` y `rol`).
4. Cuando la aplicación Android embebe o consume las APIs de nuestro módulo de cheques, adjunta el token en la cabecera HTTP:
   `Authorization: Bearer <token>`
5. Nuestro servidor valida la firma del token criptográficamente usando la clave secreta compartida en su configuración de entorno. Si es válido, extrae los datos del vendedor y asume su identidad en la cobranza.

---

## 💾 2. Sincronización de Datos (Vendedores y Clientes)

Dado que las bases de datos no están compartidas:

### A. Catálogo de Vendedores
*   **En Desarrollo/Dev:** Mantenemos la tabla local `usuarios` poblada con IDs de pruebas sincronizados manualmente.
*   **En Producción:** No es necesario replicar la tabla de vendedores completa. Al recibir el JWT válido, el servidor de cheques lee la identidad directamente del token decodificado (ID del vendedor, nombre, email) y la utiliza para insertar el registro en `cobranzas` (escribiendo `vendedor_id = JWT.sub` y `vendedor_nombre = JWT.name`).

### B. Catálogo de Clientes y Facturas
*   El endpoint `api/get_factura.php` requiere consultar datos de facturas en las 4 bases de datos ERP.
*   *Esquema:* Si las bases de datos ERP de lectura residen en el mismo servidor de base de datos que el Módulo de Cheques, las consultas se ejecutan directamente. Si están aisladas, será necesario que el equipo Android o de Infraestructura exponga un API Gateway local o replique las tablas de facturas (`tbl_ventas_devoluciones`) a nuestro servidor.

---

## 🛠️ 3. Hoja de Ruta de Integración (Fase 5)

1. **Definición de Contrato (Clave Compartida):** Acordar y guardar la firma criptográfica secreta (`JWT_SECRET`) en los archivos `.env` de ambos backends.
2. **Implementación de JWT en config/auth.php:** Incorporar decodificador en el middleware.
3. **Inyección de Token en WebViews:** Si la app Android embebe vistas web (WebView), se debe inyectar el token Bearer en los headers de navegación o a través de un puente JavaScript (`JavascriptInterface`).

---

## ❓ Preguntas de Alineación para el Equipo Android

Para completar este diseño y adaptarlo perfectamente a la aplicación actual, debemos clarificar las siguientes interrogantes con el equipo técnico de la App Android:

1. **¿Qué tipo de autenticación utiliza la App Android actualmente?**
   * ¿Retorna tokens Bearer autogenerados, JWT, cookies de sesión, o maneja sesiones en base de datos?
2. **¿La App Android consumirá los endpoints por debajo (REST API pura) o cargará la interfaz en un WebView?**
   * *Si es WebView:* ¿Cómo inyectarán las credenciales? (¿A través de un puente JavaScript que exponga el token, o por headers HTTP modificados en la carga del WebView?).
3. **¿Dónde residen las bases de datos ERP de las empresas (las 4 BDs)?**
   * ¿Están en el mismo servidor del backend de la App Android, o en otro servidor diferente al que alojará nuestro Módulo de Cheques? ¿Cómo se nos dará acceso de lectura?
4. **¿Cuál es la estructura del ID del vendedor en su sistema?**
   * ¿Es un número entero autoincremental (`INT`) o un código alfanumérico (`VARCHAR`)? Esto afectará al tipo de datos de la columna `vendedor_id` en la tabla `cobranzas`.
