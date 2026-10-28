<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

/**
 * Configurações → Agenda: percentual de "poucas vagas" do calendário lateral
 * da Agenda (dia com N% ou menos dos horários livres; padrão 20%).
 */
const props = defineProps({
    fewSlotsPercent: { type: Number, default: 20 },
    t: { type: Object, default: () => ({}) },
});

const percent = ref(props.fewSlotsPercent);
const saving = ref(false);
const message = ref('');
const error = ref('');

async function save() {
    saving.value = true;
    message.value = '';
    error.value = '';
    try {
        const res = await fetch(route('panel.setting.schedule.update'), {
            method: 'PATCH',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ few_slots_percent: Number(percent.value) }),
        });
        const json = await res.json().catch(() => ({}));
        if (!res.ok) {
            error.value = json.errors?.few_slots_percent?.[0] ?? json.message ?? '';
            return;
        }
        percent.value = json.few_slots_percent;
        message.value = json.message;
        if (window.showSuccessToast) window.showSuccessToast(json.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppLayout :title="t.settings_title ?? 'Agenda'" :breadcrumbs="[]">
        <div class="container-fluid py-3">
            <PageHeader :title="t.settings_title ?? 'Agenda'" :subtitle="t.settings_subtitle ?? ''" />

            <div class="card" style="max-width: 760px">
                <div class="card-body">
                    <form @submit.prevent="save">
                        <label for="few-slots-percent" class="form-label fw-semibold">
                            {{ t.settings_few_slots ?? 'Poucas vagas' }}
                        </label>
                        <div class="d-flex align-items-center gap-2" style="max-width: 220px">
                            <input
                                id="few-slots-percent"
                                v-model.number="percent"
                                type="number"
                                min="1"
                                max="90"
                                class="form-control"
                                :class="{ 'is-invalid': error }"
                                aria-describedby="few-slots-hint"
                            />
                            <span class="text-muted">%</span>
                        </div>
                        <div v-if="error" class="invalid-feedback d-block">{{ error }}</div>
                        <p id="few-slots-hint" class="text-muted small mt-2">
                            {{
                                t.settings_few_slots_hint ??
                                'O dia aparece como "poucas vagas" no calendário da Agenda quando restam esta porcentagem ou menos dos horários livres.'
                            }}
                        </p>
                        <button type="submit" class="btn btn-primary" :disabled="saving">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>
                            {{ t.settings_save ?? 'Salvar' }}
                        </button>
                        <span v-if="message" class="text-success small ms-2" role="status">{{ message }}</span>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
