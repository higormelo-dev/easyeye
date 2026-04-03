/**
 * managerViewToggle — Alpine.js component for DataTable / Cards toggle view.
 *
 * Replicates the same UX pattern used in /panel/accesscontrol/users.
 *
 * @param {string} cardsUrl     — GET endpoint that returns paginated JSON for cards
 * @param {string} storageKey   — localStorage key to persist view preference
 * @param {string} datatableId  — ID of the Yajra DataTable instance (e.g. 'entities_datatable')
 */
export default (cardsUrl, storageKey, datatableId) => ({
    view: 'table',
    items: [],
    meta: { total: 0, per_page: 12, current_page: 1, last_page: 1 },
    search: '',
    loading: false,

    init() {
        const saved = localStorage.getItem(storageKey);
        if (saved === 'cards' || saved === 'table') this.view = saved;

        if (this.view === 'cards') this.fetchCards(1);
    },

    setView(view) {
        this.view = view;
        localStorage.setItem(storageKey, view);

        if (view === 'cards') {
            this.fetchCards(1);
        } else {
            this.$nextTick(() => {
                const dt = window.LaravelDataTables?.[datatableId];
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
            window.LaravelDataTables?.[datatableId]?.search(this.search).draw();
        }
    },

    clearSearch() {
        this.search = '';
        this.performSearch();
    },

    fetchCards(page) {
        this.loading = true;
        const url = new URL(cardsUrl, window.location.origin);
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
                this.items   = res.data;
                this.meta    = res.meta;
                this.loading = false;
                this.$nextTick(() => {
                    document.querySelectorAll('[data-bs-toggle="tooltip"]')
                        .forEach(el => new bootstrap.Tooltip(el, { trigger: 'hover' }));
                });
            })
            .catch(() => { this.loading = false; });
    },

    refreshCards() {
        this.fetchCards(this.meta.current_page);
    },
});
