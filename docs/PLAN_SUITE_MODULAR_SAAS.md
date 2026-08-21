# Plan Maestro: Transición a Suite Modular SaaS / Shell ERP

> **Documento de Contexto y Arquitectura Agéntica**  
> **Estado:** Aprobado para Planificación e Implementación por Fases  
> **Fecha:** Agosto 2026  
> **Objetivo:** Convertir el sistema actual en una **Suite Financiera y de Cobranza Modular (SaaS Shell)** de alto rendimiento, unificando la navegación, sesión y componentes compartidos entre los 3 portales sin romper la compatibilidad operativa existente.

---

## 1. Visión y Diagnóstico de la Arquitectura

### 1.1 Estado Actual
- **Módulo 1 (Cobranza de Cheques):** `admin/index.php` + `admin/detalle.php` + `admin.js`.
- **Módulo 2 (Cuentas Corrientes):** `admin/cuentas_corrientes.php` + `js/cuentas_corrientes.js`.
- **Módulo 3 (Rendiciones de Gastos):** En fase de diseño conceptual (`docs/DISENO_RENDICIONES_GASTOS.md`).
- **Problema:** Cada vista administrativa gestiona su propio header, navegación, modal de logout y visor Lightbox de forma duplicada o semi-acoplada.

### 1.2 Estado Deseado (SaaS Shell Pattern)
Una **Suite Unificada** donde:
1. **Un Solo Login / Sesión:** Tesorería inicia sesión una sola vez y navega entre módulos según sus permisos (`auth.php`).
2. **Shell Común (App Switcher):** Barra superior estándar (Navbar ERP) que resalta el módulo activo y muestra el perfil del usuario.
3. **Componentes Compartidos Reutilizables:** Lightbox fotográfico (para cheques y boletas), sistema de notificaciones Toast y modales estándar centralizados en archivos únicos.
4. **Cero Regresiones (Non-Breaking):** La refactorización es estrictamente aditiva y modular, garantizando que el flujo productivo de cheques y CC siga funcionando al 100%.

---

## 2. Diagrama de la Arquitectura Modular

```
┌────────────────────────────────────────────────────────────────────────────────────────┐
│                               SAAS SHELL / HEADER UNIFICADO                            │
│  [🏢 GRUPO AUTOMARCO]  │ 📑 Cheques Cobranza │ 🏛️ Cuentas Corrientes │ 🧾 Rendiciones  │ [👤 Perfil | 🚪 Salir]
├────────────────────────────────────────────────────────────────────────────────────────┤
│                                                                                        │
│   ┌────────────────────────┐  ┌────────────────────────┐  ┌────────────────────────┐   │
│   │ MÓDULO 1: CHEQUES      │  │ MÓDULO 2: CC / DESPACHO│  │ MÓDULO 3: RENDICIONES  │   │
│   │ • Bandeja de Cobranzas │  │ • Cola de Recibidos    │  │ • Mis Rendiciones      │   │
│   │ • Validación Tesorería │  │ • Despacho Digitadoras │  │ • Aprobación Excesos   │   │
│   │ • Drawer de Detalle    │  │ • Historial Trazable   │  │ • Control Presupuestos │   │
│   │ • Timeline Estados     │  │ • Config Corte Diario  │  │ • Recepción Valijas    │   │
│   └────────────────────────┘  └────────────────────────┘  └────────────────────────┘   │
│                                                                                        │
├────────────────────────────────────────────────────────────────────────────────────────┤
│                           SERVICIOS Y CORE COMPARTIDO (PDO)                            │
│  • Database (Connection Pool)   • AuthGuard (RBAC)   • MailService   • Shared Lightbox │
└────────────────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Estructura de Archivos de la Suite

```
admin/
├── includes/                      <-- NUEVO: Componentes del Shell Compartido
│   ├── app_header.php             <-- Navbar unificado con App Switcher y Tabs
│   ├── app_footer.php             <-- Scripts comunes, modales de logout, etc.
│   └── shared_lightbox.php        <-- Visor universal de imágenes/cheques/boletas
├── index.php                      <-- Módulo 1: Cobranza de Cheques (Usa app_header)
├── detalle.php                    <-- Detalle de Cobranza (Usa app_header)
├── cuentas_corrientes.php         <-- Módulo 2: Cuentas Corrientes (Usa app_header)
├── rendiciones.php                <-- Módulo 3: Rendición de Gastos (Usa app_header)
├── css/
│   └── styles.css                 <-- Design System compartido (Variables CSS)
├── js/
│   ├── admin.js                   <-- Lógica exclusiva de Cheques
│   ├── cuentas_corrientes.js      <-- Lógica exclusiva de CC
│   ├── rendiciones.js             <-- Lógica exclusiva de Rendiciones
│   └── shared_ui.js               <-- NUEVO: Helper único de Lightbox & Toasts
└── api/
    ├── ... (APIs cheques)
    ├── ... (APIs CC)
    └── rendiciones/               <-- Endpoints dedicados de Rendiciones
```

---

## 4. Plan de Implementación por Fases (Roadmap)

### 📌 FASE 1: Creación del Shell Compartido (Refactorización No Disruptiva)
1. **Crear `admin/includes/app_header.php`:**
   * Renderiza el logotipo/marca, tabs de navegación con clase `.active` condicional según la variable `$CURRENT_MODULE`.
   * Muestra usuario conectado, rol y botón de cierre de sesión.
   * Filtra las pestañas mostradas según los roles de sesión (`ADMINISTRADOR`, `SUPERVISORA_CC`, `TESORERIA`).
2. **Centralizar el Lightbox (`admin/js/shared_ui.js`):**
   * Extraer la función `abrirImagenLightbox()` a un archivo común para evitar duplicaciones entre `admin.js` y `cuentas_corrientes.js`.
3. **Migrar `admin/index.php` y `admin/cuentas_corrientes.php`:**
   * Reemplazar las barras de navegación inline por `<?php $CURRENT_MODULE = 'cheques'; require_once __DIR__ . '/includes/app_header.php'; ?>`.
4. **Validación:**
   * Verificar que la navegación entre Cheques y CC sea 100% fluida y que no exista ninguna ruptura visual o funcional.

---

### 📌 FASE 2: Hub Central del Vendedor
1. **Página de Inicio del Vendedor (`vendedores/index.html` o similar):**
   * Crear o actualizar la vista principal de vendedores con dos tarjetas interactivas:
     * 📑 **Recaudación de Cheques** (Flujo actual de clientes y facturas).
     * 🧾 **Rendición Mensual de Gastos** (Acceso directo a su rendición del mes).
2. **Contexto de Sesión Compartido:**
   * El vendedor mantiene su `vendedor_id` y `empresa_id` activos en `$_SESSION['vendedor_auth']` para ambos flujos.

---

### 📌 FASE 3: Desarrollo del Módulo 3 (Rendiciones de Gastos)
1. **Base de Datos:**
   * Crear las tablas definidas en `docs/DISENO_RENDICIONES_GASTOS.md` (`presupuestos_vendedores`, `rendiciones_gastos`, `rendicion_documentos`, `rendicion_historial_estados`).
2. **API Backend (PHP + PDO):**
   * `admin/api/rendiciones/get_rendiciones.php`: Listado con filtros de estado y vendedor.
   * `admin/api/rendiciones/guardar_rendicion.php`: Recepción de boletas con hash antifraude.
   * `admin/api/rendiciones/aprobar_exceso.php`: Endpoint consumidor del token de 1 solo uso para Francisco J.
   * `admin/api/rendiciones/gestionar_estado.php`: Aprobación, rechazo y confirmación de recepción física.
   * `admin/api/rendiciones/guardar_presupuestos.php`: CRUD de presupuestos mensuales por vendedor.
3. **Frontend Administrativo (`admin/rendiciones.php` + `admin/js/rendiciones.js`):**
   * Bandeja de entrada tipo ERP con KPIs de presupuestos ejecutados vs disponibles.
   * Modal de detalle de boletas con miniaturas y Lightbox interactivo.
   * Botón de confirmación de "Documentos Físicos Recibidos".
4. **Frontend Vendedor (`vendedores/rendicion_gastos.html`):**
   * Subida ágil de comprobantes con cálculo en tiempo real de saldo restante del presupuesto mensual.
5. **Servicio de Correos (`MailService.php`):**
   * Plantilla con botones de acción rápida y Token Criptográfico firmado para Francisco J.
   * Plantilla de notificación de resolución al vendedor.

---

### 📌 FASE 4: Sincronización Dual Root ↔ Dist & Auditoría Final
1. **Replicación en `dist/`:**
   * Copiar y verificar sincronización exacta de todos los archivos en `dist/cheques_cobranza/app/`.
2. **Auditoría de Seguridad:**
   * Validación de token CSRF, RBAC en endpoints y desinfección contra Path Traversal en subida de boletas.
3. **Actualización Documental:**
   * Reflejar el estado productivo en `docs/PROJECT_STATUS.md` y `docs/ROADMAP.md`.

---

## 5. Directivas Obligatorias para Agentes de IA

Cualquier agente que trabaje en esta suite debe cumplir estrictamente con:
1. **Regla de Cero Frameworks:** No agregar Laravel, React, Vue, Tailwind ni dependencias Node al bundle base; respetar PHP puro + PDO + Vanilla CSS/JS.
2. **Regla de Réplica Dual:** Todo archivo modificado en `admin/` o `cron/` debe sincronizarse inmediatamente en `dist/cheques_cobranza/app/`.
3. **Regla de Integridad de Base de Datos:**
   * Cero concatenación SQL (usar prepared statements).
   * Nombres de bases de datos ERP y columnas existentes son intocables (`docs/DATABASE.md`).
   * No usar `DELETE` para operaciones de negocio; usar bajas lógicas o estados auditables.
