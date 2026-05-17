<script setup>
import { ref, computed, watch } from 'vue';

const props = defineProps({
    open:    { type: Boolean, required: true },
    gateway: { type: Object,  default: null },
    t:       { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close']);

const entities    = ref([]);
const loading     = ref(false);
const loadError   = ref('');
const search      = ref('');
const reloadPage  = ref(false); // true if any toggle happened

const filtered = computed(() => {
    const q = search.value.toLowerCase().trim();
    if (!q) return entities.value;
    return entities.value.filter(e =>
        e.name.toLowerCase().includes(q) || e.code.toLowerCase().includes(q)
    );
});

watch(() => props.open, async (val) => {
    if (val && props.gateway) {
        search.value    = '';
        reloadPage.value = false;
        await loadEntities();
    }
    if (!val) { entities.value = []; }
});

async function loadEntities() {
    loading.value   = true;
    loadError.value = '';
    entities.value  = [];
    try {
        const res  = await fetch(props.gateway.entity_access_url);
        const json = await res.json();
        if (!res.ok) throw new Error(json.message);
        entities.value = json.data ?? [];
    } catch {
        loadError.value = props.t.js_error_load_clinics ?? 'Erro ao carregar clínicas.';
    } finally {
        loading.value = false;
    }
}

async function toggle(entity) {
    const prev = entity.enabled;
    entity.enabled = !entity.enabled; // optimistic
    try {
        const res  = await fetch(entity.toggle_url, {
            method: 'PATCH',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
        });
        const json = await res.json();
        if (!res.ok) {
            entity.enabled = prev;
            if (window.showErrorToast) showErrorToast(json.message ?? props.t.js_error_generic);
            return;
        }
        entity.enabled   = json.enabled;
        reloadPage.value = true;
        if (window.showSuccessToast) showSuccessToast(json.message);
    } catch {
        entity.enabled = prev;
    }
}

function handleClose() {
    if (reloadPage.value) {
        // Reload only gateway counts — Inertia partial reload
        import('@inertiajs/vue3').then(({ router }) => {
            router.reload({ only: ['gateways'] });
        });
    }
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block"
            tabindex="-1"
            style="background:rgba(0,0,0,.4)"
            @click.self="handleClose"
        >
            <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <!-- Header -->
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-building-hospital me-2"></i>{{ t.modal_ea_title }}
                            <span v-if="gateway" class="text-muted fw-normal ms-1">— {{ gateway.name }}</span>
                        </h5>
                        <button type="button" class="btn-close" @click="handleClose"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Info alert -->
                        <div class="alert alert-info d-flex gap-2 align-items-start py-2 mb-3">
                            <i class="ti ti-info-circle flex-shrink-0 mt-1"></i>
                            <div class="small">{{ t.modal_ea_alert }}</div>
                        </div>

                        <!-- Search -->
                        <div class="mb-3">
                            <input
                                v-model="search"
                                type="text"
                                class="form-control form-control-sm"
                                :placeholder="t.modal_ea_search_ph"
                            >
                        </div>

                        <!-- Loading -->
                        <div v-if="loading" class="text-center py-4">
                            <div class="spinner-border spinner-border-sm text-primary"></div>
                            <p class="text-muted small mt-2 mb-0">{{ t.modal_ea_loading }}</p>
                        </div>

                        <!-- Error -->
                        <div v-else-if="loadError" class="alert alert-danger small py-2">
                            <i class="ti ti-alert-circle me-1"></i>{{ loadError }}
                        </div>

                        <!-- Empty search result -->
                        <div v-else-if="filtered.length === 0" class="text-center py-4 text-muted small">
                            {{ t.modal_ea_empty }}
                        </div>

                        <!-- Entity list -->
                        <template v-else>
                            <div
                                v-for="entity in filtered"
                                :key="entity.entity_id"
                                class="d-flex align-items-center justify-content-between py-2 border-bottom"
                            >
                                <div>
                                    <span class="fw-semibold small">{{ entity.name }}</span>
                                    <span class="badge badge-soft-secondary ms-2" style="font-size:.7rem;">{{ entity.code }}</span>
                                </div>
                                <div class="form-check form-switch mb-0">
                                    <input
                                        class="form-check-input"
                                        type="checkbox"
                                        role="switch"
                                        :checked="entity.enabled"
                                        :title="entity.enabled ? t.modal_ea_disable : t.modal_ea_enable"
                                        @change="toggle(entity)"
                                    >
                                </div>
                            </div>
                        </template>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary btn-sm" @click="handleClose">
                            {{ t.modal_ea_close }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
