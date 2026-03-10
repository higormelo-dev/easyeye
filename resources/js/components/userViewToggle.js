export default (cardsUrl, fallbackPhoto) => ({
    view: 'table',
    fallbackPhoto,
    users: [],
    meta: { total: 0, per_page: 12, current_page: 1, last_page: 1 },
    search: '',
    loading: false,

    init() {
        const saved = localStorage.getItem('users_view');
        if (saved === 'cards' || saved === 'table') this.view = saved;
        window.userViewComponent = this;

        if (this.view === 'cards') this.fetchCards(1);
    },

    setView(view) {
        this.view = view;
        localStorage.setItem('users_view', view);

        if (view === 'cards') {
            this.fetchCards(1);
        } else {
            this.$nextTick(() => {
                const dt = window.LaravelDataTables?.['users_datatable'];
                if (!dt) return;
                $(dt.table().node()).width('100%');
                dt.search(this.search).draw();
            });
        }
    },

    performSearch() {
        if (this.view === 'cards') {
            this.fetchCards(1);
        } else {
            window.LaravelDataTables?.['users_datatable']?.search(this.search).draw();
        }
    },

    clearSearch() {
        this.search = '';
        this.performSearch();
    },

    fetchCards(page) {
        this.loading = true;
        const url = new URL(cardsUrl);
        url.searchParams.set('search', this.search);
        url.searchParams.set('page', page);

        fetch(url.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
        })
            .then(r => r.json())
            .then(res => {
                this.users   = res.data;
                this.meta    = res.meta;
                this.loading = false;
                this.$nextTick(() => {
                    document.querySelectorAll('[data-bs-toggle="tooltip"]')
                        .forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
                });
            })
            .catch(() => { this.loading = false; });
    },
});
