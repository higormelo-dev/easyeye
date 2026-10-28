<script setup>
import { computed, ref, useId } from 'vue';
import { useModalA11y } from '@/Support/useModalA11y.js';

/**
 * PdfPreviewModal — visualizador universal de PDF do prontuário.
 *
 * UX: o usuário vê o PDF dentro do modal (iframe) ANTES de baixar.
 * Duas ações disponíveis:
 *   - "Abrir em nova aba"  → preview no tab nativo do browser (sem download)
 *   - "Baixar"             → força download via attribute `download` em <a>
 *
 * Para o download, montamos o nome do arquivo a partir do prop `filename`
 * (caller pode passar) ou inferido do title. Atrás disso, a URL é a mesma
 * pdf_url servida pelo backend (rota assinada / temporária).
 *
 * Abre por cima de outros diálogos (ex.: gaveta do prontuário), então entra
 * na mesma pilha de diálogos (useModalA11y): foco entra aqui e volta pra quem
 * abriu, Tab fica contido e o Escape fecha SÓ este (o da gaveta de baixo
 * espera). É montado com `v-if` pelo pai — por isso `() => true`.
 */
const props = defineProps({
    url: { type: String, required: true },
    title: { type: String, default: '' },
    /** Nome de arquivo sugerido para o atributo download. Sem extensão = .pdf adicionado. */
    filename: { type: String, default: '' },
});

const emit = defineEmits(['close']);

const downloadName = computed(() => {
    const base = (props.filename || props.title || 'prontuario').trim();
    const slug = base
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '') // remove acentos
        .replace(/[^a-zA-Z0-9._-]+/g, '_') // troca não-alfanum por _
        .replace(/^_+|_+$/g, '') // tira _ das pontas
        .slice(0, 100);
    return /\.pdf$/i.test(slug) ? slug : `${slug || 'arquivo'}.pdf`;
});

const titleId = `ee-pdf-preview-title-${useId()}`;
const dialogRef = ref(null);

useModalA11y(
    dialogRef,
    () => true,
    () => emit('close'),
);
</script>

<template>
    <Teleport to="body">
        <div
            class="modal fade show d-block"
            tabindex="-1"
            role="dialog"
            aria-modal="true"
            :aria-labelledby="titleId"
            style="background: rgba(0, 0, 0, 0.5)"
            @click.self="emit('close')"
        >
            <div class="modal-dialog modal-xl modal-dialog-centered" style="height: 90vh">
                <div ref="dialogRef" class="modal-content" style="height: 100%">
                    <div class="modal-header py-2">
                        <h6 :id="titleId" class="modal-title mb-0 d-flex align-items-center">
                            <i class="ti ti-file-text-ai me-2 text-info"></i>
                            <span class="text-truncate" :title="title">
                                {{ title || 'Pré-visualização do PDF' }}
                            </span>
                        </h6>
                        <div class="ms-auto d-flex gap-2 align-items-center">
                            <a
                                v-if="url"
                                :href="url"
                                target="_blank"
                                class="btn btn-outline-secondary btn-sm d-inline-flex align-items-center gap-1"
                                title="Abrir em nova aba"
                            >
                                <i class="ti ti-external-link"></i>
                                <span class="d-none d-sm-inline">Nova aba</span>
                            </a>
                            <a
                                v-if="url"
                                :href="url"
                                :download="downloadName"
                                class="btn btn-primary btn-sm d-inline-flex align-items-center gap-1"
                                title="Baixar PDF"
                            >
                                <i class="ti ti-download"></i>
                                <span class="d-none d-sm-inline">Baixar</span>
                            </a>
                            <button type="button" class="btn-close ms-1" @click="emit('close')"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0" style="flex: 1; overflow: hidden; background: #525659">
                        <iframe
                            :src="url"
                            style="width: 100%; height: 100%; border: none; display: block"
                            title="PDF Preview"
                        ></iframe>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
