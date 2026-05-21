<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';

const props = defineProps({
    open:       { type: Boolean, required: true },
    recordId:   { type: String,  default: null },
    categories: { type: Array,   default: () => [] },
    paperSizes: { type: Array,   default: () => [] },
    fontFamilies: { type: Array, default: () => [] },
    t:          { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const loading  = ref(false);
const saving   = ref(false);
const errors   = ref({});
const activeTab = ref('general');

const defaultForm = () => ({
    title:                '',
    description:          '',
    report_category_id:   '',
    paper_size:           'A4',
    font_family:          'Arial',
    font_size:            11,
    margin_top:           2.0,
    margin_right:         2.0,
    margin_bottom:        2.0,
    margin_left:          2.0,
    active:               true,
    show_header:          true,
    header_show_logo:     true,
    header_show_name:     true,
    header_show_address:  false,
    header_show_phone:    false,
    show_signature:       true,
    signature_show_name:  true,
    signature_show_crm:   true,
    signature_show_rqe:   true,
    show_footer:          false,
    footer_text:          '',
    footer_show_address:  false,
    footer_show_phone:    false,
});

const form = ref(defaultForm());

const isEdit   = computed(() => !!props.recordId);
const panelTitle = computed(() => isEdit.value ? props.t.form_title_edit : props.t.form_title_create);

function resetForm() {
    form.value   = defaultForm();
    errors.value = {};
    activeTab.value = 'general';
}

async function loadRecord(id) {
    loading.value = true;
    try {
        const res  = await fetch(route('manager.report-settings.show', id));
        const json = await res.json();
        const d    = json.data;
        form.value = {
            title:               d.title               ?? '',
            description:         d.description         ?? '',
            report_category_id:  d.report_category_id  ?? '',
            paper_size:          d.paper_size           ?? 'A4',
            font_family:         d.font_family          ?? 'Arial',
            font_size:           d.font_size            ?? 11,
            margin_top:          d.margin_top           ?? 2.0,
            margin_right:        d.margin_right         ?? 2.0,
            margin_bottom:       d.margin_bottom        ?? 2.0,
            margin_left:         d.margin_left          ?? 2.0,
            active:              d.active               ?? true,
            show_header:         d.show_header          ?? true,
            header_show_logo:    d.header_show_logo     ?? true,
            header_show_name:    d.header_show_name     ?? true,
            header_show_address: d.header_show_address  ?? false,
            header_show_phone:   d.header_show_phone    ?? false,
            show_signature:      d.show_signature       ?? true,
            signature_show_name: d.signature_show_name  ?? true,
            signature_show_crm:  d.signature_show_crm   ?? true,
            signature_show_rqe:  d.signature_show_rqe   ?? true,
            show_footer:         d.show_footer          ?? false,
            footer_text:         d.footer_text          ?? '',
            footer_show_address: d.footer_show_address  ?? false,
            footer_show_phone:   d.footer_show_phone    ?? false,
        };
    } finally {
        loading.value = false;
    }
}

watch(() => props.open, (val) => {
    if (val) {
        resetForm();
        if (props.recordId) loadRecord(props.recordId);
    }
});

async function submit() {
    saving.value = true;
    errors.value = {};
    try {
        const url    = isEdit.value
            ? route('manager.report-settings.update', props.recordId)
            : route('manager.report-settings.store');
        const method = isEdit.value ? 'PUT' : 'POST';

        const res = await fetch(url, {
            method,
            headers: {
                'Content-Type':  'application/json',
                'Accept':        'application/json',
                'X-CSRF-TOKEN':  document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify(form.value),
        });

        const json = await res.json();

        if (!res.ok) {
            errors.value = json.errors ?? {};
            if (Object.keys(errors.value).some(k => ['title', 'description', 'report_category_id', 'paper_size', 'font_family', 'font_size', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'active'].includes(k))) {
                activeTab.value = 'general';
            } else if (Object.keys(errors.value).some(k => k.startsWith('header'))) {
                activeTab.value = 'header';
            } else if (Object.keys(errors.value).some(k => k.startsWith('signature'))) {
                activeTab.value = 'signature';
            } else if (Object.keys(errors.value).some(k => k.startsWith('footer'))) {
                activeTab.value = 'footer';
            }
            return;
        }

        emit('saved');
        emit('close');
        router.reload({ only: ['reportSettings', 'total'] });
    } finally {
        saving.value = false;
    }
}

function err(field) {
    const e = errors.value[field];
    return Array.isArray(e) ? e[0] : (e ?? '');
}
</script>

<template>
    <OffcanvasPanel
        :open="open"
        :width="620"
        :loading="loading"
        :loading-label="t.loading"
        @close="$emit('close')"
    >
        <template #header>
            <div>
                <h5 class="mb-0 fw-semibold">
                    <i class="ti ti-file-text me-2 text-primary"></i>{{ panelTitle }}
                </h5>
            </div>
        </template>

        <template #tabs>
            <ul class="nav nav-tabs border-0 pt-1">
                <li class="nav-item">
                    <button
                        class="nav-link"
                        :class="{ active: activeTab === 'general' }"
                        @click="activeTab = 'general'"
                    >
                        <i class="ti ti-settings me-1"></i>{{ t.tab_general }}
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        class="nav-link"
                        :class="{ active: activeTab === 'header' }"
                        @click="activeTab = 'header'"
                    >
                        <i class="ti ti-layout-navbar me-1"></i>{{ t.tab_header }}
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        class="nav-link"
                        :class="{ active: activeTab === 'signature' }"
                        @click="activeTab = 'signature'"
                    >
                        <i class="ti ti-writing me-1"></i>{{ t.tab_signature }}
                    </button>
                </li>
                <li class="nav-item">
                    <button
                        class="nav-link"
                        :class="{ active: activeTab === 'footer' }"
                        @click="activeTab = 'footer'"
                    >
                        <i class="ti ti-layout-bottombar me-1"></i>{{ t.tab_footer }}
                    </button>
                </li>
            </ul>
        </template>

        <!-- ── Tab: General ────────────────────────────────────────────────── -->
        <div v-show="activeTab === 'general'" class="row g-3">

            <div class="col-12">
                <label class="form-label">{{ t.field_title }} <span class="text-danger">*</span></label>
                <input
                    v-model="form.title"
                    type="text"
                    class="form-control"
                    :class="{ 'is-invalid': err('title') }"
                    maxlength="255"
                >
                <div v-if="err('title')" class="invalid-feedback">{{ err('title') }}</div>
            </div>

            <div class="col-sm-6">
                <label class="form-label">{{ t.field_category }}</label>
                <select
                    v-model="form.report_category_id"
                    class="form-select"
                    :class="{ 'is-invalid': err('report_category_id') }"
                >
                    <option value="">{{ t.select_option }}</option>
                    <option v-for="cat in categories" :key="cat.id" :value="cat.id">{{ cat.name }}</option>
                </select>
                <div v-if="err('report_category_id')" class="invalid-feedback">{{ err('report_category_id') }}</div>
            </div>

            <div class="col-sm-6">
                <label class="form-label">{{ t.field_paper_size }}</label>
                <select v-model="form.paper_size" class="form-select">
                    <option v-for="sz in paperSizes" :key="sz" :value="sz">{{ sz }}</option>
                </select>
            </div>

            <div class="col-sm-6">
                <label class="form-label">{{ t.field_font_family }}</label>
                <select v-model="form.font_family" class="form-select">
                    <option v-for="f in fontFamilies" :key="f" :value="f">{{ f }}</option>
                </select>
            </div>

            <div class="col-sm-3">
                <label class="form-label">{{ t.field_font_size }}</label>
                <input
                    v-model.number="form.font_size"
                    type="number" min="8" max="24"
                    class="form-control"
                >
            </div>

            <div class="col-sm-3">
                <label class="form-label">{{ t.field_active }}</label>
                <select v-model="form.active" class="form-select">
                    <option :value="true">{{ t.yes }}</option>
                    <option :value="false">{{ t.no }}</option>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label">{{ t.field_description }}</label>
                <textarea
                    v-model="form.description"
                    class="form-control"
                    :class="{ 'is-invalid': err('description') }"
                    rows="2"
                    maxlength="1000"
                ></textarea>
                <div v-if="err('description')" class="invalid-feedback">{{ err('description') }}</div>
            </div>

            <!-- Margins -->
            <div class="col-12">
                <p class="fw-semibold text-muted small text-uppercase mb-2">{{ t.field_margins }}</p>
                <div class="row g-2">
                    <div class="col-6 col-sm-3">
                        <label class="form-label small">{{ t.field_margin_top }}</label>
                        <input v-model.number="form.margin_top" type="number" step="0.5" min="0" max="10" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-sm-3">
                        <label class="form-label small">{{ t.field_margin_right }}</label>
                        <input v-model.number="form.margin_right" type="number" step="0.5" min="0" max="10" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-sm-3">
                        <label class="form-label small">{{ t.field_margin_bottom }}</label>
                        <input v-model.number="form.margin_bottom" type="number" step="0.5" min="0" max="10" class="form-control form-control-sm">
                    </div>
                    <div class="col-6 col-sm-3">
                        <label class="form-label small">{{ t.field_margin_left }}</label>
                        <input v-model.number="form.margin_left" type="number" step="0.5" min="0" max="10" class="form-control form-control-sm">
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Tab: Header ─────────────────────────────────────────────────── -->
        <div v-show="activeTab === 'header'">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <p class="text-muted small mb-0">{{ t.header_desc }}</p>
                <div class="form-check form-switch ms-3 mb-0 flex-shrink-0">
                    <input
                        v-model="form.show_header"
                        class="form-check-input" type="checkbox" role="switch"
                        id="show_header"
                    >
                    <label class="form-check-label fw-semibold" for="show_header">{{ t.field_show_header }}</label>
                </div>
            </div>

            <transition name="fade">
                <div v-if="form.show_header" class="row g-3">
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.header_show_logo" class="form-check-input" type="checkbox" role="switch" id="header_show_logo">
                            <label class="form-check-label" for="header_show_logo">
                                <i class="ti ti-photo me-1 text-muted"></i>{{ t.field_header_show_logo }}
                            </label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.header_show_name" class="form-check-input" type="checkbox" role="switch" id="header_show_name">
                            <label class="form-check-label" for="header_show_name">
                                <i class="ti ti-building-hospital me-1 text-muted"></i>{{ t.field_header_show_name }}
                            </label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.header_show_address" class="form-check-input" type="checkbox" role="switch" id="header_show_address">
                            <label class="form-check-label" for="header_show_address">
                                <i class="ti ti-map-pin me-1 text-muted"></i>{{ t.field_header_show_address }}
                            </label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.header_show_phone" class="form-check-input" type="checkbox" role="switch" id="header_show_phone">
                            <label class="form-check-label" for="header_show_phone">
                                <i class="ti ti-phone me-1 text-muted"></i>{{ t.field_header_show_phone }}
                            </label>
                        </div>
                    </div>
                </div>
            </transition>

            <div v-if="!form.show_header" class="text-center text-muted py-5">
                <i class="ti ti-layout-navbar-collapse fs-1 d-block mb-2 opacity-25"></i>
                <p class="small">{{ t.field_show_header }} desativado</p>
            </div>
        </div>

        <!-- ── Tab: Signature ──────────────────────────────────────────────── -->
        <div v-show="activeTab === 'signature'">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <p class="text-muted small mb-0">{{ t.signature_desc }}</p>
                <div class="form-check form-switch ms-3 mb-0 flex-shrink-0">
                    <input
                        v-model="form.show_signature"
                        class="form-check-input" type="checkbox" role="switch"
                        id="show_signature"
                    >
                    <label class="form-check-label fw-semibold" for="show_signature">{{ t.field_show_signature }}</label>
                </div>
            </div>

            <transition name="fade">
                <div v-if="form.show_signature" class="row g-3">
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.signature_show_name" class="form-check-input" type="checkbox" role="switch" id="signature_show_name">
                            <label class="form-check-label" for="signature_show_name">
                                <i class="ti ti-user-check me-1 text-muted"></i>{{ t.field_signature_show_name }}
                            </label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.signature_show_crm" class="form-check-input" type="checkbox" role="switch" id="signature_show_crm">
                            <label class="form-check-label" for="signature_show_crm">
                                <i class="ti ti-id-badge me-1 text-muted"></i>{{ t.field_signature_show_crm }}
                            </label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.signature_show_rqe" class="form-check-input" type="checkbox" role="switch" id="signature_show_rqe">
                            <label class="form-check-label" for="signature_show_rqe">
                                <i class="ti ti-star me-1 text-muted"></i>{{ t.field_signature_show_rqe }}
                            </label>
                        </div>
                    </div>
                </div>
            </transition>

            <div v-if="!form.show_signature" class="text-center text-muted py-5">
                <i class="ti ti-writing fs-1 d-block mb-2 opacity-25"></i>
                <p class="small">{{ t.field_show_signature }} desativado</p>
            </div>
        </div>

        <!-- ── Tab: Footer ─────────────────────────────────────────────────── -->
        <div v-show="activeTab === 'footer'">
            <div class="d-flex align-items-center justify-content-between mb-3">
                <p class="text-muted small mb-0">{{ t.footer_desc }}</p>
                <div class="form-check form-switch ms-3 mb-0 flex-shrink-0">
                    <input
                        v-model="form.show_footer"
                        class="form-check-input" type="checkbox" role="switch"
                        id="show_footer"
                    >
                    <label class="form-check-label fw-semibold" for="show_footer">{{ t.field_show_footer }}</label>
                </div>
            </div>

            <transition name="fade">
                <div v-if="form.show_footer" class="row g-3">
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.footer_show_address" class="form-check-input" type="checkbox" role="switch" id="footer_show_address">
                            <label class="form-check-label" for="footer_show_address">
                                <i class="ti ti-map-pin me-1 text-muted"></i>{{ t.field_footer_show_address }}
                            </label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input v-model="form.footer_show_phone" class="form-check-input" type="checkbox" role="switch" id="footer_show_phone">
                            <label class="form-check-label" for="footer_show_phone">
                                <i class="ti ti-phone me-1 text-muted"></i>{{ t.field_footer_show_phone }}
                            </label>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">{{ t.field_footer_text }}</label>
                        <input
                            v-model="form.footer_text"
                            type="text"
                            class="form-control"
                            :class="{ 'is-invalid': err('footer_text') }"
                            :placeholder="t.field_footer_text_ph"
                            maxlength="500"
                        >
                        <div v-if="err('footer_text')" class="invalid-feedback">{{ err('footer_text') }}</div>
                    </div>
                </div>
            </transition>

            <div v-if="!form.show_footer" class="text-center text-muted py-5">
                <i class="ti ti-layout-bottombar fs-1 d-block mb-2 opacity-25"></i>
                <p class="small">{{ t.field_show_footer }} desativado</p>
            </div>
        </div>

        <template #footer>
            <button type="button" class="btn btn-secondary" @click="$emit('close')">
                {{ t.cancel }}
            </button>
            <button type="button" class="btn btn-primary" :disabled="saving" @click="submit">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                {{ saving ? t.saving : t.save }}
            </button>
        </template>
    </OffcanvasPanel>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity .2s ease; }
.fade-enter-from, .fade-leave-to       { opacity: 0; }
</style>
