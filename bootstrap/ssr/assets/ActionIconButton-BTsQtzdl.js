import { computed, unref, mergeProps, withCtx, createVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderClass, ssrRenderAttrs } from "vue/server-renderer";
import { Link } from "@inertiajs/vue3";
const _sfc_main = {
  __name: "ActionIconButton",
  __ssrInlineRender: true,
  props: {
    icon: { type: String, required: true },
    title: { type: String, default: "" },
    variant: { type: String, default: "default" },
    // default | info | success | danger | warning | primary
    disabled: { type: Boolean, default: false },
    href: { type: String, default: null },
    // link nativo (não-SPA)
    target: { type: String, default: null },
    inertiaHref: { type: String, default: null },
    // Inertia Link
    inertiaMethod: { type: String, default: "get" },
    as: { type: String, default: null }
    // override: button | a | link
  },
  emits: ["click"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const tag = computed(() => {
      if (props.as) return props.as;
      if (props.inertiaHref) return "link";
      if (props.href) return "a";
      return "button";
    });
    const classes = computed(() => [
      "ee-action-icon",
      `ee-action-icon--${props.variant}`,
      props.disabled && "ee-action-icon--disabled"
    ]);
    function handleClick(event) {
      if (props.disabled) {
        event.preventDefault();
        event.stopPropagation();
        return;
      }
      emit("click", event);
    }
    return (_ctx, _push, _parent, _attrs) => {
      if (tag.value === "link") {
        _push(ssrRenderComponent(unref(Link), mergeProps({
          href: __props.inertiaHref,
          method: __props.inertiaMethod,
          class: classes.value,
          title: __props.title,
          "aria-label": __props.title,
          "aria-disabled": __props.disabled ? "true" : null,
          onClick: handleClick
        }, _attrs), {
          default: withCtx((_, _push2, _parent2, _scopeId) => {
            if (_push2) {
              _push2(`<i class="${ssrRenderClass(__props.icon)}" aria-hidden="true"${_scopeId}></i>`);
            } else {
              return [
                createVNode("i", {
                  class: __props.icon,
                  "aria-hidden": "true"
                }, null, 2)
              ];
            }
          }),
          _: 1
        }, _parent));
      } else if (tag.value === "a") {
        _push(`<a${ssrRenderAttrs(mergeProps({
          href: __props.disabled ? null : __props.href,
          target: __props.target,
          class: classes.value,
          title: __props.title,
          "aria-label": __props.title,
          "aria-disabled": __props.disabled ? "true" : null
        }, _attrs))}><i class="${ssrRenderClass(__props.icon)}" aria-hidden="true"></i></a>`);
      } else {
        _push(`<button${ssrRenderAttrs(mergeProps({
          type: "button",
          class: classes.value,
          title: __props.title,
          "aria-label": __props.title,
          disabled: __props.disabled
        }, _attrs))}><i class="${ssrRenderClass(__props.icon)}" aria-hidden="true"></i></button>`);
      }
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Components/Panel/ActionIconButton.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as _
};
