<script setup>
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Onda 3, P1 — CRUD dos prompts favoritos do médico autenticado.
 *
 * Limite hard de 5 prompts por médico (enforced pelo backend). Reordenação via
 * setas inline; sem drag-and-drop para manter o componente leve.
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    prompts:     { type: Array,  default: () => [] },
    limit:       { type: Number, default: 5 },
    labels:      { type: Object, default: () => ({}) },
});

const lbl = (k, fb = '') => props.labels?.[k] ?? fb;

const items = ref([...props.prompts]);
const showForm = ref(false);
const editing  = ref(null);
const form = reactive({ label: '', prompt: '' });
const submitting = ref(false);
const error = ref('');

const canAdd = computed(() => items.value.length < props.limit);

function openCreate() {
    editing.value = null;
    form.label = '';
    form.prompt = '';
    error.value = '';
    showForm.value = true;
}

function openEdit(p) {
    editing.value = p;
    form.label = p.label;
    form.prompt = p.prompt;
    error.value = '';
    showForm.value = true;
}

function closeForm() {
    showForm.value = false;
    editing.value = null;
}

async function submit() {
    if (!form.label?.trim() || !form.prompt?.trim() || form.prompt.trim().length < 12) {
        error.value = lbl('label', 'Título') + ' e prompt obrigatórios (mín. 12 chars no prompt).';
        return;
    }
    submitting.value = true;
    error.value = '';
    try {
        if (editing.value) {
            await window.axios.patch(
                route('panel.setting.ai-prompts.update', { aiPrompt: editing.value.id }),
                { label: form.label, prompt: form.prompt },
            );
            const idx = items.value.findIndex(x => x.id === editing.value.id);
            if (idx >= 0) items.value[idx] = { ...items.value[idx], label: form.label, prompt: form.prompt };
        } else {
            const { data } = await window.axios.post(
                route('panel.setting.ai-prompts.store'),
                { label: form.label, prompt: form.prompt },
            );
            items.value.push({
                id:       data.id,
                label:    form.label,
                prompt:   form.prompt,
                position: items.value.length,
            });
        }
        closeForm();
    } catch (e) {
        error.value = e?.response?.data?.message ?? lbl('limit_reached', 'Falha ao salvar.');
    } finally {
        submitting.value = false;
    }
}

async function remove(p) {
    if (!window.confirm(lbl('confirm_delete', 'Excluir este prompt?'))) return;
    try {
        await window.axios.delete(
            route('panel.setting.ai-prompts.destroy', { aiPrompt: p.id }),
        );
        items.value = items.value.filter(x => x.id !== p.id);
    } catch (e) {
        error.value = e?.response?.data?.message ?? 'Falha ao excluir.';
    }
}

async function move(p, dir) {
    const idx = items.value.findIndex(x => x.id === p.id);
    const target = idx + dir;
    if (idx < 0 || target < 0 || target >= items.value.length) return;
    const newOrder = [...items.value];
    [newOrder[idx], newOrder[target]] = [newOrder[target], newOrder[idx]];
    items.value = newOrder;
    try {
        await window.axios.post(
            route('panel.setting.ai-prompts.reorder'),
            { ids: newOrder.map(x => x.id) },
        );
    } catch {
        // se falhar, reverte localmente
        const back = [...items.value];
        [back[idx], back[target]] = [back[target], back[idx]];
        items.value = back;
    }
}
</script>

<template>
    <AppLayout>
        <PageHeader :breadcrumbs="breadcrumbs" :title="lbl('page_title', 'Meus prompts de IA')"
                    :subtitle="lbl('page_subtitle', '')" />

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-muted small">{{ items.length }} / {{ limit }}</span>
                    <button type="button" class="btn btn-sm btn-primary"
                            :disabled="!canAdd" @click="openCreate">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ lbl('create', 'Novo prompt') }}
                    </button>
                </div>

                <div v-if="!canAdd" class="alert alert-warning small">{{ lbl('limit_reached', 'Limite atingido.') }}</div>

                <div v-if="!items.length" class="text-center text-muted py-4">
                    <i class="ti ti-bookmark-off d-block fs-2 mb-2" aria-hidden="true"></i>
                    {{ lbl('empty', 'Você ainda não criou nenhum prompt.') }}
                </div>

                <ul v-else class="list-group list-group-flush">
                    <li v-for="(p, i) in items" :key="p.id" class="list-group-item px-0 py-2">
                        <div class="d-flex align-items-start gap-2">
                            <div class="d-flex flex-column gap-1">
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                        :disabled="i === 0" @click="move(p, -1)"
                                        :title="lbl('move_up', 'Mover para cima')">
                                    <i class="ti ti-chevron-up" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                        :disabled="i === items.length - 1" @click="move(p, 1)"
                                        :title="lbl('move_down', 'Mover para baixo')">
                                    <i class="ti ti-chevron-down" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="flex-grow-1" style="min-width:0;">
                                <div class="fw-semibold">{{ p.label }}</div>
                                <div class="small text-muted text-truncate" :title="p.prompt">{{ p.prompt }}</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-info" @click="openEdit(p)">
                                <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ lbl('edit', 'Editar') }}
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-danger" @click="remove(p)">
                                <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ lbl('delete', 'Excluir') }}
                            </button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <!-- Modal inline simples (sem OffcanvasPanel para ser leve) -->
        <div v-if="showForm" class="modal show d-block" tabindex="-1" style="background:rgba(0,0,0,.45);">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ editing ? lbl('edit', 'Editar') : lbl('create', 'Novo prompt') }}</h5>
                        <button type="button" class="btn-close" @click="closeForm"></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="error" class="alert alert-danger small">{{ error }}</div>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">{{ lbl('label', 'Título') }}</label>
                            <input v-model="form.label" type="text" class="form-control" maxlength="120" />
                        </div>
                        <div class="mb-0">
                            <label class="form-label small fw-semibold">{{ lbl('prompt', 'Texto do prompt') }}</label>
                            <textarea v-model="form.prompt" rows="5" class="form-control" maxlength="30000"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="closeForm">{{ lbl('cancel', 'Cancelar') }}</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="submitting" @click="submit">
                            <span v-if="submitting" class="spinner-border spinner-border-sm me-1"></span>
                            {{ lbl('save', 'Salvar') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
