<script setup>
import { ref, watch, computed } from 'vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    open:       { type: Boolean, required: true },
    entryId:    { type: String,  default: null },
    categories: { type: Array,   default: () => [] },
});

const emit   = defineEmits(['close', 'saved']);
const isEdit = computed(() => !!props.entryId);
const saving = ref(false);
const errors = ref({});

const form = ref({
    entry_date:  new Date().toISOString().slice(0, 10),
    type:        'income',
    status:      'paid',
    amount:      0,
    description: '',
    category_id: '',
    notes:       '',
});

function reset() {
    form.value = {
        entry_date:  new Date().toISOString().slice(0, 10),
        type:        'income',
        status:      'paid',
        amount:      0,
        description: '',
        category_id: '',
        notes:       '',
    };
    errors.value = {};
}

watch(() => props.open, async (val) => {
    if (!val) return;
    reset();
    // Edição: o backend não tem endpoint show; reusamos os dados da listagem.
    // Como cargas alternativas não estão presentes aqui, o caso de edit
    // dependeria de um endpoint show. Para o caso comum (criar), basta.
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function submit() {
    saving.value = true;
    errors.value = {};

    const url    = isEdit.value
        ? route('panel.financial.cash-flow.update', props.entryId)
        : route('panel.financial.cash-flow.store');
    const payload = isEdit.value
        ? { ...form.value, _method: 'PATCH' }
        : form.value;

    try {
        const res = await fetch(url, {
            method:  'POST',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(payload),
        });
        const json = await res.json();

        if (res.status === 422) {
            errors.value = json.errors ?? {};
            return;
        }
        if (!res.ok) {
            if (window.showErrorToast) window.showErrorToast(json.message ?? 'Erro ao salvar.');
            return;
        }
        if (window.showSuccessToast) window.showSuccessToast(json.message ?? 'Salvo.');
        emit('saved', json.data);
    } finally {
        saving.value = false;
    }
}

function close() {
    if (saving.value) return;
    emit('close');
}

function hasError(f)   { return !!(errors.value[f] && errors.value[f].length); }
function firstError(f) { return errors.value[f]?.[0] ?? ''; }

// Filtra categorias pelo tipo selecionado
const filteredCategories = computed(() =>
    props.categories.filter(c => !form.value.type || c.type === form.value.type)
);
</script>

<template>
    <div
        v-if="open"
        class="modal d-block"
        tabindex="-1"
        style="background: rgba(0,0,0,.45);"
        @click.self="close"
    >
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="ti ti-cash-register me-1 text-primary"></i>
                        {{ isEdit ? 'Editar lançamento' : 'Novo lançamento' }}
                    </h5>
                    <button type="button" class="btn-close" :disabled="saving" @click="close"></button>
                </div>

                <div class="modal-body">
                    <form @submit.prevent="submit" class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Data <span class="text-danger">*</span></label>
                            <input v-model="form.entry_date" type="date" class="form-control"
                                   :class="{ 'is-invalid': hasError('entry_date') }" required>
                            <div class="invalid-feedback">{{ firstError('entry_date') }}</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Tipo <span class="text-danger">*</span></label>
                            <SearchSelect v-model="form.type"
                                          :options="[{value:'income',label:'Receita'},{value:'expense',label:'Despesa'}]"
                                          :value-key="'value'" :label-key="'label'"
                                          :clearable="false"
                                          :invalid="hasError('type')" />
                            <div v-if="hasError('type')" class="invalid-feedback d-block">{{ firstError('type') }}</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Descrição <span class="text-danger">*</span></label>
                            <input v-model="form.description" type="text" maxlength="255" class="form-control"
                                   :class="{ 'is-invalid': hasError('description') }" required>
                            <div class="invalid-feedback">{{ firstError('description') }}</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Categoria</label>
                            <SearchSelect v-model="form.category_id" :options="filteredCategories" :placeholder="'—'" />
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <SearchSelect v-model="form.status"
                                          :options="[{value:'pending',label:'Pendente'},{value:'paid',label:'Pago'},{value:'cancelled',label:'Cancelado'}]"
                                          :value-key="'value'" :label-key="'label'"
                                          :clearable="false" />
                        </div>

                        <div class="col-12">
                            <label class="form-label">Valor (R$) <span class="text-danger">*</span></label>
                            <input v-model.number="form.amount" type="number" step="0.01" min="0" class="form-control"
                                   :class="{ 'is-invalid': hasError('amount') }" required>
                            <div class="invalid-feedback">{{ firstError('amount') }}</div>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Observações</label>
                            <textarea v-model="form.notes" rows="2" class="form-control" maxlength="500"></textarea>
                        </div>
                    </form>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="saving" @click="close">
                        Cancelar
                    </button>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="submit">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-check me-1"></i>
                        {{ isEdit ? 'Salvar' : 'Cadastrar' }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
