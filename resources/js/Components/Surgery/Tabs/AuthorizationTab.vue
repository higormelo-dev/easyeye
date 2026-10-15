<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import CenteredModal from '@/Components/Panel/CenteredModal.vue';
import ReasonModal from '@/Components/Surgery/ReasonModal.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Aba "Autorização": estado atual, o que falta no pedido, ações permitidas
 * AGORA (o servidor decide: `section.actions`) e o log imutável de eventos.
 * Resposta da operadora, dispensa e anotação passam pelo mesmo modal.
 */
const props = defineProps({
    section: { type: Object, required: true },
    surgicalCase: { type: Object, required: true },
    can: { type: Object, default: () => ({}) },
    urls: { type: Object, default: () => ({}) },
    t: { type: Object, required: true },
});

const emit = defineEmits(['reload']);

const { date, dateTime } = useLocaleFormat();
const ui = computed(() => props.t.ui?.authorization ?? {});
const types = computed(() => props.t.ui?.authorization_types ?? {});
const actions = computed(() => props.section.actions ?? {});
const auth = props.urls.authorization;

const busy = ref(false);
const withdrawing = ref(false);
const withdrawError = ref('');
const responding = ref(false);

const form = useForm({
    type: 'authorized',
    occurred_at: '',
    authorization_number: '',
    authorization_password: '',
    valid_until: '',
    channel: '',
    protocol_number: '',
    denial_reason: '',
    note: '',
    authorized_items: [],
    expected_revision: null,
});

const allowedTypes = computed(() => {
    const list = [];
    if (actions.value.respond) list.push('submitted', 'authorized', 'partially_authorized', 'denied');
    if (actions.value.waive) list.push('waived');
    if (actions.value.note) list.push('note');
    return list;
});

function openRespond(type) {
    form.reset();
    form.clearErrors();
    form.type = type ?? allowedTypes.value[0] ?? 'note';
    form.expected_revision = props.surgicalCase.revision;
    form.authorized_items = props.section.items.map((i) => ({
        kind: i.kind,
        id: i.id,
        // Item que a operadora já respondeu (a outra guia do pedido) aparece com a resposta dela e não se refaz aqui.
        quantity: i.locked ? i.authorized : i.requested,
        label: i.label,
        requested: i.requested,
        locked: Boolean(i.locked),
    }));
    responding.value = true;
}

const showNumber = computed(() => ['authorized', 'partially_authorized'].includes(form.type));

// Parte do pedido já respondida pela operadora (a outra guia): o registro manual vale só para o que falta.
const answeredLabels = computed(() => props.section.items.filter((i) => i.locked).map((i) => i.label));
const openLabels = computed(() => props.section.items.filter((i) => !i.locked).map((i) => i.label));

function submitRespond() {
    form.transform((data) => ({
        type: data.type,
        occurred_at: data.occurred_at || undefined,
        authorization_number: showNumber.value ? data.authorization_number || undefined : undefined,
        authorization_password: showNumber.value ? data.authorization_password || undefined : undefined,
        valid_until: showNumber.value ? data.valid_until || undefined : undefined,
        channel: data.channel || undefined,
        protocol_number: ['submitted', 'authorized', 'partially_authorized', 'denied'].includes(data.type)
            ? data.protocol_number || undefined
            : undefined,
        denial_reason: data.type === 'denied' ? data.denial_reason : undefined,
        note: ['waived', 'note'].includes(data.type) ? data.note : data.note || undefined,
        authorized_items:
            data.type === 'partially_authorized'
                ? data.authorized_items.map(({ kind, id, quantity }) => ({ kind, id, quantity: Number(quantity) || 0 }))
                : undefined,
        expected_revision: data.expected_revision,
    })).post(auth.record, {
        preserveScroll: true,
        onSuccess: () => {
            responding.value = false;
            emit('reload');
        },
    });
}

function requestAuthorization() {
    busy.value = true;
    router.post(
        auth.request,
        { expected_revision: props.surgicalCase.revision },
        {
            preserveScroll: true,
            onSuccess: () => emit('reload'),
            onFinish: () => (busy.value = false),
        },
    );
}

function withdraw(reason) {
    busy.value = true;
    withdrawError.value = '';
    router.post(
        auth.withdraw,
        { reason, expected_revision: props.surgicalCase.revision },
        {
            preserveScroll: true,
            onSuccess: () => {
                withdrawing.value = false;
                emit('reload');
            },
            onError: (errors) => {
                withdrawError.value = errors.reason ?? '';
            },
            onFinish: () => (busy.value = false),
        },
    );
}

const eventTone = (type) =>
    ({
        authorized: 'success',
        partially_authorized: 'info',
        denied: 'danger',
        expired: 'danger',
        waived: 'secondary',
        requested: 'primary',
        appealed: 'primary',
    })[type] ?? 'secondary';

const err = (path) => form.errors[path] ?? '';
</script>

<template>
    <div class="d-grid gap-4">
        <section aria-labelledby="auth-state" class="d-flex flex-wrap align-items-center gap-3">
            <div>
                <h2 id="auth-state" class="h6 mb-1">{{ ui.state }}</h2>
                <span
                    class="badge fs-6"
                    :class="section.state.expired ? 'text-bg-danger' : 'text-bg-light border'"
                    data-testid="auth-state"
                >
                    {{ section.state.label }}
                </span>
                <span v-if="section.state.number" class="ms-2"
                    >{{ ui.number }}: <strong>{{ section.state.number }}</strong></span
                >
                <span v-if="section.state.valid_until" class="ms-2 text-muted"
                    >{{ ui.valid_until }} {{ date(section.state.valid_until) }}</span
                >
            </div>

            <div class="ms-auto d-flex flex-wrap gap-2">
                <button
                    v-if="actions.request"
                    type="button"
                    class="btn btn-primary btn-sm"
                    :disabled="busy || section.problems.length > 0"
                    data-testid="auth-request"
                    @click="requestAuthorization"
                >
                    <i class="ti ti-send me-1" aria-hidden="true"></i>{{ actions.appeal ? ui.appeal : ui.request }}
                </button>
                <button
                    v-if="actions.respond"
                    type="button"
                    class="btn btn-success btn-sm"
                    data-testid="auth-respond"
                    @click="openRespond('authorized')"
                >
                    <i class="ti ti-check me-1" aria-hidden="true"></i>{{ ui.respond }}
                </button>
                <button
                    v-if="actions.waive"
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-testid="auth-waive"
                    @click="openRespond('waived')"
                >
                    {{ ui.waive }}
                </button>
                <button
                    v-if="actions.withdraw"
                    type="button"
                    class="btn btn-outline-warning btn-sm"
                    data-testid="auth-withdraw"
                    @click="withdrawing = true"
                >
                    {{ ui.withdraw }}
                </button>
                <button
                    v-if="actions.note"
                    type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-testid="auth-note"
                    @click="openRespond('note')"
                >
                    <i class="ti ti-note me-1" aria-hidden="true"></i>{{ ui.add_note }}
                </button>
            </div>
        </section>

        <div v-if="section.problems.length" class="alert alert-warning mb-0" role="status" data-testid="auth-problems">
            <strong>{{ ui.problems_title }}</strong>
            <ul class="mb-0">
                <li v-for="problem in section.problems" :key="problem">{{ problem }}</li>
            </ul>
        </div>

        <section aria-labelledby="auth-events">
            <h2 id="auth-events" class="h6">{{ ui.events }}</h2>
            <p v-if="!section.events.length" class="text-muted small mb-0">{{ ui.events_empty }}</p>
            <ol v-else class="list-group list-group-flush">
                <li v-for="e in section.events" :key="e.id" class="list-group-item px-0" data-testid="auth-event">
                    <div class="d-flex flex-wrap align-items-baseline gap-2">
                        <span class="badge" :class="`text-bg-${eventTone(e.type)}`">{{ e.label }}</span>
                        <span class="text-muted small"
                            >{{ dateTime(e.occurred_at)
                            }}<template v-if="e.recorded_by"> · {{ e.recorded_by }}</template></span
                        >
                        <span v-if="e.channel" class="badge text-bg-light border">{{
                            ui[`channel_${e.channel}`] ?? e.channel
                        }}</span>
                    </div>
                    <div v-if="e.number || e.valid_until || e.protocol_number" class="small mt-1">
                        <span v-if="e.number" class="me-3">{{ ui.number }}: {{ e.number }}</span>
                        <span v-if="e.valid_until" class="me-3">{{ ui.valid_until }} {{ date(e.valid_until) }}</span>
                        <span v-if="e.protocol_number">{{ ui.protocol }}: {{ e.protocol_number }}</span>
                    </div>
                    <ul v-if="e.authorized_items?.length" class="small mb-0 mt-1">
                        <li v-for="item in e.authorized_items" :key="item.kind + item.id">
                            {{ section.items.find((i) => i.id === item.id)?.label ?? item.id }} — {{ item.quantity }}
                        </li>
                    </ul>
                    <div v-if="e.denial_reason" class="small text-danger mt-1">{{ e.denial_reason }}</div>
                    <div v-if="e.note" class="small text-muted mt-1">{{ e.note }}</div>
                </li>
            </ol>
        </section>

        <CenteredModal :open="responding" size="lg" @close="responding = false">
            <template #header>{{ ui.respond_title }}</template>

            <form class="p-2 row g-3" novalidate @submit.prevent="submitRespond">
                <div class="col-md-6">
                    <label class="form-label fw-medium" for="auth-type">{{ ui.type }}</label>
                    <select id="auth-type" v-model="form.type" class="form-select" data-testid="auth-type">
                        <option v-for="type in allowedTypes" :key="type" :value="type">
                            {{ types[type] ?? type }}
                        </option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium" for="auth-when">{{ ui.occurred_at }}</label>
                    <input
                        id="auth-when"
                        v-model="form.occurred_at"
                        type="datetime-local"
                        class="form-control"
                        :class="{ 'is-invalid': err('occurred_at') }"
                    />
                    <div class="invalid-feedback">{{ err('occurred_at') }}</div>
                </div>

                <p
                    v-if="showNumber && answeredLabels.length"
                    class="col-12 small text-muted mb-0"
                    data-testid="auth-scope-hint"
                >
                    {{ (ui.scope_hint ?? '').replace(':items', openLabels.join('; ')) }}
                </p>

                <template v-if="showNumber">
                    <div class="col-md-4">
                        <label class="form-label fw-medium" for="auth-number">{{ ui.number }}</label>
                        <input
                            id="auth-number"
                            v-model="form.authorization_number"
                            type="text"
                            maxlength="40"
                            class="form-control"
                            :class="{ 'is-invalid': err('authorization_number') }"
                            data-testid="auth-number"
                        />
                        <div class="invalid-feedback">{{ err('authorization_number') }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium" for="auth-password">{{ ui.password }}</label>
                        <input
                            id="auth-password"
                            v-model="form.authorization_password"
                            type="text"
                            maxlength="40"
                            class="form-control"
                            autocomplete="off"
                        />
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-medium" for="auth-valid">{{ ui.valid_until }}</label>
                        <input
                            id="auth-valid"
                            v-model="form.valid_until"
                            type="date"
                            class="form-control"
                            :class="{ 'is-invalid': err('valid_until') }"
                        />
                        <div class="invalid-feedback">{{ err('valid_until') }}</div>
                    </div>
                </template>

                <template v-if="form.type === 'partially_authorized'">
                    <div class="col-12">
                        <div v-if="err('authorized_items')" class="alert alert-danger py-2">
                            {{ err('authorized_items') }}
                        </div>
                        <p class="small text-muted mb-1">{{ ui.partial_hint }}</p>
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr>
                                    <th scope="col">{{ ui.item }}</th>
                                    <th scope="col" class="text-end">{{ ui.requested }}</th>
                                    <th scope="col" class="text-end" style="width: 8rem">{{ ui.authorized }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="(item, i) in form.authorized_items" :key="item.id">
                                    <td>
                                        {{ item.label }}
                                        <span v-if="item.locked" class="badge text-bg-light border ms-1">{{
                                            ui.item_answered
                                        }}</span>
                                    </td>
                                    <td class="text-end">{{ item.requested }}</td>
                                    <td>
                                        <input
                                            v-model.number="form.authorized_items[i].quantity"
                                            type="number"
                                            min="0"
                                            :max="item.requested"
                                            class="form-control form-control-sm text-end"
                                            :disabled="item.locked"
                                            :aria-label="`${ui.authorized}: ${item.label}`"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </template>

                <template v-if="['submitted', 'authorized', 'partially_authorized', 'denied'].includes(form.type)">
                    <div class="col-md-6">
                        <label class="form-label fw-medium" for="auth-channel">{{ ui.channel }}</label>
                        <select id="auth-channel" v-model="form.channel" class="form-select">
                            <option value="">—</option>
                            <option v-for="c in section.channels" :key="c" :value="c">
                                {{ ui[`channel_${c}`] ?? c }}
                            </option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-medium" for="auth-protocol">{{ ui.protocol }}</label>
                        <input
                            id="auth-protocol"
                            v-model="form.protocol_number"
                            type="text"
                            maxlength="40"
                            class="form-control"
                        />
                    </div>
                </template>

                <div v-if="form.type === 'denied'" class="col-12">
                    <label class="form-label fw-medium" for="auth-denial">{{ ui.denial_reason }}</label>
                    <textarea
                        id="auth-denial"
                        v-model="form.denial_reason"
                        rows="3"
                        maxlength="2000"
                        class="form-control"
                        :class="{ 'is-invalid': err('denial_reason') }"
                        data-testid="auth-denial"
                    ></textarea>
                    <div class="invalid-feedback">{{ err('denial_reason') }}</div>
                </div>

                <div v-if="['waived', 'note'].includes(form.type)" class="col-12">
                    <label class="form-label fw-medium" for="auth-note">{{
                        form.type === 'waived' ? ui.waive_reason : ui.note
                    }}</label>
                    <textarea
                        id="auth-note"
                        v-model="form.note"
                        rows="3"
                        maxlength="2000"
                        class="form-control"
                        :class="{ 'is-invalid': err('note') }"
                        data-testid="auth-note-text"
                    ></textarea>
                    <div class="invalid-feedback">{{ err('note') }}</div>
                    <div v-if="err('request')" class="alert alert-danger mt-2">{{ err('request') }}</div>
                </div>
            </form>

            <template #footer>
                <button type="button" class="btn btn-outline-secondary" @click="responding = false">
                    {{ ui.cancel }}
                </button>
                <button
                    type="button"
                    class="btn btn-primary"
                    :disabled="form.processing"
                    data-testid="auth-save"
                    @click="submitRespond"
                >
                    <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span
                    >{{ ui.save }}
                </button>
            </template>
        </CenteredModal>

        <ReasonModal
            :open="withdrawing"
            :title="ui.withdraw_title"
            :label="ui.withdraw_reason"
            :confirm-text="ui.withdraw"
            :cancel-text="ui.cancel"
            tone="warning"
            :busy="busy"
            :error="withdrawError"
            @close="withdrawing = false"
            @confirm="withdraw"
        />
    </div>
</template>
