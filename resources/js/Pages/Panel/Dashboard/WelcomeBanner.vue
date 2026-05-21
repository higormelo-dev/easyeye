<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

const props = defineProps({
    t: { type: Object, required: true },
});

const page      = usePage();
const auth      = computed(() => page.props.auth ?? {});
const user      = computed(() => auth.value.user ?? {});
const entity    = computed(() => auth.value.entity ?? {});
const firstName = computed(() => user.value.name?.split(' ')[0] ?? '');

const hour    = new Date().getHours();
const gKey    = hour < 12 ? 'greeting_morning' : hour < 18 ? 'greeting_afternoon' : 'greeting_evening';
const greeting = computed(() => props.t[gKey] ?? 'Olá');
</script>

<template>
    <div class="welcome-banner mb-4 mt-4">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h4 class="mb-1">{{ greeting }}, {{ firstName }}! 👋</h4>
                <p>{{ t.operational_panel?.replace(':app', entity.name) }}</p>
            </div>
            <div class="d-flex gap-2 flex-wrap" style="position:relative;z-index:1">
                <a :href="route('panel.patients.index')" class="btn btn-sm btn-banner">
                    <i class="ti ti-users me-1"></i> {{ t.btn_patients }}
                </a>
                <a :href="route('panel.patients.index')" class="btn btn-sm btn-banner btn-banner-solid">
                    <i class="ti ti-user-plus me-1"></i> {{ t.btn_new_patient }}
                </a>
            </div>
        </div>
    </div>
</template>
