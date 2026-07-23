# API.md — Referencia de Endpoints

**Propósito:** Documentar todos los endpoints HTTP del módulo, sus contratos de request/response, códigos de error y ejemplos.  
**Audiencia:** Desarrolladores frontend, integradores de la app Android, agentes de IA.  
**Referencias:** [`DATABASE.md`](./DATABASE.md) para campos · [`SECURITY.md`](./SECURITY.md) para auth · [`BUSINESS_RULES.md`](./BUSINESS_RULES.md) para validaciones.

---

## Convenciones Generales

- **Base URL local:** `http://localhost/form/api/`
- **Base URL producción:** `https://dominio.cl/form/api/`
- **Formato de respuesta:** siempre `application/json; charset=utf-8`
- **Autenticación:** Header `Authorization: Bearer {token}` (solo en `APP_ENV=production`)
- **Método de datos:** `GET` con query params · `POST` con `multipart/form-data` o JSON

### Estructura de respuesta estándar

```json
// Éxito
{ "success": true, "data": { ... } }
{ "success": true, "message": "...", "cobranza_id": 123 }

// Error
{ "success": false, "message": "Descripción del error", "errors": [ ... ] }
```

### Códigos HTTP usados

| Código | Significado |
|--------|-------------|
| `200` | Éxito |
| `400` | Parámetros faltantes o inválidos |
| `401` | Sin autenticación o token inválido |
| `403` | BD ERP no permitida (fuera de whitelist) |
| `404` | Recurso no encontrado |
| `500` | Error interno del servidor |

---

## Endpoints de la App Vendedor

---

### GET `/api/get_factura.php`

Busca una factura en la BD ERP de la empresa seleccionada y retorna los datos del cliente con el monto calculado con IVA.

**Disparado por:** Evento `input` en campo N° Factura del formulario (debounce 600ms).

#### Request

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `empresa_id` | int | ✅ | ID numérico de la empresa (value del select en UI) |
| `numero_factura` | string | ✅ | Número de factura (mínimo 4 caracteres, solo dígitos) |

```
GET /api/get_factura.php?empresa_id=3&numero_factura=440265
```

#### Response — Éxito (200)

```json
{
  "success": true,
  "data": {
    "factura": "440265",
    "rut_cliente": "76.492.105-K",
    "razon_social": "Distribuidora del Pacífico Ltda.",
    "email_cliente": "contacto@pacifico.cl",
    "monto_total_factura": 1487500
  }
}
```

#### Response — No encontrada (200)

```json
{
  "success": false,
  "message": "Factura no encontrada en el ERP de Autotec S.A"
}
```

#### Response — Error de parámetros (400)

```json
{
  "success": false,
  "message": "Parámetros requeridos: empresa_id, numero_factura"
}
```

#### Response — BD no permitida (403)

```json
{
  "success": false,
  "message": "Base de datos no autorizada"
}
```

---

### POST `/api/guardar_cobranza.php`

Guarda una cobranza completa con sus cheques e imágenes. Usa transacción SQL atómica.

**Disparado por:** Submit del formulario principal.

#### Request — `multipart/form-data`

**Campos de cabecera:**

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `empresa_id` | int | ✅ | ID de la empresa |
| `numero_factura` | string | ✅ | Número de factura |
| `rut_cliente` | string | ✅ | RUT del cliente |
| `razon_social_cliente` | string | ✅ | Razón social del cliente |
| `monto_total_factura` | decimal | — | Monto con IVA obtenido del ERP |
| `email_cliente` | string | — | Email del cliente (opcional) |
| `email_tesoreria` | string | ✅ | Email de tesorería de la empresa |
| `tipo_entrega` | string | ✅ | `CHILEXPRESS` o `PRESENCIAL_SANTIAGO` |
| `numero_seguimiento` | string | — | OT Chilexpress (requerido si `tipo_entrega=CHILEXPRESS`) |

**Arrays de cheques (indexados):**

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `banco[]` | string | ✅ | Banco emisor del cheque |
| `numero_cheque[]` | string | ✅ | Número de serie del cheque |
| `monto_cheque[]` | int | ✅ | Monto en pesos (entero, sin formato) |
| `fecha_vencimiento[]` | date | ✅ | Formato `YYYY-MM-DD` |
| `comentario_cheque[]` | string | — | Comentario/observación opcional por cheque |

**Archivos:**

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `foto_cheque[]` | file | ✅ | Foto del cheque (una por cada cheque en el array) |
| `foto_comprobante` | file | — | Foto OT Chilexpress (si `tipo_entrega=CHILEXPRESS`) |
| `foto_firma` | file | — | Foto recepción firmada (si `tipo_entrega=PRESENCIAL_SANTIAGO`) |

#### Response — Éxito (200)

```json
{
  "success": true,
  "message": "Cobranza registrada con éxito",
  "cobranza_id": 123
}
```

#### Response — Error de validación (400)

```json
{
  "success": false,
  "message": "Faltan campos requeridos",
  "errors": ["empresa_id es requerido", "Se requiere al menos un cheque"]
}
```

#### Response — Error de transacción (500)

```json
{
  "success": false,
  "message": "Error al guardar la cobranza. Intente nuevamente."
}
```

---

### GET `/api/get_mis_cobranzas.php`

Retorna las cobranzas del vendedor autenticado con sus cheques anidados. Solo lectura.

**Disparado por:** Carga de la pestaña "Ver Cheques Enviados".

#### Request

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `estado` | string | — | Filtrar por estado (`TODOS`, `INGRESADO`, `EN_TRANSITO`, etc.) |
| `empresa_id` | int | — | Filtrar por empresa |
| `busqueda` | string | — | Búsqueda libre (factura, RUT, razón social) |

```
GET /api/get_mis_cobranzas.php?estado=EN_TRANSITO&empresa_id=1
```

#### Response — Éxito (200)

```json
{
  "success": true,
  "data": [
    {
      "id": 123,
      "empresa_nombre": "Autotec S.A",
      "numero_factura": "440265",
      "razon_social_cliente": "Distribuidora del Pacífico Ltda.",
      "rut_cliente": "76.492.105-K",
      "monto_total_factura": 1487500,
      "tipo_entrega": "CHILEXPRESS",
      "numero_seguimiento": "991823746",
      "estado": "EN_TRANSITO",
      "created_at": "2026-07-23 10:30:00",
      "cheques": [
        {
          "id": 45,
          "banco": "Banco de Chile",
          "numero_cheque": "882192",
          "monto": 750000,
          "fecha_vencimiento": "2026-08-15",
          "foto_cheque_url": "uploads/3/2026-07/cheques/abc123_cheque.jpg",
          "comentario": "Cheque cruzado"
        }
      ]
    }
  ]
}
```

---

## Endpoints de Autenticación

---

### POST `/api/auth/login.php`

Autentica un usuario y retorna un token Bearer para adjuntar en requests posteriores.  
Preparado para integración con la app Android.

#### Request — `application/json`

```json
{
  "email": "vendedor@automarco.cl",
  "password": "mi_contraseña"
}
```

#### Response — Éxito (200)

```json
{
  "success": true,
  "token": "a3f8c2d1e4b7...",
  "usuario": {
    "id": 5,
    "nombre": "Juan Pérez",
    "rol": "VENDEDOR"
  }
}
```

#### Response — Credenciales inválidas (401)

```json
{
  "success": false,
  "message": "Credenciales incorrectas"
}
```

---

## Endpoints Futuros (Fase 2 — Portal Tesorería)

> Estos endpoints aún no se implementan. Se documentan para planificación.

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| `GET` | `/admin/api/get_cobranzas.php` | Listado completo con filtros (todas las empresas) |
| `POST` | `/admin/api/cambiar_estado.php` | Cambia estado + inserta en `historial_estados` |
| `GET` | `/admin/api/get_detalle_cobranza.php` | Detalle completo con historial de estados |

---

## Uso desde la App Android

La app Android consume estos endpoints mediante WebView o llamadas HTTP nativas:

```javascript
// Ejemplo fetch desde el WebView embebido
const response = await fetch('/api/guardar_cobranza.php', {
  method: 'POST',
  headers: {
    'Authorization': `Bearer ${token}`  // cuando APP_ENV = 'production'
  },
  body: formData
});
const result = await response.json();
```

El token se obtiene una vez con `POST /api/auth/login.php` y se persiste en el almacenamiento de la app Android para reutilizarlo en cada sesión.
