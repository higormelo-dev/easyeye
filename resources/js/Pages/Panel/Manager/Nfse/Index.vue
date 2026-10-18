<script setup>
/* eslint-disable vue/prop-name-casing -- Inertia fiscal contract uses snake_case props. */
import { computed, ref, watch } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import NfseSettingsForm from './NfseSettingsForm.vue';
import NfseCredentialsCard from './NfseCredentialsCard.vue';
import NfseHookCard from './NfseHookCard.vue';
import NfseMunicipalityCard from './NfseMunicipalityCard.vue';
import NfseDocumentsTable from './NfseDocumentsTable.vue';

const props = defineProps({
    settings: { type: Object, default: () => ({}) },
    credentials: { type: Array, default: () => [] },
    hook_urls: { type: Object, default: () => ({}) },
    municipality: { type: Object, default: null },
    documents: { type: Array, default: () => [] },
    pending_invoices: { type: Array, default: () => [] },
    entities_missing_ibge: { type: Array, default: () => [] },
    permissions: { type: Object, default: () => ({}) },
    t: { type: Object, default: () => ({}) },
});

const { money, dateTime } = useLocaleFormat();
const breadcrumbs = computed(() => [{ label: props.t.breadcrumb }]);
const saving = ref(false);
const errors = ref({});
const message = ref('');
const busyId = ref(null);
const actionSuccess = ref(0);
const searchResults = ref([]);
const settingsState = ref(props.settings);
const municipalityState = ref(props.municipality);
const hasMasterToken = computed(() => props.credentials.some((credential) => credential.has_master_token));
watch(
    () => props.municipality,
    (value) => {
        municipalityState.value = value;
    },
);
watch(
    () => props.settings,
    (value) => {
        settingsState.value = value;
    },
);

function endpoint(name, params) {
    return route(name, params);
}
function messages(data) {
    if (Array.isArray(data?.errors))
        return data.errors
            .map((error) => error.correcao || error.mensagem || error.codigo)
            .filter(Boolean)
            .join(' ');
    if (data?.errors) return Object.values(data.errors).flat().join(' ');
    return data?.message ?? props.t.errors?.request_failed;
}
function toast(text, kind = 'success') {
    if (kind === 'success') window.showSuccessToast?.(text);
    else window.showErrorToast?.(text);
}
async function request(method, url, payload = null) {
    const response = await window.axios({ method, url, data: payload, headers: { Accept: 'application/json' } });
    return response.data ?? {};
}
async function run({ method, url, payload, reload = false }) {
    saving.value = true;
    errors.value = {};
    message.value = '';
    try {
        const data = await request(method, url, payload);
        if (data.message) toast(data.message);
        if (reload) window.location.reload();
        return data;
    } catch (error) {
        const data = error?.response?.data ?? {};
        errors.value = Array.isArray(data.errors)
            ? {}
            : Object.fromEntries(
                  Object.entries(data.errors ?? {}).map(([key, value]) => [
                      key,
                      Array.isArray(value) ? value[0] : value,
                  ]),
              );
        message.value = messages(data);
        toast(message.value, 'error');
        return null;
    } finally {
        saving.value = false;
    }
}
async function saveSettings(payload) {
    await run({ method: 'put', url: endpoint('manager.nfse.settings.update'), payload, reload: true });
}
async function saveCredentials(payload) {
    await run({
        method: 'post',
        url: endpoint('manager.nfse.credentials.store', { environment: payload.environment }),
        payload,
        reload: true,
    });
}
async function testCredentials(environment) {
    await run({
        method: 'post',
        url: endpoint('manager.nfse.credentials.test', { environment }),
        payload: { environment },
        reload: true,
    });
}
async function manageHook(method, payload) {
    await run({
        method,
        url: endpoint(
            method === 'post' ? 'manager.nfse.hooks.store' : 'manager.nfse.hooks.destroy',
            method === 'delete' ? { environment: payload.environment } : undefined,
        ),
        payload,
        reload: true,
    });
}
async function saveMunicipality(ibge) {
    const data = await run({ method: 'get', url: endpoint('manager.nfse.municipality', { ibge, refresh: 1 }) });
    if (!data?.municipality) return;
    const settings = {
        ...settingsState.value,
        prestador: { ...settingsState.value?.prestador, codigo_municipio: data.municipality.codigo_municipio },
    };
    const result = await run({
        method: 'put',
        url: endpoint('manager.nfse.settings.update'),
        payload: settings,
    });
    if (!result) return;
    settingsState.value = settings;
    municipalityState.value = data.municipality;
}
async function refreshMunicipality() {
    const data = await run({
        method: 'get',
        url: endpoint('manager.nfse.municipality', { ibge: municipalityState.value?.codigo_municipio, refresh: 1 }),
    });
    if (data?.municipality) municipalityState.value = data.municipality;
}
async function searchMunicipality({ q, uf }) {
    const data = await run({ method: 'get', url: endpoint('manager.nfse.municipalities.search', { q, uf }) });
    searchResults.value = data?.data ?? [];
}
async function saveEntityIbge(entity, ibge_code) {
    await run({
        method: 'put',
        url: endpoint('manager.nfse.entities.ibge-code', { entity: entity.id }),
        payload: { ibge_code },
        reload: true,
    });
}
async function documentAction({ document, kind, payload }) {
    const actions = {
        issue: document.actions?.issue,
        cancel: document.actions?.cancel,
        mark_cancelled_externally: document.actions?.mark_cancelled_externally,
        resend: document.actions?.resend_email,
        sync: document.actions?.sync,
    };
    const url = actions[kind];
    if (!url) return;
    busyId.value = document.id;
    try {
        const result = await run({
            method: kind === 'sync' || kind === 'issue' || kind === 'resend' ? 'post' : 'post',
            url,
            payload,
            reload: true,
        });
        if (result) actionSuccess.value++;
    } finally {
        busyId.value = null;
    }
}
async function issuePending(invoice) {
    await run({ method: 'post', url: invoice.issue_url, payload: {}, reload: true });
}
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs"
        ><div class="container-fluid py-3">
            <PageHeader :title="t.title" />
            <p class="text-muted mb-4">{{ t.subtitle }}</p>
            <div v-if="message" class="alert alert-danger" role="alert">{{ message }}</div>
            <div v-if="permissions.can_settings" class="row g-3 mb-4">
                <div class="col-12">
                    <NfseSettingsForm
                        :settings="settingsState"
                        :t="t"
                        :saving="saving"
                        :errors="errors"
                        @save="saveSettings"
                    />
                </div>
                <div v-for="credential in credentials" :key="credential.environment" class="col-lg-6">
                    <NfseCredentialsCard
                        :credential="credential"
                        :t="t"
                        :saving="saving"
                        :errors="errors"
                        @save="saveCredentials"
                        @test="testCredentials"
                    />
                </div>
                <div v-for="credential in credentials" :key="`hook-${credential.environment}`" class="col-lg-6">
                    <NfseHookCard
                        :credential="credential"
                        :hook-url="hook_urls[credential.environment]"
                        :t="t"
                        :saving="saving"
                        :errors="errors"
                        @store="manageHook('post', $event)"
                        @destroy="manageHook('delete', $event)"
                    />
                </div>
                <div class="col-lg-6">
                    <NfseMunicipalityCard
                        :municipality="municipalityState"
                        :enabled="hasMasterToken"
                        :t="t"
                        :saving="saving"
                        :error="message"
                        @save="saveMunicipality"
                        @refresh="refreshMunicipality"
                        @search="searchMunicipality"
                    />
                    <ul v-if="searchResults.length" class="list-group mt-2">
                        <li
                            v-for="item in searchResults"
                            :key="item.codigo_municipio"
                            class="list-group-item d-flex justify-content-between align-items-center gap-2"
                        >
                            <span>{{ item.nome_municipio }} — {{ item.sigla_uf }}</span
                            ><button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                @click="saveMunicipality(item.codigo_municipio)"
                            >
                                {{ t.municipality?.save }}
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
            <NfseDocumentsTable
                :documents="documents"
                :t="t"
                :busy-id="busyId"
                :action-success="actionSuccess"
                @action="documentAction"
            />
            <div v-if="pending_invoices.length" class="card mt-4">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0">{{ t.page?.pending_invoices }}</h2>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ t.pending_invoices?.reference }}</th>
                                <th>{{ t.pending_invoices?.entity }}</th>
                                <th>{{ t.pending_invoices?.amount }}</th>
                                <th>{{ t.pending_invoices?.paid_at }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="invoice in pending_invoices" :key="invoice.id">
                                <td>{{ invoice.reference }}</td>
                                <td>{{ invoice.entity?.name }}</td>
                                <td>{{ money(invoice.amount, invoice.currency ?? 'BRL') }}</td>
                                <td>{{ dateTime(invoice.paid_at) }}</td>
                                <td class="text-end">
                                    <button
                                        v-if="permissions.can_issue"
                                        type="button"
                                        class="btn btn-sm btn-primary"
                                        @click="issuePending(invoice)"
                                    >
                                        {{ t.pending_invoices?.issue }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="entities_missing_ibge.length" class="card mt-4">
                <div class="card-header bg-transparent">
                    <h2 class="h6 mb-0">{{ t.page?.entities_missing_ibge }}</h2>
                </div>
                <div class="table-responsive">
                    <table class="table align-middle mb-0">
                        <thead>
                            <tr>
                                <th>{{ t.entities_missing_ibge?.entity }}</th>
                                <th>{{ t.entities_missing_ibge?.city }}</th>
                                <th>{{ t.entities_missing_ibge?.state }}</th>
                                <th>{{ t.entities_missing_ibge?.ibge_code }}</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="entity in entities_missing_ibge" :key="entity.id">
                                <td>{{ entity.name }}</td>
                                <td>{{ entity.city }}</td>
                                <td>{{ entity.state }}</td>
                                <td>
                                    <input
                                        v-model="entity.ibge_code"
                                        class="form-control form-control-sm"
                                        inputmode="numeric"
                                        maxlength="7"
                                        :aria-label="`${t.entities_missing_ibge?.ibge_code}: ${entity.name}`"
                                    />
                                </td>
                                <td class="text-end">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        @click="saveEntityIbge(entity, entity.ibge_code)"
                                    >
                                        {{ t.entities_missing_ibge?.save }}
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div></AppLayout
    >
</template>
