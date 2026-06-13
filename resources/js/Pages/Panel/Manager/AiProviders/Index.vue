<script setup>
import { ref, computed } from 'vue';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

const props = defineProps({
    providers:    { type: Array,  default: () => [] }, // {code,label,enabled,order,configured,model}
    modes:        { type: Array,  default: () => [] }, // {value,label,available,needs}
    enabledCount: { type: Number, default: 0 },
    t:            { type: Object, default: () => ({}) },
});

const saving = ref(false);

// Lista de trabalho (cópia editável). Ativos primeiro, na ordem de prioridade.
const rows = ref(props.providers.map(p => ({ ...p })));

// Códigos ativos na ordem atual (= prioridade).
const enabledCodes = computed(() => rows.value.filter(r => r.enabled).map(r => r.code));

// Papel derivado da ordem (0=gerador, 1=revisor, 2=adjudicador).
function roleFor(index) {
    if (!rows.value[index]?.enabled) return null;
    const pos = enabledCodes.value.indexOf(rows.value[index].code);
    return [props.t.role_generator ?? 'Gerador',
            props.t.role_reviewer ?? 'Revisor',
            props.t.role_adjudicator ?? 'Adjudicador'][pos] ?? null;
}

// Prévia dos modos conforme a contagem ativa (espelha o backend).
const previewModes = computed(() => {
    const n = enabledCodes.value.length;
    return props.modes.map(m => ({ ...m, available: n >= m.needs }));
});

function toggle(row) {
    if (!row.configured) return;        // não pode ativar sem credencial
    row.enabled = !row.enabled;
    if (!row.enabled) reflow();         // ao desativar, joga para o fim
}

// Garante ativos contíguos no topo preservando a ordem relativa.
function reflow() {
    const enabled  = rows.value.filter(r => r.enabled);
    const disabled = rows.value.filter(r => !r.enabled);
    rows.value = [...enabled, ...disabled];
}

function move(index, dir) {
    const target = index + dir;
    if (target < 0 || target >= rows.value.length) return;
    const arr = rows.value;
    [arr[index], arr[target]] = [arr[target], arr[index]];
    rows.value = [...arr];
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function save() {
    saving.value = true;
    try {
        const res = await fetch(route('manager.ai-providers.update'), {
            method:  'PATCH',
            headers: {
                Accept:         'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ providers: enabledCodes.value }),
        });
        const json = await res.json();

        if (!res.ok) {
            showToast(json.message ?? 'Erro', 'error');
            return;
        }
        if (Array.isArray(json.providers)) rows.value = json.providers.map(p => ({ ...p }));
        showToast(json.message, 'success');
    } finally {
        saving.value = false;
    }
}

function showToast(msg, type = 'success') {
    if (type === 'success' && window.showSuccessToast) return window.showSuccessToast(msg);
    if (type === 'error'   && window.showErrorToast)   return window.showErrorToast(msg);
    alert(msg);
}

const breadcrumbs = [
    { label: 'Dashboard',                       url: route('manager.dashboard'), active: false },
    { label: props.t.breadcrumb ?? 'Provedores de IA', url: '#', active: true },
];
</script>

<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="t.title ?? 'Provedores de IA'">
                <template #actions>
                    <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="save">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-check me-1"></i>{{ t.save ?? 'Salvar' }}
                    </button>
                </template>
            </PageHeader>

            <p class="text-muted small mb-3">{{ t.subtitle }}</p>

            <div class="row g-3">
                <!-- Lista de provedores -->
                <div class="col-12 col-lg-8">
                    <div class="card border-0 shadow-sm">
                        <div class="card-body p-0">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width:90px;">{{ t.enabled ?? 'Ativo' }}</th>
                                        <th>{{ t.provider ?? 'Provedor' }}</th>
                                        <th>{{ t.model ?? 'Modelo' }}</th>
                                        <th>{{ t.priority ?? 'Prioridade' }}</th>
                                        <th style="width:90px;" class="text-end"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="(row, i) in rows" :key="row.code">
                                        <td>
                                            <div class="form-check form-switch mb-0">
                                                <input class="form-check-input" type="checkbox"
                                                       :checked="row.enabled"
                                                       :disabled="!row.configured"
                                                       @change="toggle(row)">
                                            </div>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">{{ row.label }}</div>
                                            <span v-if="row.configured"
                                                  class="badge bg-success-subtle text-success border border-success">
                                                <i class="ti ti-shield-check me-1"></i>{{ t.configured ?? 'Configurado' }}
                                            </span>
                                            <span v-else
                                                  class="badge bg-danger-subtle text-danger border border-danger"
                                                  :title="t.no_credential_hint">
                                                <i class="ti ti-alert-triangle me-1"></i>{{ t.not_configured ?? 'Sem credencial' }}
                                            </span>
                                        </td>
                                        <td><code class="small">{{ row.model ?? '—' }}</code></td>
                                        <td>
                                            <span v-if="roleFor(i)" class="badge bg-primary-subtle text-primary border border-primary">
                                                {{ enabledCodes.indexOf(row.code) + 1 }}º · {{ roleFor(i) }}
                                            </span>
                                            <span v-else class="text-muted small">—</span>
                                        </td>
                                        <td class="text-end">
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1"
                                                    :disabled="i === 0" :title="t.move_up" @click="move(i, -1)">
                                                <i class="ti ti-arrow-up"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1"
                                                    :disabled="i === rows.length - 1" :title="t.move_down" @click="move(i, 1)">
                                                <i class="ti ti-arrow-down"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Prévia dos modos disponíveis -->
                <div class="col-12 col-lg-4">
                    <div class="card border-0 shadow-sm">
                        <div class="card-header fw-semibold py-2">
                            <i class="ti ti-adjustments me-1 text-muted"></i>{{ t.available_modes ?? 'Modos disponíveis' }}
                        </div>
                        <ul class="list-group list-group-flush">
                            <li v-for="m in previewModes" :key="m.value"
                                class="list-group-item d-flex justify-content-between align-items-center">
                                <span>
                                    <i class="ti me-1"
                                       :class="m.available ? 'ti-circle-check text-success' : 'ti-circle-x text-muted'"></i>
                                    {{ m.label }}
                                </span>
                                <small class="text-muted">{{ (t.mode_needs ?? 'requer :n provedor(es)').replace(':n', m.needs) }}</small>
                            </li>
                        </ul>
                        <div class="card-footer small text-muted">
                            {{ enabledCodes.length }} {{ t.provider ?? 'Provedor' }}(es)
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
