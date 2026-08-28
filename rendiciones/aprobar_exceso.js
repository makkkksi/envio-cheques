(function () {
    'use strict';
    const card = document.querySelector('.approval-card');
    const buttons = [...document.querySelectorAll('[data-decision]')];
    const result = document.getElementById('resultado');
    const comment = document.getElementById('comentario');
    const decisionSection = document.querySelector('.approval-decision');
    const headerTitle = document.querySelector('.approval-header h1');
    const headerDescription = document.querySelector('.approval-header p');
    if (!card || !buttons.length || !result || !comment) return;
    window.history.replaceState({}, document.title, window.location.pathname);
    buttons.forEach((button) => button.addEventListener('click', async function () {
        const decision = button.dataset.decision;
        if (decision === 'RECHAZADO' && !comment.value.trim()) {
            result.textContent = 'Indica el motivo del rechazo para que Tesorería pueda actuar.';
            result.classList.remove('approval-card__result--success');
            comment.focus();
            return;
        }
        buttons.forEach((item) => { item.disabled = true; });
        result.textContent = 'Registrando decisión…';
        result.classList.remove('approval-card__result--success');
        try {
            const response = await fetch('../api/rendiciones/aprobar_exceso.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ token: card.dataset.token, decision, comentario: comment.value.trim() })
            });
            const payload = await response.json();
            result.textContent = payload.message || 'No fue posible registrar la decisión.';
            const success = response.ok && payload.success;
            result.classList.toggle('approval-card__result--success', success);
            if (success) {
                const data = payload.data || {};
                const approved = data.decision === 'APROBADO';
                const title = approved ? 'Exceso aprobado' : 'Exceso rechazado';
                const continuation = approved
                    ? 'La decisión quedó registrada. La rendición continuará su revisión normal en Tesorería.'
                    : 'La decisión quedó registrada y Tesorería fue informada para continuar la gestión.';
                const approver = [data.aprobador_nombre, data.aprobador_cargo].filter(Boolean).join(' · ');
                document.body.classList.add(approved ? 'approval-page--approved' : 'approval-page--rejected');
                if (headerTitle) headerTitle.textContent = title;
                if (headerDescription) headerDescription.textContent = 'La solicitud fue resuelta y este enlace ya no admite nuevas decisiones.';
                decisionSection.innerHTML = `<div class="approval-resolved approval-resolved--${approved ? 'approved' : 'rejected'}" role="status">
                    <span class="approval-resolved__icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="m5 12 4 4L19 6"/></svg></span>
                    <div><h2>${title}</h2><p>${continuation}</p>${approver ? `<small>Decisión registrada por <strong>${escapeHtml(approver)}</strong>.</small>` : ''}</div>
                </div>`;
                card.dataset.token = '';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                buttons.forEach((item) => { item.disabled = false; });
            }
        } catch (error) {
            result.textContent = 'Error de conexión. Intenta nuevamente.';
            buttons.forEach((item) => { item.disabled = false; });
        }
    }));

    function escapeHtml(value) {
        const node = document.createElement('span');
        node.textContent = String(value || '');
        return node.innerHTML;
    }
}());
