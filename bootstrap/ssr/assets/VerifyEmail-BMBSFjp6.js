import { computed, unref, withCtx, createVNode, toDisplayString, openBlock, createBlock, createTextVNode, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrIncludeBooleanAttr } from "vue/server-renderer";
import { usePage, useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./GuestLayout-C0jWrpRj.js";
const emailVerificationImg = "/build/assets/email-verification-illustration-img-y-T_SkGr.png";
const _sfc_main = {
  __name: "VerifyEmail",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const page = usePage();
    const verificationSent = computed(() => {
      var _a;
      return ((_a = page.props.flash) == null ? void 0 : _a.status) === "verification-link-sent";
    });
    const resendForm = useForm({});
    const logoutForm = useForm({});
    function resend() {
      resendForm.post("/email/verification-notification");
    }
    function logout() {
      logoutForm.post("/logout");
    }
    return (_ctx, _push, _parent, _attrs) => {
      var _a, _b;
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: (_a = __props.t.verify_email) == null ? void 0 : _a.title
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "app-name": __props.appName,
        title: (_b = __props.t.verify_email) == null ? void 0 : _b.title,
        subtitle: "Confirmação necessária",
        "illustration-src": unref(emailVerificationImg)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a2, _b2, _c, _d, _e, _f;
          if (_push2) {
            _push2(`<p class="text-muted mb-4" style="${ssrRenderStyle({ "font-size": ".9rem" })}"${_scopeId}>${ssrInterpolate((_a2 = __props.t.verify_email) == null ? void 0 : _a2.description)}</p>`);
            if (verificationSent.value) {
              _push2(`<div class="alert alert-success mb-4"${_scopeId}><i class="ti ti-circle-check me-1"${_scopeId}></i> ${ssrInterpolate((_b2 = __props.t.verify_email) == null ? void 0 : _b2.link_sent)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="d-grid mb-3"${_scopeId}><button type="button" class="btn btn-primary fw-semibold"${ssrIncludeBooleanAttr(unref(resendForm).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(resendForm).processing) {
              _push2(`<i class="ti ti-loader-2 ee-spin me-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate((_c = __props.t.verify_email) == null ? void 0 : _c.resend)}</button></div><div class="text-center"${_scopeId}><button type="button" class="btn btn-link text-muted" style="${ssrRenderStyle({ "font-size": ".875rem" })}"${ssrIncludeBooleanAttr(unref(logoutForm).processing) ? " disabled" : ""}${_scopeId}>${ssrInterpolate(__props.t.log_out)}</button></div>`);
          } else {
            return [
              createVNode("p", {
                class: "text-muted mb-4",
                style: { "font-size": ".9rem" }
              }, toDisplayString((_d = __props.t.verify_email) == null ? void 0 : _d.description), 1),
              verificationSent.value ? (openBlock(), createBlock("div", {
                key: 0,
                class: "alert alert-success mb-4"
              }, [
                createVNode("i", { class: "ti ti-circle-check me-1" }),
                createTextVNode(" " + toDisplayString((_e = __props.t.verify_email) == null ? void 0 : _e.link_sent), 1)
              ])) : createCommentVNode("", true),
              createVNode("div", { class: "d-grid mb-3" }, [
                createVNode("button", {
                  type: "button",
                  class: "btn btn-primary fw-semibold",
                  disabled: unref(resendForm).processing,
                  onClick: resend
                }, [
                  unref(resendForm).processing ? (openBlock(), createBlock("i", {
                    key: 0,
                    class: "ti ti-loader-2 ee-spin me-1"
                  })) : createCommentVNode("", true),
                  createTextVNode(" " + toDisplayString((_f = __props.t.verify_email) == null ? void 0 : _f.resend), 1)
                ], 8, ["disabled"])
              ]),
              createVNode("div", { class: "text-center" }, [
                createVNode("button", {
                  type: "button",
                  class: "btn btn-link text-muted",
                  style: { "font-size": ".875rem" },
                  disabled: unref(logoutForm).processing,
                  onClick: logout
                }, toDisplayString(__props.t.log_out), 9, ["disabled"])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Auth/VerifyEmail.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
