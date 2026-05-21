import { ref, watch, onMounted, onUnmounted } from 'vue';
import gsap from 'gsap';

/**
 * Interpola valores numéricos suavemente usando GSAP.
 * Ideal para dashboards, KPIs e faturamento.
 *
 * @param {import('vue').Ref<number>|number} sourceValue Valor final a ser atingido
 * @param {number} duration Duração da animação em segundos
 */
export function useAnimatedNumber(sourceValue, duration = 1.2) {
    const displayValue = ref(0);
    let ctx;

    const animateTo = (val) => {
        // Respeita acessibilidade: se o usuário prefere menos movimento, corta a animação
        const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        gsap.to(displayValue, {
            value: val,
            duration: prefersReducedMotion ? 0 : duration,
            snap: { value: 1 }, // Arredonda para inteiros
            onUpdate: function () {
                displayValue.value = Math.round(this.targets()[0].value);
            }
        });
    };

    onMounted(() => {
        const initialVal = typeof sourceValue === 'number' ? sourceValue : sourceValue.value;
        ctx = gsap.context(() => animateTo(initialVal));
    });

    watch(() => (typeof sourceValue === 'number' ? sourceValue : sourceValue.value), (newVal) => {
        animateTo(newVal);
    });

    onUnmounted(() => {
        if (ctx) ctx.revert(); // Previne Memory Leaks no Inertia.js ao trocar de página
    });

    return displayValue;
}
