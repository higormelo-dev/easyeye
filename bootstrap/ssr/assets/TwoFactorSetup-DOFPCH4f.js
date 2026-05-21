import { ref, computed, unref, withCtx, openBlock, createBlock, Fragment, createVNode, createTextVNode, toDisplayString, createCommentVNode, withModifiers, withDirectives, vModelText, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrRenderAttr, ssrIncludeBooleanAttr, ssrRenderList } from "vue/server-renderer";
import { Head, router } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./GuestLayout-C0jWrpRj.js";
import { t as twostepIllustrationImg } from "./twostep-verification-illustration-img-BDktwzgC.js";
const _sfc_main = {
  __name: "TwoFactorSetup",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    secret: { type: String, required: true },
    qr_svg: { type: String, required: true },
    otpauth: { type: String, required: true },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const props = __props;
    const step = ref("setup");
    const recoveryCodes = ref([]);
    const showManual = ref(false);
    const code = ref("");
    const error = ref("");
    const submitting = ref(false);
    async function confirm() {
      var _a;
      error.value = "";
      submitting.value = true;
      try {
        const res = await fetch(route("security.two-factor.confirm"), {
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
        recoveryCodes.value = json.recovery_codes ?? [];
        step.value = "recovery";
      } catch {
        error.value = "Erro de rede. Tente novamente.";
      } finally {
        submitting.value = false;
      }
    }
    function regenerate() {
      router.post(route("security.two-factor.setup.store"));
    }
    function copyRecoveryCodes() {
      var _a;
      const text = recoveryCodes.value.join("\n");
      (_a = navigator.clipboard) == null ? void 0 : _a.writeText(text);
      if (window.showSuccessToast) window.showSuccessToast(props.t.copied ?? "Códigos copiados.");
    }
    function downloadRecoveryCodes() {
      const text = recoveryCodes.value.join("\n");
      const blob = new Blob([text], { type: "text/plain" });
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "easyeye-recovery-codes.txt";
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      URL.revokeObjectURL(url);
    }
    function done() {
      window.location.href = "/";
    }
    const formattedSecret = computed(() => {
      var _a;
      return ((_a = props.secret.match(/.{1,4}/g)) == null ? void 0 : _a.join(" ")) ?? props.secret;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: __props.t.setup_title
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "layout-mode": "illustration",
        "app-name": props.appName,
        title: step.value === "setup" ? __props.t.setup_title : __props.t.recovery_title,
        subtitle: step.value === "setup" ? __props.t.setup_intro : __props.t.recovery_intro,
        "illustration-src": unref(twostepIllustrationImg)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (step.value === "setup") {
              _push2(`<!--[--><div class="mb-3"${_scopeId}><p class="fw-medium small mb-2"${_scopeId}><i class="ti ti-qrcode me-1 text-primary"${_scopeId}></i>${ssrInterpolate(__props.t.setup_step_1)}</p><div class="d-flex justify-content-center p-3 border rounded bg-white"${_scopeId}>${__props.qr_svg ?? ""}</div></div><div class="mb-3"${_scopeId}><button type="button" class="btn btn-link btn-sm p-0 text-decoration-none"${_scopeId}><i class="${ssrRenderClass(`ti me-1 ${showManual.value ? "ti-chevron-up" : "ti-chevron-down"}`)}"${_scopeId}></i> ${ssrInterpolate(__props.t.manual_secret)}</button>`);
              if (showManual.value) {
                _push2(`<div class="mt-2 p-2 bg-light border rounded font-monospace small text-break"${_scopeId}>${ssrInterpolate(formattedSecret.value)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`</div><p class="fw-medium small mb-2"${_scopeId}><i class="ti ti-keyboard me-1 text-primary"${_scopeId}></i>${ssrInterpolate(__props.t.setup_step_2)}</p><form${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(__props.t.code_label)}</label><input${ssrRenderAttr("value", code.value)} type="text" inputmode="numeric" autocomplete="one-time-code" class="${ssrRenderClass([{ "is-invalid": error.value }, "form-control text-center fw-bold fs-4 font-monospace"])}"${ssrRenderAttr("placeholder", __props.t.code_placeholder)} maxlength="7" autofocus${ssrIncludeBooleanAttr(submitting.value) ? " disabled" : ""}${_scopeId}>`);
              if (error.value) {
                _push2(`<div class="invalid-feedback d-block"${_scopeId}>${ssrInterpolate(error.value)}</div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="d-flex justify-content-between mt-4"${_scopeId}><button type="button" class="btn btn-link btn-sm"${ssrIncludeBooleanAttr(submitting.value) ? " disabled" : ""}${_scopeId}><i class="ti ti-refresh me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_regenerate)}</button><button type="submit" class="btn btn-primary"${ssrIncludeBooleanAttr(submitting.value || !code.value) ? " disabled" : ""}${_scopeId}>`);
              if (submitting.value) {
                _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
              } else {
                _push2(`<i class="ti ti-check me-1"${_scopeId}></i>`);
              }
              _push2(` ${ssrInterpolate(__props.t.btn_confirm)}</button></div></form><!--]-->`);
            } else if (step.value === "recovery") {
              _push2(`<!--[--><div class="alert alert-warning small d-flex align-items-start mb-3"${_scopeId}><i class="ti ti-alert-triangle me-2 fs-5 mt-1"${_scopeId}></i><span${_scopeId}>${ssrInterpolate(__props.t.recovery_warning)}</span></div><div class="bg-light border rounded p-3 mb-3 font-monospace"${_scopeId}><ul class="list-unstyled mb-0"${_scopeId}><!--[-->`);
              ssrRenderList(recoveryCodes.value, (rc) => {
                _push2(`<li class="mb-1"${_scopeId}>${ssrInterpolate(rc)}</li>`);
              });
              _push2(`<!--]--></ul></div><div class="d-flex gap-2 mb-3"${_scopeId}><button type="button" class="btn btn-outline-secondary btn-sm"${_scopeId}><i class="ti ti-copy me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_copy)}</button><button type="button" class="btn btn-outline-secondary btn-sm"${_scopeId}><i class="ti ti-download me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_download)}</button></div><button type="button" class="btn btn-primary w-100"${_scopeId}><i class="ti ti-check me-1"${_scopeId}></i>${ssrInterpolate(__props.t.btn_done)}</button><!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              step.value === "setup" ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "mb-3" }, [
                  createVNode("p", { class: "fw-medium small mb-2" }, [
                    createVNode("i", { class: "ti ti-qrcode me-1 text-primary" }),
                    createTextVNode(toDisplayString(__props.t.setup_step_1), 1)
                  ]),
                  createVNode("div", {
                    class: "d-flex justify-content-center p-3 border rounded bg-white",
                    innerHTML: __props.qr_svg
                  }, null, 8, ["innerHTML"])
                ]),
                createVNode("div", { class: "mb-3" }, [
                  createVNode("button", {
                    type: "button",
                    class: "btn btn-link btn-sm p-0 text-decoration-none",
                    onClick: ($event) => showManual.value = !showManual.value
                  }, [
                    createVNode("i", {
                      class: `ti me-1 ${showManual.value ? "ti-chevron-up" : "ti-chevron-down"}`
                    }, null, 2),
                    createTextVNode(" " + toDisplayString(__props.t.manual_secret), 1)
                  ], 8, ["onClick"]),
                  showManual.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "mt-2 p-2 bg-light border rounded font-monospace small text-break"
                  }, toDisplayString(formattedSecret.value), 1)) : createCommentVNode("", true)
                ]),
                createVNode("p", { class: "fw-medium small mb-2" }, [
                  createVNode("i", { class: "ti ti-keyboard me-1 text-primary" }),
                  createTextVNode(toDisplayString(__props.t.setup_step_2), 1)
                ]),
                createVNode("form", {
                  onSubmit: withModifiers(confirm, ["prevent"])
                }, [
                  createVNode("label", { class: "form-label small" }, toDisplayString(__props.t.code_label), 1),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => code.value = $event,
                    type: "text",
                    inputmode: "numeric",
                    autocomplete: "one-time-code",
                    class: ["form-control text-center fw-bold fs-4 font-monospace", { "is-invalid": error.value }],
                    placeholder: __props.t.code_placeholder,
                    maxlength: "7",
                    autofocus: "",
                    disabled: submitting.value
                  }, null, 10, ["onUpdate:modelValue", "placeholder", "disabled"]), [
                    [vModelText, code.value]
                  ]),
                  error.value ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback d-block"
                  }, toDisplayString(error.value), 1)) : createCommentVNode("", true),
                  createVNode("div", { class: "d-flex justify-content-between mt-4" }, [
                    createVNode("button", {
                      type: "button",
                      class: "btn btn-link btn-sm",
                      disabled: submitting.value,
                      onClick: regenerate
                    }, [
                      createVNode("i", { class: "ti ti-refresh me-1" }),
                      createTextVNode(toDisplayString(__props.t.btn_regenerate), 1)
                    ], 8, ["disabled"]),
                    createVNode("button", {
                      type: "submit",
                      class: "btn btn-primary",
                      disabled: submitting.value || !code.value
                    }, [
                      submitting.value ? (openBlock(), createBlock("span", {
                        key: 0,
                        class: "spinner-border spinner-border-sm me-1"
                      })) : (openBlock(), createBlock("i", {
                        key: 1,
                        class: "ti ti-check me-1"
                      })),
                      createTextVNode(" " + toDisplayString(__props.t.btn_confirm), 1)
                    ], 8, ["disabled"])
                  ])
                ], 32)
              ], 64)) : step.value === "recovery" ? (openBlock(), createBlock(Fragment, { key: 1 }, [
                createVNode("div", { class: "alert alert-warning small d-flex align-items-start mb-3" }, [
                  createVNode("i", { class: "ti ti-alert-triangle me-2 fs-5 mt-1" }),
                  createVNode("span", null, toDisplayString(__props.t.recovery_warning), 1)
                ]),
                createVNode("div", { class: "bg-light border rounded p-3 mb-3 font-monospace" }, [
                  createVNode("ul", { class: "list-unstyled mb-0" }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(recoveryCodes.value, (rc) => {
                      return openBlock(), createBlock("li", {
                        key: rc,
                        class: "mb-1"
                      }, toDisplayString(rc), 1);
                    }), 128))
                  ])
                ]),
                createVNode("div", { class: "d-flex gap-2 mb-3" }, [
                  createVNode("button", {
                    type: "button",
                    class: "btn btn-outline-secondary btn-sm",
                    onClick: copyRecoveryCodes
                  }, [
                    createVNode("i", { class: "ti ti-copy me-1" }),
                    createTextVNode(toDisplayString(__props.t.btn_copy), 1)
                  ]),
                  createVNode("button", {
                    type: "button",
                    class: "btn btn-outline-secondary btn-sm",
                    onClick: downloadRecoveryCodes
                  }, [
                    createVNode("i", { class: "ti ti-download me-1" }),
                    createTextVNode(toDisplayString(__props.t.btn_download), 1)
                  ])
                ]),
                createVNode("button", {
                  type: "button",
                  class: "btn btn-primary w-100",
                  onClick: done
                }, [
                  createVNode("i", { class: "ti ti-check me-1" }),
                  createTextVNode(toDisplayString(__props.t.btn_done), 1)
                ])
              ], 64)) : createCommentVNode("", true)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Security/TwoFactorSetup.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
