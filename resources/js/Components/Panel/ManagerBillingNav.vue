<script setup>
import { Link } from '@inertiajs/vue3';

/**
 * Abas Planos ↔ Assinaturas no manager — as duas telas são um fluxo só
 * (definir o que vender → quem está em cada plano). Planos é só para admin;
 * sem permissão, a aba não aparece.
 */
defineProps({
    active: { type: String, required: true }, // 'plans' | 'subscriptions'
    canManagePlans: { type: Boolean, default: true },
    labels: { type: Object, default: () => ({}) }, // { nav_plans, nav_subscriptions, nav_label }
});
</script>

<template>
    <nav class="mb-3" :aria-label="labels.nav_label">
        <ul class="nav nav-tabs">
            <li v-if="canManagePlans" class="nav-item">
                <Link
                    :href="route('manager.plans.index')"
                    class="nav-link"
                    :class="{ active: active === 'plans' }"
                    :aria-current="active === 'plans' ? 'page' : undefined"
                >
                    <i class="ti ti-box me-1" aria-hidden="true"></i>{{ labels.nav_plans }}
                </Link>
            </li>
            <li class="nav-item">
                <Link
                    :href="route('manager.subscriptions.index')"
                    class="nav-link"
                    :class="{ active: active === 'subscriptions' }"
                    :aria-current="active === 'subscriptions' ? 'page' : undefined"
                >
                    <i class="ti ti-file-invoice me-1" aria-hidden="true"></i>{{ labels.nav_subscriptions }}
                </Link>
            </li>
        </ul>
    </nav>
</template>
