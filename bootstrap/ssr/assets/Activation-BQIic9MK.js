import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderClass, ssrInterpolate, ssrRenderStyle, ssrRenderList } from "vue/server-renderer";
const _sfc_main = {
  __name: "Activation",
  __ssrInlineRender: true,
  props: {
    activation: { type: Array, required: true },
    activationScore: { type: Number, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const color = computed(() => {
      const s = props.activationScore;
      if (s >= 80) return "success";
      if (s >= 50) return "info";
      if (s >= 30) return "warning";
      return "danger";
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "card border-0 shadow-sm mb-4" }, _attrs))}><div class="card-body p-4"><div class="d-flex align-items-center justify-content-between mb-3"><div><h6 class="mb-0 fw-semibold"><i class="${ssrRenderClass(`fas fa-rocket text-${color.value} me-2`)}"></i> ${ssrInterpolate(__props.t.activation_title)}</h6><p class="text-muted small mb-0">${ssrInterpolate(__props.t.activation_subtitle)}</p></div><div class="text-end"><span class="${ssrRenderClass(`fs-4 fw-bold text-${color.value}`)}">${ssrInterpolate(__props.activationScore)}%</span><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(__props.t.activation_done)}</div></div></div><div class="progress mb-3" style="${ssrRenderStyle({ "height": "8px", "border-radius": "4px" })}"><div class="${ssrRenderClass([`bg-${color.value}`, "progress-bar"])}" style="${ssrRenderStyle(`width:${__props.activationScore}%`)}"></div></div><div class="d-flex align-items-center gap-2 flex-wrap"><!--[-->`);
      ssrRenderList(__props.activation, (step) => {
        _push(`<div class="${ssrRenderClass([step.done ? "border-success bg-success bg-opacity-10" : "bg-white", "d-flex align-items-center gap-1 px-3 py-2 rounded border flex-shrink-0"])}"><i class="${ssrRenderClass([step.done ? "ti ti-circle-check text-success" : "ti ti-circle text-muted", "flex-shrink-0 fs-16"])}"></i><span class="${ssrRenderClass([step.done ? "text-success fw-medium" : "text-muted", "small text-nowrap"])}">${ssrInterpolate(step.label)}</span>`);
        if (!step.done) {
          _push(`<span class="fw-semibold small" style="${ssrRenderStyle({ "color": "#0d6efd" })}">+${ssrInterpolate(step.weight)}%</span>`);
        } else {
          _push(`<!---->`);
        }
        _push(`</div>`);
      });
      _push(`<!--]--></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard/Activation.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
