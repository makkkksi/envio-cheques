# Certificación QA — Topes y aprobaciones de Rendiciones

**Fecha:** 1 de septiembre de 2026  
**Entorno:** Laragon local, PHP/MySQL del proyecto  
**Resultado local:** APROBADO  
**Datos residuales:** ninguno; los fixtures transaccionales terminan en `ROLLBACK`.

## Suites ejecutables

```bash
php scripts/test_rendiciones.php
php scripts/test_approval_workflow.php
php scripts/verify_release.php
```

- `test_rendiciones.php`: 38 comprobaciones funcionales de esquema, identidad ERP, fondos simultáneos, rangos de gira, hash antifraude, estados, presupuesto aprobado/pendiente y PDF.
- `test_approval_workflow.php`: 34 comprobaciones transaccionales de solicitudes, tokens, fallos de correo, reenvío, cancelación, expiración, decisiones y métricas del Dashboard.
- `verify_release.php`: sintaxis PHP, contrato SQL/Zero Delete, configuración por entorno y paridad SHA-256.

## Matriz mensual M01–M20

| Casos | Cobertura certificada | Resultado |
|---|---|---|
| M01–M06 | Presupuesto requerido, saldo operativo, último informe que cruza el tope, bloqueo posterior, reserva de pendientes y bloqueo transaccional del fondo. | PASS |
| M07–M10 | Orden FIFO `fecha_emision, id`, monto parcial/cero, rechazo documental con motivo, recálculo y liberación de reserva. | PASS |
| M11–M12 | Pago ordinario hasta tope y solicitud opcional exclusivamente por el exceso desde Tesorería. | PASS |
| M13–M14 | Aprobación amplía sólo `monto_maximo_aprobable`; rechazo conserva la rendición y su tope ordinario. | PASS |
| M15–M18 | Vencimiento, fallo SMTP simulado, cancelación/cambio de responsable y rotación de token. | PASS |
| M19–M20 | No existe autorización retroactiva para una rendición pagada; cierre conserva presentado, aprobado real y marca de tope. | PASS |

## Matriz de giras G01–G19

| Casos | Cobertura certificada | Resultado |
|---|---|---|
| G01–G03 | Alta con identidad ERP, fechas, monto, justificación y responsable; estado pendiente, correo exitoso/fallido y reenvío. | PASS |
| G04–G06 | Aprobación/rechazo atómicos y token de uso único; sólo la gira aprobada queda habilitable para el vendedor. | PASS |
| G07–G09 | Token vencido, reenvío con nueva versión y cambio/cancelación auditables. | PASS |
| G10–G12 | Comprobantes dentro del rango, coexistencia Mensual/Gira y revisión documental independiente del fondo autorizado. | PASS |
| G13–G16 | Tope propio de la gira, aumento con nueva autorización, disminución compatible y solicitud anterior invalidada. | PASS |
| G17–G19 | Historial inmutable, estado de correo visible y métricas estandarizadas sin nombres libres de giras. | PASS |

## Seguridad y contratos HTTP

- Magic Links sin token válido: HTTP 422 controlado, sin stack trace.
- Páginas públicas de aprobación: HTTP 200 y rutas relativas compatibles con subdirectorios.
- Login administrativo local y vista Dashboard: HTTP 200 con sesión real.
- POST administrativo sin CSRF: HTTP 403.
- ERP: consultas de vendedores en modo lectura y bases validadas contra `ALLOWED_DATABASES`.
- SQL de negocio: prepared statements nombrados y sin `DELETE` físico.

## Único ensayo externo pendiente

El envío SMTP real en el host no forma parte de la certificación local porque produciría un mensaje a terceros. Para cerrarlo se requiere un correo de prueba expresamente autorizado. El ciclo equivalente se validó localmente registrando éxito, `ENVIO_FALLIDO`, rotación y reenvío sin emitir mensajes reales.
