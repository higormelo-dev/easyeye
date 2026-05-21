import { computed, ref, watch, unref, useSSRContext } from "vue";
import { ssrRenderTeleport, ssrRenderAttr, ssrInterpolate, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
import { useForm } from "@inertiajs/vue3";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "UserFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    userId: { type: String, default: null },
    roles: { type: Object, default: () => ({}) },
    isClient: { type: Boolean, default: true },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const isEdit = computed(() => !!props.userId);
    const title = computed(() => isEdit.value ? props.t.form_title_edit ?? "Editar Usuário" : props.t.form_title_create ?? "Novo Usuário");
    const loading = ref(false);
    const loadErr = ref("");
    const form = useForm({
      name: "",
      email: "",
      rule: "",
      active: true,
      password: "",
      password_confirmation: ""
    });
    const roleOptions = computed(
      () => Object.entries(props.roles).map(([value, label]) => ({ value, label }))
    );
    function resetForm() {
      form.reset();
      form.clearErrors();
      loadErr.value = "";
    }
    async function loadEditData(id) {
      loading.value = true;
      loadErr.value = "";
      try {
        const res = await fetch(route("panel.accesscontrol.users.show", id), {
          headers: { Accept: "application/json" }
        });
        const json = await res.json();
        if (!res.ok) throw new Error(json.message ?? "");
        const d = json.data;
        form.name = d.name ?? "";
        form.email = d.email ?? "";
        form.rule = d.rule ?? "";
        form.active = d.active ?? true;
      } catch {
        loadErr.value = props.t.js_error_load ?? "Erro ao carregar dados do usuário.";
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, async (val) => {
      if (val) {
        resetForm();
        if (props.userId) await loadEditData(props.userId);
      }
    });
    return (_ctx, _push, _parent, _attrs) => {
      ssrRenderTeleport(_push, (_push2) => {
        if (__props.open) {
          _push2(`<div class="ufm-backdrop" data-v-24c2880c></div>`);
        } else {
          _push2(`<!---->`);
        }
        if (__props.open) {
          _push2(`<div class="ufm-panel" role="dialog"${ssrRenderAttr("aria-label", title.value)} data-v-24c2880c><div class="ufm-header" data-v-24c2880c><h5 class="mb-0 fw-semibold" data-v-24c2880c><i class="ti ti-user-shield me-2 text-primary" data-v-24c2880c></i>${ssrInterpolate(title.value)}</h5><button type="button" class="btn-close" data-v-24c2880c></button></div>`);
          if (loading.value) {
            _push2(`<div class="text-center py-5" data-v-24c2880c><div class="spinner-border text-primary" role="status" data-v-24c2880c></div></div>`);
          } else if (loadErr.value) {
            _push2(`<div class="p-4" data-v-24c2880c><div class="alert alert-danger small py-2 mb-0" data-v-24c2880c><i class="ti ti-alert-circle me-1" data-v-24c2880c></i>${ssrInterpolate(loadErr.value)}</div></div>`);
          } else {
            _push2(`<form class="ufm-body" autocomplete="off" data-v-24c2880c><div class="mb-3" data-v-24c2880c><label class="form-label fw-semibold" data-v-24c2880c>${ssrInterpolate(__props.t.field_name)} <span class="text-danger" data-v-24c2880c>*</span></label><input${ssrRenderAttr("value", unref(form).name)} type="text" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.name }, "form-control"])}" autocomplete="off" data-v-24c2880c>`);
            if (unref(form).errors.name) {
              _push2(`<div class="invalid-feedback" data-v-24c2880c>${ssrInterpolate(unref(form).errors.name)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3" data-v-24c2880c><label class="form-label fw-semibold" data-v-24c2880c>${ssrInterpolate(__props.t.field_email)} <span class="text-danger" data-v-24c2880c>*</span></label><input${ssrRenderAttr("value", unref(form).email)} type="email" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}" autocomplete="off" data-v-24c2880c>`);
            if (unref(form).errors.email) {
              _push2(`<div class="invalid-feedback" data-v-24c2880c>${ssrInterpolate(unref(form).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-3" data-v-24c2880c><label class="form-label fw-semibold" data-v-24c2880c>${ssrInterpolate(__props.t.field_role)} <span class="text-danger" data-v-24c2880c>*</span></label><select class="${ssrRenderClass([{ "is-invalid": unref(form).errors.rule }, "form-select"])}" data-v-24c2880c><option value="" data-v-24c2880c${ssrIncludeBooleanAttr(Array.isArray(unref(form).rule) ? ssrLooseContain(unref(form).rule, "") : ssrLooseEqual(unref(form).rule, "")) ? " selected" : ""}>${ssrInterpolate(__props.t.field_role_placeholder)}</option><!--[-->`);
            ssrRenderList(roleOptions.value, (r) => {
              _push2(`<option${ssrRenderAttr("value", r.value)} data-v-24c2880c${ssrIncludeBooleanAttr(Array.isArray(unref(form).rule) ? ssrLooseContain(unref(form).rule, r.value) : ssrLooseEqual(unref(form).rule, r.value)) ? " selected" : ""}>${ssrInterpolate(r.label)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (unref(form).errors.rule) {
              _push2(`<div class="invalid-feedback" data-v-24c2880c>${ssrInterpolate(unref(form).errors.rule)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (isEdit.value) {
              _push2(`<div class="mb-3" data-v-24c2880c><div class="form-check form-switch" data-v-24c2880c><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, null) : unref(form).active) ? " checked" : ""} class="form-check-input" type="checkbox" id="usfActive" data-v-24c2880c><label class="form-check-label" for="usfActive" data-v-24c2880c>${ssrInterpolate(__props.t.field_active)}</label></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (!isEdit.value) {
              _push2(`<!--[--><hr class="my-3" data-v-24c2880c><div class="alert alert-info small py-2" data-v-24c2880c><i class="ti ti-info-circle me-1" data-v-24c2880c></i>${ssrInterpolate(__props.t.credentials_info)}</div><div class="mb-3" data-v-24c2880c><label class="form-label fw-semibold" data-v-24c2880c>${ssrInterpolate(__props.t.field_password)} <span class="text-danger" data-v-24c2880c>*</span></label><input${ssrRenderAttr("value", unref(form).password)} type="password" autocomplete="new-password" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password }, "form-control"])}" data-v-24c2880c>`);
              if (unref(form).errors.password) {
                _push2(`<div class="invalid-feedback" data-v-24c2880c>${ssrInterpolate(unref(form).errors.password)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="form-text" data-v-24c2880c>${ssrInterpolate(__props.t.field_password_hint)}</div></div><div class="mb-3" data-v-24c2880c><label class="form-label fw-semibold" data-v-24c2880c>${ssrInterpolate(__props.t.field_password_confirm)} <span class="text-danger" data-v-24c2880c>*</span></label><input${ssrRenderAttr("value", unref(form).password_confirmation)} type="password" autocomplete="new-password" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password_confirmation }, "form-control"])}" data-v-24c2880c>`);
              if (unref(form).errors.password_confirmation) {
                _push2(`<div class="invalid-feedback" data-v-24c2880c>${ssrInterpolate(unref(form).errors.password_confirmation)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="ufm-footer" data-v-24c2880c><button type="button" class="btn btn-light" data-v-24c2880c>${ssrInterpolate(__props.t.btn_cancel)}</button><button type="submit" class="btn btn-primary"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""} data-v-24c2880c>`);
            if (unref(form).processing) {
              _push2(`<span class="spinner-border spinner-border-sm me-1" data-v-24c2880c></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(isEdit.value ? __props.t.btn_save : __props.t.btn_create)}</button></div></form>`);
          }
          _push2(`</div>`);
        } else {
          _push2(`<!---->`);
        }
      }, "body", false, _parent);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Users/UserFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const UserFormModal = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-24c2880c"]]);
export {
  UserFormModal as default
};
