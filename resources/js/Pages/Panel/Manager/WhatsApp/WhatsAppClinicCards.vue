<script setup>
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { automationText, fill, sendingBadge, sentAnswered } from './clinicStatus.js';

/**
 * Clínicas do WhatsApp em cards — mesmo layout de Manager → Medicamentos.
 * Usa a mesma página/filtros da tabela (paginação server-side do Inertia).
 */
defineProps({
    clinics: { type: Object, required: true },
    hasFilters: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

defineEmits(['view', 'configure', 'test']);

const { number } = useLocaleFormat();
</script>

<template>
    <div v-if="clinics.data.length === 0" class="text-center text-muted py-5" data-test="wa-empty">
        <i class="ti ti-brand-whatsapp fs-1 mb-2 d-block" aria-hidden="true"></i>
        <p>{{ hasFilters ? t.ui.empty_filtered : t.ui.empty }}</p>
    </div>

    <div v-else class="row g-3" data-test="wa-clinic-cards">
        <div v-for="clinic in clinics.data" :key="clinic.id" class="col-sm-6 col-xl-4">
            <div class="card card-body h-100 mb-0" :data-test="`wa-card-${clinic.id}`">
                <div class="d-flex align-items-start gap-3">
                    <div
                        class="wa-card__avatar rounded-circle d-flex align-items-center justify-content-center flex-shrink-0"
                        :class="`wa-card__avatar--${clinic.sending}`"
                        aria-hidden="true"
                    >
                        <i :class="`${sendingBadge(clinic, t.ui).icon} fs-18`"></i>
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <h6 class="mb-0 fw-semibold lh-sm text-break">{{ clinic.name }}</h6>
                        <div v-if="clinic.code" class="text-muted small">{{ clinic.code }}</div>
                        <span
                            class="badge rounded fs-12 fw-medium mt-1"
                            :class="sendingBadge(clinic, t.ui).cls"
                            :title="sendingBadge(clinic, t.ui).hint"
                            :data-sending="clinic.sending"
                            >{{ sendingBadge(clinic, t.ui).label }}</span
                        >
                        <span class="visually-hidden">{{ sendingBadge(clinic, t.ui).hint }}</span>
                    </div>
                </div>

                <dl class="wa-card__facts small mt-3 mb-2">
                    <dt>{{ t.ui.col_confirmation }}</dt>
                    <dd :class="automationText(clinic, 'confirmation', t.ui) ? 'text-success' : 'text-muted'">
                        {{ automationText(clinic, 'confirmation', t.ui) ?? t.ui.off }}
                    </dd>
                    <dt>{{ t.ui.col_survey }}</dt>
                    <dd :class="automationText(clinic, 'survey', t.ui) ? 'text-success' : 'text-muted'">
                        {{ automationText(clinic, 'survey', t.ui) ?? t.ui.off }}
                    </dd>
                    <template v-if="sentAnswered(clinic)">
                        <dt>{{ t.ui.col_sent }}</dt>
                        <dd>
                            {{
                                fill(t.ui.sent_answered_sr, {
                                    sent: number(sentAnswered(clinic).sent),
                                    answered: number(sentAnswered(clinic).answered),
                                })
                            }}
                        </dd>
                    </template>
                    <template v-if="clinic.setting?.opt_outs">
                        <dt>{{ t.ui.col_opt_outs }}</dt>
                        <dd>{{ number(clinic.setting.opt_outs) }}</dd>
                    </template>
                </dl>

                <hr class="my-2 mt-auto" />

                <ActionIconGroup align="end" gap="tight">
                    <ActionIconButton
                        icon="ti ti-eye"
                        :title="t.ui.action_view"
                        :data-test="`card-view-${clinic.id}`"
                        @click="$emit('view', clinic)"
                    />
                    <ActionIconButton
                        v-if="clinic.setting?.has_app"
                        icon="ti ti-plug"
                        :title="t.ui.test_own_app"
                        :data-test="`card-test-${clinic.id}`"
                        @click="$emit('test', clinic)"
                    />
                    <ActionIconButton
                        icon="ti ti-settings"
                        :title="t.manager.configure"
                        :data-test="`card-configure-${clinic.id}`"
                        @click="$emit('configure', clinic)"
                    />
                </ActionIconGroup>
            </div>
        </div>
    </div>

    <TablePagination
        class="mt-3"
        :data="clinics"
        :showing-from="t.ui.showing_from"
        :showing-of="t.ui.showing_of"
        :showing-suffix="t.ui.showing_suffix"
        :aria-label="t.ui.pagination"
        :previous-label="t.ui.previous"
        :next-label="t.ui.next"
    />
</template>

<style scoped>
.wa-card__avatar {
    width: 44px;
    height: 44px;
    background: var(--bs-secondary-bg);
    color: var(--bs-secondary-color);
}
.wa-card__avatar--own {
    background: var(--bs-success-bg-subtle);
    color: var(--bs-success-text-emphasis);
}
.wa-card__avatar--global {
    background: var(--bs-info-bg-subtle);
    color: var(--bs-info-text-emphasis);
}
.wa-card__facts {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.2rem 0.75rem;
}
.wa-card__facts dt {
    font-weight: 600;
    color: var(--bs-body-color);
}
.wa-card__facts dd {
    margin: 0;
    text-align: end;
}

[data-bs-theme='dark'] .wa-card__avatar--none {
    background: var(--bs-tertiary-bg);
}
</style>
