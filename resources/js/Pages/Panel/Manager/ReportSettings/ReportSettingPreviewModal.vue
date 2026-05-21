<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    open:       { type: Boolean, required: true },
    previewUrl: { type: String,  default: null },
    t:          { type: Object,  default: () => ({}) },
});

const emit    = defineEmits(['close']);
const loading = ref(false);
const srcUrl  = ref('');

watch(() => props.open, (val) => {
    if (val && props.previewUrl) {
        loading.value = true;
        srcUrl.value  = props.previewUrl;
    } else {
        srcUrl.value = '';
    }
});

function onIframeLoad() {
    loading.value = false;
}
</script>

<template>
    <Teleport to="body">
        <transition name="rs-fade">
            <div
                v-if="open"
                class="rs-preview-backdrop"
                @click="$emit('close')"
            />
        </transition>

        <transition name="rs-scale">
            <div v-if="open" class="rs-preview-modal" role="dialog" aria-modal="true">
                <div class="rs-preview-header">
                    <span class="fw-semibold">
                        <i class="ti ti-file-search me-2 text-primary"></i>{{ t.preview_title }}
                    </span>
                    <button type="button" class="btn-close" @click="$emit('close')"></button>
                </div>

                <div class="rs-preview-body">
                    <div v-if="loading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status"></div>
                    </div>
                    <iframe
                        v-if="srcUrl"
                        :src="srcUrl"
                        class="rs-preview-iframe"
                        :class="{ 'd-none': loading }"
                        frameborder="0"
                        @load="onIframeLoad"
                    ></iframe>
                </div>

                <div class="rs-preview-footer">
                    <button class="btn btn-secondary btn-sm" @click="$emit('close')">
                        {{ t.preview_close }}
                    </button>
                </div>
            </div>
        </transition>
    </Teleport>
</template>

<style scoped>
.rs-preview-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .5);
    z-index: 1054;
}

.rs-preview-modal {
    position: fixed;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    width: min(860px, 96vw);
    height: min(90vh, 960px);
    background: #fff;
    border-radius: .5rem;
    box-shadow: 0 16px 48px rgba(0, 0, 0, .28);
    z-index: 1055;
    display: flex;
    flex-direction: column;
}

.rs-preview-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .875rem 1.25rem;
    border-bottom: 1px solid var(--bs-border-color, #dee2e6);
    flex-shrink: 0;
}

.rs-preview-body {
    flex: 1;
    overflow: hidden;
    position: relative;
}

.rs-preview-iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}

.rs-preview-footer {
    display: flex;
    justify-content: flex-end;
    padding: .75rem 1.25rem;
    border-top: 1px solid var(--bs-border-color, #dee2e6);
    flex-shrink: 0;
}

.rs-fade-enter-active, .rs-fade-leave-active { transition: opacity .2s ease; }
.rs-fade-enter-from, .rs-fade-leave-to       { opacity: 0; }

.rs-scale-enter-active, .rs-scale-leave-active { transition: opacity .22s ease, transform .22s cubic-bezier(.34,1.56,.64,1); }
.rs-scale-enter-from, .rs-scale-leave-to       { opacity: 0; transform: translate(-50%, -50%) scale(.9); }
</style>
