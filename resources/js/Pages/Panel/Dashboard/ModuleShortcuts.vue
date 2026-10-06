<script setup>
import { computed } from 'vue';
import ActionDropdown from '@/Components/Panel/ActionDropdown.vue';
import ColumnOrderMenu from '@/Components/Panel/ColumnOrderMenu.vue';
import { useUserPreferences } from '@/composables/useUserPreferences.js';

const props = defineProps({
    // Telas que o usuário pode abrir (PanelDashboardController::buildAccess —
    // mesmas regras das rotas): atalho sem acesso não aparece.
    access: { type: Object, default: () => ({}) },
    orderLabels: { type: Object, default: () => ({}) },
    // Atalhos "Em breve" só para quem vai usar o módulo (administração e
    // recepção) — o médico e os demais perfis não veem promessa de módulo.
    showSoon: { type: Boolean, default: true },
    profile: { type: String, default: '' },
    t: { type: Object, required: true },
});

const modules = computed(() => {
    const a = props.access;
    const tile = (key, label, icon, iconClass, url) => ({ key, label, icon, iconClass, url, soon: false });

    const clinical = [
        a.schedules && tile('schedule', props.t.module_schedule, 'ti ti-calendar', 'module-icon--schedule', route('panel.schedules.index')),
        a.patients && tile('patients', props.t.module_patients, 'ti ti-users', 'module-icon--patients', route('panel.patients.index')),
        tile('eye-images', props.t.module_eye_images, 'ti ti-eye', 'module-icon--eye', route('panel.eye-images.index')),
    ];
    const financial = a.financial
        ? [
              tile('financial', props.t.module_financial, 'ti ti-report-money', 'module-icon--financial', route('panel.financial.cash-flow.index')),
              tile('tiss', props.t.module_tiss, 'ti ti-file-invoice', 'module-icon--tiss', route('panel.financial.billing.index')),
              tile('glosas', props.t.module_glosas, 'ti ti-file-x', 'module-icon--glosas', route('panel.financial.tiss.glosas.index')),
              tile('bi', props.t.module_bi, 'ti ti-chart-bar', 'module-icon--bi', route('panel.financial.bi.index')),
          ]
        : [];
    const soon = props.showSoon
        ? [{ key: 'surgery', label: props.t.module_surgery, icon: 'ti ti-stethoscope', iconClass: 'module-icon--soon', url: null, soon: true }]
        : [];

    // Financeiro: os módulos do posto de trabalho dele primeiro.
    const ordered = props.profile === 'financial' ? [...financial, ...clinical] : [...clinical, ...financial];

    return [...ordered, ...soon].filter(Boolean);
});

// ── Atalhos favoritos (item MELHORIA "mais humano") ──────────────────────────
// Preferência guarda [{key, hidden}] na ordem escolhida. Módulos que o
// usuário nunca viu ainda (nova feature liberada pro papel dele, ou
// preferência salva antes de mudar de role) entram no final, visíveis —
// nunca somem por conta de uma preferência desatualizada.
const { getPreference, savePreference } = useUserPreferences();

const orderedModules = computed(() => {
    const base = modules.value;
    const saved = getPreference('favorite_shortcuts');

    if (!Array.isArray(saved) || saved.length === 0) return base;

    const byKey = Object.fromEntries(base.map((m) => [m.key, m]));
    const savedKeys = saved.map((s) => s.key).filter((k) => byKey[k]);
    const missingKeys = base.map((m) => m.key).filter((k) => !savedKeys.includes(k));

    return [...savedKeys, ...missingKeys].map((key) => ({
        ...byKey[key],
        hidden: saved.find((s) => s.key === key)?.hidden ?? false,
    }));
});

const visibleModules = computed(() => orderedModules.value.filter((m) => !m.hidden));

function persistShortcuts(list) {
    savePreference(
        'favorite_shortcuts',
        list.map((m) => ({ key: m.key, hidden: !!m.hidden })),
    );
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
    persistShortcuts(orderedModules.value.map((m) => (m.key === key ? { ...m, hidden: !m.hidden } : m)));
}

function resetShortcuts() {
    savePreference('favorite_shortcuts', []);
}
</script>

<template>
    <section class="db-section" :aria-label="t.section_shortcuts">
        <header class="db-section-head db-section-head--row">
            <h2 class="db-section-title">{{ t.section_shortcuts }}</h2>
            <div data-tour="dashboard-shortcuts-customize">
                <ActionDropdown
                    :title="t.shortcuts_title ?? 'Escolher atalhos favoritos'"
                    align="right"
                    :min-width="230"
                    btn-class="btn btn-sm btn-link text-muted text-decoration-none p-0"
                >
                    <template #trigger>
                        <i class="ti ti-adjustments-horizontal me-1" aria-hidden="true"></i>
                        <span class="fs-12">{{ t.shortcuts ?? 'Atalhos' }}</span>
                    </template>

                    <ColumnOrderMenu
                        :title="t.shortcuts_menu ?? 'Atalhos favoritos'"
                        :columns="orderedModules"
                        :labels="orderLabels"
                        toggleable
                        @move="moveShortcut"
                        @toggle="toggleShortcut"
                        @reset="resetShortcuts"
                    />
                </ActionDropdown>
            </div>
        </header>

        <div class="db-shortcuts" data-tour="dashboard-shortcuts">
            <component
                :is="mod.soon ? 'div' : 'a'"
                v-for="mod in visibleModules"
                :key="mod.key"
                :href="mod.soon ? undefined : mod.url"
                :class="['module-shortcut', mod.soon ? 'disabled' : '']"
                :aria-disabled="mod.soon ? 'true' : undefined"
                :data-module="mod.key"
            >
                <span :class="`ms-icon ${mod.iconClass}`" aria-hidden="true">
                    <i :class="mod.icon"></i>
                </span>
                <span class="module-shortcut__label">
                    {{ mod.label }}
                    <span v-if="mod.soon" class="badge-soon">{{ t.coming_soon }}</span>
                </span>
                <i v-if="!mod.soon" class="ti ti-chevron-right module-shortcut__chevron" aria-hidden="true"></i>
            </component>
        </div>
    </section>
</template>
