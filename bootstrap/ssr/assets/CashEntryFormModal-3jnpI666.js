import { computed, ref, watch, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrIncludeBooleanAttr, ssrRenderAttr, ssrRenderClass, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
const _sfc_main = {
  __name: "CashEntryFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    entryId: { type: String, default: null },
    categories: { type: Array, default: () => [] }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const isEdit = computed(() => !!props.entryId);
    const saving = ref(false);
    const errors = ref({});
    const form = ref({
      entry_date: (/* @__PURE__ */ new Date()).toISOString().slice(0, 10),
      type: "revenue",
      status: "paid",
      amount: 0,
      description: "",
      category_id: "",
      notes: ""
    });
    function reset() {
      form.value = {
        entry_date: (/* @__PURE__ */ new Date()).toISOString().slice(0, 10),
        type: "revenue",
        status: "paid",
        amount: 0,
        description: "",
        category_id: "",
        notes: ""
      };
      errors.value = {};
    }
    watch(() => props.open, async (val) => {
      if (!val) return;
      reset();
    });
    function hasError(f) {
      return !!(errors.value[f] && errors.value[f].length);
    }
    function firstError(f) {
      var _a;
      return ((_a = errors.value[f]) == null ? void 0 : _a[0]) ?? "";
    }
    const filteredCategories = computed(
      () => props.categories.filter((c) => !form.value.type || c.type === form.value.type)
    );
    return (_ctx, _push, _parent, _attrs) => {
      if (__props.open) {
        _push(`<div${ssrRenderAttrs(mergeProps({
          class: "modal d-block",
          tabindex: "-1",
          style: { "background": "rgba(0,0,0,.45)" }
        }, _attrs))}><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-cash-register me-1 text-primary"></i> ${ssrInterpolate(isEdit.value ? "Editar lançamento" : "Novo lançamento")}</h5><button type="button" class="btn-close"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></button></div><div class="modal-body"><form class="row g-3"><div class="col-md-6"><label class="form-label">Data <span class="text-danger">*</span></label><input${ssrRenderAttr("value", form.value.entry_date)} type="date" class="${ssrRenderClass([{ "is-invalid": hasError("entry_date") }, "form-control"])}" required><div class="invalid-feedback">${ssrInterpolate(firstError("entry_date"))}</div></div><div class="col-md-6"><label class="form-label">Tipo <span class="text-danger">*</span></label><select class="${ssrRenderClass([{ "is-invalid": hasError("type") }, "form-select"])}" required><option value="revenue"${ssrIncludeBooleanAttr(Array.isArray(form.value.type) ? ssrLooseContain(form.value.type, "revenue") : ssrLooseEqual(form.value.type, "revenue")) ? " selected" : ""}>Receita</option><option value="expense"${ssrIncludeBooleanAttr(Array.isArray(form.value.type) ? ssrLooseContain(form.value.type, "expense") : ssrLooseEqual(form.value.type, "expense")) ? " selected" : ""}>Despesa</option></select><div class="invalid-feedback">${ssrInterpolate(firstError("type"))}</div></div><div class="col-12"><label class="form-label">Descrição <span class="text-danger">*</span></label><input${ssrRenderAttr("value", form.value.description)} type="text" maxlength="255" class="${ssrRenderClass([{ "is-invalid": hasError("description") }, "form-control"])}" required><div class="invalid-feedback">${ssrInterpolate(firstError("description"))}</div></div><div class="col-md-6"><label class="form-label">Categoria</label><select class="form-select"><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.category_id) ? ssrLooseContain(form.value.category_id, "") : ssrLooseEqual(form.value.category_id, "")) ? " selected" : ""}>—</option><!--[-->`);
        ssrRenderList(filteredCategories.value, (cat) => {
          _push(`<option${ssrRenderAttr("value", cat.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.category_id) ? ssrLooseContain(form.value.category_id, cat.id) : ssrLooseEqual(form.value.category_id, cat.id)) ? " selected" : ""}>${ssrInterpolate(cat.name)}</option>`);
        });
        _push(`<!--]--></select></div><div class="col-md-6"><label class="form-label">Status</label><select class="form-select"><option value="pending"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "pending") : ssrLooseEqual(form.value.status, "pending")) ? " selected" : ""}>Pendente</option><option value="paid"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "paid") : ssrLooseEqual(form.value.status, "paid")) ? " selected" : ""}>Pago</option><option value="overdue"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "overdue") : ssrLooseEqual(form.value.status, "overdue")) ? " selected" : ""}>Atrasado</option></select></div><div class="col-12"><label class="form-label">Valor (R$) <span class="text-danger">*</span></label><input${ssrRenderAttr("value", form.value.amount)} type="number" step="0.01" min="0" class="${ssrRenderClass([{ "is-invalid": hasError("amount") }, "form-control"])}" required><div class="invalid-feedback">${ssrInterpolate(firstError("amount"))}</div></div><div class="col-12"><label class="form-label">Observações</label><textarea rows="2" class="form-control" maxlength="500">${ssrInterpolate(form.value.notes)}</textarea></div></form></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}> Cancelar </button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>`);
        if (saving.value) {
          _push(`<span class="spinner-border spinner-border-sm me-1"></span>`);
        } else {
          _push(`<i class="ti ti-check me-1"></i>`);
        }
        _push(` ${ssrInterpolate(isEdit.value ? "Salvar" : "Cadastrar")}</button></div></div></div></div>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Financial/CashFlow/CashEntryFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
