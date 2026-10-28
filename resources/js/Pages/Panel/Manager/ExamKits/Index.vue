<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';
import ConfirmationWithReasonModal from '@/Components/Panel/ConfirmationWithReasonModal.vue';
import ExamKitEditor from '@/Components/Panel/Documents/ExamKitEditor.vue';
import { td, fill } from '@/Support/clinicalDocuments.js';

/**
 * Manager → Kits de exames PADRÃO do EasyEye (Fase 2 / B2). Todas as
 * clínicas veem no pedido de exames; cada uma pode copiar e ajustar (a cópia
 * não muda quando o padrão muda). Exclusão com justificativa + trilha.
 */
defineOptions({ name: 'ManagerExamKitsIndex' });

const props = defineProps({
    kits: { type: Array, default: () => [] },
    maxItems: { type: Number, default: 20 },
    t: { type: Object, default: () => ({}) },
});

const tt = (path, fallback) => td(props.t, path, fallback);

const editor = ref({ open: false, kit: null, busy: false, errors: {} });
const removing = ref({ open: false, kit: null, saving: false, error: '' });

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

function openEditor(kit = null) {
    editor.value = { open: true, kit, busy: false, errors: {} };
}

async function save(payload) {
    editor.value.busy = true;
    editor.value.errors = {};
    const kit = editor.value.kit;
    const { ok, json } = kit
        ? await send('PUT', route('manager.exam-kits.update', kit.id), payload)
        : await send('POST', route('manager.exam-kits.store'), payload);
    editor.value.busy = false;
    if (!ok) {
        editor.value.errors = json.errors ?? { name: [json.message ?? ''] };
        return;
    }
    editor.value.open = false;
    window.showSuccessToast?.(json.message);
    router.reload({ only: ['kits'] });
}

async function confirmRemove(reason) {
    removing.value.saving = true;
    removing.value.error = '';
    const { ok, json } = await send('DELETE', route('manager.exam-kits.destroy', removing.value.kit.id), { reason });
    removing.value.saving = false;
    if (!ok) {
        removing.value.error = json.errors?.reason?.[0] ?? json.message ?? '';
        return;
    }
    removing.value.open = false;
    window.showSuccessToast?.(json.message);
    router.reload({ only: ['kits'] });
}
</script>

<template>
    <AppLayout :title="tt('kits.manager_title', 'Kits de exames padrão')" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader
                :title="tt('kits.manager_title', 'Kits de exames padrão')"
                :subtitle="tt('kits.manager_subtitle', '')"
            >
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

            <p v-if="!kits.length" class="text-body-secondary small">{{ tt('kits.empty_global', '') }}</p>
            <div v-else class="row g-2">
                <div v-for="kit in kits" :key="kit.id" class="col-12 col-md-6 col-xl-4">
                    <article class="card h-100" :data-testid="`manager-kit-${kit.id}`">
                        <div class="card-body p-3">
                            <div class="d-flex align-items-start gap-2 mb-1">
                                <h2 class="h6 mb-0 flex-grow-1 text-break">{{ kit.name }}</h2>
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
                            <p v-if="kit.description" class="small text-body-secondary mb-1">{{ kit.description }}</p>
                            <ul class="small mb-0 ps-3">
                                <li v-for="item in kit.items" :key="item.id">
                                    {{ item.name }}
                                    <span class="text-body-secondary"
                                        >— {{ item.tuss_code || tt('kits.no_code', 'sem código') }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                        <div class="card-footer d-flex gap-2 bg-transparent">
                            <button type="button" class="btn btn-outline-primary btn-sm" @click="openEditor(kit)">
                                <i class="ti ti-pencil me-1" aria-hidden="true"></i>{{ tt('kits.edit', 'Editar kit') }}
                            </button>
                            <button
                                type="button"
                                class="btn btn-outline-danger btn-sm ms-auto"
                                :aria-label="`${tt('kits.delete', 'Excluir')}: ${kit.name}`"
                                @click="removing = { open: true, kit, saving: false, error: '' }"
                            >
                                <i class="ti ti-trash" aria-hidden="true"></i>
                            </button>
                        </div>
                    </article>
                </div>
            </div>
        </div>

        <ExamKitEditor
            :open="editor.open"
            :kit="editor.kit"
            :max-items="maxItems"
            :busy="editor.busy"
            :errors="editor.errors"
            :t="t"
            @close="editor.open = false"
            @save="save"
        />

        <ConfirmationWithReasonModal
            :open="removing.open"
            :title="tt('kits.delete', 'Excluir')"
            :message="
                removing.kit ? fill(tt('kits.delete_confirm', 'Excluir o kit :name?'), { name: removing.kit.name }) : ''
            "
            :saving="removing.saving"
            :error="removing.error"
            @close="removing.open = false"
            @confirm="confirmRemove"
        />
    </AppLayout>
</template>
