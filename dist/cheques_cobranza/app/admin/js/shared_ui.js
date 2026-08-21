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
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        toast.style.transition = 'all 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4000);
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
        lightbox.className = 'modal-overlay';
        lightbox.style.cssText = 'display:none; position:fixed; inset:0; background:rgba(15,23,42,0.85); backdrop-filter:blur(4px); z-index:99999; flex-direction:column; align-items:center; justify-content:center;';
        lightbox.innerHTML = `
            <div style="display:flex; gap:8px; background:rgba(15,23,42,0.92); padding:8px 14px; border-radius:8px; flex-wrap:wrap; justify-content:center; margin-bottom:10px; z-index:10; border:1px solid rgba(255,255,255,0.1);">
                <button type="button" class="image-tool-btn" onclick="rotarImagenLightbox()">🔄 Rotar 90°</button>
                <button type="button" class="image-tool-btn" onclick="zoomInLightbox()">➕ Zoom</button>
                <button type="button" class="image-tool-btn" onclick="zoomOutLightbox()">➖ Zoom</button>
                <button type="button" class="image-tool-btn" onclick="toggleAltoContrasteLightbox()">☀️ Alto Contraste</button>
                <button type="button" class="image-tool-btn" onclick="resetImagenLightbox()">Restablecer</button>
                <button type="button" class="image-tool-btn" onclick="descargarImagenLightbox()">📥 Descargar</button>
                <button type="button" onclick="cerrarImagenLightbox()" style="background:#dc2626; color:white; border:none; font-size:1.1rem; width:30px; height:30px; border-radius:50%; cursor:pointer; font-weight:bold; display:flex; align-items:center; justify-content:center; line-height:1;" title="Cerrar">&times;</button>
            </div>
            <div id="lightboxImageWrapper" style="position:relative; overflow:hidden; width:90vw; height:80vh; border-radius:8px; background:rgba(0,0,0,0.25); cursor:grab;">
                <img id="imgLightboxTarget" src="" alt="Comprobante digitalizado" style="position:absolute; top:50%; left:50%; transform-origin:center center; border-radius:6px; box-shadow:0 10px 30px rgba(0,0,0,0.6); user-select:none; -webkit-user-drag:none; pointer-events:none;">
            </div>
            <div id="lightboxZoomLabel" style="position:fixed; bottom:16px; right:16px; background:rgba(15,23,42,0.85); color:#94a3b8; padding:4px 12px; border-radius:6px; font-size:0.8rem; font-weight:600; z-index:10; border:1px solid rgba(255,255,255,0.1);">100%</div>
        `;
        document.body.appendChild(lightbox);

        // Cerrar al hacer click en el fondo oscuro
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) cerrarImagenLightbox();
        });

        const wrapper = document.getElementById('lightboxImageWrapper');

        // --- DRAG CON MOUSE ---
        wrapper.addEventListener('mousedown', (e) => {
            lbDragging = true;
            wrapper.style.cursor = 'grabbing';
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
                if (w) w.style.cursor = 'grab';
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

    lightbox.style.display = 'flex';
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
    if (lightbox) lightbox.style.display = 'none';
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
        if (modalLogout) modalLogout.style.display = 'none';
    }
});

document.addEventListener('DOMContentLoaded', () => {
    // Binding centralizado de Modal Logout
    const btnAbrirModalLogout = document.getElementById('btnAbrirModalLogout');
    const modalLogout = document.getElementById('modalLogout');
    if (btnAbrirModalLogout && modalLogout) {
        btnAbrirModalLogout.addEventListener('click', () => {
            modalLogout.style.display = 'flex';
        });
    }

    const btnCancelarLogout = document.getElementById('btnCancelarLogout');
    if (btnCancelarLogout && modalLogout) {
        btnCancelarLogout.addEventListener('click', () => {
            modalLogout.style.display = 'none';
        });
    }
});
