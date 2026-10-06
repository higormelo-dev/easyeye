<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';

/**
 * Aniversariantes de hoje (recepção — só quem abre Pacientes recebe): nome,
 * idade que completa, telefone para ligar e o cadastro. Nenhum = linha
 * discreta.
 */
const props = defineProps({
    // ClinicOperationsService::birthdays()
    birthdays: { type: Object, default: null },
    canOpenPatients: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const items = computed(() => props.birthdays?.items ?? []);
const count = computed(() => Number(props.birthdays?.count ?? 0));
const more = computed(() => Math.max(0, count.value - items.value.length));
</script>

<template>
    <section class="card db-card" data-tour="dashboard-birthdays" :aria-label="t.birthdays_title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-cake" aria-hidden="true"></i>
                {{ t.birthdays_title }}
                <span class="badge db-count" :class="{ 'db-count--active': count > 0 }">{{ number(count) }}</span>
            </h3>
        </div>

        <div v-if="!items.length" class="db-empty db-empty--inline">
            <i class="ti ti-cake-off" aria-hidden="true"></i>
            <span>{{ t.birthdays_empty }}</span>
        </div>

        <ul v-else class="db-list">
            <li v-for="item in items" :key="item.id" class="db-list__item">
                <div class="db-list__main">
                    <component
                        :is="canOpenPatients ? 'a' : 'span'"
                        :href="canOpenPatients ? item.url : undefined"
                        class="db-list__title"
                        >{{ item.name }}</component
                    >
                    <span v-if="item.age" class="db-list__sub">{{ tx('birthdays_age', { age: number(item.age) }) }}</span>
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
