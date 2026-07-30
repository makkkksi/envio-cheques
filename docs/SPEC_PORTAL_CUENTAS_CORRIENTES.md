# Especificación Funcional y Técnica (SPEC)
## Módulo Gerencial de Cuentas Corrientes: Gestión, Distribución y Trazabilidad de Cheques

**Versión:** 2.0 (Final Aprobada e Implementada)  
**Audiencia:** Gerente/Supervisora de Cuentas Corrientes, Administradores del Sistema.  
**Estado:** ✅ Aprobado e Implementado en Producción/DEV.  

---

## 1. Propósito y Filosofía del Módulo

El **Módulo Gerencial de Cuentas Corrientes** es una herramienta de control y gestión diseñada para la Gerente/Supervisora del área. Su objetivo central es garantizar que la información de los cheques aprobados por Tesorería llegue de forma oportuna, limpia y trazable a las digitadoras responsables de ingresarla en el ERP Legado Optimus.

### Principios Directores:
1. **Cero Carga Extra para Digitadoras:** El sistema NO exige que las digitadoras marquen cada cheque individual como "digitado en Optimus", evitando sobrecargar su rutina.
2. **Flexibilidad Absoluta ante Ausencias:** Si una digitadora falta por enfermedad, licencia o vacaciones, la Gerente puede reasignar el correo de esa empresa en 5 segundos sin intervención técnica.
3. **Garantía de No Pérdida de Cheques (Cero Cheques Perdidos):** Cada envío por correo queda asentado en una bitácora inmutable. Si falla la red o el servidor SMTP, el sistema registra el fallo y permite el re-despacho en 1 clic.
4. **Liberación y Transición Estado Final:** Al despacharse el resumen acumulado a la hora configurada (o de forma manual), los cheques de la jornada transicionan a su estado final `DEPOSITADO` / `INGRESADO_OPTIMUS`, registrando su trazabilidad en `historial_estados`.

---

## 2. Arquitectura de Interfaz de Usuario (UI Standalone)

El módulo se encuentra disponible en su portal exclusivo **`admin/cuentas_corrientes.php`**, accesible únicamente para usuarios con rol `ADMINISTRADOR` o `SUPERVISORA_CC`.

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│  PORTAL ADMIN — MÓDULO GERENCIAL DE CUENTAS CORRIENTES                                 │
├────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                        │
│ 📌 SECCIÓN 1: MATRIZ DE ASIGNACIÓN DE DIGITADORAS (GESTIÓN DE LICENCIAS Y REEMPLAZOS)  │
│ ┌──────────────────────┬───────────────────────────────┬─────────────────────────────┐ │
│ │ Empresa              │ Digitadora Asignada (Email)   │ Acción                      │ │
│ ├──────────────────────┼───────────────────────────────┼─────────────────────────────┤ │
│ │ Automarco LTDA       │ digitadora1@app.local         │ [ ✏️ Reasignar Email ]      │ │
│ │ HD Automarco S.A     │ digitadora2@app.local         │ [ ✏️ Reasignar Email ]      │ │
│ │ Autotec S.A          │ digitadora3@app.local         │ [ ✏️ Reasignar Email ]      │ │
│ │ Gabtec S.A           │ digitadora4@app.local         │ [ ✏️ Reasignar Email ]      │ │
│ └──────────────────────┴───────────────────────────────┴─────────────────────────────┘ │
│                                                                                        │
│ 📊 SECCIÓN 2: CONTROL Y ESTADO DE LA JORNADA (CORTE 16:00 HRS)                        │
│ ┌────────────────────────────────────────────────────────────────────────────────────┐ │
│ │ Cheques Validados Hoy por Tesorería: 14 cheques ($12.450.000)                        │ │
│ │ Próximo Despacho Programado: Hoy a las 16:00 hrs (Quedan 3h 15m)                   │ │
│ │ [ ⚡ Despachar Resumen Ahora ]   [ 👁️ Previsualizar Correo HTML ]                   │ │
│ └────────────────────────────────────────────────────────────────────────────────────┘ │
│                                                                                        │
│ 📜 SECCIÓN 3: BITÁCORA HISTÓRICA DE ENVÍOS (TRAZABILIDAD DE INFORMES)                   │
│ ┌──────────────────┬──────────────┬────────────────────────┬─────────┬──────────────┐ │
│ │ Fecha/Hora       │ Empresa      │ Destinatario           │ Cheques │ Estado       │ │
│ ├──────────────────┼──────────────┼────────────────────────┼─────────┼──────────────┤ │
│ │ 30-07-2026 16:00 │ Automarco    │ digitadora1@app.local  │ 5 chqs  │ 🟢 ENVIADO   │ │
│ │ 30-07-2026 16:00 │ Gabtec S.A   │ digitadora4@app.local  │ 3 chqs  │ 🔴 FALLIDO   │ │
│ └──────────────────┴──────────────┴────────────────────────┴─────────┴──────────────┘ │
│   * Nota: Si un envío figura 🔴 FALLIDO, se muestra el botón [ 🔄 Re-enviar a... ]      │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Especificación Técnica de Datos y Backend

### 3.1 Modelo de Datos Relacionado
- **`empresas`**: Almacena `id`, `nombre`, `email_tesoreria_defecto` (que actúa como el email de la digitadora asignada).
- **`log_envios_informes`**: Registra `id`, `empresa_id`, `tipo_informe`, `destinatario`, `copia_cc`, `asunto`, `estado_envio` (`ENVIADO`/`FALLIDO`), `error_mensaje`, `cantidad_cobranzas`, `fecha_envio`.

### 3.2 Endpoints a Desarrollar (`admin/api/`)
1. **`get_gestion_cc.php`**: Retorna las asignaciones por empresa, las métricas del día y el historial de envíos de la bitácora.
2. **`guardar_asignacion_digitadora.php`**: Permite a la Gerente actualizar el email de una empresa de forma instantánea.
3. **`despachar_resumen_cc.php`**: Ejecuta el procesamiento de envío (vía Cron Job a las 16:00 hrs o por gatillo manual) y graba la bitácora.
4. **`reenviar_informe_cc.php`**: Re-procesa un envio fallido o permite reenviar un informe a una nueva dirección especificada por la Gerente.

---

## 4. Preguntas Estratégicas para la Definición (Sin Asumir Nada)

Para garantizar que el módulo sea 100% útil y evitar sobre-trabajo, es necesario validar los siguientes puntos con la Gerente de Cuentas Corrientes:

### ❓ Grupo A: Gestión de Ausencias y Reemplazos
1. **Q-A1:** Cuando una digitadora de una empresa falta (ej. Gabtec S.A), ¿los cheques de esa empresa los asume **otra digitadora específica** o la propia Supervisora prefiere recibirlos en su correo?
2. **Q-A2:** ¿Es suficiente con actualizar el correo de la empresa en la tabla de asignación, o se requiere poder asignar **múltiples correos receptores** por empresa (ej. Digitadora Principal + Digitadora de Respaldo)?

### ❓ Grupo B: Trazabilidad y Re-Envíos
3. **Q-B1:** Si el envío automático de las 16:00 hrs falla por algún motivo técnico, ¿prefieres que el sistema intente **re-enviar automáticamente 3 veces** cada 10 minutos, o que únicamente le alerte a la Supervisora en el portal para que ella presione "Re-enviar"?
4. **Q-B2:** ¿La Supervisora necesita ver la lista de cheques individuales incluidos dentro de un informe enviado al hacer clic sobre una fila de la bitácora?

### ❓ Grupo C: Horarios y Despacho
5. **Q-C1:** ¿El horario de las 16:00 hrs es fijo para todas las empresas de lunes a viernes, o hay días (como los viernes) en que el corte debe ser antes (ej. a las 14:00 o 15:00 hrs)?
