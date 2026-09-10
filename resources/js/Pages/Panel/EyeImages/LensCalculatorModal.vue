<script setup>
import { reactive, computed } from 'vue';

/**
 * Calculadora de lentes (benchmark 09/09/2026 — concorrente tem "Lens
 * Calculator" no menu de contexto). 100% client-side, sem backend/persistência
 * — é uma calculadora de óptica, não gera documento nem grava nada.
 *
 * Escopo DELIBERADAMENTE restrito a fórmulas de óptica determinísticas e
 * consensuais (livro-texto, sem espaço pra "qual fórmula é a certa"):
 *   1. Conversão de distância ao vértice (óculos → lente de contato)
 *   2. Equivalente esférico
 *
 * FORA DE ESCOPO DE PROPÓSITO: cálculo de potência de LIO (lente
 * intraocular — SRK/T, Holladay, Barrett...). Diferente das duas fórmulas
 * acima, IOL power depende de biometria (comprimento axial, K, ACD) e tem
 * disputa clínica real entre fórmulas — embutir uma sozinha aqui e deixar
 * parecer "a conta oficial" é risco real de segurança do paciente (poder
 * errado de LIO implantado = comprometimento visual permanente). Se
 * precisar, use uma calculadora de IOL validada e dedicada (ex.: Barrett
 * Universal II), nunca este utilitário.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    t:    { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close']);

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

function close() {
    emit('close');
}

// ── Conversão de distância ao vértice ───────────────────────────────────
// F_cl = F_sp / (1 - d·F_sp), d em metros. 12mm é o padrão de referência
// pra armação (ajustável — alguns óculos medem diferente).
const vertex = reactive({
    distanceMm: 12,
    od: null,
    oe: null,
});

// Campo vazio (null/'') é "nada digitado ainda" (mostra "—") — DIFERENTE de
// esférico = 0 digitado de propósito (plano, valor clínico real e válido).
// Number(null) === 0 em JS, então a checagem tem que vir ANTES da conversão,
// senão os dois casos ficam indistinguíveis e um campo vazio mostraria "0.00".
function isBlank(v) {
    return v === null || v === undefined || v === '';
}

function vertexConvert(sphere) {
    if (isBlank(sphere)) return null;
    const s = Number(sphere);
    if (!Number.isFinite(s)) return null;
    if (s === 0) return 0;
    const d = (Number(vertex.distanceMm) || 12) / 1000;
    const denom = 1 - d * s;
    if (denom === 0) return null;
    return Math.round((s / denom) * 100) / 100;
}

const vertexResultOd = computed(() => vertexConvert(vertex.od));
const vertexResultOe = computed(() => vertexConvert(vertex.oe));

// ── Equivalente esférico ────────────────────────────────────────────────
// SE = Esférico + Cilindro/2 — convenção padrão, independe de notação
// (plus ou minus cylinder já entra com o sinal certo no campo).
const se = reactive({
    odSphere: null, odCylinder: null,
    oeSphere: null, oeCylinder: null,
});

function sphericalEquivalent(sphere, cylinder) {
    if (isBlank(sphere)) return null;
    const s = Number(sphere);
    if (!Number.isFinite(s)) return null;
    const cyl = isBlank(cylinder) ? 0 : Number(cylinder);
    if (!Number.isFinite(cyl)) return null;
    return Math.round((s + cyl / 2) * 100) / 100;
}

const seResultOd = computed(() => sphericalEquivalent(se.odSphere, se.odCylinder));
const seResultOe = computed(() => sphericalEquivalent(se.oeSphere, se.oeCylinder));
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.5);"
             role="dialog" aria-modal="true" aria-labelledby="eyeLensCalcModalTitle"
             @click.self="close" @keydown.escape.window="close">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 id="eyeLensCalcModalTitle" class="modal-title">
                            <i class="ti ti-calculator me-2 text-primary"></i>
                            {{ tt('lens_calc_title', 'Calculadora de lentes') }}
                        </h6>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-secondary py-2 px-3 mb-3" style="font-size:.78rem;">
                            <i class="ti ti-info-circle me-1"></i>
                            {{ tt('lens_calc_disclaimer', 'Fórmulas de óptica de referência (vértice e equivalente esférico) — sempre confira o resultado antes de usar. Não inclui cálculo de LIO (lente intraocular): use uma calculadora de biometria dedicada e validada para cirurgia de catarata.') }}
                        </div>

                        <!-- Distância ao vértice -->
                        <h6 class="fw-semibold mb-2">{{ tt('lens_calc_vertex_title', 'Conversão de distância ao vértice') }}</h6>
                        <p class="text-muted small mb-2">
                            {{ tt('lens_calc_vertex_hint', 'Converte a graduação do óculos para a potência equivalente em lente de contato (vértice zero).') }}
                        </p>
                        <div class="row g-2 align-items-end mb-3">
                            <div class="col-12 col-sm-4">
                                <label class="form-label small mb-1">{{ tt('lens_calc_vertex_distance', 'Distância ao vértice (mm)') }}</label>
                                <input v-model.number="vertex.distanceMm" type="number" step="0.5" class="form-control form-control-sm">
                            </div>
                            <div class="col-6 col-sm-4">
                                <label class="form-label small mb-1">{{ tt('lens_calc_sphere_od', 'Esférico OD (D)') }}</label>
                                <input v-model.number="vertex.od" type="number" step="0.25" class="form-control form-control-sm" placeholder="ex.: -6.00">
                            </div>
                            <div class="col-6 col-sm-4">
                                <label class="form-label small mb-1">{{ tt('lens_calc_sphere_oe', 'Esférico OE (D)') }}</label>
                                <input v-model.number="vertex.oe" type="number" step="0.25" class="form-control form-control-sm" placeholder="ex.: -6.00">
                            </div>
                        </div>
                        <div class="row g-2 mb-4">
                            <div class="col-6">
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small">OD → {{ tt('lens_calc_result', 'Lente de contato') }}</div>
                                    <div class="fs-5 fw-bold">{{ vertexResultOd !== null ? vertexResultOd.toFixed(2) : '—' }}</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small">OE → {{ tt('lens_calc_result', 'Lente de contato') }}</div>
                                    <div class="fs-5 fw-bold">{{ vertexResultOe !== null ? vertexResultOe.toFixed(2) : '—' }}</div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <!-- Equivalente esférico -->
                        <h6 class="fw-semibold mb-2">{{ tt('lens_calc_se_title', 'Equivalente esférico') }}</h6>
                        <p class="text-muted small mb-2">
                            {{ tt('lens_calc_se_hint', 'SE = Esférico + Cilindro / 2.') }}
                        </p>
                        <div class="row g-2 mb-2">
                            <div class="col-12 col-sm-6">
                                <label class="form-label small mb-1 fw-semibold">OD</label>
                                <div class="row g-1">
                                    <div class="col-6">
                                        <input v-model.number="se.odSphere" type="number" step="0.25" class="form-control form-control-sm" placeholder="Esférico">
                                    </div>
                                    <div class="col-6">
                                        <input v-model.number="se.odCylinder" type="number" step="0.25" class="form-control form-control-sm" placeholder="Cilindro">
                                    </div>
                                </div>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label small mb-1 fw-semibold">OE</label>
                                <div class="row g-1">
                                    <div class="col-6">
                                        <input v-model.number="se.oeSphere" type="number" step="0.25" class="form-control form-control-sm" placeholder="Esférico">
                                    </div>
                                    <div class="col-6">
                                        <input v-model.number="se.oeCylinder" type="number" step="0.25" class="form-control form-control-sm" placeholder="Cilindro">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small">OD → SE</div>
                                    <div class="fs-5 fw-bold">{{ seResultOd !== null ? seResultOd.toFixed(2) : '—' }}</div>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="border rounded p-2 text-center">
                                    <div class="text-muted small">OE → SE</div>
                                    <div class="fs-5 fw-bold">{{ seResultOe !== null ? seResultOe.toFixed(2) : '—' }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary btn-sm" @click="close">
                            {{ tt('close', 'Fechar') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
