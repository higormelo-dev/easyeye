import { ref, watch, mergeProps, withCtx, openBlock, createBlock, Fragment, createVNode, createTextVNode, toDisplayString, createCommentVNode, renderList, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderList, ssrRenderClass } from "vue/server-renderer";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import { _ as _export_sfc } from "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "PlanDetailDrawer",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    planId: { type: String, default: null },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "edit"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const loading = ref(false);
    const plan = ref(null);
    async function loadDetail(id) {
      loading.value = true;
      plan.value = null;
      try {
        const res = await fetch(route("manager.plans.show", id));
        const json = await res.json();
        plan.value = json.data;
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val && props.planId) loadDetail(props.planId);
      if (!val) plan.value = null;
    });
    function featureValueLabel(feature) {
      if (feature.is_boolean) {
        const ok = feature.value === "1" || feature.value === 1;
        return ok ? props.t.feature_included ?? "Incluído" : props.t.feature_not_included ?? "Não incluído";
      }
      const v = parseInt(feature.value);
      return v === 0 ? props.t.feature_unlimited ?? "Ilimitado" : String(v);
    }
    function featureBadgeClass(feature) {
      if (feature.is_boolean) {
        return feature.value === "1" || feature.value === 1 ? "bg-success" : "bg-secondary";
      }
      return parseInt(feature.value) === 0 ? "bg-info text-dark" : "bg-secondary";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 440,
        loading: loading.value,
        "loading-label": __props.t.loading,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          var _a, _b;
          if (_push2) {
            _push2(`<div data-v-f4a863d7${_scopeId}><h5 class="mb-0 fw-semibold" data-v-f4a863d7${_scopeId}><i class="ti ti-box me-2 text-info" data-v-f4a863d7${_scopeId}></i> ${ssrInterpolate(((_a = plan.value) == null ? void 0 : _a.name) ?? __props.t.loading)}</h5>`);
            if (plan.value) {
              _push2(`<div class="mt-1 d-flex gap-2 align-items-center" data-v-f4a863d7${_scopeId}>`);
              if (plan.value.active) {
                _push2(`<span class="badge bg-success" data-v-f4a863d7${_scopeId}>${ssrInterpolate(__props.t.status_active)}</span>`);
              } else {
                _push2(`<span class="badge bg-secondary" data-v-f4a863d7${_scopeId}>${ssrInterpolate(__props.t.status_inactive)}</span>`);
              }
              _push2(`<span class="badge badge-soft-info rounded fs-12" data-v-f4a863d7${_scopeId}>${ssrInterpolate(plan.value.billing_label)}</span></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (plan.value) {
              _push2(`<button class="btn btn-sm btn-outline-primary ms-2" data-v-f4a863d7${_scopeId}><i class="ti ti-edit me-1" data-v-f4a863d7${_scopeId}></i> ${ssrInterpolate(__props.t.detail_btn_edit)}</button>`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-box me-2 text-info" }),
                  createTextVNode(" " + toDisplayString(((_b = plan.value) == null ? void 0 : _b.name) ?? __props.t.loading), 1)
                ]),
                plan.value ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "mt-1 d-flex gap-2 align-items-center"
                }, [
                  plan.value.active ? (openBlock(), createBlock("span", {
                    key: 0,
                    class: "badge bg-success"
                  }, toDisplayString(__props.t.status_active), 1)) : (openBlock(), createBlock("span", {
                    key: 1,
                    class: "badge bg-secondary"
                  }, toDisplayString(__props.t.status_inactive), 1)),
                  createVNode("span", { class: "badge badge-soft-info rounded fs-12" }, toDisplayString(plan.value.billing_label), 1)
                ])) : createCommentVNode("", true)
              ]),
              plan.value ? (openBlock(), createBlock("button", {
                key: 0,
                class: "btn btn-sm btn-outline-primary ms-2",
                onClick: ($event) => _ctx.$emit("edit", plan.value.id)
              }, [
                createVNode("i", { class: "ti ti-edit me-1" }),
                createTextVNode(" " + toDisplayString(__props.t.detail_btn_edit), 1)
              ], 8, ["onClick"])) : createCommentVNode("", true)
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            if (plan.value) {
              _push2(`<!--[--><div class="pdd-section" data-v-f4a863d7${_scopeId}><div class="pdd-section__title" data-v-f4a863d7${_scopeId}><i class="ti ti-currency-dollar me-1" data-v-f4a863d7${_scopeId}></i> ${ssrInterpolate(__props.t.section_pricing)}</div><div class="pdd-table" data-v-f4a863d7${_scopeId}><div class="pdd-row" data-v-f4a863d7${_scopeId}><span class="pdd-label" data-v-f4a863d7${_scopeId}>${ssrInterpolate(__props.t.detail_price)}</span><span class="pdd-value fw-semibold fs-5 text-body" data-v-f4a863d7${_scopeId}>${ssrInterpolate(plan.value.price_formatted)}</span></div><div class="pdd-row" data-v-f4a863d7${_scopeId}><span class="pdd-label" data-v-f4a863d7${_scopeId}>${ssrInterpolate(__props.t.detail_cycle)}</span><span class="pdd-value" data-v-f4a863d7${_scopeId}>${ssrInterpolate(plan.value.billing_label)}</span></div><div class="pdd-row" data-v-f4a863d7${_scopeId}><span class="pdd-label" data-v-f4a863d7${_scopeId}>${ssrInterpolate(__props.t.detail_sort_order)}</span><span class="pdd-value" data-v-f4a863d7${_scopeId}>${ssrInterpolate(plan.value.sort_order)}</span></div>`);
              if (plan.value.description) {
                _push2(`<div class="pdd-row" data-v-f4a863d7${_scopeId}><span class="pdd-label" data-v-f4a863d7${_scopeId}>${ssrInterpolate(__props.t.detail_description)}</span><span class="pdd-value" data-v-f4a863d7${_scopeId}>${ssrInterpolate(plan.value.description)}</span></div>`);
              } else {
                _push2(`<!---->`);
              }
              _push2(`<div class="pdd-row" data-v-f4a863d7${_scopeId}><span class="pdd-label" data-v-f4a863d7${_scopeId}>${ssrInterpolate(__props.t.detail_created_at)}</span><span class="pdd-value" data-v-f4a863d7${_scopeId}>${ssrInterpolate(plan.value.created_at)}</span></div></div></div>`);
              if (plan.value.features_display && plan.value.features_display.length) {
                _push2(`<div class="pdd-section" data-v-f4a863d7${_scopeId}><div class="pdd-section__title" data-v-f4a863d7${_scopeId}><i class="ti ti-adjustments me-1" data-v-f4a863d7${_scopeId}></i> ${ssrInterpolate(__props.t.section_features)}</div><div class="pdd-table" data-v-f4a863d7${_scopeId}><!--[-->`);
                ssrRenderList(plan.value.features_display, (f) => {
                  _push2(`<div class="pdd-row" data-v-f4a863d7${_scopeId}><span class="pdd-label" data-v-f4a863d7${_scopeId}>${ssrInterpolate(f.label)}</span><span class="pdd-value" data-v-f4a863d7${_scopeId}><span class="${ssrRenderClass(`badge ${featureBadgeClass(f)}`)}" data-v-f4a863d7${_scopeId}>${ssrInterpolate(featureValueLabel(f))}</span></span></div>`);
                });
                _push2(`<!--]--></div></div>`);
              } else {
                _push2(`<div class="text-muted small text-center py-3" data-v-f4a863d7${_scopeId}><i class="ti ti-adjustments-off d-block mb-1 fs-4" data-v-f4a863d7${_scopeId}></i> ${ssrInterpolate(__props.t.empty_features)}</div>`);
              }
              _push2(`<!--]-->`);
            } else {
              _push2(`<!---->`);
            }
          } else {
            return [
              plan.value ? (openBlock(), createBlock(Fragment, { key: 0 }, [
                createVNode("div", { class: "pdd-section" }, [
                  createVNode("div", { class: "pdd-section__title" }, [
                    createVNode("i", { class: "ti ti-currency-dollar me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.section_pricing), 1)
                  ]),
                  createVNode("div", { class: "pdd-table" }, [
                    createVNode("div", { class: "pdd-row" }, [
                      createVNode("span", { class: "pdd-label" }, toDisplayString(__props.t.detail_price), 1),
                      createVNode("span", { class: "pdd-value fw-semibold fs-5 text-body" }, toDisplayString(plan.value.price_formatted), 1)
                    ]),
                    createVNode("div", { class: "pdd-row" }, [
                      createVNode("span", { class: "pdd-label" }, toDisplayString(__props.t.detail_cycle), 1),
                      createVNode("span", { class: "pdd-value" }, toDisplayString(plan.value.billing_label), 1)
                    ]),
                    createVNode("div", { class: "pdd-row" }, [
                      createVNode("span", { class: "pdd-label" }, toDisplayString(__props.t.detail_sort_order), 1),
                      createVNode("span", { class: "pdd-value" }, toDisplayString(plan.value.sort_order), 1)
                    ]),
                    plan.value.description ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "pdd-row"
                    }, [
                      createVNode("span", { class: "pdd-label" }, toDisplayString(__props.t.detail_description), 1),
                      createVNode("span", { class: "pdd-value" }, toDisplayString(plan.value.description), 1)
                    ])) : createCommentVNode("", true),
                    createVNode("div", { class: "pdd-row" }, [
                      createVNode("span", { class: "pdd-label" }, toDisplayString(__props.t.detail_created_at), 1),
                      createVNode("span", { class: "pdd-value" }, toDisplayString(plan.value.created_at), 1)
                    ])
                  ])
                ]),
                plan.value.features_display && plan.value.features_display.length ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "pdd-section"
                }, [
                  createVNode("div", { class: "pdd-section__title" }, [
                    createVNode("i", { class: "ti ti-adjustments me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.section_features), 1)
                  ]),
                  createVNode("div", { class: "pdd-table" }, [
                    (openBlock(true), createBlock(Fragment, null, renderList(plan.value.features_display, (f) => {
                      return openBlock(), createBlock("div", {
                        key: f.key,
                        class: "pdd-row"
                      }, [
                        createVNode("span", { class: "pdd-label" }, toDisplayString(f.label), 1),
                        createVNode("span", { class: "pdd-value" }, [
                          createVNode("span", {
                            class: `badge ${featureBadgeClass(f)}`
                          }, toDisplayString(featureValueLabel(f)), 3)
                        ])
                      ]);
                    }), 128))
                  ])
                ])) : (openBlock(), createBlock("div", {
                  key: 1,
                  class: "text-muted small text-center py-3"
                }, [
                  createVNode("i", { class: "ti ti-adjustments-off d-block mb-1 fs-4" }),
                  createTextVNode(" " + toDisplayString(__props.t.empty_features), 1)
                ]))
              ], 64)) : createCommentVNode("", true)
            ];
          }
        }),
        _: 1
      }, _parent));
    };
  }
};
const _sfc_setup = _sfc_main.setup;
_sfc_main.setup = (props, ctx) => {
  const ssrContext = useSSRContext();
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Plans/PlanDetailDrawer.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
const PlanDetailDrawer = /* @__PURE__ */ _export_sfc(_sfc_main, [["__scopeId", "data-v-f4a863d7"]]);
export {
  PlanDetailDrawer as default
};
