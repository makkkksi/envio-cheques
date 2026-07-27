
El proyecto se encuentra en **Fase 1 (Backend Base)**. Se ha iniciado la creación de la capa de configuración base del sistema mediante el script SQL DDL `config/setup.sql`.

---

## Avance por Fases

| Fase | Descripción | Estado | Avance |
|------|-------------|--------|--------|
| **Fase 1** | Backend PHP: BD central, endpoints API, integración real con frontend | ✅ Completado | 100% |
| **Fase 2** | Portal de Tesorería (`/admin/`) | ✅ Completado | 100% |
| **Fase 3** | Notificaciones por correo SMTP host | ⏳ Pendiente | 0% |
| **Fase 4** | Motor de alertas por días transcurridos (Cron Job) | ⏳ Pendiente | 0% |
| **Fase 5** | Integración de autenticación con App Android | ⏳ Pendiente | 0% |

---

## Componentes Entregados (Fase 1)

- [x] `config/setup.sql` — Script DDL completo y alineado de `bd_modulo_cobranzas`, tablas relacionales y seeders (empresas y usuario Sistema).
- [x] `config/app.php` — Constantes de entorno y configuración.
- [x] `config/db.php` — Clase Database PDO.
- [x] `config/auth.php` — Middleware de autenticación.
- [x] `api/get_factura.php` — Endpoint de búsqueda de facturas ERP.
- [x] `api/guardar_cobranza.php` — Endpoint transaccional de cobranzas.
- [x] `api/get_mis_cobranzas.php` — Endpoints probados y documentados.
- [x] `api/auth/login.php` — Endpoint de autenticación Bearer.
- [x] `services/MailService.php` — Servicio de notificaciones por correo.
- [x] `script.js` — Refactorización del frontend para consumir la API real.
- [x] `api/completar_envio.php` — Segundo paso: completa despacho y registra la transición correspondiente.
- [x] Frontend — Separación visual entre cobranzas por enviar y enviadas, con modal para completar el envío.
- [x] `config/update_schema_flujo_dividido.sql` — Script puntual para alinear una BD local ya creada sin tocar las bases ERP.

## Próximo trabajo inmediato

Ejecutar la actualización del esquema en la BD central local (`bd_modulo_cobranzas`) o recrearla desde `config/setup.sql`. Como este es un entorno cerrado sin datos reales, no se requiere migración de preservación de datos.
