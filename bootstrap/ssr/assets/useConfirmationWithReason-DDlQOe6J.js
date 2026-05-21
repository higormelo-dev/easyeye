import { ref } from "vue";
function useConfirmationWithReason() {
  const state = ref({
    open: false,
    saving: false,
    title: "",
    message: "",
    confirmLabel: "",
    confirmVariant: "danger",
    onConfirm: null
  });
  function open(config) {
    state.value = {
      open: true,
      saving: false,
      title: config.title ?? "",
      message: config.message ?? "",
      confirmLabel: config.confirmLabel ?? "",
      confirmVariant: config.confirmVariant ?? "danger",
      onConfirm: config.onConfirm
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
export {
  useConfirmationWithReason as u
};
