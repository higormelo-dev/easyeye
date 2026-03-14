/**
 * patientSearch — Alpine.js component para busca e cadastro rápido de paciente.
 *
 * Deve ser usado dentro de um elemento que tenha acesso ao crudForm via $parent.
 * Ao selecionar ou criar um paciente, atualiza automaticamente:
 *   $parent.form.patient_id, full_name, telephone e cellphone.
 *
 * Uso:
 *   <div x-data="patientSearch(searchUrl, quickStoreUrl)">...</div>
 */
export default (searchUrl, quickStoreUrl) => ({
    query:           '',
    results:         [],
    searching:       false,
    showResults:     false,
    selectedPatient: null,
    showCreate:      false,
    creating:        false,
    newPatient:      { name: '', cellphone: '' },

    async search() {
        if (this.query.length < 2) {
            this.results     = [];
            this.showResults = false;
            return;
        }

        this.searching = true;

        try {
            const res = await fetch(`${searchUrl}?q=${encodeURIComponent(this.query)}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept':           'application/json',
                },
            });

            this.results     = await res.json();
            this.showResults = true;
        } finally {
            this.searching = false;
        }
    },

    select(patient) {
        this.selectedPatient = patient;
        this.results         = [];
        this.showResults     = false;
        this.query           = '';

        this.$dispatch('patient-selected', patient);
    },

    clear() {
        this.selectedPatient = null;
        this.$dispatch('patient-cleared');
    },

    prefillCreate() {
        this.newPatient.name = this.query;
        this.showCreate      = true;
        this.showResults     = false;
    },

    async createAndLink() {
        if (!this.newPatient.name || !this.newPatient.cellphone) return;

        this.creating = true;

        try {
            const res = await fetch(quickStoreUrl, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]').content,
                    'Content-Type':     'application/json',
                    'Accept':           'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(this.newPatient),
            });

            const data = await res.json();

            if (!res.ok) {
                if (res.status === 422) {
                    const firstError = Object.values(data.errors ?? {})[0]?.[0] ?? 'Erro de validação.';
                    Swal.fire({ icon: 'warning', title: 'Atenção', text: firstError });
                    return;
                }
                throw new Error(data.message ?? 'Erro ao criar paciente.');
            }

            this.select(data.patient);
            this.showCreate  = false;
            this.newPatient  = { name: '', cellphone: '' };
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Erro', text: e.message });
        } finally {
            this.creating = false;
        }
    },

    closeResults() {
        setTimeout(() => { this.showResults = false; }, 200);
    },

    reset() {
        this.query           = '';
        this.results         = [];
        this.showResults     = false;
        this.selectedPatient = null;
        this.showCreate      = false;
        this.newPatient      = { name: '', cellphone: '' };
    },
});
