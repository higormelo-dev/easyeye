export default (cardsUrl, storageKey) => ({
    view: 'table',
    settings: [],
    meta: { total: 0, per_page: 12, current_page: 1, last_page: 1 },
    search: '',
    loading: false,

    init() {
        const saved = localStorage.getItem(storageKey);
        if (saved === 'cards' || saved === 'table') {
            this.view = saved;
        }
        if (this.view === 'cards') {
            this.fetchCards(1);
        }
    },

    setView(view) {
        this.view = view;
        localStorage.setItem(storageKey, view);
        if (view === 'cards') {
            this.fetchCards(1);
        } else {
            this.$nextTick(() => {
                const dt = Object.values(window.LaravelDataTables ?? {})[0];
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
            const dt = Object.values(window.LaravelDataTables ?? {})[0];
            if (dt) dt.search(this.search).draw();
        }
    },

    clearSearch() {
        this.search = '';
        this.performSearch();
    },

    reloadCurrentView() {
        if (this.view === 'cards') {
            this.fetchCards(this.meta.current_page);
        } else {
            Object.values(window.LaravelDataTables ?? {})[0]?.ajax.reload();
        }
    },

    fetchCards(page) {
        this.loading = true;
        const url = new URL(cardsUrl);
        url.searchParams.set('search', this.search);
        url.searchParams.set('page', page);

        fetch(url.toString(), {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        })
            .then(r => r.json())
            .then(res => {
                this.settings = res.data;
                this.meta     = res.meta;
                this.loading  = false;
            })
            .catch(() => { this.loading = false; });
    },
});
