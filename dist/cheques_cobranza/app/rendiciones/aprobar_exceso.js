(function () {
    'use strict';
    const card = document.querySelector('.approval-card');
    const button = document.getElementById('btnResolver');
    const result = document.getElementById('resultado');
    if (!card || !button || !result) return;
    window.history.replaceState({}, document.title, window.location.pathname);

    button.addEventListener('click', async function () {
        button.disabled = true;
        result.textContent = 'Registrando decisión...';
        try {
            const response = await fetch('../api/rendiciones/aprobar_exceso.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    token: card.dataset.token,
                    decision: card.dataset.decision,
                    comentario: document.getElementById('comentario').value.trim()
                })
            });
            const payload = await response.json();
            result.textContent = payload.message || 'No fue posible registrar la decisión.';
            result.classList.toggle('approval-card__result--success', response.ok && payload.success);
            if (!response.ok || !payload.success) button.disabled = false;
        } catch (error) {
            result.textContent = 'Error de conexión. Intente nuevamente.';
            button.disabled = false;
        }
    });
}());
