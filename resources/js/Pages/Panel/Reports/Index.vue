<script setup>
import { Link } from '@inertiajs/vue3';
import AppLayout  from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/Panel/PageHeader.vue';

defineProps({
    breadcrumbs: { type: Array, default: () => [] },
    links:       { type: Array, default: () => [] },
});
</script>

<template>
    <AppLayout title="Relatórios" :breadcrumbs="breadcrumbs">
        <div class="container-fluid py-3">
            <PageHeader title="Relatórios" />

            <div class="row g-3">
                <div v-for="link in links" :key="link.url" class="col-md-4">
                    <Link :href="link.url" class="card border-0 shadow-sm h-100 text-decoration-none text-reset">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="avatar avatar-lg bg-primary-subtle rounded-circle">
                                <i :class="`ti ${link.icon} fs-3 text-primary`"></i>
                            </span>
                            <div>
                                <h6 class="fw-semibold mb-0">{{ link.title }}</h6>
                            </div>
                        </div>
                    </Link>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
