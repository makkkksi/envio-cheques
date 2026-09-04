(function () {
    'use strict';
    const card = document.querySelector('.approval-card');
    const buttons = [...document.querySelectorAll('[data-decision]')];
    const result = document.getElementById('resultado');
    const comment = document.getElementById('comentario');
    const decisionSection = document.querySelector('.approval-decision');
    const headerTitle = document.querySelector('.approval-header h1');
    const headerDescription = document.querySelector('.approval-header p');
    const docList = document.getElementById('approvalDocList');
    const metricTotalApproved = document.getElementById('metricTotalApproved');
    const metricExcess = document.getElementById('metricExcess');
    const excessAlertBox = document.getElementById('excessAlertBox');
    const excessAmountText = document.getElementById('excessAmountText');
    const btnApproveTope = document.getElementById('btnApproveTope');
    const btnTopeSubtext = document.getElementById('btnTopeSubtext');

    if (!card || !buttons.length || !result || !comment) return;

    window.history.replaceState({}, document.title, window.location.pathname);

    const moneyFormatter = new Intl.NumberFormat('es-CL', {
        style: 'currency',
        currency: 'CLP',
        maximumFractionDigits: 0
    });

    function formatMoney(amount) {
        return moneyFormatter.format(Number(amount || 0));
    }

    function updateLiveFinancials(event) {
        if (event?.target?.matches('[data-doc-decision]')) {
            const docCard = event.target.closest('[data-doc-id]');
            const isRejected = event.target.value === 'RECHAZAR';
            const amountInput = docCard.querySelector('[data-doc-amount]');
            const reasonInput = docCard.querySelector('[data-doc-reason]');
            amountInput.disabled = isRejected;
            reasonInput.disabled = !isRejected;
            if (isRejected) {
                docCard.classList.remove('is-approved');
                docCard.classList.add('is-rejected');
                reasonInput.focus();
            } else {
                docCard.classList.remove('is-rejected');
                docCard.classList.add('is-approved');
            }
        }

        let totalApproved = 0;
        const cards = document.querySelectorAll('[data-doc-id]');
        cards.forEach((docCard) => {
            const decSelect = docCard.querySelector('[data-doc-decision]');
            const amtInput = docCard.querySelector('[data-doc-amount]');
            if (decSelect && decSelect.value === 'APROBAR') {
                totalApproved += Number(amtInput?.value || 0);
            }
        });

        if (metricTotalApproved) {
            metricTotalApproved.textContent = formatMoney(totalApproved);
        }

        const budget = Number(card.dataset.budget || 0);
        const excess = Math.max(0, totalApproved - budget);

        if (metricExcess) {
            metricExcess.textContent = excess > 0 ? `+${formatMoney(excess)}` : '$0';
            metricExcess.style.color = excess > 0 ? '#b91c1c' : '#64748b';
        }

        if (excessAlertBox) {
            excessAlertBox.style.display = excess > 0 ? 'block' : 'none';
        }
        if (excessAmountText) {
            excessAmountText.textContent = formatMoney(excess);
        }

        if (btnApproveTope) {
            btnApproveTope.style.display = excess > 0 ? 'inline-block' : 'none';
        }
        if (btnTopeSubtext) {
            btnTopeSubtext.textContent = `Sin cubrir exceso (${formatMoney(excess)})`;
        }
    }

    if (docList) {
        docList.addEventListener('change', updateLiveFinancials);
        docList.addEventListener('input', updateLiveFinancials);
        updateLiveFinancials();
    }

    buttons.forEach((button) => button.addEventListener('click', async function () {
        const decision = button.dataset.decision;
        const generalComment = comment.value.trim();

        if (decision === 'RECHAZADO' && !generalComment) {
            result.textContent = 'Indica el motivo del rechazo en el comentario para que Tesorería y el vendedor puedan coordinar.';
            result.classList.remove('approval-card__result--success');
            comment.focus();
            return;
        }

        // Recolectar decisiones individuales por comprobante
        const decisiones = [];
        const cards = document.querySelectorAll('[data-doc-id]');
        let approvedItemCount = 0;

        for (const docCard of cards) {
            const docId = Number(docCard.dataset.docId);
            const docDecision = docCard.querySelector('[data-doc-decision]').value;
            const docAmount = Number(docCard.querySelector('[data-doc-amount]').value || 0);
            const docReason = (docCard.querySelector('[data-doc-reason]').value || '').trim();

            if (docDecision === 'RECHAZAR' && !docReason && decision !== 'RECHAZADO') {
                result.textContent = 'Cada comprobante rechazado requiere un motivo obligatorio antes de emitir la resolución.';
                result.classList.remove('approval-card__result--success');
                const reasonInput = docCard.querySelector('[data-doc-reason]');
                reasonInput.focus();
                return;
            }

            if (docDecision === 'APROBAR') {
                approvedItemCount++;
            }

            decisiones.push({
                documento_id: docId,
                decision: docDecision,
                monto_validado: docAmount,
                motivo: docReason
            });
        }

        if (decision !== 'RECHAZADO' && approvedItemCount === 0) {
            result.textContent = 'No puedes aprobar una rendición si todos los comprobantes individuales están rechazados. Usa "Rechazar Rendición".';
            result.classList.remove('approval-card__result--success');
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
                    comentario: generalComment,
                    decisiones
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
                        ? `La rendición fue aprobada solo hasta el presupuesto asignado por un total neto de ${formatMoney(data.monto_aprobado)}. El exceso no será reembolsado. Tesorería ha sido notificada.`
                        : `La rendición ha sido oficialmente autorizada por un total neto de ${formatMoney(data.monto_aprobado)}. Tesorería ha recibido la confirmación para proceder con el pago.`)
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

                if (docList) {
                    docList.style.pointerEvents = 'none';
                    docList.style.opacity = '0.7';
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
