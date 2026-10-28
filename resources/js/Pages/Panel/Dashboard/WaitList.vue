<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Lista de espera (recepção): total ativo e os primeiros — na mesma ordem do
 * painel "Lista de espera" da Agenda —, com médico, período desejado e
 * telefone. Vazia = linha discreta.
 */
const props = defineProps({
    // ClinicOperationsService::waitlist()
    waitlist: { type: Object, default: null },
    canOpenSchedule: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const items = computed(() => props.waitlist?.items ?? []);
const count = computed(() => Number(props.waitlist?.count ?? 0));
const more = computed(() => Math.max(0, count.value - items.value.length));

function preferred(item) {
    if (item.from && item.until) return tx('waitlist_between', { from: item.from, until: item.until });
    if (item.from) return tx('waitlist_from', { date: item.from });
    if (item.until) return tx('waitlist_until', { date: item.until });

    return '';
}

function since(item) {
    if (item.days === null || item.days === undefined) return '';
    if (item.days === 0) return props.t.waitlist_since_today;

    return tx(item.days === 1 ? 'waitlist_since_one' : 'waitlist_since_other', { count: number(item.days) });
}
</script>

<template>
    <section class="card db-card" data-tour="dashboard-waitlist" :aria-label="t.waitlist_title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-list-numbers" aria-hidden="true"></i>
                {{ t.waitlist_title }}
                <span class="badge db-count" :class="{ 'db-count--active': count > 0 }">{{ number(count) }}</span>
            </h3>
            <a v-if="canOpenSchedule && waitlist?.url" :href="waitlist.url" class="db-link">
                {{ t.waitlist_open }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <div v-if="!items.length" class="db-empty db-empty--inline">
            <i class="ti ti-circle-check" aria-hidden="true"></i>
            <span>{{ t.waitlist_empty }}</span>
        </div>

        <ul v-else class="db-list">
            <li v-for="item in items" :key="item.id" class="db-list__item">
                <div class="db-list__main">
                    <span class="db-list__title">{{ item.name }}</span>
                    <span class="db-list__sub">
                        {{ [item.doctor, preferred(item), since(item)].filter(Boolean).join(' · ') }}
                    </span>
                </div>
                <a
                    v-if="item.phone_href"
                    :href="item.phone_href"
                    class="btn btn-sm db-btn-soft db-icon-only"
                    :title="item.phone"
                    :aria-label="tx('confirm_call_aria', { name: item.name, phone: item.phone })"
                >
                    <i class="ti ti-phone" aria-hidden="true"></i>
                </a>
            </li>
        </ul>

        <p v-if="more > 0" class="db-card-note" role="note">{{ tx('list_more', { count: number(more) }) }}</p>
    </section>
</template>
