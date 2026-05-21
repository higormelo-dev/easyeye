import { ref, computed, unref, withCtx, createVNode, toDisplayString, openBlock, createBlock, createTextVNode, createCommentVNode, withModifiers, withDirectives, vModelText, vModelDynamic, vModelCheckbox, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderAttr, ssrInterpolate, ssrRenderClass, ssrRenderDynamicModel, ssrIncludeBooleanAttr, ssrLooseContain } from "vue/server-renderer";
import { useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1, l as logoSvg } from "./GuestLayout-C0jWrpRj.js";
import { l as logoWhiteSvg } from "./logo-white-hVd1h5De.js";
const illustrationImg = "/build/assets/log-illustration-img-01-D28dQ0M0.png";
const _sfc_main = {
  __name: "Login",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) },
    flash: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const form = useForm({
      email: "",
      password: "",
      remember: false
    });
    const showPassword = ref(false);
    function submit() {
      form.post("/login", {
        onFinish: () => form.reset("password")
      });
    }
    const statusMessage = computed(() => {
      var _a;
      return ((_a = props.flash) == null ? void 0 : _a.status) ?? null;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: __props.t.sign_in
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "layout-mode": "login-illustration",
        "app-name": __props.appName
      }, {
        "left-panel": withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b, _c, _d, _e, _f, _g, _h, _i, _j, _k, _l;
          if (_push2) {
            _push2(`<div class="ee-login-panel-wrapper"${_scopeId}><div class="ee-login-panel-blob ee-login-panel-blob-1"${_scopeId}></div><div class="ee-login-panel-blob ee-login-panel-blob-2"${_scopeId}></div><img${ssrRenderAttr("src", unref(logoWhiteSvg))}${ssrRenderAttr("alt", __props.appName)} class="ee-login-panel-logo"${_scopeId}><img${ssrRenderAttr("src", unref(illustrationImg))}${ssrRenderAttr("alt", __props.appName)} class="ee-login-panel-img"${_scopeId}><div class="ee-login-features"${_scopeId}><div class="ee-login-feature"${_scopeId}><div class="ee-login-feature-ico"${_scopeId}><i class="ti ti-calendar"${_scopeId}></i></div><span${_scopeId}>${ssrInterpolate((_a = __props.t.panel) == null ? void 0 : _a.feature_schedule)}</span></div><div class="ee-login-feature"${_scopeId}><div class="ee-login-feature-ico"${_scopeId}><i class="ti ti-file-medical"${_scopeId}></i></div><span${_scopeId}>${ssrInterpolate((_b = __props.t.panel) == null ? void 0 : _b.feature_record)}</span></div><div class="ee-login-feature"${_scopeId}><div class="ee-login-feature-ico"${_scopeId}><i class="ti ti-receipt"${_scopeId}></i></div><span${_scopeId}>${ssrInterpolate((_c = __props.t.panel) == null ? void 0 : _c.feature_tiss)}</span></div><div class="ee-login-feature"${_scopeId}><div class="ee-login-feature-ico"${_scopeId}><i class="ti ti-shield-check"${_scopeId}></i></div><span${_scopeId}>${ssrInterpolate((_d = __props.t.panel) == null ? void 0 : _d.feature_compliance)}</span></div></div><div class="ee-login-quote"${_scopeId}><p${_scopeId}>&quot;${ssrInterpolate((_e = __props.t.panel) == null ? void 0 : _e.quote_text)}&quot;</p><cite${_scopeId}>${ssrInterpolate((_f = __props.t.panel) == null ? void 0 : _f.quote_author)}</cite></div></div>`);
          } else {
            return [
              createVNode("div", { class: "ee-login-panel-wrapper" }, [
                createVNode("div", { class: "ee-login-panel-blob ee-login-panel-blob-1" }),
                createVNode("div", { class: "ee-login-panel-blob ee-login-panel-blob-2" }),
                createVNode("img", {
                  src: unref(logoWhiteSvg),
                  alt: __props.appName,
                  class: "ee-login-panel-logo"
                }, null, 8, ["src", "alt"]),
                createVNode("img", {
                  src: unref(illustrationImg),
                  alt: __props.appName,
                  class: "ee-login-panel-img"
                }, null, 8, ["src", "alt"]),
                createVNode("div", { class: "ee-login-features" }, [
                  createVNode("div", { class: "ee-login-feature" }, [
                    createVNode("div", { class: "ee-login-feature-ico" }, [
                      createVNode("i", { class: "ti ti-calendar" })
                    ]),
                    createVNode("span", null, toDisplayString((_g = __props.t.panel) == null ? void 0 : _g.feature_schedule), 1)
                  ]),
                  createVNode("div", { class: "ee-login-feature" }, [
                    createVNode("div", { class: "ee-login-feature-ico" }, [
                      createVNode("i", { class: "ti ti-file-medical" })
                    ]),
                    createVNode("span", null, toDisplayString((_h = __props.t.panel) == null ? void 0 : _h.feature_record), 1)
                  ]),
                  createVNode("div", { class: "ee-login-feature" }, [
                    createVNode("div", { class: "ee-login-feature-ico" }, [
                      createVNode("i", { class: "ti ti-receipt" })
                    ]),
                    createVNode("span", null, toDisplayString((_i = __props.t.panel) == null ? void 0 : _i.feature_tiss), 1)
                  ]),
                  createVNode("div", { class: "ee-login-feature" }, [
                    createVNode("div", { class: "ee-login-feature-ico" }, [
                      createVNode("i", { class: "ti ti-shield-check" })
                    ]),
                    createVNode("span", null, toDisplayString((_j = __props.t.panel) == null ? void 0 : _j.feature_compliance), 1)
                  ])
                ]),
                createVNode("div", { class: "ee-login-quote" }, [
                  createVNode("p", null, '"' + toDisplayString((_k = __props.t.panel) == null ? void 0 : _k.quote_text) + '"', 1),
                  createVNode("cite", null, toDisplayString((_l = __props.t.panel) == null ? void 0 : _l.quote_author), 1)
                ])
              ])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="text-center mb-4"${_scopeId}><a href="/login" class="d-inline-block"${_scopeId}><img${ssrRenderAttr("src", unref(logoSvg))} class="img-fluid ee-login-brand"${ssrRenderAttr("alt", __props.appName)}${_scopeId}></a></div><div class="card ee-login-card"${_scopeId}><div class="card-body ee-login-card-body ee-auth-form"${_scopeId}><div class="text-center mb-4"${_scopeId}><h2 class="ee-login-title"${_scopeId}>${ssrInterpolate(__props.t.sign_in)}</h2><p class="ee-login-subtitle"${_scopeId}>${ssrInterpolate(__props.t.login_subtitle)}</p></div>`);
            if (statusMessage.value) {
              _push2(`<div class="alert alert-success mb-3 py-2"${_scopeId}><i class="ti ti-circle-check me-1"${_scopeId}></i> ${ssrInterpolate(statusMessage.value)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (unref(form).errors.email) {
              _push2(`<div class="alert alert-danger mb-3 py-2"${_scopeId}><i class="ti ti-alert-circle me-1"${_scopeId}></i> ${ssrInterpolate(unref(form).errors.email)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<form novalidate${_scopeId}><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.email ?? "E-mail")}</label><div class="input-group"${_scopeId}><span class="input-group-text"${_scopeId}><i class="ti ti-mail"${_scopeId}></i></span><input${ssrRenderAttr("value", unref(form).email)} type="email" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.email }, "form-control"])}" autocomplete="username" autofocus required${_scopeId}></div></div><div class="mb-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.password ?? "Senha")}</label><div class="input-group"${_scopeId}><span class="input-group-text"${_scopeId}><i class="ti ti-lock"${_scopeId}></i></span><input${ssrRenderDynamicModel(showPassword.value ? "text" : "password", unref(form).password, null)}${ssrRenderAttr("type", showPassword.value ? "text" : "password")} class="${ssrRenderClass([{ "is-invalid": unref(form).errors.password }, "form-control border-end-0"])}" autocomplete="current-password" placeholder="••••••••••" required${_scopeId}><button type="button" class="btn btn-light" tabindex="-1"${_scopeId}><i class="${ssrRenderClass(showPassword.value ? "ti ti-eye-off" : "ti ti-eye")}"${_scopeId}></i></button></div>`);
            if (unref(form).errors.password) {
              _push2(`<div class="invalid-feedback d-block mt-1"${_scopeId}>${ssrInterpolate(unref(form).errors.password)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="d-flex align-items-center justify-content-between mb-3"${_scopeId}><div class="form-check mb-0"${_scopeId}><input${ssrIncludeBooleanAttr(Array.isArray(unref(form).remember) ? ssrLooseContain(unref(form).remember, null) : unref(form).remember) ? " checked" : ""} type="checkbox" id="remember" class="form-check-input"${_scopeId}><label for="remember" class="form-check-label text-dark"${_scopeId}>${ssrInterpolate(__props.t.remember_me)}</label></div><a href="/forgot-password" class="ee-login-forgot"${_scopeId}>${ssrInterpolate(__props.t.forget_password)}</a></div><div class="d-grid mb-3"${_scopeId}><button type="submit" class="btn btn-primary ee-login-submit"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<span${_scopeId}><i class="ti ti-loader-2 ee-spin me-1"${_scopeId}></i></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(__props.t.sign_in)}</button></div><p class="ee-login-register text-center mb-0"${_scopeId}>${ssrInterpolate(__props.t.no_account_yet)} <a href="/register"${_scopeId}>${ssrInterpolate(__props.t.sign_up)}</a></p><div class="ee-login-trust"${_scopeId}><div class="ee-login-trust-item"${_scopeId}><i class="ti ti-lock"${_scopeId}></i> SSL</div><div class="ee-login-trust-item"${_scopeId}><i class="ti ti-shield-check"${_scopeId}></i> LGPD</div><div class="ee-login-trust-item"${_scopeId}><i class="ti ti-certificate"${_scopeId}></i> CFM</div><div class="ee-login-trust-item"${_scopeId}><i class="ti ti-star"${_scopeId}></i> 97% NPS</div></div></form></div></div>`);
          } else {
            return [
              createVNode("div", { class: "text-center mb-4" }, [
                createVNode("a", {
                  href: "/login",
                  class: "d-inline-block"
                }, [
                  createVNode("img", {
                    src: unref(logoSvg),
                    class: "img-fluid ee-login-brand",
                    alt: __props.appName
                  }, null, 8, ["src", "alt"])
                ])
              ]),
              createVNode("div", { class: "card ee-login-card" }, [
                createVNode("div", { class: "card-body ee-login-card-body ee-auth-form" }, [
                  createVNode("div", { class: "text-center mb-4" }, [
                    createVNode("h2", { class: "ee-login-title" }, toDisplayString(__props.t.sign_in), 1),
                    createVNode("p", { class: "ee-login-subtitle" }, toDisplayString(__props.t.login_subtitle), 1)
                  ]),
                  statusMessage.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "alert alert-success mb-3 py-2"
                  }, [
                    createVNode("i", { class: "ti ti-circle-check me-1" }),
                    createTextVNode(" " + toDisplayString(statusMessage.value), 1)
                  ])) : createCommentVNode("", true),
                  unref(form).errors.email ? (openBlock(), createBlock("div", {
                    key: 1,
                    class: "alert alert-danger mb-3 py-2"
                  }, [
                    createVNode("i", { class: "ti ti-alert-circle me-1" }),
                    createTextVNode(" " + toDisplayString(unref(form).errors.email), 1)
                  ])) : createCommentVNode("", true),
                  createVNode("form", {
                    onSubmit: withModifiers(submit, ["prevent"]),
                    novalidate: ""
                  }, [
                    createVNode("div", { class: "mb-3" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.email ?? "E-mail"), 1),
                      createVNode("div", { class: "input-group" }, [
                        createVNode("span", { class: "input-group-text" }, [
                          createVNode("i", { class: "ti ti-mail" })
                        ]),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).email = $event,
                          type: "email",
                          class: ["form-control", { "is-invalid": unref(form).errors.email }],
                          autocomplete: "username",
                          autofocus: "",
                          required: ""
                        }, null, 10, ["onUpdate:modelValue"]), [
                          [vModelText, unref(form).email]
                        ])
                      ])
                    ]),
                    createVNode("div", { class: "mb-3" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.password ?? "Senha"), 1),
                      createVNode("div", { class: "input-group" }, [
                        createVNode("span", { class: "input-group-text" }, [
                          createVNode("i", { class: "ti ti-lock" })
                        ]),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).password = $event,
                          type: showPassword.value ? "text" : "password",
                          class: ["form-control border-end-0", { "is-invalid": unref(form).errors.password }],
                          autocomplete: "current-password",
                          placeholder: "••••••••••",
                          required: ""
                        }, null, 10, ["onUpdate:modelValue", "type"]), [
                          [vModelDynamic, unref(form).password]
                        ]),
                        createVNode("button", {
                          type: "button",
                          class: "btn btn-light",
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
                    createVNode("div", { class: "d-flex align-items-center justify-content-between mb-3" }, [
                      createVNode("div", { class: "form-check mb-0" }, [
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).remember = $event,
                          type: "checkbox",
                          id: "remember",
                          class: "form-check-input"
                        }, null, 8, ["onUpdate:modelValue"]), [
                          [vModelCheckbox, unref(form).remember]
                        ]),
                        createVNode("label", {
                          for: "remember",
                          class: "form-check-label text-dark"
                        }, toDisplayString(__props.t.remember_me), 1)
                      ]),
                      createVNode("a", {
                        href: "/forgot-password",
                        class: "ee-login-forgot"
                      }, toDisplayString(__props.t.forget_password), 1)
                    ]),
                    createVNode("div", { class: "d-grid mb-3" }, [
                      createVNode("button", {
                        type: "submit",
                        class: "btn btn-primary ee-login-submit",
                        disabled: unref(form).processing
                      }, [
                        unref(form).processing ? (openBlock(), createBlock("span", { key: 0 }, [
                          createVNode("i", { class: "ti ti-loader-2 ee-spin me-1" })
                        ])) : createCommentVNode("", true),
                        createTextVNode(" " + toDisplayString(__props.t.sign_in), 1)
                      ], 8, ["disabled"])
                    ]),
                    createVNode("p", { class: "ee-login-register text-center mb-0" }, [
                      createTextVNode(toDisplayString(__props.t.no_account_yet) + " ", 1),
                      createVNode("a", { href: "/register" }, toDisplayString(__props.t.sign_up), 1)
                    ]),
                    createVNode("div", { class: "ee-login-trust" }, [
                      createVNode("div", { class: "ee-login-trust-item" }, [
                        createVNode("i", { class: "ti ti-lock" }),
                        createTextVNode(" SSL")
                      ]),
                      createVNode("div", { class: "ee-login-trust-item" }, [
                        createVNode("i", { class: "ti ti-shield-check" }),
                        createTextVNode(" LGPD")
                      ]),
                      createVNode("div", { class: "ee-login-trust-item" }, [
                        createVNode("i", { class: "ti ti-certificate" }),
                        createTextVNode(" CFM")
                      ]),
                      createVNode("div", { class: "ee-login-trust-item" }, [
                        createVNode("i", { class: "ti ti-star" }),
                        createTextVNode(" 97% NPS")
                      ])
                    ])
                  ], 32)
                ])
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Auth/Login.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
