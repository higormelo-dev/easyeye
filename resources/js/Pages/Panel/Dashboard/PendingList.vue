<script setup>
import { computed } from 'vue';

// Uma pendência do médico (laudos de IA a revisar, prontuários sem
// assinatura) dentro do card "Pendências": com itens, contagem + os mais
// recentes, cada um com o link de onde ele resolve; zerada, vira UMA linha
// discreta ("Tudo em dia") em vez de um card grande vazio.
const props = defineProps({
    title: { type: String, required: true },
    icon: { type: String, required: true },
    count: { type: Number, default: 0 },
    // [{ id, title, subtitle, url }]
    items: { type: Array, default: () => [] },
    hint: { type: String, default: '' },
    emptyText: { type: String, default: '' },
    actionLabel: { type: String, default: '' },
    seeAllUrl: { type: String, default: null },
    t: { type: Object, required: true },
});

const showingText = computed(() => {
    if (props.count <= props.items.length || !props.t.pending_showing) return '';

    return props.t.pending_showing.replace(':shown', String(props.items.length)).replace(':total', String(props.count));
});

const countLabel = computed(() => (props.t.pending_count ?? ':count').replace(':count', String(props.count)));
</script>

<template>
    <div v-if="count === 0" class="pending-ok" role="status">
        <i class="ti ti-circle-check" aria-hidden="true"></i>
        <span>{{ emptyText }}</span>
    </div>

    <div v-else class="pending-list" :aria-label="title" role="group">
        <div class="pending-list__head">
            <span class="pending-list__title">
                <i :class="icon" aria-hidden="true"></i>
                {{ title }}
                <span class="badge rounded-pill pending-list-count pending-list-count--active" :title="countLabel" :aria-label="countLabel">{{
                    count
                }}</span>
            </span>
            <a v-if="seeAllUrl" :href="seeAllUrl" class="db-link">
                {{ t.btn_see_all }} <i class="ti ti-arrow-right" aria-hidden="true"></i>
            </a>
        </div>

        <p v-if="hint" class="pending-list__hint">{{ hint }}</p>

        <ul v-if="items.length" class="db-list db-list--dense pending-list-items">
            <li v-for="item in items" :key="item.id" class="db-list__item">
                <div class="db-list__main">
                    <span class="db-list__title pending-list-title">{{ item.title }}</span>
                    <span class="db-list__sub">{{ item.subtitle }}</span>
                </div>
                <a
                    :href="item.url"
                    class="btn btn-sm db-btn-soft flex-shrink-0"
                    :aria-label="`${actionLabel}: ${item.title}`"
                >
                    {{ actionLabel }}
                </a>
            </li>
        </ul>

        <p v-if="showingText" class="db-card-note db-card-note--inline" role="note">
            {{ showingText }}
        </p>
    </div>
</template>
