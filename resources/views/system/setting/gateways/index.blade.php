@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs', [
        'breadcrumbs' => [
            ['label' => __('actions.sidemenu.dashboard'), 'url' => route('panel.dashboard'), 'active' => false],
            ['label' => 'Gateways de Pagamento', 'url' => route('panel.setting.gateways.index'), 'active' => true],
        ]
    ])
@endsection

@section('content')

    {{-- ══ Page Header ══════════════════════════════════════════════════════ --}}
    <div class="d-flex align-items-center gap-2 pb-3 mb-4 border-bottom">
        <div class="flex-grow-1">
            <h4 class="fw-bold mb-0">
                <i class="ti ti-credit-card me-2 text-success"></i>Gateways de Pagamento
            </h4>
            <p class="text-muted small mb-0 mt-1">
                Configure os gateways de pagamento da <strong>sua clínica</strong> para receber pagamentos dos pacientes.
            </p>
        </div>
    </div>
    {{-- ══ /Page Header ═════════════════════════════════════════════════════ --}}

    {{-- ══ Aviso informativo ═════════════════════════════════════════════════ --}}
    <div class="alert alert-info d-flex align-items-start gap-2 mb-4">
        <i class="ti ti-info-circle fs-18 mt-1 flex-shrink-0"></i>
        <div>
            <strong>Contexto: recebimento de pacientes.</strong>
            As credenciais cadastradas aqui são usadas exclusivamente pela <strong>sua clínica</strong> para processar pagamentos dos pacientes.
            Elas são isoladas e nunca compartilhadas com outras clínicas ou com o sistema EasyEye.
            A chave nunca é exibida após salvar.
        </div>
    </div>
    {{-- ══ /Aviso ════════════════════════════════════════════════════════════ --}}

    {{-- ══ Cards dos Gateways ═══════════════════════════════════════════════ --}}
    <div class="row g-3">
        @forelse($gateways as $gateway)
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between py-2">
                    <div class="d-flex align-items-center gap-2">
                        <span class="fw-bold">{{ $gateway->name }}</span>
                        <span class="badge badge-soft-secondary text-uppercase small">{{ $gateway->code }}</span>
                    </div>
                    @if($gateway->credentials_count > 0)
                        <span class="badge badge-soft-success">
                            <i class="ti ti-check me-1"></i>Configurado
                        </span>
                    @else
                        <span class="badge badge-soft-warning">
                            <i class="ti ti-alert-triangle me-1"></i>Não configurado
                        </span>
                    @endif
                </div>

                <div class="card-body pb-2">
                    @if($gateway->credentials_count > 0)
                        <div class="mb-3">
                            <span class="badge badge-soft-success">
                                <i class="ti ti-database me-1"></i>{{ $gateway->credentials_count }} credencial(is) cadastrada(s)
                            </span>
                        </div>
                    @else
                        <p class="small text-muted mb-3">
                            Nenhuma credencial cadastrada. Clique em "Gerenciar" para adicionar.
                        </p>
                    @endif

                    {{-- Capacidades --}}
                    <div class="d-flex flex-wrap gap-1">
                        @if($gateway->supports_subscriptions)
                            <span class="badge badge-soft-info"><i class="ti ti-refresh me-1"></i>Assinaturas</span>
                        @endif
                        @if($gateway->supports_one_time_charges)
                            <span class="badge badge-soft-info"><i class="ti ti-bolt me-1"></i>Cobranças avulsas</span>
                        @endif
                        @if($gateway->supports_refunds)
                            <span class="badge badge-soft-info"><i class="ti ti-arrow-back me-1"></i>Reembolsos</span>
                        @endif
                        @if($gateway->supports_webhooks)
                            <span class="badge badge-soft-info"><i class="ti ti-webhook me-1"></i>Webhooks</span>
                        @endif
                    </div>
                </div>

                <div class="card-footer bg-transparent py-2">
                    <button type="button"
                            class="btn btn-sm btn-outline-success w-100 btn-credentials"
                            data-gateway-name="{{ $gateway->name }}"
                            data-url="{{ route('panel.setting.gateways.credentials', $gateway) }}"
                            data-store-url="{{ route('panel.setting.gateways.credentials.store', $gateway) }}">
                        <i class="ti ti-settings me-1"></i>Gerenciar credenciais
                    </button>
                </div>
            </div>
        </div>
        @empty
            <div class="col-12">
                <div class="card">
                    <div class="card-body text-center text-muted py-5">
                        <i class="ti ti-credit-card fs-36 mb-3 d-block"></i>
                        Nenhum gateway de pagamento disponível no momento.
                    </div>
                </div>
            </div>
        @endforelse
    </div>
    {{-- ══ /Cards ════════════════════════════════════════════════════════════ --}}

{{-- ══ Modal: Gerenciar credenciais do tenant ══════════════════════════════ --}}
<div class="modal fade" id="credentialsModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="ti ti-settings me-2"></i>Credenciais: <span id="cred-gateway-name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info d-flex gap-2 align-items-start">
                    <i class="ti ti-info-circle flex-shrink-0 mt-1"></i>
                    <div>
                        Cadastre a chave de API fornecida pelo gateway para que sua clínica possa receber pagamentos.
                        A chave <strong>nunca é exibida</strong> após salvar.
                        Ao cadastrar uma nova credencial, a anterior é desativada automaticamente.
                    </div>
                </div>

                {{-- Lista de credenciais existentes --}}
                <h6 class="fw-semibold mb-2">Credenciais cadastradas</h6>
                <div id="cred-list-loading" class="text-center py-3 d-none">
                    <div class="spinner-border spinner-border-sm text-primary"></div>
                </div>
                <div id="cred-list-empty" class="text-center py-3 text-muted d-none">
                    <i class="ti ti-database-off d-block fs-24 mb-1"></i>
                    Nenhuma credencial cadastrada ainda.
                </div>
                <div id="cred-list-container" class="mb-4"></div>

                <hr>
                {{-- Formulário: nova credencial --}}
                <h6 class="fw-semibold mb-3"><i class="ti ti-plus me-1"></i>Nova credencial</h6>
                <form id="cred-store-form">
                    <input type="hidden" id="cred-store-url">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Rótulo <small class="text-muted">(opcional)</small></label>
                            <input type="text" class="form-control" name="label"
                                   placeholder="Ex: Produção — chave rotacionada em jun/2025">
                        </div>
                        <div class="col-12">
                            <label class="form-label">API Secret <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="password" class="form-control font-monospace" name="secret"
                                       id="cred-secret-input" autocomplete="new-password" required
                                       placeholder="Cole aqui a chave de API fornecida pelo gateway">
                                <button class="btn btn-outline-secondary" type="button" id="cred-toggle-secret">
                                    <i class="ti ti-eye"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Webhook Secret <small class="text-muted">(opcional)</small></label>
                            <div class="input-group">
                                <input type="password" class="form-control font-monospace" name="webhook_secret"
                                       id="cred-webhook-input" autocomplete="new-password"
                                       placeholder="Chave para validar assinatura dos webhooks">
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
                <button type="button" class="btn btn-success" id="cred-store-btn">
                    <span id="cred-store-spinner" class="spinner-border spinner-border-sm me-1 d-none"></span>
                    Salvar credencial
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@section('javascript')
<script>
$(function () {
    const csrf = $('meta[name="csrf-token"]').attr('content');
    let currentCredentialsUrl = null;

    $(document).on('click', '.btn-credentials', function () {
        currentCredentialsUrl = $(this).data('url');
        $('#cred-gateway-name').text($(this).data('gateway-name'));
        $('#cred-store-url').val($(this).data('store-url'));
        $('#cred-store-form')[0].reset();
        $('#cred-store-error').addClass('d-none');
        $('#credentialsModal').modal('show');
        loadCredentials(currentCredentialsUrl);
    });

    function loadCredentials(url) {
        $('#cred-list-loading').removeClass('d-none');
        $('#cred-list-empty, #cred-list-container').addClass('d-none');
        $('#cred-list-container').empty();

        $.ajax({
            url,
            headers: { 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
            success: function (res) {
                $('#cred-list-loading').addClass('d-none');
                const items = res.data ?? [];

                if (!items.length) {
                    $('#cred-list-empty').removeClass('d-none');
                    return;
                }

                const html = items.map(c => `
                    <div class="d-flex align-items-center justify-content-between py-2 border-bottom" id="cred-row-${c.id}">
                        <div>
                            <div class="fw-semibold small">${c.label ?? 'Sem rótulo'}</div>
                            <div class="d-flex flex-wrap gap-2 mt-1">
                                ${c.active
                                    ? '<span class="badge badge-soft-success">Ativa</span>'
                                    : '<span class="badge badge-soft-secondary">Inativa</span>'}
                                ${c.valid_from ? `<small class="text-muted">${c.valid_from} → ${c.valid_to ?? '∞'}</small>` : ''}
                                <small class="text-muted">${c.created_at}</small>
                            </div>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="text-muted small fst-italic me-2">chave oculta</span>
                            ${c.active ? `
                                <button class="btn btn-sm btn-outline-danger btn-revoke"
                                        data-cred-id="${c.id}"
                                        data-bs-toggle="tooltip" title="Revogar credencial">
                                    <i class="ti ti-x"></i>
                                </button>` : ''}
                        </div>
                    </div>`).join('');

                $('#cred-list-container').removeClass('d-none').html(html);
                $('[data-bs-toggle="tooltip"]').tooltip();
            },
            error: () => {
                $('#cred-list-loading').addClass('d-none');
                $('#cred-list-container').removeClass('d-none')
                    .html('<div class="alert alert-danger">Erro ao carregar credenciais.</div>');
            }
        });
    }

    $(document).on('click', '.btn-revoke', function () {
        const credId = $(this).data('cred-id');
        if (!confirm('Revogar esta credencial? A ação não pode ser desfeita.')) return;

        const revokeUrl = currentCredentialsUrl + '/' + credId + '/revoke';

        $.ajax({
            method: 'PATCH', url: revokeUrl, dataType: 'json',
            headers: { 'X-CSRF-TOKEN': csrf },
            success: res => {
                if (window.showSuccessToast) showSuccessToast(res.message);
                loadCredentials(currentCredentialsUrl);
            },
            error: res => {
                if (window.showErrorToast) showErrorToast(res.responseJSON?.message ?? 'Erro ao revogar.');
            }
        });
    });

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
                $('#cred-store-form')[0].reset();
                loadCredentials(currentCredentialsUrl);
                setTimeout(() => location.reload(), 1200);
            },
            error: res => {
                const errors = res.responseJSON?.errors;
                let msg = res.responseJSON?.message ?? 'Erro ao salvar.';
                if (errors) {
                    msg = Object.values(errors).flat().join('<br>');
                }
                $('#cred-store-error').removeClass('d-none').html(msg);
            },
            complete: () => {
                btn.prop('disabled', false);
                $('#cred-store-spinner').addClass('d-none');
            }
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
});
</script>
@endsection
