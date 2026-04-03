/**
 * cid10Search — Alpine.js component para busca e seleção de múltiplos CIDs.
 *
 * Uso:
 *   <div x-data="cid10Search(searchUrl, initialCids)">
 *
 * O component mantém um array interno `selected` com objetos {code, description}.
 * Um input hidden serializa esse array como JSON para o submit do formulário.
 * O médico pode adicionar quantos diagnósticos desejar e remover individualmente.
 */
export default (searchUrl, initialCids = []) => ({
    query: '',
    selected: Array.isArray(initialCids) ? initialCids : [],
    results: [],
    searching: false,
    open: false,
    activeIndex: -1,

    get hasSelection() {
        return this.selected.length > 0;
    },

    /** JSON serializado para o input hidden que será submetido no form */
    get serialized() {
        return JSON.stringify(this.selected);
    },

    async search() {
        if (this.query.length < 2) {
            this.results = [];
            this.open = false;
            return;
        }
        this.searching = true;
        try {
            const res = await fetch(
                `${searchUrl}?q=${encodeURIComponent(this.query)}`,
                { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } },
            );
            const all = await res.json();
            // Remove CIDs já selecionados da lista de sugestões
            const selectedCodes = this.selected.map((s) => s.code);
            this.results = all.filter((item) => !selectedCodes.includes(item.code));
            this.activeIndex = -1;
            this.open = this.results.length > 0;
        } finally {
            this.searching = false;
        }
    },

    select(item) {
        if (this.selected.find((s) => s.code === item.code)) return;
        this.selected.push({ code: item.code, description: item.description });
        this.query = '';
        this.results = [];
        this.open = false;
        this.activeIndex = -1;
    },

    remove(code) {
        this.selected = this.selected.filter((s) => s.code !== code);
    },

    selectActive() {
        if (this.activeIndex >= 0 && this.activeIndex < this.results.length) {
            this.select(this.results[this.activeIndex]);
        }
    },

    focusResult(index) {
        if (this.results.length === 0) return;
        this.activeIndex = Math.max(0, Math.min(index, this.results.length - 1));
    },

    close() {
        this.open = false;
        this.activeIndex = -1;
    },
});
