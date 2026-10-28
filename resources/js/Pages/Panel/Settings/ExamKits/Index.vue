<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import ExamKitEditor from '@/Components/Panel/Documents/ExamKitEditor.vue';
import { td, fill } from '@/Support/clinicalDocuments.js';

/**
 * Configurações › Kits de exames (Fase 2 / B2): kits da clínica (criar,
 * editar, excluir, vincular a convênios — nenhum = todos) e kits padrão do
 * EasyEye para copiar e ajustar. O pedido de exames do prontuário mostra os
 * kits do convênio do atendimento.
 */
defineOptions({ name: 'SettingsExamKitsIndex' });

const props = defineProps({
    clinicKits: { type: Array, default: () => [] },
    globalKits: { type: Array, default: () => [] },
    covenants: { type: Array, default: () => [] },
    maxItems: { type: Number, default: 20 },
    t: { type: Object, default: () => ({}) },
});

const tt = (path, fallback) => td(props.t, path, fallback);
// Exames do catálogo de procedimentos da clínica (busca do pedido de exames).
const procedureSearchUrl = route('panel.procedures.search');

const editor = ref({ open: false, kit: null, busy: false, errors: {} });
const confirming = ref(null);
const busyId = ref('');
const flash = ref('');
const error = ref('');

const sourceName = (kit) => props.globalKits.find((g) => g.id === kit.source_kit_id)?.name ?? '';

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function send(method, url, body = null) {
    const res = await fetch(url, {
        method,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf() },
        body: body ? JSON.stringify(body) : undefined,
    });
    return { ok: res.ok, json: await res.json().catch(() => ({})) };
}

function notify(message) {
    flash.value = message;
    error.value = '';
    window.showSuccessToast?.(message);
    router.reload({ only: ['clinicKits', 'globalKits'] });
}

function openEditor(kit = null) {
    editor.value = { open: true, kit, busy: false, errors: {} };
}

async function save(payload) {
    editor.value.busy = true;
    editor.value.errors = {};
    const kit = editor.value.kit;
    const { ok, json } = kit
        ? await send('PUT', route('panel.setting.exam-kits.update', kit.id), payload)
        : await send('POST', route('panel.setting.exam-kits.store'), payload);
    editor.value.busy = false;
    if (!ok) {
        editor.value.errors = json.errors ?? { name: [json.message ?? ''] };
        return;
    }
    editor.value.open = false;
    notify(json.message);
}

async function copy(kit) {
    busyId.value = kit.id;
    const { ok, json } = await send('POST', route('panel.setting.exam-kits.copy', kit.id));
    busyId.value = '';
    if (!ok) {
        error.value = json.errors ? Object.values(json.errors)[0][0] : (json.message ?? '');
        return;
    }
    notify(json.message);
}

async function destroy(kit) {
    busyId.value = kit.id;
    const { ok, json } = await send('DELETE', route('panel.setting.exam-kits.destroy', kit.id));
    busyId.value = '';
    confirming.value = null;
    if (!ok) {
        error.value = json.message ?? '';
        return;
    }
    notify(json.message);
}

const empty = computed(() => !props.clinicKits.length);
</script>

<template>
    <AppLayout :title="tt('kits.title', 'Kits de exames')" :breadcrumbs="[]">
        <div class="container-fluid py-3 ee-kits">
            <PageHeader :title="tt('kits.title', 'Kits de exames')" :subtitle="tt('kits.subtitle', '')">
                <template #actions>
                    <button
                        type="button"
                        class="btn btn-primary btn-sm"
                        data-testid="exam-kit-new"
                        @click="openEditor()"
                    >
                        <i class="ti ti-plus me-1" aria-hidden="true"></i>{{ tt('kits.new', 'Novo kit') }}
                    </button>
                </template>
            </PageHeader>

            <div v-if="error" class="alert alert-danger py-2" role="alert">{{ error }}</div>
            <div class="visually-hidden" role="status" aria-live="polite">{{ flash }}</div>

            <!-- Kits da clínica -->
            <section class="mb-4" aria-labelledby="ee-kits-clinic">
                <h2 id="ee-kits-clinic" class="h6 fw-semibold mb-2">{{ tt('kits.clinic', 'Kits da clínica') }}</h2>
                <p v-if="empty" class="text-body-secondary small">
                    {{ tt('kits.empty_clinic', 'A clínica ainda não tem kits próprios.') }}
                </p>
                <div v-else class="row g-2">
                    <div v-for="kit in clinicKits" :key="kit.id" class="col-12 col-md-6 col-xl-4">
                        <article class="card h-100 ee-kits__card" :data-testid="`clinic-kit-${kit.id}`">
                            <div class="card-body p-3">
                                <div class="d-flex align-items-start gap-2 mb-1">
                                    <h3 class="h6 mb-0 flex-grow-1 text-break">{{ kit.name }}</h3>
                                    <span
                                        class="badge"
                                        :class="
                                            kit.active
                                                ? 'bg-success-subtle text-success-emphasis'
                                                : 'bg-secondary-subtle text-secondary-emphasis'
                                        "
                                        >{{
                                            kit.active ? tt('kits.active', 'Ativo') : tt('kits.inactive', 'Inativo')
                                        }}</span
                                    >
                                </div>
                                <p v-if="kit.source_kit_id && sourceName(kit)" class="small text-body-secondary mb-1">
                                    {{ fill(tt('kits.source', 'Cópia do padrão ":name"'), { name: sourceName(kit) }) }}
                                </p>
                                <ul class="small mb-2 ps-3">
                                    <li v-for="item in kit.items" :key="item.id">
                                        {{ item.name }}
                                        <span class="text-body-secondary"
                                            >— {{ item.tuss_code || tt('kits.no_code', 'sem código') }}</span
                                        >
                                    </li>
                                </ul>
                                <div class="small">
                                    <span class="fw-semibold">{{ tt('kits.covenants', 'Convênios') }}:</span>
                                    <template v-if="kit.covenants.length">
                                        <span
                                            v-for="c in kit.covenants"
                                            :key="c.id"
                                            class="badge bg-primary-subtle text-primary-emphasis ms-1"
                                            >{{ c.name
                                            }}<template v-if="!c.active">
                                                ({{ tt('kits.covenant_inactive', 'inativo') }})</template
                                            ></span
                                        >
                                    </template>
                                    <span v-else class="ms-1">{{
                                        tt('kits.covenants_all', 'Todos os convênios')
                                    }}</span>
                                </div>
                            </div>
                            <div class="card-footer d-flex gap-2 flex-wrap align-items-center">
                                <template v-if="confirming === kit.id">
                                    <span class="small flex-grow-1">{{
                                        fill(tt('kits.delete_confirm', 'Excluir o kit ":name"?'), { name: kit.name })
                                    }}</span>
                                    <button
                                        type="button"
                                        class="btn btn-danger btn-sm"
                                        :disabled="busyId === kit.id"
                                        @click="destroy(kit)"
                                    >
                                        {{ tt('kits.delete', 'Excluir') }}
                                    </button>
                                    <button type="button" class="btn btn-light btn-sm" @click="confirming = null">
                                        {{ tt('kits.cancel', 'Cancelar') }}
                                    </button>
                                </template>
                                <template v-else>
                                    <button
                                        type="button"
                                        class="btn btn-outline-primary btn-sm"
                                        @click="openEditor(kit)"
                                    >
                                        <i class="ti ti-pencil me-1" aria-hidden="true"></i
                                        >{{ tt('kits.edit', 'Editar kit') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-outline-danger btn-sm ms-auto"
                                        :aria-label="`${tt('kits.delete', 'Excluir')}: ${kit.name}`"
                                        @click="confirming = kit.id"
                                    >
                                        <i class="ti ti-trash" aria-hidden="true"></i>
                                    </button>
                                </template>
                            </div>
                        </article>
                    </div>
                </div>
            </section>

            <!-- Kits padrão do EasyEye -->
            <section aria-labelledby="ee-kits-global">
                <h2 id="ee-kits-global" class="h6 fw-semibold mb-1">
                    {{ tt('kits.global', 'Kits padrão do EasyEye') }}
                </h2>
                <p class="text-body-secondary small mb-2">{{ tt('kits.global_hint', '') }}</p>
                <p v-if="!globalKits.length" class="text-body-secondary small">{{ tt('kits.empty_global', '') }}</p>
                <div v-else class="row g-2">
                    <div v-for="kit in globalKits" :key="kit.id" class="col-12 col-md-6 col-xl-4">
                        <article class="card h-100 ee-kits__card" :data-testid="`global-kit-${kit.id}`">
                            <div class="card-body p-3">
                                <h3 class="h6 mb-1 text-break">{{ kit.name }}</h3>
                                <p v-if="kit.description" class="small text-body-secondary mb-1">
                                    {{ kit.description }}
                                </p>
                                <ul class="small mb-0 ps-3">
                                    <li v-for="item in kit.items" :key="item.id">
                                        {{ item.name }}
                                        <span class="text-body-secondary"
                                            >— {{ item.tuss_code || tt('kits.no_code', 'sem código') }}</span
                                        >
                                    </li>
                                </ul>
                            </div>
                            <div class="card-footer">
                                <button
                                    type="button"
                                    class="btn btn-sm"
                                    :class="kit.copied ? 'btn-light' : 'btn-outline-primary'"
                                    :disabled="kit.copied || busyId === kit.id"
                                    :title="kit.copied ? tt('kits.already_copied', 'Já copiado') : ''"
                                    :data-testid="`copy-kit-${kit.id}`"
                                    @click="copy(kit)"
                                >
                                    <i class="ti" :class="kit.copied ? 'ti-check' : 'ti-copy'" aria-hidden="true"></i>
                                    {{
                                        kit.copied
                                            ? tt('kits.already_copied', 'Já copiado')
                                            : tt('kits.copy', 'Copiar para a clínica')
                                    }}
                                </button>
                            </div>
                        </article>
                    </div>
                </div>
            </section>
        </div>

        <ExamKitEditor
            :open="editor.open"
            :kit="editor.kit"
            with-covenants
            :covenants="covenants"
            :procedure-search-url="procedureSearchUrl"
            :max-items="maxItems"
            :busy="editor.busy"
            :errors="editor.errors"
            :t="t"
            @close="editor.open = false"
            @save="save"
        />
    </AppLayout>
</template>

<style scoped>
.ee-kits__card .card-footer {
    background: transparent;
}
</style>
