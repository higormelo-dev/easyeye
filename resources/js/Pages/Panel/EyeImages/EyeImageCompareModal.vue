<script setup>
import { computed, reactive, ref, watch } from 'vue';

/**
 * Comparar/Alinhar — compara 2 imagens (mesmo olho, datas diferentes) pra
 * acompanhar evolução de uma patologia (vídeo de referência do ticket:
 * aba "Align" do gerenciador de imagens de terceiros).
 *
 * Sem registro automático de imagem (visão computacional) — o médico
 * arrasta a imagem de cima pra alinhar pontos de referência manualmente.
 * Dois modos: lado a lado (comparação visual direta) e sobreposição com
 * opacidade (evidencia diferenças sutis entre os dois exames).
 */
const props = defineProps({
    open:   { type: Boolean, required: true },
    images: { type: Array,   default: () => [] }, // [{ id, url, label }, { id, url, label }]
    t:      { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

function tt(key, fallback = '') {
    return props.t?.[key] ?? fallback;
}

const mode    = ref('overlay'); // 'overlay' | 'side'
const opacity = ref(60);
const offset  = reactive({ x: 0, y: 0 });

const dragging = ref(false);
const dragStart = reactive({ x: 0, y: 0, offsetX: 0, offsetY: 0 });

function resetOffset() {
    offset.x = 0;
    offset.y = 0;
}

watch(() => props.open, (isOpen) => {
    if (isOpen) {
        mode.value    = 'overlay';
        opacity.value = 60;
        resetOffset();
    }
});

function onDragStart(event) {
    dragging.value = true;
    dragStart.x = event.clientX;
    dragStart.y = event.clientY;
    dragStart.offsetX = offset.x;
    dragStart.offsetY = offset.y;
    window.addEventListener('mousemove', onDragMove);
    window.addEventListener('mouseup', onDragEnd);
}

function onDragMove(event) {
    if (!dragging.value) return;
    offset.x = dragStart.offsetX + (event.clientX - dragStart.x);
    offset.y = dragStart.offsetY + (event.clientY - dragStart.y);
}

function onDragEnd() {
    dragging.value = false;
    window.removeEventListener('mousemove', onDragMove);
    window.removeEventListener('mouseup', onDragEnd);
}

const overlayStyle = computed(() => ({
    opacity: opacity.value / 100,
    transform: `translate(${offset.x}px, ${offset.y}px)`,
    cursor: dragging.value ? 'grabbing' : 'grab',
}));

function close() {
    onDragEnd();
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal fade show d-block" tabindex="-1" style="background:rgba(0,0,0,.7);"
             @click.self="close">
            <div class="modal-dialog modal-xl modal-dialog-centered">
                <div class="modal-content bg-dark text-light">
                    <div class="modal-header py-2 border-secondary">
                        <h6 class="modal-title">
                            <i class="ti ti-adjustments-horizontal me-2 text-info"></i>{{ tt('compare_title', 'Comparar exames') }}
                        </h6>
                        <button type="button" class="btn-close btn-close-white" @click="close"></button>
                    </div>

                    <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom border-secondary flex-wrap">
                        <div class="btn-group btn-group-sm" role="group">
                            <button type="button" class="btn"
                                    :class="mode === 'overlay' ? 'btn-info text-dark' : 'btn-outline-light'"
                                    @click="mode = 'overlay'">
                                {{ tt('compare_mode_overlay', 'Sobrepor') }}
                            </button>
                            <button type="button" class="btn"
                                    :class="mode === 'side' ? 'btn-info text-dark' : 'btn-outline-light'"
                                    @click="mode = 'side'">
                                {{ tt('compare_mode_side_by_side', 'Lado a lado') }}
                            </button>
                        </div>

                        <template v-if="mode === 'overlay'">
                            <div class="vr opacity-25 d-none d-sm-block"></div>
                            <label class="small text-light-emphasis mb-0 d-flex align-items-center gap-2">
                                {{ tt('compare_opacity', 'Opacidade') }}
                                <input type="range" min="0" max="100" v-model.number="opacity" style="width:120px;">
                                <span style="min-width:2.5em;">{{ opacity }}%</span>
                            </label>
                            <button type="button" class="btn btn-outline-light btn-sm" @click="resetOffset">
                                <i class="ti ti-relation-one-to-one me-1"></i>{{ tt('compare_reset', 'Redefinir posição') }}
                            </button>
                        </template>

                        <span class="text-muted small ms-auto d-none d-md-inline">
                            {{ mode === 'overlay' ? tt('compare_hint', 'Arraste a imagem de cima para alinhar os pontos de referência.') : '' }}
                        </span>
                    </div>

                    <div class="modal-body p-2">
                        <div v-if="images.length !== 2" class="alert alert-warning small mb-0">
                            {{ tt('compare_select_two', 'Selecione exatamente 2 imagens para comparar.') }}
                        </div>

                        <!-- Lado a lado -->
                        <div v-else-if="mode === 'side'" class="row g-2">
                            <div v-for="img in images" :key="img.id" class="col-6">
                                <div class="text-center small text-muted mb-1">{{ img.label }}</div>
                                <img :src="img.url" :alt="img.label"
                                     style="width:100%;height:auto;display:block;background:#111;border-radius:4px;">
                            </div>
                        </div>

                        <!-- Sobreposição -->
                        <div v-else class="position-relative mx-auto" style="max-width:820px;overflow:hidden;border-radius:4px;background:#111;">
                            <img :src="images[0].url" :alt="images[0].label"
                                 style="width:100%;height:auto;display:block;user-select:none;" draggable="false">
                            <img :src="images[1].url" :alt="images[1].label"
                                 class="position-absolute top-0 start-0"
                                 style="width:100%;height:auto;user-select:none;"
                                 draggable="false"
                                 :style="overlayStyle"
                                 @mousedown.prevent="onDragStart">
                            <div class="position-absolute bottom-0 start-0 end-0 d-flex justify-content-between px-2 py-1"
                                 style="background:rgba(0,0,0,.55);font-size:.7rem;pointer-events:none;">
                                <span>{{ images[0].label }}</span>
                                <span>{{ images[1].label }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
