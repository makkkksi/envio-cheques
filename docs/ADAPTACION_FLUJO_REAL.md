# Adaptación del Sistema a la Realidad Operativa y Flujo de Trabajo

## 1. Definición del Flujo Operativo Consolidado

A partir del análisis operativo y de las decisiones de negocio, el flujo oficial del sistema se define en la siguiente cadena de custodia:

```
[Vendedor en Terreno]
   │  1. Registra cobranza en la App (el vendedor precarga el 90% de los datos).
   │  2. Opcional: Genera/Comparte Recibo Digital en PDF con el cliente.
   ▼
[Despacho / Tránsito Física]
   │  El cheque físico viaja a Tesorería en sobre.
   ▼
[Tesorería en Portal Admin (Validación Física)]
   │  1. Recibe el sobre físico y verifica el cheque en mano (Físico > Foto).
   │  2. Si hay errores menores: Edita y completa los datos directamente en el portal.
   │  3. Si hay discrepancia grave de monto: Rechaza con motivo obligatorio (vendedor es notificado).
   │  4. Si todo está correcto: Presiona "Confirmar Recepción" en 1 Clic ───(Notifica a Cuentas Corrientes)──┐
   ▼                                                                                                 │
[Estado: RECIBIDO_TESORERIA]                                                                         │
   │                                                                                                 │
   ▼                                                                                                 ▼
[Cuentas Corrientes / Digitadoras] <─────────────────────────────────────────────────────────────────┘
   │  1. Reciben notificación de cobranza validada por Tesorería.
   │  2. Entran a la Vista Limpia en el Portal Admin (`RECIBIDO_TESORERIA`).
   │  3. Leen los datos estructurados en pantalla y los digitan en Optimus ERP (Año 2000).
   │  4. Depositan el cheque físico en el Banco.
   │  5. Registran N° de Papeleta / Depósito y cambian estado a "DEPOSITADO".
   ▼
[Estado: DEPOSITADO] (Fin de Trazabilidad)
```

---

## 2. El Portal como Herramienta de Ahorro de Tiempo (No un Trámite Extra)

Para garantizar la adopción entusiasta por parte de Tesorería, el portal implementa los siguientes pilares de valor:

### A. Eliminación del 90% del Tipeo Manual en Tesorería
- **Antes (Manual):** Tesorería recibía el cheque y tipeaba desde cero en Excel: RUT, Razón Social, Banco, N° Cheque, Monto, Vencimiento y Facturas. *(10 a 15 minutos por cobranza)*.
- **Con el Portal:** Los datos ya vienen digitados por el vendedor desde la App. Tesorería solo verifica contra el papel físico y hace **1 Clic en Confirmar**. *(10 segundos por cobranza)*.

### B. Eliminación de Llamadas de Interrupción
- Los vendedores consultan el estado en vivo de sus cheques (`EN TRÁNSITO`, `RECIBIDO_TESORERIA`, `DEPOSITADO`) desde su propia App.
- Tesorería deja de perder horas respondiendo llamadas telefónicas sobre el estado de depósitos o liberación de facturas.

### C. Blindaje y Trazabilidad de Custodia
- Si un cheque se pierde en el despacho o si un vendedor afirma haberlo entregado, Tesorería queda protegida: la responsabilidad es de quien transporte el cheque mientras no figure como `RECIBIDO_TESORERIA` en el sistema.

### D. Edición Rápida vs Rechazo Formal
- **Edición en Portal (`editar_cheques.php`):** Permite a Tesorería corregir errores menores del vendedor (ej. banco o fecha) de inmediato sin trabar el proceso.
- **Rechazo con Motivo (`RECHAZADO`):** Si la diferencia de monto es grave respecto al talonario, Tesorería rechaza con comentario obligatorio, alertando al vendedor en su App.

---

## 3. Preservación del Rol de Cuentas Corrientes (Optimus ERP)

1. **Cero Reemplazo Laboral:** No se implementará importación masiva automática hacia Optimus ERP para preservar el trabajo de digitación del equipo de Cuentas Corrientes.
2. **Vista de Lectura de Alto Contraste:** El Portal ofrecerá una vista de tarjeta limpia con tipografía grande y datos destacados para que las digitadoras lean y traspasen la información a Optimus sin fatiga visual.
3. **Papeleta de Depósito:** Al terminar la digitación y efectuar el depósito bancario, la digitadora registra el N° de Papeleta en el portal para cerrar el ciclo financiero.

---

## 4. Hoja de Ruta de Futuras Mejoras (Post-Reunión)

- [ ] **Notificación Automática a Cuentas Corrientes:** Enviar email a `cuentascorrientes@empresa.cl` con link al registro cuando Tesorería aprueba.
- [ ] **Generador de Recibo PDF para Vendedores:** Permitir al vendedor descargar/compartir por WhatsApp un recibo en PDF equivalente al talonario de papel.
- [ ] **Filtro Rápido en Portal Admin:** Acceso directo de 1 clic para la bandeja de Cuentas Corrientes (`Pendientes de Digitar en Optimus`).
