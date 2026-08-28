<?php
/**
 * admin/includes/app_header.php
 * 
 * Shell ERP Modular / App Switcher Unificado — Grupo Automarco
 * Renderiza el header compartido, pestañas de navegación y modal de cierre de sesión.
 */

require_once __DIR__ . '/../../config/auth.php';
startSecureSession();

$rolUsuario = $_SESSION['admin_user_rol'] ?? '';
$nombreUsuario = $_SESSION['admin_user_nombre'] ?? 'Usuario';
$currentModule = $CURRENT_MODULE ?? 'cheques';

// Mapeo de etiqueta y clase para el rol
$roleLabels = [
    'ADMINISTRADOR' => ['label' => 'Admin', 'class' => 'role-admin'],
    'TESORERIA' => ['label' => 'Tesorería', 'class' => 'role-tesoreria'],
    'SUPERVISORA_CC' => ['label' => 'C. Corrientes', 'class' => 'role-cc']
];
$roleBadgeData = $roleLabels[$rolUsuario] ?? ['label' => $rolUsuario, 'class' => ''];
?>
<!-- 1. HEADER MODULAR COMPARTIDO (SAAS SHELL) -->
<header class="shell-header">
    
    <!-- BRAND / LOGOTIPO ERP -->
    <div class="shell-brand-group">
        <img class="shell-brand-logo" src="../LOGO-HOLDING-AUTOMARCO.png" alt="Holding Automarco" width="128" height="28">
        <div class="shell-brand-title">Gestión Financiera <span>Suite</span></div>
    </div>

    <!-- APP SWITCHER TABS (TODOS LOS MÓDULOS DE LA SUITE DISPONIBLES) -->
    <nav class="shell-nav" aria-label="Módulos del Sistema">
        
        <?php if (userHasPermission($rolUsuario, 'cheques.view')): ?>
        <a href="index.php" class="shell-tab <?php echo ($currentModule === 'cheques') ? 'shell-tab--active' : ''; ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                <line x1="2" y1="10" x2="22" y2="10"></line>
                <line x1="6" y1="15" x2="10" y2="15"></line>
            </svg>
            <span>Cheques Cobranza</span>
        </a>
        <?php endif; ?>

        <?php if (userHasPermission($rolUsuario, 'cc.view')): ?>
        <a href="cuentas_corrientes.php" class="shell-tab <?php echo ($currentModule === 'cuentas_corrientes') ? 'shell-tab--active' : ''; ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 21h18"></path>
                <path d="M5 21V7l7-4 7 4v14"></path>
                <path d="M9 10h1"></path>
                <path d="M9 14h1"></path>
                <path d="M9 18h1"></path>
                <path d="M14 10h1"></path>
                <path d="M14 14h1"></path>
                <path d="M14 18h1"></path>
            </svg>
            <span>Cuentas Corrientes</span>
        </a>
        <?php endif; ?>

        <?php if (userHasPermission($rolUsuario, 'rendiciones.view')): ?>
        <a href="rendiciones.php" class="shell-tab <?php echo ($currentModule === 'rendiciones') ? 'shell-tab--active' : ''; ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            <span>Rendición Gastos</span>
        </a>
        <?php endif; ?>

        <?php if (userHasPermission($rolUsuario, 'users.manage')): ?>
        <a href="usuarios.php" class="shell-tab <?php echo ($currentModule === 'usuarios') ? 'shell-tab--active' : ''; ?>">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M22 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            <span>Usuarios</span>
        </a>
        <?php endif; ?>

    </nav>

    <!-- ZONA DERECHA: USUARIO, ACCIONES Y LOGOUT -->
    <div class="shell-right-zone">
        
        <?php if ($currentModule === 'cuentas_corrientes'): ?>
        <div class="cutoff-timer shell-cutoff-timer" id="txtCutoffTimer">
            Corte Hoy: <strong id="lblCutoffHour">--:--</strong> - <strong id="lblCutoffRemaining">Faltan --h --m</strong>
        </div>
        <?php endif; ?>

        <div class="shell-user-info">
            <span class="user-label">Usuario:</span>
            <span class="shell-user-name"><?php echo htmlspecialchars($nombreUsuario); ?></span>
            <span class="shell-role-badge <?php echo htmlspecialchars($roleBadgeData['class']); ?>"><?php echo htmlspecialchars($roleBadgeData['label']); ?></span>
        </div>

        <button type="button" id="btnHeaderRefresh" class="shell-btn-config shell-btn-refresh" title="Actualizar datos sin recargar la sesión" aria-label="Actualizar datos del módulo">
            <svg aria-hidden="true" viewBox="0 0 24 24">
                <path d="M20 11a8.1 8.1 0 0 0-15.5-2M4 4v5h5"></path>
                <path d="M4 13a8.1 8.1 0 0 0 15.5 2M20 20v-5h-5"></path>
            </svg>
            <span>Recargar</span>
        </button>

        <?php if (userHasPermission($rolUsuario, 'cc.manage') || userHasPermission($rolUsuario, 'companies.manage')): ?>
        <?php if ($currentModule === 'rendiciones' && userHasPermission($rolUsuario, 'users.manage')): ?>
        <button type="button" id="btnHeaderApprovers" class="shell-btn-config" title="Configurar responsables de aprobación de excesos">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M2 21v-2a4 4 0 0 1 4-4h6a4 4 0 0 1 4 4v2"></path>
                <path d="M19 8v6M22 11h-6"></path>
            </svg>
            Aprobadores
        </button>
        <?php endif; ?>

        <button type="button" id="btnHeaderConfig" class="shell-btn-config" title="Configurar corte, correos, digitadoras y empresas">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="3"></circle>
                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path>
            </svg>
            Configuración
        </button>
        <?php endif; ?>

        <button type="button" id="btnAbrirModalLogout" class="shell-btn-logout" title="Cerrar sesión segura">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Salir
        </button>

    </div>

</header>

<!-- MODAL LOGOUT UNIVERSAL -->
<div id="modalLogout" class="modal-logout-overlay" hidden>
    <div class="modal-logout-card">
        <h3 class="modal-logout-title">Cerrar Sesión</h3>
        <p class="modal-logout-text">¿Está seguro que desea cerrar su sesión de trabajo?</p>
        <div class="modal-logout-actions">
            <button type="button" id="btnCancelarLogout" class="modal-logout-btn-cancel">Cancelar</button>
            <button type="button" id="btnConfirmarLogout" class="modal-logout-btn-confirm" data-logout-url="api/auth/logout.php">Sí, cerrar sesión</button>
        </div>
    </div>
</div>
