<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { computeDelta } from './trend.js';

/**
 * Indicador do Dashboard: rótulo, valor no formato do idioma (número, moeda,
 * %), variação ▲▼ com cor SEMÂNTICA (falta subir = vermelho; receita subir =
 * verde) e a dica do período. Vira link quando há acesso à tela (`url`).
 *
 * kpi: { key, label, value, format: 'number'|'money'|'pct'|'text', icon, tone,
 *        caption, hint, url, delta: { previous, kind, better } | null,
 *        compareLabel, alert }
 */
const props = defineProps({
    kpi: { type: Object, required: true },
    // Atualização em segundo plano (polling de 30 s): o número fica na tela
    // (sem piscar a cada 30 s) — só um traço discreto indica a atualização.
    isRefreshing: { type: Boolean, default: false },
    // Recarga pedida ("Atualizar") ou dado ainda não carregado: esqueleto.
    loading: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const { number, money } = useLocaleFormat();

const formatted = computed(() => {
    const { value, format } = props.kpi;
    if (value === null || value === undefined || value === '') return '—';
    if (format === 'text' || typeof value === 'string') return String(value);
    if (format === 'money') return money(value);
    if (format === 'pct') return `${number(value, Number.isInteger(Number(value)) ? 0 : 1)}%`;

    return number(value);
});

const rawDelta = computed(() => {
    const d = props.kpi.delta;
    if (!d) return null;

    return computeDelta(props.kpi.value, d.previous, { kind: d.kind ?? 'count', better: d.better ?? 'up' });
});

// Período anterior zerado: sem seta (não há variação a mostrar) — a legenda
// diz "Sem dados em 1–6 de set." no lugar do "vs. …".
const noBase = computed(() => rawDelta.value?.unit === 'none');
const delta = computed(() => (noBase.value ? null : rawDelta.value));
const caption = computed(() =>
    noBase.value ? (props.t.delta_no_base ?? '').replace(':period', props.kpi.compareLabel ?? '') : props.kpi.caption,
);

const ARROWS = { up: '▲', down: '▼', flat: '=' };

const deltaText = computed(() => {
    const d = delta.value;
    if (!d) return '';
    if (d.unit === 'pp') return `${number(d.value, 1)} ${props.t.delta_pp ?? 'p.p.'}`;

    return `${number(d.value, d.value >= 10 || Number.isInteger(d.value) ? 0 : 1)}%`;
});

// Leitor de tela: "aumento de 12% em relação a 1–6 de set." (a seta é decorativa).
const deltaSr = computed(() => {
    const d = delta.value;
    if (!d) return '';
    const key = { up: 'delta_sr_up', down: 'delta_sr_down', flat: 'delta_sr_flat' }[d.direction];

    return (props.t[key] ?? '')
        .replace(':value', deltaText.value)
        .replace(':period', props.kpi.compareLabel ?? '')
        .replace(/\s+/g, ' ')
        .trim();
});

const linkTitle = computed(() =>
    props.kpi.url ? (props.t.kpi_open_list ?? ':label').replace(':label', props.kpi.label) : null,
);
</script>

<template>
    <component
        :is="kpi.url ? Link : 'div'"
        :href="kpi.url ?? undefined"
        :title="linkTitle ?? kpi.hint ?? null"
        :data-kpi="kpi.key"
        :class="[
            'db-metric',
            `db-metric--${kpi.tone ?? 'neutral'}`,
            kpi.url ? 'db-metric--link' : '',
            kpi.alert ? 'db-metric--alert' : '',
            isRefreshing ? 'db-metric--refreshing' : '',
        ]"
    >
        <div class="db-metric__head">
            <span class="db-metric__label">{{ kpi.label }}</span>
            <span v-if="kpi.icon" class="db-metric__icon" aria-hidden="true"><i :class="kpi.icon"></i></span>
        </div>

        <div class="db-metric__value stat-value">
            <span v-if="loading" class="stat-skeleton" aria-hidden="true"></span>
            <template v-else>{{ formatted }}</template>
        </div>

        <div v-if="loading" class="db-metric__foot" aria-hidden="true">
            <span class="stat-skeleton stat-skeleton--line"></span>
        </div>
        <div v-else class="db-metric__foot">
            <span
                v-if="delta"
                class="db-delta"
                :class="`db-delta--${delta.tone}`"
                :data-delta="delta.direction"
                :data-tone="delta.tone"
            >
                <span aria-hidden="true">{{ ARROWS[delta.direction] }} {{ deltaText }}</span>
                <span class="visually-hidden">{{ deltaSr }}</span>
            </span>
            <span v-if="caption" class="db-metric__caption">{{ caption }}</span>
        </div>
        <span v-if="kpi.hint && kpi.url" class="visually-hidden">{{ kpi.hint }}</span>
    </component>
</template>
