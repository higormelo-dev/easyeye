/**
 * crudForm — Alpine.js component genérico para formulários CRUD.
 *
 * Uso na Blade:
 *   x-data="crudForm({ storeUrl: '...', updateUrl: '...', deleteUrl: '...' })"
 *
 * @param {string}        storeUrl    — POST para criação
 * @param {string|null}   updateUrl   — PATCH para edição (null se não aplicável)
 * @param {string|null}   deleteUrl   — DELETE para remoção (null se não aplicável)
 * @param {object}        fields      — Valores iniciais do form
 * @param {Function|null} onSuccess   — Callback após salvar com sucesso
 */
export default ({ storeUrl, updateUrl = null, deleteUrl = null, fields = {}, onSuccess = null }) => ({
    form:    { ...fields },
    errors:  {},
    loading: false,
    editing: false,   // true quando é edição
    _updateUrl: updateUrl,
    _deleteUrl: deleteUrl,
    _onSuccess: onSuccess,

    // ── Preenche o form para edição ──────────────────────────────────────────
    fill(data, editUrl) {
        this.form       = { ...this.form, ...data };
        this._updateUrl = editUrl;
        this.editing    = true;
        this.errors     = {};
    },

    // ── Carrega dados para edição via AJAX e abre o modal ────────────────────
    async loadAndEdit(showUrl, editUrl, modalId = null) {
        this.loading = true;

        try {
            const res  = await this._request(showUrl, 'GET');
            const data = await res.json();

            if (!res.ok) throw new Error(data.message ?? 'Erro ao carregar.');

            this.fill(data.data ?? data, editUrl);

            if (modalId) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById(modalId)).show();
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Erro', text: e.message });
        } finally {
            this.loading = false;
        }
    },

    // ── Limpa o form (novo registro) ─────────────────────────────────────────
    reset() {
        this.form    = { ...fields };
        this.errors  = {};
        this.editing = false;
    },

    // ── Salva (cria ou atualiza) ─────────────────────────────────────────────
    async save() {
        if (this.loading) return;
        this.loading = true;
        this.errors  = {};

        const url    = this.editing ? this._updateUrl : storeUrl;
        const method = this.editing ? 'PATCH' : 'POST';

        try {
            const res  = await this._request(url, method, this.form);
            const data = await res.json();

            if (!res.ok) {
                if (res.status === 422) {
                    this.errors = data.errors ?? {};
                    return;
                }
                throw new Error(data.message ?? 'Erro inesperado.');
            }

            await Swal.fire({ icon: 'success', title: 'Sucesso!', text: data.message, timer: 2000, showConfirmButton: false });

            this.reset();

            if (typeof this._onSuccess === 'function') {
                this._onSuccess(data);
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Erro!', text: e.message });
        } finally {
            this.loading = false;
        }
    },

    // ── Remove ───────────────────────────────────────────────────────────────
    async destroy(url = null) {
        const deleteUrl = url ?? this._deleteUrl;
        if (!deleteUrl) return;

        const { isConfirmed } = await Swal.fire({
            title: 'Confirmar exclusão',
            text: 'Esta ação não pode ser desfeita.',
            icon: 'warning',
            showCancelButton:  true,
            confirmButtonText: 'Excluir',
            cancelButtonText:  'Cancelar',
            confirmButtonColor: '#dc3545',
        });

        if (!isConfirmed) return;

        this.loading = true;

        try {
            const res  = await this._request(deleteUrl, 'DELETE');
            const data = await res.json();

            if (!res.ok) throw new Error(data.message ?? 'Erro ao excluir.');

            await Swal.fire({ icon: 'success', title: 'Excluído!', text: data.message, timer: 2000, showConfirmButton: false });

            if (typeof this._onSuccess === 'function') {
                this._onSuccess(data);
            }
        } catch (e) {
            Swal.fire({ icon: 'error', title: 'Erro!', text: e.message });
        } finally {
            this.loading = false;
        }
    },

    // ── Helpers ──────────────────────────────────────────────────────────────
    hasError(field) {
        return !!this.errors[field];
    },

    firstError(field) {
        return this.errors[field]?.[0] ?? '';
    },

    _request(url, method, body = null) {
        const opts = {
            method,
            headers: {
                'X-CSRF-TOKEN':     document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Content-Type':     'application/json',
                'Accept':           'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        };

        if (body && method !== 'GET' && method !== 'DELETE') {
            opts.body = JSON.stringify(body);
        }

        return fetch(url, opts);
    },
});
