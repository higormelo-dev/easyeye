/**
 * datatableManager — Alpine.js component genérico para módulos com DataTable + modal CRUD.
 *
 * Substitui os arquivos system/*.js individuais (jQuery) de todos os módulos simples.
 *
 * Uso na Blade (index.blade.php):
 *   <div x-data="datatableManager({
 *       baseUrl:     '{{ route('panel.setting.covenants.index') }}',
 *       entityName:  '{{ mb_convert_case(__('actions.covenant'), MB_CASE_LOWER, 'UTF-8') }}',
 *       datatableId: 'covenants_datatable',
 *       modalSize:   'modal-xl',
 *   })">
 *
 * @param {string} baseUrl      — URL base do resource (ex: /panel/setting/covenants)
 * @param {string} entityName   — Nome da entidade para mensagens (ex: "convênio")
 * @param {string} datatableId  — ID do elemento <table> do DataTable
 * @param {string} modalSize    — Classe de tamanho do modal Bootstrap (padrão: modal-lg)
 */
export default ({ baseUrl, entityName, datatableId, modalSize = 'modal-lg' }) => ({
    _action:   'store',
    _recordId: null,

    get _csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
    },

    get _table() {
        return window.LaravelDataTables?.[datatableId];
    },

    // ── Inicialização ────────────────────────────────────────────────────────

    init() {
        // Botão "Novo registro" da subnav
        document.querySelector('.new-register')
            ?.addEventListener('click', e => { e.preventDefault(); this.openNew(); });

        // Bind nos botões das linhas do DataTable (re-bind a cada draw)
        // draw.dt é evento jQuery — deve-se usar $.on, não addEventListener
        const tableEl = document.getElementById(datatableId);
        if (!tableEl) return;

        $(tableEl).on('draw.dt', () => this._bindRowButtons(tableEl));
    },

    _bindRowButtons(tableEl) {
        tableEl.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
            window.bootstrap?.Tooltip.getInstance(el)?.dispose();
            if (window.bootstrap) new window.bootstrap.Tooltip(el, { trigger: 'hover' });
        });

        tableEl.querySelectorAll('.btn-edit')
            .forEach(b => (b.onclick = () => this.openEdit(b.dataset.id)));

        tableEl.querySelectorAll('.btn-show')
            .forEach(b => (b.onclick = () => this.openShow(b.dataset.id)));

        tableEl.querySelectorAll('.btn-active')
            .forEach(b => (b.onclick = () => this.toggleActive(b.dataset.id, b.dataset.situation)));

        tableEl.querySelectorAll('.btn-trash')
            .forEach(b => (b.onclick = () => this.confirmDelete(b.dataset.id)));

        tableEl.querySelectorAll('.btn-restore')
            .forEach(b => (b.onclick = () => this.confirmRestore(b.dataset.id)));
    },

    // ── Abertura do modal ────────────────────────────────────────────────────

    async openNew() {
        this._action   = 'store';
        this._recordId = null;
        this._prepareModal(this._t('register'), true);

        const html = await this._fetchHtml(`${baseUrl}/create`);
        this._setContent(html);
        this._afterLoad();
    },

    async openEdit(id) {
        this._action   = 'update';
        this._recordId = id;
        this._prepareModal(this._t('edit'), true);

        const html = await this._fetchHtml(`${baseUrl}/${id}/edit`);
        this._setContent(html);
        this._afterLoad();
    },

    async openShow(id) {
        this._action   = 'show';
        this._recordId = id;
        this._prepareModal(this._t('view'), false);

        const html = await this._fetchHtml(`${baseUrl}/${id}`);
        this._setContent(html);
    },

    // ── CRUD ─────────────────────────────────────────────────────────────────

    async save() {
        this._setLoading(true);
        this._clearErrors();

        const isUpdate = this._action === 'update';
        const url      = isUpdate ? `${baseUrl}/${this._recordId}` : baseUrl;
        const method   = isUpdate ? 'PUT' : 'POST';

        try {
            const res  = await this._request(url, method, this._collectFormData());
            const json = await res.json();

            if (!res.ok) {
                if (res.status === 422) { this._showErrors(json.errors ?? {}); return; }
                throw new Error(json.message ?? 'Erro inesperado.');
            }

            window.bootstrap?.Modal.getInstance(document.getElementById('modal_default'))?.hide();
            this._toast(json.message, 'success');
            this._table?.ajax.reload();
        } catch (e) {
            this._toast(e.message, 'error');
        } finally {
            this._setLoading(false);
        }
    },

    async toggleActive(id, situation) {
        try {
            const res  = await this._request(`${baseUrl}/${id}`, 'PUT', { type_method: 1, active: situation });
            const json = await res.json();
            if (!res.ok) throw new Error(json.message);
            this._toast(json.message, 'success');
            this._table?.ajax.reload();
        } catch (e) {
            this._toast(e.message, 'error');
        }
    },

    async confirmDelete(id) {
        const confirmed = await this._confirm(
            window.translations?.messages?.delete_confirm_title ?? 'Confirmar exclusão',
            window.translations?.messages?.delete_confirm_text  ?? 'Esta ação não pode ser desfeita.',
            '#d33'
        );
        if (!confirmed) return;

        try {
            const res  = await this._request(`${baseUrl}/${id}`, 'DELETE');
            const json = await res.json();
            if (!res.ok) throw new Error(json.message);
            this._toast(json.message, 'success');
            this._table?.ajax.reload();
        } catch (e) {
            this._toast(e.message, 'error');
        }
    },

    async confirmRestore(id) {
        const confirmed = await this._confirm(
            window.translations?.messages?.restore_confirm_title ?? 'Confirmar restauração',
            window.translations?.messages?.restore_confirm_text  ?? 'Deseja restaurar este registro?',
            '#3085d6'
        );
        if (!confirmed) return;

        try {
            const res  = await this._request(`${baseUrl}/${id}/restore`, 'GET');
            const json = await res.json();
            if (!res.ok) throw new Error(json.message);
            this._toast(json.message, 'success');
            this._table?.ajax.reload();
        } catch (e) {
            this._toast(e.message, 'error');
        }
    },

    // ── Helpers de modal ─────────────────────────────────────────────────────

    _prepareModal(title, showSave) {
        // Título
        const titleEl = document.querySelector('.modal-title-default');
        if (titleEl) titleEl.textContent = title;

        // Tamanho
        const dialog = document.querySelector('#modal_default .modal-dialog');
        if (dialog) {
            dialog.classList.remove('modal-sm', 'modal-md', 'modal-lg', 'modal-xl');
            dialog.classList.add(modalSize);
        }

        // Botão Salvar
        const saveBtn = document.getElementById('btn-modal-default');
        if (saveBtn) {
            saveBtn.style.display = showSave ? 'block' : 'none';
            saveBtn.onclick       = () => this.save();
        }

        // Limpa estado anterior e mostra spinner
        this._clearErrors();
        this._setContent('<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div></div>');

        window.bootstrap?.Modal.getOrCreateInstance(document.getElementById('modal_default')).show();
    },

    _setContent(html) {
        const el = document.getElementById('retorno-default');
        if (el) el.innerHTML = html;
    },

    _setLoading(state) {
        const btn = document.getElementById('btn-modal-default');
        if (btn) btn.disabled = state;
    },

    _clearErrors() {
        const errEl = document.getElementById('erro-default');
        const msgEl = document.getElementById('erro-msg-default');
        if (errEl) { errEl.classList.remove('show'); errEl.style.display = 'none'; }
        if (msgEl) msgEl.innerHTML = '';
    },

    _showErrors(errors) {
        const errEl = document.getElementById('erro-default');
        const msgEl = document.getElementById('erro-msg-default');
        if (!errEl || !msgEl) return;

        let html = '<strong>Erro!</strong> Preencha corretamente os campos abaixo:<br>';
        Object.values(errors).flat().forEach(msg => { html += `- ${msg}<br>`; });

        msgEl.innerHTML = html;
        errEl.style.display = '';
        errEl.classList.add('show');
    },

    // Inicializa plugins injetados no conteúdo do modal (ex: colorpicker)
    _afterLoad() {
        document.querySelectorAll('#retorno-default .colorpicker').forEach(el => {
            if (typeof $.fn?.asColorPicker !== 'undefined') $(el).asColorPicker();
        });
    },

    // ── Coleta de dados do formulário ────────────────────────────────────────

    _collectFormData() {
        const container = document.getElementById('retorno-default');
        if (!container) return {};

        const data = {};
        container.querySelectorAll('input, select, textarea').forEach(el => {
            if (!el.name || el.disabled) return;
            if (el.type === 'checkbox')    data[el.name] = el.checked ? 1 : 0;
            else if (el.type === 'radio')  { if (el.checked) data[el.name] = el.value; }
            else                           data[el.name] = el.value;
        });
        return data;
    },

    // ── Utilitários ──────────────────────────────────────────────────────────

    _t(key) {
        const msg  = window.translations?.messages?.[key] ?? key;
        const name = entityName;
        return msg.replace(':name', name);
    },

    async _confirm(title, text, confirmColor) {
        const trans = window.translations?.messages ?? {};
        const { isConfirmed } = await Swal.fire({
            title,
            text,
            icon:               'warning',
            showCancelButton:   true,
            confirmButtonColor: confirmColor,
            confirmButtonText:  trans.confirm_yes ?? 'Sim',
            cancelButtonText:   trans.confirm_no  ?? 'Não',
        });
        return isConfirmed;
    },

    async _fetchHtml(url) {
        try {
            const res = await fetch(url, {
                headers: {
                    'X-CSRF-TOKEN':     this._csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            if (!res.ok) throw new Error(`HTTP ${res.status}`);
            return res.text();
        } catch (e) {
            return `<p class="text-danger">Erro ao carregar: ${e.message}</p>`;
        }
    },

    _request(url, method, body = null) {
        const opts = {
            method,
            headers: {
                'X-CSRF-TOKEN':     this._csrf,
                'Content-Type':     'application/json',
                'Accept':           'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };
        if (body && !['GET', 'DELETE'].includes(method)) {
            opts.body = JSON.stringify(body);
        }
        return fetch(url, opts);
    },

    _toast(message, type = 'success') {
        if (typeof $.toast === 'function') {
            $.toast({
                heading:  type === 'success' ? 'Sucesso' : 'Erro!',
                text:     message,
                position: 'top-right',
                loaderBg: type === 'success' ? '#006A4E' : '#EF0107',
                icon:     type,
                hideAfter: type === 'success' ? 3500 : 5000,
                stack:    6,
            });
        } else {
            Swal.fire({ icon: type, text: message, timer: 3000, showConfirmButton: false });
        }
    },
});
