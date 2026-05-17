<script setup>
/**
 * PageHeader — Cabeçalho padrão de listagem do painel.
 *
 * Props:
 *   title          – texto do título
 *   total          – contagem exibida no badge
 *   totalLabel     – prefixo do badge ("Total:")
 *   view           – view ativa: 'table' | 'cards'
 *   viewTableTitle – tooltip do botão tabela
 *   viewCardsTitle – tooltip do botão cards
 *
 * Emits:
 *   set-view(v)    – quando o usuário alterna a visualização
 *
 * Slots:
 *   #actions       – botões à direita (ex: "Novo X")
 */
defineProps({
    title:          { type: String, required: true },
    total:          { type: Number, default: null },
    totalLabel:     { type: String, default: 'Total:' },
    view:           { type: String, default: 'table' },
    viewTableTitle: { type: String, default: 'Tabela' },
    viewCardsTitle: { type: String, default: 'Cards' },
});

defineEmits(['set-view']);
</script>

<template>
    <div class="d-flex align-items-sm-center flex-sm-row flex-column gap-2 pb-3 mb-3 border-bottom">

        <!-- Title + total badge -->
        <div class="flex-grow-1">
            <h4 class="fw-bold mb-0">
                {{ title }}
                <span
                    v-if="total !== null"
                    class="badge badge-soft-primary fw-medium border py-1 px-2 border-primary fs-13 ms-1"
                >
                    {{ totalLabel }} {{ total }}
                </span>
            </h4>
        </div>

        <!-- Right side: view toggle + slot actions -->
        <div class="d-flex align-items-center gap-2">
            <!-- View toggle -->
            <div class="bg-white border shadow-sm rounded px-1 d-flex align-items-center">
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

            <!-- Caller-defined action buttons -->
            <slot name="actions" />
        </div>
    </div>
</template>
