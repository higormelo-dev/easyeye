<script setup>
import { reactive, computed, watch, ref, nextTick, onBeforeUnmount } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import {
    DEFAULT_VERTEX_MM,
    DEFAULT_PROFILE,
    computeContactLens,
    contactLensInputs,
    contactLensInvalid,
    contactLensNoteText,
    contactLensSummary,
    formatLens,
    formatTheoretical,
    isLegacyContactLens,
    lensTypeLabel,
    refractionFromRecord,
    vertexStatus,
} from './contactLens.js';

/**
 * Lente de contato DENTRO do prontuário, para usar durante a consulta:
 * refração dos óculos → cálculo → POTÊNCIA DE LC SUGERIDA (em destaque),
 * respeitando os incrementos reais das lentes da linha escolhida. O cálculo
 * teórico aparece pequeno, só para conferência. "Usar no prontuário" leva o
 * resultado ao formulário — gravado ao salvar a consulta (o servidor
 * recalcula com a mesma lógica; nunca confia no resultado do navegador).
 * Regras clínicas e fontes: contactLens.js.
 *
 * Cálculo gravado na versão 1 (antes da v2): assinado → mostrado como está;
 * em edição → os valores são trazidos para cá e, ao usar, grava v2.
 *
 * FORA DE ESCOPO DE PROPÓSITO: potência de LIO (SRK/T, Holladay, Barrett...)
 * — depende de biometria e tem disputa clínica entre fórmulas; embutir uma
 * aqui parecendo "a conta oficial" é risco real ao paciente.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    t: { type: Object, default: () => ({}) },
    // Cálculo já vinculado a esta consulta (ou null).
    modelValue: { type: Object, default: null },
    // Formulário do prontuário — fonte do "copiar refração".
    record: { type: Object, default: () => ({}) },
    // Prontuário assinado: só consulta.
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'apply', 'remove']);

const { locale } = useLocaleFormat();

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

const EYES = ['od', 'oe'];
const FIELDS = ['sphere', 'cylinder', 'axis'];

const dialogRef = ref(null);
// Aviso do "copiar refração": 'empty' (bloco em branco) ou 'unreadable'.
const copyNotice = ref(null);
// Refração copiada ('dynamic' | 'static'): botão marcado até o médico editar um olho.
const copiedFrom = ref(null);
let openerEl = null;

// Esc fecha mesmo com o foco fora do diálogo (listener no document, não no
// div) — mas Esc dado dentro de outro diálogo aberto por cima (assistente de
// IA, aviso de sessão…) é daquele diálogo, não da calculadora.
function onKeydown(event) {
    if (event.key !== 'Escape') return;
    const target = event.target;
    const inOtherDialog =
        target instanceof Element &&
        !dialogRef.value?.contains(target) &&
        target.closest('[role="dialog"], [aria-modal="true"], .modal, .offcanvas');
    if (inOtherDialog) return;
    event.preventDefault();
    close();
}

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

const inputs = reactive({
    vertex_distance_mm: DEFAULT_VERTEX_MM,
    profile: DEFAULT_PROFILE,
    lens_mode: 'auto',
    od: { sphere: null, cylinder: null, axis: null },
    oe: { sphere: null, cylinder: null, axis: null },
});

// Texto que o campo numérico não reconhece (ex.: "-1.5-", vírgula onde o
// navegador não aceita) chega ao v-model como '' — sem isto, viraria "vazio"
// e o cálculo ignoraria o cilindro sem aviso. Só nova digitação limpa a
// marca: se o navegador apagar o texto ao sair do campo, o aviso continua.
// Chaves: 'vertex_distance_mm', 'od.sphere', 'oe.axis'…
const unreadable = reactive(new Set());

function trackUnreadable(key, event) {
    if (event.target?.validity?.badInput) unreadable.add(key);
    else unreadable.delete(key);
    if (key.includes('.')) copiedFrom.value = null;
}

const legacy = computed(() => isLegacyContactLens(props.modelValue));
// Assinado com cálculo v1: mostra o gravado (formato antigo), sem calculadora.
const legacyReadonly = computed(() => props.readonly && legacy.value);
const legacyRows = computed(() => (legacy.value ? contactLensSummary(props.modelValue, props.t, locale.value) : []));

// Abre com o que está vinculado à consulta (ou em branco) e leva o foco
// para dentro do diálogo; ao fechar, devolve o foco a quem abriu.
watch(
    () => props.open,
    async (open) => {
        // SSR: o Vue roda watcher `immediate` no servidor, onde não há document.
        if (typeof document === 'undefined') return;
        document.removeEventListener('keydown', onKeydown);
        if (!open) {
            openerEl?.focus?.();
            openerEl = null;
            return;
        }
        openerEl = document.activeElement;
        document.addEventListener('keydown', onKeydown);
        copyNotice.value = null;
        copiedFrom.value = null;
        unreadable.clear();
        const saved = contactLensInputs(props.modelValue);
        Object.assign(inputs, {
            vertex_distance_mm: saved.vertex_distance_mm,
            profile: saved.profile,
            lens_mode: saved.lens_mode,
        });
        Object.assign(inputs.od, saved.od);
        Object.assign(inputs.oe, saved.oe);
        await nextTick();
        // Foco no título (o leitor de tela anuncia o diálogo; Tab segue para os
        // controles) — focar "Dinâmica" parecia que ela já tinha sido copiada.
        dialogRef.value?.querySelector('#contactLensCalcTitle')?.focus();
    },
    { immediate: true },
);

const calculation = computed(() => computeContactLens(inputs));
const invalidFields = computed(() => new Set([...contactLensInvalid(inputs), ...unreadable]));
const canApply = computed(() => Boolean(calculation.value) && invalidFields.value.size === 0);

// Campo inválido (não reconhecido, fora da faixa ou com mais de 2 casas):
// destacado e anunciado (aria-invalid).
const invalid = (key) => invalidFields.value.has(key);

const fmt = computed(() => ({ locale: locale.value, t: props.t }));

/**
 * Resultado exibido por olho. Assinado (v2): o GRAVADO — o mesmo do resumo e
 * do PDF —, sem recalcular, para uma mudança de regra não alterar o assinado.
 * `state`: empty (sem esférico) · complete (lente completa) · partial (falta
 * cilindro/eixo) · none (fora da linha) — o destaque muda de tom.
 */
const eyeViews = computed(() =>
    Object.fromEntries(
        EYES.map((eye) => {
            const result =
                props.readonly && props.modelValue && !legacy.value
                    ? (props.modelValue.results?.[eye] ?? null)
                    : (calculation.value?.results?.[eye] ?? null);
            const profile = props.readonly && props.modelValue ? props.modelValue.profile : inputs.profile;
            const s = result?.suggested;
            const state = !result
                ? 'empty'
                : !s
                  ? 'none'
                  : result.type === 'toric' && (s.cylinder === null || s.axis === null)
                    ? 'partial'
                    : 'complete';
            return [
                eye,
                {
                    result,
                    state,
                    suggested: !result
                        ? '—'
                        : s
                          ? formatLens(s, result.type, fmt.value)
                          : tt('contact_lens_no_lens_title', 'Sem lente nesta linha'),
                    type: result ? lensTypeLabel(result.type, props.t) : '',
                    theoretical: result ? formatTheoretical(result, fmt.value) : '',
                    vertex: result ? vertexStatus(result, props.t) : '',
                    notes: (result?.notes ?? []).map((code) => contactLensNoteText(code, profile, props.t)),
                },
            ];
        }),
    ),
);

const eyeName = (eye) => (eye === 'od' ? tt('od', 'OD') : tt('oe', 'OE'));
const eyeLong = (eye) =>
    eye === 'od' ? tt('contact_lens_eye_od', 'Olho direito') : tt('contact_lens_eye_oe', 'Olho esquerdo');

const FIELD_META = {
    sphere: { label: 'contact_lens_sphere', fallback: 'Esférico', step: '0.25', min: -40, max: 40, mode: 'decimal' },
    cylinder: {
        label: 'contact_lens_cylinder',
        fallback: 'Cilíndrico',
        step: '0.25',
        min: -15,
        max: 15,
        mode: 'decimal',
    },
    axis: { label: 'contact_lens_axis', fallback: 'Eixo', step: '1', min: 0, max: 180, mode: 'numeric' },
};

const copyNoticeText = computed(() => {
    if (copyNotice.value === 'empty') {
        return tt('contact_lens_copy_empty', 'Essa refração ainda não foi preenchida no prontuário.');
    }
    if (copyNotice.value === 'unreadable') {
        return tt(
            'contact_lens_copy_unreadable',
            'Essa refração tem valor que não é número (ex.: "PL"): digite os valores.',
        );
    }
    return '';
});

/**
 * Copia esférico, cilindro e eixo da refração do prontuário (sem redigitar).
 * Bloco em branco no prontuário não é copiado (não vira "plano" por engano),
 * nem bloco com valor que não é número (ex.: "PL") — o médico digita.
 */
function copyFrom(prefix) {
    const r = refractionFromRecord(props.record, prefix);
    copyNotice.value = !r ? 'empty' : r.unreadable.length ? 'unreadable' : null;
    if (copyNotice.value) return;
    EYES.forEach((eye) => {
        FIELDS.forEach((field) => unreadable.delete(`${eye}.${field}`));
        Object.assign(inputs[eye], r[eye]);
    });
    copiedFrom.value = prefix;
}

function close() {
    emit('close');
}

function apply() {
    if (!canApply.value) return;
    emit('apply', calculation.value);
    close();
}

function remove() {
    emit('remove');
    close();
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            ref="dialogRef"
            class="modal fade show d-block"
            tabindex="-1"
            style="background: rgba(0, 0, 0, 0.5)"
            role="dialog"
            aria-modal="true"
            aria-labelledby="contactLensCalcTitle"
            @click.self="close"
        >
            <div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 id="contactLensCalcTitle" class="modal-title clc-title" tabindex="-1">
                            <i class="ti ti-calculator me-2 text-primary" aria-hidden="true"></i>
                            {{ tt('contact_lens_title', 'Cálculo de lentes de contato') }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close"
                            :aria-label="tt('contact_lens_close', 'Fechar')"
                            @click="close"
                        ></button>
                    </div>
                    <div class="modal-body">
                        <div v-if="readonly" class="alert alert-warning py-2 px-3 mb-3 small" role="status">
                            <i class="ti ti-lock me-1" aria-hidden="true"></i>
                            {{ tt('contact_lens_locked', 'Prontuário assinado: cálculo somente para consulta.') }}
                        </div>

                        <!-- Cálculo da versão 1: mostrado como está (formato antigo). -->
                        <div v-if="legacy && legacyRows.length" class="clc-legacy small mb-3" data-legacy>
                            <div v-if="!readonly" class="mb-1">
                                <i class="ti ti-history me-1" aria-hidden="true"></i>
                                {{
                                    tt(
                                        'contact_lens_legacy_prefill',
                                        'Este prontuário tem um cálculo da versão anterior da calculadora; os valores foram trazidos para cá. Ao usar no prontuário, ele é substituído pelo novo cálculo.',
                                    )
                                }}
                            </div>
                            <div class="fw-semibold">
                                {{ tt('contact_lens_title', 'Cálculo de lentes de contato') }}
                                ({{ tt('contact_lens_legacy', 'versão anterior') }})
                            </div>
                            <ul class="list-unstyled mb-0">
                                <li v-for="row in legacyRows" :key="row.key">
                                    <span class="text-body-secondary">{{ row.label }}:</span> {{ row.value }}
                                </li>
                            </ul>
                        </div>

                        <template v-if="!legacyReadonly">
                            <!-- Copiar a refração já digitada no prontuário (grupo rotulado:
                                 o leitor de tela anuncia "Copiar refração" com os botões). -->
                            <div
                                v-if="!readonly"
                                class="d-flex align-items-center gap-2 flex-wrap mb-2"
                                role="group"
                                aria-labelledby="clc-copy-label"
                            >
                                <span id="clc-copy-label" class="small fw-semibold">{{
                                    tt('contact_lens_copy_from', 'Copiar refração:')
                                }}</span>
                                <div class="btn-group btn-group-sm">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        :class="{ active: copiedFrom === 'dynamic' }"
                                        :aria-pressed="copiedFrom === 'dynamic'"
                                        data-copy="dynamic"
                                        @click="copyFrom('dynamic')"
                                    >
                                        {{ tt('contact_lens_copy_dynamic', 'Dinâmica') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        :class="{ active: copiedFrom === 'static' }"
                                        :aria-pressed="copiedFrom === 'static'"
                                        data-copy="static"
                                        @click="copyFrom('static')"
                                    >
                                        {{ tt('contact_lens_copy_static', 'Estática') }}
                                    </button>
                                </div>
                                <!-- Sempre no DOM: região viva criada já com texto nem sempre é anunciada. -->
                                <span class="small text-body-secondary fst-italic" role="status" data-copy-notice>{{
                                    copyNoticeText
                                }}</span>
                            </div>

                            <fieldset :disabled="readonly">
                                <!-- Opções: linha discreta (o padrão serve à maioria das consultas). -->
                                <div
                                    class="clc-options d-flex flex-wrap align-items-center mb-3"
                                    role="group"
                                    :aria-label="tt('contact_lens_options', 'Opções do cálculo')"
                                    data-options
                                >
                                    <span class="clc-options__pair">
                                        <label for="clc-distance" class="clc-options__label">{{
                                            tt('contact_lens_vertex_distance', 'Distância ao vértice (mm)')
                                        }}</label>
                                        <input
                                            id="clc-distance"
                                            v-model.number="inputs.vertex_distance_mm"
                                            type="number"
                                            step="0.5"
                                            min="5"
                                            max="25"
                                            inputmode="decimal"
                                            class="form-control form-control-sm clc-options__distance"
                                            :class="{ 'is-invalid': invalid('vertex_distance_mm') }"
                                            :aria-invalid="invalid('vertex_distance_mm')"
                                            @input="trackUnreadable('vertex_distance_mm', $event)"
                                        />
                                    </span>
                                    <span class="clc-options__pair">
                                        <label for="clc-mode" class="clc-options__label">{{
                                            tt('contact_lens_mode', 'Tipo de lente')
                                        }}</label>
                                        <select
                                            id="clc-mode"
                                            v-model="inputs.lens_mode"
                                            class="form-select form-select-sm clc-options__select"
                                        >
                                            <option value="auto">
                                                {{ tt('contact_lens_mode_auto', 'Automático') }}
                                            </option>
                                            <option value="spherical">
                                                {{ tt('contact_lens_mode_spherical', 'Esférica') }}
                                            </option>
                                            <option value="toric">{{ tt('contact_lens_mode_toric', 'Tórica') }}</option>
                                        </select>
                                    </span>
                                    <span class="clc-options__pair">
                                        <label for="clc-profile" class="clc-options__label">{{
                                            tt('contact_lens_profile', 'Linha de lentes')
                                        }}</label>
                                        <select
                                            id="clc-profile"
                                            v-model="inputs.profile"
                                            class="form-select form-select-sm clc-options__select"
                                        >
                                            <option value="standard">
                                                {{ tt('contact_lens_profile_standard', 'Padrão de mercado') }}
                                            </option>
                                            <option value="extended">
                                                {{
                                                    tt(
                                                        'contact_lens_profile_extended',
                                                        'Faixa estendida (sob encomenda)',
                                                    )
                                                }}
                                            </option>
                                        </select>
                                    </span>
                                </div>

                                <!-- Um bloco por olho: refração dos óculos → lente sugerida. -->
                                <div class="row g-3">
                                    <div v-for="eye in EYES" :key="eye" class="col-12 col-md-6">
                                        <section
                                            class="clc-eye h-100"
                                            :aria-labelledby="`clc-${eye}-title`"
                                            :data-eye="eye"
                                        >
                                            <div :id="`clc-${eye}-title`" class="clc-eye__title">
                                                {{ eyeName(eye) }}
                                                <span class="clc-eye__long">{{ eyeLong(eye) }}</span>
                                            </div>
                                            <div class="clc-eye__caption">
                                                {{ tt('contact_lens_spectacles', 'Refração dos óculos') }}
                                            </div>
                                            <div class="row g-2">
                                                <div v-for="field in FIELDS" :key="field" class="col-4">
                                                    <label
                                                        :for="`clc-${eye}-${field}`"
                                                        class="form-label clc-eye__label"
                                                        ><span class="visually-hidden">{{ `${eyeName(eye)} ` }}</span
                                                        >{{
                                                            tt(FIELD_META[field].label, FIELD_META[field].fallback)
                                                        }}</label
                                                    >
                                                    <div :class="{ 'input-group input-group-sm': field === 'axis' }">
                                                        <input
                                                            :id="`clc-${eye}-${field}`"
                                                            v-model.number="inputs[eye][field]"
                                                            type="number"
                                                            :step="FIELD_META[field].step"
                                                            :min="FIELD_META[field].min"
                                                            :max="FIELD_META[field].max"
                                                            :inputmode="FIELD_META[field].mode"
                                                            class="form-control form-control-sm"
                                                            :class="{ 'is-invalid': invalid(`${eye}.${field}`) }"
                                                            :aria-invalid="invalid(`${eye}.${field}`)"
                                                            @input="trackUnreadable(`${eye}.${field}`, $event)"
                                                        />
                                                        <span v-if="field === 'axis'" class="input-group-text">°</span>
                                                    </div>
                                                </div>
                                            </div>

                                            <!-- Destaque: a potência prescritível. -->
                                            <div
                                                class="clc-result"
                                                :class="`clc-result--${eyeViews[eye].state}`"
                                                aria-live="polite"
                                                aria-atomic="true"
                                                :data-result="eye"
                                            >
                                                <div class="d-flex align-items-center justify-content-between gap-2">
                                                    <span class="clc-result__label">
                                                        <span class="visually-hidden">{{ `${eyeName(eye)}: ` }}</span>
                                                        {{ tt('contact_lens_suggested', 'Lente de contato sugerida') }}
                                                    </span>
                                                    <span
                                                        v-if="eyeViews[eye].result"
                                                        class="badge rounded-pill clc-result__type"
                                                        :class="`clc-result__type--${eyeViews[eye].result.type}`"
                                                        :data-type="eye"
                                                        >{{ eyeViews[eye].type }}</span
                                                    >
                                                </div>
                                                <div class="clc-result__power" :data-suggested="eye">
                                                    {{ eyeViews[eye].suggested }}
                                                </div>
                                                <div
                                                    v-if="eyeViews[eye].result"
                                                    class="clc-result__theory"
                                                    :data-theoretical="eye"
                                                >
                                                    {{ tt('contact_lens_theoretical', 'Cálculo teórico') }}:
                                                    {{ eyeViews[eye].theoretical }} · {{ eyeViews[eye].vertex }}
                                                </div>
                                                <div v-else class="clc-result__theory">
                                                    {{
                                                        tt(
                                                            'contact_lens_enter_sphere',
                                                            'Digite o esférico da refração.',
                                                        )
                                                    }}
                                                </div>
                                                <ul
                                                    v-if="eyeViews[eye].notes.length"
                                                    class="clc-result__notes"
                                                    :data-notes="eye"
                                                >
                                                    <li v-for="note in eyeViews[eye].notes" :key="note">
                                                        <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i
                                                        >{{ note }}
                                                    </li>
                                                </ul>
                                            </div>
                                        </section>
                                    </div>
                                </div>
                            </fieldset>

                            <div v-if="invalidFields.size" class="text-danger small mt-3" role="alert" data-invalid>
                                <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                                {{
                                    tt(
                                        'contact_lens_invalid',
                                        'Confira os campos destacados: valor não reconhecido, fora da faixa aceita (esférico ±40 D, cilíndrico ±15 D, eixo 0–180 inteiro, vértice 5–25 mm) ou com mais de 2 casas decimais.',
                                    )
                                }}
                            </div>

                            <p class="clc-footnote mt-3 mb-0" data-footnote>
                                <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                                {{
                                    tt(
                                        'contact_lens_footer',
                                        'Sugestão para lente de teste — confirme com sobre-refração e com a tabela do fabricante.',
                                    )
                                }}
                                {{
                                    tt(
                                        'contact_lens_disclaimer',
                                        'Não calcula LIO (lente intraocular): para catarata, use uma calculadora de biometria validada.',
                                    )
                                }}
                            </p>
                        </template>
                    </div>
                    <div class="modal-footer py-2 clc-footer">
                        <button
                            v-if="!readonly && modelValue"
                            type="button"
                            class="btn btn-outline-danger btn-sm me-auto"
                            data-action="remove"
                            @click="remove"
                        >
                            {{ tt('contact_lens_remove', 'Remover do prontuário') }}
                        </button>
                        <button type="button" class="btn btn-secondary btn-sm" @click="close">
                            {{ tt('contact_lens_close', 'Fechar') }}
                        </button>
                        <button
                            v-if="!readonly"
                            type="button"
                            class="btn btn-primary btn-sm clc-apply"
                            data-action="apply"
                            :disabled="!canApply"
                            @click="apply"
                        >
                            <i class="ti ti-check me-1" aria-hidden="true"></i
                            >{{ tt('contact_lens_apply', 'Usar no prontuário') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.clc-title:focus {
    outline: none;
}

.clc-options {
    font-size: 0.8rem;
    color: var(--bs-secondary-color);
    column-gap: 1.1rem;
    row-gap: 0.4rem;
}

/* Rótulo e campo quebram juntos (nunca o rótulo numa linha e o campo na outra). */
.clc-options__pair {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
}

.clc-options__label {
    margin: 0;
    white-space: nowrap;
}

.clc-options__distance {
    width: 4.5rem;
}

.clc-options__pair {
    max-width: 100%;
    min-width: 0;
}

.clc-options__select {
    width: auto;
    min-width: 0;
    max-width: 100%;
}

.clc-eye {
    border: 1px solid var(--bs-border-color);
    border-radius: 0.6rem;
    padding: 0.85rem;
}

.clc-eye__title {
    font-size: 1rem;
    font-weight: 700;
    margin-bottom: 0.35rem;
    color: var(--bs-emphasis-color);
}

.clc-eye__long {
    font-size: 0.78rem;
    font-weight: 400;
    color: var(--bs-secondary-color);
    margin-left: 0.25rem;
}

.clc-eye__caption {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--bs-secondary-color);
    margin-bottom: 0.35rem;
}

.clc-eye__label {
    font-size: 0.78rem;
    margin-bottom: 0.2rem;
}

/* O valor digitado é o dado clínico: legível como texto, não cinza de placeholder. */
.clc-eye .form-control,
.clc-options .form-control,
.clc-options .form-select {
    color: var(--bs-emphasis-color);
}

.clc-result {
    margin-top: 0.85rem;
    border-radius: 0.5rem;
    padding: 0.7rem 0.85rem;
    border: 1px solid var(--bs-border-color);
    background: var(--bs-tertiary-bg);
}

.clc-result--complete {
    background: var(--primary-transparent, var(--bs-primary-bg-subtle));
    border-color: var(--primary, var(--bs-primary));
}

.clc-result--partial,
.clc-result--none {
    background: var(--bs-warning-bg-subtle);
    border-color: var(--bs-warning-border-subtle);
}

.clc-result__label {
    font-size: 0.72rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--bs-secondary-color);
}

.clc-result__type {
    font-size: 0.72rem;
    font-weight: 600;
    background: var(--bs-secondary-bg);
    color: var(--bs-emphasis-color);
    border: 1px solid var(--bs-border-color);
}

.clc-result__type--toric {
    background: var(--primary, var(--bs-primary));
    color: #fff;
    border-color: transparent;
}

.clc-result__power {
    font-size: 1.85rem;
    font-weight: 700;
    line-height: 1.15;
    margin: 0.3rem 0 0.25rem;
    font-variant-numeric: tabular-nums;
    color: var(--bs-emphasis-color);
    overflow-wrap: anywhere;
}

.clc-result--complete .clc-result__power {
    color: var(--primary, var(--bs-primary));
}

.clc-result--empty .clc-result__power {
    color: var(--bs-secondary-color);
}

/* Sem lente completa: menor e no tom de aviso — nunca lido como lente prescritível. */
.clc-result--partial .clc-result__power {
    font-size: 1.35rem;
    color: var(--bs-warning-text-emphasis);
}

.clc-result--none .clc-result__power {
    font-size: 1.15rem;
    color: var(--bs-warning-text-emphasis);
}

.clc-result__theory {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}

.clc-result__notes {
    list-style: none;
    margin: 0.4rem 0 0;
    padding: 0;
    font-size: 0.78rem;
    color: var(--bs-warning-text-emphasis);
}

.clc-legacy {
    border: 1px dashed var(--bs-border-color);
    border-radius: 0.5rem;
    padding: 0.6rem 0.75rem;
    background: var(--bs-tertiary-bg);
}

.clc-footnote {
    font-size: 0.75rem;
    color: var(--bs-secondary-color);
}

/* Tema escuro: o índigo do tema some no fundo escuro — clareia o destaque. */
[data-bs-theme='dark'] .clc-result--complete {
    border-color: #6870d6;
}

[data-bs-theme='dark'] .clc-result--complete .clc-result__power {
    color: #c7cbff;
}

[data-bs-theme='dark'] .clc-result__type--toric {
    background: #4a53c8;
}

@media (max-width: 575.98px) {
    .clc-result--complete .clc-result__power {
        font-size: 1.6rem;
    }

    /* Celular: "Usar no prontuário" primeiro e largo; a coluna da direita fica
       livre para o botão flutuante do assistente (fixo no canto da tela). */
    .clc-footer {
        justify-content: flex-start;
        padding-right: 5.25rem;
    }

    .clc-apply {
        order: -1;
        flex: 1 1 100%;
    }
}
</style>
