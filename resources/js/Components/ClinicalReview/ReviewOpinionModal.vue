<script setup>
import { computed, ref, watch } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import { makeTx, NEUTRAL_BADGE } from '@/Support/recallRule.js';
import { useLocaleFormat } from '@/composables/useLocaleFormat';

/**
 * O parecer do Revisor, para a pessoa da EasyEye conferir (Manager). Mostra o que ele DECLAROU
 * (nome, CRM/UF, RQE, e-mail, conflito, a prova do envio), compara com o cadastro e, se o convite
 * está aguardando conferência, registra o aceite: abrir o portal do CFM, conferir o declarado e
 * marcar o que foi conferido. Sem as duas conferências não há aceite (o servidor também recusa).
 *
 * Tudo que o Revisor digitou (justificativas, nome...) aparece como TEXTO: nada disso é HTML.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    /** A linha do convite (Index): items, attestation, acceptance, can_accept. */
    invitation: { type: Object, default: null },
    t: { type: Object, required: true },
});

const emit = defineEmits(['close']);

const page = usePage();
const tx = makeTx(() => props.t);
const { dateTime } = useLocaleFormat();

const attestation = computed(() => props.invitation?.attestation ?? null);
const acceptance = computed(() => props.invitation?.acceptance ?? null);
const canAccept = computed(() => props.invitation?.can_accept === true);
const mismatch = computed(() => attestation.value?.identity_mismatch ?? []);

const DECISION_CLASS = {
    approved: 'text-bg-success',
    changes_requested: 'text-bg-warning',
    rejected: 'text-bg-danger',
};

// ── o aceite ────────────────────────────────────────────────────────────────
const choice = ref('accepted');
const form = useForm({ decision: 'accepted', crm_checked: false, rqe_checked: false, note: '' }).transform((data) => ({
    ...data,
    decision: choice.value,
}));

watch(
    () => [props.open, props.invitation?.id],
    () => {
        if (!props.open) return;

        form.reset();
        form.clearErrors();
    },
    { immediate: true },
);

function decide(decision) {
    if (form.processing) return;

    choice.value = decision;
    form.post(route('manager.clinical-reviews.acceptance', props.invitation.id), {
        preserveScroll: true,
        // Recusa de negócio (já conferido por outra pessoa, convite mudou) volta como aviso: o modal fica aberto.
        onSuccess: () => {
            if (!page.props.flash?.error) emit('close');
        },
    });
}

const differs = (field) => mismatch.value.includes(field);
</script>

<template>
    <CenteredModal :open="open && invitation !== null" size="xl" @close="emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                {{ canAccept ? tx('acceptance.title') : tx('acceptance.view_title') }}
                <small v-if="invitation" class="fw-normal text-body-secondary">— {{ invitation.reviewer.name }}</small>
            </h5>
        </template>

        <div v-if="invitation" data-testid="opinion-modal">
            <!-- identidade declarada × cadastro -->
            <section v-if="attestation" class="mb-4" data-testid="opinion-identity">
                <div
                    v-if="!attestation.identity_matches"
                    class="rounded border p-2 px-3 small mb-3 bg-warning-subtle text-warning-emphasis border-warning-subtle"
                    role="alert"
                    data-testid="mismatch-banner"
                >
                    <strong>{{ tx('acceptance.mismatch_title') }}:</strong>
                    {{ mismatch.map((field) => tx(`acceptance.mismatch_fields.${field}`)).join(', ') }}.
                    {{ tx('acceptance.mismatch_hint') }}
                </div>

                <div class="row g-4">
                    <div class="col-lg-7">
                        <h6 class="fw-semibold">{{ tx('acceptance.declared_title') }}</h6>
                        <dl class="row small mb-0">
                            <dt class="col-sm-4">{{ tx('acceptance.fields.name') }}</dt>
                            <dd class="col-sm-8 text-break">{{ attestation.name }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.email') }}</dt>
                            <dd class="col-sm-8 text-break" :class="{ 'fw-semibold': differs('email') }">
                                {{ attestation.email }}
                            </dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.crm') }}</dt>
                            <dd class="col-sm-8" :class="{ 'fw-semibold': differs('crm_number') || differs('crm_uf') }">
                                {{ attestation.crm_number }} / {{ attestation.crm_uf }}
                            </dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.rqe') }}</dt>
                            <dd class="col-sm-8" :class="{ 'fw-semibold': differs('rqe') }">{{ attestation.rqe }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.specialty') }}</dt>
                            <dd class="col-sm-8">{{ attestation.specialty }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.conflict') }}</dt>
                            <dd class="col-sm-8">
                                {{
                                    attestation.conflict_of_interest
                                        ? tx('acceptance.conflict_yes')
                                        : tx('acceptance.conflict_none')
                                }}
                                <span
                                    v-if="attestation.conflict_description"
                                    class="d-block text-break"
                                    style="white-space: pre-line"
                                    >{{ attestation.conflict_description }}</span
                                >
                            </dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.display') }}</dt>
                            <dd class="col-sm-8">
                                {{
                                    attestation.allow_identity_display
                                        ? tx('acceptance.allowed')
                                        : tx('acceptance.not_allowed')
                                }}
                            </dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.signed_at') }}</dt>
                            <dd class="col-sm-8">{{ dateTime(attestation.signed_at) }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.ip') }}</dt>
                            <dd class="col-sm-8">{{ attestation.ip }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.agent') }}</dt>
                            <dd class="col-sm-8 text-break">{{ attestation.user_agent }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.hash') }}</dt>
                            <dd class="col-sm-8">
                                <code class="text-break">{{ attestation.submission_hash }}</code>
                            </dd>
                        </dl>
                    </div>

                    <div v-if="attestation.registered" class="col-lg-5">
                        <h6 class="fw-semibold">{{ tx('acceptance.registered_title') }}</h6>
                        <dl class="row small mb-0">
                            <dt class="col-sm-4">{{ tx('acceptance.fields.name') }}</dt>
                            <dd class="col-sm-8 text-break">{{ attestation.registered.name }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.email') }}</dt>
                            <dd class="col-sm-8 text-break">{{ attestation.registered.email }}</dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.crm') }}</dt>
                            <dd class="col-sm-8">
                                {{ attestation.registered.crm_number }} / {{ attestation.registered.crm_uf }}
                            </dd>
                            <dt class="col-sm-4">{{ tx('acceptance.fields.rqe') }}</dt>
                            <dd class="col-sm-8">{{ attestation.registered.rqe }}</dd>
                        </dl>
                    </div>
                </div>
            </section>

            <!-- decisões -->
            <section class="mb-4" data-testid="opinion-decisions">
                <h6 class="fw-semibold">{{ tx('acceptance.decisions') }}</h6>
                <ul class="list-group">
                    <li
                        v-for="item in invitation.items"
                        :key="item.id"
                        class="list-group-item"
                        :data-testid="`opinion-${item.key}`"
                    >
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <strong>{{ item.title }}</strong>
                            <span class="text-body-secondary">v{{ item.version }}</span>
                            <code class="small">{{ item.short_hash }}</code>
                            <span
                                v-if="item.decision"
                                class="badge fw-normal"
                                :class="DECISION_CLASS[item.decision] ?? NEUTRAL_BADGE"
                                >{{ item.decision_label }}</span
                            >
                            <Link
                                v-if="item.protocol_id"
                                :href="
                                    route('manager.recall-protocols.versions.show', [item.protocol_id, item.version_id])
                                "
                                class="ms-auto small"
                                >{{ tx('acceptance.open_version') }}</Link
                            >
                        </div>
                        <p v-if="item.comment" class="small mb-1 mt-2 text-break" style="white-space: pre-line">
                            <strong>{{ tx('acceptance.comment') }}:</strong> {{ item.comment }}
                        </p>
                        <p
                            v-if="item.proposed_changes"
                            class="small mb-0 mt-1 text-break"
                            style="white-space: pre-line"
                        >
                            <strong>{{ tx('acceptance.proposed') }}:</strong> {{ item.proposed_changes }}
                        </p>
                    </li>
                </ul>
            </section>

            <!-- aceite já registrado -->
            <section v-if="acceptance" data-testid="opinion-result">
                <h6 class="fw-semibold">{{ tx('acceptance.result_title') }}</h6>
                <p class="mb-1">
                    <span
                        class="badge fw-normal"
                        :class="acceptance.decision === 'accepted' ? 'text-bg-success' : 'text-bg-danger'"
                        >{{ acceptance.decision_label }}</span
                    >
                    {{
                        tx('acceptance.result_by', {
                            name: acceptance.accepted_by,
                            date: dateTime(acceptance.checked_at),
                        })
                    }}
                    · {{ tx('acceptance.method_cfm') }}
                </p>
                <p class="small mb-1">
                    {{
                        acceptance.crm_checked
                            ? tx('acceptance.checked_crm')
                            : `${tx('acceptance.fields.crm')}: ${tx('acceptance.not_checked')}`
                    }}
                    ·
                    {{
                        acceptance.rqe_checked
                            ? tx('acceptance.checked_rqe')
                            : `${tx('acceptance.fields.rqe')}: ${tx('acceptance.not_checked')}`
                    }}
                </p>
                <p v-if="acceptance.note" class="small text-break mb-0" style="white-space: pre-line">
                    {{ acceptance.note }}
                </p>
            </section>

            <!-- a conferência no CFM -->
            <form v-else-if="canAccept" novalidate data-testid="acceptance-form" @submit.prevent>
                <h6 class="fw-semibold">{{ tx('acceptance.check_title') }}</h6>
                <p class="small text-body-secondary">{{ tx('acceptance.check_hint') }}</p>

                <div class="form-check">
                    <input
                        id="ac-crm"
                        v-model="form.crm_checked"
                        class="form-check-input"
                        type="checkbox"
                        data-testid="crm-checked"
                    />
                    <label class="form-check-label" for="ac-crm">{{ tx('acceptance.crm_checked') }}</label>
                </div>
                <div class="form-check mb-2">
                    <input
                        id="ac-rqe"
                        v-model="form.rqe_checked"
                        class="form-check-input"
                        type="checkbox"
                        data-testid="rqe-checked"
                    />
                    <label class="form-check-label" for="ac-rqe">{{ tx('acceptance.rqe_checked') }}</label>
                </div>
                <div
                    v-if="form.errors.crm_checked"
                    class="invalid-feedback d-block mb-2"
                    role="alert"
                    data-testid="error-checks"
                >
                    {{ form.errors.crm_checked }}
                </div>

                <label for="ac-note" class="form-label">{{ tx('acceptance.note') }}</label>
                <textarea
                    id="ac-note"
                    v-model="form.note"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.note }"
                    rows="3"
                    maxlength="1000"
                    aria-describedby="ac-note-hint"
                    data-testid="acceptance-note"
                ></textarea>
                <div id="ac-note-hint" class="form-text">{{ tx('acceptance.note_hint') }}</div>
                <div v-if="form.errors.note" class="invalid-feedback d-block" role="alert" data-testid="error-note">
                    {{ form.errors.note }}
                </div>

                <p class="small text-body-secondary mt-3 mb-0">
                    {{ tx('acceptance.accept_effect') }} {{ tx('acceptance.refuse_effect') }}
                </p>
            </form>
        </div>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="form.processing" @click="emit('close')">
                {{ tx('acceptance.close') }}
            </button>
            <template v-if="canAccept && !acceptance">
                <button
                    type="button"
                    class="btn btn-outline-danger"
                    :disabled="form.processing"
                    data-testid="refuse"
                    @click="decide('refused')"
                >
                    {{ tx('acceptance.refuse') }}
                </button>
                <button
                    type="button"
                    class="btn btn-success"
                    :disabled="form.processing"
                    data-testid="accept"
                    @click="decide('accepted')"
                >
                    <span
                        v-if="form.processing"
                        class="spinner-border spinner-border-sm me-1"
                        aria-hidden="true"
                    ></span>
                    {{ tx('acceptance.accept') }}
                </button>
            </template>
        </template>
    </CenteredModal>
</template>
