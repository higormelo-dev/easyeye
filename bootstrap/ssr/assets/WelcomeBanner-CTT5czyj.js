import { computed, mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrInterpolate, ssrRenderStyle, ssrRenderAttr } from "vue/server-renderer";
import { usePage } from "@inertiajs/vue3";
const _sfc_main = {
  __name: "WelcomeBanner",
  __ssrInlineRender: true,
  props: {
    t: { type: Object, required: true }
  },
  setup(__props) {
    const props = __props;
    const page = usePage();
    const auth = computed(() => page.props.auth ?? {});
    const user = computed(() => auth.value.user ?? {});
    const entity = computed(() => auth.value.entity ?? {});
    const firstName = computed(() => {
      var _a;
      return ((_a = user.value.name) == null ? void 0 : _a.split(" ")[0]) ?? "";
    });
    const hour = (/* @__PURE__ */ new Date()).getHours();
    const gKey = hour < 12 ? "greeting_morning" : hour < 18 ? "greeting_afternoon" : "greeting_evening";
    const greeting = computed(() => props.t[gKey] ?? "Olá");
    return (_ctx, _push, _parent, _attrs) => {
      var _a;
      _push(`<div${ssrRenderAttrs(mergeProps({ class: "welcome-banner mb-4 mt-4" }, _attrs))}><div class="d-flex align-items-center justify-content-between flex-wrap gap-3"><div><h4 class="mb-1">${ssrInterpolate(greeting.value)}, ${ssrInterpolate(firstName.value)}! 👋</h4><p>${ssrInterpolate((_a = __props.t.operational_panel) == null ? void 0 : _a.replace(":app", entity.value.name))}</p></div><div class="d-flex gap-2 flex-wrap" style="${ssrRenderStyle({ "position": "relative", "z-index": "1" })}"><a${ssrRenderAttr("href", _ctx.route("panel.patients.index"))} class="btn btn-sm btn-banner"><i class="ti ti-users me-1"></i> ${ssrInterpolate(__props.t.btn_patients)}</a><a${ssrRenderAttr("href", _ctx.route("panel.patients.index"))} class="btn btn-sm btn-banner btn-banner-solid"><i class="ti ti-user-plus me-1"></i> ${ssrInterpolate(__props.t.btn_new_patient)}</a></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Dashboard/WelcomeBanner.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
