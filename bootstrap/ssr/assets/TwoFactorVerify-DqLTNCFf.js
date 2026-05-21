import { ref, unref, withCtx, createVNode, withModifiers, toDisplayString, withDirectives, vModelText, openBlock, createBlock, createCommentVNode, createTextVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./GuestLayout-C0jWrpRj.js";
import { t as twostepIllustrationImg } from "./twostep-verification-illustration-img-BDktwzgC.js";
const _sfc_main = {
  __name: "TwoFactorVerify",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const mode = ref("totp");
    const code = ref("");
    const error = ref("");
    const saving = ref(false);
    async function submit() {
      var _a;
      error.value = "";
      saving.value = true;
      try {
        const res = await fetch(route("security.two-factor.verify.store"), {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
          },
          body: JSON.stringify({ code: code.value })
        });
        const json = await res.json();
        if (!res.ok) {
          error.value = json.message ?? "Código inválido.";
          return;
        }
        window.location.href = json.redirect ?? "/";
      } catch {
        error.value = "Erro de rede. Tente novamente.";
      } finally {
        saving.value = false;
      }
    }
    function toggleMode() {
      mode.value = mode.value === "totp" ? "recovery" : "totp";
      code.value = "";
      error.value = "";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: __props.t.verify_title
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "layout-mode": "illustration",
        "app-name": props.appName,
        title: __props.t.verify_title,
        subtitle: __props.t.verify_intro,
        "illustration-src": unref(twostepIllustrationImg)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<form${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(mode.value === "totp" ? __props.t.code_label : __props.t.recovery_code_label)}</label><input${ssrRenderAttr("value", code.value)} type="text"${ssrRenderAttr("inputmode", mode.value === "totp" ? "numeric" : "text")}${ssrRenderAttr("autocomplete", mode.value === "totp" ? "one-time-code" : "off")} class="${ssrRenderClass([{ "is-invalid": error.value }, "form-control text-center fw-bold fs-4 font-monospace"])}"${ssrRenderAttr("placeholder", mode.value === "totp" ? __props.t.code_placeholder : "XXXX-XXXX")}${ssrRenderAttr("maxlength", mode.value === "totp" ? 7 : 20)} autofocus${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}${_scopeId}>`);
            if (error.value) {
              _push2(`<div class="invalid-feedback d-block"${_scopeId}>${ssrInterpolate(error.value)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="d-flex justify-content-between align-items-center mt-4"${_scopeId}><button type="button" class="btn btn-link btn-sm p-0"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}${_scopeId}>${ssrInterpolate(mode.value === "totp" ? __props.t.verify_use_recovery : __props.t.verify_use_totp)}</button><button type="submit" class="btn btn-primary"${ssrIncludeBooleanAttr(saving.value || !code.value) ? " disabled" : ""}${_scopeId}>`);
            if (saving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<i class="ti ti-shield-check me-1"${_scopeId}></i>`);
            }
            _push2(` ${ssrInterpolate(__props.t.btn_verify)}</button></div></form>`);
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"])
              }, [
                createVNode("label", { class: "form-label small" }, toDisplayString(mode.value === "totp" ? __props.t.code_label : __props.t.recovery_code_label), 1),
                withDirectives(createVNode("input", {
                  "onUpdate:modelValue": ($event) => code.value = $event,
                  type: "text",
                  inputmode: mode.value === "totp" ? "numeric" : "text",
                  autocomplete: mode.value === "totp" ? "one-time-code" : "off",
                  class: ["form-control text-center fw-bold fs-4 font-monospace", { "is-invalid": error.value }],
                  placeholder: mode.value === "totp" ? __props.t.code_placeholder : "XXXX-XXXX",
                  maxlength: mode.value === "totp" ? 7 : 20,
                  autofocus: "",
                  disabled: saving.value
                }, null, 10, ["onUpdate:modelValue", "inputmode", "autocomplete", "placeholder", "maxlength", "disabled"]), [
                  [vModelText, code.value]
                ]),
                error.value ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "invalid-feedback d-block"
                }, toDisplayString(error.value), 1)) : createCommentVNode("", true),
                createVNode("div", { class: "d-flex justify-content-between align-items-center mt-4" }, [
                  createVNode("button", {
                    type: "button",
                    class: "btn btn-link btn-sm p-0",
                    onClick: toggleMode,
                    disabled: saving.value
                  }, toDisplayString(mode.value === "totp" ? __props.t.verify_use_recovery : __props.t.verify_use_totp), 9, ["disabled"]),
                  createVNode("button", {
                    type: "submit",
                    class: "btn btn-primary",
                    disabled: saving.value || !code.value
                  }, [
                    saving.value ? (openBlock(), createBlock("span", {
                      key: 0,
                      class: "spinner-border spinner-border-sm me-1"
                    })) : (openBlock(), createBlock("i", {
                      key: 1,
                      class: "ti ti-shield-check me-1"
                    })),
                    createTextVNode(" " + toDisplayString(__props.t.btn_verify), 1)
                  ], 8, ["disabled"])
                ])
              ], 32)
            ];
          }
        }),
        _: 1
      }, _parent));
      _push(`<!--]-->`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Security/TwoFactorVerify.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
