<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

const props = defineProps({
    appName: { type: String, default: 'EasyEye' },
    t: { type: Object, default: () => ({}) },
    entities: { type: Object, default: () => ({}) },
});

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
        :illustration-src="'/system/images/auth/twostep-verification-illustration-img.png'"
    >
        <div v-if="form.errors.entity_user_id" class="alert alert-danger mb-4">
            {{ form.errors.entity_user_id }}
        </div>

        <form @submit.prevent="submit" novalidate>
            <div class="mb-4">
                <label class="form-label">{{ t.select_entity }} <span style="color:#ef4444;">*</span></label>
                <select
                    v-model="form.entity_user_id"
                    class="form-select"
                    :class="{ 'is-invalid': form.errors.entity_user_id }"
                    required
                >
                    <option value="">Selecione...</option>
                    <option v-for="(name, id) in entities" :key="id" :value="id">{{ name }}</option>
                </select>
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
