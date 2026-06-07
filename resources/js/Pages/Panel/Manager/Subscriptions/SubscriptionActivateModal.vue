<script setup>
import { ref, watch, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

const props = defineProps({
    open:          { type: Boolean, required: true },
    subscription:  { type: Object,  default: null },
    plans:         { type: Array,   default: () => [] },
    billingCycles: { type: Array,   default: () => [] },
    gateways:      { type: Array,   default: () => [] },
    t:             { type: Object,  default: () => ({}) },
});

const emit = defineEmits(['close', 'saved']);

const saving  = ref(false);
const error   = ref('');
const form    = ref({ plan_id: '', billing_cycle: '', gateway: '' });

// Preserva o rótulo "Nome — Preço" do plano no SearchSelect.
const planOptions = computed(() =>
    props.plans.map((p) => ({ id: p.id, label: p.price ? `${p.name} — ${p.price}` : p.name })),
);

watch(() => props.open, (val) => {
    if (val) {
        form.value  = { plan_id: '', billing_cycle: props.billingCycles[0]?.value ?? '', gateway: '' };
        error.value = '';
    }
});

async function submit() {
    saving.value = true;
    error.value  = '';
    try {
        const res = await fetch(route('manager.subscriptions.activate'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'Accept': 'application/json',
            },
            body: JSON.stringify({
                entity_id:     props.subscription?.entity_id,
                plan_id:       form.value.plan_id,
                billing_cycle: form.value.billing_cycle,
                gateway:       form.value.gateway || undefined,
            }),
        });

        const json = await res.json();

        if (!res.ok) {
            error.value = json.message ?? 'Erro ao ativar assinatura.';
            return;
        }

        emit('saved', json.message ?? t.activate_success);
        emit('close');
        router.reload({ only: ['subscriptions', 'total'] });
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
            class="modal fade show d-block"
            tabindex="-1"
            style="background:rgba(0,0,0,.4)"
            @click.self="$emit('close')"
        >
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="ti ti-player-play me-2 text-success"></i>{{ t.activate_title }}
                        </h5>
                        <button type="button" class="btn-close" @click="$emit('close')"></button>
                    </div>

                    <div class="modal-body">
                        <!-- Empresa -->
                        <div class="mb-3">
                            <label class="form-label fw-semibold">{{ t.activate_company }}</label>
                            <p class="form-control-plaintext fw-bold mb-0">{{ subscription?.entity_name ?? '—' }}</p>
                        </div>

                        <!-- Plano -->
                        <div class="mb-3">
                            <label class="form-label">{{ t.activate_plan }}</label>
                            <SearchSelect
                                v-model="form.plan_id"
                                :options="planOptions"
                                :label-key="'label'"
                                :placeholder="t.activate_plan_select"
                                :clearable="false"
                            />

                        </div>

                        <!-- Ciclo -->
                        <div class="mb-3">
                            <label class="form-label">{{ t.activate_cycle }}</label>
                            <SearchSelect
                                v-model="form.billing_cycle"
                                :options="billingCycles"
                                :value-key="'value'"
                                :label-key="'label'"
                                :clearable="false"
                            />
                        </div>

                        <!-- Gateway -->
                        <div class="mb-3">
                            <label class="form-label">{{ t.activate_gateway }}</label>
                            <SearchSelect
                                v-model="form.gateway"
                                :options="gateways"
                                :value-key="'value'"
                                :label-key="'label'"
                                :placeholder="t.activate_gateway_default"
                            />
                            <div class="form-text">{{ t.activate_gateway_hint }}</div>
                        </div>

                        <!-- Erro -->
                        <div v-if="error" class="alert alert-danger py-2 small mb-0">
                            <i class="ti ti-alert-circle me-1"></i>{{ error }}
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" @click="$emit('close')">{{ t.btn_cancel }}</button>
                        <button type="button" class="btn btn-success" :disabled="saving" @click="submit">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ t.activate_btn }}
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </Teleport>
</template>
