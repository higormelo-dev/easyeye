<script setup>
import { ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * Política da clínica: médicos veem os próprios repasses ("Meus repasses")?
 * Só admin altera (expõe valores ao médico — a rota exige entity.role:admin);
 * para os demais o switch fica desabilitado, com o aviso.
 *
 * O switch reflete a escolha na hora e volta ao valor do servidor ao terminar
 * a visita (sucesso, erro ou recusa por permissão).
 */
const props = defineProps({
    settings: { type: Object, default: () => ({}) },   // { doctor_payouts_visible, can_manage }
    action:   { type: String, required: true },        // routes.settings (PATCH)
    t:        { type: Object, default: () => ({}) },
});

const uid = useId();
const ids = {
    title:  `dp-settings-title-${uid}`,
    toggle: `dp-settings-visible-${uid}`,
    hint:   `dp-settings-hint-${uid}`,
    admin:  `dp-settings-admin-${uid}`,
};

const visible = ref(Boolean(props.settings.doctor_payouts_visible));
const saving  = ref(false);

watch(() => props.settings.doctor_payouts_visible, (value) => { visible.value = Boolean(value); });

function save(event) {
    if (!props.settings.can_manage || saving.value) return;

    const next = Boolean(event.target.checked);
    visible.value = next;

    router.patch(props.action, { doctor_payouts_visible: next }, {
        preserveScroll: true,
        preserveState:  true,
        onStart:  () => { saving.value = true; },
        onFinish: () => {
            saving.value  = false;
            visible.value = Boolean(props.settings.doctor_payouts_visible);
        },
    });
}
</script>

<template>
    <section class="card mb-3" :aria-labelledby="ids.title" data-test="rules-settings">
        <div class="card-body">
            <h2 :id="ids.title" class="h6 fw-bold mb-2">
                <i class="ti ti-eye-check me-1 text-primary" aria-hidden="true"></i>{{ t.settings_title }}
            </h2>
            <div class="form-check form-switch mb-1">
                <input
                    :id="ids.toggle"
                    class="form-check-input"
                    type="checkbox"
                    role="switch"
                    :checked="visible"
                    :disabled="!settings.can_manage || saving"
                    :aria-describedby="settings.can_manage ? ids.hint : `${ids.hint} ${ids.admin}`"
                    data-test="settings-visible"
                    @change="save"
                >
                <label :for="ids.toggle" class="form-check-label fw-medium">{{ t.settings_visible }}</label>
                <span v-if="saving" class="spinner-border spinner-border-sm ms-2 text-muted" role="status">
                    <span class="visually-hidden">{{ t.settings_title }}</span>
                </span>
            </div>
            <p :id="ids.hint" class="small text-muted mb-0">{{ t.settings_visible_hint }}</p>
            <p v-if="!settings.can_manage" :id="ids.admin" class="small text-muted mb-0 mt-1" data-test="settings-admin-only">
                <i class="ti ti-shield-lock me-1" aria-hidden="true"></i>{{ t.admin_only }}
            </p>
        </div>
    </section>
</template>
