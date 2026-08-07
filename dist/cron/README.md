# Despliegue de Cron Jobs en Amazon AWS (Producción)

En entornos de producción sobre Amazon EC2 (Amazon Linux 2, Ubuntu, etc.), la automatización de procesos se configura directamente a nivel de sistema operativo utilizando la herramienta `crontab`.

Este proyecto requiere **dos (2)** cron jobs para operar correctamente.

## 1. Identificar la Ruta Absoluta del Proyecto

Antes de configurar, debes conocer la ruta absoluta donde clonaste o subiste el proyecto web. Por lo general en un servidor web Apache/Nginx típico suele ser:
- `/var/www/html/form/`
- `/home/ec2-user/public_html/form/` (Si usas un virtual host)
- `/home/ubuntu/form/`

> [!IMPORTANT]  
> Asegúrate de reemplazar `/var/www/html/form/` en los comandos a continuación por la ruta real de tu instancia EC2.

## 2. Configurar el Crontab

Conéctate a tu instancia EC2 vía SSH:
```bash
ssh -i "tu-llave.pem" ec2-user@tu-ip-elastica.amazonaws.com
```

Abre el editor de tareas programadas del usuario que corre el servidor web (usualmente `www-data` o `apache`, pero si no hay problemas de permisos puedes usar el tuyo):
```bash
sudo crontab -u www-data -e
```
*(Si prefieres hacerlo con tu usuario actual, solo ejecuta `crontab -e`)*

Al final del archivo que se abre (usualmente en `nano` o `vi`), pega las siguientes líneas:

### A) Despacho Automático de Cuentas Corrientes (Fase 7)
Se ejecuta en el **minuto 0 de cada hora**. El script internamente revisa la BD para ver si el despacho automático está "Activado" en el panel y si la hora actual coincide con la "Hora de Corte" seleccionada por la supervisora.

```bash
0 * * * * /usr/bin/php /var/www/html/form/cron/resumen_diario_cuentas_corrientes.php >> /var/www/html/form/cron/logs_cc.log 2>&1
```

### B) Motor de Alertas por Demora (Fase 4)
Se ejecuta **una vez al día** (por ejemplo, a las 08:00 AM). Escanea las cobranzas en tránsito y alerta a los vendedores si superan los días máximos permitidos configurados.

```bash
0 8 * * * /usr/bin/php /var/www/html/form/cron/check_alertas.php >> /var/www/html/form/cron/logs_alertas.log 2>&1
```

Guarda y cierra el archivo (`CTRL+O`, `Enter`, `CTRL+X` en nano, o `:wq` en vi).

## 3. Logs y Monitoreo (Troubleshooting)

Como ves en los comandos de arriba, le indicamos a Linux que escriba la salida de los scripts en archivos locales:
- `cron/logs_cc.log`
- `cron/logs_alertas.log`

Si algo no se está enviando, puedes leer el log en vivo en el servidor AWS:
```bash
tail -f /var/www/html/form/cron/logs_cc.log
```

> [!TIP]
> Los archivos de PHP (`check_alertas.php` y `resumen_diario_cuentas_corrientes.php`) usan **rutas dinámicas seguras** (`__DIR__`). No importa desde qué carpeta ejecutes el comando en la terminal, siempre encontrarán la base de datos y las librerías correctamente.
