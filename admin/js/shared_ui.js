/**
 * admin/js/shared_ui.js
 * 
 * Componentes y Utilidades UI Compartidas — SaaS Shell Suite (Grupo Automarco)
 * Centraliza: Lightbox Fotográfico con Zoom/Drag, Sistema de Toasts y Gestión de Modales Globales.
 */

// ==========================================
// 1. SISTEMA GLOBAL DE NOTIFICACIONES (TOAST)
// ==========================================
function showToast(message, type = 'success') {
    let container = document.getElementById('toastContainer');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);
    setTimeout(() => {
        toast.classList.add('toast--leaving');
        setTimeout(() => toast.remove(), 300);
    }, 4000);
}

function getAdminCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function getAdminJsonHeaders() {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-Token': getAdminCsrfToken()
    };
}

// ==========================================
// 2. VISOR FOTOGRÁFICO AVANZADO (LIGHTBOX)
// Zoom, Rotación 90°, Arrastre (Mouse/Touch), Alto Contraste y Descarga
// ==========================================
let lbRotation = 0;
let lbZoom = 1;
let lbContrast = false;
let lbPanX = 0;
let lbPanY = 0;
let lbDragging = false;
let lbDragStartX = 0;
let lbDragStartY = 0;
let lbPanStartX = 0;
let lbPanStartY = 0;

function abrirImagenLightbox(url) {
    if (!url) return;
    let lightbox = document.getElementById('modalLightbox');
    if (!lightbox) {
        lightbox = document.createElement('div');
        lightbox.id = 'modalLightbox';
        lightbox.className = 'modal-overlay shared-lightbox';
        lightbox.hidden = true;
        lightbox.innerHTML = `
            <div class="shared-lightbox__toolbar">
                <button type="button" class="image-tool-btn" data-lightbox-action="rotate">Rotar 90°</button>
                <button type="button" class="image-tool-btn" data-lightbox-action="zoom-in">Acercar</button>
                <button type="button" class="image-tool-btn" data-lightbox-action="zoom-out">Alejar</button>
                <button type="button" class="image-tool-btn" data-lightbox-action="contrast">Alto contraste</button>
                <button type="button" class="image-tool-btn" data-lightbox-action="reset">Restablecer</button>
                <button type="button" class="image-tool-btn" data-lightbox-action="download">Descargar</button>
                <button type="button" class="shared-lightbox__close" data-lightbox-action="close" title="Cerrar" aria-label="Cerrar visor">&times;</button>
            </div>
            <div id="lightboxImageWrapper" class="shared-lightbox__image-wrapper">
                <img id="imgLightboxTarget" class="shared-lightbox__image" src="" alt="Comprobante digitalizado">
            </div>
            <div id="lightboxZoomLabel" class="shared-lightbox__zoom-label">100%</div>
        `;
        document.body.appendChild(lightbox);

        const lightboxActions = {
            rotate: rotarImagenLightbox,
            'zoom-in': zoomInLightbox,
            'zoom-out': zoomOutLightbox,
            contrast: toggleAltoContrasteLightbox,
            reset: resetImagenLightbox,
            download: descargarImagenLightbox,
            close: cerrarImagenLightbox
        };
        lightbox.querySelectorAll('[data-lightbox-action]').forEach((button) => {
            button.addEventListener('click', () => lightboxActions[button.dataset.lightboxAction]?.());
        });

        // Cerrar al hacer click en el fondo oscuro
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) cerrarImagenLightbox();
        });

        const wrapper = document.getElementById('lightboxImageWrapper');

        // --- DRAG CON MOUSE ---
        wrapper.addEventListener('mousedown', (e) => {
            lbDragging = true;
            wrapper.classList.add('is-dragging');
            lbDragStartX = e.clientX;
            lbDragStartY = e.clientY;
            lbPanStartX = lbPanX;
            lbPanStartY = lbPanY;
            e.preventDefault();
        });

        window.addEventListener('mousemove', (e) => {
            if (!lbDragging) return;
            lbPanX = lbPanStartX + (e.clientX - lbDragStartX);
            lbPanY = lbPanStartY + (e.clientY - lbDragStartY);
            aplicarTransformLightbox();
        });

        window.addEventListener('mouseup', () => {
            if (lbDragging) {
                lbDragging = false;
                const w = document.getElementById('lightboxImageWrapper');
                if (w) w.classList.remove('is-dragging');
            }
        });

        // --- DRAG TOUCH (MÓVILES / TABLETS) ---
        wrapper.addEventListener('touchstart', (e) => {
            if (e.touches.length === 1) {
                lbDragging = true;
                lbDragStartX = e.touches[0].clientX;
                lbDragStartY = e.touches[0].clientY;
                lbPanStartX = lbPanX;
                lbPanStartY = lbPanY;
            }
        }, { passive: true });

        wrapper.addEventListener('touchmove', (e) => {
            if (!lbDragging || e.touches.length !== 1) return;
            lbPanX = lbPanStartX + (e.touches[0].clientX - lbDragStartX);
            lbPanY = lbPanStartY + (e.touches[0].clientY - lbDragStartY);
            aplicarTransformLightbox();
            e.preventDefault();
        }, { passive: false });

        wrapper.addEventListener('touchend', () => { lbDragging = false; });

        // --- ZOOM CON RUEDA DEL MOUSE ---
        wrapper.addEventListener('wheel', (e) => {
            e.preventDefault();
            if (e.deltaY < 0) {
                lbZoom = Math.min(lbZoom + 0.15, 5);
            } else {
                lbZoom = Math.max(lbZoom - 0.15, 0.3);
            }
            aplicarTransformLightbox();
        }, { passive: false });
    }

    // Restablecer estado inicial
    lbRotation = 0;
    lbZoom = 1;
    lbContrast = false;
    lbPanX = 0;
    lbPanY = 0;

    const img = document.getElementById('imgLightboxTarget');
    img.onload = () => aplicarTransformLightbox();
    img.src = url;
    if (img.complete && img.naturalWidth) aplicarTransformLightbox();

    lightbox.hidden = false;
    document.body.classList.add('modal-open');
}

function aplicarTransformLightbox() {
    const img = document.getElementById('imgLightboxTarget');
    const label = document.getElementById('lightboxZoomLabel');
    if (!img) return;

    const nw = img.naturalWidth;
    const nh = img.naturalHeight;
    if (!nw || !nh) return;

    const wrapper = document.getElementById('lightboxImageWrapper');
    const wW = wrapper.clientWidth;
    const wH = wrapper.clientHeight;

    const fitScale = Math.min(wW / nw, wH / nh);
    const baseW = nw * fitScale;
    const baseH = nh * fitScale;

    img.style.width = baseW + 'px';
    img.style.height = baseH + 'px';

    const tx = -baseW / 2 + lbPanX;
    const ty = -baseH / 2 + lbPanY;
    img.style.transform = `translate(${tx}px, ${ty}px) scale(${lbZoom}) rotate(${lbRotation}deg)`;

    if (lbContrast) {
        img.classList.add('high-contrast-image');
    } else {
        img.classList.remove('high-contrast-image');
    }

    if (label) label.textContent = Math.round(lbZoom * 100) + '%';
}

function rotarImagenLightbox() {
    lbRotation = (lbRotation + 90) % 360;
    aplicarTransformLightbox();
}

function zoomInLightbox() {
    lbZoom = Math.min(lbZoom + 0.25, 5);
    aplicarTransformLightbox();
}

function zoomOutLightbox() {
    lbZoom = Math.max(lbZoom - 0.25, 0.3);
    aplicarTransformLightbox();
}

function toggleAltoContrasteLightbox() {
    lbContrast = !lbContrast;
    aplicarTransformLightbox();
}

function resetImagenLightbox() {
    lbRotation = 0;
    lbZoom = 1;
    lbContrast = false;
    lbPanX = 0;
    lbPanY = 0;
    aplicarTransformLightbox();
}

function cerrarImagenLightbox() {
    const lightbox = document.getElementById('modalLightbox');
    if (lightbox) lightbox.hidden = true;
    document.body.classList.remove('modal-open');
}

function descargarImagenLightbox() {
    const img = document.getElementById('imgLightboxTarget');
    if (!img || !img.src) return;
    const a = document.createElement('a');
    a.href = img.src;
    const filename = img.src.substring(img.src.lastIndexOf('/') + 1);
    a.download = filename || ('comprobante_' + Date.now() + '.jpg');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}

// ==========================================
// 3. LISTENERS GLOBALES (TECLADO & MODAL LOGOUT)
// ==========================================
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' || e.keyCode === 27) {
        cerrarImagenLightbox();
        const modalLogout = document.getElementById('modalLogout');
        if (modalLogout) modalLogout.hidden = true;
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Binding centralizado de Modal Logout
    const btnAbrirModalLogout = document.getElementById('btnAbrirModalLogout');
    const modalLogout = document.getElementById('modalLogout');
    if (btnAbrirModalLogout && modalLogout) {
        btnAbrirModalLogout.addEventListener('click', () => {
            modalLogout.hidden = false;
        });
    }

    const btnCancelarLogout = document.getElementById('btnCancelarLogout');
    if (btnCancelarLogout && modalLogout) {
        btnCancelarLogout.addEventListener('click', () => {
            modalLogout.hidden = true;
        });
    }

    const btnConfirmarLogout = document.getElementById('btnConfirmarLogout');
    if (btnConfirmarLogout) {
        btnConfirmarLogout.addEventListener('click', async () => {
            btnConfirmarLogout.disabled = true;
            try {
                const response = await fetch(btnConfirmarLogout.dataset.logoutUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': getAdminCsrfToken() }
                });
                const data = await response.json();
                if (!response.ok || !data.success) {
                    throw new Error(data.message || 'No fue posible cerrar la sesión.');
                }
                window.location.href = 'login.php';
            } catch (error) {
                showToast(error.message || 'No fue posible cerrar la sesión.', 'error');
                btnConfirmarLogout.disabled = false;
            }
        });
    }

    if (modalLogout) {
        modalLogout.addEventListener('click', (event) => {
            if (event.target === modalLogout) modalLogout.hidden = true;
        });
    }
});
