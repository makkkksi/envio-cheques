# Autotec E-Commerce Web — Guía de Instalación

## Estructura del Proyecto

```
autotec_web/
├── index.html              ← Aplicación principal (SPA responsiva)
├── api/
│   ├── config.php          ← Configuración DB y helpers
│   ├── auth.php            ← Login / logout / check sesión
│   ├── productos.php       ← Búsqueda de productos (aplicación, código, medidas)
│   └── carro.php           ← Carro de compras (agregar, confirmar, historial)
├── sql/
│   └── autotec_ecom_setup.sql  ← Script SQL a ejecutar UNA SOLA VEZ
└── assets/
    ├── css/                ← (para estilos adicionales si se necesitan)
    ├── js/                 ← (para scripts adicionales)
    └── img/productos/      ← Imágenes de productos (img_chica_nombre)
```

---

## 1. Base de Datos

La solución usa **una sola base de datos** (`autotec_ecom`) que ya contiene las tablas
de productos y clientes originales. Solo se agregan las tablas `web_*` nuevas.

```bash
mysql -u root -p autotec_ecom < sql/autotec_ecom_setup.sql
```

### Tablas nuevas creadas:
| Tabla | Descripción |
|-------|-------------|
| `web_usuarios` | Usuarios del sitio web (login) |
| `web_sesiones` | Tokens de sesión activos |
| `web_carro_cabecera` | Pedidos / carros de compra |
| `web_carro_detalle` | Items de cada pedido |
| `web_pedidos_log` | Historial de acciones |

### Tablas existentes que se CONSULTAN (sin modificar):
- `autotec_ecom.tbl_clientes`
- `autotec_ecom.tbl_productos`
- `autotec_ecom.tbl_productos_modelos`
- `autotec_ecom.tbl_modelos_marcas`
- `autotec_ecom.tbl_marcas_productos`
- `autotec_ecom.tbl_marcas`
- `autotec_ecom.tbl_clasificacion`
- `autotec_ecom.tbl_imagenes`
- `autotec_ecom.tbl_cilindrada`
- etc.

---

## 2. Configuración PHP

Editar `api/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_USER', 'tu_usuario_mysql');
define('DB_PASS', 'tu_password');
define('DB_NAME', 'autotec_ecom');
define('BASE_URL', 'https://tudominio.cl');
```

### Requisitos del servidor:
- PHP 7.4+ con extensión PDO y PDO_MySQL
- MySQL 5.7+ o MariaDB 10.3+
- Apache/Nginx con mod_rewrite (para URLs limpias)

---

## 3. Usuario administrador

El script SQL crea un usuario por defecto:
- **Usuario:** `admin`
- **Password:** `password`  ← ¡**CAMBIAR** inmediatamente!

Para cambiar el password:
```sql
UPDATE web_usuarios 
SET password = '$2y$10$NUEVO_HASH_AQUI' 
WHERE usuario = 'admin';
```

Generar hash en PHP:
```php
echo password_hash('tu_nueva_contraseña', PASSWORD_BCRYPT);
```

---

## 4. Imágenes de Productos

Las imágenes se sirven desde `assets/img/productos/`.
El nombre de archivo viene de `tbl_imagenes.img_chica_nombre`.

Copiar imágenes al directorio:
```bash
cp /ruta/original/imagenes/* autotec_web/assets/img/productos/
```

---

## 5. Funcionalidades del Sitio

### Búsquedas disponibles:
1. **Por Aplicación** — Categoría → Marca Vehículo → Modelo → Año
2. **Por Código/OEM** — Busca en prod_id, prod_nombre, codigo_equivalente1
3. **Por Marca de Vehículo** — Filtra todos los productos de una marca
4. **Por Medidas** — Para embragues, correas, etc.

### Carro de Compras:
- Agregar productos con cantidad
- Ver totales (neto + IVA + total)
- Confirmar pedido con OC y observaciones
- El pedido queda en `web_carro_cabecera` con estado `enviado`

### Seguridad:
- Autenticación por token (cookie segura HttpOnly)
- Sesiones con TTL de 8 horas
- Contraseñas hasheadas con bcrypt
- Consultas con PDO prepared statements (sin SQL injection)

---

## 6. Integración con Sistema Android

Los pedidos confirmados en el sitio web quedan en `web_carro_cabecera/detalle`.
Para integrarlos al sistema Android/servidor, se puede crear un endpoint adicional
que mueva los pedidos a `tbl_cabecera` / `tbl_detalle` con la misma estructura.

Ejemplo de integración:
```sql
-- Pasar pedido web a tablas nativas
INSERT INTO tbl_cabecera (emp_id, cli_rut, cli_sec, pedi_total_neto, ...)
SELECT emp_id, cli_rut, cli_sec, pedi_total_neto, ...
FROM web_carro_cabecera WHERE pedi_id = ? AND pedi_estado = 'enviado';
```

---

## 7. Deploy Rápido (Apache)

```apache
<VirtualHost *:443>
    ServerName tudominio.cl
    DocumentRoot /var/www/autotec_web
    
    <Directory /var/www/autotec_web>
        AllowOverride All
        Require all granted
    </Directory>
    
    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/tudominio.crt
    SSLCertificateKeyFile /etc/ssl/private/tudominio.key
</VirtualHost>
```

Archivo `.htaccess` en la raíz:
```apache
Options -Indexes
Header always set X-Frame-Options "SAMEORIGIN"
Header always set X-Content-Type-Options "nosniff"

<FilesMatch "\.php$">
    # Solo permitir acceso a archivos en /api/
</FilesMatch>
```
