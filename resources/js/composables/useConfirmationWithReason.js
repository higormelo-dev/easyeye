import { ref } from 'vue';

/**
 * Composable para uso do <ConfirmationWithReasonModal>.
 *
 * Exemplo:
 *   const { state, open, close, handle } = useConfirmationWithReason();
 *
 *   function onDelete(item) {
 *       open({
 *           title: 'Excluir empresa',
 *           message: 'Esta ação não pode ser desfeita.',
 *           confirmVariant: 'danger',
 *           async onConfirm(reason) {
 *               const res = await fetch(item.destroy_url, {
 *                   method: 'DELETE',
 *                   headers: { 'Content-Type': 'application/json', ... },
 *                   body: JSON.stringify({ reason }),
 *               });
 *               // ...
 *           },
 *       });
 *   }
 *
 * Template:
 *   <ConfirmationWithReasonModal
 *       :open="state.open"
 *       :title="state.title"
 *       :message="state.message"
 *       :confirm-variant="state.confirmVariant"
 *       :saving="state.saving"
 *       @close="close"
 *       @confirm="handle"
 *   />
 */
export function useConfirmationWithReason() {
    const state = ref({
        open:           false,
        saving:         false,
        title:          '',
        message:        '',
        confirmLabel:   '',
        confirmVariant: 'danger',
        onConfirm:      null,
    });

    function open(config) {
        state.value = {
            open:           true,
            saving:         false,
            title:          config.title          ?? '',
            message:        config.message        ?? '',
            confirmLabel:   config.confirmLabel   ?? '',
            confirmVariant: config.confirmVariant ?? 'danger',
            onConfirm:      config.onConfirm,
        };
    }

    function close() {
        if (state.value.saving) return;
        state.value.open = false;
    }

    async function handle(reason) {
        if (!state.value.onConfirm) return;
        state.value.saving = true;
        try {
            await state.value.onConfirm(reason);
            state.value.open = false;
        } finally {
            state.value.saving = false;
        }
    }

    return { state, open, close, handle };
}
