<script setup>
import { computed, ref, watch } from 'vue';
import SearchSelect from '@/Components/Panel/SearchSelect.vue';

/**
 * AcuitySelect — seletor de Acuidade Visual do prontuário.
 *
 * Sobre o catálogo (CD 1M..CD 5M são 5 linhas de visual_acuity_types):
 *  - o dropdown mostra UMA opção "CONTA DEDOS"; ao escolhê-la aparece ao
 *    lado o seletor de distância (1..5 m) — obrigatório: o v-model só é
 *    emitido depois da distância, apontando direto pro FK "CD Xm".
 *  - carregando um prontuário salvo com CD Xm, o par (CONTA DEDOS + distância)
 *    é remontado automaticamente.
 *  - lista aberta mais alta que o padrão (menos rolagem — pedido do ticket).
 *
 * O dado permanece 100% estruturado: nada de texto livre, o valor final é
 * sempre o id de uma linha do catálogo; PDFs e resumos exibem "CD 3M" direto.
 */
const props = defineProps({
    modelValue: { type: [String, null], default: null },
    options:    { type: Array,   default: () => [] },
    placeholder:{ type: String,  default: '—' },
    disabled:   { type: Boolean, default: false },
    invalid:    { type: Boolean, default: false },
});

const emit = defineEmits(['update:modelValue']);

const CD_SENTINEL = '__conta_dedos__';
const cdRegex     = /^CD\s*([1-5])\s*M$/i;

const cdOptions = computed(() =>
    props.options
        .filter(o => cdRegex.test((o.name ?? '').trim()))
        .map(o => ({ ...o, meters: Number(o.name.trim().match(cdRegex)[1]) }))
        .sort((a, b) => a.meters - b.meters),
);

// Dropdown principal: opções normais + "CONTA DEDOS" no lugar do bloco CD.
const displayOptions = computed(() => {
    if (cdOptions.value.length === 0) return props.options;

    const out      = [];
    let cdInserted = false;

    for (const o of props.options) {
        if (cdRegex.test((o.name ?? '').trim())) {
            if (!cdInserted) {
                out.push({ id: CD_SENTINEL, name: 'CONTA DEDOS' });
                cdInserted = true;
            }

            continue;
        }
        out.push(o);
    }

    return out;
});

const display  = ref(null); // valor do dropdown principal (id normal ou sentinel)
const distance = ref('');   // id da linha CD Xm quando CONTA DEDOS ativo

function syncFromModel(value) {
    // Estado "CONTA DEDOS aguardando distância": emitimos null pro pai até a
    // distância ser escolhida — quando esse null ecoa de volta pelo v-model,
    // NÃO pode apagar o modo CD (senão o seletor de metros nunca aparece).
    if (!value && display.value === CD_SENTINEL && !distance.value) return;

    const cd = cdOptions.value.find(o => o.id === value);

    if (cd) {
        display.value  = CD_SENTINEL;
        distance.value = cd.id;
    } else {
        display.value  = value ?? null;
        distance.value = '';
    }
}

watch(() => props.modelValue, syncFromModel, { immediate: true });
// Options chegam async (props do Inertia) — re-sincroniza quando carregam.
watch(cdOptions, () => syncFromModel(props.modelValue));

const cdActive = computed(() => display.value === CD_SENTINEL);

function onDisplayChange(value) {
    display.value = value;

    if (value === CD_SENTINEL) {
        // Distância é obrigatória: só emite quando escolhida.
        emit('update:modelValue', distance.value || null);

        return;
    }

    distance.value = '';
    emit('update:modelValue', value ?? null);
}

function onDistanceChange() {
    emit('update:modelValue', distance.value || null);
}
</script>

<template>
    <!-- flex-grow + width:1%: dentro de um .input-group, div genérica não
         estica como .form-control — sem isso o select colapsa a ~15px. -->
    <div class="d-flex gap-1 align-items-start flex-grow-1" style="width:1%;min-width:0;">
        <div class="flex-grow-1 min-w-0">
            <SearchSelect
                :model-value="display"
                :options="displayOptions"
                :placeholder="placeholder"
                :disabled="disabled"
                :invalid="invalid || (cdActive && !distance)"
                list-height="22rem"
                @update:model-value="onDisplayChange"
            />
        </div>

        <!-- Distância do Conta Dedos — só aparece com CONTA DEDOS selecionado -->
        <select v-if="cdActive"
                v-model="distance"
                class="form-select form-select-sm flex-shrink-0"
                style="width:84px;min-height:30px;"
                :class="{ 'is-invalid': !distance }"
                :disabled="disabled"
                title="Distância do Conta Dedos"
                @change="onDistanceChange">
            <option value="" disabled>m?</option>
            <option v-for="cd in cdOptions" :key="cd.id" :value="cd.id">{{ cd.meters }} m</option>
        </select>
    </div>
</template>
