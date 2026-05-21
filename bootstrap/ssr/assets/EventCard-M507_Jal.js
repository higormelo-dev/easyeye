import { mergeProps, useSSRContext } from "vue";
import { ssrRenderAttrs, ssrRenderStyle, ssrInterpolate } from "vue/server-renderer";
const _sfc_main = {
  __name: "EventCard",
  __ssrInlineRender: true,
  props: {
    item: { type: Object, required: true },
    t: { type: Object, required: true }
  },
  setup(__props) {
    const typeKeyMap = {
      meeting: "event_meeting",
      maintenance: "event_maintenance",
      personal: "event_personal",
      training: "event_training",
      other: "event_other"
    };
    return (_ctx, _push, _parent, _attrs) => {
      _push(`<div${ssrRenderAttrs(mergeProps({
        class: "card mb-2 border-0 shadow-sm",
        style: { borderLeft: `4px solid ${__props.item.color} !important`, background: "#f8f9fa" }
      }, _attrs))}><div class="card-body py-2 px-3"><div class="d-flex align-items-center gap-3"><div class="text-center flex-shrink-0" style="${ssrRenderStyle({ "min-width": "48px" })}"><div class="fw-bold" style="${ssrRenderStyle({ fontSize: ".9rem", color: __props.item.color })}">${ssrInterpolate(__props.item.starts_at_fmt)}</div><div class="text-muted" style="${ssrRenderStyle({ "font-size": ".7rem" })}">${ssrInterpolate(__props.item.ends_at_fmt)}</div></div><div class="flex-shrink-0 text-center" style="${ssrRenderStyle({ "width": "36px" })}"><i class="fas fa-calendar-check fa-lg" style="${ssrRenderStyle({ color: __props.item.color, opacity: ".7" })}"></i></div><div class="flex-grow-1 min-w-0"><div class="d-flex align-items-center gap-2 flex-wrap"><span class="fw-semibold text-truncate" style="${ssrRenderStyle({ "font-size": ".9rem" })}">${ssrInterpolate(__props.item.title)}</span><span class="badge rounded-pill" style="${ssrRenderStyle({ background: __props.item.color, fontSize: ".68rem", opacity: ".85" })}">${ssrInterpolate(__props.t[typeKeyMap[__props.item.event_type]] ?? __props.item.event_type)}</span></div>`);
      if (__props.item.doctor_name) {
        _push(`<div class="text-muted" style="${ssrRenderStyle({ "font-size": ".78rem" })}"><i class="fas fa-user-md me-1"></i>${ssrInterpolate(__props.item.doctor_name)}</div>`);
      } else {
        _push(`<!---->`);
      }
      if (__props.item.notes) {
        _push(`<div class="text-muted fst-italic text-truncate" style="${ssrRenderStyle({ "font-size": ".75rem" })}">${ssrInterpolate(__props.item.notes)}</div>`);
      } else {
        _push(`<!---->`);
      }
      _push(`</div><div class="flex-shrink-0"><span class="badge bg-secondary" style="${ssrRenderStyle({ "font-size": ".68rem" })}">${ssrInterpolate(__props.t.event_badge)}</span></div></div></div></div>`);
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Schedules/EventCard.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
