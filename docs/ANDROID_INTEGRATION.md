# Análisis de Integración Android — App Autotec_Grande

## 1. Visión General de la App

| Propiedad | Valor |
|---|---|
| **Package** | `com.example.autotec2` |
| **Tipo de Proyecto** | Eclipse Android Project (Legacy, **no Gradle**) |
| **Target SDK** | `android-24` (Android 7.0 Nougat) |
| **Min SDK** | `android-24` |
| **Lenguaje** | Java puro |
| **Build Tools** | 20 |
| **Orientación** | Portrait (excepto `Cobranza.java` que es landscape) |
| **Tema** | `Theme.Black.NoTitleBar.Fullscreen` |

> [!IMPORTANT]
> Esta app **NO usa Gradle ni Android Studio**. Es un proyecto Eclipse (`.classpath`, `.project`, `project.properties`). Para compilarla necesitaríamos migrarla a Gradle/Android Studio o usar las herramientas legacy de Eclipse ADT.


---

## 2. Arquitectura de la App

### Clase `Application` Global: `Globals.java`
La app usa una clase `Globals extends Application` como **almacenamiento de estado global en memoria**. Es el equivalente a un "singleton de sesión".

**Variables clave que maneja:**
| Variable | Getter/Setter | Default | Uso |
|---|---|---|---|
| `_vendedorcod` | `get_vendedorcod()` / `set_vendedorcod()` | `"34"` | **Código numérico del vendedor logueado** |
| `_vendpass` | `get_vendpass()` | `"cod34"` | Password por defecto del vendedor |
| `_cli_rut` | `get_cli_rut()` / `set_cli_rut()` | `""` | RUT del cliente seleccionado |
| `_cli_rut2` | `get_cli_rut2()` | `""` | RUT secundario |
| `_cli_sec` | `get_cli_sec()` | `""` | Secuencia/sucursal del cliente |
| `_cli_disdes` | `get_cli_disdes()` | `""` | Descuento del cliente |
| `_correo` | `get_correo()` | `""` | Correo del cliente |
| `_orden` | `get_orden()` | `""` | Nro. de orden/pedido |
| `_totalcompra` | `get_totalcompra()` | `""` | Monto total de la compra |
| `_cli_bi` | `get_cli_bi()` / `set_cli_bi()` | `""` | RUT para Business Intelligence |
| `recuperar_carro` | `get_recuperar_carro()` | `false` | Flag para recuperar carro pendiente |

### Persistencia de Sesión: `SharedPreferences("sesion")`
Además de `Globals`, la app guarda datos en `SharedPreferences` con el nombre **`"sesion"`**:
- `vendedorcod` — código del vendedor (String)

Esto se usa como **fallback** cuando `Globals` pierde su valor (ej. si Android mata el proceso). Los WebView Activities (`Sugerido.java`, `SimpleRoute.java`) verifican ambos:
```java
String vendedorcod = this.global.get_vendedorcod();
if (vendedorcod == null || vendedorcod.equals("999") || vendedorcod.isEmpty()) {
    SharedPreferences prefs = this.getSharedPreferences("sesion", MODE_PRIVATE);
    vendedorcod = prefs.getString("vendedorcod", "999");
    this.global.set_vendedorcod(vendedorcod);
}
```

---

## 3. Flujo de Login (`LoginActivity.java`)

### Mecanismo de Autenticación
La autenticación es **100% local/hardcoded** (no hay llamada a servidor):
- **Usuario fijo:** `"autotec"` (case insensitive)
- **Password por defecto:** Lo que retorne `global.get_vendpass()` → `"cod34"`
- **Logins de excepción (5 vendedores especiales):**

| Password | Código Vendedor Asignado |
|---|---|
| `cod86` | `86` |
| `cod87` | `87` |
| `cod88` | `88` |
| `cod71` | `71` |
| `cod85` | `85` |

### Flujo:
1. Usuario ingresa `"autotec"` + password.
2. Si es login normal (`cod34`) → vendedor queda con código por defecto `"34"`.
3. Si es login excepción (ej `cod86`) → se llama `global.set_vendedorcod("86")` y se guarda en SharedPreferences.
4. Se valida `oDB.ValidaAlerta()` (verifica que las tablas locales tengan datos de clientes/productos).
5. Si todo ok → navega a `MenuPrincipal.class`.

### Servicios que inicia al login:
- `Persistencia.class` — servicio en background para sincronización de datos.
- `Persistencia_Correo.class` — servicio en background para envío de correos.

---

## 4. Menú Principal (`MenuPrincipal.java` + `activity_menu_principal.xml`)

El menú principal es un layout vertical con **12 botones (6 filas × 2 columnas)** usando imágenes como background:

| Fila | Botón Izquierdo | Botón Derecho |
|---|---|---|
| 1 | **Clientes** (`boton_cliente`) → `Clientes.class` | **Sistema/Actualización** (`boton_sistma`) → `Actualizaciones.class` |
| 2 | **Por Código** (`boton_por_codigo`) → `ProductosCod.class` | **Compras** (`boton_compras`) → `Carro.class` |
| 3 | **Por Medidas** (`boton_por_medidas`) → `Medidas.class` | **Pedidos** (`boton_pedidos`) → `Lista_pedidos.class` |
| 4 | **Por Aplicación** (`boton_por_aplicacion`) → `Aplicaciones.class` | **Cobranzas** (`boton_listado_cobranzas`) → `Cobranza.class` |
| 5 | **Seguimiento** (`boton_seguimiento_pedidos`) → `SimpleRoute.class` (WebView) | **Devoluciones** (`boton_devolucionat`) → `Devoluciones.class` (WebView) |
| 6 | **Análisis/BI** (`boton_analisis`) → `Inteligencia.class` (WebView) | **Sugerido** (`btn_sugerigo_autotec`) → `Sugerido.class` (WebView) |

**Patrón de navegación:** Los métodos del menú (`clientes()`, `cobranza()`, etc.) son `onClick` handlers definidos en el XML. La mayoría validan datos locales con `validarDatos()` antes de navegar.

---

## 5. Patrón de WebView — Cómo se Pasan Datos

La app tiene **4 Activities que usan WebView** para cargar contenido web. Todas siguen un patrón muy similar:

### 5.1 `Sugerido.java` ⭐ (Mejor referencia para nuestra integración)
```
URL: http://autotecbi.automarco.cl/#/sugerido?codv={vendedorcod}
Layout: activity_sugerido.xml (WebView fullscreen con id "webView1")
```
**Configuración del WebView:**
- `setJavaScriptEnabled(true)`
- `setDomStorageEnabled(true)` ← **VITAL para nuestro formulario**
- `setAllowFileAccess(true)`
- `setCacheMode(LOAD_NO_CACHE)` + `clearCache(true)` + `clearHistory()`
- `setMixedContentMode(MIXED_CONTENT_ALWAYS_ALLOW)` ← permite HTTP en HTTPS
- `WebChromeClient` habilitado
- SSL errors ignorados (`handler.proceed()`)
- **JavascriptInterface `"Android"`** con método `goBackToMainMenu()` para volver al menú

**Paso de datos:** Vía **query string `?codv=`** con el código del vendedor.

### 5.2 `SimpleRoute.java`
```
URL: http://www.autotec.cl/json/simpleroute.php?codv={vendedorcod}
Layout: activity_simple_route.xml (WebView fullscreen con id "webView1")
```
Mismo patrón, pero sin `WebChromeClient` ni JavascriptInterface funcional.

### 5.3 `Devoluciones.java`
```
URL: https://devoluciones.automarco.cl/
Layout: activity_simple_route.xml (reutiliza el layout)
```
**Sin query params.** Pero incluye `JavascriptInterface("Android")` con método `savePDF(String base64PDF)` para descarga de PDFs.

### 5.4 `MercadoPago.java`
```
URL: https://www.autotec.cl/save-compraMPTablet.php?venr={vendedorcod}&cli={cli}&ord={orden}&mon={totalcompra}
```
**Abre en Chrome externo** en vez de WebView interno. Pasa múltiples params de `Globals`.

### 5.5 `Inteligencia.java`
```
URL: https://autotecbi.automarco.cl/#/{birutLimpio}?productos={productosConcatenados}
Layout: activity_inteligencia.xml (WebView con id "webViewBI")
```
Requiere que el usuario haya seleccionado un cliente previamente (`get_cli_bi()`).

---

## 6. Network Security Config

El archivo `res/xml/network_security_config.xml` permite **cleartext HTTP** (no-HTTPS) para:
- `autotec.cl` (y subdominios)
- `18.246.128.89`

> [!WARNING]
> Para nuestra integración, necesitaremos **añadir el dominio del servidor de cobranzas** a este archivo si usamos HTTP, o configurar HTTPS correctamente.

---

## 7. Base de Datos Local (`DBProvider.java`)

La app tiene una base de datos SQLite local manejada por `DBProvider.java` (84KB, archivo extenso). Contiene tablas para:
- Clientes locales
- Productos
- Cobranzas (listado de facturas/cuotas del ERP)
- Alertas de sincronización
- Cabecera de vendedor

La tabla de cobranzas local (`tbl_cobranza`) se usa para el módulo de listado de cobranzas existente (`Cobranza.java`), que es **nativo** (no WebView) y muestra facturas vencidas con semáforo de colores.

---

## 8. Plan de Integración: Módulo de Envío de Cheques

### Estrategia Recomendada
Replicar exactamente el patrón de `Sugerido.java` (el más completo de los WebViews):

### Archivos a crear/modificar:

| Archivo | Acción | Descripción |
|---|---|---|
| `EnvioCheques.java` | **NUEVO** | Activity WebView que carga nuestro formulario |
| `activity_envio_cheques.xml` | **NUEVO** | Layout fullscreen WebView |
| `boton_envio_cheques.png` | **NUEVO** | Imagen del botón para el menú |
| `activity_menu_principal.xml` | **MODIFICAR** | Añadir 7ª fila con el botón |
| `MenuPrincipal.java` | **MODIFICAR** | Añadir método `envioCheques(View)` |
| `AndroidManifest.xml` | **MODIFICAR** | Registrar `EnvioCheques` activity |
| `network_security_config.xml` | **MODIFICAR** | Añadir dominio del servidor |

### URL del WebView:
```
http://{SERVIDOR}/form/index.html?vendedor_id={vendedorcod}
```

### Configuraciones necesarias del WebView:
```java
// Mínimo para nuestro formulario:
settings.setJavaScriptEnabled(true);        // JS del formulario
settings.setDomStorageEnabled(true);        // localStorage, sessionStorage
settings.setCacheMode(LOAD_NO_CACHE);       // No cachear
settings.setAllowFileAccess(true);          // Para subir fotos de cheques
settings.setMixedContentMode(MIXED_CONTENT_ALWAYS_ALLOW);

// Para la cámara/fotos (subir cheques):
// Necesitaremos override de WebChromeClient.onShowFileChooser()
```

> [!CAUTION]
> **Subida de archivos (fotos de cheques):** El WebView de Android **NO soporta** `<input type="file">` por defecto. Necesitamos implementar `WebChromeClient.onShowFileChooser()` para que el vendedor pueda subir las fotos de los cheques desde la cámara o galería. Esto es **CRÍTICO** y lo maneja `Sugerido.java` parcialmente pero `Devoluciones.java` ya tiene el patrón de permisos.

### Variables disponibles para pasar al WebView:
Desde `Globals` podemos pasar vía query params:
- `vendedorcod` → **vendedor_id** del formulario
- `cli_rut` → RUT del cliente seleccionado (si aplica)
- `cli_rut2` → RUT secundario
- `correo` → Email del cliente

---

## 9. Resumen de Archivos Java Clave

| Archivo | Tamaño | Rol |
|---|---|---|
| `LoginActivity.java` | 6.7KB | Login hardcoded, inicia servicios |
| `Globals.java` | 3.2KB | Estado global (vendedor, cliente, etc.) |
| `MenuPrincipal.java` | 5.3KB | Menú con 12 botones |
| `Sugerido.java` | 3.7KB | ⭐ **Mejor referencia** WebView con JS Interface |
| `SimpleRoute.java` | 2.5KB | WebView básico con query params |
| `Devoluciones.java` | 4.1KB | WebView con descarga PDF y permisos |
| `MercadoPago.java` | 4.1KB | Abre Chrome externo con params |
| `Inteligencia.java` | 28.9KB | WebView BI con datos de productos |
| `Cobranza.java` | 17.0KB | Listado de cobranzas nativo (ListView) |
| `DBProvider.java` | 84.1KB | Base de datos SQLite local |
| `Persistencia.java` | 82.8KB | Servicio de sincronización background |

---

## 10. Cambios Necesarios en la Arquitectura Web (PHP/JS) para Producción

Para que el formulario web funcione correctamente cuando sea embebido en la aplicación Android en **producción**, necesitamos realizar ajustes en nuestra arquitectura web (backend y frontend), ya que actualmente dependemos de parámetros que la app móvil no envía por defecto.

### 1. Flexibilizar la Autenticación en `api/auth_seller.php`
**El Problema:** 
Actualmente, `auth_seller.php` exige que se envíe el `vendedor_id` **Y** la `empresa` (o el email) para consultar la base de datos correspondiente (Automarco, Autotec, etc.) y obtener el correo del vendedor. Si en producción no se envía la empresa y no hay email, el script devuelve un error `403 Forbidden` (ya que el correo fallback `dev_{id}@local.test` solo funciona en entorno `local`). 
La app de Android, por defecto, solo maneja globalmente el código del vendedor (`vendedorcod`), pero no el código de la empresa a la que pertenece.

**La Solución:**
- Modificar `api/auth_seller.php` para que, si recibe el `vendedor_id` pero **NO** recibe la `empresa`, haga una búsqueda secuencial (o un `UNION`) en las 4 bases de datos del ERP (`automarc_automarco`, `gabteccl_sitbdd1978`, `autotec_ecom`, `autohd_automarcohd`) hasta encontrar el registro del vendedor en la tabla `tbl_vendedores`.
- Una vez encontrado, extraer el `ven_mail` y el nombre, e iniciar la sesión PHP.

### 2. Manejo de Sesiones (Cookies) en el WebView
**El Problema:**
El backend PHP utiliza `session_start()` para mantener al vendedor autenticado (vía cookie `PHPSESSID`). Los WebViews de Android a veces son restrictivos con las cookies, lo que podría causar que el vendedor pierda la sesión al enviar una imagen o cambiar de pestaña internamente.

**La Solución:**
- **Lado Android (App):** Asegurarse de habilitar las cookies en el WebView (`CookieManager.getInstance().setAcceptCookie(true)`).
- **Lado Web (PHP):** Asegurarnos de que las llamadas a la API (`fetch`) incluyan siempre `credentials: 'same-origin'` (o `include`) en `script.js` para que la cookie de sesión viaje en cada petición de subida de cheques o guardado.

### 3. Ajustes de Interfaz de Usuario (UI) para la App
**El Problema:**
Al abrir el formulario web en el navegador del PC, se ve bien. Pero dentro de la app Android (que ocupa toda la pantalla sin barra de direcciones), algunos elementos web pueden verse redundantes o estorbar (como márgenes excesivos, textos muy pequeños, etc.).

**La Solución:**
- En `script.js`, detectar si el formulario se está ejecutando dentro del WebView de Android (por ejemplo, verificando si existe el objeto inyectado `window.Android` o leyendo el `User-Agent`).
- Si está en Android:
  - Ocultar botones de cierre web o adaptarlos para que llamen a la interfaz nativa (`window.Android.goBackToMainMenu()`).
  - Aumentar el tamaño de los touch targets (botones).

### 4. Entorno Seguro (HTTPS)
**El Problema:**
A partir de Android 9, se bloquea por defecto el tráfico HTTP en texto plano. La app actual tiene una excepción para `autotec.cl`, pero es una mala práctica depender de ello para nuevos módulos.

**La Solución:**
- Asegurarnos de que la URL de producción del formulario web (ej. `https://www.autotec.cl/form/index.html`) utilice estrictamente **HTTPS**.

### 5. Soporte de Cámara para `<input type="file">`
**El Problema:**
Nuestro formulario usa `<input type="file" accept="image/*" capture="environment">` para tomar fotos de cheques. En Android, esto **no funciona por defecto** dentro de un WebView sin código nativo adicional.

**La Solución:**
- **En PHP:** Asegurarse de que `upload_max_filesize` y `post_max_size` en el servidor sean suficientemente grandes (las cámaras de tablets/móviles toman fotos muy pesadas, >5MB).
- **En JS:** Implementar una reducción de imagen en el frontend (Canvas) antes de enviarla, para ahorrar datos móviles y evitar timeouts.