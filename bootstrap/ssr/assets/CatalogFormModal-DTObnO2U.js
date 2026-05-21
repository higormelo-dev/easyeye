import { computed, ref, watch, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrIncludeBooleanAttr, ssrRenderList, ssrRenderClass, ssrRenderDynamicModel, ssrRenderAttr, ssrRenderStyle, ssrLooseContain, ssrLooseEqual } from "vue/server-renderer";
const _sfc_main = {
  __name: "CatalogFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    itemId: { type: String, default: null },
    fields: { type: Array, required: true },
    crudFields: { type: Object, required: true },
    storeUrl: { type: String, required: true },
    urlTemplates: { type: Object, required: true },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const isEdit = computed(() => !!props.itemId);
    const title = computed(() => isEdit.value ? props.t.form_title_edit ?? "Editar registro" : props.t.form_title_create ?? "Novo registro");
    const loading = ref(false);
    const saving = ref(false);
    const errors = ref({});
    const form = ref({ ...props.crudFields });
    function reset() {
      form.value = { ...props.crudFields };
      errors.value = {};
    }
    async function loadEditData() {
      loading.value = true;
      try {
        const url = props.urlTemplates.update.replace("__ID__", props.itemId).replace("/update", "/edit");
        const editUrl = props.urlTemplates.update.replace("__ID__", props.itemId);
        const showUrl = props.urlTemplates.show.replace("__ID__", props.itemId);
        const res = await fetch(showUrl, { headers: { Accept: "application/json" } });
        const json = await res.json();
        const next = { ...props.crudFields };
        for (const key of Object.keys(props.crudFields)) {
          if (json.data && key in json.data) {
            next[key] = json.data[key];
          }
        }
        form.value = next;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, async (val) => {
      if (!val) return;
      reset();
      if (isEdit.value) await loadEditData();
    });
    function hasError(field) {
      return !!(errors.value[field] && errors.value[field].length);
    }
    function firstError(field) {
      var _a;
      return ((_a = errors.value[field]) == null ? void 0 : _a[0]) ?? "";
    }
    return (_ctx, _push, _parent, _attrs) => {
      if (__props.open) {
        _push(`<div${ssrRenderAttrs(mergeProps({
          class: "modal d-block",
          tabindex: "-1",
          style: { "background": "rgba(0,0,0,.45)" }
        }, _attrs))}><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-database me-1 text-info"></i>${ssrInterpolate(title.value)}</h5><button type="button" class="btn-close"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></button></div><div class="modal-body">`);
        if (loading.value) {
          _push(`<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span> ${ssrInterpolate(__props.t.loading ?? "Carregando...")}</div>`);
        } else {
          _push(`<form class="row g-3"><!--[-->`);
          ssrRenderList(__props.fields, (field) => {
            _push(`<div class="${ssrRenderClass(field.type === "checkbox" ? "col-md-6" : "col-12")}">`);
            if (field.type !== "checkbox") {
              _push(`<label class="form-label">${ssrInterpolate(field.label)} `);
              if (field.required) {
                _push(`<span class="text-danger">*</span>`);
              } else {
                _push(`<!---->`);
              }
              _push(`</label>`);
            } else {
              _push(`<!---->`);
            }
            if (field.type === "text" || field.type === "numeric" || !field.type) {
              _push(`<input${ssrRenderDynamicModel(field.type === "numeric" ? "number" : "text", form.value[field.key], null)}${ssrRenderAttr("type", field.type === "numeric" ? "number" : "text")} class="${ssrRenderClass([{ "is-invalid": hasError(field.key) }, "form-control"])}"${ssrRenderAttr("maxlength", field.maxlength ?? 255)}${ssrRenderAttr("step", field.step ?? void 0)}${ssrRenderAttr("min", field.min ?? void 0)}${ssrIncludeBooleanAttr(field.required ?? false) ? " required" : ""} autocomplete="off">`);
            } else if (field.type === "color") {
              _push(`<input${ssrRenderAttr("value", form.value[field.key])} type="color" class="${ssrRenderClass([{ "is-invalid": hasError(field.key) }, "form-control form-control-color"])}" style="${ssrRenderStyle({ "width": "100px", "height": "40px" })}">`);
            } else if (field.type === "select") {
              _push(`<select class="${ssrRenderClass([{ "is-invalid": hasError(field.key) }, "form-select"])}"${ssrIncludeBooleanAttr(field.required ?? false) ? " required" : ""}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value[field.key]) ? ssrLooseContain(form.value[field.key], "") : ssrLooseEqual(form.value[field.key], "")) ? " selected" : ""}>—</option><!--[-->`);
              ssrRenderList(field.options ?? [], (opt) => {
                _push(`<option${ssrRenderAttr("value", opt.value)}${ssrIncludeBooleanAttr(Array.isArray(form.value[field.key]) ? ssrLooseContain(form.value[field.key], opt.value) : ssrLooseEqual(form.value[field.key], opt.value)) ? " selected" : ""}>${ssrInterpolate(opt.label)}</option>`);
              });
              _push(`<!--]--></select>`);
            } else if (field.type === "checkbox") {
              _push(`<div class="form-check form-switch mt-2"><input${ssrRenderAttr("id", `field_${field.key}`)}${ssrIncludeBooleanAttr(Array.isArray(form.value[field.key]) ? ssrLooseContain(form.value[field.key], null) : form.value[field.key]) ? " checked" : ""} type="checkbox" class="form-check-input" role="switch"><label class="form-check-label"${ssrRenderAttr("for", `field_${field.key}`)}>${ssrInterpolate(field.label)}</label></div>`);
            } else {
              _push(`<!---->`);
            }
            if (hasError(field.key)) {
              _push(`<div class="invalid-feedback d-block">${ssrInterpolate(firstError(field.key))}</div>`);
            } else {
              _push(`<!---->`);
            }
            if (field.hint) {
              _push(`<small class="text-muted d-block">${ssrInterpolate(field.hint)}</small>`);
            } else {
              _push(`<!---->`);
            }
            _push(`</div>`);
          });
          _push(`<!--]-->`);
          if (isEdit.value && "active" in __props.crudFields) {
            _push(`<div class="col-md-6"><label class="form-label">${ssrInterpolate(__props.t.status_active ?? "Ativo")}</label><div class="form-check form-switch"><input id="field_active"${ssrIncludeBooleanAttr(Array.isArray(form.value.active) ? ssrLooseContain(form.value.active, null) : form.value.active) ? " checked" : ""} type="checkbox" class="form-check-input" role="switch"><label class="form-check-label" for="field_active">${ssrInterpolate(form.value.active ? __props.t.status_active ?? "Ativo" : __props.t.status_inactive ?? "Inativo")}</label></div></div>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</form>`);
        }
        _push(`</div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}>${ssrInterpolate(__props.t.btn_cancel ?? "Cancelar")}</button><button type="button" class="btn btn-primary btn-sm"${ssrIncludeBooleanAttr(saving.value || loading.value) ? " disabled" : ""}>`);
        if (saving.value) {
          _push(`<span class="spinner-border spinner-border-sm me-1"></span>`);
        } else {
          _push(`<i class="ti ti-check me-1"></i>`);
        }
        _push(` ${ssrInterpolate(isEdit.value ? __props.t.btn_save ?? "Salvar" : __props.t.btn_create ?? "Cadastrar")}</button></div></div></div></div>`);
      } else {
        _push(`<!---->`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Settings/Catalog/CatalogFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
