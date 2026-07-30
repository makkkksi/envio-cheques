# Memoria y Resumen Ejecutivo para Informe de Práctica Profesional

**Proyecto:** Sistema Web y Móvil de Gestión, Digitalización y Trazabilidad de Cobranzas por Cheques  
**Autor:** Alumno de Práctica Profesional  
**Organización:** Holding Automarco (Automarco LTDA, HD Automarco S.A, Autotec S.A, Gabtec S.A)  

---

## 1. Introducción y Contexto del Proyecto

El proyecto surge ante la necesidad de modernizar y dar trazabilidad al proceso de cobranzas por medio de cheques en el holding de empresas Automarco. Tradicionalmente, los vendedores en terreno cobraban facturas recibiendo cheques físicos, llenaban un talonario de papel y enviaban los documentos a las oficinas centrales.

Durante el desarrollo de la práctica, **el alumno asumió un rol proactivo y autodidacta de liderazgo**, realizando levantamientos de información directamente con las áreas usuarias (Vendedores, Tesorería y Cuentas Corrientes) para rediseñar un flujo digital que se adaptara a la realidad operativa de la empresa.

---

## 2. Levantamiento de Información y Trabajo de Campo (El Descubrimiento del Flujo Real)

Lejos de limitar el proyecto a una implementación puramente teórica, se realizaron reuniones presenciales y entrevistas con los distintos actores clave:

1. **Reunión con Tesorería:** Se descubrió que Tesorería recibía los sobres físicos y dedicaba horas a re-escribir manualmente cada dato en planillas Excel, además de atender llamadas constantes de vendedores preguntando si sus cheques habían llegado.
2. **Reunión con Cuentas Corrientes:** Se identificó un procedimiento físico arraigado: una funcionaria subía todos los días a las 16:00 hrs a las oficinas de Tesorería a buscar las copias en papel del talonario.
3. **Descubrimiento del ERP Legado (Optimus del año 2000):** Se descubrió que el ingreso final de los pagos se realiza en un ERP de más de 20 años de antigüedad (Optimus), operado por digitadoras especializadas asignadas por cada empresa del holding.

---

## 3. Principales Problemáticas Identificadas y Soluciones Implementadas

### 🔴 Problemática 1: Resistencia al Cambio por Parte de los Vendedores
* **El Problema:** La mayoría de los vendedores son personas con años de trayectoria, acostumbradas a la velocidad del talonario de papel y reacias a utilizar tecnologías complejas en terreno.
* **La Solución Pragmática:** 
  * Se diseñó el selector web/móvil inspirándose directamente en el formato de su talonario físico.
  * El sistema autocompleta el 90% de los datos al seleccionar el cliente y las facturas.
  * Se implementó la generación automática de un **Recibo Digital en PDF**, permitiendo al vendedor enviarlo instantáneamente por WhatsApp al cliente, dándole un beneficio directo que reemplaza la copia física en papel.

---

### 🔴 Problemática 2: Triple Digitación Manual y Sobrecarga en Tesorería
* **El Problema:** La información se escribía 3 veces: Vendedor (papel) ➔ Tesorería (Excel manual) ➔ Cuentas Corrientes (Optimus ERP).
* **La Solución Pragmática:**
  * Se eliminó el uso de la planilla Excel en Tesorería, reemplazándola por el **Portal Web Admin** (`/form/admin`).
  * Como el vendedor ya precarga los datos, Tesorería solo verifica la concordancia contra el cheque físico en mano y presiona en 1 segundo el botón verde **`✓ Validar - Enviar Cuentas Corrientes`**.
  * Si hay errores menores del vendedor (ej. banco o fecha), Tesorería los corrige de inmediato en el portal (`editar_cheques.php`), evitando devolver el trámite.

---

### 🔴 Problemática 3: Respeto al Rol Operativo y Protección del Empleo (Optimus ERP)
* **El Problema:** La posibilidad de desarrollar una integración automática hacia el ERP Optimus amenazaba la percepción de estabilidad laboral de las digitadoras de Cuentas Corrientes.
* **La Solución Pragmática:**
  * Se tomó la decisión consciente de **no eliminar el trabajo de las digitadoras**, manteniendo su labor de ingreso manual a Optimus.
  * En su lugar, el sistema les entrega la información perfectamente limpia, estructurada y filtrada por empresa en el portal y en su correo electrónico para facilitar su tipeo sin errores.

---

### 🔴 Problemática 4: Eliminación del Viaje Físico de las 16:00 hrs y Control Gerencial de Liberación
* **El Problema:** Una funcionaria de Cuentas Corrientes debía interrumpir su trabajo todos los días a las 16:00 hrs para desplazarse a buscar los papeles físicos en Tesorería.
* **La Solución Pragmática:**
  * Se diseñó el **Portal Exclusivo de Cuentas Corrientes** (`admin/cuentas_corrientes.php`) y la **Automatización del Resumen Diario**.
  * La Gerencia de Cuentas Corrientes configura la hora de corte diario (ej. 16:00 hrs) y administra la matriz de digitadoras asignadas por empresa (permitiendo reasignaciones por licencias médicas o vacaciones).
  * A la hora de corte o al hacer clic en **`⚡ Despachar Resumen Ahora`**, el sistema agrupa los cheques validados por Tesorería, envía los correos en HTML Limpio por empresa a las digitadoras (con CC a la Supervisora) y actualiza el estado final de las cobranzas a `DEPOSITADO` / `INGRESADO_OPTIMUS`, registrando la traza de auditoría en `historial_estados` y `log_envios_informes`.
  * Se implementó una **Regla Anti-Spam**: Si una empresa no tuvo cobranzas en el día, se omite el envío del correo.

---

### 🔴 Problemática 5: Garantía de No Pérdida de Cheques por Fallas Técnicas (Caídas de Red/Servidor)
* **El Problema:** Existía el riesgo de que si el servidor SMTP fallaba o se caía la conexión de red durante el envío del correo de las 16:00 hrs, los cheques o informes se perdieran.
* **La Solución Pragmática:**
  * Se creó la tabla de auditoría inmutable **`log_envios_informes`**.
  * Cada notificación enviada o fallida queda grabada con su motivo exacto.
  * Se disponibilizó en el Portal Admin un botón de **Re-intento en 1 Clic** para la Supervisora, garantizando que **cero cheques o cobranzas se traspapelen**.

---

### 🔴 Problemática 6: Colisión de IDs de Vendedores entre Múltiples Empresas del Holding
* **El Problema:** Cada una de las 4 empresas del holding (Automarco LTDA, HD Automarco, Autotec, Gabtec) posee su propio ERP con autoincrementales independientes. Un mismo vendedor tenía distintos IDs numéricos en cada sistema.
* **La Solución Pragmática:**
  * Se estableció el correo electrónico institucional del vendedor (`ven_mail`) como clave única de homologación universal.
  * El backend (`api/get_clientes.php`) realiza una unificación dinámica que permite al vendedor ver todos sus clientes y facturas impagas de las 4 razones sociales en una sola pantalla.

---

### 🔴 Problemática 7: Complejidad en Facturas con Cuotas y Discrepancias de Monto
* **El Problema:** Las facturas con pagos parciales/cuotas generaban confusión visual y desalineamiento en la tabla, además de que los cheques adjuntos a veces no coincidían con el monto de la factura.
* **La Solución Pragmática:**
  * Se implementó un selector jerárquico en 3 niveles: **Empresa ➔ Documento ➔ Cuotas** mediante un sistema de acordeón expandible in-line y alineación vertical estricta mediante CSS Grid.
  * Se incorporó una alerta visual de alto impacto basada en el **Principio de Von Restorff** (`discrepancy-callout`), que advierte a Tesorería en color ámbar/rojo si el total de los cheques no coincide exactamente con el monto de las facturas abonadas, incluyendo la justificación escrita por el vendedor.

---

### 🔴 Problemática 8: Prevención de Errores en Producción por Esquema Incompleto (Schema Drift)
* **El Problema:** Al agregar funciones de seguridad (Rate Limiting y Auditoría) en etapas intermedias, algunas tablas podían quedar fuera del script de instalación base `setup.sql`, provocando errores 500 en producción.
* **La Solución Pragmática:**
  * Se desarrolló un script de verificación automatizada: **`scratch/verify_schema_integrity.php`**.
  * Este script escanea todo el código fuente en PHP buscando consultas SQL y valida en 100% que las tablas existan en MySQL y en `setup.sql` antes de realizar cualquier despliegue.

---

## 4. Matriz Resumen de Desafíos y Soluciones

| # | Desafío Encontrado | Causa Raíz | Solución Pragmática Implementada |
|---|--------------------|------------|----------------------------------|
| 1 | Resistencia al cambio del vendedor | Hábito con el talonario físico | UI idéntica al talonario + PDF automático para WhatsApp |
| 2 | Triple digitación manual | Uso de planillas Excel intermedias | Portal Web Admin que elimina Excel y valida en 1 Clic |
| 3 | Miedo al reemplazo laboral en C.Corrientes | Mantenimiento de ERP Optimus (año 2000) | Preservación del trabajo de digitación con datos limpios |
| 4 | Pérdida de tiempo en viaje de las 16:00 hrs | Traslado físico a buscar papeles | Envíos automáticos por correo a las 16:00 hrs por empresa |
| 5 | Riesgo de pérdida de cheques por fallas | Caídas de servidor o SMTP | Bitácora `log_envios_informes` con reintento en 1 Clic |
| 6 | Colisión de IDs de Vendedor | 4 ERPs independientes en el holding | Homologación universal por email (`ven_mail`) |
| 7 | Manejo de Cuotas y Discrepancias | Pagos parciales y diferencias de monto | Acordeón CSS Grid de 3 niveles + Alerta Von Restorff |
| 8 | Errores en Producción por tablas faltantes | Desalineación de esquema (Schema Drift) | Script verificador de integridad (`verify_schema_integrity.php`) |

---

## 5. Conclusión y Aporte Profesional de la Práctica

A través de un enfoque autónomo, investigativo y pragmático, la práctica profesional no solo consistió en la escritura de código, sino en la **reingeniería de un proceso operativo real**. Al escuchar activamente las necesidades de Vendedores, Tesorería y Cuentas Corrientes, se logró transformar un sistema que corría el riesgo de ser percibido como "un trámite molesto" en una **herramienta indispensable de ahorro de tiempo y trazabilidad** para la empresa.
