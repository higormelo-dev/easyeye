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

        this.fetchList();
    },

    setDoctor(id) {
        this.doctor = id;
        this.fetchList();
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
            })
            .catch(err => {
                const msg = err?.message ?? 'Erro ao carregar agendamentos.';
                Swal.fire('Erro!', msg, 'error');
            })
            .finally(() => { this.loading = false; });
    },
});
