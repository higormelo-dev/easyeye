<script setup>
import { toRefs, computed } from 'vue';
import { useAnimatedNumber } from '@/useAnimatedNumber';

const props = defineProps({
    title: { type: String, required: true },
    value: { type: Number, required: true },
    prefix: { type: String, default: '' },
    suffix: { type: String, default: '' },
    icon: { type: String, required: true },
    delay: { type: Number, default: 0 }, // Para efeito cascata (stagger) em listas
});

const { value } = toRefs(props);
const animatedValue = useAnimatedNumber(value);

const formattedValue = computed(() => {
    // Formatação segura que previne XSS, delegando a renderização ao {{ }} do Vue
    return `${props.prefix}${animatedValue.value.toLocaleString('pt-BR')}${props.suffix}`;
});
</script>

<template>
    <!--
      v-motion cuida da entrada do card via aceleração de GPU (transform/opacity).
      Atraso dinâmico permite o efeito de escada (stagger) em dashboards.
    -->
    <div
        v-motion
        :initial="{ opacity: 0, y: 20 }"
        :enter="{ opacity: 1, y: 0, transition: { duration: 500, delay: props.delay } }"
        class="kpi-card bg-white p-4 rounded-3 shadow-sm border border-gray-100 d-flex align-items-center gap-3"
    >
        <div class="kpi-icon-wrapper rounded-circle bg-light d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
            <i :class="[icon, 'fs-4 text-primary']"></i>
        </div>

        <div>
            <p class="text-muted mb-1 fs-14 fw-medium">{{ title }}</p>
            <h4 class="mb-0 fw-bold text-dark">{{ formattedValue }}</h4>
        </div>
    </div>
</template>

<style scoped>
/* Garantir que o card fique responsivo a eventos de mouse mantendo a elegância */
.kpi-card {
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    will-change: transform, opacity; /* Dica ao browser para usar GPU */
}

.kpi-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.05) !important;
}
</style>
