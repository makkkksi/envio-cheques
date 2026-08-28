<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/auth.php';

$adminUser = requireAdminPage('users.manage');
$rolUsuario = $adminUser['rol'];
$csrfToken = getCsrfToken();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken); ?>">
    <title>Administración de Usuarios — Suite Financiera</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/shell.css?v=20260828-session-1">
    <link rel="stylesheet" href="css/modal_config_cc.css">
    <link rel="stylesheet" href="css/usuarios.css?v=1">
</head>
<body>
    <?php
    $CURRENT_MODULE = 'usuarios';
    require_once __DIR__ . '/includes/app_header.php';
    ?>

    <main class="users-page">
        <section class="users-heading" aria-labelledby="usersTitle">
            <div>
                <p class="users-eyebrow">Control de acceso</p>
                <h1 id="usersTitle">Usuarios administrativos</h1>
                <p>Administre roles y vigencia de acceso sin eliminar el historial de ninguna cuenta.</p>
            </div>
            <button type="button" class="users-primary-button" id="btnNuevoUsuario">Nuevo usuario</button>
        </section>

        <section class="users-card">
            <div class="users-table-wrap">
                <table class="users-table">
                    <thead>
                        <tr>
                            <th>Usuario</th>
                            <th>Correo</th>
                            <th>Rol</th>
                            <th>Estado</th>
                            <th>Creado</th>
                            <th class="users-actions-heading">Acciones</th>
                        </tr>
                    </thead>
                    <tbody id="usuariosTableBody">
                        <tr><td colspan="6" class="users-empty-cell">Cargando usuarios...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

    <div class="users-modal" id="modalUsuario" hidden>
        <div class="users-modal-card" role="dialog" aria-modal="true" aria-labelledby="modalUsuarioTitle">
            <div class="users-modal-header">
                <div>
                    <p class="users-eyebrow">Cuenta administrativa</p>
                    <h2 id="modalUsuarioTitle">Nuevo usuario</h2>
                </div>
                <button type="button" class="users-icon-button" data-close-modal="modalUsuario" aria-label="Cerrar">&times;</button>
            </div>
            <form id="formUsuario">
                <input type="hidden" id="usuarioId" value="0">
                <div class="users-form-grid">
                    <label class="users-field users-field-wide">
                        <span>Nombre completo</span>
                        <input type="text" id="usuarioNombre" maxlength="100" required autocomplete="name">
                    </label>
                    <label class="users-field users-field-wide">
                        <span>Correo electrónico</span>
                        <input type="email" id="usuarioEmail" maxlength="150" required autocomplete="email">
                    </label>
                    <label class="users-field">
                        <span>Rol</span>
                        <select id="usuarioRol" required>
                            <option value="TESORERIA">Tesorería</option>
                            <option value="SUPERVISORA_CC">Supervisora CC</option>
                            <option value="ADMINISTRADOR">Administrador</option>
                        </select>
                    </label>
                    <label class="users-field users-password-field" id="passwordField">
                        <span>Contraseña inicial</span>
                        <input type="password" id="usuarioPassword" minlength="10" autocomplete="new-password">
                    </label>
                    <label class="users-check users-field-wide">
                        <input type="checkbox" id="usuarioActivo" checked>
                        <span>Cuenta activa</span>
                    </label>
                </div>
                <div class="users-modal-actions">
                    <button type="button" class="users-secondary-button" data-close-modal="modalUsuario">Cancelar</button>
                    <button type="submit" class="users-primary-button" id="btnGuardarUsuario">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>

    <div class="users-modal" id="modalResetPassword" hidden>
        <div class="users-modal-card users-modal-card-small" role="dialog" aria-modal="true" aria-labelledby="modalResetTitle">
            <div class="users-modal-header">
                <div>
                    <p class="users-eyebrow">Seguridad de cuenta</p>
                    <h2 id="modalResetTitle">Restablecer contraseña</h2>
                </div>
                <button type="button" class="users-icon-button" data-close-modal="modalResetPassword" aria-label="Cerrar">&times;</button>
            </div>
            <form id="formResetPassword">
                <input type="hidden" id="resetUsuarioId" value="0">
                <p class="users-modal-copy" id="resetUsuarioLabel"></p>
                <label class="users-field">
                    <span>Nueva contraseña</span>
                    <input type="password" id="resetPassword" minlength="10" required autocomplete="new-password">
                    <small>Mínimo 10 caracteres.</small>
                </label>
                <div class="users-modal-actions">
                    <button type="button" class="users-secondary-button" data-close-modal="modalResetPassword">Cancelar</button>
                    <button type="submit" class="users-primary-button" id="btnResetPassword">Restablecer</button>
                </div>
            </form>
        </div>
    </div>

    <?php include __DIR__ . '/components/modal_config_cc.php'; ?>
    <script src="js/shared_ui.js?v=20260828-session-1"></script>
    <script src="js/modal_config_cc.js?v=11"></script>
    <script src="js/usuarios.js?v=20260826-1"></script>
</body>
</html>
