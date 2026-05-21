<script setup>
import { ref, reactive, computed, watch, onBeforeUnmount } from 'vue';

/**
 * MedicalRecordFileUploadModal — Modal de upload de anexos do prontuário.
 *
 * Recursos:
 *   - Drag & drop com zona destacada (hover state)
 *   - Multi-file (até `storage.max_files_per_batch`, espelha backend)
 *   - Progresso por arquivo em tempo real via XHR (byte-accurate, sem polling)
 *   - Validação client-side espelhando o backend (extensão + tamanho + cota)
 *   - Cancelamento por arquivo (XHR.abort)
 *   - Retry por arquivo após erro
 *   - Cabeçalho de cota (used/limit/percent) com cor por threshold (80/95)
 *
 * Compliance:
 *   - LGPD Art. 37: o backend já registra trilha via LogsDataAccess no controller.
 *     Aqui não persistimos nada além de mostrar progresso no cliente.
 *   - Cota CFM/LGPD: arquivos nunca são removidos automaticamente — apenas o
 *     upload é bloqueado quando atinge o limite (retenção de 20 anos).
 *
 * Por que XHR ao invés de Reverb/Redis:
 *   - Upload é cliente→servidor, ≤10MB, sem processamento server-side.
 *   - XMLHttpRequest.upload.onprogress já é real-time byte-accurate no browser.
 *   - Adicionar WebSocket aqui só adiciona latência e ponto de falha.
 *   - Quando houver pós-processamento (AV, OCR, derivados), aí sim publicar via
 *     broadcast Redis + Echo. Hoje não há.
 */
const props = defineProps({
    show:      { type: Boolean, required: true },
    storeUrl:  { type: String,  required: true },
    storage:   { type: Object,  required: true },
    csrfToken: { type: String,  required: true },
});

const emit = defineEmits(['close', 'uploaded', 'storage-updated']);

// Estado local da cota — sincronizado com prop, mas atualizado após cada upload
const quota = reactive({ ...props.storage });
watch(() => props.storage, (v) => Object.assign(quota, v), { deep: true });

// Lista de itens (cada arquivo selecionado vira um item com estado próprio)
// Status: 'pending' | 'uploading' | 'success' | 'error' | 'cancelled'
const items = ref([]);

const dragging = ref(false);

const isFull = computed(() =>
    !quota.is_unlimited && quota.remaining_bytes !== null && quota.remaining_bytes <= 0
);

const quotaColor = computed(() => {
    if (quota.is_unlimited) return 'bg-success';
    if (quota.percent >= 95) return 'bg-danger';
    if (quota.percent >= 80) return 'bg-warning';
    return 'bg-info';
});

const quotaLabel = computed(() => {
    if (quota.is_unlimited) {
        return `${formatBytes(quota.used_bytes)} usados — Ilimitado`;
    }
    return `${formatBytes(quota.used_bytes)} de ${formatBytes(quota.limit_bytes)} usados`;
});

const hasActive = computed(() => items.value.some(i => i.status === 'uploading'));
const allDone = computed(() =>
    items.value.length > 0 &&
    items.value.every(i => ['success', 'error', 'cancelled'].includes(i.status))
);
const pendingBytes = computed(() =>
    items.value
        .filter(i => i.status === 'pending')
        .reduce((sum, i) => sum + i.size, 0)
);

// ──────────────────────────────────────────────────────────────────────────
// Drag & drop
// ──────────────────────────────────────────────────────────────────────────
function onDragOver(e) { e.preventDefault(); dragging.value = true; }
function onDragLeave(e) {
    // Só desliga quando sai realmente da zona (não dos filhos)
    if (e.currentTarget.contains(e.relatedTarget)) return;
    dragging.value = false;
}
function onDrop(e) {
    e.preventDefault();
    dragging.value = false;
    addFiles(e.dataTransfer?.files);
}
function onPickerChange(e) {
    addFiles(e.target.files);
    e.target.value = ''; // permite re-selecionar o mesmo arquivo
}

// ──────────────────────────────────────────────────────────────────────────
// Itens
// ──────────────────────────────────────────────────────────────────────────
function addFiles(fileList) {
    if (!fileList?.length) return;

    const remainingSlots = quota.max_files_per_batch - items.value.filter(
        i => ['pending', 'uploading'].includes(i.status)
    ).length;

    Array.from(fileList).slice(0, remainingSlots).forEach((file) => {
        const item = reactive({
            id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
            file,
            name: file.name,
            size: file.size,
            mime: file.type || guessMimeFromName(file.name),
            ext: extOf(file.name),
            isImage: /^image\//.test(file.type),
            previewUrl: /^image\//.test(file.type) ? URL.createObjectURL(file) : null,
            status: 'pending',
            progress: 0,
            error: null,
            xhr: null,
        });

        const validation = validateItem(item);
        if (validation) {
            item.status = 'error';
            item.error = validation;
        }

        items.value.push(item);
    });
}

function removeItem(id) {
    const idx = items.value.findIndex(i => i.id === id);
    if (idx === -1) return;
    const item = items.value[idx];
    if (item.status === 'uploading') item.xhr?.abort();
    if (item.previewUrl) URL.revokeObjectURL(item.previewUrl);
    items.value.splice(idx, 1);
}

function cancelItem(id) {
    const item = items.value.find(i => i.id === id);
    if (item?.status === 'uploading') {
        item.xhr?.abort();
        item.status = 'cancelled';
        item.error = 'Upload cancelado';
    }
}

function retryItem(id) {
    const item = items.value.find(i => i.id === id);
    if (!item) return;
    item.error = null;
    item.progress = 0;
    item.status = 'pending';
    const validation = validateItem(item);
    if (validation) { item.status = 'error'; item.error = validation; return; }
    uploadItem(item);
}

// ──────────────────────────────────────────────────────────────────────────
// Validação client-side (espelha backend)
// ──────────────────────────────────────────────────────────────────────────
function validateItem(item) {
    if (!quota.accept_mimes.includes(item.ext)) {
        return `Extensão ".${item.ext}" não permitida.`;
    }
    if (item.size > quota.max_file_size_bytes) {
        return `Tamanho excede ${formatBytes(quota.max_file_size_bytes)}.`;
    }
    if (!quota.is_unlimited && quota.remaining_bytes !== null) {
        const wouldExceed = item.size > quota.remaining_bytes;
        if (wouldExceed) {
            return `Cota esgotada. Restante: ${formatBytes(quota.remaining_bytes)}.`;
        }
    }
    return null;
}

// ──────────────────────────────────────────────────────────────────────────
// Upload (XHR com progress por arquivo — paralelizado)
// ──────────────────────────────────────────────────────────────────────────
function uploadAll() {
    const pending = items.value.filter(i => i.status === 'pending');
    if (!pending.length) return;

    // Pré-check de cota agregada (anti-burst): soma todos os pendentes
    if (!quota.is_unlimited && quota.remaining_bytes !== null) {
        const total = pending.reduce((sum, i) => sum + i.size, 0);
        if (total > quota.remaining_bytes) {
            pending.forEach((i) => {
                i.status = 'error';
                i.error = `Soma dos arquivos excede a cota disponível (${formatBytes(quota.remaining_bytes)}).`;
            });
            return;
        }
    }

    pending.forEach(uploadItem);
}

function uploadItem(item) {
    item.status = 'uploading';
    item.progress = 0;
    item.error = null;

    const formData = new FormData();
    formData.append('files[]', item.file);

    const xhr = new XMLHttpRequest();
    item.xhr = xhr;

    xhr.upload.addEventListener('progress', (e) => {
        if (e.lengthComputable) {
            item.progress = Math.round((e.loaded / e.total) * 100);
        }
    });

    xhr.addEventListener('load', () => {
        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                const data = JSON.parse(xhr.responseText);
                item.status = 'success';
                item.progress = 100;
                // Atualiza cota com estado autoritativo do servidor
                if (data.storage) {
                    Object.assign(quota, data.storage);
                    emit('storage-updated', { ...data.storage });
                }
                // Emite arquivo salvo (controller retorna array `files`)
                if (Array.isArray(data.files)) {
                    data.files.forEach(f => emit('uploaded', f));
                }
            } catch (err) {
                item.status = 'error';
                item.error = 'Resposta inválida do servidor.';
            }
        } else {
            item.status = 'error';
            try {
                const err = JSON.parse(xhr.responseText);
                item.error = err.message ?? `Erro ${xhr.status}`;
            } catch {
                item.error = `Erro ${xhr.status}`;
            }
        }
    });

    xhr.addEventListener('error', () => {
        item.status = 'error';
        item.error = 'Falha de rede.';
    });

    xhr.addEventListener('abort', () => {
        if (item.status === 'uploading') {
            item.status = 'cancelled';
            item.error = 'Upload cancelado';
        }
    });

    xhr.open('POST', props.storeUrl);
    xhr.setRequestHeader('X-CSRF-TOKEN', props.csrfToken);
    xhr.setRequestHeader('Accept', 'application/json');
    xhr.send(formData);
}

// ──────────────────────────────────────────────────────────────────────────
// Helpers
// ──────────────────────────────────────────────────────────────────────────
function formatBytes(bytes) {
    if (bytes === 0 || bytes == null) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.min(units.length - 1, Math.floor(Math.log(bytes) / Math.log(1024)));
    const value = bytes / Math.pow(1024, i);
    return `${value.toFixed(value >= 10 || i === 0 ? 0 : 1)} ${units[i]}`;
}

function extOf(name) {
    const m = /\.([a-z0-9]+)$/i.exec(name);
    return m ? m[1].toLowerCase() : '';
}

function guessMimeFromName(name) {
    const e = extOf(name);
    const map = {
        jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png',
        gif: 'image/gif', webp: 'image/webp', pdf: 'application/pdf',
        doc: 'application/msword',
        docx: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    };
    return map[e] ?? 'application/octet-stream';
}

function statusLabel(s) {
    return {
        pending:   'Aguardando',
        uploading: 'Enviando…',
        success:   'Concluído',
        error:     'Erro',
        cancelled: 'Cancelado',
    }[s] ?? s;
}

function statusClass(s) {
    return {
        pending:   'text-muted',
        uploading: 'text-info',
        success:   'text-success',
        error:     'text-danger',
        cancelled: 'text-warning',
    }[s] ?? '';
}

function iconFor(item) {
    if (item.isImage) return null;
    const map = {
        pdf: 'fas fa-file-pdf text-danger',
        doc: 'fas fa-file-word text-primary',
        docx: 'fas fa-file-word text-primary',
        gif: 'fas fa-file-image text-info',
        webp: 'fas fa-file-image text-info',
    };
    return map[item.ext] ?? 'fas fa-file text-secondary';
}

// ──────────────────────────────────────────────────────────────────────────
// Ciclo de vida
// ──────────────────────────────────────────────────────────────────────────
function close() {
    if (hasActive.value) {
        if (!confirm('Há uploads em andamento. Cancelar e fechar?')) return;
        items.value.forEach((i) => { if (i.status === 'uploading') i.xhr?.abort(); });
    }
    cleanup();
    emit('close');
}

function cleanup() {
    items.value.forEach((i) => { if (i.previewUrl) URL.revokeObjectURL(i.previewUrl); });
    items.value = [];
    dragging.value = false;
}

// Limpa previews quando o componente é destruído
onBeforeUnmount(() => {
    items.value.forEach((i) => { if (i.previewUrl) URL.revokeObjectURL(i.previewUrl); });
});

// Reset ao reabrir
watch(() => props.show, (v) => { if (v) cleanup(); });
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="modal fade show d-block" tabindex="-1"
             style="background: rgba(0, 0, 0, .5);"
             @click.self="close">
            <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title">
                            <i class="fas fa-paperclip me-2" style="color: #607d8b;"></i>
                            Anexar arquivos ao prontuário
                        </h6>
                        <button type="button" class="btn-close" @click="close"></button>
                    </div>

                    <div class="modal-body p-3">
                        <!-- Cota de armazenamento -->
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center small mb-1">
                                <span class="text-muted">
                                    <i class="fas fa-hdd me-1"></i>
                                    Armazenamento
                                </span>
                                <span :class="quota.percent >= 95 ? 'text-danger fw-bold' : 'text-muted'">
                                    {{ quotaLabel }}
                                </span>
                            </div>
                            <div class="progress" style="height: 6px;">
                                <div class="progress-bar"
                                     :class="quotaColor"
                                     :style="`width: ${quota.is_unlimited ? 0 : quota.percent}%`"
                                     role="progressbar"
                                     :aria-valuenow="quota.percent"
                                     aria-valuemin="0"
                                     aria-valuemax="100"></div>
                            </div>
                            <div v-if="quota.percent >= 80 && !quota.is_unlimited"
                                 class="small mt-1"
                                 :class="quota.percent >= 95 ? 'text-danger' : 'text-warning'">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                <template v-if="quota.percent >= 95">
                                    Armazenamento quase esgotado — considere adquirir um pacote adicional.
                                </template>
                                <template v-else>
                                    Você já usou {{ quota.percent }}% da cota. Pode contratar mais espaço.
                                </template>
                            </div>
                        </div>

                        <!-- Zona drag-drop -->
                        <div class="upload-dropzone"
                             :class="{ 'is-dragging': dragging, 'is-disabled': isFull }"
                             @dragover="onDragOver"
                             @dragleave="onDragLeave"
                             @drop="onDrop">
                            <input type="file"
                                   id="upload-picker"
                                   multiple
                                   :accept="quota.accept"
                                   :disabled="isFull"
                                   class="d-none"
                                   @change="onPickerChange">
                            <div class="upload-dropzone-inner">
                                <i class="fas fa-cloud-upload-alt upload-dropzone-icon"></i>
                                <p class="mb-1 fw-semibold">
                                    <template v-if="isFull">
                                        Cota esgotada
                                    </template>
                                    <template v-else>
                                        Arraste arquivos aqui
                                    </template>
                                </p>
                                <p class="text-muted small mb-2">
                                    ou
                                    <label for="upload-picker"
                                           class="text-primary"
                                           style="cursor: pointer; text-decoration: underline;">
                                        clique para selecionar
                                    </label>
                                </p>
                                <p class="text-muted small mb-0">
                                    Até {{ quota.max_files_per_batch }} arquivos por envio,
                                    máx. {{ formatBytes(quota.max_file_size_bytes) }} cada<br>
                                    Aceitos: JPG, PNG, GIF, WEBP, PDF, DOC, DOCX
                                </p>
                            </div>
                        </div>

                        <!-- Lista de itens -->
                        <div v-if="items.length > 0" class="upload-items mt-3">
                            <div v-for="item in items"
                                 :key="item.id"
                                 class="upload-item"
                                 :class="`is-${item.status}`">

                                <div class="upload-item-thumb">
                                    <img v-if="item.isImage && item.previewUrl"
                                         :src="item.previewUrl"
                                         :alt="item.name">
                                    <i v-else :class="iconFor(item)"></i>
                                </div>

                                <div class="upload-item-body">
                                    <div class="upload-item-head">
                                        <span class="upload-item-name" :title="item.name">{{ item.name }}</span>
                                        <span class="upload-item-size text-muted small">
                                            {{ formatBytes(item.size) }}
                                        </span>
                                    </div>

                                    <div class="progress mt-1" style="height: 4px;">
                                        <div class="progress-bar"
                                             :class="{
                                                'bg-info': item.status === 'uploading',
                                                'bg-success': item.status === 'success',
                                                'bg-danger': item.status === 'error',
                                                'bg-warning': item.status === 'cancelled',
                                                'bg-secondary': item.status === 'pending',
                                             }"
                                             :style="`width: ${item.status === 'pending' ? 0 : item.progress}%`"></div>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-1">
                                        <span class="small" :class="statusClass(item.status)">
                                            <i v-if="item.status === 'success'" class="fas fa-check-circle me-1"></i>
                                            <i v-else-if="item.status === 'error'" class="fas fa-times-circle me-1"></i>
                                            <i v-else-if="item.status === 'uploading'" class="fas fa-spinner fa-spin me-1"></i>
                                            <i v-else-if="item.status === 'cancelled'" class="fas fa-ban me-1"></i>
                                            {{ statusLabel(item.status) }}
                                            <template v-if="item.status === 'uploading'">
                                                ({{ item.progress }}%)
                                            </template>
                                        </span>
                                        <span v-if="item.error" class="small text-danger ms-2">
                                            {{ item.error }}
                                        </span>
                                    </div>
                                </div>

                                <div class="upload-item-actions">
                                    <button v-if="item.status === 'uploading'"
                                            type="button"
                                            class="btn btn-sm btn-link text-warning"
                                            title="Cancelar"
                                            @click="cancelItem(item.id)">
                                        <i class="fas fa-stop-circle"></i>
                                    </button>
                                    <button v-else-if="item.status === 'error' || item.status === 'cancelled'"
                                            type="button"
                                            class="btn btn-sm btn-link text-info"
                                            title="Tentar novamente"
                                            @click="retryItem(item.id)">
                                        <i class="fas fa-redo"></i>
                                    </button>
                                    <button v-if="item.status !== 'uploading'"
                                            type="button"
                                            class="btn btn-sm btn-link text-danger"
                                            title="Remover"
                                            @click="removeItem(item.id)">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer py-2">
                        <div class="me-auto small text-muted">
                            <template v-if="pendingBytes > 0">
                                <i class="fas fa-clock me-1"></i>
                                {{ items.filter(i => i.status === 'pending').length }}
                                arquivo(s) pendente(s) — {{ formatBytes(pendingBytes) }}
                            </template>
                            <template v-else-if="allDone">
                                <i class="fas fa-check-circle text-success me-1"></i>
                                Envio finalizado
                            </template>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary" @click="close">
                            Fechar
                        </button>
                        <button type="button"
                                class="btn btn-sm btn-primary"
                                :disabled="hasActive || pendingBytes === 0 || isFull"
                                @click="uploadAll">
                            <i class="fas fa-upload me-1"></i>
                            Enviar
                            <template v-if="pendingBytes > 0">
                                ({{ items.filter(i => i.status === 'pending').length }})
                            </template>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.upload-dropzone {
    border: 2px dashed #c4d3e1;
    border-radius: 8px;
    padding: 1.5rem;
    background: #f8fafc;
    transition: all .18s ease;
    cursor: pointer;
}
.upload-dropzone.is-dragging {
    border-color: #1976d2;
    background: #e3eef9;
    transform: scale(1.005);
}
.upload-dropzone.is-disabled {
    opacity: .55;
    cursor: not-allowed;
    border-color: #e57373;
    background: #fff5f5;
}
.upload-dropzone-inner {
    text-align: center;
    pointer-events: none;
}
.upload-dropzone-inner label {
    pointer-events: auto;
}
.upload-dropzone-icon {
    font-size: 2.2rem;
    color: #90a4ae;
    margin-bottom: .4rem;
}
.upload-dropzone.is-dragging .upload-dropzone-icon {
    color: #1976d2;
}

.upload-items {
    max-height: 320px;
    overflow-y: auto;
    border-top: 1px solid #eef2f6;
    padding-top: .5rem;
}
.upload-item {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: .55rem .25rem;
    border-bottom: 1px solid #f1f4f7;
}
.upload-item:last-child { border-bottom: 0; }
.upload-item.is-success { background: #f5fbf6; }
.upload-item.is-error,
.upload-item.is-cancelled { background: #fff8f8; }

.upload-item-thumb {
    width: 44px;
    height: 44px;
    flex-shrink: 0;
    border-radius: 6px;
    background: #fff;
    border: 1px solid #e1e7ee;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
}
.upload-item-thumb img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}
.upload-item-thumb i {
    font-size: 1.4rem;
}
.upload-item-body {
    flex: 1;
    min-width: 0;
}
.upload-item-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: .5rem;
}
.upload-item-name {
    font-size: .85rem;
    font-weight: 500;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: 100%;
}
.upload-item-actions {
    flex-shrink: 0;
    display: flex;
    align-items: center;
    gap: .15rem;
}
.upload-item-actions .btn-link {
    padding: .15rem .4rem;
}
</style>
