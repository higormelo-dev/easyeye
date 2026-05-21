import { computed, unref, withCtx, openBlock, createBlock, createVNode, createTextVNode, toDisplayString, createCommentVNode, withModifiers, withDirectives, vModelText, Fragment, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderAttr, ssrRenderClass, ssrIncludeBooleanAttr, ssrRenderList } from "vue/server-renderer";
import { usePage, useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./GuestLayout-C0jWrpRj.js";
import { l as logoWhiteSvg } from "./logo-white-hVd1h5De.js";
const forgotIllustrationImg = "/build/assets/forgot-illustration-img-BayqvdBD.png";
const _sfc_main = {
  __name: "ForgotPassword",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const page = usePage();
    const statusMessage = computed(() => {
      var _a;
      return ((_a = page.props.flash) == null ? void 0 : _a.status) ?? null;
    });
    const form = useForm({ email: "" });
    function submit() {
      form.post("/forgot-password");
    }
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b;
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: (_a = __props.t.forgot_password) == null ? void 0 : _a.title
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "app-name": __props.appName,
        title: (_b = __props.t.forgot_password) == null ? void 0 : _b.title,
        "illustration-src": unref(forgotIllustrationImg)
      }, {
        "left-panel": withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b2, _c, _d, _e, _f;
          if (_push2) {
            _push2(`<div class="ee-fp-dark-panel"${_scopeId}><img${ssrRenderAttr("src", unref(logoWhiteSvg))}${ssrRenderAttr("alt", __props.appName)} style="${ssrRenderStyle({ "height": "32px", "margin-bottom": "2.5rem" })}"${_scopeId}><div class="rounded-circle d-flex align-items-center justify-content-center mb-4" style="${ssrRenderStyle({ "width": "80px", "height": "80px", "background": "rgba(0,180,216,.15)", "font-size": "2rem", "color": "#00B4D8" })}"${_scopeId}><i class="ti ti-lock-open"${_scopeId}></i></div><h3 class="fw-bold mb-2" style="${ssrRenderStyle({ "color": "#fff", "font-size": "1.4rem" })}"${_scopeId}>${ssrInterpolate((_a2 = __props.t.panel_fp) == null ? void 0 : _a2.title)}</h3><p class="mb-4" style="${ssrRenderStyle({ "color": "rgba(255,255,255,.65)", "font-size": ".9rem", "max-width": "280px" })}"${_scopeId}>${ssrInterpolate((_b2 = __props.t.panel_fp) == null ? void 0 : _b2.subtitle)}</p><div class="d-flex flex-column gap-3 text-start" style="${ssrRenderStyle({ "max-width": "300px", "width": "100%" })}"${_scopeId}><!--[-->`);
            ssrRenderList(3, (n) => {
              var _a3, _b3, _c2;
              _push2(`<div class="d-flex align-items-start gap-3"${_scopeId}><div class="rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0" style="${ssrRenderStyle({ "width": "28px", "height": "28px", "background": "rgba(0,180,216,.2)", "color": "#00B4D8", "font-size": "13px" })}"${_scopeId}>${ssrInterpolate(n)}</div><span style="${ssrRenderStyle({ "color": "rgba(255,255,255,.75)", "font-size": ".85rem", "padding-top": "4px" })}"${_scopeId}>${ssrInterpolate(n === 1 ? (_a3 = __props.t.panel_fp) == null ? void 0 : _a3.step_1 : n === 2 ? (_b3 = __props.t.panel_fp) == null ? void 0 : _b3.step_2 : (_c2 = __props.t.panel_fp) == null ? void 0 : _c2.step_3)}</span></div>`);
            });
            _push2(`<!--]--></div><div class="d-flex align-items-center gap-2 mt-4 px-3 py-2 rounded" style="${ssrRenderStyle({ "background": "rgba(255,255,255,.07)", "color": "rgba(255,255,255,.55)", "font-size": ".8rem" })}"${_scopeId}><i class="ti ti-shield-check" style="${ssrRenderStyle({ "color": "#00B4D8" })}"${_scopeId}></i> ${ssrInterpolate((_c = __props.t.panel_fp) == null ? void 0 : _c.security_note)}</div></div>`);
          } else {
            return [
              createVNode("div", { class: "ee-fp-dark-panel" }, [
                createVNode("img", {
                  src: unref(logoWhiteSvg),
                  alt: __props.appName,
                  style: { "height": "32px", "margin-bottom": "2.5rem" }
                }, null, 8, ["src", "alt"]),
                createVNode("div", {
                  class: "rounded-circle d-flex align-items-center justify-content-center mb-4",
                  style: { "width": "80px", "height": "80px", "background": "rgba(0,180,216,.15)", "font-size": "2rem", "color": "#00B4D8" }
                }, [
                  createVNode("i", { class: "ti ti-lock-open" })
                ]),
                createVNode("h3", {
                  class: "fw-bold mb-2",
                  style: { "color": "#fff", "font-size": "1.4rem" }
                }, toDisplayString((_d = __props.t.panel_fp) == null ? void 0 : _d.title), 1),
                createVNode("p", {
                  class: "mb-4",
                  style: { "color": "rgba(255,255,255,.65)", "font-size": ".9rem", "max-width": "280px" }
                }, toDisplayString((_e = __props.t.panel_fp) == null ? void 0 : _e.subtitle), 1),
                createVNode("div", {
                  class: "d-flex flex-column gap-3 text-start",
                  style: { "max-width": "300px", "width": "100%" }
                }, [
                  (openBlock(), createBlock(Fragment, null, renderList(3, (n) => {
                    var _a3, _b3, _c2;
                    return createVNode("div", {
                      key: n,
                      class: "d-flex align-items-start gap-3"
                    }, [
                      createVNode("div", {
                        class: "rounded-circle d-flex align-items-center justify-content-center fw-bold flex-shrink-0",
                        style: { "width": "28px", "height": "28px", "background": "rgba(0,180,216,.2)", "color": "#00B4D8", "font-size": "13px" }
                      }, toDisplayString(n), 1),
                      createVNode("span", { style: { "color": "rgba(255,255,255,.75)", "font-size": ".85rem", "padding-top": "4px" } }, toDisplayString(n === 1 ? (_a3 = __props.t.panel_fp) == null ? void 0 : _a3.step_1 : n === 2 ? (_b3 = __props.t.panel_fp) == null ? void 0 : _b3.step_2 : (_c2 = __props.t.panel_fp) == null ? void 0 : _c2.step_3), 1)
                    ]);
                  }), 64))
                ]),
                createVNode("div", {
                  class: "d-flex align-items-center gap-2 mt-4 px-3 py-2 rounded",
                  style: { "background": "rgba(255,255,255,.07)", "color": "rgba(255,255,255,.55)", "font-size": ".8rem" }
                }, [
                  createVNode("i", {
                    class: "ti ti-shield-check",
                    style: { "color": "#00B4D8" }
                  }),
                  createTextVNode(" " + toDisplayString((_f = __props.t.panel_fp) == null ? void 0 : _f.security_note), 1)
                ])
              ])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b2;
          if (_push2) {
            if (statusMessage.value) {
              _push2(`<div class="alert alert-success mb-4 py-2"${_scopeId}><i class="ti ti-circle-check me-1"${_scopeId}></i> ${ssrInterpolate(statusMessage.value)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (unref(form).errors.email) {
              _push2(`<div class="alert alert-danger mb-4 py-2"${_scopeId}><i class="ti ti-alert-circle me-1"${_scopeId}></i> ${ssrInterpolate(unref(form).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<form novalidate${_scopeId}><div class="mb-4"${_scopeId}><label class="form-label"${_scopeId}>E-mail <span style="${ssrRenderStyle({ "color": "#ef4444" })}"${_scopeId}>*</span></label><div class="input-group"${_scopeId}><span class="input-group-text"${_scopeId}><i class="ti ti-mail"${_scopeId}></i></span><input${ssrRenderAttr("value", unref(form).email)} type="email" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}" autofocus autocomplete="username" required${_scopeId}></div></div><div class="mb-3"${_scopeId}><button type="submit" class="btn btn-primary w-100"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<i class="ti ti-loader-2 ee-spin me-1"${_scopeId}></i>`);
            } else {
              _push2(`<i class="ti ti-send me-1"${_scopeId}></i>`);
            }
            _push2(` ${ssrInterpolate((_a2 = __props.t.forgot_password) == null ? void 0 : _a2.send_link)}</button></div><div class="text-center"${_scopeId}><a href="/login" class="d-inline-flex align-items-center gap-1 text-muted" style="${ssrRenderStyle({ "font-size": ".875rem", "text-decoration": "none" })}"${_scopeId}><i class="ti ti-arrow-left"${_scopeId}></i> ${ssrInterpolate(__props.t.back_to_login)}</a></div></form>`);
          } else {
            return [
              statusMessage.value ? (openBlock(), createBlock("div", {
                key: 0,
                class: "alert alert-success mb-4 py-2"
              }, [
                createVNode("i", { class: "ti ti-circle-check me-1" }),
                createTextVNode(" " + toDisplayString(statusMessage.value), 1)
              ])) : createCommentVNode("", true),
              unref(form).errors.email ? (openBlock(), createBlock("div", {
                key: 1,
                class: "alert alert-danger mb-4 py-2"
              }, [
                createVNode("i", { class: "ti ti-alert-circle me-1" }),
                createTextVNode(" " + toDisplayString(unref(form).errors.email), 1)
              ])) : createCommentVNode("", true),
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"]),
                novalidate: ""
              }, [
                createVNode("div", { class: "mb-4" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode("E-mail "),
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
                  createVNode("button", {
                    type: "submit",
                    class: "btn btn-primary w-100",
                    disabled: unref(form).processing
                  }, [
                    unref(form).processing ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-loader-2 ee-spin me-1"
                    })) : (openBlock(), createBlock("i", {
                      key: 1,
                      class: "ti ti-send me-1"
                    })),
                    createTextVNode(" " + toDisplayString((_b2 = __props.t.forgot_password) == null ? void 0 : _b2.send_link), 1)
                  ], 8, ["disabled"])
                ]),
                createVNode("div", { class: "text-center" }, [
                  createVNode("a", {
                    href: "/login",
                    class: "d-inline-flex align-items-center gap-1 text-muted",
                    style: { "font-size": ".875rem", "text-decoration": "none" }
                  }, [
                    createVNode("i", { class: "ti ti-arrow-left" }),
                    createTextVNode(" " + toDisplayString(__props.t.back_to_login), 1)
                  ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Auth/ForgotPassword.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
