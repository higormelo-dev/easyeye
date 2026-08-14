<script setup>
import { computed } from 'vue';
import ActionDropdown  from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import { useUserPreferences } from '@/composables/useUserPreferences.js';

const props = defineProps({
    rule: { type: String, default: '' },
    t:    { type: Object, required: true },
});

const isAdminOrFinancial = computed(() =>
    ['admin', 'financial'].includes(props.rule),
);

const modules = computed(() => {
    const all = [
        {
            key:       'schedule',
            label:     props.t.module_schedule,
            icon:      'ti ti-calendar',
            iconClass: 'module-icon--schedule',
            url:       route('panel.schedules.index'),
            soon:      false,
        },
        {
            key:       'eye-images',
            label:     props.t.module_eye_images,
            icon:      'ti ti-eye',
            iconClass: 'module-icon',
            url:       route('panel.eye-images.index'),
            soon:      false,
        },
        ...(isAdminOrFinancial.value
            ? [
                {
                    key:       'tiss',
                    label:     props.t.module_tiss,
                    icon:      'ti ti-file-invoice',
                    iconClass: 'module-icon--tiss',
                    url:       route('panel.financial.billing.index'),
                    soon:      false,
                },
                {
                    key:       'financial',
                    label:     props.t.module_financial,
                    icon:      'ti ti-report-money',
                    iconClass: 'module-icon--financial',
                    url:       route('panel.financial.cash-flow.index'),
                    soon:      false,
                },
            ]
            : []
        ),
        {
            key:       'surgery',
            label:     props.t.module_surgery,
            icon:      'ti ti-stethoscope',
            iconClass: 'module-icon--soon',
            url:       null,
            soon:      true,
        },
    ];
    return all;
});

// ── Atalhos favoritos (item MELHORIA "mais humano") ──────────────────────────
// Preferência guarda [{key, hidden}] na ordem escolhida. Módulos que o
// usuário nunca viu ainda (nova feature liberada pro papel dele, ou
// preferência salva antes de mudar de role) entram no final, visíveis —
// nunca somem por conta de uma preferência desatualizada.
const { getPreference, savePreference } = useUserPreferences();

const orderedModules = computed(() => {
    const base  = modules.value;
    const saved = getPreference('favorite_shortcuts');

    if (!Array.isArray(saved) || saved.length === 0) return base;

    const byKey       = Object.fromEntries(base.map((m) => [m.key, m]));
    const savedKeys   = saved.map((s) => s.key).filter((k) => byKey[k]);
    const missingKeys = base.map((m) => m.key).filter((k) => !savedKeys.includes(k));

    return [...savedKeys, ...missingKeys].map((key) => ({
        ...byKey[key],
        hidden: saved.find((s) => s.key === key)?.hidden ?? false,
    }));
});

const visibleModules = computed(() => orderedModules.value.filter((m) => !m.hidden));

function persistShortcuts(list) {
    savePreference('favorite_shortcuts', list.map((m) => ({ key: m.key, hidden: !!m.hidden })));
}

function moveShortcut(fromIndex, toIndex) {
    if (fromIndex === toIndex || fromIndex < 0 || toIndex < 0) return;
    if (fromIndex >= orderedModules.value.length || toIndex >= orderedModules.value.length) return;

    const next = [...orderedModules.value];
    const [moved] = next.splice(fromIndex, 1);
    next.splice(toIndex, 0, moved);
    persistShortcuts(next);
}

function toggleShortcut(key) {
    persistShortcuts(orderedModules.value.map((m) => (
        m.key === key ? { ...m, hidden: !m.hidden } : m
    )));
}

function resetShortcuts() {
    savePreference('favorite_shortcuts', []);
}
</script>

<template>
    <div class="d-flex justify-content-end mb-1">
        <ActionDropdown
            title="Escolher atalhos favoritos"
            align="right"
            :min-width="230"
            btn-class="btn btn-sm btn-link text-muted text-decoration-none p-0"
        >
            <template #trigger>
                <i class="ti ti-adjustments-horizontal me-1"></i>
                <span class="fs-12">Atalhos</span>
            </template>

            <ColumnOrderMenu
                title="Atalhos favoritos"
                :columns="orderedModules"
                toggleable
                @move="moveShortcut"
                @toggle="toggleShortcut"
                @reset="resetShortcuts"
            />
        </ActionDropdown>
    </div>

    <div class="row g-3 mb-4">
        <div v-for="mod in visibleModules" :key="mod.key" class="col-6 col-sm-4 col-md-2">
            <component
                :is="mod.soon ? 'div' : 'a'"
                :href="mod.soon ? undefined : mod.url"
                :class="['module-shortcut w-100', mod.soon ? 'disabled' : '']"
            >
                <span v-if="mod.soon" class="badge-soon">{{ t.coming_soon }}</span>
                <span :class="`ms-icon ${mod.iconClass}`">
                    <i :class="mod.icon"></i>
                </span>
                <span>{{ mod.label }}</span>
            </component>
        </div>
    </div>
</template>
