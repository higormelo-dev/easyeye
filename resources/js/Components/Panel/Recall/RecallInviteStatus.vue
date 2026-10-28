<script setup>
import { computed } from 'vue';
import { useLocaleFormat } from '@/composables/useLocaleFormat.js';
import { makeTx } from '@/Support/recallRule.js';

/**
 * O convite por WhatsApp de um retorno, em linguagem de recepção (Fase 6): o que aconteceu com o último convite
 * (na fila, enviado, entregue, lido, não entregue, cancelado), o que o paciente respondeu ("Quero agendar" / "Já
 * agendei") e por que não dá para convidar (sem autorização de lembretes, respondeu SAIR). Só texto de apresentação:
 * a decisão de enviar é do servidor. O significado nunca fica só na cor.
 */
const props = defineProps({
    // `row.invite` da lista: { consent, opted_out, can_try, requested, last }.
    invite: { type: Object, default: null },
    // O canal de convite da clínica está aberto (senão não há o que oferecer e o aviso de "sem autorização" some).
    channelOpen: { type: Boolean, default: false },
    // O celular do paciente está marcado como WhatsApp.
    whatsapp: { type: Boolean, default: false },
    t: { type: Object, required: true },
});

const tx = makeTx(() => props.t);
const { date } = useLocaleFormat();

const last = computed(() => props.invite?.last ?? null);
const day = (iso) => (iso ? date(iso.substring(0, 10)) : '');

// Estado da entrega: fila → enviado → entregue → lido; falha e cancelado à parte.
const deliveryText = computed(() => {
    if (!last.value) return '';

    const status = last.value.status;
    const when = day(last.value.at);
    const origin = props.t.row.invite[last.value.origin === 'auto' ? 'origin_auto' : 'origin_manual'];

    const base = ['sent', 'delivered', 'read'].includes(status)
        ? tx(`row.invite.${status}`, { date: when })
        : tx(`row.invite.${status}`);

    return `${base} (${origin})`;
});

const deliveryTone = computed(() => {
    const status = last.value?.status;

    if (status === 'failed') return 'recall-tone recall-tone--danger';
    if (status === 'cancelled') return 'recall-tone recall-tone--muted';

    return 'small text-muted';
});

const response = computed(() => last.value?.response ?? null);

const unreachable = computed(() => {
    if (!props.channelOpen || !props.invite) return null;
    if (props.invite.opted_out) return 'opted_out';
    if (!props.invite.consent && props.whatsapp) return 'no_consent';

    return null;
});
</script>

<template>
    <div v-if="invite" data-testid="recall-invite-status">
        <div v-if="invite.requested" class="mt-1">
            <span class="recall-tone recall-tone--success" data-testid="recall-invite-requested">
                <i class="ti ti-hand-finger me-1" aria-hidden="true"></i>{{ t.row.invite.requested }}
            </span>
        </div>
        <div v-else-if="response === 'wants_to_schedule'" class="small text-muted" data-testid="recall-invite-asked">
            {{ tx('row.invite.asked_book', { date: day(last.responded_at) }) }}
        </div>
        <div v-if="response === 'already_scheduled'" class="mt-1">
            <span class="recall-tone recall-tone--warning" data-testid="recall-invite-asked-done">
                <i class="ti ti-calendar-question me-1" aria-hidden="true"></i
                >{{ tx('row.invite.asked_done', { date: day(last.responded_at) }) }}
            </span>
        </div>

        <div v-if="last" class="mt-1" :class="deliveryTone" data-testid="recall-invite-delivery">
            <i class="ti ti-brand-whatsapp me-1" aria-hidden="true"></i>{{ deliveryText }}
        </div>

        <div v-if="unreachable" class="small text-muted mt-1" data-testid="recall-invite-unreachable">
            <i class="ti ti-phone-call me-1" aria-hidden="true"></i>{{ t.row.invite[unreachable] }}
        </div>
    </div>
</template>
