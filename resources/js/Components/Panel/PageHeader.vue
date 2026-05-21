<script setup>
/**
 * PageHeader — cabeçalho padrão das páginas do painel.
 *
 * Mostra título + total opcional + ações.
 * O view-toggle (tabela/cards) é OPT-IN via `show-view-toggle` — antes era
 * fixo em todas as telas e poluía visualmente as 26+ páginas que não têm
 * essa funcionalidade.
 */
defineProps({
    title:          { type: String,  default: '' },
    subtitle:       { type: String,  default: '' },
    total:          { type: Number,  default: null },
    /** Mostra o toggle tabela/cards. Default false — só ative em telas que ouvem `@set-view`. */
    showViewToggle: { type: Boolean, default: false },
    view:           { type: String,  default: 'table' },
    viewTableTitle: { type: String,  default: 'Tabela' },
    viewCardsTitle: { type: String,  default: 'Cards' },
});

defineEmits(['set-view']);
</script>

<template>
    <div class="d-flex align-items-center gap-2 pb-3 mb-3 border-bottom">

        <!-- Título + subtitle + total -->
        <div class="d-flex align-items-center gap-2 me-auto">
            <h4 class="mb-0 fw-bold">{{ title }}</h4>
            <small v-if="subtitle" class="text-muted">{{ subtitle }}</small>
            <span v-if="total !== null"
                  style="font-size:.78rem;font-weight:600;color:#0d6efd;background:#eff4ff;border:1.5px solid #0d6efd;border-radius:20px;padding:2px 12px;white-space:nowrap;line-height:1.6;">
                Total: {{ total }}
            </span>
        </div>

        <!-- View toggle (opt-in) -->
        <div v-if="showViewToggle"
             class="bg-white border shadow-sm rounded px-1 d-flex align-items-center">
            <button
                type="button"
                class="rounded p-1 d-flex align-items-center border-0"
                :class="view === 'table' ? 'bg-light' : 'bg-white'"
                :title="viewTableTitle"
                @click="$emit('set-view', 'table')"
            ><i class="ti ti-list fs-14 text-body"></i></button>
            <button
                type="button"
                class="rounded p-1 d-flex align-items-center border-0"
                :class="view === 'cards' ? 'bg-light' : 'bg-white'"
                :title="viewCardsTitle"
                @click="$emit('set-view', 'cards')"
            ><i class="ti ti-layout-grid fs-14 text-body"></i></button>
        </div>

        <!-- Caller-defined action buttons (use btn-group para agrupar) -->
        <slot name="actions" />
    </div>
</template>
