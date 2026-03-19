// Lógica específica da página de agendamentos.
// O componente Alpine `scheduleView` está registrado globalmente em app.js.

document.addEventListener('DOMContentLoaded', () => {
    const scheduleUrl  = id => `/panel/schedules/${id}`;
    const situationUrl = id => `/panel/schedules/${id}/situation`;

    document.getElementById('list-schedule')?.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-show');
        if (!btn) return;

        const id = btn.dataset.id;
        document.querySelector('.modal-title-default').textContent = 'Visualizar Agendamento';
        document.getElementById('btn-modal-default').style.display  = 'none';
        document.getElementById('erro-default').style.display       = 'none';

        fetch(scheduleUrl(id), {
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'X-Requested-With': 'XMLHttpRequest',
            },
        })
            .then(res => res.text())
            .then(html => {
                document.getElementById('retorno-default').innerHTML = html;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modal_default')).show();
            })
            .catch(() => {
                if (window.showErrorToast) showErrorToast('Não foi possível carregar o agendamento.');
            });
    });

    document.getElementById('list-schedule')?.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-arrived');
        if (!btn) return;

        const id = btn.dataset.id;
        btn.disabled = true;

        try {
            const res = await fetch(situationUrl(id), {
                method: 'PATCH',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ situation: 2 }), // Waiting
            });

            if (!res.ok) {
                const err = await res.json();
                Swal.fire('Erro!', err.message ?? 'Não foi possível marcar chegada.', 'error');
                btn.disabled = false;
                return;
            }

            // Refresh the schedule list via Alpine
            window.dispatchEvent(new CustomEvent('schedule-saved'));
        } catch {
            Swal.fire('Erro!', 'Não foi possível marcar chegada.', 'error');
            btn.disabled = false;
        }
    });
});
