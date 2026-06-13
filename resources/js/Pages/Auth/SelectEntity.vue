<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';
import twostepIllustrationImg from '@img/system/auth/twostep-verification-illustration-img.png';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
    entities: { type: Object, default: () => ({}) },
});

// `entities` é um mapa { id: name }; SearchSelect espera um array de objetos.
const entityOptions = computed(() =>
    Object.entries(props.entities).map(([id, name]) => ({ id, name })),
);

const form = useForm({ entity_user_id: '' });
const logoutForm = useForm({});

function submit() {
    form.post('/select-entity');
}

function logout() {
    logoutForm.post('/logout');
}
</script>

<template>
    <Head :title="t.select_entity" />

    <GuestLayout
        :app-name="appName"
        :title="t.select_entity"
        subtitle="Selecione a clínica para continuar"
        :illustration-src="twostepIllustrationImg"
    >
        <div v-if="form.errors.entity_user_id" class="alert alert-danger mb-4">
            {{ form.errors.entity_user_id }}
        </div>

        <form @submit.prevent="submit" novalidate>
            <div class="mb-4">
                <label class="form-label">{{ t.select_entity }} <span style="color:#ef4444;">*</span></label>
                <SearchSelect
                    v-model="form.entity_user_id"
                    :options="entityOptions"
                    :placeholder="'Selecione...'"
                    :invalid="!!form.errors.entity_user_id"
                    :clearable="false"
                />
            </div>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary fw-semibold" :disabled="form.processing">
                    <i v-if="form.processing" class="ti ti-loader-2 ee-spin me-1"></i>
                    Selecionar
                </button>
            </div>
        </form>

        <div class="text-center">
            <button
                type="button"
                class="btn btn-link text-muted"
                style="font-size:.875rem;"
                :disabled="logoutForm.processing"
                @click="logout"
            >
                {{ t.log_out }}
            </button>
        </div>
    </GuestLayout>
</template>
