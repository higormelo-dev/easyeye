<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout         from '@/Layouts/AppLayout.vue';
import PageHeader        from '@/Components/Panel/PageHeader.vue';
import TablePagination   from '@/Components/Panel/TablePagination.vue';
import ActionDropdown    from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton  from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup   from '@/Components/Panel/ActionIconGroup.vue';
import SearchSelect      from '@/Components/Panel/SearchSelect.vue';
import CashEntryFormModal from './CashEntryFormModal.vue';

const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    entries:     { type: Object, required: true },
    categories:  { type: Array,  default: () => [] },
    summary:     { type: Object, default: () => ({}) },
    filters:     { type: Object, default: () => ({}) },
    t:           { type: Object, default: () => ({}) },
});

const form = ref({ ...props.filters });

function applyFilter() {
    router.get(route('panel.financial.cash-flow.index'), form.value, {
        preserveState: true, preserveScroll: true,
    });
}

function resetFilter() {
    router.get(route('panel.financial.cash-flow.index'));
}

function brl(v) {
    return 'R$ ' + Number(v ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

// ── Form modal ──────────────────────────────────────────────────────────────
const formOpen   = ref(false);
const editingId  = ref(null);

function openCreate() { editingId.value = null;  formOpen.value = true; }
function openEdit(e)  { editingId.value = e.id; formOpen.value = true; }
function onSaved()    { formOpen.value = false; router.reload({ only: ['entries', 'summary'] }); }

async function onDelete(entry) {
    if (!confirm('Excluir este lançamento?')) return;
    const res = await fetch(route('panel.financial.cash-flow.destroy', entry.id), {
        method: 'DELETE',
        headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf() },
    });
    if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast('Lançamento removido.');
        router.reload({ only: ['entries', 'summary'] });
    } else if (window.showErrorToast) {
        window.showErrorToast('Erro ao remover.');
    }
}

const typeBadge = (t) => t === 'income'
    ? 'badge badge-soft-success rounded text-success border border-success'
    : 'badge badge-soft-danger rounded text-danger border border-danger';

const statusBadge = (s) => {
    if (s === 'paid')      return 'badge bg-success';
    if (s === 'pending')   return 'badge bg-warning text-dark';
    if (s === 'cancelled') return 'badge bg-danger';
    return 'badge bg-secondary';
};
</script>

<template>
    <AppLayout title="Fluxo de Caixa" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Fluxo de Caixa">
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" @click="openCreate">
                        <i class="ti ti-plus me-1"></i>Novo lançamento
                    </button>
                </template>
            </PageHeader>

            <!-- KPIs do período -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-success border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Receitas (período)</small>
                            <div class="fw-bold fs-5 text-success">{{ brl(summary.income) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-danger border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Despesas (período)</small>
                            <div class="fw-bold fs-5 text-danger">{{ brl(summary.expense) }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-info border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">Saldo</small>
                            <div class="fw-bold fs-5" :class="(summary.balance ?? 0) >= 0 ? 'text-success' : 'text-danger'">
                                {{ brl(summary.balance) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card border-0 shadow-sm border-start border-warning border-3 h-100">
                        <div class="card-body py-3">
                            <small class="text-muted d-block">A receber</small>
                            <div class="fw-bold fs-5 text-warning">{{ brl(summary.pending) }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Filtros -->
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body py-3">
                    <form @submit.prevent="applyFilter" class="row g-2 align-items-end">
                        <div class="col-md-2">
                            <label class="form-label small mb-1">De</label>
                            <input v-model="form.from" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Até</label>
                            <input v-model="form.to" type="date" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Tipo</label>
                            <SearchSelect
                                v-model="form.type"
                                :options="[{ value: 'income', label: 'Receita' }, { value: 'expense', label: 'Despesa' }]"
                                :value-key="'value'"
                                :label-key="'label'"
                                :placeholder="'Todos'"
                            />
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">Status</label>
                            <SearchSelect
                                v-model="form.status"
                                :options="[{ value: 'pending', label: 'Pendente' }, { value: 'paid', label: 'Pago' }, { value: 'cancelled', label: 'Cancelado' }]"
                                :value-key="'value'"
                                :label-key="'label'"
                                :placeholder="'Todos'"
                            />
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small mb-1">Categoria</label>
                            <SearchSelect
                                v-model="form.category_id"
                                :options="categories"
                                :placeholder="'Todas'"
                            />
                        </div>
                        <div class="col-md-1 d-flex gap-1">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="ti ti-filter"></i>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm" @click="resetFilter">
                                <i class="ti ti-refresh"></i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabela -->
            <div class="card">
                <div class="table-responsive">
                    <table class="table table-nowrap table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Descrição</th>
                                <th>Categoria</th>
                                <th>Convênio</th>
                                <th class="text-center">Tipo</th>
                                <th class="text-center">Status</th>
                                <th class="text-end">Valor</th>
                                <th class="text-end">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="entries.data.length === 0">
                                <td colspan="8" class="text-center text-muted py-5">
                                    <i class="ti ti-cash-register fs-1 d-block mb-2"></i>
                                    Nenhum lançamento no período.
                                </td>
                            </tr>
                            <tr v-for="entry in entries.data" :key="entry.id">
                                <td class="text-muted small">{{ entry.entry_date }}</td>
                                <td class="fw-medium">{{ entry.description }}</td>
                                <td class="text-muted">{{ entry.category_name || '—' }}</td>
                                <td class="text-muted">{{ entry.covenant_name || '—' }}</td>
                                <td class="text-center">
                                    <span :class="typeBadge(entry.type)">
                                        {{ entry.type === 'income' ? 'Receita' : 'Despesa' }}
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span :class="statusBadge(entry.status)" class="rounded fs-11 fw-medium">
                                        {{ entry.status === 'paid' ? 'Pago' :
                                           entry.status === 'pending' ? 'Pendente' :
                                           entry.status === 'cancelled' ? 'Cancelado' : entry.status }}
                                    </span>
                                </td>
                                <td class="text-end fw-bold" :class="entry.type === 'income' ? 'text-success' : 'text-danger'">
                                    {{ brl(entry.amount) }}
                                </td>
                                <td class="text-end">
                                    <ActionIconGroup align="end" gap="tight">
                                        <ActionIconButton
                                            icon="ti ti-edit"
                                            title="Editar"
                                            :disabled="entry.has_claim"
                                            @click="openEdit(entry)"
                                        />
                                        <ActionIconButton
                                            icon="ti ti-trash"
                                            title="Excluir"
                                            variant="danger"
                                            :disabled="entry.has_claim"
                                            @click="onDelete(entry)"
                                        />
                                    </ActionIconGroup>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <TablePagination :data="entries" class="mt-3" />

            <CashEntryFormModal
                :open="formOpen"
                :entry-id="editingId"
                :categories="categories"
                @close="formOpen = false"
                @saved="onSaved"
            />
        </div>
    </AppLayout>
</template>
