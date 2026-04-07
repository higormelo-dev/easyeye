<div x-data="subscriptionShow('{{ url('panel/manager/subscriptions/' . $subscription->id) }}')" x-init="init()">

    {{-- ══ Tabs ════════════════════════════════════════════════════════════ --}}
    <ul class="nav nav-tabs nav-tabs-custom mb-3" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" href="#tab-overview" data-bs-toggle="tab" role="tab">
                <i class="ti ti-info-circle me-1"></i>Visão Geral
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#tab-invoices" data-bs-toggle="tab" role="tab"
               x-on:click="loadInvoices()">
                <i class="ti ti-receipt me-1"></i>Faturas & Pagamentos
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" href="#tab-retries" data-bs-toggle="tab" role="tab"
               x-on:click="loadRetries()">
                <i class="ti ti-refresh-alert me-1"></i>Retentativas
            </a>
        </li>
    </ul>

    <div class="tab-content">

        {{-- ══ Tab: Visão Geral ════════════════════════════════════════════ --}}
        <div class="tab-pane fade show active" id="tab-overview" role="tabpanel">
            <table class="table table-sm">
                <tbody>
                    <tr>
                        <th width="35%">Empresa</th>
                        <td>{{ $subscription->entity?->name ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Plano</th>
                        <td>{{ $subscription->plan?->name ?? '-' }}</td>
                    </tr>

                    <tr><th class="bg-light" colspan="2"><small class="text-muted">Status da Assinatura</small></th></tr>
                    <tr>
                        <th>Status</th>
                        <td>
                            <span class="badge {{ $subscription->status->badgeClass() }}">
                                {{ $subscription->status->label() }}
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th>Estado de Cobrança</th>
                        <td>
                            @php
                                $billingStateBadge = match($subscription->billing_state) {
                                    'paid'               => 'badge-soft-success',
                                    'pending'            => 'badge-soft-warning',
                                    'past_due'           => 'badge-soft-orange',
                                    'chargeback'         => 'badge-soft-danger',
                                    'error'              => 'badge-soft-danger',
                                    'cancelled'          => 'badge-soft-secondary',
                                    'pending_activation' => 'badge-soft-info',
                                    default              => 'badge-soft-secondary',
                                };
                                $billingStateLabel = match($subscription->billing_state) {
                                    'paid'               => 'Pago',
                                    'pending'            => 'Pendente',
                                    'past_due'           => 'Em atraso',
                                    'chargeback'         => 'Chargeback',
                                    'error'              => 'Erro',
                                    'cancelled'          => 'Cancelado',
                                    'pending_activation' => 'Ativando...',
                                    default              => $subscription->billing_state ?? '-',
                                };
                            @endphp
                            <span class="badge {{ $billingStateBadge }}">{{ $billingStateLabel }}</span>
                        </td>
                    </tr>

                    @if($subscription->last_billing_error)
                        <tr>
                            <th>Erro de Cobrança</th>
                            <td>
                                <div class="alert alert-danger py-1 px-2 small mb-0">
                                    <i class="ti ti-alert-circle me-1"></i>
                                    {{ $subscription->last_billing_error }}
                                </div>
                            </td>
                        </tr>
                    @endif

                    <tr><th class="bg-light" colspan="2"><small class="text-muted">Gateway de Pagamento</small></th></tr>
                    <tr>
                        <th>Gateway</th>
                        <td>
                            @if($subscription->gateway)
                                <span class="badge badge-soft-primary text-uppercase">{{ $subscription->gateway }}</span>
                            @else
                                <span class="text-muted">Não configurado</span>
                            @endif
                        </td>
                    </tr>
                    @if($subscription->gateway_customer_id)
                        <tr>
                            <th>ID do Cliente no Gateway</th>
                            <td><code>{{ $subscription->gateway_customer_id }}</code></td>
                        </tr>
                    @endif
                    @if($subscription->gateway_subscription_id)
                        <tr>
                            <th>ID da Assinatura no Gateway</th>
                            <td><code>{{ $subscription->gateway_subscription_id }}</code></td>
                        </tr>
                    @endif

                    <tr><th class="bg-light" colspan="2"><small class="text-muted">Datas</small></th></tr>
                    <tr>
                        <th>Início</th>
                        <td>{{ $subscription->starts_at?->format('d/m/Y') ?? '-' }}</td>
                    </tr>
                    <tr>
                        <th>Vencimento</th>
                        <td>
                            @if($subscription->ends_at)
                                {{ $subscription->ends_at->format('d/m/Y') }}
                            @else
                                <span class="text-muted">Vitalício</span>
                            @endif
                        </td>
                    </tr>
                    @if($subscription->next_billing_at)
                        <tr>
                            <th>Próxima Cobrança</th>
                            <td>
                                <span class="fw-semibold">{{ $subscription->next_billing_at->format('d/m/Y') }}</span>
                                @if($subscription->next_billing_at->isPast())
                                    <span class="badge badge-soft-danger ms-1">Vencida</span>
                                @endif
                            </td>
                        </tr>
                    @endif
                    @if($subscription->last_payment_at)
                        <tr>
                            <th>Último Pagamento</th>
                            <td>{{ $subscription->last_payment_at->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endif
                    @if($subscription->trial_ends_at)
                        <tr>
                            <th>Trial até</th>
                            <td>{{ $subscription->trial_ends_at->format('d/m/Y') }}</td>
                        </tr>
                    @endif
                    @if($subscription->grace_period_ends_at)
                        <tr>
                            <th>Período de Graça até</th>
                            <td>{{ $subscription->grace_period_ends_at->format('d/m/Y') }}</td>
                        </tr>
                    @endif
                    @if($subscription->cancelled_at)
                        <tr>
                            <th>Cancelado em</th>
                            <td>{{ $subscription->cancelled_at->format('d/m/Y H:i') }}</td>
                        </tr>
                    @endif
                    <tr>
                        <th>Criado em</th>
                        <td>{{ $subscription->created_at->format('d/m/Y H:i') }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        {{-- ══ /Tab: Visão Geral ════════════════════════════════════════════ --}}

        {{-- ══ Tab: Faturas & Pagamentos ═══════════════════════════════════ --}}
        <div class="tab-pane fade" id="tab-invoices" role="tabpanel">
            <div x-show="invoicesLoading" class="text-center py-4">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <span class="ms-2 text-muted small">Carregando faturas...</span>
            </div>

            <div x-show="!invoicesLoading">
                <template x-if="invoices.length === 0">
                    <div class="text-center py-4 text-muted">
                        <i class="ti ti-receipt fs-2 mb-2 d-block"></i>
                        Nenhuma fatura encontrada.
                    </div>
                </template>

                <template x-for="inv in invoices" :key="inv.id">
                    <div class="card mb-2">
                        <div class="card-header py-2 d-flex align-items-center justify-content-between">
                            <div class="d-flex align-items-center gap-2">
                                <span class="fw-semibold small" x-text="inv.reference"></span>
                                <span class="badge" :class="inv.status_badge" x-text="inv.status_label"></span>
                                <template x-if="inv.billing_reason">
                                    <span class="badge badge-soft-secondary" x-text="inv.billing_reason"></span>
                                </template>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold text-success">R$ <span x-text="inv.amount"></span></span>
                                <template x-if="inv.gateway_code">
                                    <span class="badge badge-soft-primary ms-1 text-uppercase" x-text="inv.gateway_code"></span>
                                </template>
                            </div>
                        </div>
                        <div class="card-body py-2 small text-muted">
                            <div class="row g-2">
                                <div class="col-auto" x-show="inv.period_start">
                                    <i class="ti ti-calendar me-1"></i>
                                    Período: <span x-text="inv.period_start"></span> – <span x-text="inv.period_end"></span>
                                </div>
                                <div class="col-auto" x-show="inv.due_at">
                                    <i class="ti ti-clock me-1"></i>
                                    Vencimento: <span x-text="inv.due_at"></span>
                                </div>
                                <div class="col-auto" x-show="inv.paid_at">
                                    <i class="ti ti-check text-success me-1"></i>
                                    Pago em: <span x-text="inv.paid_at"></span>
                                </div>
                            </div>

                            {{-- Pagamentos da fatura --}}
                            <template x-if="inv.payments && inv.payments.length > 0">
                                <div class="mt-2 border-top pt-2">
                                    <div class="fw-semibold text-body mb-1">Pagamentos</div>
                                    <template x-for="pay in inv.payments" :key="pay.id">
                                        <div class="d-flex align-items-center justify-content-between py-1 border-bottom">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge" :class="pay.status_badge" x-text="pay.status"></span>
                                                <span class="text-uppercase" x-text="pay.gateway_code"></span>
                                                <template x-if="pay.external_payment_id">
                                                    <code class="small" x-text="pay.external_payment_id"></code>
                                                </template>
                                            </div>
                                            <div class="text-end">
                                                <span class="fw-semibold">R$ <span x-text="pay.amount"></span></span>
                                                <div>
                                                    <template x-if="pay.paid_at">
                                                        <span class="text-success"><i class="ti ti-check me-1"></i><span x-text="pay.paid_at"></span></span>
                                                    </template>
                                                    <template x-if="pay.failed_at">
                                                        <span class="text-danger"><i class="ti ti-x me-1"></i><span x-text="pay.failed_at"></span></span>
                                                    </template>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </div>
        {{-- ══ /Tab: Faturas & Pagamentos ═══════════════════════════════════ --}}

        {{-- ══ Tab: Retentativas ═══════════════════════════════════════════ --}}
        <div class="tab-pane fade" id="tab-retries" role="tabpanel">
            <div x-show="retriesLoading" class="text-center py-4">
                <div class="spinner-border spinner-border-sm text-primary"></div>
                <span class="ms-2 text-muted small">Carregando retentativas...</span>
            </div>

            <div x-show="!retriesLoading">
                <template x-if="retries.length === 0">
                    <div class="text-center py-4 text-muted">
                        <i class="ti ti-refresh-alert fs-2 mb-2 d-block"></i>
                        Nenhuma retentativa registrada.
                    </div>
                </template>

                <div class="table-responsive" x-show="retries.length > 0">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Tentativa</th>
                                <th>Status</th>
                                <th>Gateway</th>
                                <th>Agendada para</th>
                                <th>Executada em</th>
                                <th>Resultado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="retry in retries" :key="retry.id">
                                <tr>
                                    <td class="text-center fw-bold" x-text="'#' + retry.attempt_number"></td>
                                    <td><span class="badge" :class="retry.status_badge" x-text="retry.status"></span></td>
                                    <td class="text-uppercase small" x-text="retry.gateway_code || '-'"></td>
                                    <td x-text="retry.scheduled_for"></td>
                                    <td x-text="retry.executed_at || '-'"></td>
                                    <td class="small text-muted" x-text="retry.result_message || '-'"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        {{-- ══ /Tab: Retentativas ═══════════════════════════════════════════ --}}

    </div>
</div>

<script>
function subscriptionShow(baseUrl) {
    return {
        invoices: [],
        retries: [],
        invoicesLoading: false,
        retriesLoading: false,
        invoicesLoaded: false,
        retriesLoaded: false,

        init() {},

        loadInvoices() {
            if (this.invoicesLoaded) return;
            this.invoicesLoading = true;
            fetch(baseUrl + '/invoices', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                this.invoices = data.data ?? [];
                this.invoicesLoaded = true;
            })
            .finally(() => { this.invoicesLoading = false; });
        },

        loadRetries() {
            if (this.retriesLoaded) return;
            this.retriesLoading = true;
            fetch(baseUrl + '/retries', {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(r => r.json())
            .then(data => {
                this.retries = data.data ?? [];
                this.retriesLoaded = true;
            })
            .finally(() => { this.retriesLoading = false; });
        }
    };
}
</script>
