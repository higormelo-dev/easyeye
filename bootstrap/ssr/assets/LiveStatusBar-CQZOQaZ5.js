import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle, ssrIncludeBooleanAttr, ssrRenderClass } from "vue/server-renderer";
const _sfc_main = {
  __name: "LiveStatusBar",
  __ssrInlineRender: true,
  props: {
    isRefreshing: { type: Boolean, default: false },
    lastUpdated: { type: Date, default: () => /* @__PURE__ */ new Date() },
    t: { type: Object, required: true }
  },
  emits: ["refresh"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const lastUpdatedTime = computed(() => {
      const locale = window.sessionLocale ?? "pt-BR";
      return props.lastUpdated.toLocaleTimeString(locale, {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
      });
    });
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "db-live-bar d-flex align-items-center gap-2 mb-3" }, _attrs))}><span class="badge db-live-badge d-flex align-items-center gap-1"><span class="db-live-dot"></span> ${ssrInterpolate(__props.t.live_label)}</span><span class="text-muted" style="${ssrRenderStyle({ "font-size": ".78rem" })}">`);
      if (__props.isRefreshing) {
        _push(`<!--[--><i class="ti ti-loader-2 db-spin me-1"></i> ${ssrInterpolate(__props.t.live_refreshing)}<!--]-->`);
      } else {
        _push(`<!--[-->${ssrInterpolate(__props.t.last_updated_at)} ${ssrInterpolate(lastUpdatedTime.value)}<!--]-->`);
      }
      _push(`</span><button class="btn btn-xs btn-outline-secondary ms-auto"${ssrIncludeBooleanAttr(__props.isRefreshing) ? " disabled" : ""}><i class="${ssrRenderClass([{ "db-spin": __props.isRefreshing }, "ti ti-refresh me-1"])}"></i> ${ssrInterpolate(__props.t.btn_refresh)}</button></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/LiveStatusBar.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
