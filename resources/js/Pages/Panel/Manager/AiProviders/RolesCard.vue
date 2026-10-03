<script setup>
import { computed, ref, watch } from 'vue';

/**
 * Papéis do assistente de IA: quem gera (principal), quem confere (revisor) e
 * quem desempata (árbitro). Só provedores configurados (chave no .env +
 * modelo) e liberados para dados de pacientes (LGPD) entram na escolha; a
 * mudança vale na hora para todas as clínicas.
 */
const props = defineProps({
    roles: { type: Object, default: () => ({}) }, // {primary, reviewer, adjudicator}
    providers: { type: Array, default: () => [] }, // providerCards()
    modes: { type: Array, default: () => [] }, // {value, label, needs}
    t: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['saved']);

const form = ref(fromRoles(props.roles));
const saving = ref(false);

function fromRoles(roles) {
    return {
        primary: roles?.primary ?? null,
        reviewer: roles?.reviewer ?? null,
        adjudicator: roles?.adjudicator ?? null,
    };
}

// Salvou (ou outra aba mudou): recarga das props traz os papéis gravados.
watch(
    () => props.roles,
    (roles) => (form.value = fromRoles(roles)),
);

function tr(key, fallback, replace = {}) {
    let text = props.t[key] ?? fallback;
    for (const [k, v] of Object.entries(replace)) text = text.replace(`:${k}`, v);
    return text;
}

const byCode = computed(() => Object.fromEntries(props.providers.map((p) => [p.code, p])));
const selectable = computed(() => props.providers.filter((p) => p.configured && p.lgpd?.patients !== false));
// Bloqueado (LGPD) que sobrou num papel salvo: aparece desabilitado para o admin ver e trocar.
const blockedIn = (key) => {
    const p = byCode.value[form.value[key]];
    return p?.lgpd?.patients === false ? p : null;
};
const savedCodes = computed(() => Object.values(props.roles ?? {}).filter(Boolean));
const assigned = computed(() => [
    ...new Set([form.value.primary, form.value.reviewer, form.value.adjudicator].filter(Boolean)),
]);

const ROLES = [
    { key: 'primary', icon: 'ti-bolt', title: 'role_primary', hint: 'role_primary_hint', required: true },
    {
        key: 'reviewer',
        icon: 'ti-eye-check',
        title: 'role_reviewer_title',
        hint: 'role_reviewer_hint',
        required: false,
    },
    {
        key: 'adjudicator',
        icon: 'ti-scale',
        title: 'role_adjudicator_title',
        hint: 'role_adjudicator_hint',
        required: false,
    },
];

// Validação viva (espelha o servidor): o admin vê o problema ANTES de salvar.
const problems = computed(() => {
    const f = form.value;
    const list = [];
    if (!f.primary) list.push(tr('error_empty', 'Defina o provedor principal.'));
    if (f.adjudicator && !f.reviewer) list.push(tr('error_adjudicator_without_reviewer', 'Árbitro exige um revisor.'));
    if (
        (f.reviewer && f.reviewer === f.primary) ||
        (f.adjudicator && [f.primary, f.reviewer].includes(f.adjudicator))
    ) {
        list.push(tr('error_duplicate_role', 'Cada papel precisa de um provedor diferente.'));
    }
    for (const code of assigned.value) {
        const p = byCode.value[code];
        if (p?.lgpd?.patients === false) {
            list.push(
                tr('problem_blocked', ':provider: bloqueado para dados de pacientes (LGPD).', { provider: p.label }),
            );
        }
        if (transferMissing(p)) {
            list.push(
                tr('problem_transfer', ':provider: registre o mecanismo de transferência.', { provider: p.label }),
            );
        }
        if (p && !p.price_ok) list.push(`${p.label}: ${tr('price_missing', 'modelo sem preço cadastrado')}`);
        if (p?.model_unlisted_at) {
            list.push(
                tr('problem_unlisted', ':provider: :model', {
                    provider: p.label,
                    model: p.model,
                    date: p.model_unlisted_at,
                }),
            );
        }
    }
    return list;
});

// Espelha o servidor: provedor que PASSA a levar dado de paciente para fora
// do Brasil precisa do mecanismo registrado; quem já estava salvo segue.
function transferMissing(p) {
    return !!p?.lgpd?.needs_record && !p.lgpd.record && !savedCodes.value.includes(p.code);
}

const lgpdBlocked = computed(() =>
    assigned.value.some((code) => {
        const p = byCode.value[code];
        return p?.lgpd?.patients === false || transferMissing(p);
    }),
);

const canSave = computed(() => {
    const f = form.value;
    return (
        !saving.value &&
        !lgpdBlocked.value &&
        !!f.primary &&
        !(f.adjudicator && !f.reviewer) &&
        !(f.reviewer && f.reviewer === f.primary) &&
        !(f.adjudicator && [f.primary, f.reviewer].includes(f.adjudicator))
    );
});

// Prévia dos modos: escala com o nº de papéis preenchidos.
const previewModes = computed(() => props.modes.map((m) => ({ ...m, available: assigned.value.length >= m.needs })));

// Limpar o revisor arrasta o árbitro junto (consenso exige os três).
function onRoleChange(key) {
    if (key === 'reviewer' && !form.value.reviewer) form.value.adjudicator = null;
}

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function save() {
    if (!canSave.value) return;
    saving.value = true;
    try {
        const res = await fetch(route('manager.ai-providers.update'), {
            method: 'PATCH',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
            body: JSON.stringify({
                primary: form.value.primary,
                reviewer: form.value.reviewer || null,
                adjudicator: form.value.adjudicator || null,
            }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            window.showErrorToast?.(
                json.message ?? Object.values(json.errors ?? {}).flat()[0] ?? tr('test_failed', 'Erro'),
            );
            return;
        }
        window.showSuccessToast?.(json.message);
        emit('saved');
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="card mb-3" data-roles-card>
        <div class="card-header bg-transparent">
            <div class="fw-semibold">{{ tr('roles_title', 'Papéis do assistente') }}</div>
            <div class="text-muted small">{{ t.roles_subtitle }}</div>
        </div>
        <div class="card-body">
            <div v-if="problems.length" class="alert alert-warning py-2 small" role="alert" data-role-problems>
                <div v-for="(problem, i) in problems" :key="i">
                    <i class="ti ti-alert-triangle me-1" aria-hidden="true"></i>{{ problem }}
                </div>
            </div>

            <div class="row g-3">
                <div v-for="role in ROLES" :key="role.key" class="col-12 col-md-4">
                    <label class="form-label fw-semibold mb-1" :for="`role-${role.key}`">
                        <i :class="`ti ${role.icon} me-1 text-primary`" aria-hidden="true"></i
                        >{{ tr(role.title, role.key) }}
                        <span v-if="role.required" class="text-danger" aria-hidden="true">*</span>
                    </label>
                    <select
                        :id="`role-${role.key}`"
                        v-model="form[role.key]"
                        class="form-select"
                        :data-role="role.key"
                        :aria-describedby="`role-${role.key}-hint`"
                        :required="role.required"
                        @change="onRoleChange(role.key)"
                    >
                        <option v-if="!role.required" :value="null">{{ tr('role_none', '— Nenhum —') }}</option>
                        <option v-else-if="!form.primary" :value="null" disabled>—</option>
                        <option v-if="blockedIn(role.key)" :value="blockedIn(role.key).code" disabled>
                            {{ blockedIn(role.key).label }} ({{ tr('option_blocked', 'bloqueado — LGPD') }})
                        </option>
                        <option v-for="p in selectable" :key="p.code" :value="p.code">
                            {{ p.label }}{{ p.model ? ` · ${p.model}` : '' }}
                        </option>
                    </select>
                    <div :id="`role-${role.key}-hint`" class="form-text">{{ tr(role.hint, '') }}</div>
                </div>
            </div>
        </div>
        <div class="card-footer bg-transparent d-flex flex-wrap align-items-center gap-2">
            <div class="d-flex flex-wrap align-items-center gap-2 me-auto small">
                <span class="text-muted">{{ tr('available_modes', 'Modos disponíveis') }}:</span>
                <span
                    v-for="m in previewModes"
                    :key="m.value"
                    class="badge rounded fs-12 fw-medium"
                    :class="
                        m.available ? 'badge-soft-success text-success border border-success' : 'badge-soft-secondary'
                    "
                    :title="tr('mode_needs', 'requer :n provedor(es)').replace(':n', m.needs)"
                    :data-mode="m.value"
                    :data-available="String(m.available)"
                >
                    <i :class="`ti ${m.available ? 'ti-circle-check' : 'ti-circle-x'} me-1`" aria-hidden="true"></i
                    >{{ m.label }}
                </span>
            </div>
            <button type="button" class="btn btn-primary" :disabled="!canSave" data-roles-save @click="save">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                <i v-else class="ti ti-check me-1" aria-hidden="true"></i>{{ tr('save_roles', 'Salvar papéis') }}
            </button>
        </div>
    </div>
</template>
