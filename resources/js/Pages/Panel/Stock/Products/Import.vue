<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Importação em massa de produtos via CSV (GAP fechado — revisão pós-Fase
 * 4, "melhorar o módulo de estoque"). Fluxo SÍNCRONO em 2 passos — sem
 * Job/fila/polling como o de pacientes (ver docblock de
 * App\Services\Stock\ProductImportService pro porquê):
 *   1. Envia o arquivo → back-end lê e valida TUDO, devolve preview
 *      (linhas válidas prontas + linhas com erro e o motivo).
 *   2. Usuário revisa e confirma → back-end cria de fato, devolve
 *      quantos entraram + quem foi pulado (ex.: duplicata surgida entre
 *      o preview e a confirmação).
 */
const props = defineProps({
    breadcrumbs: { type: Array,  default: () => [] },
    maxRows:     { type: Number, required: true },
    routes:      { type: Object, required: true }, // { template, preview, confirm, products }
});

const fileInput = ref(null);
const file       = ref(null);
const previewing = ref(false);
const previewError = ref('');
const preview     = ref(null); // { valid, errors, total } | null

function onFileChange(e) {
    file.value = e.target.files[0] ?? null;
    preview.value = null;
    previewError.value = '';
}

async function submitPreview() {
    if (!file.value) return;

    previewing.value = true;
    previewError.value = '';
    try {
        const formData = new FormData();
        formData.append('file', file.value);
        const { data } = await window.axios.post(props.routes.preview, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        preview.value = data;
    } catch (e) {
        previewError.value = e.response?.data?.message ?? 'Não foi possível ler o arquivo.';
    } finally {
        previewing.value = false;
    }
}

const confirming    = ref(false);
const confirmResult = ref(null); // { created, skipped } | null
const confirmError  = ref('');

async function confirmImport() {
    if (!preview.value?.valid?.length) return;

    confirming.value = true;
    confirmError.value = '';
    try {
        const { data } = await window.axios.post(props.routes.confirm, { rows: preview.value.valid });
        confirmResult.value = data;
        preview.value = null;
        file.value = null;
        if (fileInput.value) fileInput.value.value = '';
    } catch (e) {
        confirmError.value = e.response?.data?.message ?? 'Não foi possível confirmar a importação.';
    } finally {
        confirming.value = false;
    }
}

function startOver() {
    preview.value = null;
    confirmResult.value = null;
    file.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

const hasCategoryWarnings = computed(() => (preview.value?.valid ?? []).some((r) => r.category_not_found));
</script>

<template>
    <AppLayout title="Importar produtos" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Importar produtos">
                <template #actions>
                    <Link :href="routes.products" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-arrow-left me-1"></i>Voltar pra Produtos
                    </Link>
                </template>
            </PageHeader>

            <div v-if="confirmResult" class="alert alert-success">
                <strong>{{ confirmResult.created }} produto(s) importado(s) com sucesso.</strong>
                <div v-if="confirmResult.skipped.length > 0" class="mt-2 small">
                    {{ confirmResult.skipped.length }} pulado(s) na confirmação:
                    <ul class="mb-0">
                        <li v-for="(s, i) in confirmResult.skipped" :key="i">{{ s.name }} — {{ s.reason }}</li>
                    </ul>
                </div>
                <button type="button" class="btn btn-sm btn-outline-success mt-2" @click="startOver">Importar outro arquivo</button>
            </div>

            <template v-else>
                <!-- Passo 1: upload -->
                <div v-if="!preview" class="card">
                    <div class="card-body">
                        <p class="text-muted">
                            Envie uma planilha CSV com os produtos a cadastrar. Colunas esperadas: <code>nome</code> (obrigatório),
                            <code>unidade</code> (obrigatório — un, cx, fr, par, amp, ml, mg, g, l ou o nome por extenso),
                            <code>sku</code>, <code>codigo_barras</code>, <code>categoria</code>, <code>preco_venda</code>,
                            <code>estoque_minimo</code>, <code>estoque_maximo</code>. Limite de {{ maxRows }} linhas por arquivo.
                        </p>
                        <a :href="routes.template" class="btn btn-outline-secondary btn-sm mb-3">
                            <i class="ti ti-download me-1"></i>Baixar modelo CSV
                        </a>

                        <div class="mb-3">
                            <input ref="fileInput" type="file" accept=".csv,text/csv" class="form-control" @change="onFileChange">
                        </div>

                        <div v-if="previewError" class="alert alert-danger py-2">{{ previewError }}</div>

                        <button type="button" class="btn btn-primary" :disabled="!file || previewing" @click="submitPreview">
                            <span v-if="previewing" class="spinner-border spinner-border-sm me-1"></span>
                            Ler e validar arquivo
                        </button>
                    </div>
                </div>

                <!-- Passo 2: preview + confirmação -->
                <div v-else>
                    <div class="alert alert-light border mb-3">
                        <strong>{{ preview.valid.length }}</strong> de <strong>{{ preview.total }}</strong> linha(s) prontas pra importar.
                        <span v-if="preview.errors.length > 0" class="text-danger">{{ preview.errors.length }} com erro (não serão importadas).</span>
                    </div>

                    <div v-if="hasCategoryWarnings" class="alert alert-warning py-2 small">
                        Algumas linhas citam uma categoria que não existe nesta clínica — o produto será importado SEM categoria (você pode categorizar depois).
                    </div>

                    <div v-if="preview.errors.length > 0" class="mb-3">
                        <h6>Linhas com erro</h6>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead><tr><th>Linha</th><th>Nome</th><th>Motivo</th></tr></thead>
                                <tbody>
                                    <tr v-for="(e, i) in preview.errors" :key="i">
                                        <td>{{ e.line }}</td>
                                        <td>{{ e.name }}</td>
                                        <td class="text-danger small">{{ e.reason }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="preview.valid.length > 0" class="mb-3">
                        <h6>Prontos pra importar</h6>
                        <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
                            <table class="table table-sm table-hover">
                                <thead><tr><th>Nome</th><th>Unidade</th><th>SKU</th><th>Categoria</th></tr></thead>
                                <tbody>
                                    <tr v-for="(r, i) in preview.valid" :key="i">
                                        <td>{{ r.name }}</td>
                                        <td>{{ r.unit }}</td>
                                        <td class="small text-muted">{{ r.sku ?? '—' }}</td>
                                        <td class="small" :class="{ 'text-warning': r.category_not_found }">
                                            {{ r.category_not_found ? 'Não encontrada — sem categoria' : (r.product_category_id ? 'OK' : '—') }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div v-if="confirmError" class="alert alert-danger py-2">{{ confirmError }}</div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-light" :disabled="confirming" @click="startOver">Cancelar</button>
                        <button type="button" class="btn btn-primary" :disabled="preview.valid.length === 0 || confirming" @click="confirmImport">
                            <span v-if="confirming" class="spinner-border spinner-border-sm me-1"></span>
                            Confirmar importação de {{ preview.valid.length }} produto(s)
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </AppLayout>
</template>
