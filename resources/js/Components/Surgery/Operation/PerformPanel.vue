<script setup>
import { computed, reactive, ref } from 'vue';
import { callApi, compact, errorMessages, useOperationText } from './useOperation.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Registrar a cirurgia como REALIZADA: lista o que o servidor vai conferir
 * (autorização, implante da LIO planejada, Pausa Cirúrgica), pede a
 * confirmação da equipe e, para cada condição não atendida que admite exceção,
 * a justificativa do cirurgião. Depois de registrada, mostra o ato gravado.
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { tr } = useOperationText(() => props.t);
const { dateTime } = useLocaleFormat();

const form = reactive({
    team_confirmed: false,
    performed_at: '',
    complications: '',
    authorization_override_reason: '',
    no_implant_reason: '',
    time_out_override_reason: '',
    plan_deviation_reason: '',
    consent_override_reason: '',
});

const busy = ref(false);
const errors = ref([]);

const requirements = computed(() => props.section.requirements ?? []);
const blocked = computed(() => requirements.value.filter((r) => !r.ok));
const minReason = computed(() => props.section.options?.override_min ?? 10);

function overrideShort(field) {
    return (form[field] ?? '').trim().length < minReason.value;
}

/** Só dá para tentar quando cada condição pendente tem a sua justificativa. */
const ready = computed(
    () => form.team_confirmed && blocked.value.every((r) => r.override !== null && !overrideShort(r.override)),
);

async function perform() {
    if (busy.value) return;
    busy.value = true;
    errors.value = [];

    const result = await callApi(props.section.urls.perform, {
        body: compact({
            ...form,
            performed_at: form.performed_at ? new Date(form.performed_at).toISOString() : '',
            expected_revision: props.section.state.case_revision,
        }),
    });

    busy.value = false;

    if (result.ok) {
        emit('reload');
        return;
    }

    errors.value = errorMessages(result);
}
</script>

<template>
    <section class="card mb-3" data-testid="op-perform">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="ti ti-heart-rate-monitor" aria-hidden="true"></i>
            <h2 class="h6 mb-0">{{ tr('perform.title') }}</h2>
        </div>

        <!-- Cirurgia já registrada -->
        <div v-if="section.performed && section.operation" class="card-body" data-testid="op-performed">
            <p class="mb-1">
                <span class="badge text-bg-success me-2">{{ tr('perform.done') }}</span>
                {{ dateTime(section.operation.performed_at) }}
                <span v-if="section.operation.performer" class="text-muted"> · {{ section.operation.performer }}</span>
            </p>
            <p v-if="section.operation.complications" class="small mb-1">
                <strong>{{ tr('perform.complications') }}:</strong> {{ section.operation.complications }}
            </p>
            <p v-if="section.operation.no_implant_reason" class="small mb-1">
                <strong>{{ tr('perform.no_implant_reason') }}:</strong> {{ section.operation.no_implant_reason }}
            </p>
            <ul v-if="Object.keys(section.operation.overrides ?? {}).length" class="small mb-0">
                <li v-for="(reason, key) in section.operation.overrides" :key="key">
                    <strong>{{ tr(`perform.override.${key}`) }}:</strong> {{ reason }}
                </li>
            </ul>
        </div>

        <!-- Ainda agendada: condições e formulário -->
        <div v-else class="card-body">
            <ul class="list-unstyled mb-3" :aria-label="tr('perform.requirements')">
                <li
                    v-for="req in requirements"
                    :key="req.key"
                    class="mb-2"
                    :data-testid="`op-req-${req.key}`"
                    :data-ok="req.ok ? '1' : '0'"
                >
                    <i
                        class="ti me-1"
                        :class="req.ok ? 'ti-circle-check text-success' : 'ti-alert-triangle text-warning'"
                        aria-hidden="true"
                    ></i>
                    <span class="visually-hidden">{{ req.ok ? tr('perform.ok') : tr('perform.pending') }}:</span>
                    {{ req.message }}
                    <div v-if="!req.ok && req.override && section.state.can_perform" class="mt-1 ms-4">
                        <label class="form-label small mb-1" :for="`op-ovr-${req.key}`">
                            {{ tr(`perform.override.${req.key}`) }}
                            <span class="text-muted">({{ tr('perform.min_chars', { n: minReason }) }})</span>
                        </label>
                        <textarea
                            :id="`op-ovr-${req.key}`"
                            v-model="form[req.override]"
                            class="form-control form-control-sm"
                            rows="2"
                            maxlength="2000"
                            :data-testid="`op-ovr-${req.key}`"
                        ></textarea>
                    </div>
                </li>
            </ul>

            <div v-if="section.state.can_perform">
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="form-label small mb-1" for="op-performed-at">{{
                            tr('perform.performed_at')
                        }}</label>
                        <input
                            id="op-performed-at"
                            v-model="form.performed_at"
                            type="datetime-local"
                            class="form-control form-control-sm"
                        />
                        <div class="form-text">{{ tr('perform.performed_at_hint') }}</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label small mb-1" for="op-complications">{{
                            tr('perform.complications')
                        }}</label>
                        <textarea
                            id="op-complications"
                            v-model="form.complications"
                            class="form-control form-control-sm"
                            rows="2"
                            maxlength="5000"
                        ></textarea>
                    </div>
                </div>

                <div v-if="section.team?.length" class="small mb-2">
                    <strong>{{ tr('perform.team') }}:</strong>
                    <span v-for="(m, i) in section.team" :key="i" class="ms-2">
                        {{ m.role_label }}: {{ m.name }}<span v-if="m.registry"> ({{ m.registry }})</span
                        ><span v-if="i < section.team.length - 1">;</span>
                    </span>
                </div>

                <div class="form-check mb-3">
                    <input
                        id="op-team-confirmed"
                        v-model="form.team_confirmed"
                        type="checkbox"
                        class="form-check-input"
                        data-testid="op-team-confirmed"
                    />
                    <label class="form-check-label" for="op-team-confirmed">{{ tr('perform.team_confirmed') }}</label>
                </div>

                <div v-if="errors.length" class="alert alert-danger py-2" role="alert" data-testid="op-perform-errors">
                    <div v-for="(message, i) in errors" :key="i">{{ message }}</div>
                </div>

                <button
                    type="button"
                    class="btn btn-success"
                    :disabled="busy || !ready"
                    data-testid="op-perform-button"
                    @click="perform"
                >
                    <i class="ti ti-check me-1" aria-hidden="true"></i>{{ tr('perform.button') }}
                </button>
                <p v-if="!ready" class="form-text mb-0">{{ tr('perform.not_ready') }}</p>
            </div>
            <p v-else class="text-muted small mb-0">{{ tr('perform.only_surgeon') }}</p>
        </div>
    </section>
</template>
