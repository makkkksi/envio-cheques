let usuariosAdministrativos = [];

function setUsersModalOpen(modalId, isOpen) {
    const modal = document.getElementById(modalId);
    if (!modal) return;
    modal.hidden = !isOpen;
    document.body.classList.toggle('modal-open', isOpen);
}

function formatAdminRole(role) {
    const labels = {
        ADMINISTRADOR: 'Administrador',
        TESORERIA: 'Tesorería',
        SUPERVISORA_CC: 'Supervisora CC'
    };
    return labels[role] || role;
}

function formatUserDate(value) {
    if (!value) return '-';
    const parsed = new Date(value.replace(' ', 'T'));
    return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString('es-CL');
}

function createTextCell(value) {
    const cell = document.createElement('td');
    cell.textContent = value;
    return cell;
}

function renderUsuarios() {
    const tbody = document.getElementById('usuariosTableBody');
    tbody.replaceChildren();

    if (usuariosAdministrativos.length === 0) {
        const row = document.createElement('tr');
        const cell = createTextCell('No hay usuarios administrativos registrados.');
        cell.colSpan = 6;
        cell.className = 'users-empty-cell';
        row.appendChild(cell);
        tbody.appendChild(row);
        return;
    }

    usuariosAdministrativos.forEach((user) => {
        const row = document.createElement('tr');
        const nameCell = createTextCell(user.nombre);
        nameCell.className = 'users-name-cell';
        row.appendChild(nameCell);
        row.appendChild(createTextCell(user.email));

        const roleCell = document.createElement('td');
        const roleBadge = document.createElement('span');
        roleBadge.className = `users-role users-role-${user.rol.toLowerCase()}`;
        roleBadge.textContent = formatAdminRole(user.rol);
        roleCell.appendChild(roleBadge);
        row.appendChild(roleCell);

        const statusCell = document.createElement('td');
        const statusBadge = document.createElement('span');
        statusBadge.className = `users-status ${Number(user.activo) === 1 ? 'is-active' : 'is-inactive'}`;
        statusBadge.textContent = Number(user.activo) === 1 ? 'Activo' : 'Inactivo';
        statusCell.appendChild(statusBadge);
        row.appendChild(statusCell);
        row.appendChild(createTextCell(formatUserDate(user.created_at)));

        const actionsCell = document.createElement('td');
        actionsCell.className = 'users-row-actions';
        const editButton = document.createElement('button');
        editButton.type = 'button';
        editButton.className = 'users-table-button';
        editButton.textContent = 'Editar';
        editButton.addEventListener('click', () => openEditUser(user.id));
        const resetButton = document.createElement('button');
        resetButton.type = 'button';
        resetButton.className = 'users-table-button users-table-button-muted';
        resetButton.textContent = 'Reset contraseña';
        resetButton.addEventListener('click', () => openResetPassword(user.id));
        actionsCell.append(editButton, resetButton);
        row.appendChild(actionsCell);
        tbody.appendChild(row);
    });
}

async function cargarUsuarios() {
    try {
        const response = await fetch('api/get_usuarios.php');
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'No fue posible cargar los usuarios.');
        usuariosAdministrativos = data.data || [];
        renderUsuarios();
    } catch (error) {
        showToast(error.message || 'No fue posible cargar los usuarios.', 'error');
        usuariosAdministrativos = [];
        renderUsuarios();
    }
}

function openNewUser() {
    document.getElementById('formUsuario').reset();
    document.getElementById('usuarioId').value = '0';
    document.getElementById('usuarioActivo').checked = true;
    document.getElementById('usuarioPassword').required = true;
    document.getElementById('passwordField').hidden = false;
    document.getElementById('modalUsuarioTitle').textContent = 'Nuevo usuario';
    setUsersModalOpen('modalUsuario', true);
    document.getElementById('usuarioNombre').focus();
}

function openEditUser(userId) {
    const user = usuariosAdministrativos.find((item) => Number(item.id) === Number(userId));
    if (!user) return;
    document.getElementById('usuarioId').value = user.id;
    document.getElementById('usuarioNombre').value = user.nombre;
    document.getElementById('usuarioEmail').value = user.email;
    document.getElementById('usuarioRol').value = user.rol;
    document.getElementById('usuarioActivo').checked = Number(user.activo) === 1;
    document.getElementById('usuarioPassword').required = false;
    document.getElementById('usuarioPassword').value = '';
    document.getElementById('passwordField').hidden = true;
    document.getElementById('modalUsuarioTitle').textContent = 'Editar usuario';
    setUsersModalOpen('modalUsuario', true);
    document.getElementById('usuarioNombre').focus();
}

function openResetPassword(userId) {
    const user = usuariosAdministrativos.find((item) => Number(item.id) === Number(userId));
    if (!user) return;
    document.getElementById('resetUsuarioId').value = user.id;
    document.getElementById('resetPassword').value = '';
    document.getElementById('resetUsuarioLabel').textContent = `Defina una nueva contraseña para ${user.nombre}.`;
    setUsersModalOpen('modalResetPassword', true);
    document.getElementById('resetPassword').focus();
}

async function guardarUsuario(event) {
    event.preventDefault();
    const button = document.getElementById('btnGuardarUsuario');
    button.disabled = true;
    try {
        const userId = Number(document.getElementById('usuarioId').value);
        const payload = {
            id: userId,
            nombre: document.getElementById('usuarioNombre').value.trim(),
            email: document.getElementById('usuarioEmail').value.trim(),
            rol: document.getElementById('usuarioRol').value,
            activo: document.getElementById('usuarioActivo').checked ? 1 : 0,
            password: userId === 0 ? document.getElementById('usuarioPassword').value : ''
        };
        const response = await fetch('api/guardar_usuario.php', {
            method: 'POST',
            headers: getAdminJsonHeaders(),
            body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'No fue posible guardar el usuario.');
        showToast(data.message, 'success');
        setUsersModalOpen('modalUsuario', false);
        await cargarUsuarios();
    } catch (error) {
        showToast(error.message || 'No fue posible guardar el usuario.', 'error');
    } finally {
        button.disabled = false;
    }
}

async function resetearPassword(event) {
    event.preventDefault();
    const button = document.getElementById('btnResetPassword');
    button.disabled = true;
    try {
        const response = await fetch('api/resetear_password_usuario.php', {
            method: 'POST',
            headers: getAdminJsonHeaders(),
            body: JSON.stringify({
                id: Number(document.getElementById('resetUsuarioId').value),
                password: document.getElementById('resetPassword').value
            })
        });
        const data = await response.json();
        if (!response.ok || !data.success) throw new Error(data.message || 'No fue posible restablecer la contraseña.');
        showToast(data.message, 'success');
        setUsersModalOpen('modalResetPassword', false);
    } catch (error) {
        showToast(error.message || 'No fue posible restablecer la contraseña.', 'error');
    } finally {
        button.disabled = false;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btnNuevoUsuario').addEventListener('click', openNewUser);
    document.getElementById('formUsuario').addEventListener('submit', guardarUsuario);
    document.getElementById('formResetPassword').addEventListener('submit', resetearPassword);
    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', () => setUsersModalOpen(button.dataset.closeModal, false));
    });
    document.querySelectorAll('.users-modal').forEach((modal) => {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) setUsersModalOpen(modal.id, false);
        });
    });
    cargarUsuarios();
});
