<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { situationBadgeStyle } from './situationBadge.js';

/**
 * Sala de espera agora (recepção): quem chegou e espera — pronto para o médico
 * ou em preparo (dilatação/exame) —, por ordem de chegada, com o tempo de
 * espera (âmbar a partir de 30 min, vermelho a partir de 60). Vazia = uma
 * linha discreta, não um card grande.
 */
const props = defineProps({
    // ClinicOperationsService::reception().waiting_room
    room: { type: Object, default: null },
    t: { type: Object, required: true },
});

const { number } = useLocaleFormat();
const tx = (key, params = {}) =>
    Object.entries(params).reduce((text, [name, value]) => text.replace(`:${name}`, String(value)), props.t[key] ?? '');

const items = computed(() => props.room?.items ?? []);
const count = computed(() => Number(props.room?.count ?? 0));
const more = computed(() => Math.max(0, count.value - items.value.length));

const waitTone = (minutes) => (minutes >= 60 ? 'danger' : minutes >= 30 ? 'warning' : 'ok');
</script>

<template>
    <section class="card db-card waiting-room" data-tour="dashboard-waiting-room" :aria-label="t.waiting_room_title">
        <div class="db-card-header">
            <h3 class="db-card-title">
                <i class="ti ti-armchair" aria-hidden="true"></i>
                {{ t.waiting_room_title }}
                <span class="badge db-count" :class="{ 'db-count--active': count > 0 }">{{ number(count) }}</span>
            </h3>
            <span v-if="room?.in_care" class="db-card-meta">
                {{ tx('waiting_room_in_care', { count: number(room.in_care) }) }}
            </span>
        </div>

        <div v-if="!items.length" class="db-empty db-empty--inline">
            <i class="ti ti-mood-smile" aria-hidden="true"></i>
            <span>{{ t.waiting_room_empty }}</span>
        </div>

        <ul v-else class="db-list">
            <li v-for="item in items" :key="item.id" class="db-list__item" :data-state="item.state">
                <div class="db-list__main">
                    <span class="db-list__title">{{ item.name }}</span>
                    <span class="db-list__sub">
                        {{ item.doctor }} · {{ tx('waiting_room_arrived', { time: item.arrived_time }) }}
                    </span>
                </div>
                <div class="db-list__side">
                    <span
                        class="db-wait"
                        :class="`db-wait--${waitTone(item.waiting_minutes)}`"
                        :title="tx('waiting_room_waiting', { minutes: number(item.waiting_minutes) })"
                    >
                        {{ tx('waiting_room_minutes', { minutes: number(item.waiting_minutes) }) }}
                    </span>
                    <span class="badge rounded-pill schedule-badge" :style="situationBadgeStyle(item.badge)">
                        {{ item.label }}
                    </span>
                </div>
            </li>
        </ul>

        <p v-if="more > 0" class="db-card-note" role="note">{{ tx('list_more', { count: number(more) }) }}</p>
    </section>
</template>
