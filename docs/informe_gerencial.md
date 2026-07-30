# INFORME GERENCIAL DE PROYECTO: SISTEMA DE TRAZABILIDAD Y DIGITALIZACIÓN DE COBRANZAS DE CHEQUES

**Para:** Gerencia de Administración, Finanzas y Operaciones  
**De:** Departamento de Desarrollo de Sistemas  
**Holding:** Automarco (Automarco LTDA, HD Automarco S.A, Autotec S.A, Gabtec S.A)  
**Fecha:** Julio de 2026  
**Documento:** Resumen de Estado del Arte, Flujos, Seguridad, Operaciones y Plan de Hardening  

---

## 1. Resumen Ejecutivo

Este proyecto fue desarrollado con el objetivo de modernizar, centralizar y asegurar el ciclo de cobranzas por cheques del holding de empresas Automarco. Tradicionalmente, este proceso presentaba una alta ineficiencia operativa (triple digitación manual, llamadas telefónicas constantes para confirmación de recepción y traslado físico de talonarios en papel a las 16:00 hrs). 

La solución implementada consiste en un **Sistema Web y Móvil de Gestión, Digitalización y Trazabilidad de Cobranzas**, que enlaza al vendedor en terreno con Tesorería y Cuentas Corrientes. Este informe recopila el flujo de trabajo real implementado, la arquitectura de base de datos, el análisis crítico de puntos de falla y el estado del roadmap hacia producción.

---

## 2. El Flujo de Trabajo Implementado (Cadena de Custodia Digital)

El sistema opera bajo un flujo de estados secuencial y atómico que asegura que ningún cheque se pierda y que cada actor conozca su responsabilidad en tiempo real:

```
[1. VENDEDOR en Terreno]
   │  * Registra cobranza en la App seleccionando el Cliente y marcando facturas.
   │  * Adjunta foto de los cheques y del comprobante de despacho (Chilexpress/OT).
   │  * Estado Inicial: PENDIENTE_ENVIO / EN_TRANSITO.
   ▼
[2. TESORERÍA (Validación Física en Mano)]
   │  * Recibe sobre físico de Chilexpress y verifica el cheque físico contra la pantalla.
   │  * Si todo coincide: Clic en "✓ Validar - Enviar Cuentas Corrientes". (Estado: RECIBIDO_TESORERIA).
   │    -> Se notifica al vendedor por correo y entra a la cola de Cuentas Corrientes.
   │  * Si hay error: Clic en "Rechazar" con motivo obligatorio. (Estado: RECHAZADO).
   │    -> Notificación inmediata al vendedor con el motivo y registro en historial.
   ▼
[3. PORTAL GERENCIAL DE CUENTAS CORRIENTES (Liberación Manual/Automatizada)]
   │  * La Supervisora administra la matriz de digitadoras por empresa y hora de corte.
   │  * Al presionar "⚡ Despachar Resumen Ahora" (o a la hora de corte configurada):
   │    -> Agrupa los cheques validados por empresa de origen.
   │    -> Despacha correo HTML limpio a las digitadoras (con CC a la Supervisora).
   │    -> Transiciona el estado de las cobranzas a DEPOSITADO.
   │    -> Registra auditoría en `log_envios_informes` e `historial_estados`.
   ▼
[4. DIGITADORAS DE CUENTAS CORRIENTES]
   │  * Reciben el email y digitan en Optimus ERP cuando se liberan de su cola cotidiana.
```

---

## 3. Arquitectura y Componentes Técnicos

El sistema se construyó bajo una arquitectura **B2B Desktop-First / Mobile-Responsive** ligera y ágil:

* **Backend:** PHP Puro con PDO (sin dependencias de frameworks masivos para garantizar compatibilidad a largo plazo con servidores de hosting compartidos).
* **Frontend:** HTML5, CSS3 Vanilla estructurado y Javascript Vanilla (cero frameworks como React o Vue, lo que garantiza velocidad máxima de carga).
* **Base de Datos:** MySQL. Conexión de doble instancia:
  * `bd_modulo_cobranzas` (Base de datos central del sistema que almacena cabeceras, cheques, historial de estados e logs de auditoría).
  * `bd_automarco` / ERPs (Conexión de solo lectura para jalar carteras de clientes y facturas vivas en tiempo real).

### Estructura de la Base de Datos Central
Las tablas del sistema central se estructuran de la siguiente manera para garantizar consistencia:
* `cobranzas`: Registra cabecera de la cobranza, vendedor, cliente, tipo de despacho y estado actual.
* `cobranza_facturas`: Tabla pivot que soporta que una cobranza (un cheque) liquide múltiples facturas/cuotas cruzadas de distintas razones sociales.
* `cheques`: Datos de cheques individuales (número, banco, monto, vencimiento, URL de imagen, papeleta de depósito).
* `historial_estados`: Historial inmutable de auditoría por cada transición de estado de un cheque.
* `log_envios_informes`: Bitácora inmutable de despachos de correo para Cuentas Corrientes.
* `configuraciones_sistema`: Parámetros operativos modificables como la hora de corte diario.

---

## 4. Reglas del Resumen Diario y Gestión de Digitadoras

Para el correcto funcionamiento del Portal de Cuentas Corrientes, se estructuraron las siguientes reglas operativas y lógicas de datos:

### 4.1 Principios Directores del Portal C.CC.
1. **Cero Carga Extra para Digitadoras:** El sistema no obliga a las digitadoras a marcar cada cheque como "ingresado" en un portal web. Ellas simplemente reciben la información estructurada y siguen su flujo cotidiano de ingreso a Optimus ERP.
2. **Flexibilidad ante Reemplazos/Ausencias:** Si una digitadora se ausenta por enfermedad o vacaciones, la supervisora puede cambiar el correo de destino de la empresa en 5 segundos desde el panel de administración, redirigiendo los flujos de trabajo sin intervención del área de TI.
3. **Garantía Anti-Pérdida:** Toda transacción queda registrada. Si hay fallas en la red o servidor SMTP, el sistema registra el estado `FALLIDO` y el motivo, habilitando un botón de reenvío inmediato en la interfaz.

### 4.2 Matriz de Digitadoras (Simulación y Producción)
En el entorno de desarrollo y pruebas se configuran direcciones simuladas en la tabla `empresas` que se mapearán a los correos corporativos en producción:

| ID | Empresa | Base de Datos ERP | Correo Digitadora Asignada (DEV) |
|----|---------|-------------------|----------------------------------|
| 1  | Automarco LTDA | `automarc_automarco` | `digitadora1@app.local` |
| 2  | HD Automarco S.A | `autohd_automarcohd` | `digitadora2@app.local` |
| 3  | Autotec S.A | `autotec_ecom` | `digitadora3@app.local` |
| 4  | Gabtec S.A | `gabteccl_sitbdd1978` | `digitadora4@app.local` |

### 4.3 Formato del Correo HTML Consolidado
El correo que reciben las digitadoras cumple con especificaciones de lectura ágil y limpia para agilizar la transcripción manual:
* **Estructura HTML Limpia:** Sin adjuntos pesados, optimizado para Outlook y clientes de correo corporativos.
* **Información Detallada:** Contiene la Empresa, Datos del Cliente (RUT y Razón Social), Vendedor, Detalle de Cheques (Banco, N° Cheque, Monto, Fecha de Vencimiento) y las Facturas/Cuotas Abonadas con sus montos.
* **Hipervínculo Directo:** Incluye un enlace directo al Portal Admin (`admin/index.php?id=XXX`) para ver las imágenes de los cheques físicos y comprobantes de depósito en alta resolución con herramientas de rotación y zoom.

### 4.4 Seguridad en el Programador de Tareas (Cron CLI)
El script automático de despacho (`cron/resumen_diario_cuentas_corrientes.php`) implementa tres capas cruciales de control técnico:
* **Opción Activable (Desactivada por Defecto):** A requerimiento operativo, el envío automático por cron job se ha configurado como una característica *opcional e inactiva por defecto* (controlada mediante la constante `AUTO_DISPATCH_ENABLED` en `config/app.php`). Por consiguiente, la plataforma opera prioritariamente bajo el modelo de liberación manual controlada por la Supervisora en el portal.
* **Idempotencia por Base de Datos (Prevención de Duplicados):** Si el cron job del servidor se ejecuta cada minuto (cuando está activado), el sistema valida en `log_envios_informes` que no se haya enviado un correo exitoso (`ENVIADO`) para esa empresa el día de hoy. De existir, aborta el proceso inmediatamente, garantizando que las digitadoras no reciban correos duplicados.
* **Sincronización Dinámica de Timezone:** Para prevenir desfases horarios entre el servidor de base de datos y el servidor web (especialmente en comparaciones de fecha de corte), el script CLI obtiene el offset de PHP (Santiago de Chile) y sincroniza dinámicamente la sesión de base de datos:
  ```sql
  SET time_zone = '-04:00'; -- Offset dinámico según horario de invierno/verano
  ```

---

## 5. Problemáticas Operativas Resueltas

Durante el levantamiento de información y la etapa de desarrollo se detectaron y resolvieron los siguientes retos del negocio:

1. **La Cobranza Cruzada (Cross-Company):** Un solo cheque físico frecuentemente amortiza facturas de distintas razones sociales (ej. Automarco y Autotec).
   * *Solución:* El sistema define una **Empresa Primaria** (derivada de la primera factura del listado) donde el cheque se depositará e ingresará contablemente. El correo a la digitadora incluye el listado completo para que pueda realizar el traspaso/cruce contable interno en el ERP.
2. **Resistencia al Cambio del Vendedor:** Vendedores acostumbrados a la rapidez del talonario papel.
   * *Solución:* Interfaz ultra-simplificada que autocompleta el 90% de los datos y genera un **Recibo Digital en PDF** para enviarlo de inmediato por WhatsApp al cliente.
3. **Pérdida de Tiempo en Viajes Físicos:** Cuentas Corrientes subía todos los días a las 16:00 hrs a buscar los papeles.
   * *Solución:* Distribución digital automatizada de datos a los correos de las digitadoras a la hora de corte, eliminando traslados.

---

## 6. Análisis de Puntos de Falla Críticos (Riesgos y Soluciones)

Se presenta un análisis interno de riesgos potenciales a monitorear durante el despliegue a producción:

### Falla 1: Falsos Positivos de Ingreso en ERP (El "Rebote" de Optimus)
* **Riesgo:** Cuentas Corrientes libera el lote y el estado pasa automáticamente a `DEPOSITADO` / `INGRESADO_OPTIMUS`. Sin embargo, la digitadora puede encontrar un error al tipear en el ERP físico (ej: factura cerrada a última hora). El sistema asumirá éxito mientras que en el ERP no estará ingresado.
* **Mitigación (Roadmap):** Introducir un estado temporal `EN_PROCESO_DIGITACION` y permitir que la digitadora confirme con un enlace de éxito o reporte un rebote, lo que reversaría el flujo hacia Tesorería/Vendedor.

### Falla 2: Dependencia Absoluta del Correo Electrónico
* **Riesgo:** Si el servidor de correos falla o los resúmenes entran en la bandeja de SPAM, la digitadora no verá su carga de trabajo.
* **Mitigación (Roadmap):** Construir una vista simple de solo lectura en el portal de administración para que cada digitadora vea su cola de cheques asignada de forma directa mediante base de datos, usando el correo solo como una notificación opcional.

### Falla 3: Errores Humanos en la Validación de Tesorería
* **Riesgo:** Fatiga visual de Tesorería al aprobar montos erróneos digitados por el vendedor.
* **Mitigación (Roadmap):** Forzar validación a ciegas donde Tesorería debe digitar obligatoriamente el monto físico en mano y el sistema arroja alerta si no coincide con lo ingresado por el vendedor.

### Falla 4: Concurrencia en Despachos Simultáneos
* **Riesgo:** Si la Supervisora de CC presiona el botón manual exactamente al mismo segundo en que corre el Cron Job automático de las 16:00, podrían duplicarse los correos o generarse inconsistencias de estados.
* **Mitigación (Roadmap):** Implementar bloqueos transaccionales duros (`SELECT ... FOR UPDATE`) en MySQL al procesar el lote de cheques validados, garantizando que un único proceso tome el control de forma atómica.

---

## 7. Sistema de Reportería y Trazabilidad de Auditoría

El portal de Cuentas Corrientes cuenta con un módulo de reportería y auditoría robusto enfocado en el control interno:

* **Bitácora de Envíos (`log_envios_informes`):** Registra cada lote despachado con fecha, empresa, destinatario, cantidad de cobranzas y estado de red (🟢 ENVIADO / 🔴 FALLIDO).
* **Botón de Reenvío Inteligente:** Si un correo falla, la supervisora puede forzar el reenvío inmediato con un solo clic e incluso especificar un correo alternativo de respaldo en caliente.
* **Bitácora de Historial (`historial_estados`):** Cada vez que un cheque cambia de estado, se guarda el usuario responsable (rut/id), marca de tiempo, el estado anterior, el nuevo estado y el comentario/motivo.
* **Consola de Control de Jornada:** Muestra en tiempo real la cantidad de cheques validados por Tesorería acumulados que esperan por liberación.

---

## 8. Próximos Pasos (Preparación Go-Live)

Para iniciar la puesta en marcha definitiva de la aplicación, el Roadmap establece las siguientes actividades de seguridad y despliegue técnico:

1. **Protección del Directorio de Subidas (`uploads/`):** Implementar archivo `.htaccess` con directivas que inhabiliten la ejecución de código (ej: PHP) en la carpeta de imágenes para prevenir inyección de malware.
2. **Validación de Identidad del Vendedor (Identity Hardening):** Validar que las peticiones a la base de datos de carteras de clientes estén firmadas y correspondan únicamente a las carteras asignadas al vendedor en sesión.
3. **Paso a Entorno Productivo:** Cambiar la variable global `APP_ENV` a `'production'` para remover accesos bypass de depuración local, forzar protocolo seguro HTTPS y aplicar contraseñas de alta entropía.
