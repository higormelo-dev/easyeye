<script setup>
import { computed } from 'vue';
import { makeTx } from '@/Support/recallRule.js';

/**
 * Sugestão do campo "Retorno" do prontuário (Fase 6): o intervalo da consulta de retorno que a clínica escolheu para
 * cada condição crônica ATIVA do paciente, em dias, com um botão "Usar". O médico decide — nada é preenchido
 * sozinho. Avisa quando o valor digitado passa do máximo validado do protocolo (a lista de retornos limita o pedido).
 */
const props = defineProps({
    suggestions: { type: Array, required: true },
    maxDays: { type: Number, default: null },
    t: { type: Object, required: true },
    /** O valor que está no campo agora (para marcar a sugestão já usada e avisar do máximo). */
    current: { type: [Number, String], default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['use']);

const tx = makeTx(() => props.t);

const currentDays = computed(() => {
    const value = Number(props.current);

    return Number.isInteger(value) && value > 0 ? value : null;
});

const overCap = computed(
    () => props.maxDays !== null && currentDays.value !== null && currentDays.value > props.maxDays,
);
</script>

<template>
    <div class="small mt-1" data-testid="follow-up-suggestion">
        <div class="fw-semibold">{{ tx('title') }}</div>
        <ul class="list-unstyled mb-1">
            <li
                v-for="item in suggestions"
                :key="item.protocol_key + item.eye"
                class="d-flex flex-wrap align-items-center gap-2"
                :data-testid="`follow-up-${item.protocol_key}`"
            >
                <span>
                    {{
                        tx('line', {
                            title: item.title,
                            eye: item.eye_label,
                            interval: item.interval,
                            unit: item.unit_label,
                        })
                    }}
                    ({{ tx('days', { days: item.days }) }})
                </span>
                <span v-if="currentDays === item.days" class="recall-tone recall-tone--success">{{
                    tx('in_use')
                }}</span>
                <button
                    v-else
                    type="button"
                    class="btn btn-sm recall-btn py-0"
                    :disabled="disabled"
                    data-testid="follow-up-use"
                    @click="emit('use', item.days)"
                >
                    {{ tx('use', { days: item.days }) }}
                </button>
            </li>
        </ul>
        <div v-if="maxDays !== null" class="form-text mb-0" data-testid="follow-up-cap">
            {{ tx(overCap ? 'cap_hit' : 'cap', { days: maxDays }) }}
        </div>
    </div>
</template>
