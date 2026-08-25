# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Tesorería y administradores del Grupo Automarco operan diariamente bandejas financieras de alta densidad para revisar cobranzas, rendiciones y presupuestos. Vendedores en terreno registran documentos y consultan sus rendiciones desde un portal web integrado a una WebView Android.

## Product Purpose

La Suite de Gestión Financiera centraliza Cobranza de Cheques, Cuentas Corrientes y Rendiciones de Gastos para que cada actor pueda registrar, revisar, aprobar y auditar movimientos financieros sin perder trazabilidad entre empresas del holding.

## Positioning

Un único Shell ERP conecta operación de terreno, revisión de Tesorería, presupuestos mensuales o de gira y evidencia documental, preservando la identidad nativa de vendedor por empresa y una pista de auditoría inmutable.

## Operating Context

El portal administrativo se utiliza principalmente en escritorio durante jornadas de revisión financiera. Las tareas frecuentes son filtrar bandejas, abrir un registro en patrón maestro–detalle, inspeccionar fotografías, confirmar recepción física, aprobar o rechazar y administrar presupuestos. La instalación local oficial usa Laragon; `dist/cheques_cobranza/app/` es el paquete productivo.

## Capabilities and Constraints

- PHP puro con PDO y frontend Vanilla CSS/JS, sin frameworks ni dependencias externas.
- Tres módulos administrativos bajo `admin/includes/app_header.php`, con RBAC y sesión compartida.
- Rendiciones admite presupuestos mensuales y giras, bolsa documental, control de exceso, Magic Token, recepción física, aprobación total/parcial, rechazo y pago.
- Cero eliminación física de datos de negocio; estados y bajas lógicas auditables.
- Los cambios funcionales de aplicación mantienen réplica raíz/dist, salvo configuración ambiental explícitamente diferenciada en `.htaccess`.
- Las APIs responden JSON `{ "success": true/false, ... }` y las mutaciones requieren CSRF.

## Brand Commitments

Nombre: Sistema de Gestión Financiera / Gestión Financiera Suite del Grupo Automarco. Deben preservarse el logotipo existente, el App Switcher, la terminología financiera en español y la continuidad operativa con la Bandeja de Cheques.

## Evidence on Hand

El repositorio contiene la implementación funcional de los tres módulos, el diseño vigente de Cheques como referencia operativa, los endpoints de Rendiciones, el logotipo del holding y documentación de arquitectura, base de datos y reglas de negocio. No existen métricas productivas ni testimonios que deban inventarse.

## Product Principles

- La operación crítica debe poder escanearse y decidirse rápidamente.
- La misma acción y estado deben verse y comportarse igual en toda la suite.
- La evidencia documental y la trazabilidad acompañan siempre al movimiento financiero.
- La complejidad técnica no debe trasladarse al operador.
- La configuración de producción nunca debe interferir con el entorno local.

## Accessibility & Inclusion

Los controles operativos requieren foco visible, objetivos mínimos de 44 px, navegación por teclado, etiquetas accesibles, contraste semántico y adaptación funcional a escritorio, tablet y móvil.
