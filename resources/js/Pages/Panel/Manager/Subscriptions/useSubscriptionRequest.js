import { ref } from 'vue';

/**
 * Envio JSON das ações de assinatura do manager (criar, adicionar período,
 * alterar). Erros de validação (422) vão por campo; o erro de regra de
 * negócio (`subscription`) e falhas do servidor viram a mensagem do modal.
 *
 * @param {() => string} fallbackMessage mensagem genérica no idioma do usuário
 */
export function useSubscriptionRequest(fallbackMessage) {
    const saving = ref(false);
    const errors = ref({});
    const message = ref('');

    function reset() {
        errors.value = {};
        message.value = '';
    }

    async function send(method, url, payload) {
        saving.value = true;
        reset();

        try {
            const { data } = await window.axios({
                method,
                url,
                data: payload,
                headers: { Accept: 'application/json' },
            });

            return data ?? {};
        } catch (err) {
            const data = err?.response?.data ?? {};

            if (err?.response?.status === 422 && data.errors) {
                errors.value = Object.fromEntries(
                    Object.entries(data.errors).map(([key, value]) => [key, Array.isArray(value) ? value[0] : value]),
                );
                message.value = errors.value.subscription ?? '';
            } else {
                message.value = data.message || fallbackMessage();
            }

            return null;
        } finally {
            saving.value = false;
        }
    }

    return { saving, errors, message, send, reset };
}
