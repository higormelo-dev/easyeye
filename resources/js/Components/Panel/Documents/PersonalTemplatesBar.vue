<script setup>
import { computed, onMounted, ref, useId } from 'vue';
import { documentTexts, td, fill } from '@/Support/clinicalDocuments.js';
import { enterSubmits } from '@/Support/subform.js';

/**
 * "Salvar como modelo" + "Meus modelos" (Fase 2 / B3): modelos PESSOAIS do
 * médico, por clínica e com nome, para receita, pedido de exames e
 * relatório. Só o dono lista/edita/exclui (servidor). `build()` devolve o
 * que salvar agora ({ body, payload?, report_setting_content_id? } ou null).
 *
 * `showSelect=false`: a lista já aparece em outro seletor (editor de
 * Documentações — junto dos modelos da clínica); o pai informa o modelo
 * escolhido por v-model.
 */
const props = defineProps({
    kind: { type: String, default: null },
    // `text` = só os modelos que um modal entende (receita/pedido: corpo em
    // texto + itens); vazio = todos (editor de Documentações).
    format: { type: String, default: null },
    // Vai junto ao salvar/atualizar: prontuário de origem e data do documento
    // — o servidor devolve os dados do paciente às variáveis (LGPD).
    context: { type: Object, default: () => ({}) },
    url: { type: String, required: true },
    // `.../doctor-document-templates/__ID__` (PUT e DELETE)
    itemUrlTemplate: { type: String, required: true },
    build: { type: Function, required: true },
    showSelect: { type: Boolean, default: true },
    modelValue: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    t: { type: Object, default: null },
});

const emit = defineEmits(['update:modelValue', 'apply', 'changed']);

const texts = documentTexts(props.t);
const tt = (path, fallback) => td(texts, path, fallback);
const uid = useId();

const templates = ref([]);
const mode = ref('idle'); // idle | save | rename | delete
const name = ref('');
const busy = ref(false);
const message = ref('');
const error = ref('');

const selectedId = computed({
    get: () => props.modelValue ?? '',
    set: (v) => emit('update:modelValue', v ?? ''),
});
const selected = computed(() => templates.value.find((t) => t.id === selectedId.value) ?? null);

/**
 * `props.build()` desta barra só sabe montar o formato da PRÓPRIA tela
 * (`format` = "text" nos modais de receita/pedido; null/"html" no editor de
 * Documentações, que lista modelos dos dois formatos — doc do componente).
 * "Atualizar modelo atual" com um modelo do OUTRO formato sobrescreveria o
 * `body`/`payload` dele com o que esta tela produz, destruindo o que a outra
 * tela espera (2ª revisão Codex, P2-7).
 */
const canUpdateSelected = computed(
    () => Boolean(selected.value) && selected.value.body_format === (props.format ?? 'html'),
);

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function request(method, url, body = null) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => ({}));
    return { ok: res.ok, json };
}

async function load() {
    const params = new URLSearchParams();
    if (props.kind) params.set('kind', props.kind);
    if (props.format) params.set('format', props.format);
    const query = params.toString();
    const url = query ? `${props.url}?${query}` : props.url;
    try {
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        templates.value = res.ok ? ((await res.json())?.data ?? []) : [];
    } catch {
        templates.value = [];
    }
}

onMounted(load);
defineExpose({ reload: load });

// Escolher no seletor (ação do médico) aplica o modelo; o v-model só guarda qual é.
function onSelect(id) {
    selectedId.value = id;
    const template = templates.value.find((t) => t.id === id);
    if (!template) return;
    emit('apply', template);
    flash(fill(tt('templates.applied', 'Modelo ":name" aplicado.'), { name: template.name }));
}

function flash(text) {
    message.value = text;
    error.value = '';
    setTimeout(() => {
        if (message.value === text) message.value = '';
    }, 3000);
}

function firstError(json) {
    const errors = json?.errors ?? {};
    const first = Object.values(errors)[0];
    return (
        (Array.isArray(first) ? first[0] : first) ||
        json?.message ||
        tt('templates.error', 'Não foi possível salvar o modelo.')
    );
}

function start(next) {
    error.value = '';
    message.value = '';
    name.value = next === 'rename' ? (selected.value?.name ?? '') : '';
    mode.value = next;
}

async function submitName() {
    const value = name.value.trim();
    if (value.length < 2 || busy.value) return;
    busy.value = true;
    try {
        if (mode.value === 'rename' && selected.value) {
            // O servidor confere o nome novo contra dado do paciente — precisa
            // do prontuário de origem igual ao salvar (9ª revisão Codex, P2).
            const { ok, json } = await request('PUT', props.itemUrlTemplate.replace('__ID__', selected.value.id), {
                name: value,
                ...(props.context ?? {}),
            });
            if (!ok) {
                error.value = firstError(json);
                return;
            }
        } else {
            const content = props.build();
            if (!content || !String(content.body ?? '').trim()) {
                error.value = tt('templates.body_required', 'O conteúdo está vazio.');
                return;
            }
            const { ok, json } = await request('POST', props.url, {
                kind: props.kind ?? 'report',
                name: value,
                ...content,
                ...(props.context ?? {}),
            });
            if (!ok) {
                error.value = firstError(json);
                return;
            }
            await load();
            selectedId.value = json.data?.id ?? '';
        }
        await load();
        mode.value = 'idle';
        flash(tt('templates.saved', 'Modelo salvo.'));
        emit('changed');
    } finally {
        busy.value = false;
    }
}

const onNameKeydown = enterSubmits(submitName);

async function updateCurrent() {
    if (!canUpdateSelected.value || busy.value) return;
    const content = props.build();
    if (!content || !String(content.body ?? '').trim()) {
        error.value = tt('templates.body_required', 'O conteúdo está vazio.');
        return;
    }
    busy.value = true;
    try {
        const { ok, json } = await request('PUT', props.itemUrlTemplate.replace('__ID__', selected.value.id), {
            body: content.body,
            payload: content.payload ?? null,
            ...(props.context ?? {}),
        });
        if (!ok) {
            error.value = firstError(json);
            return;
        }
        await load();
        flash(tt('templates.saved', 'Modelo salvo.'));
        emit('changed');
    } finally {
        busy.value = false;
    }
}

async function confirmDelete() {
    if (!selected.value || busy.value) return;
    busy.value = true;
    try {
        const { ok, json } = await request('DELETE', props.itemUrlTemplate.replace('__ID__', selected.value.id));
        if (!ok) {
            error.value = firstError(json);
            return;
        }
        selectedId.value = '';
        mode.value = 'idle';
        await load();
        flash(tt('templates.deleted', 'Modelo excluído.'));
        emit('changed');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="ee-tpl-bar" data-testid="personal-templates">
        <div v-if="showSelect" class="mb-1">
            <label class="form-label small mb-1" :for="`${uid}-sel`">{{
                tt('templates.select', 'Meus modelos')
            }}</label>
            <select
                :id="`${uid}-sel`"
                :value="selectedId"
                class="form-select form-select-sm"
                :disabled="disabled"
                data-testid="personal-templates-select"
                @change="onSelect($event.target.value)"
            >
                <option value="">
                    {{
                        templates.length
                            ? tt('templates.select_ph', 'Usar um modelo meu…')
                            : tt('templates.empty', 'Você ainda não salvou modelos.')
                    }}
                </option>
                <option v-for="tpl in templates" :key="tpl.id" :value="tpl.id">{{ tpl.name }}</option>
            </select>
        </div>

        <div v-if="mode === 'idle'" class="d-flex gap-1 flex-wrap align-items-center">
            <button
                type="button"
                class="btn btn-outline-primary btn-sm"
                :disabled="disabled || busy"
                data-testid="personal-templates-save-as"
                @click="start('save')"
            >
                <i class="ti ti-bookmark-plus me-1" aria-hidden="true"></i
                >{{ tt('templates.save_as', 'Salvar como modelo') }}
            </button>
            <template v-if="selected">
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    :disabled="disabled || busy || !canUpdateSelected"
                    :title="
                        canUpdateSelected
                            ? ''
                            : tt(
                                  'templates.update_format_mismatch',
                                  'Modelo de outro formato de tela — abra-o lá para atualizar.',
                              )
                    "
                    data-testid="personal-templates-update"
                    @click="updateCurrent"
                >
                    <i class="ti ti-refresh me-1" aria-hidden="true"></i
                    >{{ tt('templates.update', 'Atualizar com o conteúdo atual') }}
                </button>
                <button
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    :disabled="busy"
                    data-testid="personal-templates-rename"
                    @click="start('rename')"
                >
                    <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ tt('templates.rename', 'Renomear') }}
                </button>
                <button
                    type="button"
                    class="btn btn-outline-danger btn-sm"
                    :disabled="busy"
                    data-testid="personal-templates-delete"
                    @click="start('delete')"
                >
                    <i class="ti ti-trash me-1" aria-hidden="true"></i>{{ tt('templates.delete', 'Excluir modelo') }}
                </button>
            </template>
        </div>

        <!-- <div>, não <form>: a barra pode ficar dentro de outro formulário. -->
        <div
            v-else-if="mode === 'save' || mode === 'rename'"
            class="d-flex gap-1 flex-wrap align-items-end"
            role="group"
            :aria-label="tt('templates.name', 'Nome do modelo')"
            @keydown="onNameKeydown"
        >
            <div class="flex-grow-1" style="min-width: 12rem">
                <label class="form-label small mb-1" :for="`${uid}-name`">{{
                    tt('templates.name', 'Nome do modelo')
                }}</label>
                <input
                    :id="`${uid}-name`"
                    v-model="name"
                    type="text"
                    maxlength="120"
                    class="form-control form-control-sm"
                    :class="{ 'is-invalid': error }"
                    :placeholder="tt('templates.name_ph', 'Ex.: Pós-op catarata padrão')"
                    data-testid="personal-templates-name"
                />
            </div>
            <button
                type="button"
                class="btn btn-primary btn-sm"
                :disabled="busy || name.trim().length < 2"
                data-testid="personal-templates-confirm"
                @click="submitName"
            >
                <span v-if="busy" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                >{{ tt('templates.save', 'Salvar modelo') }}
            </button>
            <button type="button" class="btn btn-light btn-sm" @click="mode = 'idle'">
                {{ tt('templates.cancel', 'Cancelar') }}
            </button>
        </div>

        <div v-else-if="mode === 'delete'" class="d-flex gap-1 flex-wrap align-items-center" role="group">
            <span class="small">{{
                fill(tt('templates.delete_confirm', 'Excluir o modelo ":name"?'), { name: selected?.name ?? '' })
            }}</span>
            <button
                type="button"
                class="btn btn-danger btn-sm"
                :disabled="busy"
                data-testid="personal-templates-delete-confirm"
                @click="confirmDelete"
            >
                {{ tt('templates.delete', 'Excluir modelo') }}
            </button>
            <button type="button" class="btn btn-light btn-sm" @click="mode = 'idle'">
                {{ tt('templates.cancel', 'Cancelar') }}
            </button>
        </div>

        <div v-if="error" class="small text-danger mt-1" role="alert">{{ error }}</div>
        <div class="small text-success mt-1" role="status" aria-live="polite">{{ message }}</div>
        <small class="text-body-secondary d-block">{{
            tt('templates.hint', 'Só você vê os seus modelos, nesta clínica.')
        }}</small>
    </div>
</template>
