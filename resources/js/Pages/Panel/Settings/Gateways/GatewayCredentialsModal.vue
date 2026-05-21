<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Modal de credenciais do tenant para um gateway.
 *
 * Padrão Sanctum-style: a credencial é exibida APENAS no momento do cadastro
 * (secret nunca volta no GET de listagem — só metadata).
 */
const props = defineProps({
    open:    { type: Boolean, required: true },
    gateway: { type: Object,  default: null },
});

const emit = defineEmits(['close']);

const credentials = ref([]);
const listLoading = ref(false);
const listError   = ref('');

const form = ref({ label: '', secret: '', webhook_secret: '', valid_from: '', valid_to: '' });
const saving      = ref(false);
const formError   = ref('');
const showSecret  = ref(false);
const showWebhook = ref(false);

watch(() => props.open, async (val) => {
    if (val && props.gateway) {
        resetForm();
        await loadCredentials();
    }
    if (!val) {
        credentials.value = [];
        listError.value   = '';
    }
});

function csrf() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function loadCredentials() {
    listLoading.value = true;
    listError.value   = '';
    credentials.value = [];
    try {
        const res  = await fetch(props.gateway.credentials_url, { headers: { Accept: 'application/json' } });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? '');
        credentials.value = json.data ?? [];
    } catch {
        listError.value = 'Erro ao carregar credenciais.';
    } finally {
        listLoading.value = false;
    }
}

function resetForm() {
    form.value = { label: '', secret: '', webhook_secret: '', valid_from: '', valid_to: '' };
    formError.value   = '';
    showSecret.value  = false;
    showWebhook.value = false;
}

async function saveCredential() {
    if (!form.value.secret) {
        formError.value = 'Informe o secret.';
        return;
    }
    saving.value    = true;
    formError.value = '';

    try {
        const body = {};
        if (form.value.label)          body.label          = form.value.label;
        if (form.value.secret)         body.secret         = form.value.secret;
        if (form.value.webhook_secret) body.webhook_secret = form.value.webhook_secret;
        if (form.value.valid_from)     body.valid_from     = form.value.valid_from;
        if (form.value.valid_to)       body.valid_to       = form.value.valid_to;

        const res = await fetch(props.gateway.credentials_store_url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept':       'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(body),
        });
        const json = await res.json();
        if (!res.ok) {
            formError.value = json.errors
                ? Object.values(json.errors).flat().join(' ')
                : (json.message ?? 'Erro ao salvar');
            return;
        }
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        emit('close');
        router.reload({ only: ['gateways'] });
    } finally {
        saving.value = false;
    }
}

async function revokeCredential(cred) {
    if (!confirm('Revogar esta credencial?')) return;
    const url = props.gateway.credentials_url.replace('/credentials', `/credentials/${cred.id}/revoke`);
    const res = await fetch(url, {
        method:  'PATCH',
        headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' },
    });
    const json = await res.json();
    if (res.ok) {
        if (window.showSuccessToast) window.showSuccessToast(json.message);
        await loadCredentials();
    } else if (window.showErrorToast) {
        window.showErrorToast(json.message ?? 'Erro');
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block"
            tabindex="-1"
            style="background:rgba(0,0,0,.4);"
            @click.self="$emit('close')"
        >
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-key me-2"></i>Credenciais
                            <span v-if="gateway" class="text-muted fw-normal ms-1">— {{ gateway.name }}</span>
                        </h5>
                        <button type="button" class="btn-close" @click="$emit('close')"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Credenciais existentes -->
                        <div class="mb-4">
                            <h6 class="fw-semibold mb-3">Credenciais ativas</h6>

                            <div v-if="listLoading" class="text-center py-3 text-muted">
                                <span class="spinner-border spinner-border-sm me-2"></span>Carregando...
                            </div>

                            <div v-else-if="listError" class="alert alert-danger small">
                                {{ listError }}
                            </div>

                            <div v-else-if="credentials.length === 0" class="text-center py-3 text-muted small">
                                <i class="ti ti-key-off d-block fs-3 mb-1 opacity-25"></i>
                                Nenhuma credencial cadastrada.
                            </div>

                            <table v-else class="table table-sm table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Label</th>
                                        <th>Status</th>
                                        <th>Validade</th>
                                        <th>Criada em</th>
                                        <th class="text-end">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="c in credentials" :key="c.id">
                                        <td class="fw-medium">{{ c.label }}</td>
                                        <td>
                                            <span v-if="c.active" class="badge badge-soft-success rounded text-success border border-success fs-11">Ativa</span>
                                            <span v-else class="badge badge-soft-secondary rounded fs-11">Revogada</span>
                                        </td>
                                        <td class="small text-muted">
                                            {{ c.valid_from || '—' }} → {{ c.valid_to || '—' }}
                                        </td>
                                        <td class="small text-muted">{{ c.created_at }}</td>
                                        <td class="text-end">
                                            <button
                                                v-if="c.active"
                                                type="button"
                                                class="btn btn-sm btn-outline-danger"
                                                title="Revogar"
                                                @click="revokeCredential(c)"
                                            >
                                                <i class="ti ti-x"></i>
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- Nova credencial -->
                        <div class="border-top pt-3">
                            <h6 class="fw-semibold mb-3">Nova credencial</h6>

                            <div class="alert alert-warning small">
                                <i class="ti ti-info-circle me-1"></i>
                                Salvar uma nova credencial REVOGA a anterior automaticamente.
                                O secret só é armazenado criptografado — não é exibido em consultas futuras.
                            </div>

                            <div v-if="formError" class="alert alert-danger small">{{ formError }}</div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Label</label>
                                    <input v-model="form.label" type="text" class="form-control form-control-sm" maxlength="120" placeholder="Ex: Produção 2026">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Secret / API Key <span class="text-danger">*</span></label>
                                    <div class="input-group input-group-sm">
                                        <input
                                            v-model="form.secret"
                                            :type="showSecret ? 'text' : 'password'"
                                            class="form-control"
                                            required
                                        >
                                        <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showSecret = !showSecret">
                                            <i :class="showSecret ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <label class="form-label small">Webhook Secret (opcional)</label>
                                    <div class="input-group input-group-sm">
                                        <input
                                            v-model="form.webhook_secret"
                                            :type="showWebhook ? 'text' : 'password'"
                                            class="form-control"
                                        >
                                        <button type="button" class="btn btn-outline-secondary" tabindex="-1" @click="showWebhook = !showWebhook">
                                            <i :class="showWebhook ? 'ti ti-eye-off' : 'ti ti-eye'"></i>
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Válida de</label>
                                    <input v-model="form.valid_from" type="date" class="form-control form-control-sm">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Válida até</label>
                                    <input v-model="form.valid_to" type="date" class="form-control form-control-sm">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary btn-sm" @click="$emit('close')">Fechar</button>
                        <button type="button" class="btn btn-primary btn-sm" :disabled="saving" @click="saveCredential">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            <i v-else class="ti ti-device-floppy me-1"></i>Salvar credencial
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
