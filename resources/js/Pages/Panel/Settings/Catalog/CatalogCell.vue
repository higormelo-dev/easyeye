<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Valor de uma coluna schema-driven do catálogo — compartilhado pela tabela
 * (CatalogTable) e pelos cards (CatalogCards) para os dois modos exibirem
 * cada tipo igual. Tipos: text | code | abbrev | color | yesno | numeric.
 */
const props = defineProps({
    item: { type: Object, required: true },
    col:  { type: Object, required: true },
    t:    { type: Object, default: () => ({}) },
});

const page = usePage();

// Locale do usuário (pt_BR → pt-BR) para números; fallback pt-BR.
const locale = computed(() => String(page.props?.locale ?? 'pt_BR').replace('_', '-'));

const value   = computed(() => props.item?.[props.col.key]);
const isEmpty = computed(() => value.value === null || value.value === undefined || value.value === '');
const display = computed(() => (isEmpty.value ? '—' : value.value));
</script>

<template>
    <code v-if="col.type === 'code'" class="text-muted small">{{ display }}</code>

    <span v-else-if="col.type === 'abbrev'" class="badge badge-soft-info rounded fs-11 fw-medium">{{ display }}</span>

    <span v-else-if="col.type === 'color'" class="d-inline-flex align-items-center gap-1">
        <span
            class="rounded-circle border d-inline-block"
            :style="{ background: value ?? '#ccc', width: '18px', height: '18px' }"
            aria-hidden="true"
        ></span>
        <code class="small text-muted">{{ display }}</code>
    </span>

    <span
        v-else-if="col.type === 'yesno'"
        :class="`badge rounded fs-11 ${value ? 'badge-soft-success text-success border border-success' : 'badge-soft-secondary'}`"
    >{{ value ? (t.yes ?? 'Sim') : (t.no ?? 'Não') }}</span>

    <span v-else-if="col.type === 'numeric'" class="font-monospace small">
        {{ Number(value ?? 0).toLocaleString(locale) }}
    </span>

    <span v-else :class="col.key === 'name' ? 'fw-medium' : 'text-muted'">{{ display }}</span>
</template>
