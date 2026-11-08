<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import TissCredentialFormModal from './TissCredentialFormModal.vue';

/**
 * Financeiro > Credenciais TISS (só administrador da clínica). Uma linha por operadora + ambiente; segredos nunca
 * chegam aqui (só "está salvo"). O aviso do topo diz se o envio é real ou simulado: no simulado o protocolo começa
 * com SIMULADO- e não vale como aceite. Textos em lang/{locale}/tiss_credentials.php (prop `t`).
 */
const props = defineProps({
    credentials: { type: Array, default: () => [] },
    environments: { type: Array, default: () => [] },
    transport: { type: Object, required: true },
    routes: { type: Object, required: true },
    breadcrumbs: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const tx = (template, params = {}) =>
    Object.entries(params).reduce((text, [k, v]) => text.replaceAll(`:${k}`, String(v ?? '')), template ?? '');

const envLabel = (env) => props.t.environments?.[env] ?? env;
const ENV_BADGE = { sandbox: 'bg-info text-dark', production: 'bg-primary' };

// ── Formulário ───────────────────────────────────────────────────────────────
const formOpen = ref(false);
const editing = ref(null);

function openCreate() {
    editing.value = null;
    formOpen.value = true;
}

function openEdit(credential) {
    editing.value = credential;
    formOpen.value = true;
}

// ── Situação: a cor reforça, o texto informa (MASTER.md §3) ───────────────────
function status(c) {
    if (!c.active) return { label: props.t.status?.inactive, badge: 'bg-secondary' };
    if (c.missing.length === 0) return { label: props.t.status?.ready, badge: 'bg-success' };
    const missing = c.missing.map((item) => props.t.missing?.[item] ?? item).join(', ');
    return { label: tx(props.t.status?.incomplete, { missing }), badge: 'bg-warning text-dark' };
}

function authLabels(c) {
    const a = props.t.auth ?? {};
    const labels = [];
    if (c.username && c.has_password) labels.push(a.login);
    if (c.has_token) labels.push(a.token);
    if (c.certificate) {
        const date = c.certificate.valid_to;
        const when = date ? ` (${tx(c.certificate.expired ? a.expired : a.valid_to, { date })})` : '';
        labels.push(`${a.certificate}${when}`);
    }
    return labels;
}

// ── Ações por linha ──────────────────────────────────────────────────────────
// Nome acessível com a linha: o texto visível ("Editar") vem primeiro, depois operadora e ambiente.
const rowLabel = (action, c) => `${action}: ${c.operator ?? '—'} (${envLabel(c.environment)})`;
const busyId = ref(null);
const busy = computed(() => busyId.value !== null);

// O resultado (teste, remoção) volta como aviso flash; a tabela recarrega com o último teste.
function run(c, request) {
    if (busy.value) return;
    busyId.value = c.id;
    request({ preserveScroll: true, onFinish: () => (busyId.value = null) });
}

const test = (c) => run(c, (options) => router.post(c.urls.test, {}, options));

function remove(c) {
    const message = tx(props.t.actions?.confirm_delete, { operator: c.operator, environment: envLabel(c.environment) });
    if (window.confirm(message)) run(c, (options) => router.delete(c.urls.destroy, options));
}
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="page-tiss-credentials" data-ee-density="default">
            <PageHeader title-tag="h1" :title="t.title" :subtitle="t.subtitle">
                <template #actions>
                    <button type="button" class="btn btn-primary" data-test="tiss-cred-new" @click="openCreate">
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ t.new }}
                    </button>
                </template>
            </PageHeader>

            <div
                class="alert d-flex align-items-start gap-2"
                :class="transport.simulated ? 'alert-warning' : 'alert-info'"
                role="status"
                data-test="tiss-cred-transport"
            >
                <i
                    class="ti fs-5"
                    :class="transport.simulated ? 'ti-flask' : 'ti-plug-connected'"
                    aria-hidden="true"
                ></i>
                <div>
                    {{
                        transport.simulated
                            ? tx(t.transport?.simulated, { driver: transport.driver })
                            : tx(t.transport?.real, { environment: envLabel(transport.environment) })
                    }}
                </div>
            </div>

            <p class="small text-muted">{{ t.admin_only }} {{ t.test?.note }}</p>

            <div v-if="!credentials.length" class="alert alert-light border" data-test="tiss-cred-empty">
                {{ t.empty }}
            </div>

            <div v-else class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr>
                            <th scope="col">{{ t.columns?.operator }}</th>
                            <th scope="col">{{ t.columns?.environment }}</th>
                            <th scope="col">{{ t.columns?.endpoint }}</th>
                            <th scope="col">{{ t.columns?.authentication }}</th>
                            <th scope="col">{{ t.columns?.status }}</th>
                            <th scope="col">{{ t.columns?.last_test }}</th>
                            <th scope="col">{{ t.columns?.actions }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in credentials" :key="c.id" :data-test="`tiss-cred-row-${c.id}`">
                            <td>
                                <div class="fw-semibold">{{ c.operator ?? '—' }}</div>
                                <div class="small text-muted">{{ tx(t.status?.ans, { code: c.ans_code }) }}</div>
                                <div v-if="c.placeholder" class="small text-muted">{{ t.status?.placeholder }}</div>
                            </td>
                            <td>
                                <span class="badge" :class="ENV_BADGE[c.environment] ?? 'bg-secondary'">
                                    {{ envLabel(c.environment) }}
                                </span>
                            </td>
                            <td class="text-break small">
                                {{ c.effective_endpoint ?? '—' }}
                                <span v-if="c.effective_endpoint && !c.endpoint_submission" class="text-muted">
                                    ({{ t.status?.default_url }})
                                </span>
                            </td>
                            <td class="small">
                                <div v-for="label in authLabels(c)" :key="label">{{ label }}</div>
                                <div
                                    v-if="!c.has_authentication"
                                    class="text-warning-emphasis"
                                    data-test="tiss-cred-no-auth"
                                >
                                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ t.auth?.no_auth }}
                                </div>
                            </td>
                            <td>
                                <span class="badge text-wrap text-start" :class="status(c).badge">
                                    {{ status(c).label }}
                                </span>
                            </td>
                            <td class="small" data-test="tiss-cred-last-test">
                                <template v-if="c.last_test">
                                    <i
                                        class="ti me-1"
                                        :class="
                                            c.last_test.ok
                                                ? 'ti-circle-check text-success'
                                                : 'ti-alert-circle text-danger'
                                        "
                                        aria-hidden="true"
                                    ></i>
                                    {{ tx(t.test?.at, { result: c.last_test.text, date: c.last_test.at ?? '—' }) }}
                                </template>
                                <span v-else class="text-muted">{{ t.test?.never }}</span>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary"
                                        data-test="tiss-cred-test"
                                        :aria-label="rowLabel(t.actions?.test, c)"
                                        :disabled="busy"
                                        :aria-busy="busyId === c.id ? 'true' : undefined"
                                        @click="test(c)"
                                    >
                                        <i class="ti ti-plug me-1" aria-hidden="true"></i>
                                        {{ busyId === c.id ? t.actions?.testing : t.actions?.test }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-secondary"
                                        data-test="tiss-cred-edit"
                                        :aria-label="rowLabel(t.actions?.edit, c)"
                                        :disabled="busy"
                                        @click="openEdit(c)"
                                    >
                                        {{ t.actions?.edit }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger"
                                        data-test="tiss-cred-delete"
                                        :aria-label="rowLabel(t.actions?.delete, c)"
                                        :disabled="busy"
                                        @click="remove(c)"
                                    >
                                        {{ t.actions?.delete }}
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <TissCredentialFormModal
                :open="formOpen"
                :credential="editing"
                :environments="environments"
                :routes="routes"
                :t="t"
                @close="formOpen = false"
            />
        </div>
    </AppLayout>
</template>
