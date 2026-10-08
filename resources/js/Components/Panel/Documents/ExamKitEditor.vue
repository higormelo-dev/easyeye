<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import { documentTexts, td, fill } from '@/Support/clinicalDocuments.js';

/**
 * Editor de kit de exames (Fase 2 / B2) — Configurações › Kits de exames
 * (kit da clínica, com convênios) e manager (kit padrão, sem convênios).
 * Exame = nome + código TUSS; na clínica, dá para escolher do catálogo de
 * procedimentos dela. O servidor valida nome único, limite, tenant e
 * convênios ativos.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    kit: { type: Object, default: null },
    withCovenants: { type: Boolean, default: false },
    covenants: { type: Array, default: () => [] },
    procedureSearchUrl: { type: String, default: '' },
    maxItems: { type: Number, default: 20 },
    busy: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
    t: { type: Object, default: null },
});

const emit = defineEmits(['close', 'save']);

const texts = documentTexts(props.t);
const tt = (path, fallback) => td(texts, path, fallback);
const uid = useId();

let seq = 0;
const form = reactive({ name: '', description: '', active: true, items: [], covenant_ids: [] });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const kit = props.kit;
        form.name = kit?.name ?? '';
        form.description = kit?.description ?? '';
        form.active = kit ? Boolean(kit.active) : true;
        form.items = (kit?.items ?? []).map((i) => ({
            key: `k${++seq}`,
            name: i.name,
            tuss_code: i.tuss_code ?? '',
            procedure_id: i.procedure_id ?? null,
            procedure_name: i.procedure_name ?? null,
        }));
        if (!form.items.length) addEmpty();
        form.covenant_ids = (kit?.covenants ?? []).map((c) => c.id);
    },
);

// Convênios: ativos da clínica + os inativos que o kit já tinha (aparecem marcados).
const covenantOptions = computed(() => {
    const extra = (props.kit?.covenants ?? [])
        .filter((c) => !props.covenants.some((o) => o.id === c.id))
        .map((c) => ({ id: c.id, name: `${c.name} (${tt('kits.covenant_inactive', 'inativo')})` }));
    return [...props.covenants, ...extra];
});

function addEmpty() {
    if (form.items.length >= props.maxItems) return;
    form.items.push({ key: `k${++seq}`, name: '', tuss_code: '', procedure_id: null, procedure_name: null });
}

function move(index, delta) {
    const target = index + delta;
    if (target < 0 || target >= form.items.length) return;
    const [item] = form.items.splice(index, 1);
    form.items.splice(target, 0, item);
}

function remove(index) {
    form.items.splice(index, 1);
}

// Editar nome/código de um item vinculado ao catálogo solta o vínculo: o
// item passa a valer pelo que foi digitado (antes o procedure_id antigo
// ficava e o pedido saía com o exame/código do catálogo, não o editado).
function unlinkOnEdit(item) {
    if (!item.procedure_id) return;
    item.procedure_id = null;
    item.procedure_name = null;
}

// ── Catálogo da clínica ───────────────────────────────────────────────────
const query = ref('');
const results = ref([]);
const resultsOpen = ref(false);
let timer = null;

function onSearch() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        const q = query.value.trim();
        if (q.length < 2 || !props.procedureSearchUrl) {
            results.value = [];
            resultsOpen.value = false;
            return;
        }
        try {
            const res = await fetch(`${props.procedureSearchUrl}?q=${encodeURIComponent(q)}`, {
                headers: { Accept: 'application/json' },
            });
            results.value = res.ok ? await res.json() : [];
        } catch {
            results.value = [];
        }
        resultsOpen.value = results.value.length > 0;
    }, 250);
}

function pick(proc) {
    if (form.items.length >= props.maxItems) return;
    // Substitui uma linha vazia, se houver.
    const empty = form.items.find((i) => !i.name.trim());
    const item = empty ?? { key: `k${++seq}` };
    Object.assign(item, {
        name: proc.name,
        tuss_code: proc.code ?? '',
        procedure_id: proc.id,
        procedure_name: proc.name,
    });
    if (!empty) form.items.push(item);
    query.value = '';
    results.value = [];
    resultsOpen.value = false;
}

const fieldError = (name) => props.errors?.[name]?.[0] ?? '';
const itemsError = computed(() => {
    const key = Object.keys(props.errors ?? {}).find((k) => k === 'items' || k.startsWith('items.'));
    return key ? props.errors[key][0] : '';
});
const covenantError = computed(() => {
    const key = Object.keys(props.errors ?? {}).find((k) => k.startsWith('covenant_ids'));
    return key ? props.errors[key][0] : '';
});

function submit() {
    emit('save', {
        name: form.name.trim(),
        description: form.description.trim() || null,
        active: form.active,
        items: form.items
            .filter((i) => i.name.trim())
            .map((i) => ({
                name: i.name.trim(),
                tuss_code: i.tuss_code.trim() || null,
                ...(i.procedure_id ? { procedure_id: i.procedure_id } : {}),
            })),
        ...(props.withCovenants ? { covenant_ids: form.covenant_ids } : {}),
    });
}
</script>

<template>
    <CenteredModal :open="open" size="lg" @close="$emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-list-check me-2 text-primary" aria-hidden="true"></i
                >{{ kit ? tt('kits.edit', 'Editar kit') : tt('kits.new', 'Novo kit') }}
            </h5>
        </template>

        <form :id="`${uid}-form`" novalidate data-testid="exam-kit-editor" @submit.prevent="submit">
            <div class="row g-2 mb-2">
                <div class="col-12 col-md-8">
                    <label class="form-label small mb-1" :for="`${uid}-name`"
                        >{{ tt('kits.name', 'Nome do kit') }}
                        <span class="text-danger" aria-hidden="true">*</span></label
                    >
                    <input
                        :id="`${uid}-name`"
                        v-model="form.name"
                        type="text"
                        maxlength="120"
                        required
                        class="form-control form-control-sm"
                        :class="{ 'is-invalid': fieldError('name') }"
                        data-testid="exam-kit-name"
                    />
                    <div v-if="fieldError('name')" class="invalid-feedback">{{ fieldError('name') }}</div>
                </div>
                <div class="col-12 col-md-4 d-flex align-items-end">
                    <div class="form-check form-switch mb-1">
                        <input :id="`${uid}-active`" v-model="form.active" class="form-check-input" type="checkbox" />
                        <label class="form-check-label" :for="`${uid}-active`">{{ tt('kits.active', 'Ativo') }}</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label small mb-1" :for="`${uid}-desc`">{{
                        tt('kits.description', 'Descrição')
                    }}</label>
                    <input
                        :id="`${uid}-desc`"
                        v-model="form.description"
                        type="text"
                        maxlength="500"
                        class="form-control form-control-sm"
                    />
                </div>
            </div>

            <fieldset class="ee-kit__items mb-2">
                <legend class="form-label small mb-1 d-flex justify-content-between w-100">
                    <span>{{ tt('kits.items', 'Exames') }}</span>
                    <span class="text-body-secondary">{{ form.items.length }}/{{ maxItems }}</span>
                </legend>
                <div v-for="(item, index) in form.items" :key="item.key" class="ee-kit__row">
                    <div class="flex-grow-1 min-w-0">
                        <label class="visually-hidden" :for="`${uid}-n-${item.key}`">{{
                            tt('kits.item_name', 'Exame')
                        }}</label>
                        <input
                            :id="`${uid}-n-${item.key}`"
                            v-model="item.name"
                            type="text"
                            maxlength="160"
                            class="form-control form-control-sm"
                            :class="{ 'is-invalid': errors?.[`items.${index}.name`] }"
                            :placeholder="tt('kits.item_name', 'Exame')"
                            data-testid="exam-kit-item-name"
                            @input="unlinkOnEdit(item)"
                        />
                        <small v-if="item.procedure_id" class="text-success d-block">
                            <i class="ti ti-link me-1" aria-hidden="true"></i>{{ item.procedure_name || item.name }}
                        </small>
                    </div>
                    <div class="ee-kit__code">
                        <label class="visually-hidden" :for="`${uid}-c-${item.key}`">{{
                            tt('kits.item_code', 'Código TUSS')
                        }}</label>
                        <input
                            :id="`${uid}-c-${item.key}`"
                            v-model="item.tuss_code"
                            type="text"
                            inputmode="numeric"
                            maxlength="10"
                            class="form-control form-control-sm"
                            :placeholder="tt('kits.item_code', 'Código TUSS')"
                            :title="tt('kits.item_code_hint', '')"
                            data-testid="exam-kit-item-code"
                            @input="unlinkOnEdit(item)"
                        />
                    </div>
                    <div class="btn-group btn-group-sm flex-shrink-0">
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            :disabled="index === 0"
                            :aria-label="fill(tt('kits.item_up', 'Subir :name'), { name: item.name })"
                            @click="move(index, -1)"
                        >
                            <i class="ti ti-arrow-up" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            :disabled="index === form.items.length - 1"
                            :aria-label="fill(tt('kits.item_down', 'Descer :name'), { name: item.name })"
                            @click="move(index, 1)"
                        >
                            <i class="ti ti-arrow-down" aria-hidden="true"></i>
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-danger"
                            :aria-label="fill(tt('kits.item_remove', 'Remover exame :name'), { name: item.name })"
                            @click="remove(index)"
                        >
                            <i class="ti ti-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <div v-if="itemsError" class="invalid-feedback d-block" role="alert">{{ itemsError }}</div>
                <div class="d-flex gap-2 flex-wrap align-items-start mt-2">
                    <button
                        type="button"
                        class="btn btn-outline-primary btn-sm"
                        :disabled="form.items.length >= maxItems"
                        data-testid="exam-kit-add-item"
                        @click="addEmpty"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tt('kits.item_add', 'Adicionar exame') }}
                    </button>
                    <div v-if="procedureSearchUrl" class="position-relative flex-grow-1" style="min-width: 14rem">
                        <label class="visually-hidden" :for="`${uid}-q`">{{
                            tt('kits.item_catalog', 'Buscar no catálogo da clínica')
                        }}</label>
                        <input
                            :id="`${uid}-q`"
                            v-model="query"
                            type="text"
                            autocomplete="off"
                            class="form-control form-control-sm"
                            role="combobox"
                            :aria-expanded="resultsOpen ? 'true' : 'false'"
                            :aria-controls="`${uid}-results`"
                            :placeholder="tt('kits.item_catalog', 'Buscar no catálogo da clínica')"
                            :disabled="form.items.length >= maxItems"
                            @input="onSearch"
                        />
                        <ul
                            v-if="resultsOpen"
                            :id="`${uid}-results`"
                            class="list-group shadow-sm position-absolute w-100 ee-kit__results"
                            role="listbox"
                        >
                            <li
                                v-for="proc in results"
                                :key="proc.id"
                                class="list-group-item list-group-item-action py-1 px-2 small"
                                role="option"
                                tabindex="0"
                                @mousedown.prevent="pick(proc)"
                                @keydown.enter.prevent="pick(proc)"
                            >
                                <span class="fw-semibold">{{ proc.name }}</span>
                                <span v-if="proc.code" class="text-body-secondary ms-1">({{ proc.code }})</span>
                            </li>
                        </ul>
                    </div>
                </div>
                <small class="form-text d-block mt-1">{{
                    tt(
                        'kits.item_code_hint',
                        'No pedido, o código é procurado no catálogo de procedimentos da clínica; sem cadastro, o exame sai como texto.',
                    )
                }}</small>
            </fieldset>

            <div v-if="withCovenants" class="mb-2">
                <label class="form-label small mb-1" :for="`${uid}-cov`">{{ tt('kits.covenants', 'Convênios') }}</label>
                <SearchSelect
                    :id="`${uid}-cov`"
                    v-model="form.covenant_ids"
                    :options="covenantOptions"
                    value-key="id"
                    label-key="name"
                    multiple
                    :placeholder="tt('kits.covenants_all', 'Todos os convênios')"
                    :invalid="Boolean(covenantError)"
                />
                <div v-if="covenantError" class="invalid-feedback d-block" role="alert">{{ covenantError }}</div>
                <small class="form-text d-block">{{
                    tt('kits.covenants_hint', 'Em branco = vale para todos os convênios. Só convênios ativos.')
                }}</small>
            </div>
        </form>

        <template #footer>
            <button type="button" class="btn btn-secondary btn-sm" @click="$emit('close')">
                {{ tt('kits.cancel', 'Cancelar') }}
            </button>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="busy || form.name.trim().length < 2"
                data-testid="exam-kit-save"
                @click="submit"
            >
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ tt('kits.save', 'Salvar kit') }}
            </button>
        </template>
    </CenteredModal>
</template>

<style scoped>
.ee-kit__items {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.4rem;
    padding: 0.5rem 0.6rem;
    background: var(--bs-tertiary-bg);
}
.ee-kit__row {
    display: flex;
    gap: 0.4rem;
    align-items: flex-start;
    margin-bottom: 0.4rem;
}
.ee-kit__code {
    width: 7.5rem;
    flex-shrink: 0;
}
.ee-kit__results {
    z-index: 1080;
    top: 100%;
    max-height: 220px;
    overflow-y: auto;
}
.min-w-0 {
    min-width: 0;
}
@media (max-width: 575.98px) {
    .ee-kit__row {
        flex-wrap: wrap;
    }
    .ee-kit__row > .flex-grow-1 {
        flex-basis: 100%;
    }
    .ee-kit__code {
        flex: 1 1 auto;
        width: auto;
    }
}
</style>
