// Lógica específica da página de agendamentos.
// O componente Alpine `scheduleView` está registrado globalmente em app.js.

document.addEventListener('DOMContentLoaded', () => {
    const csrf          = () => document.querySelector('meta[name="csrf-token"]').content;
    const scheduleUrl   = id => `/panel/schedules/${id}`;
    const situationUrl  = id => `/panel/schedules/${id}/situation`;
    const rescheduleUrl = id => `/panel/schedules/${id}/reschedule`;
    const bulkUpdateUrl     = '/panel/schedules/bulk-update';
    const bulkRescheduleUrl = '/panel/schedules/bulk-reschedule';

    // Acessa a instância do Alpine scheduleView (exposta em scheduleView.js init())
    const sv = () => window.scheduleViewComponent ?? null;

    const listEl = () => document.getElementById('list-schedule');

    const patchSituation = async (id, situation, notes = null) => {
        const res = await fetch(situationUrl(id), {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ situation, notes }),
        });

        if (! res.ok) {
            const err = await res.json();
            throw new Error(err.message ?? 'Erro ao atualizar situação.');
        }

        return res.json();
    };

    const refreshList = () => window.dispatchEvent(new CustomEvent('schedule-saved'));

    // ── Visualizar agendamento ─────────────────────────────────────────────
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-show');
        if (! btn || ! listEl()?.contains(btn)) return;

        const id = btn.dataset.id;
        document.querySelector('.modal-title-default').textContent = 'Visualizar Agendamento';
        document.getElementById('btn-modal-default').style.display = 'none';
        document.getElementById('erro-default').style.display      = 'none';

        fetch(scheduleUrl(id), {
            headers: { 'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(res => res.text())
            .then(html => {
                document.getElementById('retorno-default').innerHTML = html;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modal_default')).show();
            })
            .catch(() => { if (window.showErrorToast) showErrorToast('Não foi possível carregar o agendamento.'); });
    });

    // ── Mudança de situação genérica (btn-situation) ───────────────────────
    document.body.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-situation');
        if (! btn || ! listEl()?.contains(btn)) return;

        const id  = btn.dataset.id;
        const to  = parseInt(btn.dataset.to);
        btn.disabled = true;

        try {
            await patchSituation(id, to);
            refreshList();
            if (window.showSuccessToast) showSuccessToast('Situação atualizada.');
        } catch (err) {
            if (window.showErrorToast) showErrorToast(err.message);
            btn.disabled = false;
        }
    });

    // ── Cancelar (pede motivo) ─────────────────────────────────────────────
    document.body.addEventListener('click', async (e) => {
        const btn = e.target.closest('.btn-cancel');
        if (! btn || ! listEl()?.contains(btn)) return;

        const id = btn.dataset.id;

        const { value: reason, isConfirmed } = await Swal.fire({
            title: 'Cancelar agendamento',
            input: 'textarea',
            inputLabel: 'Motivo do cancelamento',
            inputPlaceholder: 'Informe o motivo...',
            inputAttributes: { rows: 3 },
            inputValidator: v => v.trim() ? null : 'O motivo é obrigatório.',
            showCancelButton: true,
            confirmButtonText: 'Cancelar agendamento',
            cancelButtonText: 'Voltar',
            confirmButtonColor: '#dc3545',
        });

        if (! isConfirmed) return;

        btn.disabled = true;
        try {
            await patchSituation(id, 9, reason); // 9 = Cancelled
            refreshList();
            if (window.showSuccessToast) showSuccessToast('Agendamento cancelado.');
        } catch (err) {
            if (window.showErrorToast) showErrorToast(err.message);
            btn.disabled = false;
        }
    });

    // ── Reagendar ──────────────────────────────────────────────────────────
    let _rescheduleId = null;

    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-reschedule');
        if (! btn || ! listEl()?.contains(btn)) return;

        _rescheduleId = btn.dataset.id;

        // Preenche select de médicos
        let doctors = [];
        try { doctors = JSON.parse(btn.dataset.doctors || '[]'); } catch {}

        const sel = document.getElementById('reschedule-doctor-id');
        sel.innerHTML = '';
        doctors.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d.id;
            opt.textContent = d.name;
            if (d.id === btn.dataset.doctor_id) opt.selected = true;
            sel.appendChild(opt);
        });

        // Reset campos
        document.getElementById('reschedule-date-time').value = '';
        document.getElementById('reschedule-date-error').textContent = '';
        document.getElementById('reschedule-date-time').classList.remove('is-invalid');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('rescheduleModal')).show();
    });

    document.getElementById('btn-reschedule-save')?.addEventListener('click', async () => {
        const dateTime = document.getElementById('reschedule-date-time').value;
        const doctorId = document.getElementById('reschedule-doctor-id').value;
        const spinner  = document.getElementById('reschedule-spinner');
        const saveBtn  = document.getElementById('btn-reschedule-save');
        const dateInput = document.getElementById('reschedule-date-time');
        const dateError = document.getElementById('reschedule-date-error');

        if (! dateTime) {
            dateInput.classList.add('is-invalid');
            dateError.textContent = window.schedulesLang?.rescheduleErrorDate ?? 'A data e hora são obrigatórias.';
            return;
        }

        dateInput.classList.remove('is-invalid');
        dateError.textContent = '';
        spinner.classList.remove('d-none');
        saveBtn.disabled = true;

        try {
            const res = await fetch(rescheduleUrl(_rescheduleId), {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf(),
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ date_time: dateTime, doctor_id: doctorId }),
            });

            if (! res.ok) {
                const err = await res.json();
                throw new Error(err.message ?? 'Erro ao reagendar.');
            }

            bootstrap.Modal.getOrCreateInstance(document.getElementById('rescheduleModal')).hide();
            refreshList();
            if (window.showSuccessToast) showSuccessToast(window.schedulesLang?.rescheduleSuccess ?? 'Reagendamento realizado com sucesso.');
        } catch (err) {
            if (window.showErrorToast) showErrorToast(err.message);
        } finally {
            spinner.classList.add('d-none');
            saveBtn.disabled = false;
        }
    });

    // ── Humor do paciente ──────────────────────────────────────────────────
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-mood');
        if (! btn || ! listEl()?.contains(btn)) return;

        const id   = btn.dataset.id;
        const mood = btn.dataset.mood || null;

        fetch(`/panel/schedules/${id}/mood`, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': csrf(),
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ mood }),
        })
            .then(r => r.ok ? refreshList() : r.json().then(d => { throw new Error(d.message); }))
            .catch(err => { if (window.showErrorToast) showErrorToast(err.message); });
    });

    // ── Abrir ficha do paciente em modal ──────────────────────────────────
    document.body.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-patient');
        if (! btn) return;

        const id = btn.dataset.id;
        window.dispatchEvent(new CustomEvent('edit-patient', { detail: { id } }));
    });

    // ── Checkbox de seleção em massa ──────────────────────────────────────
    document.body.addEventListener('change', (e) => {
        const cb = e.target.closest('.schedule-checkbox');
        if (! cb || ! listEl()?.contains(cb)) return;

        sv()?.toggleSelect(cb.dataset.id);
    });

    // Clicar em qualquer parte do card (em modo seleção) também marca/desmarca
    document.body.addEventListener('click', (e) => {
        const view = sv();
        if (! view?.selectionMode) return;

        const card = e.target.closest('.schedule-card');
        if (! card || ! listEl()?.contains(card)) return;

        // Ignora cliques em botões de ação dentro do card
        if (e.target.closest('button, a, input')) return;

        const id = card.dataset.scheduleId;
        if (id) {
            view.toggleSelect(id);
            view._syncCheckboxes();
        }
    });

    // ── Envio de ação em massa ─────────────────────────────────────────────
    const doBulkUpdate = async (situation, notes = null) => {
        const view = sv();
        if (! view || view.selectedIds.length === 0) return;

        const ids = [...view.selectedIds];

        try {
            const res = await fetch(bulkUpdateUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN':     csrf(),
                    'Content-Type':     'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ ids, situation, notes }),
            });

            const data = await res.json();

            if (! res.ok) {
                const msg = data.errors
                    ? Object.values(data.errors).flat().join('\n')
                    : (data.message ?? 'Erro ao atualizar.');
                throw new Error(msg);
            }

            view.clearSelection();
            refreshList();
            if (window.showSuccessToast) showSuccessToast(data.message);
        } catch (err) {
            if (window.showErrorToast) showErrorToast(err.message);
        }
    };

    // ── Confirmar em massa ─────────────────────────────────────────────────
    document.body.addEventListener('click', (e) => {
        if (! e.target.closest('#btn-bulk-confirm')) return;
        doBulkUpdate(2); // Confirmed
    });

    // ── Faltou em massa ────────────────────────────────────────────────────
    document.body.addEventListener('click', (e) => {
        if (! e.target.closest('#btn-bulk-noshow')) return;
        doBulkUpdate(8); // NoShow
    });

    // ── Cancelar em massa ──────────────────────────────────────────────────
    document.body.addEventListener('click', async (e) => {
        if (! e.target.closest('#btn-bulk-cancel')) return;

        const { value: reason, isConfirmed } = await Swal.fire({
            title:            'Cancelar agendamentos selecionados',
            input:            'textarea',
            inputLabel:       'Motivo do cancelamento',
            inputPlaceholder: 'Ex.: Médico indisposto, feriado...',
            inputAttributes:  { rows: 3 },
            inputValidator:   v => v.trim() ? null : 'O motivo é obrigatório.',
            showCancelButton:    true,
            confirmButtonText:   'Cancelar agendamentos',
            cancelButtonText:    'Voltar',
            confirmButtonColor:  '#dc3545',
        });

        if (! isConfirmed) return;
        doBulkUpdate(9, reason); // Cancelled
    });

    // ── Alterar data em massa ──────────────────────────────────────────────
    document.body.addEventListener('click', async (e) => {
        if (! e.target.closest('#btn-bulk-reschedule')) return;

        const today = new Date().toISOString().substring(0, 10);

        const { value: formValues, isConfirmed } = await Swal.fire({
            title:             'Alterar data dos agendamentos',
            html: `
                <div class="mb-3 text-start">
                    <label class="form-label fw-semibold">Nova data <span class="text-danger">*</span></label>
                    <input id="swal-bulk-date" type="date" class="form-control" min="${today}">
                </div>
                <div class="text-start">
                    <label class="form-label fw-semibold">Novo horário
                        <small class="text-muted fw-normal">— opcional, mantém o horário original se vazio</small>
                    </label>
                    <input id="swal-bulk-time" type="time" class="form-control">
                </div>`,
            confirmButtonText: 'Alterar data',
            cancelButtonText:  'Voltar',
            showCancelButton:  true,
            focusConfirm:      false,
            preConfirm() {
                const date = document.getElementById('swal-bulk-date').value;
                if (! date) {
                    Swal.showValidationMessage('A nova data é obrigatória.');
                    return false;
                }
                return {
                    date,
                    time: document.getElementById('swal-bulk-time').value || null,
                };
            },
        });

        if (! isConfirmed) return;

        const view = sv();
        if (! view || view.selectedIds.length === 0) return;

        try {
            const res  = await fetch(bulkRescheduleUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN':     csrf(),
                    'Content-Type':     'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    ids:  [...view.selectedIds],
                    date: formValues.date,
                    time: formValues.time,
                }),
            });
            const data = await res.json();

            if (! res.ok) {
                // Mostra os erros de validação em detalhe
                const msg = data.errors
                    ? Object.values(data.errors).flat().join('\n')
                    : (data.message ?? 'Erro ao alterar data.');
                throw new Error(msg);
            }

            view.clearSelection();
            refreshList();
            if (window.showSuccessToast) showSuccessToast(data.message);
        } catch (err) {
            if (window.showErrorToast) showErrorToast(err.message);
        }
    });

    // ── Timer de espera (atualiza a cada minuto) ───────────────────────────
    const updateWaitingTimers = () => {
        document.querySelectorAll('.waiting-timer').forEach(el => {
            const arrived = new Date(el.dataset.arrived);
            const mins    = Math.floor((Date.now() - arrived.getTime()) / 60000);
            const text    = el.querySelector('.timer-text');
            if (! text) return;

            text.textContent = mins < 1 ? 'agora' : `${mins} min`;

            el.classList.remove('bg-success', 'bg-warning', 'bg-danger', 'text-dark', 'waiting-timer-urgent');
            if (mins >= 30) {
                el.classList.add('bg-danger', 'waiting-timer-urgent');
            } else if (mins >= 15) {
                el.classList.add('bg-warning', 'text-dark');
            } else {
                el.classList.add('bg-success');
            }
        });
    };

    updateWaitingTimers();
    setInterval(updateWaitingTimers, 60_000);
});
