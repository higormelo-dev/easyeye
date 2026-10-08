<script setup>
import { computed, ref } from 'vue';
import { clinicalTexts, tc, fill } from '@/Support/clinicalSafety.js';

/**
 * Alerta FIXO de segurança clínica (Fase 2 / A1-A2) — cabeçalho do prontuário,
 * drawer da Agenda/Paciente e receituário.
 *
 *  - Alergia ativa: faixa vermelha com ícone + "ALERGIA:" + substância,
 *    reação, gravidade e situação (texto, não só cor).
 *  - "Nega alergias" (quem/quando) ou "Sem registro de alergias" quando
 *    ninguém perguntou — nunca em branco.
 *  - Alertas de risco ocular das medicações em uso (com a fonte, para o
 *    médico validar); sem alerta, nada.
 */
const props = defineProps({
    summary: { type: Object, default: null },
    showRisks: { type: Boolean, default: true },
    compact: { type: Boolean, default: false },
    // Botão "Revisar alergias e medicações" (abre o bloco de edição).
    reviewable: { type: Boolean, default: false },
    t: { type: Object, default: null },
});

defineEmits(['review']);

const texts = clinicalTexts(props.t);
const tt = (path, fallback) => tc(texts, path, fallback);

const state = computed(() => props.summary?.allergy_state ?? 'unknown');
const activeAllergies = computed(() =>
    (props.summary?.allergies ?? []).filter((a) => a.active !== false && a.status !== 'discarded'),
);
const risks = computed(() => (props.showRisks ? (props.summary?.risk_alerts ?? []) : []));
const openSources = ref(false);

function allergyDetail(a) {
    return [a.reaction, a.severity_label, a.status === 'reported' ? tt('banner.unconfirmed', 'não confirmada') : null]
        .filter(Boolean)
        .join(' · ');
}

const deniedText = computed(() => {
    const denied = props.summary?.denied;
    if (!denied?.by && !denied?.at_label) return tt('banner.denied', 'Nega alergias');
    return fill(tt('banner.denied_detail', 'Nega alergias — registrado por :name em :date'), {
        name: denied.by ?? '—',
        date: denied.at_label ?? '',
    });
});
</script>

<template>
    <section
        v-if="summary"
        class="cs-banner"
        :class="{ 'cs-banner--compact': compact }"
        :aria-label="tt('banner.label', 'Segurança clínica do paciente')"
        data-testid="clinical-alert-banner"
    >
        <div class="d-flex align-items-start gap-2 flex-wrap">
            <div class="flex-grow-1 min-w-0">
                <!-- Alergias -->
                <div v-if="state === 'has'" class="cs-banner__allergy" role="alert" data-testid="cs-allergy-alert">
                    <i class="ti ti-alert-triangle cs-banner__icon" aria-hidden="true"></i>
                    <strong class="cs-banner__label">{{ tt('banner.allergy_alert', 'ALERGIA') }}:</strong>
                    <span v-for="(a, i) in activeAllergies" :key="a.id" class="cs-banner__item">
                        <strong>{{ a.substance }}</strong>
                        <span v-if="allergyDetail(a)" class="cs-banner__detail"> ({{ allergyDetail(a) }})</span
                        ><span v-if="i < activeAllergies.length - 1" aria-hidden="true">;</span>
                    </span>
                </div>
                <div v-else-if="state === 'denied'" class="cs-banner__neutral" data-testid="cs-allergy-denied">
                    <i class="ti ti-circle-check" aria-hidden="true"></i>
                    <span>{{ deniedText }}</span>
                </div>
                <div v-else class="cs-banner__unknown" role="status" data-testid="cs-allergy-unknown">
                    <i class="ti ti-help-circle" aria-hidden="true"></i>
                    <strong>{{ tt('banner.no_record', 'Sem registro de alergias') }}</strong>
                    <span v-if="!compact" class="cs-banner__hint">
                        —
                        {{
                            tt('banner.no_record_hint', 'Ninguém perguntou ainda — pergunte ao paciente e registre.')
                        }}</span
                    >
                </div>

                <!-- Alertas de risco ocular (medicações em uso) -->
                <div v-if="risks.length" class="cs-banner__risks" role="note" data-testid="cs-risk-alerts">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <strong
                            ><i class="ti ti-pill me-1" aria-hidden="true"></i
                            >{{ tt('banner.risks', 'Atenção às medicações em uso') }}:</strong
                        >
                        <button
                            type="button"
                            class="btn btn-link btn-sm p-0 cs-banner__sources-toggle"
                            :aria-expanded="openSources ? 'true' : 'false'"
                            @click="openSources = !openSources"
                        >
                            {{
                                openSources
                                    ? tt('banner.hide_sources', 'Ocultar fontes')
                                    : tt('banner.show_sources', 'Ver fontes')
                            }}
                        </button>
                    </div>
                    <ul class="mb-0 ps-3">
                        <li v-for="risk in risks" :key="risk.key">
                            <strong>{{ risk.group }}</strong>
                            <span class="cs-banner__meds"> ({{ risk.medications.join(', ') }})</span>
                            — {{ risk.alert }}
                            <em v-if="risk.origin === 'free_text'" class="cs-banner__origin">
                                [{{ tt('banner.free_text', 'citado no texto livre') }}]</em
                            >
                            <small v-if="openSources" class="d-block cs-banner__source">
                                {{ tt('banner.source', 'Fonte') }}: {{ risk.source }}
                            </small>
                        </li>
                    </ul>
                </div>
            </div>

            <button
                v-if="reviewable"
                type="button"
                class="btn btn-sm btn-outline-secondary cs-banner__review flex-shrink-0"
                data-testid="cs-review"
                @click="$emit('review')"
            >
                <i class="ti ti-clipboard-heart me-1" aria-hidden="true"></i
                >{{ tt('banner.review', 'Revisar alergias e medicações') }}
            </button>
        </div>
    </section>
</template>

<style scoped>
.cs-banner {
    border-radius: 0.5rem;
    font-size: 0.85rem;
    line-height: 1.35;
}
.cs-banner__allergy {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.25rem 0.4rem;
    padding: 0.45rem 0.7rem;
    border: 2px solid var(--bs-danger, #dc3545);
    border-left-width: 6px;
    border-radius: 0.5rem;
    background: var(--bs-danger-bg-subtle, #f8d7da);
    color: var(--bs-danger-text-emphasis, #58151c);
}
.cs-banner__label {
    letter-spacing: 0.02em;
}
.cs-banner__icon {
    font-size: 1.1rem;
    align-self: center;
}
.cs-banner__neutral,
.cs-banner__unknown {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
    padding: 0.35rem 0.7rem;
    border-radius: 0.5rem;
    border: 1px dashed var(--bs-border-color, #dee2e6);
    background: var(--bs-tertiary-bg, #f8f9fa);
    color: var(--bs-body-color);
}
.cs-banner__unknown {
    border-color: var(--bs-warning-border-subtle, #ffe69c);
    background: var(--bs-warning-bg-subtle, #fff3cd);
    color: var(--bs-warning-text-emphasis, #664d03);
}
.cs-banner__hint {
    font-size: 0.78rem;
}
.cs-banner__risks {
    margin-top: 0.4rem;
    padding: 0.45rem 0.7rem;
    border: 1px solid var(--bs-warning-border-subtle, #ffe69c);
    border-left-width: 6px;
    border-radius: 0.5rem;
    background: var(--bs-warning-bg-subtle, #fff3cd);
    color: var(--bs-warning-text-emphasis, #664d03);
}
.cs-banner__source,
.cs-banner__origin {
    opacity: 0.9;
}
.cs-banner__sources-toggle {
    font-size: 0.78rem;
    color: inherit;
    text-decoration: underline;
}
.cs-banner--compact {
    font-size: 0.8rem;
}
.cs-banner--compact .cs-banner__allergy,
.cs-banner--compact .cs-banner__unknown,
.cs-banner--compact .cs-banner__neutral {
    padding: 0.3rem 0.55rem;
}
@media (max-width: 575.98px) {
    .cs-banner__review {
        width: 100%;
    }
}
</style>
