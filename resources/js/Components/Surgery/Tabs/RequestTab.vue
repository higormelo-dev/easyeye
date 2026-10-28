<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Aba "Pedido": o que foi pedido à operadora (procedimentos e OPME, com a
 * quantidade autorizada ao lado), a LIO planejada, exames de apoio, equipe e o
 * retrato de segurança clínica congelado no pedido.
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    options: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['edit', 'reload']);

const { dateTime, money } = useLocaleFormat();
const ui = computed(() => props.t.ui?.request ?? {});
const safety = computed(() => props.section.safety ?? {});
const allergies = computed(() => safety.value.allergies ?? null);
const canEdit = computed(
    () => props.section.can_edit_request || props.section.can_edit_clinical || props.section.can_edit_team,
);

const allergyState = computed(() => allergies.value?.state ?? 'unknown');
</script>

<template>
    <div class="d-grid gap-4">
        <div v-if="canEdit" class="text-end">
            <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                data-testid="request-edit"
                @click="emit('edit')"
            >
                <i class="ti ti-edit me-1" aria-hidden="true"></i>{{ ui.edit }}
            </button>
        </div>

        <section aria-labelledby="req-procedures">
            <h2 id="req-procedures" class="h6">{{ ui.procedures }}</h2>
            <p v-if="!section.procedures.length" class="text-muted small mb-0">{{ ui.procedures_empty }}</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ ui.col_tuss }}</th>
                            <th scope="col">{{ ui.col_description }}</th>
                            <th scope="col" class="text-end">{{ ui.col_requested }}</th>
                            <th scope="col" class="text-end">{{ ui.col_authorized }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in section.procedures" :key="p.id" data-testid="request-procedure">
                            <td class="text-nowrap">{{ p.tuss_code ?? '—' }}</td>
                            <td>{{ p.description }}</td>
                            <td class="text-end">{{ p.quantity }}</td>
                            <td class="text-end">{{ p.authorized_quantity ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="surgicalCase.needs_opme || section.materials.length" aria-labelledby="req-materials">
            <h2 id="req-materials" class="h6">{{ ui.materials }}</h2>
            <p v-if="!section.materials.length" class="text-muted small mb-0">{{ ui.materials_empty }}</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ ui.col_description }}</th>
                            <th scope="col">{{ ui.col_manufacturer }}</th>
                            <th scope="col">{{ ui.col_anvisa }}</th>
                            <th scope="col" class="text-end">{{ ui.col_value }}</th>
                            <th scope="col" class="text-end">{{ ui.col_requested }}</th>
                            <th scope="col" class="text-end">{{ ui.col_authorized }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="m in section.materials" :key="m.id" data-testid="request-material">
                            <td>
                                {{ m.description }}
                                <div v-if="m.technical_justification" class="small text-muted">
                                    {{ m.technical_justification }}
                                </div>
                            </td>
                            <td>{{ m.manufacturer ?? '—' }}</td>
                            <td>{{ m.anvisa_registration ?? '—' }}</td>
                            <td class="text-end">{{ m.unit_value ? money(m.unit_value) : '—' }}</td>
                            <td class="text-end">{{ m.quantity }}</td>
                            <td class="text-end">{{ m.authorized_quantity ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section
            v-if="surgicalCase.cid_codes.length || surgicalCase.clinical_indication"
            aria-labelledby="req-diagnosis"
        >
            <h2 id="req-diagnosis" class="h6">{{ ui.diagnosis }}</h2>
            <ul v-if="surgicalCase.cid_codes.length" class="list-inline mb-2">
                <li v-for="cid in surgicalCase.cid_codes" :key="cid.code" class="list-inline-item">
                    <span class="badge text-bg-light border"
                        >{{ cid.code }}<template v-if="cid.description"> — {{ cid.description }}</template></span
                    >
                </li>
            </ul>
            <p v-if="surgicalCase.clinical_indication" class="mb-0" style="white-space: pre-line">
                {{ surgicalCase.clinical_indication }}
            </p>
        </section>

        <section aria-labelledby="req-iol">
            <h2 id="req-iol" class="h6">{{ ui.iol }}</h2>
            <p v-if="!section.iol_plans.length" class="text-muted small mb-0">{{ ui.iol_empty }}</p>
            <div v-else class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ ui.col_eye }}</th>
                            <th scope="col">{{ ui.col_model }}</th>
                            <th scope="col" class="text-end">{{ ui.col_power }}</th>
                            <th scope="col">{{ ui.col_formula }}</th>
                            <th scope="col" class="text-end">{{ ui.col_target }}</th>
                            <th scope="col" class="text-end">{{ ui.col_predicted }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="p in section.iol_plans" :key="p.id" data-testid="request-iol">
                            <td>{{ p.eye_label }}</td>
                            <td>{{ p.model_label }}</td>
                            <td class="text-end">{{ p.power }}</td>
                            <td>{{ p.formula ?? '—' }}</td>
                            <td class="text-end">{{ p.target_refraction ?? '—' }}</td>
                            <td class="text-end">{{ p.predicted_refraction ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mt-1 mb-0">{{ ui.iol_not_calculated }}</p>
        </section>

        <div class="row g-4">
            <section class="col-lg-6" aria-labelledby="req-team">
                <h2 id="req-team" class="h6">{{ ui.team }}</h2>
                <p v-if="!section.team.length" class="text-muted small mb-0">{{ ui.team_empty }}</p>
                <ul v-else class="list-unstyled mb-0">
                    <li v-for="m in section.team" :key="m.id">
                        <span class="fw-medium">{{ m.role_label }}:</span> {{ m.name
                        }}<span v-if="m.registry" class="text-muted"> ({{ m.registry }})</span>
                    </li>
                </ul>
            </section>
            <section class="col-lg-6" aria-labelledby="req-exams">
                <h2 id="req-exams" class="h6">{{ ui.exams }}</h2>
                <p v-if="!section.exams.length" class="text-muted small mb-0">{{ ui.exams_empty }}</p>
                <ul v-else class="list-unstyled mb-0">
                    <li v-for="e in section.exams" :key="e.id">
                        {{ e.name }}<span v-if="e.performed_at" class="text-muted"> · {{ e.performed_at }}</span>
                        <span v-if="e.laterality" class="badge text-bg-light border ms-1">{{ e.laterality }}</span>
                        <span class="badge text-bg-light ms-1">{{ ui[`exam_role_${e.role}`] ?? e.role }}</span>
                    </li>
                </ul>
            </section>
        </div>

        <section aria-labelledby="req-safety" class="border rounded p-3">
            <h2 id="req-safety" class="h6 d-flex align-items-center gap-2">
                <i class="ti ti-shield-heart" aria-hidden="true"></i>{{ ui.safety }}
                <span v-if="safety.taken_at" class="text-muted small fw-normal"
                    >{{ ui.safety_taken_at }} {{ dateTime(safety.taken_at) }}</span
                >
            </h2>

            <p v-if="!allergies" class="text-muted small mb-0">{{ ui.safety_none }}</p>
            <template v-else>
                <div class="mb-2" data-testid="safety-allergies">
                    <span class="fw-medium">{{ ui.allergies }}:</span>
                    <span v-if="allergyState === 'denied'" class="ms-1">{{ ui.allergies_denied }}</span>
                    <span v-else-if="allergyState === 'unknown'" class="ms-1 text-warning-emphasis">{{
                        ui.allergies_unknown
                    }}</span>
                    <ul v-else class="mb-0">
                        <li v-for="a in allergies.items" :key="a.substance">
                            {{ a.substance }}<span v-if="a.reaction" class="text-muted"> — {{ a.reaction }}</span>
                        </li>
                    </ul>
                </div>
                <div v-if="safety.medications?.length" class="mb-2">
                    <span class="fw-medium">{{ ui.medications }}:</span>
                    {{ safety.medications.map((m) => m.name).join(', ') }}
                </div>
                <div
                    v-for="alert in safety.risk_alerts"
                    :key="alert.key"
                    class="alert alert-warning py-2 mb-2"
                    role="note"
                    data-testid="safety-risk"
                >
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>
                    <strong>{{ alert.alert }}</strong>
                    <span class="text-muted"> — {{ alert.medications.join(', ') }}</span>
                </div>
            </template>
        </section>
    </div>
</template>
