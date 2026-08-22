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
    flow:     { type: Object,  default: null }, // { id, situation, update_url }
    isDoctor: { type: Boolean, default: false },
    locked:   { type: Boolean, default: false },
    exitUrl:  { type: String,  required: true },
});

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
        router.visit(props.exitUrl);
    } finally {
        busy.value = false;
    }
}

defineExpose({ requestExit, active });

const options = [
    {
        target: SITUATION.ATTENDED,
        label: 'Finalizar consulta',
        hint: 'Atendimento concluído — status vira "Atendido".',
        icon: 'fas fa-check-double',
        btn: 'btn-success',
    },
    {
        target: SITUATION.DILATING,
        label: 'Dilatar',
        hint: 'Status vira "Dilatando" e o prontuário continua aberto pra quando o paciente voltar.',
        icon: 'fas fa-eye-dropper',
        btn: 'btn-outline-primary',
    },
    {
        target: SITUATION.EXAM,
        label: 'Realizar exame',
        hint: 'Status vira "Em exame" e o prontuário continua aberto pra quando o paciente voltar.',
        icon: 'fas fa-stethoscope',
        btn: 'btn-outline-primary',
    },
    {
        target: null,
        label: 'Continuar atendimento',
        hint: 'Sai da tela mantendo "Em consulta" — nada é finalizado.',
        icon: 'fas fa-user-md',
        btn: 'btn-outline-secondary',
    },
];
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="modal fade show d-block" style="background:rgba(0,0,0,.5);z-index:1080;" @click.self="open = false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h6 class="modal-title">
                            <i class="fas fa-route me-2 text-primary"></i>O que acontece com o paciente agora?
                        </h6>
                        <button type="button" class="btn-close" @click="open = false"></button>
                    </div>
                    <div class="modal-body">
                        <p class="text-muted small mb-3">
                            Fechar o prontuário não finaliza a consulta — escolha o destino do paciente na Agenda.
                        </p>

                        <div v-if="error" class="alert alert-warning py-2 small">{{ error }}</div>

                        <div class="d-grid gap-2">
                            <button v-for="opt in options"
                                    :key="opt.label"
                                    type="button"
                                    class="btn text-start d-flex align-items-center gap-3 py-2"
                                    :class="opt.btn"
                                    :disabled="busy"
                                    @click="choose(opt.target)">
                                <i :class="opt.icon" class="fs-5"></i>
                                <span>
                                    <span class="d-block fw-semibold">{{ opt.label }}</span>
                                    <span class="d-block small opacity-75">{{ opt.hint }}</span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
