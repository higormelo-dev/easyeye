<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout    from '@/Layouts/AppLayout.vue';
import PageHeader   from '@/Components/Panel/PageHeader.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    breadcrumbs:        { type: Array,  default: () => [] },
    covenants:          { type: Array,  default: () => [] },
    procedures:         { type: Array,  default: () => [] },
    selectedCovenantId: { type: String, default: '' },
    prices:             { type: Object, default: () => ({}) }, // { procedure_id: { price, charging } }
    t:                  { type: Object, default: () => ({}) },
});

const covenantId = ref(props.selectedCovenantId);
const saving     = ref(false);

// Monta as linhas da grade a partir dos procedimentos + preços existentes.
function buildRows() {
    return props.procedures.map((p) => {
        const existing = props.prices[p.id] ?? {};
        return {
            procedure_id: p.id,
            code:         p.code,
            name:         p.name,
            price:        existing.price ?? '',
            charging:     existing.charging ?? true,
        };
    });
}

const rows = ref(buildRows());

// Ao recarregar 'prices' (troca de convênio), reconstrói a grade.
watch(() => props.prices, () => { rows.value = buildRows(); });

// Recarrega os preços ao trocar o convênio no SearchSelect.
watch(covenantId, () => onCovenantChange());

function onCovenantChange() {
    router.get(
        route('panel.financial.procedure-prices.index'),
        { covenant_id: covenantId.value },
        { preserveState: true, preserveScroll: true, only: ['prices', 'selectedCovenantId'] },
    );
}

function save() {
    if (!covenantId.value) return;
    saving.value = true;

    const items = rows.value.map((r) => ({
        procedure_id: r.procedure_id,
        price:        r.price === '' || r.price === null ? null : r.price,
        charging:     r.charging,
    }));

    router.post(
        route('panel.financial.procedure-prices.store'),
        { covenant_id: covenantId.value, items },
        {
            preserveScroll: true,
            onSuccess: () => { if (window.showSuccessToast) window.showSuccessToast(props.t.pp_saved ?? 'Preços atualizados.'); },
            onError:   () => { if (window.showErrorToast) window.showErrorToast('Erro ao salvar os preços.'); },
            onFinish:  () => { saving.value = false; },
        },
    );
}
</script>

<template>
    <AppLayout :title="t.pp_title ?? 'Tabela de Preços'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid">
            <PageHeader :title="t.pp_title ?? 'Tabela de Preços'" :subtitle="t.pp_subtitle ?? ''">
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="saving || !covenantId" @click="save">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-device-floppy me-1"></i>
                        {{ t.pp_save ?? 'Salvar preços' }}
                    </button>
                </template>
            </PageHeader>

            <div v-if="!covenants.length" class="alert alert-warning">
                {{ t.pp_no_covenants ?? 'Cadastre um convênio antes de definir preços.' }}
            </div>

            <div v-else class="card">
                <div class="card-body">
                    <div class="row g-3 align-items-end mb-3">
                        <div class="col-md-4">
                            <label class="form-label">{{ t.pp_covenant ?? 'Convênio' }}</label>
                            <SearchSelect v-model="covenantId" :options="covenants" :clearable="false"
                                          :placeholder="t.pp_covenant ?? 'Convênio'" />
                        </div>
                        <div class="col-md-8">
                            <small class="text-muted">{{ t.pp_empty_hint ?? '' }}</small>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 120px">{{ t.pp_code ?? 'Código' }}</th>
                                    <th>{{ t.pp_procedure ?? 'Procedimento' }}</th>
                                    <th style="width: 180px">{{ t.pp_price ?? 'Preço (R$)' }}</th>
                                    <th style="width: 120px" class="text-center">{{ t.pp_charging ?? 'Faturável' }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.procedure_id">
                                    <td><code>{{ row.code }}</code></td>
                                    <td>{{ row.name }}</td>
                                    <td>
                                        <input v-model.number="row.price" type="number" step="0.01" min="0"
                                               class="form-control form-control-sm" placeholder="—">
                                    </td>
                                    <td class="text-center">
                                        <input v-model="row.charging" type="checkbox" class="form-check-input">
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
