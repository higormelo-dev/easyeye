<script setup>
/**
 * CenteredModal — Modal centralizado na tela.
 *
 * Interface de slots idêntica ao OffcanvasPanel para facilitar troca:
 *   #header  – título + ícone (btn-close adicionado automaticamente)
 *   #tabs    – barra de abas opcional (entre header e body)
 *   #default – conteúdo principal (body scrollável)
 *   #footer  – rodapé fixo com botões de ação
 *
 * Props:
 *   open         – controla visibilidade
 *   size         – sm | md | lg | xl  (default: lg)
 *   loading      – exibe spinner no body
 *   loadingLabel – texto do spinner
 */
defineProps({
    open:         { type: Boolean, required: true },
    size:         { type: String,  default: 'lg' },
    loading:      { type: Boolean, default: false },
    loadingLabel: { type: String,  default: 'Carregando...' },
});

defineEmits(['close']);
</script>

<template>
    <Teleport to="body">

        <!-- Backdrop -->
        <transition name="ee-fade">
            <div v-if="open"
                 class="ee-modal__backdrop"
                 @click="$emit('close')" />
        </transition>

        <!-- Dialog wrapper -->
        <transition name="ee-modal-scale">
            <div v-if="open"
                 class="ee-modal__wrap"
                 role="dialog"
                 aria-modal="true"
                 @click.self="$emit('close')">

                <div class="ee-modal__dialog" :class="`ee-modal--${size}`">

                    <!-- Header -->
                    <div class="ee-modal__header">
                        <div class="ee-modal__header-content">
                            <slot name="header" />
                        </div>
                        <button type="button"
                                class="btn-close flex-shrink-0"
                                @click="$emit('close')" />
                    </div>

                    <!-- Optional tabs bar -->
                    <div v-if="$slots.tabs" class="ee-modal__tabs border-bottom">
                        <slot name="tabs" />
                    </div>

                    <!-- Loading state -->
                    <div v-if="loading" class="text-center py-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">{{ loadingLabel }}</span>
                        </div>
                    </div>

                    <!-- Body + footer -->
                    <template v-else>
                        <div class="ee-modal__body">
                            <slot />
                        </div>
                        <div v-if="$slots.footer" class="ee-modal__footer">
                            <slot name="footer" />
                        </div>
                    </template>

                </div>
            </div>
        </transition>

    </Teleport>
</template>

<style scoped>
/* ── Backdrop ─────────────────────────────────────────────────────────── */
.ee-modal__backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, .45);
    z-index: 1054;
}

/* ── Wrapper centraliza o dialog ──────────────────────────────────────── */
.ee-modal__wrap {
    position: fixed;
    inset: 0;
    z-index: 1055;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    pointer-events: none;
}

/* ── Dialog box ───────────────────────────────────────────────────────── */
.ee-modal__dialog {
    background: #fff;
    border-radius: .5rem;
    box-shadow: 0 8px 40px rgba(0, 0, 0, .22);
    display: flex;
    flex-direction: column;
    max-height: calc(100dvh - 2rem);
    width: 100%;
    pointer-events: all;
}

.ee-modal--sm { max-width: 400px; }
.ee-modal--md { max-width: 540px; }
.ee-modal--lg { max-width: 660px; }
.ee-modal--xl { max-width: 900px; }

:root[data-bs-theme=dark] .ee-modal__dialog {
    background: #0f1729;
    box-shadow: 0 8px 40px rgba(0, 0, 0, .5);
}

/* ── Header ───────────────────────────────────────────────────────────── */
.ee-modal__header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--bs-border-color, #dee2e6);
    flex-shrink: 0;
    gap: .75rem;
}

.ee-modal__header-content {
    flex: 1;
    min-width: 0;
}

/* ── Tabs bar ─────────────────────────────────────────────────────────── */
.ee-modal__tabs {
    flex-shrink: 0;
    padding: .75rem 1rem 0;
}

.ee-modal__tabs :deep(.nav-link) {
    font-size: .8125rem;
    padding: .5rem .75rem;
    border-radius: .375rem .375rem 0 0;
    color: var(--bs-body-color);
}

/* ── Body ─────────────────────────────────────────────────────────────── */
.ee-modal__body {
    flex: 1;
    overflow-y: auto;
    padding: 1.25rem;
}

/* ── Footer ───────────────────────────────────────────────────────────── */
.ee-modal__footer {
    display: flex;
    justify-content: flex-end;
    gap: .75rem;
    padding: .875rem 1.25rem;
    border-top: 1px solid var(--bs-border-color, #dee2e6);
    flex-shrink: 0;
    background: #fff;
}

:root[data-bs-theme=dark] .ee-modal__footer {
    background: #0f1729;
}

/* ── Transitions ──────────────────────────────────────────────────────── */
.ee-fade-enter-active,
.ee-fade-leave-active  { transition: opacity .2s ease; }
.ee-fade-enter-from,
.ee-fade-leave-to      { opacity: 0; }

.ee-modal-scale-enter-active,
.ee-modal-scale-leave-active { transition: opacity .2s ease, transform .2s cubic-bezier(.4, 0, .2, 1); }
.ee-modal-scale-enter-from,
.ee-modal-scale-leave-to     { opacity: 0; transform: scale(.95); }

/* ── Responsive ───────────────────────────────────────────────────────── */
@media (max-width: 480px) {
    .ee-modal__dialog {
        max-height: calc(100dvh - .5rem);
        border-radius: .375rem;
    }
    .ee-modal__wrap {
        padding: .25rem;
        align-items: flex-end;
    }
}
</style>
