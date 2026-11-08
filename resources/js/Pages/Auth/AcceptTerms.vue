<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import GuestLayout from '@/Layouts/GuestLayout.vue';

/**
 * Reaceite dos documentos legais — o gate `terms.accepted` do painel manda
 * para cá enquanto houver versão vigente não aceita (ex.: Política v1.1).
 *
 * Form HTML comum (POST + redirect com reload completo), como a saída do
 * VerifyPhone: depois do aceite o usuário volta ao painel (outro shell).
 * `version_ids` = exatamente as versões exibidas; se outra foi publicada no
 * meio, o servidor recusa e a tela volta com o texto novo.
 */
defineProps({
    appName: { type: String, default: 'EasyEye' },
    documents: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) },
});

const page = usePage();
const error = computed(() => page.props.errors?.accepted ?? '');
const accepted = ref(false);
// Lido só no navegador (o SSR não tem `document`).
const csrfToken = ref('');
onMounted(() => {
    csrfToken.value = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
});

const logoutForm = useForm({});
function logout() {
    logoutForm.post('/logout');
}
</script>

<template>
    <Head :title="t.page_title" />

    <GuestLayout :app-name="appName" :title="t.title" :subtitle="t.subtitle">
        <p class="text-muted mb-3" style="font-size: 0.9rem">{{ t.intro }}</p>

        <div v-if="error" class="alert alert-danger mb-3" role="alert">
            <i class="ti ti-alert-circle me-1"></i> {{ error }}
        </div>

        <ul class="list-group mb-3">
            <li v-for="doc in documents" :key="doc.version_id" class="list-group-item">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <span class="fw-semibold">
                        {{ doc.title }}
                        <span class="text-muted fw-normal">({{ t.version?.replace(':version', doc.version) }})</span>
                    </span>
                    <a :href="doc.url" target="_blank" rel="noopener" class="text-nowrap">
                        {{ t.open }} <i class="ti ti-external-link"></i>
                    </a>
                </div>
                <p v-if="doc.summary" class="text-muted mb-0 mt-1" style="font-size: 0.85rem">{{ doc.summary }}</p>
            </li>
        </ul>

        <form method="post" action="/accept-terms">
            <input type="hidden" name="_token" :value="csrfToken" />
            <input
                v-for="doc in documents"
                :key="doc.version_id"
                type="hidden"
                name="version_ids[]"
                :value="doc.version_id"
            />

            <div class="form-check mb-3">
                <input
                    id="terms-reaccept"
                    v-model="accepted"
                    class="form-check-input"
                    type="checkbox"
                    name="accepted"
                    value="1"
                />
                <label class="form-check-label" for="terms-reaccept">{{ t.checkbox }}</label>
            </div>

            <div class="d-grid mb-3">
                <button type="submit" class="btn btn-primary fw-semibold" :disabled="!accepted || !csrfToken">
                    {{ t.submit }}
                </button>
            </div>
        </form>

        <div class="text-center">
            <button
                type="button"
                class="btn btn-link text-muted"
                style="font-size: 0.875rem"
                :disabled="logoutForm.processing"
                @click="logout"
            >
                {{ t.logout }}
            </button>
        </div>
    </GuestLayout>
</template>
