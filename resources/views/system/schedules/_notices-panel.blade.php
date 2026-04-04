{{--
    Painel Mural de Recados.
    Deve estar dentro do div scheduleView (acesso ao CSRF via fetch).
    Rotas necessárias:
      GET  panel.notices.index
      POST panel.notices.store
      POST panel.notices.read  (notices/{notice}/read)
      DELETE panel.notices.destroy
--}}
<div x-data="{
        noticesOpen:  false,
        notices:      [],
        unreadCount:  0,
        loading:      false,
        showForm:     false,
        newContent:   '',
        newUrgent:    false,
        newExpires:   '',
        saving:       false,

        noticesUrl:        @js(route('panel.notices.index')),
        noticesStoreUrl:   @js(route('panel.notices.store')),
        noticesReadUrl:    @js(url('panel/notices')),
        csrfToken:         @js(csrf_token()),

        async fetchNotices() {
            this.loading = true;
            try {
                const res  = await fetch(this.noticesUrl, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken }
                });
                const data = await res.json();
                this.notices     = data.data ?? [];
                this.unreadCount = data.unread_count ?? 0;
            } catch { }
            this.loading = false;
        },

        async markRead(notice) {
            if (notice.is_read) return;
            notice.is_read = true;
            this.unreadCount = Math.max(0, this.unreadCount - 1);
            await fetch(`${this.noticesReadUrl}/${notice.id}/read`, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken }
            });
        },

        async deleteNotice(notice) {
            this.notices = this.notices.filter(n => n.id !== notice.id);
            if (! notice.is_read) this.unreadCount = Math.max(0, this.unreadCount - 1);
            await fetch(`${this.noticesReadUrl}/${notice.id}`, {
                method:  'DELETE',
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': this.csrfToken }
            });
        },

        async saveNotice() {
            if (! this.newContent.trim()) return;
            this.saving = true;
            try {
                await fetch(this.noticesStoreUrl, {
                    method:  'POST',
                    headers: {
                        'Content-Type':  'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-CSRF-TOKEN':  this.csrfToken
                    },
                    body: JSON.stringify({
                        content:    this.newContent,
                        priority:   this.newUrgent ? 1 : 0,
                        expires_at: this.newExpires || null
                    })
                });
                this.newContent = '';
                this.newUrgent  = false;
                this.newExpires = '';
                this.showForm   = false;
                await this.fetchNotices();
            } catch { }
            this.saving = false;
        },

        toggle() {
            this.noticesOpen = ! this.noticesOpen;
            if (this.noticesOpen && this.notices.length === 0) this.fetchNotices();
        }
    }"
     x-init="fetchNotices()"
     class="mb-3">

    {{-- Botão de toggle --}}
    <button type="button"
            class="btn btn-sm btn-outline-primary position-relative mb-2"
            @click="toggle()">
        <i class="fas fa-bullhorn me-1"></i> Mural de Recados
        <span x-show="unreadCount > 0" x-cloak
              class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
              x-text="unreadCount"
              style="font-size:.65rem;"></span>
    </button>

    {{-- Painel --}}
    <div x-show="noticesOpen" x-cloak class="border border-primary rounded">

        {{-- Cabeçalho --}}
        <div class="d-flex align-items-center justify-content-between px-3 py-2 bg-primary text-white rounded-top">
            <span class="fw-semibold small">
                <i class="fas fa-bullhorn me-1"></i> Mural de Recados
                <span x-show="unreadCount > 0" x-cloak
                      class="badge bg-warning text-dark ms-1"
                      x-text="unreadCount + ' não lido(s)'"
                      style="font-size:.65rem;"></span>
            </span>
            <div class="d-flex gap-2">
                <button type="button"
                        class="btn btn-sm btn-light py-0"
                        @click="showForm = ! showForm">
                    <i class="fas fa-plus me-1"></i><span style="font-size:.8rem;">Novo</span>
                </button>
                <button type="button" class="btn-close btn-close-white" @click="noticesOpen = false"></button>
            </div>
        </div>

        {{-- Formulário de novo recado --}}
        <div x-show="showForm" x-cloak class="p-3 border-bottom bg-light">
            <textarea class="form-control form-control-sm mb-2"
                      rows="2"
                      x-model="newContent"
                      placeholder="Digite o recado…"
                      maxlength="1000"></textarea>
            <div class="d-flex align-items-center gap-3 flex-wrap">
                <div class="form-check form-check-inline mb-0">
                    <input class="form-check-input" type="checkbox" id="notice-urgent" x-model="newUrgent">
                    <label class="form-check-label small text-danger fw-semibold" for="notice-urgent">
                        <i class="fas fa-exclamation-triangle me-1"></i> Urgente
                    </label>
                </div>
                <div class="d-flex align-items-center float-end gap-1">
                    <label class="small text-muted mb-0">Expira em:</label>
                    <input type="date" class="form-control form-control-sm" x-model="newExpires" style="max-width:150px;">
                </div>
                <div class="ms-auto d-flex gap-2">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary"
                            @click="showForm = false">
                        Cancelar
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-primary"
                            :disabled="saving || ! newContent.trim()"
                            @click="saveNotice()">
                        <span x-show="saving" class="spinner-border spinner-border-sm me-1"></span>
                        Publicar
                    </button>
                </div>
            </div>
        </div>

        {{-- Lista de recados --}}
        <div x-show="loading" x-cloak class="text-center py-3 text-muted small">
            <span class="spinner-border spinner-border-sm me-1"></span> Carregando…
        </div>

        <template x-if="! loading && notices.length === 0">
            <p class="text-muted text-center py-3 mb-0 small">
                <i class="fas fa-check-circle me-1"></i> Nenhum recado no momento.
            </p>
        </template>

        <div class="list-group list-group-flush rounded-bottom" style="max-height:300px; overflow-y:auto;">
            <template x-for="notice in notices" :key="notice.id">
                <div class="list-group-item px-3 py-2"
                     :class="{
                         'border-start border-danger border-3': notice.is_urgent,
                         'bg-light': ! notice.is_read
                     }">
                    <div class="d-flex gap-2 align-items-start">

                        {{-- Ícones de alerta e pin --}}
                        <div class="flex-shrink-0 pt-1" style="width:20px;">
                            <template x-if="notice.is_urgent">
                                <i class="fas fa-exclamation-triangle text-danger" title="Urgente"></i>
                            </template>
                            <template x-if="notice.pinned && ! notice.is_urgent">
                                <i class="fas fa-thumbtack text-primary" title="Fixado"></i>
                            </template>
                        </div>

                        {{-- Conteúdo --}}
                        <div class="flex-grow-1 min-w-0">
                            <p class="mb-1 small" style="white-space:pre-wrap;" x-text="notice.content"></p>
                            <div class="text-muted" style="font-size:.72rem;">
                                <span x-text="notice.author"></span>
                                &middot;
                                <span x-text="notice.created_at"></span>
                                <template x-if="notice.expires_at">
                                    <span class="ms-1">
                                        &middot; expira <span x-text="notice.expires_at"></span>
                                    </span>
                                </template>
                            </div>
                        </div>

                        {{-- Ações --}}
                        <div class="flex-shrink-0 d-flex gap-1">
                            <button type="button"
                                    class="btn btn-xs btn-outline-success py-0 px-1"
                                    style="font-size:.72rem;"
                                    x-show="! notice.is_read"
                                    @click="markRead(notice)"
                                    title="Marcar como lido">
                                <i class="fas fa-check"></i> Li
                            </button>
                            <span x-show="notice.is_read"
                                  class="text-success"
                                  style="font-size:.72rem; padding:.1rem .25rem;"
                                  title="Lido">
                                <i class="fas fa-check-double"></i>
                            </span>
                            <button type="button"
                                    class="btn btn-xs btn-outline-danger py-0 px-1"
                                    style="font-size:.72rem;"
                                    @click="deleteNotice(notice)"
                                    title="Remover recado">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>

                    </div>
                </div>
            </template>
        </div>

    </div>
</div>
