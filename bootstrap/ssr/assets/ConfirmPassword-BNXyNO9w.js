import { ref, unref, withCtx, createVNode, openBlock, createBlock, toDisplayString, createCommentVNode, withModifiers, createTextVNode, withDirectives, vModelDynamic, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderDynamicModel, ssrRenderAttr, ssrRenderClass, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./GuestLayout-C0jWrpRj.js";
import { t as twostepIllustrationImg } from "./twostep-verification-illustration-img-BDktwzgC.js";
const _sfc_main = {
  __name: "ConfirmPassword",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const showPassword = ref(false);
    const form = useForm({ password: "" });
    function submit() {
      form.post("/confirm-password", {
        onFinish: () => form.reset("password")
      });
    }
    return (_ctx, _push, _parent, _attrs) => {
      var _a;
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: ((_a = __props.t.confirm_password) == null ? void 0 : _a.title) ?? "Confirmação de segurança"
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "app-name": __props.appName,
        title: "Área Segura",
        subtitle: "Confirme sua identidade",
        "illustration-src": unref(twostepIllustrationImg)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<p class="text-muted mb-4" style="${ssrRenderStyle({ "font-size": ".9rem" })}"${_scopeId}> Por segurança, confirme sua senha antes de continuar. </p>`);
            if (unref(form).errors.password) {
              _push2(`<div class="alert alert-danger mb-3 py-2"${_scopeId}>${ssrInterpolate(unref(form).errors.password)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<form novalidate${_scopeId}><div class="mb-4"${_scopeId}><label class="form-label"${_scopeId}>Senha <span style="${ssrRenderStyle({ "color": "#ef4444" })}"${_scopeId}>*</span></label><div class="input-group"${_scopeId}><span class="input-group-text bg-white border-end-0"${_scopeId}><i class="ti ti-lock text-muted"${_scopeId}></i></span><input${ssrRenderDynamicModel(showPassword.value ? "text" : "password", unref(form).password, null)}${ssrRenderAttr("type", showPassword.value ? "text" : "password")} class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password }, "form-control border-start-0 border-end-0"])}" autocomplete="current-password" autofocus required${_scopeId}><button type="button" class="btn btn-outline-secondary border-start-0" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showPassword.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div></div><div class="d-grid"${_scopeId}><button type="submit" class="btn btn-primary fw-semibold"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<i class="ti ti-loader-2 ee-spin me-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` Confirmar </button></div></form>`);
          } else {
            return [
              createVNode("p", {
                class: "text-muted mb-4",
                style: { "font-size": ".9rem" }
              }, " Por segurança, confirme sua senha antes de continuar. "),
              unref(form).errors.password ? (openBlock(), createBlock("div", {
                key: 0,
                class: "alert alert-danger mb-3 py-2"
              }, toDisplayString(unref(form).errors.password), 1)) : createCommentVNode("", true),
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"]),
                novalidate: ""
              }, [
                createVNode("div", { class: "mb-4" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode("Senha "),
                    createVNode("span", { style: { "color": "#ef4444" } }, "*")
                  ]),
                  createVNode("div", { class: "input-group" }, [
                    createVNode("span", { class: "input-group-text bg-white border-end-0" }, [
                      createVNode("i", { class: "ti ti-lock text-muted" })
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).password = $event,
                      type: showPassword.value ? "text" : "password",
                      class: ["form-control border-start-0 border-end-0", { "is-invalid": unref(form).errors.password }],
                      autocomplete: "current-password",
                      autofocus: "",
                      required: ""
                    }, null, 10, ["onUpdate:modelValue", "type"]), [
                      [vModelDynamic, unref(form).password]
                    ]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-outline-secondary border-start-0",
                      tabindex: "-1",
                      onClick: ($event) => showPassword.value = !showPassword.value
                    }, [
                      createVNode("i", {
                        class: showPassword.value ? "ti ti-eye-off" : "ti ti-eye"
                      }, null, 2)
                    ], 8, ["onClick"])
                  ])
                ]),
                createVNode("div", { class: "d-grid" }, [
                  createVNode("button", {
                    type: "submit",
                    class: "btn btn-primary fw-semibold",
                    disabled: unref(form).processing
                  }, [
                    unref(form).processing ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-loader-2 ee-spin me-1"
                    })) : createCommentVNode("", true),
                    createTextVNode(" Confirmar ")
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Auth/ConfirmPassword.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
