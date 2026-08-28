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

### GET `/api/get_clientes.php`

Retorna la lista de clientes únicos asociados a la cartera del vendedor que poseen documentos impagos en la tabla consolidada `bd_automarco.tbl_cobranza`.

**Disparado por:** Carga del formulario del vendedor o lectura del `vendedor_id`.

#### Request

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `vendedor_id` | int | Opcional | ID del vendedor (si no se envía, toma el usuario autenticado) |

```
GET /api/get_clientes.php?vendedor_id=2
```

#### Response — Éxito (200)

```json
{
  "success": true,
  "vendedor_id": 2,
  "count": 2,
  "data": [
    {
      "rut_completo": "77891200-7",
      "clirut": "77891200",
      "clidv": "7",
      "razon_social": "BALEO REPUESTOS LTDA.",
      "email_cliente": "ba-leo_ltda@hotmail.com",
      "total_facturas": 108,
      "total_deuda": 617543028
    }
  ]
}
```

---

### GET `/api/get_facturas_cliente.php`

Retorna todas las facturas impagas asociadas al RUT de un cliente a través de todas las empresas del holding (`EMP01`, `EMP03`, `EMP06`, `EMP10`).

**Disparado por:** Selección de un cliente en el dropdown del vendedor.

#### Request

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `rut_cliente` | string | ✅ | RUT del cliente (numérico o formateado con DV) |

```
GET /api/get_facturas_cliente.php?rut_cliente=77891200
```

#### Response — Éxito (200)

```json
{
  "success": true,
  "clirut": "77891200",
  "count": 1,
  "total_deuda": 3023727,
  "data": [
    {
      "codigo_empresa": "EMP10",
      "empresa_id": 4,
      "empresa_nombre": "Gabtec S.A",
      "numero_factura": "003163",
      "fecha_emision": "05-08-2024",
      "fecha_vencimiento": "01-08-2023",
      "glosa": "CH.PROT.B-CH.EXTRAVIO",
      "total_cuota": 3023727,
      "saldo_cuota": 3023727,
      "tipo_doc": 6
    }
  ]
}
```

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

### POST `/api/completar_envio.php`

Completa el segundo paso de una cobranza en estado `PENDIENTE_ENVIO`. Actualiza sus datos logísticos, registra la transición en la bitácora y envía las notificaciones.

**Disparado por:** Botón “Completar envío” en el historial del vendedor.

#### Request — `multipart/form-data`

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `cobranza_id` | int | ✅ | ID de la cobranza pendiente |
| `tipo_entrega` | string | ✅ | `CHILEXPRESS` o `PRESENCIAL_SANTIAGO` |
| `numero_seguimiento` | string | — | OT de Chilexpress, si corresponde |
| `foto_comprobante` | file | Condicional | Requerida para `CHILEXPRESS` |
| `foto_firma` | file | Condicional | Requerida para `PRESENCIAL_SANTIAGO` |

#### Response — Éxito (200)

```json
{
  "success": true,
  "message": "Envío completado correctamente",
  "data": {
    "cobranza_id": 123,
    "estado": "EN_TRANSITO"
  }
}
```

---

### GET `/api/get_mis_cobranzas.php`

Retorna las cobranzas del vendedor autenticado con sus cheques anidados. Solo lectura.

**Disparado por:** Carga de la pestaña "Ver Cheques Enviados".

#### Request

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `estado` | string | — | Filtrar por estado (`TODOS`, `PENDIENTE_ENVIO`, `EN_TRANSITO`, etc.) |
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

## Endpoints del Portal de Tesorería (`/admin/`)

---

### GET `/admin/api/get_cobranzas.php`

Listado general de cobranzas para el Portal de Tesorería. Incluye resumen de facturas desglosadas (`facturas`) y cheques anidados.

#### Request

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `estado` | string | — | Estado o grupo (`BANDEJA_TRABAJO`, `EN_TRANSITO`, `DEPOSITADO`, `TODOS`) |
| `empresa_id` | int | — | ID de la empresa central |
| `busqueda` | string | — | Búsqueda libre (Factura, RUT, Cliente, Vendedor) |

#### Response — Éxito (200)

```json
{
  "success": true,
  "metrics": {
    "bandeja_trabajo": 3,
    "pendientes_envio": 2,
    "en_transito": 2,
    "recibidos": 1,
    "depositados": 5,
    "rechazados": 1,
    "total": 11
  },
  "data": [
    {
      "id": 12,
      "empresa_nombre": "Multi-Empresa",
      "rut_cliente": "14395118-9",
      "razon_social_cliente": "HERRERA PEREIRA GERARDO",
      "monto_total_factura": 308000,
      "estado": "PENDIENTE_ENVIO",
      "created_at": "2026-07-29 11:45:03",
      "vendedor_nombre": "Juan Carlos Quiróz",
      "facturas": [
        {
          "codigo_empresa": "EMP03",
          "numero_factura": "022048",
          "monto_cubierto": 154000
        },
        {
          "codigo_empresa": "EMP03",
          "numero_factura": "022050",
          "monto_cubierto": 154000
        }
      ],
      "cheques": [
        {
          "id": 14,
          "banco": "BANCO DE CHILE",
          "numero_cheque": "99887766",
          "monto": 308000,
          "foto_cheque_url": "uploads/cheque_test_e2e.jpg"
        }
      ]
    }
  ]
}
```

---

### GET `/admin/api/get_detalle_cobranza.php`

Obtiene el detalle completo de una cobranza específica para el drawer o modal de auditoría de Tesorería.

#### Request

```
GET /admin/api/get_detalle_cobranza.php?id=12
```

#### Response — Éxito (200)

```json
{
  "success": true,
  "data": {
    "cobranza": {
      "id": 12,
      "empresa_nombre": "Multi-Empresa",
      "rut_cliente": "14395118-9",
      "razon_social_cliente": "HERRERA PEREIRA GERARDO",
      "monto_total_factura": "308000",
      "estado": "PENDIENTE_ENVIO",
      "vendedor_nombre": "Juan Carlos Quiróz",
      "total_cheques": 308000,
      "cantidad_cheques": 1
    },
    "facturas": [
      {
        "id": 3,
        "codigo_empresa": "EMP03",
        "numero_factura": "022048",
        "monto_cubierto": "154000"
      },
      {
        "id": 4,
        "codigo_empresa": "EMP03",
        "numero_factura": "022050",
        "monto_cubierto": "154000"
      }
    ],
    "cheques": [
      {
        "id": 14,
        "banco": "BANCO DE CHILE",
        "numero_cheque": "99887766",
        "monto": "308000",
        "foto_cheque_url": "uploads/cheque_test_e2e.jpg"
      }
    ],
    "historial": []
  }
}
```

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

---

## Endpoints de Administración de Usuarios (`ADMINISTRADOR`)

Todos requieren sesión administrativa activa. Los endpoints `POST` requieren además el header `X-CSRF-Token` generado por la vista del Shell.

### GET `/admin/api/get_usuarios.php`

Lista cuentas con rol `ADMINISTRADOR`, `TESORERIA` o `SUPERVISORA_CC`. No retorna hashes, tokens ni credenciales.

```json
{ "success": true, "data": [{ "id": 3, "nombre": "Tesorería", "email": "tesoreria@automarco.cl", "rol": "TESORERIA", "activo": 1 }] }
```

### POST `/admin/api/guardar_usuario.php`

Crea o actualiza una cuenta administrativa. Para creación, `password` debe tener al menos 10 caracteres y se almacena con `password_hash(..., PASSWORD_BCRYPT)`. La desactivación usa `activo = 0`; nunca elimina físicamente la cuenta.

```json
{ "id": 0, "nombre": "Nombre Apellido", "email": "usuario@automarco.cl", "rol": "TESORERIA", "activo": 1, "password": "contraseña-inicial" }
```

### POST `/admin/api/resetear_password_usuario.php`

Restablece el hash y revoca cualquier token Bearer existente del usuario objetivo.

```json
{ "id": 3, "password": "nueva-contraseña" }
```

---

## Módulo 3 — Rendiciones de Gastos

Todas las respuestas usan `{ "success": true/false, ... }`. Las mutaciones con sesión requieren `X-CSRF-Token`. Ningún endpoint de vendedor acepta `vendedor_id` o `empresa_id`: ambos se obtienen exclusivamente de `$_SESSION['vendedor_auth']`.

### GET `/api/auth/session_vendedor.php`

Heartbeat y consulta de sesión para Cheques y Rendiciones. Renueva la actividad de una sesión válida y devuelve identidad canónica, empresa, expiración restante y CSRF vigente. Responde `401` cuando no existe una sesión recuperable.

### GET `/admin/api/auth/session_status.php`

Heartbeat del Shell ERP. Revalida usuario, estado y rol contra `usuarios`, actualiza la actividad y entrega la expiración restante. No recibe credenciales ni reemplaza el login.

### POST `/api/rendiciones/guardar_documento_bolsa.php`

`multipart/form-data` con `tipo_documento`, `categoria_gasto`, `fecha_emision`, `monto` y `foto_documento`. Documentos normales exigen `rut_proveedor` y `numero_documento`; `CENA_CLIENTE` exige los cinco campos tributarios; `PEAJE` sólo exige fecha, monto y fotografía.

Responde `409` si `document_hash` ya existe en el holding.

### GET `/api/rendiciones/get_bolsa_gastos.php`

Devuelve `documentos`, `presupuestos` activos con saldo calculado y `csrf_token` para las mutaciones posteriores. Cada presupuesto diferencia `monto_aprobado` (rendiciones aprobadas, aprobadas parcialmente o pagadas), `monto_pendiente` (fondos comprometidos aún no aprobados), `monto_utilizado` (compromiso total) y `saldo_disponible` (cupo todavía libre).

### POST `/api/rendiciones/eliminar_documento_bolsa.php`

```json
{ "documento_id": 18 }
```

Sólo admite documentos propios en `BORRADOR`. Realiza baja lógica `DESCARTADO`; no elimina registros ni fotografías.

### POST `/api/rendiciones/guardar_rendicion.php`

```json
{
  "presupuesto_id": 4,
  "documento_ids": [18, 19, 20],
  "nota_vendedor": "Opcional; máximo 500 caracteres"
}
```

Bloquea presupuesto y documentos, recalcula montos y compromete el fondo dentro de una única transacción. Sin exceso pasa a `EN_REVISION_TESORERIA`; con exceso pasa a `PENDIENTE_APROBACION_EXCESO`. Si se informa `nota_vendedor`, se persiste en `rendiciones_gastos.nota_vendedor` y queda incluida en el evento inmutable `ENVIAR_RENDICION`.

### GET `/api/rendiciones/get_mis_rendiciones.php`

Filtros opcionales: `estado`, `pagina`, `limite`. Retorna sólo rendiciones del vendedor y empresa de la sesión.

### POST `/api/rendiciones/aprobar_exceso.php`

Endpoint público de capacidad limitada. La vista `/rendiciones/aprobar_exceso.php` presenta el resumen financiero, comprobantes y datos SII antes de obtener confirmación humana y enviar:

```json
{ "token": "64-caracteres-hex", "decision": "APROBADO", "comentario": "Opcional al aprobar; obligatorio al rechazar" }
```

El token expira a las 48 horas y sólo puede consumirse una vez. Aprobar mueve la rendición a `EN_REVISION_TESORERIA`; rechazar la mueve a `RECHAZADA` y libera el presupuesto comprometido.

Al resolver correctamente, la respuesta incorpora `decision`, `aprobador_nombre` y `aprobador_cargo`. La vista reemplaza de inmediato el formulario por un estado final sin controles reutilizables.

### GET `/admin/reportes/comprobante_aprobacion_exceso.php?id={id}`

Requiere `rendiciones.view`. Genera en demanda un PDF imprimible únicamente cuando `decision_exceso = APROBADO`. Usa los snapshots históricos de nombre/cargo, la fecha de decisión, el resumen financiero y los comprobantes activos. El documento certifica la aprobación gerencial del exceso; no representa aprobación final ni pago de la rendición.

### GET `/admin/api/rendiciones/get_rendiciones.php`

Requiere `rendiciones.view`. Filtros: `estado`, `mes`, `vendedor_id`, `empresa_id`, `tipo`, `pagina`, `limite`.

### GET `/admin/api/rendiciones/get_dashboard_analitico.php`

Requiere `rendiciones.view`. Recibe `mes=YYYY-MM` y `ventana=6|12`. Consolida por `(empresa_id, vendedor_id)` presupuesto activo, monto aprobado, monto pendiente, rendiciones aprobadas/rechazadas, casos con exceso aprobado, ticket promedio y tendencia mensual. La respuesta incluye un resumen holding y `fondos_por_tipo`, comparación estandarizada entre `MENSUAL` y `GIRA` con cantidad de fondos, promedio, asignado, aprobado, pendiente, ejecución y excesos. No entrega nombres libres de giras y no considera rendiciones rechazadas como gasto ni como exceso efectivo.

### GET `/admin/api/rendiciones/get_detalle_rendicion.php?id={id}`

Requiere `rendiciones.view`. Devuelve cabecera, documentos con datos SII e historial inmutable.

### GET `/admin/api/rendiciones/buscar_vendedores.php`

Requiere `rendiciones.manage`. Consulta en modo de sólo lectura el catálogo `tbl_vendedores` de los ERP autorizados.

- Con `empresa_id`: retorna hasta 100 coincidencias de esa empresa. `busqueda` es opcional y filtra por nombre, correo o `cli_vendedor`.
- Sin `empresa_id`: retorna el directorio del holding homologado por `ven_mail`, incluyendo los códigos locales del vendedor en cada empresa.
- Gabtec usa internamente `ven_nombre`; la respuesta conserva el contrato común `vendedor_nombre`.

```json
{
  "success": true,
  "data": [{
    "empresa_id": 1,
    "empresa_nombre": "Automarco LTDA",
    "vendedor_id": 25,
    "vendedor_nombre": "Vendedor ERP",
    "vendedor_email": "vendedor@empresa.cl"
  }],
  "empresas": [{ "empresa_id": 1, "empresa_nombre": "Automarco LTDA" }],
  "alcance": "EMPRESA"
}
```

### POST `/admin/api/rendiciones/cambiar_estado.php`

Requiere `rendiciones.manage` y CSRF. Acciones aceptadas: `RECIBIR_FISICOS`, `APROBAR_TOTAL`, `APROBAR_PARCIAL`, `RECHAZAR`, `RECHAZAR_EXCESO_TESORERIA`, `MARCAR_PAGADA` y `REENVIAR_EXCESO`. Para `REENVIAR_EXCESO` se exige `aprobador_id`; el servidor carga el responsable activo, guarda snapshots y rota el Magic Token anterior. `RECHAZAR_EXCESO_TESORERIA` sólo opera desde `PENDIENTE_APROBACION_EXCESO`, exige motivo, libera íntegramente el compromiso e invalida cualquier enlace emitido sin enviar correo nuevo.

### GET|POST `/admin/api/rendiciones/gestion_aprobadores.php`

- `GET` requiere `rendiciones.manage` y devuelve los dos responsables activos para que Tesorería elija el destinatario.
- `POST` requiere `users.manage` y CSRF. Recibe exactamente dos elementos `{ orden, nombre, cargo, email }`, valida correos distintos y actualiza por posición sin eliminación física.

### GET|POST `/admin/api/rendiciones/gestion_presupuestos.php`

Requiere `rendiciones.manage`. `POST` admite `CREAR`, `ACTUALIZAR` y `DESACTIVAR`; no existe operación física de eliminación. En crear y actualizar, el servidor ignora cualquier nombre o correo aportado por el cliente y vuelve a resolver ambos campos mediante `(empresa_id, vendedor_id)` en el ERP correspondiente. Para `GIRA`, exige `nombre_gira` de 3–100 caracteres y fechas válidas; deriva `periodo_mes` desde `fecha_inicio`, por lo que no confía en un período mensual oculto enviado por el navegador.
