@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs', [
        'breadcrumbs' => [
            ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
            ['label' => 'Gateways de Pagamento', 'url' => route('panel.manager.gateways.index'), 'active' => true],
        ]
    ])
@endsection

@section('content')

    {{-- ══ Page Header ══════════════════════════════════════════════════════ --}}
    <div class="d-flex align-items-center gap-2 pb-3 mb-4 border-bottom">
        <div class="flex-grow-1">
            <h4 class="fw-bold mb-0">
                <i class="ti ti-credit-card me-2 text-primary"></i>Gateways de Pagamento
            </h4>
            <p class="text-muted small mb-0 mt-1">
                Gateways usados pelo <strong>EasyEye</strong> para cobrar as clínicas pelas assinaturas do sistema.
            </p>
        </div>
    </div>
    {{-- ══ /Page Header ═════════════════════════════════════════════════════ --}}

    {{-- ══ Explicação dos dois contextos ════════════════════════════════════ --}}
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="card border-primary h-100">
                <div class="card-body">
                    <div class="d-flex gap-2 align-items-start">
                        <i class="ti ti-building-store fs-22 text-primary mt-1 flex-shrink-0"></i>
                        <div>
                            <h6 class="fw-bold mb-1">Este painel — SaaS Billing</h6>
                            <p class="small text-muted mb-2">
                                Gerencia as credenciais usadas pelo <strong>EasyEye</strong> para cobrar as mensalidades das clínicas.
                            </p>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-soft-primary">Fonte primária: <code>.env</code></span>
                                <span class="badge badge-soft-secondary">Override: banco de dados</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-success h-100">
                <div class="card-body">
                    <div class="d-flex gap-2 align-items-start">
                        <i class="ti ti-users fs-22 text-success mt-1 flex-shrink-0"></i>
                        <div>
                            <h6 class="fw-bold mb-1">Painel das clínicas — Tenant Payment</h6>
                            <p class="small text-muted mb-2">
                                Cada clínica configura seu próprio gateway para receber pagamentos dos pacientes.
                                Gerenciado no painel de configurações de cada clínica.
                            </p>
                            <span class="badge badge-soft-success">Credenciais por clínica, isoladas</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {{-- ══ /Explicação ══════════════════════════════════════════════════════ --}}

    {{-- ══ Aviso .env ════════════════════════════════════════════════════════ --}}
    <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
        <i class="ti ti-info-circle fs-18 mt-1 flex-shrink-0"></i>
        <div>
            <strong>Fonte primária:</strong> as chaves de API dos gateways são lidas do <code>.env</code> do servidor
            (<code>ASAAS_SECRET</code>, <code>INFINITEPAY_SECRET</code>, etc.).
            O banco de dados serve como <strong>override operacional</strong> — útil para rotacionar credenciais sem redeploy.
            Se o <code>.env</code> estiver preenchido, ele tem prioridade sobre qualquer credencial salva aqui.
        </div>
    </div>
    {{-- ══ /Aviso .env ══════════════════════════════════════════════════════ --}}

    {{-- ══ Cards dos Gateways ═══════════════════════════════════════════════ --}}
    <div class="row g-3">
        @foreach($gateways as $gateway)
        @php $isEnvConfigured = $envConfigured[$gateway->code] ?? false; @endphp
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between py-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-bold">{{ $gateway->name }}</span>
                        <span class="badge badge-soft-secondary text-uppercase small">{{ $gateway->code }}</span>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <div class="form-check form-switch mb-0"
                             data-bs-toggle="tooltip"
                             title="{{ $gateway->active ? 'Desativar gateway' : 'Ativar gateway' }}">
                            <input class="form-check-input gateway-toggle"
                                   type="checkbox"
                                   role="switch"
                                   data-url="{{ route('panel.manager.gateways.toggle-active', $gateway) }}"
                                   {{ $gateway->active ? 'checked' : '' }}>
                        </div>
                        <span class="badge badge-soft-info"
                              data-bs-toggle="tooltip"
                              title="Prioridade (menor = usado primeiro)">
                            <i class="ti ti-sort-ascending me-1"></i>{{ $gateway->priority }}
                        </span>
                    </div>
                </div>

                <div class="card-body pb-2">
                    {{-- Fonte da credencial --}}
                    <div class="mb-3">
                        @if($isEnvConfigured)
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-soft-success">
                                    <i class="ti ti-check me-1"></i>.env configurado
                                </span>
                                <small class="text-muted">Chave ativa via variável de ambiente</small>
                            </div>
                        @else
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge badge-soft-warning">
                                    <i class="ti ti-alert-triangle me-1"></i>.env não configurado
                                </span>
                            </div>
                        @endif

                        @if($gateway->credentials_count > 0)
                            <div class="mt-1">
                                <span class="badge badge-soft-info">
                                    <i class="ti ti-database me-1"></i>{{ $gateway->credentials_count }} override(s) no banco
                                </span>
                            </div>
                        @endif
                    </div>

                    {{-- Capacidades --}}
                    <div class="d-flex flex-wrap gap-1">
                        @if($gateway->supports_subscriptions)
                            <span class="badge badge-soft-success"><i class="ti ti-refresh me-1"></i>Assinaturas</span>
                        @endif
                        @if($gateway->supports_one_time_charges)
                            <span class="badge badge-soft-success"><i class="ti ti-bolt me-1"></i>Cobranças avulsas</span>
                        @endif
                        @if($gateway->supports_refunds)
                            <span class="badge badge-soft-success"><i class="ti ti-arrow-back me-1"></i>Reembolsos</span>
                        @endif
                        @if($gateway->supports_webhooks)
                            <span class="badge badge-soft-success"><i class="ti ti-webhook me-1"></i>Webhooks</span>
                        @endif
                    </div>
                </div>

                <div class="card-footer bg-transparent py-2 d-flex gap-2">
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary flex-grow-1 btn-credentials"
                            data-gateway-name="{{ $gateway->name }}"
                            data-url="{{ route('panel.manager.gateways.credentials', $gateway) }}"
                            data-store-url="{{ route('panel.manager.gateways.credentials.store', $gateway) }}">
                        <i class="ti ti-database me-1"></i>Override no banco
                    </button>
                    <button type="button"
                            class="btn btn-sm btn-outline-secondary btn-priority"
                            data-gateway-name="{{ $gateway->name }}"
                            data-priority="{{ $gateway->priority }}"
                            data-url="{{ route('panel.manager.gateways.priority', $gateway) }}"
                            data-bs-toggle="tooltip" title="Alterar prioridade">
                        <i class="ti ti-sort-ascending"></i>
                    </button>
                </div>
            </div>
        </div>
        @endforeach
    </div>
    {{-- ══ /Cards ════════════════════════════════════════════════════════════ --}}

{{-- ══ Modal: Override de credencial no banco ══════════════════════════════ --}}
<div class="modal fade" id="credentialsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ti ti-database me-2"></i>Override de credencial: <span id="cred-gateway-name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning d-flex gap-2 align-items-start">
                    <i class="ti ti-alert-triangle flex-shrink-0 mt-1"></i>
                    <div>
                        Use este formulário <strong>apenas</strong> para sobrescrever temporariamente a credencial do <code>.env</code>
                        sem precisar de redeploy — por exemplo, durante uma rotação de chaves.
                        A chave <strong>nunca é exibida</strong> após salvar.
                        Ao salvar, a credencial anterior é desativada automaticamente.
                    </div>
                </div>

                <div id="cred-list-loading" class="text-center py-3 d-none">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                </div>
                <div id="cred-list-empty" class="text-center py-3 text-muted d-none">
                    Nenhum override cadastrado. A chave em uso vem do <code>.env</code>.
                </div>
                <div id="cred-list-container" class="mb-4"></div>

                <hr>
                <h6 class="fw-semibold mb-3"><i class="ti ti-plus me-1"></i>Novo override</h6>
                <form id="cred-store-form">
                    <input type="hidden" id="cred-store-url">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Rótulo</label>
                            <input type="text" class="form-control" name="label"
                                   placeholder="Ex: Chave nova após rotação 2025-06">
                        </div>
                        <div class="col-12">
                            <label class="form-label">API Secret <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control font-monospace" name="secret"
                                       id="cred-secret-input" autocomplete="new-password" required>
                                <button class="btn btn-outline-secondary" type="button" id="cred-toggle-secret">
                                    <i class="ti ti-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Webhook Secret <small class="text-muted">(opcional)</small></label>
                            <div class="input-group">
                                <input type="password" class="form-control font-monospace" name="webhook_secret"
                                       id="cred-webhook-input" autocomplete="new-password">
                                <button class="btn btn-outline-secondary" type="button" id="cred-toggle-webhook">
                                    <i class="ti ti-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Válida a partir de</label>
                            <input type="date" class="form-control" name="valid_from">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Válida até</label>
                            <input type="date" class="form-control" name="valid_to">
                        </div>
                    </div>
                    <div id="cred-store-error" class="alert alert-danger mt-3 d-none"></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fechar</button>
                <button type="button" class="btn btn-primary" id="cred-store-btn">
                    <span id="cred-store-spinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
                    Salvar override
                </button>
            </div>
        </div>
    </div>
</div>

{{-- ══ Modal: Prioridade ═══════════════════════════════════════════════════ --}}
<div class="modal fade" id="priorityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-sort-ascending me-2"></i>Prioridade</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="priority-form">
                <div class="modal-body">
                    <input type="hidden" id="priority-url">
                    <p class="fw-semibold mb-1" id="priority-gateway-name"></p>
                    <p class="small text-muted">Menor valor = maior prioridade. Usado pelo sistema de fallback de gateways.</p>
                    <input type="number" class="form-control" id="priority-input" min="1" max="999" required>
                    <div id="priority-error" class="alert alert-danger mt-2 d-none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary">Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@section('javascript')
<script>
$(function () {
    const csrf = $('meta[name="csrf-token"]').attr('content');

    $(document).on('change', '.gateway-toggle', function () {
        const url = $(this).data('url');
        const el  = this;
        $.ajax({
            method: 'PATCH', url, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: res => { if (window.showSuccessToast) showSuccessToast(res.message); },
            error: res => {
                el.checked = !el.checked;
                if (window.showErrorToast) showErrorToast(res.responseJSON?.message ?? 'Erro.');
            }
        });
    });

    $(document).on('click', '.btn-credentials', function () {
        $('#cred-gateway-name').text($(this).data('gateway-name'));
        $('#cred-store-url').val($(this).data('store-url'));
        $('#cred-store-form')[0].reset();
        $('#cred-store-error').addClass('d-none');
        $('#credentialsModal').modal('show');
        loadCredentials($(this).data('url'));
    });

    function loadCredentials(url) {
        $('#cred-list-loading').removeClass('d-none');
        $('#cred-list-empty, #cred-list-container').addClass('d-none');
        $('#cred-list-container').empty();

        $.ajax({
            url, headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                $('#cred-list-loading').addClass('d-none');
                const items = res.data ?? [];
                if (!items.length) { $('#cred-list-empty').removeClass('d-none'); return; }

                const html = items.map(c => `
                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom">
                        <div>
                            <div class="fw-semibold small">${c.label ?? 'Sem rótulo'}</div>
                            <div class="d-flex gap-2 mt-1">
                                ${c.active ? '<span class="badge badge-soft-success">Ativa</span>' : '<span class="badge badge-soft-secondary">Inativa</span>'}
                                ${c.valid_from ? `<small class="text-muted">${c.valid_from} → ${c.valid_to ?? '∞'}</small>` : ''}
                                <small class="text-muted">${c.created_at}</small>
                            </div>
                        </div>
                        <div class="text-muted small fst-italic">chave oculta</div>
                    </div>`).join('');

                $('#cred-list-container').removeClass('d-none').html(html);
            },
            error: () => {
                $('#cred-list-loading').addClass('d-none');
                $('#cred-list-container').removeClass('d-none').html('<div class="alert alert-danger">Erro ao carregar.</div>');
            }
        });
    }

    $('#cred-store-btn').on('click', function () {
        const url = $('#cred-store-url').val();
        const btn = $(this);
        btn.prop('disabled', true);
        $('#cred-store-spinner').removeClass('d-none');
        $('#cred-store-error').addClass('d-none');

        const data = {};
        $('#cred-store-form').serializeArray().forEach(f => { data[f.name] = f.value; });

        $.ajax({
            method: 'POST', url, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf }, data,
            success: res => {
                if (window.showSuccessToast) showSuccessToast(res.message);
                $('#credentialsModal').modal('hide');
                setTimeout(() => location.reload(), 800);
            },
            error: res => {
                $('#cred-store-error').removeClass('d-none').text(res.responseJSON?.message ?? 'Erro ao salvar.');
            },
            complete: () => { btn.prop('disabled', false); $('#cred-store-spinner').addClass('d-none'); }
        });
    });

    function togglePassword(inputId, btn) {
        const input = $(inputId);
        const isPass = input.attr('type') === 'password';
        input.attr('type', isPass ? 'text' : 'password');
        btn.find('i').toggleClass('ti-eye', !isPass).toggleClass('ti-eye-off', isPass);
    }
    $('#cred-toggle-secret').on('click', function () { togglePassword('#cred-secret-input', $(this)); });
    $('#cred-toggle-webhook').on('click', function () { togglePassword('#cred-webhook-input', $(this)); });

    $(document).on('click', '.btn-priority', function () {
        $('#priority-url').val($(this).data('url'));
        $('#priority-gateway-name').text($(this).data('gateway-name'));
        $('#priority-input').val($(this).data('priority'));
        $('#priority-error').addClass('d-none');
        $('#priorityModal').modal('show');
    });

    $('#priority-form').on('submit', function (e) {
        e.preventDefault();
        $.ajax({
            method: 'PATCH', url: $('#priority-url').val(), dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            data: { priority: $('#priority-input').val() },
            success: res => { if (window.showSuccessToast) showSuccessToast(res.message); $('#priorityModal').modal('hide'); setTimeout(() => location.reload(), 800); },
            error: res => { $('#priority-error').removeClass('d-none').text(res.responseJSON?.message ?? 'Erro.'); }
        });
    });
});
</script>
@endsection
