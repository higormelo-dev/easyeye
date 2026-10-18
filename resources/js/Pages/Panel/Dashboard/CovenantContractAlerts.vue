<script setup>
const { data, t } = defineProps({ data: { type: Object, required: true }, t: { type: Object, default: () => ({}) } });
function text(template, values) {
    return Object.entries(values).reduce(
        (result, [key, value]) => result.replaceAll(`:${key}`, String(value)),
        template ?? '',
    );
}
</script>
<template>
    <section
        class="alert alert-warning d-flex gap-2 align-items-start"
        role="alert"
        data-tour="dashboard-covenant-contracts"
    >
        <i class="ti ti-file-alert fs-4 flex-shrink-0" aria-hidden="true"></i>
        <div class="flex-grow-1">
            <strong class="d-block">{{ t.covenant_contracts_title }}</strong>
            <ul class="mb-0 ps-3">
                <li v-for="item in data.items ?? []" :key="item.id ?? item.name">
                    {{ item.name }} —
                    <span v-if="item.expired" class="badge text-bg-danger me-1">{{
                        t.covenant_contracts_expired_badge
                    }}</span
                    >{{
                        item.expired
                            ? text(t.covenant_contracts_expired, { date: item.ends_at_label })
                            : item.days_until === 0
                              ? text(t.covenant_contracts_today, { date: item.ends_at_label })
                              : text(t.covenant_contracts_expiring, { days: item.days_until, date: item.ends_at_label })
                    }}
                </li>
            </ul>
            <span v-if="data.overflow" class="d-block small mt-1">{{
                text(t.covenant_contracts_more, { count: data.overflow })
            }}</span>
            <a v-if="data.url" :href="data.url" class="alert-link d-inline-block mt-2">{{
                t.covenant_contracts_open
            }}</a>
        </div>
    </section>
</template>
