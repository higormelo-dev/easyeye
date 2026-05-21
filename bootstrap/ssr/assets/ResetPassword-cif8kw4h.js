import { ref, unref, withCtx, openBlock, createBlock, createVNode, createTextVNode, toDisplayString, createCommentVNode, withModifiers, withDirectives, vModelText, vModelDynamic, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrRenderStyle, ssrRenderClass, ssrRenderDynamicModel, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./GuestLayout-C0jWrpRj.js";
const resetIllustrationImg = "/build/assets/reset-illustration-img-C17wCf-S.png";
const _sfc_main = {
  __name: "ResetPassword",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) },
    token: { type: String, required: true },
    email: { type: String, default: "" }
  },
  setup(__props) {
    const props = __props;
    const showPassword = ref(false);
    const showConfirm = ref(false);
    const form = useForm({
      token: props.token,
      email: props.email,
      password: "",
      password_confirmation: ""
    });
    function submit() {
      form.post("/reset-password", {
        onFinish: () => form.reset("password", "password_confirmation")
      });
    }
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b;
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: (_a = __props.t.reset_password) == null ? void 0 : _a.title
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "app-name": __props.appName,
        title: (_b = __props.t.reset_password) == null ? void 0 : _b.title,
        "illustration-src": unref(resetIllustrationImg)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b2, _c, _d, _e, _f, _g, _h;
          if (_push2) {
            if (unref(form).errors.email) {
              _push2(`<div class="alert alert-danger mb-3 py-2"${_scopeId}><i class="ti ti-alert-circle me-1"${_scopeId}></i> ${ssrInterpolate(unref(form).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<form novalidate${_scopeId}><input type="hidden"${ssrRenderAttr("value", unref(form).token)} name="token"${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate((_a2 = __props.t.reset_password) == null ? void 0 : _a2.email)} <span style="${ssrRenderStyle({ "color": "#ef4444" })}"${_scopeId}>*</span></label><div class="input-group"${_scopeId}><span class="input-group-text"${_scopeId}><i class="ti ti-mail"${_scopeId}></i></span><input${ssrRenderAttr("value", unref(form).email)} type="email" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}" autofocus autocomplete="username" required${_scopeId}></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate((_b2 = __props.t.reset_password) == null ? void 0 : _b2.password)} <span style="${ssrRenderStyle({ "color": "#ef4444" })}"${_scopeId}>*</span></label><div class="input-group"${_scopeId}><input${ssrRenderDynamicModel(showPassword.value ? "text" : "password", unref(form).password, null)}${ssrRenderAttr("type", showPassword.value ? "text" : "password")} class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password }, "form-control"])}" autocomplete="new-password" required${_scopeId}><button type="button" class="btn btn-outline-secondary" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showPassword.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div>`);
            if (unref(form).errors.password) {
              _push2(`<div class="invalid-feedback d-block mt-1"${_scopeId}>${ssrInterpolate(unref(form).errors.password)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="mb-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate((_c = __props.t.reset_password) == null ? void 0 : _c.confirm_password)} <span style="${ssrRenderStyle({ "color": "#ef4444" })}"${_scopeId}>*</span></label><div class="input-group"${_scopeId}><input${ssrRenderDynamicModel(showConfirm.value ? "text" : "password", unref(form).password_confirmation, null)}${ssrRenderAttr("type", showConfirm.value ? "text" : "password")} class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password_confirmation }, "form-control"])}" autocomplete="new-password" required${_scopeId}><button type="button" class="btn btn-outline-secondary" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showConfirm.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div></div><div class="d-grid mb-3"${_scopeId}><button type="submit" class="btn btn-primary fw-semibold"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<i class="ti ti-loader-2 ee-spin me-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate((_d = __props.t.reset_password) == null ? void 0 : _d.submit)}</button></div><p class="text-center mb-0"${_scopeId}><a href="/login" class="text-muted" style="${ssrRenderStyle({ "font-size": ".875rem", "text-decoration": "none" })}"${_scopeId}>${ssrInterpolate(__props.t.back_to_login)}</a></p></form>`);
          } else {
            return [
              unref(form).errors.email ? (openBlock(), createBlock("div", {
                key: 0,
                class: "alert alert-danger mb-3 py-2"
              }, [
                createVNode("i", { class: "ti ti-alert-circle me-1" }),
                createTextVNode(" " + toDisplayString(unref(form).errors.email), 1)
              ])) : createCommentVNode("", true),
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"]),
                novalidate: ""
              }, [
                createVNode("input", {
                  type: "hidden",
                  value: unref(form).token,
                  name: "token"
                }, null, 8, ["value"]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString((_e = __props.t.reset_password) == null ? void 0 : _e.email) + " ", 1),
                    createVNode("span", { style: { "color": "#ef4444" } }, "*")
                  ]),
                  createVNode("div", { class: "input-group" }, [
                    createVNode("span", { class: "input-group-text" }, [
                      createVNode("i", { class: "ti ti-mail" })
                    ]),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).email = $event,
                      type: "email",
                      class: ["form-control", { "is-invalid": unref(form).errors.email }],
                      autofocus: "",
                      autocomplete: "username",
                      required: ""
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, unref(form).email]
                    ])
                  ])
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString((_f = __props.t.reset_password) == null ? void 0 : _f.password) + " ", 1),
                    createVNode("span", { style: { "color": "#ef4444" } }, "*")
                  ]),
                  createVNode("div", { class: "input-group" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).password = $event,
                      type: showPassword.value ? "text" : "password",
                      class: ["form-control", { "is-invalid": unref(form).errors.password }],
                      autocomplete: "new-password",
                      required: ""
                    }, null, 10, ["onUpdate:modelValue", "type"]), [
                      [vModelDynamic, unref(form).password]
                    ]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-outline-secondary",
                      tabindex: "-1",
                      onClick: ($event) => showPassword.value = !showPassword.value
                    }, [
                      createVNode("i", {
                        class: showPassword.value ? "ti ti-eye-off" : "ti ti-eye"
                      }, null, 2)
                    ], 8, ["onClick"])
                  ]),
                  unref(form).errors.password ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback d-block mt-1"
                  }, toDisplayString(unref(form).errors.password), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "mb-4" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString((_g = __props.t.reset_password) == null ? void 0 : _g.confirm_password) + " ", 1),
                    createVNode("span", { style: { "color": "#ef4444" } }, "*")
                  ]),
                  createVNode("div", { class: "input-group" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => unref(form).password_confirmation = $event,
                      type: showConfirm.value ? "text" : "password",
                      class: ["form-control", { "is-invalid": unref(form).errors.password_confirmation }],
                      autocomplete: "new-password",
                      required: ""
                    }, null, 10, ["onUpdate:modelValue", "type"]), [
                      [vModelDynamic, unref(form).password_confirmation]
                    ]),
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-outline-secondary",
                      tabindex: "-1",
                      onClick: ($event) => showConfirm.value = !showConfirm.value
                    }, [
                      createVNode("i", {
                        class: showConfirm.value ? "ti ti-eye-off" : "ti ti-eye"
                      }, null, 2)
                    ], 8, ["onClick"])
                  ])
                ]),
                createVNode("div", { class: "d-grid mb-3" }, [
                  createVNode("button", {
                    type: "submit",
                    class: "btn btn-primary fw-semibold",
                    disabled: unref(form).processing
                  }, [
                    unref(form).processing ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-loader-2 ee-spin me-1"
                    })) : createCommentVNode("", true),
                    createTextVNode(" " + toDisplayString((_h = __props.t.reset_password) == null ? void 0 : _h.submit), 1)
                  ], 8, ["disabled"])
                ]),
                createVNode("p", { class: "text-center mb-0" }, [
                  createVNode("a", {
                    href: "/login",
                    class: "text-muted",
                    style: { "font-size": ".875rem", "text-decoration": "none" }
                  }, toDisplayString(__props.t.back_to_login), 1)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Auth/ResetPassword.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
