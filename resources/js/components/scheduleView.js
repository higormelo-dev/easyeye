const pad = n => String(n).padStart(2, '0');

const todayFormatted = () => {
    const d = new Date();
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`;
};

const defaultBout = () => {
    const h = new Date().getHours();
    if (h < 13)  return 2; // Manhã
    if (h < 18)  return 3; // Tarde
    return 4;              // Noite
};

export default (ajaxUrl, csrfToken, initialDoctor) => ({
    calendar: todayFormatted(),
    doctor: initialDoctor || 'tudo',
    bout: defaultBout(),
    search: '',
    loading: false,
    _fp: null,

    // ── Seleção em massa ────────────────────────────────────────────────
    selectionMode: false,
    selectedIds:   [],

    // ── Lista de espera ─────────────────────────────────────────────────
    waitingEntries:   [],
    waitingCount:     0,
    showWaitingPanel: false,
    _waitingUrl:      '/panel/waiting-list',

    init() {
        this._fp = flatpickr(this.$refs.calendarPicker, {
            inline: true,
            defaultDate: 'today',
            onChange: ([date]) => {
                if (!date) return;
                this.calendar = `${pad(date.getDate())}/${pad(date.getMonth() + 1)}/${date.getFullYear()}`;
                this.fetchList();
            },
        });

        // Expõe a instância para o schedules.js (event delegation vanilla)
        window.scheduleViewComponent = this;

        // Atualiza lista de espera após salvar um agendamento
        window.addEventListener('schedule-saved', () => this.fetchWaiting());

        this.fetchList();
        this.fetchWaiting();
    },

    setDoctor(id) {
        this.doctor = id;
        this.fetchList();
        this.fetchWaiting();
    },

    setBout(value) {
        this.bout = value;
        this.fetchList();
    },

    fetchList() {
        this.loading = true;

        fetch(ajaxUrl, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: new URLSearchParams({
                date:   this.calendar,
                doctor: this.doctor,
                bout:   this.bout,
                search: this.search,
            }),
        })
            .then(r => {
                if (!r.ok) return r.json().then(e => Promise.reject(e));
                return r.text();
            })
            .then(html => {
                document.getElementById('list-schedule').innerHTML = html;
                this._syncCheckboxes();
            })
            .catch(err => {
                const msg = err?.message ?? 'Erro ao carregar agendamentos.';
                Swal.fire('Erro!', msg, 'error');
            })
            .finally(() => { this.loading = false; });
    },

    // ── Lista de espera ─────────────────────────────────────────────────

    fetchWaiting() {
        const doctor = this.doctor === 'tudo' ? '' : this.doctor;
        const url    = doctor
            ? `${this._waitingUrl}?doctor_id=${encodeURIComponent(doctor)}`
            : this._waitingUrl;

        fetch(url, {
            headers: {
                'X-CSRF-TOKEN':     csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
        })
            .then(r => r.json())
            .then(data => {
                this.waitingEntries = data.data ?? [];
                this.waitingCount   = data.count ?? 0;
            })
            .catch(() => {});
    },

    toggleWaitingPanel() {
        this.showWaitingPanel = ! this.showWaitingPanel;
    },

    /**
     * Pre-fills the crudForm schedule modal with data from a waiting list entry
     * and opens the modal so the user can pick a date/time.
     */
    scheduleFromWaiting(entry) {
        window.dispatchEvent(new CustomEvent('prefill-from-waiting', { detail: entry }));
    },

    /**
     * Removes an entry from the waiting list immediately (optimistic UI).
     */
    removeWaiting(id) {
        fetch(`${this._waitingUrl}/${id}`, {
            method:  'DELETE',
            headers: {
                'X-CSRF-TOKEN':     csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
        })
            .then(r => r.json())
            .then(() => {
                this.waitingEntries = this.waitingEntries.filter(e => e.id !== id);
                this.waitingCount   = this.waitingEntries.length;
                if (window.showSuccessToast) showSuccessToast('Removido da lista de espera.');
            })
            .catch(() => {
                if (window.showErrorToast) showErrorToast('Erro ao remover da lista.');
            });
    },

    /**
     * Sends the updated order to the backend after drag-and-drop or arrow buttons.
     */
    reorderWaiting() {
        const ids = this.waitingEntries.map(e => e.id);

        fetch(`${this._waitingUrl}/reorder`, {
            method:  'PATCH',
            headers: {
                'X-CSRF-TOKEN':     csrfToken,
                'Content-Type':     'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept':           'application/json',
            },
            body: JSON.stringify({ ids }),
        }).catch(() => {});
    },

    moveWaiting(index, direction) {
        const target = index + direction;
        if (target < 0 || target >= this.waitingEntries.length) return;

        const entries = [...this.waitingEntries];
        [entries[index], entries[target]] = [entries[target], entries[index]];
        this.waitingEntries = entries;

        this.reorderWaiting();
    },

    // ── Seleção em massa ────────────────────────────────────────────────

    toggleSelectionMode() {
        this.selectionMode = ! this.selectionMode;
        if (! this.selectionMode) this.selectedIds = [];
        this._syncCheckboxes();
    },

    clearSelection() {
        this.selectionMode = false;
        this.selectedIds   = [];
        this._syncCheckboxes();
    },

    toggleSelect(id) {
        const idx = this.selectedIds.indexOf(id);
        if (idx === -1) this.selectedIds.push(id);
        else            this.selectedIds.splice(idx, 1);
    },

    toggleSelectAll() {
        const all = [...document.querySelectorAll('#list-schedule .schedule-checkbox')]
            .map(el => el.dataset.id);

        this.selectedIds = this.selectedIds.length === all.length ? [] : all;
        this._syncCheckboxes();
    },

    isAllSelected() {
        const all = document.querySelectorAll('#list-schedule .schedule-checkbox');
        return all.length > 0 && this.selectedIds.length === all.length;
    },

    _syncCheckboxes() {
        document.querySelectorAll('#list-schedule .schedule-checkbox').forEach(el => {
            el.checked = this.selectedIds.includes(el.dataset.id);
        });
    },
});
