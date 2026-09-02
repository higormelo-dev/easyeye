<script setup>
import { ref, computed, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

/**
 * ScheduleFlowGuard — integra Agenda ↔ Prontuário quando o atendimento está
 * vinculado a um agendamento (prop scheduleFlow de buildFormProps):
 *
 *  1. Médico abriu o prontuário → agendamento vira "Em consulta" (6)
 *     automaticamente. Guarda: só a partir de estados não-terminais
 *     (Agendado/Confirmado/Aguardando/Dilatando/Em exame) — reabrir um
 *     atendimento já Atendido/Cancelado/Faltou NUNCA rebaixa o status.
 *  2. Ao sair pelo "← Prontuários", pergunta o destino do paciente:
 *     Finalizar (→ Atendido) · Dilatar (→ Dilatando) · Realizar exame
 *     (→ Em exame) · Continuar atendimento (mantém Em consulta).
 *     Fechar a tela NUNCA finaliza a consulta sozinho — Dilatando/Em exame
 *     mantêm o prontuário aberto pra continuar quando o paciente voltar.
 *
 * Usa o endpoint existente panel.schedules.situation (log de transição,
 * cache da sala de espera, notificações e trava de caixa pro Atendido
 * já inclusos).
 */
const props = defineProps({
    t:        { type: Object,  default: () => ({}) },
    flow:     { type: Object,  default: null }, // { id, situation, update_url }
    isDoctor: { type: Boolean, default: false },
    locked:   { type: Boolean, default: false },
    exitUrl:  { type: String,  required: true },
    // Destino após Finalizar/Dilatar/Exame — a Agenda (o médico segue pro
    // próximo paciente). "Continuar" mantém o exitUrl (lista de prontuários).
    finishUrl: { type: String, default: null },
    // Quando informado, Finalizar/Dilatar/Exame passam pelo submit do
    // MedicalRecordForm (salva o digitado + transita no backend + Agenda) em
    // vez do PATCH direto — mesma semântica dos botões da barra inferior.
    submitFlow: { type: Function, default: null },
});

const FLOW_KEY = { 7: 'finish', 4: 'dilate', 5: 'exam' };

// Situações (espelho de App\Enums\ScheduleSituation)
const SITUATION = { DILATING: 4, EXAM: 5, IN_PROGRESS: 6, ATTENDED: 7 };
const AUTO_START_FROM = [1, 2, 3, 4, 5]; // Agendado, Confirmado, Aguardando, Dilatando, Em exame

const situation = ref(props.flow?.situation ?? null);
const open      = ref(false);
const busy      = ref(false);
const error     = ref('');

// Fluxo só existe pra médico, com agendamento vinculado e prontuário editável.
const active = computed(() => Boolean(props.flow && props.isDoctor && !props.locked));

async function patchSituation(value) {
    const res = await fetch(props.flow.update_url, {
        method: 'PATCH',
        headers: {
            'Content-Type': 'application/json',
            'Accept':       'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: JSON.stringify({ situation: value }),
    });
    const json = await res.json();

    if (res.ok) situation.value = value;

    return { ok: res.ok, json };
}

// 1) Abriu o prontuário → Em consulta (silencioso; falha não bloqueia o médico)
onMounted(async () => {
    if (!active.value) return;
    if (!AUTO_START_FROM.includes(situation.value)) return;
    try { await patchSituation(SITUATION.IN_PROGRESS); } catch { /**/ }
});

// 2) Saída — chamado pelo botão "← Prontuários" da página
function requestExit() {
    if (!active.value) {
        router.visit(props.exitUrl);
        return;
    }
    error.value = '';
    open.value  = true;
}

async function choose(target) {
    // Continuar atendimento: sai mantendo o status atual (Em consulta).
    if (target === null) {
        open.value = false;
        router.visit(props.exitUrl);
        return;
    }

    if (typeof props.submitFlow === 'function' && FLOW_KEY[target]) {
        open.value = false;
        props.submitFlow(FLOW_KEY[target]);
        return;
    }

    busy.value  = true;
    error.value = '';
    try {
        const { ok, json } = await patchSituation(target);

        if (!ok) {
            // Ex.: trava de caixa da clínica pro Atendido (requires_cash_to_complete)
            error.value = json.message ?? 'Não foi possível atualizar o status.';
            return;
        }

        open.value = false;
        if (window.showSuccessToast && json.message) window.showSuccessToast(json.message);
        router.visit(props.finishUrl ?? props.exitUrl);
    } finally {
        busy.value = false;
    }
}

defineExpose({ requestExit, active });

const options = [
    {
        target: SITUATION.ATTENDED,
        label: props.t.flow_finish ?? 'Finalizar consulta',
        hint: props.t.flow_finish_hint ?? 'Atendimento concluído — status vira "Atendido".',
        icon: 'fas fa-check-double',
        btn: 'btn-success',
    },
    {
        target: SITUATION.DILATING,
        label: props.t.flow_dilate ?? 'Dilatar',
        hint: props.t.flow_dilate_hint ?? 'Status vira "Dilatando" e o prontuário continua aberto pra quando o paciente voltar.',
        icon: 'fas fa-eye-dropper',
        btn: 'btn-outline-primary',
    },
    {
        target: SITUATION.EXAM,
        label: props.t.flow_exam ?? 'Realizar exame',
        hint: props.t.flow_exam_hint ?? 'Status vira "Em exame" e o prontuário continua aberto pra quando o paciente voltar.',
        icon: 'fas fa-stethoscope',
        btn: 'btn-outline-primary',
    },
    {
        target: null,
        label: props.t.flow_continue ?? 'Continuar atendimento',
        hint: props.t.flow_continue_hint ?? 'Sai da tela mantendo "Em consulta" — nada é finalizado.',
        icon: 'fas fa-user-md',
        btn: 'btn-outline-secondary',
    },
];
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal fade show d-block" style="background:rgba(0,0,0,.5);z-index:1080;" @click.self="open = false">
            <div class="modal-dialog modal-dialog-centered sfg-dialog">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title">
                            <i class="fas fa-route me-2 text-primary"></i>{{ t.flow_title ?? 'O que acontece com o paciente agora?' }}
                        </h6>
                        <button type="button" class="btn-close" @click="open = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            {{ t.flow_subtitle ?? 'Fechar o prontuário não finaliza a consulta — escolha o destino do paciente na Agenda.' }}
                        </p>

                        <div v-if="error" class="alert alert-warning py-2 small">{{ error }}</div>

                        <div class="d-grid gap-2">
                            <!-- sfg-option: o tema define altura fixa/nowrap em .btn,
                                 fazendo o hint vazar da borda — as regras scoped
                                 abaixo neutralizam isso sem depender do tema. -->
                            <button v-for="opt in options"
                                    :key="opt.label"
                                    type="button"
                                    class="btn sfg-option"
                                    :class="opt.btn"
                                    :disabled="busy"
                                    @click="choose(opt.target)">
                                <span class="sfg-option__icon"><i :class="opt.icon"></i></span>
                                <span class="sfg-option__text">
                                    <span class="sfg-option__label">{{ opt.label }}</span>
                                    <span class="sfg-option__hint">{{ opt.hint }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.sfg-dialog {
    max-width: 520px;
}

/* Neutraliza altura fixa e nowrap que o tema aplica em .btn — cada opção é
   um "cartão" com ícone fixo à esquerda e texto que quebra livremente. */
.sfg-option {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    width: 100%;
    height: auto;
    min-height: 0;
    padding: .65rem .85rem;
    text-align: start;
    white-space: normal;
    line-height: 1.35;
    border-radius: .5rem;
}

.sfg-option__icon {
    flex-shrink: 0;
    width: 2rem;
    height: 2rem;
    display: grid;
    place-items: center;
    border-radius: 50%;
    background: rgba(255, 255, 255, .18);
    font-size: .95rem;
}

/* Nas variantes outline o fundo do círculo acompanha a cor do botão. */
.sfg-option.btn-outline-primary .sfg-option__icon,
.sfg-option.btn-outline-secondary .sfg-option__icon,
.sfg-option.btn-outline-info .sfg-option__icon {
    background: rgba(13, 110, 253, .1);
}

.sfg-option__text {
    display: block;
    min-width: 0;
}

.sfg-option__label {
    display: block;
    font-weight: 600;
}

.sfg-option__hint {
    display: block;
    font-size: .8rem;
    opacity: .8;
    overflow-wrap: anywhere;
}
</style>
