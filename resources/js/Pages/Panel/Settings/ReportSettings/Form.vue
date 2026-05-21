<script setup>
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Formulário de modelo de documentação clínica.
 *
 * Modo create: POST → store.
 * Modo edit:   PUT  → update (com método _method via Inertia useForm).
 *
 * Contents é array dinâmico (textareas editáveis); pode adicionar/remover
 * variantes do mesmo template (Receita simples, Receita controlada, etc.).
 */
const props = defineProps({
    breadcrumbs:          { type: Array,  default: () => [] },
    mode:                 { type: String, default: 'create' },   // 'create' | 'edit'
    reportSetting:        { type: Object, default: null },
    categories:           { type: Array,  default: () => [] },
    paper_sizes:          { type: Array,  default: () => [] },
    documentation_types:  { type: Array,  default: () => [] },
    urls:                 { type: Object, required: true },
});

const isEdit = computed(() => props.mode === 'edit');

const form = useForm({
    _method:             isEdit.value ? 'PATCH' : 'POST',
    title:               props.reportSetting?.title              ?? '',
    description:         props.reportSetting?.description        ?? '',
    report_category_id:  props.reportSetting?.report_category_id ?? '',
    paper_size:          props.reportSetting?.paper_size         ?? 'A4',
    font_family:         props.reportSetting?.font_family        ?? 'Helvetica',
    font_size:           props.reportSetting?.font_size          ?? 11,
    margin_top:          props.reportSetting?.margin_top         ?? 2,
    margin_right:        props.reportSetting?.margin_right       ?? 2,
    margin_bottom:       props.reportSetting?.margin_bottom      ?? 2,
    margin_left:         props.reportSetting?.margin_left        ?? 2,
    show_header:          props.reportSetting?.show_header         ?? true,
    header_show_logo:     props.reportSetting?.header_show_logo    ?? true,
    header_show_name:     props.reportSetting?.header_show_name    ?? true,
    header_show_address:  props.reportSetting?.header_show_address ?? true,
    header_show_phone:    props.reportSetting?.header_show_phone   ?? true,
    show_signature:       props.reportSetting?.show_signature      ?? true,
    signature_show_name:  props.reportSetting?.signature_show_name ?? true,
    signature_show_crm:   props.reportSetting?.signature_show_crm  ?? true,
    signature_show_rqe:   props.reportSetting?.signature_show_rqe  ?? false,
    show_footer:          props.reportSetting?.show_footer         ?? false,
    footer_text:          props.reportSetting?.footer_text         ?? '',
    footer_show_address:  props.reportSetting?.footer_show_address ?? false,
    footer_show_phone:    props.reportSetting?.footer_show_phone   ?? false,
    active:               props.reportSetting?.active              ?? true,
    contents:             props.reportSetting?.contents?.map(c => ({
        type:    c.type,
        label:   c.label,
        content: c.content,
        active:  c.active,
    })) ?? [{ type: 'recipe', label: 'Conteúdo principal', content: '', active: true }],
});

function addContent() {
    form.contents.push({ type: 'recipe', label: '', content: '', active: true });
}

function removeContent(idx) {
    form.contents.splice(idx, 1);
}

function submit() {
    const url = isEdit.value ? props.urls.update : props.urls.store;
    form.post(url, {
        preserveScroll: true,
        onSuccess: () => {
            if (!isEdit.value) {
                // redirect happens via controller
            }
        },
    });
}

const activeTab = ref('general');
</script>

<template>
    <AppLayout :title="isEdit ? 'Editar modelo' : 'Novo modelo'" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader :title="isEdit ? 'Editar modelo' : 'Novo modelo de documentação'">
                <template #actions>
                    <Link :href="urls.index" class="btn btn-outline-secondary btn-sm">
                        <i class="ti ti-arrow-left me-1"></i>Voltar
                    </Link>
                </template>
            </PageHeader>

            <form @submit.prevent="submit">
                <!-- Tabs -->
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <button type="button" :class="['nav-link', { active: activeTab === 'general' }]" @click="activeTab = 'general'">
                            <i class="ti ti-info-circle me-1"></i>Geral
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" :class="['nav-link', { active: activeTab === 'layout' }]" @click="activeTab = 'layout'">
                            <i class="ti ti-layout me-1"></i>Layout
                        </button>
                    </li>
                    <li class="nav-item">
                        <button type="button" :class="['nav-link', { active: activeTab === 'contents' }]" @click="activeTab = 'contents'">
                            <i class="ti ti-file-text me-1"></i>Conteúdos ({{ form.contents.length }})
                        </button>
                    </li>
                </ul>

                <!-- Tab: Geral -->
                <div v-show="activeTab === 'general'" class="card mb-3">
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Título <span class="text-danger">*</span></label>
                                <input v-model="form.title" type="text" maxlength="255" class="form-control"
                                       :class="{ 'is-invalid': form.errors.title }" required>
                                <div v-if="form.errors.title" class="invalid-feedback">{{ form.errors.title }}</div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Categoria</label>
                                <select v-model="form.report_category_id" class="form-select">
                                    <option value="">—</option>
                                    <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descrição</label>
                                <textarea v-model="form.description" rows="2" maxlength="1000" class="form-control"></textarea>
                            </div>
                            <div class="col-md-4">
                                <div class="form-check form-switch mt-4">
                                    <input v-model="form.active" type="checkbox" class="form-check-input" id="field_active" role="switch">
                                    <label class="form-check-label" for="field_active">Ativo</label>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Layout -->
                <div v-show="activeTab === 'layout'" class="card mb-3">
                    <div class="card-body">
                        <h6 class="fw-semibold mb-3"><i class="ti ti-page-break me-1"></i>Página</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3">
                                <label class="form-label small">Tamanho</label>
                                <select v-model="form.paper_size" class="form-select form-select-sm">
                                    <option v-for="ps in paper_sizes" :key="ps.value" :value="ps.value">{{ ps.label }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Fonte</label>
                                <input v-model="form.font_family" type="text" maxlength="50" class="form-control form-control-sm">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Tamanho</label>
                                <input v-model.number="form.font_size" type="number" min="8" max="24" class="form-control form-control-sm">
                            </div>
                        </div>

                        <h6 class="fw-semibold mb-3"><i class="ti ti-frame me-1"></i>Margens (cm)</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-3"><label class="form-label small">Topo</label>
                                <input v-model.number="form.margin_top" type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"></div>
                            <div class="col-md-3"><label class="form-label small">Direita</label>
                                <input v-model.number="form.margin_right" type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"></div>
                            <div class="col-md-3"><label class="form-label small">Inferior</label>
                                <input v-model.number="form.margin_bottom" type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"></div>
                            <div class="col-md-3"><label class="form-label small">Esquerda</label>
                                <input v-model.number="form.margin_left" type="number" step="0.1" min="0" max="10" class="form-control form-control-sm"></div>
                        </div>

                        <hr>

                        <h6 class="fw-semibold mb-3"><i class="ti ti-layout-navbar me-1"></i>Cabeçalho</h6>
                        <div class="form-check form-switch mb-2">
                            <input v-model="form.show_header" type="checkbox" class="form-check-input" id="show_header" role="switch">
                            <label class="form-check-label" for="show_header">Exibir cabeçalho</label>
                        </div>
                        <div v-if="form.show_header" class="row g-2 ms-3">
                            <div class="col-md-3"><div class="form-check"><input v-model="form.header_show_logo" type="checkbox" class="form-check-input" id="h_logo"><label class="form-check-label" for="h_logo">Logo</label></div></div>
                            <div class="col-md-3"><div class="form-check"><input v-model="form.header_show_name" type="checkbox" class="form-check-input" id="h_name"><label class="form-check-label" for="h_name">Nome</label></div></div>
                            <div class="col-md-3"><div class="form-check"><input v-model="form.header_show_address" type="checkbox" class="form-check-input" id="h_addr"><label class="form-check-label" for="h_addr">Endereço</label></div></div>
                            <div class="col-md-3"><div class="form-check"><input v-model="form.header_show_phone" type="checkbox" class="form-check-input" id="h_phone"><label class="form-check-label" for="h_phone">Telefone</label></div></div>
                        </div>

                        <hr>

                        <h6 class="fw-semibold mb-3"><i class="ti ti-signature me-1"></i>Assinatura</h6>
                        <div class="form-check form-switch mb-2">
                            <input v-model="form.show_signature" type="checkbox" class="form-check-input" id="show_sig" role="switch">
                            <label class="form-check-label" for="show_sig">Exibir assinatura</label>
                        </div>
                        <div v-if="form.show_signature" class="row g-2 ms-3">
                            <div class="col-md-3"><div class="form-check"><input v-model="form.signature_show_name" type="checkbox" class="form-check-input" id="s_name"><label class="form-check-label" for="s_name">Nome</label></div></div>
                            <div class="col-md-3"><div class="form-check"><input v-model="form.signature_show_crm" type="checkbox" class="form-check-input" id="s_crm"><label class="form-check-label" for="s_crm">CRM</label></div></div>
                            <div class="col-md-3"><div class="form-check"><input v-model="form.signature_show_rqe" type="checkbox" class="form-check-input" id="s_rqe"><label class="form-check-label" for="s_rqe">RQE</label></div></div>
                        </div>

                        <hr>

                        <h6 class="fw-semibold mb-3"><i class="ti ti-layout-bottombar me-1"></i>Rodapé</h6>
                        <div class="form-check form-switch mb-2">
                            <input v-model="form.show_footer" type="checkbox" class="form-check-input" id="show_footer" role="switch">
                            <label class="form-check-label" for="show_footer">Exibir rodapé</label>
                        </div>
                        <div v-if="form.show_footer" class="ms-3">
                            <div class="mb-2">
                                <label class="form-label small">Texto do rodapé</label>
                                <textarea v-model="form.footer_text" rows="2" maxlength="500" class="form-control form-control-sm"></textarea>
                            </div>
                            <div class="row g-2">
                                <div class="col-md-4"><div class="form-check"><input v-model="form.footer_show_address" type="checkbox" class="form-check-input" id="f_addr"><label class="form-check-label" for="f_addr">Endereço</label></div></div>
                                <div class="col-md-4"><div class="form-check"><input v-model="form.footer_show_phone" type="checkbox" class="form-check-input" id="f_phone"><label class="form-check-label" for="f_phone">Telefone</label></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Tab: Conteúdos -->
                <div v-show="activeTab === 'contents'" class="card mb-3">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h6 class="fw-semibold mb-0">Conteúdos disponíveis</h6>
                            <button type="button" class="btn btn-sm btn-outline-primary" @click="addContent">
                                <i class="ti ti-plus me-1"></i>Adicionar variante
                            </button>
                        </div>

                        <div v-if="form.contents.length === 0" class="text-muted small text-center py-3">
                            Nenhum conteúdo. Clique em "Adicionar variante" para criar.
                        </div>

                        <div v-for="(content, idx) in form.contents" :key="idx" class="card border mb-3">
                            <div class="card-body">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-4">
                                        <label class="form-label small">Tipo</label>
                                        <select v-model="content.type" class="form-select form-select-sm">
                                            <option v-for="t in documentation_types" :key="t.value" :value="t.value">{{ t.label }}</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small">Rótulo (ex: "Receita controlada")</label>
                                        <input v-model="content.label" type="text" maxlength="255" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end gap-2">
                                        <div class="form-check form-switch mb-1">
                                            <input v-model="content.active" type="checkbox" class="form-check-input" :id="`active_${idx}`" role="switch">
                                            <label class="form-check-label small" :for="`active_${idx}`">Ativo</label>
                                        </div>
                                        <button
                                            type="button"
                                            class="btn btn-sm btn-outline-danger ms-auto"
                                            @click="removeContent(idx)"
                                            title="Remover"
                                        >
                                            <i class="ti ti-trash"></i>
                                        </button>
                                    </div>
                                </div>

                                <label class="form-label small">Conteúdo (suporta HTML + placeholders)</label>
                                <textarea v-model="content.content" rows="8" class="form-control font-monospace small" required></textarea>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="d-flex justify-content-end gap-2">
                    <Link :href="urls.index" class="btn btn-light">Cancelar</Link>
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">
                        <span v-if="form.processing" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ti ti-device-floppy me-1"></i>
                        {{ isEdit ? 'Salvar alterações' : 'Cadastrar' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
