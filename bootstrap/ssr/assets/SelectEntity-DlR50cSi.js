import { unref, withCtx, openBlock, createBlock, toDisplayString, createCommentVNode, createVNode, withModifiers, createTextVNode, withDirectives, Fragment, renderList, vModelSelect, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderStyle, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr } from "vue/server-renderer";
import { useForm, Head } from "@inertiajs/vue3";
import { _ as _sfc_main$1 } from "./GuestLayout-C0jWrpRj.js";
import { t as twostepIllustrationImg } from "./twostep-verification-illustration-img-BDktwzgC.js";
const _sfc_main = {
  __name: "SelectEntity",
  __ssrInlineRender: true,
  props: {
    appName: { type: String, default: "EasyEye" },
    t: { type: Object, default: () => ({}) },
    entities: { type: Object, default: () => ({}) }
  },
  setup(__props) {
    const form = useForm({ entity_user_id: "" });
    const logoutForm = useForm({});
    function submit() {
      form.post("/select-entity");
    }
    function logout() {
      logoutForm.post("/logout");
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<!--[-->`);
      _push(ssrRenderComponent(unref(Head), {
        title: __props.t.select_entity
      }, null, _parent));
      _push(ssrRenderComponent(_sfc_main$1, {
        "app-name": __props.appName,
        title: __props.t.select_entity,
        subtitle: "Selecione a clínica para continuar",
        "illustration-src": unref(twostepIllustrationImg)
      }, {
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (unref(form).errors.entity_user_id) {
              _push2(`<div class="alert alert-danger mb-4"${_scopeId}>${ssrInterpolate(unref(form).errors.entity_user_id)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<form novalidate${_scopeId}><div class="mb-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.select_entity)} <span style="${ssrRenderStyle({ "color": "#ef4444" })}"${_scopeId}>*</span></label><select class="${ssrRenderClass([{ "is-invalid": unref(form).errors.entity_user_id }, "form-select"])}" required${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(unref(form).entity_user_id) ? ssrLooseContain(unref(form).entity_user_id, "") : ssrLooseEqual(unref(form).entity_user_id, "")) ? " selected" : ""}${_scopeId}>Selecione...</option><!--[-->`);
            ssrRenderList(__props.entities, (name, id) => {
              _push2(`<option${ssrRenderAttr("value", id)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).entity_user_id) ? ssrLooseContain(unref(form).entity_user_id, id) : ssrLooseEqual(unref(form).entity_user_id, id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(name)}</option>`);
            });
            _push2(`<!--]--></select></div><div class="d-grid mb-3"${_scopeId}><button type="submit" class="btn btn-primary fw-semibold"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<i class="ti ti-loader-2 ee-spin me-1"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` Selecionar </button></div></form><div class="text-center"${_scopeId}><button type="button" class="btn btn-link text-muted" style="${ssrRenderStyle({ "font-size": ".875rem" })}"${ssrIncludeBooleanAttr(unref(logoutForm).processing) ? " disabled" : ""}${_scopeId}>${ssrInterpolate(__props.t.log_out)}</button></div>`);
          } else {
            return [
              unref(form).errors.entity_user_id ? (openBlock(), createBlock("div", {
                key: 0,
                class: "alert alert-danger mb-4"
              }, toDisplayString(unref(form).errors.entity_user_id), 1)) : createCommentVNode("", true),
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"]),
                novalidate: ""
              }, [
                createVNode("div", { class: "mb-4" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString(__props.t.select_entity) + " ", 1),
                    createVNode("span", { style: { "color": "#ef4444" } }, "*")
                  ]),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => unref(form).entity_user_id = $event,
                    class: ["form-select", { "is-invalid": unref(form).errors.entity_user_id }],
                    required: ""
                  }, [
                    createVNode("option", { value: "" }, "Selecione..."),
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.entities, (name, id) => {
                      return openBlock(), createBlock("option", {
                        key: id,
                        value: id
                      }, toDisplayString(name), 9, ["value"]);
                    }), 128))
                  ], 10, ["onUpdate:modelValue"]), [
                    [vModelSelect, unref(form).entity_user_id]
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
                    createTextVNode(" Selecionar ")
                  ], 8, ["disabled"])
                ])
              ], 32),
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Auth/SelectEntity.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
