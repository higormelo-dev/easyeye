<script setup>
import { computed } from 'vue';

const props = defineProps({
    rule: { type: String, default: '' },
    t:    { type: Object, required: true },
});

const isAdminOrFinancial = computed(() =>
    ['admin', 'financial'].includes(props.rule),
);

const modules = computed(() => {
    const all = [
        {
            key:       'schedule',
            label:     props.t.module_schedule,
            icon:      'ti ti-calendar',
            iconClass: 'module-icon--schedule',
            url:       route('panel.schedules.index'),
            soon:      false,
        },
        {
            key:       'eye-images',
            label:     props.t.module_eye_images,
            icon:      'ti ti-eye',
            iconClass: 'module-icon',
            url:       route('panel.eye-images.index'),
            soon:      false,
        },
        ...(isAdminOrFinancial.value
            ? [
                {
                    key:       'tiss',
                    label:     props.t.module_tiss,
                    icon:      'ti ti-file-invoice',
                    iconClass: 'module-icon--tiss',
                    url:       route('panel.financial.billing.index'),
                    soon:      false,
                },
                {
                    key:       'financial',
                    label:     props.t.module_financial,
                    icon:      'ti ti-report-money',
                    iconClass: 'module-icon--financial',
                    url:       route('panel.financial.cash-flow.index'),
                    soon:      false,
                },
            ]
            : []
        ),
        {
            key:       'surgery',
            label:     props.t.module_surgery,
            icon:      'ti ti-stethoscope',
            iconClass: 'module-icon--soon',
            url:       null,
            soon:      true,
        },
    ];
    return all;
});
</script>

<template>
    <div class="row g-3 mb-4">
        <div v-for="mod in modules" :key="mod.key" class="col-6 col-sm-4 col-md-2">
            <component
                :is="mod.soon ? 'div' : 'a'"
                :href="mod.soon ? undefined : mod.url"
                :class="['module-shortcut w-100', mod.soon ? 'disabled' : '']"
            >
                <span v-if="mod.soon" class="badge-soon">{{ t.coming_soon }}</span>
                <span :class="`ms-icon ${mod.iconClass}`">
                    <i :class="mod.icon"></i>
                </span>
                <span>{{ mod.label }}</span>
            </component>
        </div>
    </div>
</template>
