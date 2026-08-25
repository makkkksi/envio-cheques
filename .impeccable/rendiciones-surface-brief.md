# Rendiciones administrativas

- Scope: `admin/rendiciones.php`, `admin/css/rendiciones.css`, `admin/js/rendiciones.js` y réplica productiva.
- Visitor mode: Operate.
- Audience and job: Tesorería y administradores revisan rendiciones, evidencia, excesos y presupuestos con alta frecuencia.
- Primary task: filtrar la bandeja, seleccionar una rendición, auditar comprobantes y ejecutar la transición válida sin abandonar el contexto.
- Content: estados y contadores, tabla maestra, metadatos, comprobantes/SII, trazabilidad, presupuestos y agregados analíticos calculados desde APIs existentes.
- Constraints: Shell ERP y Lightbox compartidos; Vanilla CSS/JS; sin cambios de contrato API; RBAC; accesibilidad; raíz/dist idénticos.
- Direction: extender exactamente el lenguaje operativo de Cheques con una sidebar secundaria blanca, tabla maestra densa y drawer derecho sticky.
- Memorable moment: al elegir una fila, el inspector revela alerta de exceso, evidencia y stepper completo mientras la selección permanece marcada en azul.
- Unresolved: la API de listado no expone RUT del vendedor; la búsqueda usa código ERP y campos disponibles, y puede incluir RUT de proveedor cuando el detalle ya está en caché.
