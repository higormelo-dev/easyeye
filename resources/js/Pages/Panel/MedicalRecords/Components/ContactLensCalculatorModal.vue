<script setup>
import { reactive, computed, watch, ref, nextTick, onBeforeUnmount } from 'vue';
import {
    DEFAULT_VERTEX_MM,
    computeContactLens,
    contactLensOutOfRange,
    vertexConvert,
    sphericalEquivalent,
    refractionFromRecord,
    toNumber,
} from './contactLens.js';

/**
 * Cálculo de lentes de contato DENTRO do prontuário (saiu do Gerenciador de
 * Imagens): o médico calcula sem sair da consulta e "Usar no prontuário"
 * leva o resultado ao formulário — gravado ao salvar a consulta (o servidor
 * recalcula com as mesmas fórmulas; nunca confia no resultado do navegador).
 *
 * Mesmas fórmulas de óptica de antes (contactLens.js):
 *   1. Conversão de distância ao vértice (óculos → lente de contato)
 *   2. Equivalente esférico
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
    // Formulário do prontuário — fonte do "copiar da refração".
    record: { type: Object, default: () => ({}) },
    // Prontuário assinado: só consulta.
    readonly: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'apply', 'remove']);

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

const dialogRef = ref(null);
// Refração que o médico tentou copiar mas está em branco no prontuário.
const copyEmpty = ref(null);
let openerEl = null;

// Esc fecha mesmo com o foco fora do diálogo (listener no document, não no div).
function onKeydown(event) {
    if (event.key !== 'Escape') return;
    event.preventDefault();
    close();
}

onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));

const inputs = reactive({
    vertex_distance_mm: DEFAULT_VERTEX_MM,
    vertex_od: null,
    vertex_oe: null,
    se_od_sphere: null,
    se_od_cylinder: null,
    se_oe_sphere: null,
    se_oe_cylinder: null,
});

// Abre com o que está vinculado à consulta (ou em branco) e leva o foco
// para dentro do diálogo; ao fechar, devolve o foco a quem abriu.
watch(
    () => props.open,
    async (open) => {
        document.removeEventListener('keydown', onKeydown);
        if (!open) {
            openerEl?.focus?.();
            openerEl = null;
            return;
        }
        openerEl = document.activeElement;
        document.addEventListener('keydown', onKeydown);
        copyEmpty.value = null;
        const saved = props.modelValue ?? {};
        Object.assign(inputs, {
            vertex_distance_mm: saved.vertex_distance_mm ?? DEFAULT_VERTEX_MM,
            vertex_od: saved.vertex_od ?? null,
            vertex_oe: saved.vertex_oe ?? null,
            se_od_sphere: saved.se_od_sphere ?? null,
            se_od_cylinder: saved.se_od_cylinder ?? null,
            se_oe_sphere: saved.se_oe_sphere ?? null,
            se_oe_cylinder: saved.se_oe_cylinder ?? null,
        });
        await nextTick();
        dialogRef.value?.querySelector('input:not(:disabled), button')?.focus();
    },
    { immediate: true },
);

// Assinado: mostra o resultado GRAVADO (o mesmo do resumo e do PDF), sem
// recalcular — se a fórmula mudar de versão, o assinado não muda na tela.
const result = (key, compute) =>
    computed(() => (props.readonly && props.modelValue ? toNumber(props.modelValue[key]) : compute()));

const vertexResultOd = result('vertex_od_result', () => vertexConvert(inputs.vertex_od, inputs.vertex_distance_mm));
const vertexResultOe = result('vertex_oe_result', () => vertexConvert(inputs.vertex_oe, inputs.vertex_distance_mm));
const seResultOd = result('se_od_result', () => sphericalEquivalent(inputs.se_od_sphere, inputs.se_od_cylinder));
const seResultOe = result('se_oe_result', () => sphericalEquivalent(inputs.se_oe_sphere, inputs.se_oe_cylinder));

const calculation = computed(() => computeContactLens(inputs));
const outOfRange = computed(() => new Set(contactLensOutOfRange(inputs)));
const canApply = computed(() => Boolean(calculation.value) && outOfRange.value.size === 0);

// Campo fora da faixa: destacado e anunciado (aria-invalid).
const invalid = (key) => outOfRange.value.has(key);

// Mesma exibição da calculadora antiga (2 casas; vazio = "—").
const show = (v) => (v !== null ? v.toFixed(2) : '—');

/**
 * Copia esférico/cilindro da refração do prontuário (sem redigitar). Bloco
 * em branco no prontuário não é copiado (não vira "plano" por engano).
 */
function copyFrom(prefix) {
    const r = refractionFromRecord(props.record, prefix);
    copyEmpty.value = r ? null : prefix;
    if (!r) return;
    Object.assign(inputs, {
        vertex_od: r.od.sphere,
        vertex_oe: r.oe.sphere,
        se_od_sphere: r.od.sphere,
        se_od_cylinder: r.od.cylinder,
        se_oe_sphere: r.oe.sphere,
        se_oe_cylinder: r.oe.cylinder,
    });
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
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 id="contactLensCalcTitle" class="modal-title">
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
                        <div class="alert alert-secondary py-2 px-3 mb-3" style="font-size: 0.78rem">
                            <i class="ti ti-info-circle me-1" aria-hidden="true"></i>
                            {{
                                tt(
                                    'contact_lens_disclaimer',
                                    'Fórmulas de óptica de referência (vértice e equivalente esférico) — sempre confira o resultado antes de usar. Não inclui cálculo de LIO (lente intraocular): use uma calculadora de biometria dedicada e validada para cirurgia de catarata.',
                                )
                            }}
                        </div>

                        <div v-if="readonly" class="alert alert-warning py-2 px-3 mb-3 small" role="status">
                            <i class="ti ti-lock me-1" aria-hidden="true"></i>
                            {{ tt('contact_lens_locked', 'Prontuário assinado: cálculo somente para consulta.') }}
                        </div>

                        <!-- Copiar da refração já digitada no prontuário -->
                        <div v-else class="d-flex align-items-center gap-2 flex-wrap mb-3">
                            <span class="small text-muted">{{
                                tt('contact_lens_copy_from', 'Copiar da refração:')
                            }}</span>
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                data-copy="dynamic"
                                @click="copyFrom('dynamic')"
                            >
                                {{ tt('contact_lens_copy_dynamic', 'Dinâmica') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-secondary btn-sm"
                                data-copy="static"
                                @click="copyFrom('static')"
                            >
                                {{ tt('contact_lens_copy_static', 'Estática') }}
                            </button>
                            <span v-if="copyEmpty" class="small text-muted fst-italic" role="status" data-copy-empty>
                                {{
                                    tt(
                                        'contact_lens_copy_empty',
                                        'Essa refração ainda não foi preenchida no prontuário.',
                                    )
                                }}
                            </span>
                        </div>

                        <fieldset :disabled="readonly">
                            <!-- Distância ao vértice -->
                            <h6 class="fw-semibold mb-2">
                                {{ tt('contact_lens_vertex_title', 'Conversão de distância ao vértice') }}
                            </h6>
                            <p class="text-muted small mb-2">
                                {{
                                    tt(
                                        'contact_lens_vertex_hint',
                                        'Converte o esférico do óculos para a potência equivalente em lente de contato (vértice zero). O cilindro não entra nesta conversão.',
                                    )
                                }}
                            </p>
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-12 col-sm-4">
                                    <label for="clc-distance" class="form-label small mb-1">{{
                                        tt('contact_lens_vertex_distance', 'Distância ao vértice (mm)')
                                    }}</label>
                                    <input
                                        id="clc-distance"
                                        v-model.number="inputs.vertex_distance_mm"
                                        type="number"
                                        step="0.5"
                                        min="5"
                                        max="25"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': invalid('vertex_distance_mm') }"
                                        :aria-invalid="invalid('vertex_distance_mm')"
                                    />
                                </div>
                                <div class="col-6 col-sm-4">
                                    <label for="clc-vertex-od" class="form-label small mb-1">{{
                                        tt('contact_lens_sphere_od', 'Esférico OD (D)')
                                    }}</label>
                                    <input
                                        id="clc-vertex-od"
                                        v-model.number="inputs.vertex_od"
                                        type="number"
                                        step="0.25"
                                        min="-40"
                                        max="40"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': invalid('vertex_od') }"
                                        :aria-invalid="invalid('vertex_od')"
                                        placeholder="-6.00"
                                    />
                                </div>
                                <div class="col-6 col-sm-4">
                                    <label for="clc-vertex-oe" class="form-label small mb-1">{{
                                        tt('contact_lens_sphere_oe', 'Esférico OE (D)')
                                    }}</label>
                                    <input
                                        id="clc-vertex-oe"
                                        v-model.number="inputs.vertex_oe"
                                        type="number"
                                        step="0.25"
                                        min="-40"
                                        max="40"
                                        class="form-control form-control-sm"
                                        :class="{ 'is-invalid': invalid('vertex_oe') }"
                                        :aria-invalid="invalid('vertex_oe')"
                                        placeholder="-6.00"
                                    />
                                </div>
                            </div>
                            <div class="row g-2 mb-4" aria-live="polite">
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="text-muted small">
                                            {{ tt('od', 'OD') }} → {{ tt('contact_lens_result', 'Lente de contato') }}
                                        </div>
                                        <div class="fs-5 fw-bold" data-result="vertex-od">
                                            {{ show(vertexResultOd) }}
                                        </div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="text-muted small">
                                            {{ tt('oe', 'OE') }} → {{ tt('contact_lens_result', 'Lente de contato') }}
                                        </div>
                                        <div class="fs-5 fw-bold" data-result="vertex-oe">
                                            {{ show(vertexResultOe) }}
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <hr />

                            <!-- Equivalente esférico -->
                            <h6 class="fw-semibold mb-2">{{ tt('contact_lens_se_title', 'Equivalente esférico') }}</h6>
                            <p class="text-muted small mb-2">
                                {{ tt('contact_lens_se_hint', 'SE = Esférico + Cilindro / 2.') }}
                            </p>
                            <div class="row g-2 mb-2">
                                <div class="col-12 col-sm-6">
                                    <span class="form-label small mb-1 fw-semibold d-block">{{ tt('od', 'OD') }}</span>
                                    <div class="row g-1">
                                        <div class="col-6">
                                            <label for="clc-se-od-sph" class="visually-hidden"
                                                >{{ tt('od', 'OD') }} {{ tt('contact_lens_sphere', 'Esférico') }}</label
                                            >
                                            <input
                                                id="clc-se-od-sph"
                                                v-model.number="inputs.se_od_sphere"
                                                type="number"
                                                step="0.25"
                                                min="-40"
                                                max="40"
                                                class="form-control form-control-sm"
                                                :class="{ 'is-invalid': invalid('se_od_sphere') }"
                                                :aria-invalid="invalid('se_od_sphere')"
                                                :placeholder="tt('contact_lens_sphere', 'Esférico')"
                                            />
                                        </div>
                                        <div class="col-6">
                                            <label for="clc-se-od-cyl" class="visually-hidden"
                                                >{{ tt('od', 'OD') }}
                                                {{ tt('contact_lens_cylinder', 'Cilindro') }}</label
                                            >
                                            <input
                                                id="clc-se-od-cyl"
                                                v-model.number="inputs.se_od_cylinder"
                                                type="number"
                                                step="0.25"
                                                min="-15"
                                                max="15"
                                                class="form-control form-control-sm"
                                                :class="{ 'is-invalid': invalid('se_od_cylinder') }"
                                                :aria-invalid="invalid('se_od_cylinder')"
                                                :placeholder="tt('contact_lens_cylinder', 'Cilindro')"
                                            />
                                        </div>
                                    </div>
                                </div>
                                <div class="col-12 col-sm-6">
                                    <span class="form-label small mb-1 fw-semibold d-block">{{ tt('oe', 'OE') }}</span>
                                    <div class="row g-1">
                                        <div class="col-6">
                                            <label for="clc-se-oe-sph" class="visually-hidden"
                                                >{{ tt('oe', 'OE') }} {{ tt('contact_lens_sphere', 'Esférico') }}</label
                                            >
                                            <input
                                                id="clc-se-oe-sph"
                                                v-model.number="inputs.se_oe_sphere"
                                                type="number"
                                                step="0.25"
                                                min="-40"
                                                max="40"
                                                class="form-control form-control-sm"
                                                :class="{ 'is-invalid': invalid('se_oe_sphere') }"
                                                :aria-invalid="invalid('se_oe_sphere')"
                                                :placeholder="tt('contact_lens_sphere', 'Esférico')"
                                            />
                                        </div>
                                        <div class="col-6">
                                            <label for="clc-se-oe-cyl" class="visually-hidden"
                                                >{{ tt('oe', 'OE') }}
                                                {{ tt('contact_lens_cylinder', 'Cilindro') }}</label
                                            >
                                            <input
                                                id="clc-se-oe-cyl"
                                                v-model.number="inputs.se_oe_cylinder"
                                                type="number"
                                                step="0.25"
                                                min="-15"
                                                max="15"
                                                class="form-control form-control-sm"
                                                :class="{ 'is-invalid': invalid('se_oe_cylinder') }"
                                                :aria-invalid="invalid('se_oe_cylinder')"
                                                :placeholder="tt('contact_lens_cylinder', 'Cilindro')"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="row g-2" aria-live="polite">
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="text-muted small">
                                            {{ tt('od', 'OD') }} → {{ tt('contact_lens_se_short', 'SE') }}
                                        </div>
                                        <div class="fs-5 fw-bold" data-result="se-od">{{ show(seResultOd) }}</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="border rounded p-2 text-center">
                                        <div class="text-muted small">
                                            {{ tt('oe', 'OE') }} → {{ tt('contact_lens_se_short', 'SE') }}
                                        </div>
                                        <div class="fs-5 fw-bold" data-result="se-oe">{{ show(seResultOe) }}</div>
                                    </div>
                                </div>
                            </div>
                        </fieldset>

                        <div v-if="outOfRange.size" class="text-danger small mt-3" role="alert" data-out-of-range>
                            <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                            {{
                                tt(
                                    'contact_lens_out_of_range',
                                    'Valor fora da faixa aceita (esférico ±40 D, cilindro ±15 D, vértice 5–25 mm) — confira os campos destacados.',
                                )
                            }}
                        </div>

                        <p v-if="!readonly" class="text-muted small mt-3 mb-0">
                            <i class="ti ti-device-floppy me-1" aria-hidden="true"></i>
                            {{
                                tt(
                                    'contact_lens_save_hint',
                                    'Ao usar no prontuário, o resultado fica vinculado a esta consulta quando você salvar.',
                                )
                            }}
                        </p>
                    </div>
                    <div class="modal-footer py-2">
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
                            class="btn btn-primary btn-sm"
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
