<script setup>
import { onMounted, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    version: { type: String, default: null },
    html: { type: String, default: null },
    seenUrl: { type: String, default: '' },
    t: { type: Object, default: () => ({}) },
    breadcrumbs: { type: Array, default: () => [] },
});
const marked = ref(false);
onMounted(() => {
    if (!props.seenUrl || marked.value) return;
    marked.value = true;
    router.post(props.seenUrl, {}, { preserveScroll: true, onSuccess: () => router.reload({ only: ['nav'] }) });
});
</script>
<template>
    <AppLayout :title="t.title" :breadcrumbs="breadcrumbs">
        <section class="card">
            <div class="card-header bg-transparent d-flex flex-wrap align-items-center justify-content-between gap-2">
                <div>
                    <h1 class="h5 mb-1">{{ t.title }}</h1>
                    <p class="text-muted mb-0">{{ t.subtitle }}</p>
                </div>
                <span v-if="version" class="badge text-bg-primary">v{{ version }}</span>
            </div>
            <!-- eslint-disable-next-line vue/no-v-html -- HTML sanitizado no servidor a partir do changelog versionado. -->
            <div v-if="html" class="card-body news-content" v-html="html"></div>
            <p v-else class="card-body text-muted mb-0">{{ t.empty }}</p>
        </section>
    </AppLayout>
</template>
