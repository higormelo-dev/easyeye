<script setup>
import TablePagination from '@/Components/Panel/TablePagination.vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ActionIconButton from '@/Components/Panel/ActionIconButton.vue';
import ActionIconGroup from '@/Components/Panel/ActionIconGroup.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat';
import { automationText, fill, sendingBadge, sentAnswered } from './clinicStatus.js';

/**
 * Clínicas do WhatsApp em tabela — mesmo layout de Manager → Medicamentos
 * (ver detalhes + menu ⋮ com Configurar e, com app próprio, Testar app).
 * Página/filtros vêm do servidor (paginação Inertia).
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
    <div class="table-responsive">
        <table class="table table-nowrap table-hover align-middle mb-0" data-test="wa-clinic-table">
            <thead class="table-light">
                <tr>
                    <th>{{ t.ui.col_clinic }}</th>
                    <th>{{ t.ui.col_number }}</th>
                    <th class="d-none d-md-table-cell">{{ t.ui.col_confirmation }}</th>
                    <th class="d-none d-md-table-cell">{{ t.ui.col_survey }}</th>
                    <th class="d-none d-lg-table-cell text-center">{{ t.ui.col_sent }}</th>
                    <th class="d-none d-lg-table-cell text-center">{{ t.ui.col_opt_outs }}</th>
                    <th class="text-end">{{ t.ui.col_actions }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-if="clinics.data.length === 0">
                    <td colspan="7" class="text-center text-muted py-5" data-test="wa-empty">
                        <i class="ti ti-brand-whatsapp fs-1 d-block mb-2" aria-hidden="true"></i>
                        {{ hasFilters ? t.ui.empty_filtered : t.ui.empty }}
                    </td>
                </tr>

                <tr v-for="clinic in clinics.data" :key="clinic.id" :data-test="`wa-row-${clinic.id}`">
                    <td>
                        <div class="fw-medium" style="font-size: 0.875rem">{{ clinic.name }}</div>
                        <div v-if="clinic.code" class="text-muted" style="font-size: 0.75rem">{{ clinic.code }}</div>
                    </td>
                    <td>
                        <span
                            class="badge rounded fs-12 fw-medium"
                            :class="sendingBadge(clinic, t.ui).cls"
                            :title="sendingBadge(clinic, t.ui).hint"
                            :data-test="`wa-sending-${clinic.id}`"
                            :data-sending="clinic.sending"
                            ><i :class="`${sendingBadge(clinic, t.ui).icon} me-1`" aria-hidden="true"></i
                            >{{ sendingBadge(clinic, t.ui).label }}</span
                        >
                        <span class="visually-hidden">{{ sendingBadge(clinic, t.ui).hint }}</span>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <span v-if="automationText(clinic, 'confirmation', t.ui)" class="text-success small">
                            <i class="ti ti-check me-1" aria-hidden="true"></i
                            >{{ automationText(clinic, 'confirmation', t.ui) }}
                        </span>
                        <span v-else class="text-muted small">{{ t.ui.off }}</span>
                    </td>
                    <td class="d-none d-md-table-cell">
                        <span v-if="automationText(clinic, 'survey', t.ui)" class="text-success small">
                            <i class="ti ti-check me-1" aria-hidden="true"></i
                            >{{ automationText(clinic, 'survey', t.ui) }}
                        </span>
                        <span v-else class="text-muted small">{{ t.ui.off }}</span>
                    </td>
                    <td class="d-none d-lg-table-cell text-center small">
                        <template v-if="sentAnswered(clinic)">
                            <span aria-hidden="true">{{
                                fill(t.ui.sent_answered, {
                                    sent: number(sentAnswered(clinic).sent),
                                    answered: number(sentAnswered(clinic).answered),
                                })
                            }}</span>
                            <span class="visually-hidden">{{
                                fill(t.ui.sent_answered_sr, {
                                    sent: number(sentAnswered(clinic).sent),
                                    answered: number(sentAnswered(clinic).answered),
                                })
                            }}</span>
                        </template>
                        <span v-else class="text-muted">—</span>
                    </td>
                    <td class="d-none d-lg-table-cell text-center small">
                        <span v-if="clinic.setting?.opt_outs" class="badge badge-soft-warning rounded">{{
                            number(clinic.setting.opt_outs)
                        }}</span>
                        <span v-else class="text-muted">—</span>
                    </td>
                    <td class="text-end">
                        <ActionIconGroup align="end" gap="tight">
                            <ActionIconButton
                                icon="ti ti-eye"
                                :title="t.ui.action_view"
                                :data-test="`view-${clinic.id}`"
                                @click="$emit('view', clinic)"
                            />
                            <ActionDropdown
                                btn-class="ee-action-icon ee-action-icon--default"
                                icon="ti ti-dots-vertical"
                                :title="t.ui.more_actions"
                            >
                                <li>
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1"
                                        :data-test="`configure-${clinic.id}`"
                                        @click="$emit('configure', clinic)"
                                    >
                                        <i class="ti ti-settings me-1" aria-hidden="true"></i> {{ t.manager.configure }}
                                    </button>
                                </li>
                                <li v-if="clinic.setting?.has_app">
                                    <button
                                        type="button"
                                        class="dropdown-item rounded-1"
                                        :data-test="`test-${clinic.id}`"
                                        @click="$emit('test', clinic)"
                                    >
                                        <i class="ti ti-plug me-1" aria-hidden="true"></i> {{ t.ui.test_own_app }}
                                    </button>
                                </li>
                            </ActionDropdown>
                        </ActionIconGroup>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <TablePagination
        :data="clinics"
        :showing-from="t.ui.showing_from"
        :showing-of="t.ui.showing_of"
        :showing-suffix="t.ui.showing_suffix"
        :aria-label="t.ui.pagination"
        :previous-label="t.ui.previous"
        :next-label="t.ui.next"
    />
</template>
