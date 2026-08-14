<script setup>
import { ref } from 'vue';

/**
 * Painel de reordenação de colunas — pensado pra viver dentro do slot do
 * ActionDropdown (mesmo shell/posicionamento reaproveitado em outros pontos
 * do painel). Arrasta-e-solta (HTML5 DnD nativo, sem lib nova) + setas
 * cima/baixo como alternativa acessível pra quem não usa mouse/drag.
 *
 * `columns` já vem NA ORDEM ATUAL (o pai é quem decide a ordem real e
 * decide se cada evento é aplicado) — este componente só emite intenção.
 */
const props = defineProps({
    columns: { type: Array, required: true }, // [{ key, label }] na ordem atual
    title:   { type: String, default: 'Ordem das colunas' },
});

const emit = defineEmits(['move', 'reset']);

const dragIndex   = ref(null);
const dragOverIdx = ref(null);

function onDragStart(index) {
    dragIndex.value = index;
}

function onDragEnter(index) {
    dragOverIdx.value = index;
}

function onDrop(index) {
    if (dragIndex.value !== null && dragIndex.value !== index) {
        emit('move', dragIndex.value, index);
    }
    dragIndex.value   = null;
    dragOverIdx.value = null;
}

function onDragEnd() {
    dragIndex.value   = null;
    dragOverIdx.value = null;
}
</script>

<template>
    <li>
        <div class="column-order-menu px-1 py-1" style="min-width:230px;">
            <p class="text-muted small fw-medium mb-2 px-1">{{ title }}</p>

            <ul class="list-unstyled mb-2">
                <li
                    v-for="(col, index) in columns"
                    :key="col.key"
                    class="column-order-item d-flex align-items-center gap-1 px-2 py-1 rounded"
                    :class="{ 'column-order-item--over': dragOverIdx === index && dragIndex !== index }"
                    draggable="true"
                    @dragstart="onDragStart(index)"
                    @dragenter.prevent="onDragEnter(index)"
                    @dragover.prevent
                    @drop="onDrop(index)"
                    @dragend="onDragEnd"
                >
                    <i class="ti ti-grip-vertical text-muted column-order-grip"></i>
                    <span class="flex-grow-1 small text-truncate">{{ col.label }}</span>
                    <button
                        type="button"
                        class="btn btn-sm btn-link p-1 text-muted lh-1"
                        :disabled="index === 0"
                        title="Mover para cima"
                        @click="$emit('move', index, index - 1)"
                    >
                        <i class="ti ti-chevron-up fs-14"></i>
                    </button>
                    <button
                        type="button"
                        class="btn btn-sm btn-link p-1 text-muted lh-1"
                        :disabled="index === columns.length - 1"
                        title="Mover para baixo"
                        @click="$emit('move', index, index + 1)"
                    >
                        <i class="ti ti-chevron-down fs-14"></i>
                    </button>
                </li>
            </ul>

            <button
                type="button"
                class="btn btn-sm btn-outline-secondary w-100"
                @click="$emit('reset')"
            >
                <i class="ti ti-restore me-1"></i>Restaurar padrão
            </button>
        </div>
    </li>
</template>

<style scoped>
.column-order-item {
    cursor: grab;
    transition: background-color .1s ease;
}
.column-order-item:active {
    cursor: grabbing;
}
.column-order-item--over {
    background-color: var(--primary-transparent, rgba(13, 110, 253, .08));
}
.column-order-grip {
    cursor: grab;
}
</style>
