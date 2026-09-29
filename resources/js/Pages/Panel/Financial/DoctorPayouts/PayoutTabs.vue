<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

/**
 * Abas do Repasse médico (Apuração, Fechamentos, Regras). São páginas, não
 * painéis: navegação por links Inertia e `aria-current="page"` na atual
 * (sem role=tab, que exigiria tabpanels na mesma página).
 */
const props = defineProps({
    tabs:    { type: Object, default: () => ({}) },   // { apuracao, closings, rules } → URLs
    current: { type: String, default: '' },
    t:       { type: Object, default: () => ({}) },
});

const TABS = [
    { key: 'apuracao', icon: 'ti ti-calculator' },
    { key: 'closings', icon: 'ti ti-lock' },
    { key: 'rules',    icon: 'ti ti-adjustments-dollar' },
];

const items = computed(() => TABS
    .filter((tab) => props.tabs?.[tab.key])
    .map((tab) => ({ ...tab, href: props.tabs[tab.key], label: props.t.tabs?.[tab.key] ?? tab.key })));
</script>

<template>
    <nav v-if="items.length" class="doctor-payout-tabs mb-3" :aria-label="t.title">
        <ul class="nav nav-tabs flex-nowrap">
            <li v-for="tab in items" :key="tab.key" class="nav-item">
                <Link
                    :href="tab.href"
                    class="nav-link d-flex align-items-center gap-1 text-nowrap"
                    :class="{ active: tab.key === current }"
                    :aria-current="tab.key === current ? 'page' : undefined"
                    :data-test="`tab-${tab.key}`"
                >
                    <i :class="tab.icon" aria-hidden="true"></i>{{ tab.label }}
                </Link>
            </li>
        </ul>
    </nav>
</template>

<style scoped>
/* Celular estreito: as abas rolam na horizontal em vez de quebrar a linha. */
.doctor-payout-tabs {
    overflow-x: auto;
    overflow-y: hidden;
}
</style>
