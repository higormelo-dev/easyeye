<script setup>
/**
 * PageHeader — cabeçalho padrão das páginas do painel.
 *
 * Mostra título + total opcional + ações.
 * O view-toggle (tabela/cards) é OPT-IN via `show-view-toggle` — antes era
 * fixo em todas as telas e poluía visualmente as 26+ páginas que não têm
 * essa funcionalidade.
 */
import { useLocaleFormat } from '@/composables/useLocaleFormat';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    total: { type: Number, default: null },
    /** Rótulo traduzido do total (opcional — padrão mantém o texto de sempre). */
    totalLabel: { type: String, default: 'Total:' },
    /** Mostra o toggle tabela/cards. Default false — só ative em telas que ouvem `@set-view`. */
    showViewToggle: { type: Boolean, default: false },
    view: { type: String, default: 'table' },
    viewTableTitle: { type: String, default: 'Tabela' },
    viewCardsTitle: { type: String, default: 'Cards' },
});

defineEmits(['set-view']);

// Total no formato do idioma do usuário (1234 → "1.234" em pt-BR).
const { number } = useLocaleFormat();
</script>

<template>
    <div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom">
        <!-- Título + subtitle + total -->
        <div class="d-flex align-items-center gap-2 me-auto">
            <h4 class="mb-0 fw-bold">{{ title }}</h4>
            <small v-if="subtitle" class="text-muted">{{ subtitle }}</small>
            <span v-if="total !== null" class="page-header-total"> {{ totalLabel }} {{ number(total) }} </span>
        </div>

        <!-- View toggle (opt-in) -->
        <div v-if="showViewToggle" class="page-header-toggle border shadow-sm rounded px-1 d-flex align-items-center">
            <button
                type="button"
                class="rounded p-1 d-flex align-items-center border-0"
                :class="view === 'table' ? 'page-header-toggle-active' : 'page-header-toggle-idle'"
                :title="viewTableTitle"
                :aria-label="viewTableTitle"
                :aria-pressed="view === 'table'"
                @click="$emit('set-view', 'table')"
            >
                <i class="ti ti-list fs-14 text-body" aria-hidden="true"></i>
            </button>
            <button
                type="button"
                class="rounded p-1 d-flex align-items-center border-0"
                :class="view === 'cards' ? 'page-header-toggle-active' : 'page-header-toggle-idle'"
                :title="viewCardsTitle"
                :aria-label="viewCardsTitle"
                :aria-pressed="view === 'cards'"
                @click="$emit('set-view', 'cards')"
            >
                <i class="ti ti-layout-grid fs-14 text-body" aria-hidden="true"></i>
            </button>
        </div>

        <!-- Caller-defined action buttons (use btn-group para agrupar) -->
        <slot name="actions" />
    </div>
</template>

<style scoped>
/* Selo "Total": mesmas cores de sempre no tema claro; no escuro usa as
   variáveis do Bootstrap para manter contraste (antes: hex fixo inline). */
.page-header-total {
    font-size: 0.78rem;
    font-weight: 600;
    color: #0d6efd;
    background: #eff4ff;
    border: 1.5px solid #0d6efd;
    border-radius: 20px;
    padding: 2px 12px;
    white-space: nowrap;
    line-height: 1.6;
}
.page-header-toggle,
.page-header-toggle-idle {
    background: #fff;
}
.page-header-toggle-active {
    background: var(--bs-light, #f8f9fa);
}

/* `:root[...] .classe` (e não `:global([...]) .classe`): no CSS com escopo o
   :global(...) substitui o seletor inteiro — as regras caíam no <html> e o
   selo/toggle continuavam claros no tema escuro. */
:root[data-bs-theme='dark'] .page-header-total {
    color: var(--bs-primary-text-emphasis);
    background: var(--bs-primary-bg-subtle);
    border-color: var(--bs-primary-border-subtle);
}
:root[data-bs-theme='dark'] .page-header-toggle,
:root[data-bs-theme='dark'] .page-header-toggle-idle {
    background: var(--bs-body-bg);
}
:root[data-bs-theme='dark'] .page-header-toggle-active {
    background: var(--bs-tertiary-bg);
}
</style>
