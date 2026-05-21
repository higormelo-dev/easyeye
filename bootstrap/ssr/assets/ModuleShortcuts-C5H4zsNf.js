import { computed, mergeProps, createVNode, resolveDynamicComponent, withCtx, openBlock, createBlock, toDisplayString, createCommentVNode, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderList, ssrRenderVNode, ssrInterpolate, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "ModuleShortcuts",
  __ssrInlineRender: true,
  props: {
    rule: { type: String, default: "" },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const isAdminOrFinancial = computed(
      () => ["admin", "financial"].includes(props.rule)
    );
    const modules = computed(() => {
      const all = [
        {
          key: "schedule",
          label: props.t.module_schedule,
          icon: "ti ti-calendar",
          iconClass: "module-icon--schedule",
          url: route("panel.schedules.index"),
          soon: false
        },
        {
          key: "eye-images",
          label: props.t.module_eye_images,
          icon: "ti ti-eye",
          iconClass: "module-icon",
          url: route("panel.eye-images.index"),
          soon: false
        },
        ...isAdminOrFinancial.value ? [
          {
            key: "tiss",
            label: props.t.module_tiss,
            icon: "ti ti-file-invoice",
            iconClass: "module-icon--tiss",
            url: route("panel.financial.billing.index"),
            soon: false
          },
          {
            key: "financial",
            label: props.t.module_financial,
            icon: "ti ti-report-money",
            iconClass: "module-icon--financial",
            url: route("panel.financial.cash-flow.index"),
            soon: false
          }
        ] : [],
        {
          key: "surgery",
          label: props.t.module_surgery,
          icon: "ti ti-stethoscope",
          iconClass: "module-icon--soon",
          url: null,
          soon: true
        }
      ];
      return all;
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "row g-3 mb-4" }, _attrs))}><!--[-->`);
      ssrRenderList(modules.value, (mod) => {
        _push(`<div class="col-6 col-sm-4 col-md-2">`);
        ssrRenderVNode(_push, createVNode(resolveDynamicComponent(mod.soon ? "div" : "a"), {
          href: mod.soon ? void 0 : mod.url,
          class: ["module-shortcut w-100", mod.soon ? "disabled" : ""]
        }, {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              if (mod.soon) {
                _push2(`<span class="badge-soon"${_scopeId}>${ssrInterpolate(__props.t.coming_soon)}</span>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<span class="${ssrRenderClass(`ms-icon ${mod.iconClass}`)}"${_scopeId}><i class="${ssrRenderClass(mod.icon)}"${_scopeId}></i></span><span${_scopeId}>${ssrInterpolate(mod.label)}</span>`);
            } else {
              return [
                mod.soon ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "badge-soon"
                }, toDisplayString(__props.t.coming_soon), 1)) : createCommentVNode("", true),
                createVNode("span", {
                  class: `ms-icon ${mod.iconClass}`
                }, [
                  createVNode("i", {
                    class: mod.icon
                  }, null, 2)
                ], 2),
                createVNode("span", null, toDisplayString(mod.label), 1)
              ];
            }
          }),
          _: 2
        }), _parent);
        _push(`</div>`);
      });
      _push(`<!--]--></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard/ModuleShortcuts.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
