@extends('layouts.app')

@section('breadcrumb')
    @include('components.breadcrumbs')
@endsection

@section('content')
    <div x-data="crudForm({
            storeUrl: @js(route('panel.financial.cash-flow.store')),
            updateUrl: null,
            fields: {
                entry_date: @js(now()->toDateString()),
                description: '',
                type: 'income',
                status: 'paid',
                category_id: '',
                amount: '',
                payment_method: '',
                notes: ''
            },
            onSuccess: () => {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('cashEntryModal')).hide();
                window.location.reload();
            }
        })"
         x-init="$nextTick(() => document.getElementById('cashEntryModal').addEventListener('hidden.bs.modal', () => reset()))"
         @open-create-cash-entry.window="reset(); bootstrap.Modal.getOrCreateInstance(document.getElementById('cashEntryModal')).show()"
         @edit-cash-entry.window="fill($event.detail.entry, $event.detail.updateUrl); bootstrap.Modal.getOrCreateInstance(document.getElementById('cashEntryModal')).show()">

        @include('system.financial.cashflow._form-modal')

        <div class="card mb-4 border-0 shadow-sm">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Período inicial</label>
                        <input type="date" name="from" value="{{ $from }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small fw-semibold">Período final</label>
                        <input type="date" name="to" value="{{ $to }}" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Tipo</label>
                        <select name="type" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <option value="income" @selected(request('type') === 'income')>Receita</option>
                            <option value="expense" @selected(request('type') === 'expense')>Despesa</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="">Todos</option>
                            <option value="pending" @selected(request('status') === 'pending')>Pendente</option>
                            <option value="paid" @selected(request('status') === 'paid')>Pago</option>
                            <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelado</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small fw-semibold">Categoria</label>
                        <select name="category_id" class="form-select form-select-sm">
                            <option value="">Todas</option>
                            @foreach($categories as $category)
                                <option value="{{ $category->id }}" @selected(request('category_id') === $category->id)>
                                    {{ $category->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12 d-flex justify-content-end gap-1">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="ti ti-filter me-1"></i> Filtrar
                        </button>
                        <a href="{{ route('panel.financial.cash-flow.index') }}" class="btn btn-outline-secondary btn-sm">
                            <i class="ti ti-x me-1"></i> Limpar
                        </a>
                        <button type="button" class="btn btn-info btn-sm" @click="$dispatch('open-create-cash-entry')">
                            <i class="ti ti-plus me-1"></i> Novo lançamento
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Receitas no período</div>
                        <div class="fs-3 fw-bold text-success">R$ {{ number_format($summary['income'], 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Despesas no período</div>
                        <div class="fs-3 fw-bold text-danger">R$ {{ number_format($summary['expense'], 2, ',', '.') }}</div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Saldo no período</div>
                        <div class="fs-3 fw-bold {{ $summary['balance'] >= 0 ? 'text-primary' : 'text-danger' }}">
                            R$ {{ number_format($summary['balance'], 2, ',', '.') }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent fw-semibold">
                Lançamentos ({{ $entries->total() }})
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Data</th>
                            <th>Código</th>
                            <th>Descrição</th>
                            <th>Categoria</th>
                            <th>Tipo</th>
                            <th>Status</th>
                            <th class="text-end">Valor</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($entries as $entry)
                            <tr>
                                <td>{{ $entry->entry_date?->format('d/m/Y') }}</td>
                                <td><code>{{ $entry->code }}</code></td>
                                <td>{{ $entry->description }}</td>
                                <td>{{ $entry->category?->name ?? '—' }}</td>
                                <td>
                                    <span class="badge {{ $entry->type->value === 'income' ? 'bg-success' : 'bg-danger' }}">
                                        {{ $entry->type->label() }}
                                    </span>
                                </td>
                                <td>
                                    <span class="badge {{ $entry->status->value === 'paid' ? 'bg-primary' : ($entry->status->value === 'pending' ? 'bg-warning text-dark' : 'bg-dark') }}">
                                        {{ $entry->status->label() }}
                                    </span>
                                </td>
                                <td class="text-end fw-semibold {{ $entry->type->value === 'income' ? 'text-success' : 'text-danger' }}">
                                    R$ {{ number_format((float) $entry->amount, 2, ',', '.') }}
                                </td>
                                <td class="text-end">
                                    <button type="button"
                                            class="btn btn-outline-primary btn-xs btn-show-cash-entry"
                                            data-code="{{ $entry->code }}"
                                            data-entry-date="{{ $entry->entry_date?->format('Y-m-d') }}"
                                            data-description="{{ $entry->description }}"
                                            data-type="{{ $entry->type->value }}"
                                            data-status="{{ $entry->status->value }}"
                                            data-category="{{ $entry->category?->name ?? 'Sem categoria' }}"
                                            data-amount="{{ number_format((float) $entry->amount, 2, '.', '') }}"
                                            data-payment-method="{{ $entry->payment_method }}"
                                            data-notes="{{ $entry->notes }}"
                                            title="{{ __('actions.view') }}">
                                        <i class="ti ti-eye"></i>
                                    </button>

                                    <button type="button"
                                            class="btn btn-outline-info btn-xs btn-edit-cash-entry"
                                            data-update-url="{{ route('panel.financial.cash-flow.update', $entry) }}"
                                            data-entry-date="{{ $entry->entry_date?->format('Y-m-d') }}"
                                            data-description="{{ $entry->description }}"
                                            data-type="{{ $entry->type->value }}"
                                            data-status="{{ $entry->status->value }}"
                                            data-category-id="{{ $entry->category_id }}"
                                            data-amount="{{ number_format((float) $entry->amount, 2, '.', '') }}"
                                            data-payment-method="{{ $entry->payment_method }}"
                                            data-notes="{{ $entry->notes }}"
                                            title="{{ __('actions.edit') }}">
                                        <i class="ti ti-edit"></i>
                                    </button>

                                    <form method="POST"
                                          action="{{ route('panel.financial.cash-flow.destroy', $entry) }}"
                                          class="d-inline"
                                          onsubmit="return confirm('Deseja realmente excluir este lançamento?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-outline-danger btn-xs" type="submit" title="{{ __('actions.delete') }}">
                                            <i class="ti ti-trash"></i>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="text-center text-muted py-4">
                                    Nenhum lançamento no período informado.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($entries->hasPages())
                <div class="card-body">
                    {{ $entries->links() }}
                </div>
            @endif
        </div>
    </div>
@endsection

@section('modals')
    @include('components.modal_default')
@endsection

@section('javascript')
    <script>
        (function () {
            const modalDefault = document.getElementById('modal_default');
            if (!modalDefault) return;

            const typeLabels = { income: 'Receita', expense: 'Despesa' };
            const statusLabels = { paid: 'Pago', pending: 'Pendente', cancelled: 'Cancelado' };

            const escapeHtml = (value) => String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#39;');

            const parseEntry = (button) => ({
                code: button.dataset.code ?? '',
                entry_date: button.dataset.entryDate ?? '',
                description: button.dataset.description ?? '',
                type: button.dataset.type ?? 'income',
                status: button.dataset.status ?? 'paid',
                category_id: button.dataset.categoryId ?? '',
                category: button.dataset.category ?? 'Sem categoria',
                amount: button.dataset.amount ?? '0.00',
                payment_method: button.dataset.paymentMethod ?? '',
                notes: button.dataset.notes ?? '',
                update_url: button.dataset.updateUrl ?? '',
            });

            document.addEventListener('click', function (event) {
                const editButton = event.target.closest('.btn-edit-cash-entry');
                if (editButton) {
                    const entry = parseEntry(editButton);
                    window.dispatchEvent(new CustomEvent('edit-cash-entry', {
                        detail: {
                            entry: {
                                entry_date: entry.entry_date,
                                description: entry.description,
                                type: entry.type,
                                status: entry.status,
                                category_id: entry.category_id,
                                amount: entry.amount,
                                payment_method: entry.payment_method,
                                notes: entry.notes,
                            },
                            updateUrl: entry.update_url,
                        },
                    }));
                    return;
                }

                const showButton = event.target.closest('.btn-show-cash-entry');
                if (!showButton) return;

                const entry = parseEntry(showButton);
                const amount = Number.parseFloat(entry.amount || '0').toLocaleString('pt-BR', {
                    minimumFractionDigits: 2,
                    maximumFractionDigits: 2,
                });

                document.querySelector('.modal-title-default').textContent = 'Visualizar lançamento';
                document.getElementById('btn-modal-default').style.display = 'none';

                const dialog = modalDefault.querySelector('.modal-dialog');
                dialog.classList.remove('modal-sm', 'modal-md', 'modal-lg', 'modal-xl');
                dialog.classList.add('modal-lg');

                document.getElementById('erro-default').classList.remove('show');
                document.getElementById('erro-default').style.display = 'none';

                document.getElementById('retorno-default').innerHTML = `
                    <div class="row g-2">
                        <div class="col-md-4"><strong>Código:</strong><br>${escapeHtml(entry.code)}</div>
                        <div class="col-md-4"><strong>Data:</strong><br>${escapeHtml(entry.entry_date)}</div>
                        <div class="col-md-4"><strong>Valor:</strong><br>R$ ${escapeHtml(amount)}</div>
                        <div class="col-12"><strong>Descrição:</strong><br>${escapeHtml(entry.description || '—')}</div>
                        <div class="col-md-4"><strong>Tipo:</strong><br>${escapeHtml(typeLabels[entry.type] ?? entry.type)}</div>
                        <div class="col-md-4"><strong>Status:</strong><br>${escapeHtml(statusLabels[entry.status] ?? entry.status)}</div>
                        <div class="col-md-4"><strong>Categoria:</strong><br>${escapeHtml(entry.category || 'Sem categoria')}</div>
                        <div class="col-md-6"><strong>Forma de pagamento:</strong><br>${escapeHtml(entry.payment_method || '—')}</div>
                        <div class="col-12"><strong>Observações:</strong><br>${escapeHtml(entry.notes || '—')}</div>
                    </div>
                `;

                bootstrap.Modal.getOrCreateInstance(modalDefault).show();
            });
        })();
    </script>
@endsection
