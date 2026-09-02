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
            result.textContent = 'Indica el motivo del rechazo para que Tesorería y el vendedor puedan corregir.';
            result.classList.remove('approval-card__result--success');
            comment.focus();
            return;
        }

        buttons.forEach((item) => { item.disabled = true; });
        result.textContent = 'Registrando resolución…';
        result.classList.remove('approval-card__result--success');

        try {
            const response = await fetch('../api/rendiciones/aprobar_rendicion.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content
                },
                body: JSON.stringify({
                    token: card.dataset.token,
                    decision,
                    comentario: comment.value.trim()
                })
            });
            const payload = await response.json();
            result.textContent = payload.message || 'No fue posible registrar la resolución.';
            const success = response.ok && payload.success;
            result.classList.toggle('approval-card__result--success', success);

            if (success) {
                const data = payload.data || {};
                const isCapped   = data.decision === 'APROBADO_TOPE';
                const isApproved = data.decision === 'APROBADO' || isCapped;
                const approver   = [data.aprobador_nombre, data.aprobador_cargo].filter(Boolean).join(' · ');

                const title = isApproved
                    ? (isCapped ? 'Rendición Aprobada hasta el Tope' : 'Rendición Aprobada Exitosamente')
                    : 'Rendición Rechazada';

                const continuation = isApproved
                    ? (isCapped
                        ? 'La rendición fue aprobada solo hasta el presupuesto asignado. El exceso presentado no será reembolsado. Tesorería ha sido notificada.'
                        : 'La rendición ha sido oficialmente autorizada. Tesorería ha recibido la confirmación para proceder con el pago.')
                    : 'La rendición fue rechazada y Tesorería ha sido notificada para coordinar con el vendedor.';

                document.body.classList.add(isApproved ? 'approval-page--approved' : 'approval-page--rejected');
                if (headerTitle) headerTitle.textContent = title;
                if (headerDescription) headerDescription.textContent = 'La solicitud fue resuelta y este enlace ya no admite nuevas decisiones.';

                let pdfButtonHtml = '';
                if (isApproved && data.pdf_url) {
                    pdfButtonHtml = `<br><a href="${escapeHtml(data.pdf_url)}" target="_blank" class="approval-card__pdf-btn">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
                        Descargar Planilla Oficial en PDF (Excel)
                    </a>`;
                }

                decisionSection.innerHTML = `<div class="approval-resolved approval-resolved--${isApproved ? 'approved' : 'rejected'}" role="status">
                    <span class="approval-resolved__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24">
                            ${isApproved ? '<path d="m5 12 4 4L19 6"/>' : '<path d="m7 7 10 10M17 7 7 17"/>'}
                        </svg>
                    </span>
                    <div>
                        <h2>${title}</h2>
                        <p>${continuation}</p>
                        ${approver ? `<small>Resolución registrada por <strong>${escapeHtml(approver)}</strong>.</small>` : ''}
                        ${pdfButtonHtml}
                    </div>
                </div>`;

                card.dataset.token = '';
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                buttons.forEach((item) => { item.disabled = false; });
            }
        } catch (error) {
            result.textContent = 'Error de conexión con el servidor. Intenta nuevamente.';
            buttons.forEach((item) => { item.disabled = false; });
        }
    }));

    function escapeHtml(value) {
        const node = document.createElement('span');
        node.textContent = String(value || '');
        return node.innerHTML;
    }
}());
