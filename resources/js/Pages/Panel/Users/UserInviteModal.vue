<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import OffcanvasPanel from '@/Components/Panel/OffcanvasPanel.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * Convidar quem já usa o EasyEye (outra clínica): só e-mail + perfil. O
 * servidor responde sempre a mesma coisa — exista conta ou não — e só quem tem
 * acesso recebe o convite (UserInvitationService). Médico não aparece aqui:
 * tem o convite próprio no cadastro de médicos.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    roles: { type: Object, default: () => ({}) }, // perfil convidável: rule → rótulo
    t: { type: Object, default: () => ({}) }, // lang access_control (usa `invitation`)
});

const emit = defineEmits(['close']);

const it = computed(() => props.t.invitation ?? {});
const roleOptions = computed(() => Object.entries(props.roles).map(([value, label]) => ({ value, label })));

const form = useForm({ email: '', rule: '' });

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

function submit() {
    form.post(route('panel.accesscontrol.users.invitations.store'), {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
}
</script>

<template>
    <OffcanvasPanel :open="open" :width="480" :close-label="it.close" @close="emit('close')">
        <template #header>
            <h5 class="mb-0 fw-semibold">
                <i class="ti ti-mail-forward me-2 text-primary" aria-hidden="true"></i>{{ it.title }}
            </h5>
        </template>

        <p class="text-muted small">{{ it.intro }}</p>

        <form id="user-invite-form" novalidate @submit.prevent="submit">
            <div class="mb-3">
                <label for="invite-email" class="form-label fw-semibold"
                    >{{ it.email }} <span class="text-danger" aria-hidden="true">*</span></label
                >
                <input
                    id="invite-email"
                    v-model="form.email"
                    type="email"
                    class="form-control"
                    :class="{ 'is-invalid': form.errors.email }"
                    autocomplete="off"
                    :aria-invalid="form.errors.email ? 'true' : 'false'"
                    :aria-describedby="form.errors.email ? 'invite-email-error' : undefined"
                    required
                />
                <div v-if="form.errors.email" id="invite-email-error" class="invalid-feedback d-block">
                    {{ form.errors.email }}
                </div>
            </div>

            <div class="mb-1">
                <label class="form-label fw-semibold"
                    >{{ it.rule }} <span class="text-danger" aria-hidden="true">*</span></label
                >
                <SearchSelect
                    v-model="form.rule"
                    :options="roleOptions"
                    :value-key="'value'"
                    :label-key="'label'"
                    :clearable="false"
                    :invalid="!!form.errors.rule"
                />
                <div v-if="form.errors.rule" class="invalid-feedback d-block">{{ form.errors.rule }}</div>
            </div>
            <p class="form-text">{{ it.rule_hint }}</p>
        </form>

        <template #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ it.close }}</button>
            <button type="submit" form="user-invite-form" class="btn btn-primary" :disabled="form.processing">
                <span v-if="form.processing" class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
                {{ it.submit }}
            </button>
        </template>
    </OffcanvasPanel>
</template>
