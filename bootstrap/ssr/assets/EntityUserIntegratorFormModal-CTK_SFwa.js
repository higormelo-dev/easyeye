import { computed, ref, watch, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrIncludeBooleanAttr, ssrRenderAttr, ssrRenderClass, ssrLooseContain, ssrLooseEqual } from "vue/server-renderer";
const _sfc_main = {
  __name: "EntityUserIntegratorFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    entityId: { type: String, required: true },
    itemId: { type: String, default: null },
    editDataUrl: { type: String, default: "" },
    updateUrl: { type: String, default: "" },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const isEdit = computed(() => !!props.itemId);
    const title = computed(() => isEdit.value ? props.t.form_title_edit ?? "Editar Usuário Integrador" : props.t.form_title_create ?? "Novo Usuário Integrador");
    const loading = ref(false);
    const saving = ref(false);
    const loadErr = ref("");
    const form = ref({
      name: "",
      email: "",
      password: "",
      active: true
    });
    const errors = ref({});
    function reset() {
      form.value = { name: "", email: "", password: "", active: true };
      errors.value = {};
      loadErr.value = "";
    }
    async function loadEditData() {
      loading.value = true;
      loadErr.value = "";
      try {
        const res = await fetch(props.editDataUrl, {
          headers: { Accept: "application/json" }
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? "");
        form.value = {
          name: json.data.name ?? "",
          email: json.data.email ?? "",
          password: "",
          active: json.data.active ?? true
        };
      } catch {
        loadErr.value = props.t.detail_loading_error ?? "Erro ao carregar dados.";
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, async (val) => {
      if (!val) return;
      reset();
      if (isEdit.value && props.editDataUrl) {
        await loadEditData();
      }
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
        }, _attrs))}><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="ti ti-user-cog me-1 text-info"></i>${ssrInterpolate(title.value)}</h5><button type="button" class="btn-close"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}></button></div><div class="modal-body">`);
        if (loading.value) {
          _push(`<div class="text-center text-muted py-3"><span class="spinner-border spinner-border-sm me-2"></span> ${ssrInterpolate(__props.t.detail_loading ?? "Carregando...")}</div>`);
        } else if (loadErr.value) {
          _push(`<div class="alert alert-danger small">${ssrInterpolate(loadErr.value)}</div>`);
        } else {
          _push(`<form class="row g-3"><div class="col-12"><label class="form-label">${ssrInterpolate(__props.t.field_name ?? "Nome")} <span class="text-danger">*</span></label><input${ssrRenderAttr("value", form.value.name)} type="text" class="${ssrRenderClass([{ "is-invalid": hasError("name") }, "form-control"])}" maxlength="255" autocomplete="off" required><div class="invalid-feedback">${ssrInterpolate(firstError("name"))}</div></div><div class="col-12"><label class="form-label">${ssrInterpolate(__props.t.field_email ?? "E-mail")} <span class="text-danger">*</span></label><input${ssrRenderAttr("value", form.value.email)} type="email" class="${ssrRenderClass([{ "is-invalid": hasError("email") }, "form-control"])}" maxlength="255" autocomplete="off" required><div class="invalid-feedback">${ssrInterpolate(firstError("email"))}</div></div><div class="col-12"><label class="form-label">${ssrInterpolate(__props.t.field_password ?? "Senha")} `);
          if (!isEdit.value) {
            _push(`<span class="text-danger">*</span>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</label><input${ssrRenderAttr("value", form.value.password)} type="password" class="${ssrRenderClass([{ "is-invalid": hasError("password") }, "form-control"])}" maxlength="255" autocomplete="new-password"><div class="invalid-feedback">${ssrInterpolate(firstError("password"))}</div>`);
          if (isEdit.value) {
            _push(`<small class="text-muted">${ssrInterpolate(__props.t.field_password_hint ?? "Deixe em branco para não alterar.")}</small>`);
          } else {
            _push(`<!---->`);
          }
          _push(`</div>`);
          if (isEdit.value) {
            _push(`<div class="col-md-6"><label class="form-label">${ssrInterpolate(__props.t.field_active ?? "Ativo")}</label><select class="form-select"><option${ssrRenderAttr("value", true)}${ssrIncludeBooleanAttr(Array.isArray(form.value.active) ? ssrLooseContain(form.value.active, true) : ssrLooseEqual(form.value.active, true)) ? " selected" : ""}>${ssrInterpolate(__props.t.field_yes ?? "Sim")}</option><option${ssrRenderAttr("value", false)}${ssrIncludeBooleanAttr(Array.isArray(form.value.active) ? ssrLooseContain(form.value.active, false) : ssrLooseEqual(form.value.active, false)) ? " selected" : ""}>${ssrInterpolate(__props.t.field_no ?? "Não")}</option></select></div>`);
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/EntityUserIntegrators/EntityUserIntegratorFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
