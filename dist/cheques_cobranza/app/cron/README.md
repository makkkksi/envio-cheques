# Guía de Despliegue de Cron Jobs en Servidor Linux (Producción)

En entornos de producción sobre servidores Linux / Apache (AWS EC2, Servidor Dedicado o Hosting cPanel), la automatización de procesos se configura directamente a nivel de sistema operativo utilizando la herramienta `crontab` o mediante WebCron protegido por token.

Este módulo cuenta con **tres (3)** procesos programados independientes:

---

## 1. Identificar la Ruta Física de Producción

En el servidor de producción de Holding Automarco, la ruta física es:
```text
/var/www/html/autotec/cobranza_cheques/app/
```

Los scripts se encuentran en:
- `/var/www/html/autotec/cobranza_cheques/app/cron/resumen_diario_cuentas_corrientes.php`
- `/var/www/html/autotec/cobranza_cheques/app/cron/check_alertas.php`
- `/var/www/html/autotec/cobranza_cheques/app/cron/purgar_fotos_cheques_vencidos.php`

---

## 2. Configuración en Crontab Linux (Recomendado)

Conéctate al servidor vía SSH y edita el crontab del usuario web (`www-data` o `apache`):

```bash
sudo crontab -u www-data -e
```
*(O directamente `crontab -e` con tu usuario de despliegue)*

Agrega las siguientes líneas al final del archivo:

```bash
# -----------------------------------------------------------------------------------------
# MÓDULO COBRANZAS: Tareas Programadas Automáticas
# -----------------------------------------------------------------------------------------

# A) Despacho Automático a Cuentas Corrientes (Evalúa cada 15 minutos de Lun a Vie de 08:00 a 19:00 hrs)
#    Verifica si el interruptor está ACTIVO y si ya se alcanzó la Hora de Corte configurada en BD.
*/15 8-19 * * 1-5 /usr/bin/php /var/www/html/autotec/cobranza_cheques/app/cron/resumen_diario_cuentas_corrientes.php >> /var/www/html/autotec/cobranza_cheques/app/logs/cron_despacho_cc.log 2>&1

# B) Motor de Alertas por Demora (Se ejecuta a las 08:00 AM de Lun a Sáb)
#    Detecta cobranzas que superan los días máximos permitidos y envía alerta a Vendedor y CC.
0 8 * * 1-6 /usr/bin/php /var/www/html/autotec/cobranza_cheques/app/cron/check_alertas.php >> /var/www/html/autotec/cobranza_cheques/app/logs/cron_alertas.log 2>&1

# C) Purga Segura de Fotos de Cheques Vencidos (>3 Meses) (Se ejecuta semanalmente los Domingos a las 03:00 AM)
#    Elimina archivos físicos de cheques/comprobantes vencidos >3 meses manteniendo intactos los datos en BD.
0 3 * * 0 /usr/bin/php /var/www/html/autotec/cobranza_cheques/app/cron/purgar_fotos_cheques_vencidos.php >> /var/www/html/autotec/cobranza_cheques/app/logs/cron_purga_fotos.log 2>&1
```

---

## 3. Configuración Alternativa vía WebCron (HTTP)

Si tu proveedor de hosting no permite acceso a CLI o prefieres usar un servicio externo (ej. EasyCron, UptimeRobot, cron-job.org), todos los scripts aceptan ejecución HTTP enviando el token secreto configurado en `config/app.php` (`CRON_SECRET_KEY`):

* **Despacho Cuentas Corrientes:**
  ```text
  GET https://www.autotec.cl/cobranza_cheques/app/cron/resumen_diario_cuentas_corrientes.php?cron_token=cobranzas_cron_secret_2026
  ```

* **Alertas por Días Transcurridos:**
  ```text
  GET https://www.autotec.cl/cobranza_cheques/app/cron/check_alertas.php?cron_token=cobranzas_cron_secret_2026
  ```

* **Purga Segura de Fotos Vencidas (>3 Meses):**
  ```text
  GET https://www.autotec.cl/cobranza_cheques/app/cron/purgar_fotos_cheques_vencidos.php?cron_token=cobranzas_cron_secret_2026
  ```
  *(Para ejecutar en modo simulación sin borrar nada: agregar `&dry_run=1`)*

> [!WARNING]
> Peticiones HTTP sin el parámetro `cron_token` válido son rechazadas inmediatamente con código `403 Forbidden`.

---

## 4. Permisos de Seguridad del Directorio

Asegúrate de que los scripts y directorios tengan los permisos adecuados:

```bash
# Asignar permisos de lectura y ejecución para el servidor web
chmod 750 /var/www/html/autotec/cobranza_cheques/app/cron/*.php

# Asegurar que el directorio de logs tenga permisos de escritura
mkdir -p /var/www/html/autotec/cobranza_cheques/app/logs
chmod 775 /var/www/html/autotec/cobranza_cheques/app/logs
```

---

## 5. Monitoreo y Troubleshooting en Tiempo Real

Para inspeccionar la ejecución de los crons en vivo desde la terminal del servidor:

```bash
# Monitorear log de despacho a Cuentas Corrientes:
tail -f /var/www/html/autotec/cobranza_cheques/app/logs/cron_despacho_cc.log

# Monitorear log de alertas de demora:
tail -f /var/www/html/autotec/cobranza_cheques/app/logs/cron_alertas.log

# Monitorear log de purga de fotos de cheques vencidos:
tail -f /var/www/html/autotec/cobranza_cheques/app/logs/cron_purga_fotos.log
```


