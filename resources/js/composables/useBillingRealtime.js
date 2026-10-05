import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { echo, echoIsConfigured, useConnectionStatus } from '@laravel/echo-vue';

/**
 * Pagamento da assinatura confirmado em tempo real — WebSocket via Reverb
 * (canal privado `billing.{entityId}`, evento `.invoice.paid` de
 * App\Events\Billing\InvoicePaid), sem polling HTTP.
 *
 * Vários componentes da mesma tela (página + checkout aberto) podem ouvir o
 * mesmo canal: um registro por canal conta os ouvintes e só sai do canal
 * quando o último desmonta (um `leave` não derruba o outro).
 *
 * - onPaid(payload): { invoice_id, subscription_id, status, amount, paid_at, ... }
 * - onResync(): assinatura confirmada e reconexões — o evento pode ter saído
 *   antes de a tela assinar; a tela relê o estado UMA vez. Sem tempo real,
 *   também ao voltar para a aba (nunca em intervalo).
 *
 * @param {() => ({channel?: string, event?: string}|null)} realtimeGetter
 * @param {{ onPaid?: Function, onResync?: Function, active?: () => boolean }} [options]
 */
const registry = new Map();

function join(name, event, listener) {
    let entry = registry.get(name);

    if (!entry) {
        entry = { listeners: new Set(), event };
        registry.set(name, entry);
        echo()
            .private(name)
            .subscribed(() => entry.listeners.forEach((l) => l.onResync?.()))
            .listen(event, (payload) => entry.listeners.forEach((l) => l.onPaid?.(payload)));
    }

    entry.listeners.add(listener);
}

function part(name, listener) {
    const entry = registry.get(name);
    if (!entry) return;

    entry.listeners.delete(listener);
    if (entry.listeners.size === 0) {
        registry.delete(name);
        echo().leave(name);
    }
}

export function useBillingRealtime(realtimeGetter, { onPaid, onResync, active } = {}) {
    const configured = echoIsConfigured();
    const status = configured ? useConnectionStatus() : ref('disconnected');
    const listener = {
        onPaid: (payload) => onPaid?.(payload),
        onResync: () => onResync?.(),
    };
    let current = null;

    function leave() {
        if (current) {
            part(current, listener);
            current = null;
        }
    }

    watch(
        () => realtimeGetter()?.channel ?? null,
        (channel) => {
            if (channel === current) return;
            leave();
            if (!channel || !configured) return;

            current = channel;
            join(channel, realtimeGetter()?.event || '.invoice.paid', listener);
        },
        { immediate: true },
    );

    let wasConnected = status.value === 'connected';
    watch(status, (now) => {
        if (now === 'connected') {
            if (wasConnected && current) onResync?.();
            wasConnected = true;
        }
    });

    const realtimeConnected = computed(() => configured && status.value === 'connected');

    function onVisible() {
        if (document.visibilityState !== 'visible' || realtimeConnected.value) return;
        if (active && !active()) return;
        onResync?.();
    }

    if (typeof document !== 'undefined') document.addEventListener('visibilitychange', onVisible);

    onBeforeUnmount(() => {
        leave();
        if (typeof document !== 'undefined') document.removeEventListener('visibilitychange', onVisible);
    });

    return { realtimeConnected };
}

/** Só para testes: esquece os canais registrados. */
export function resetBillingRealtime() {
    registry.clear();
}
