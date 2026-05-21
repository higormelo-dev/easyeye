import { computed, ref, watch, mergeProps, withCtx, unref, createVNode, withModifiers, withDirectives, toDisplayString, vModelText, openBlock, createBlock, createCommentVNode, Fragment, renderList, vModelSelect, vShow, createTextVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrRenderStyle, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrRenderList, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual } from "vue/server-renderer";
import { useForm } from "@inertiajs/vue3";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "PlanFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    planId: { type: String, default: null },
    features: { type: Array, default: () => [] },
    billingCycles: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const isEdit = computed(() => !!props.planId);
    const title = computed(() => isEdit.value ? props.t.form_title_edit : props.t.form_title_create);
    const loading = ref(false);
    const activeTab = ref("dados");
    function buildFeaturesDefault() {
      return Object.fromEntries(props.features.map((f) => [f.key, "0"]));
    }
    const form = useForm({
      name: "",
      description: "",
      price: "0.00",
      billing_cycle: "monthly",
      active: true,
      sort_order: 0,
      features: {}
    });
    function resetForm() {
      var _a;
      form.reset();
      form.clearErrors();
      form.features = buildFeaturesDefault();
      form.price = "0.00";
      form.billing_cycle = ((_a = props.billingCycles[0]) == null ? void 0 : _a.value) ?? "monthly";
      activeTab.value = "dados";
    }
    async function loadEditData(id) {
      var _a;
      loading.value = true;
      try {
        const res = await fetch(route("manager.plans.show", id));
        const json = await res.json();
        const d = json.data;
        form.name = d.name ?? "";
        form.description = d.description ?? "";
        form.price = String(d.price ?? "0.00");
        form.billing_cycle = d.billing_cycle ?? ((_a = props.billingCycles[0]) == null ? void 0 : _a.value) ?? "monthly";
        form.active = d.active ?? true;
        form.sort_order = d.sort_order ?? 0;
        form.features = { ...buildFeaturesDefault(), ...d.features };
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, async (val) => {
      if (val) {
        resetForm();
        if (props.planId) await loadEditData(props.planId);
      }
    });
    function submit() {
      const opts = { preserveScroll: true, onSuccess: () => emit("close") };
      isEdit.value ? form.put(route("manager.plans.update", props.planId), opts) : form.post(route("manager.plans.store"), opts);
    }
    const booleanFeatures = computed(() => props.features.filter((f) => f.is_boolean));
    const numericFeatures = computed(() => props.features.filter((f) => f.is_numeric));
    const tabErrors = computed(() => ({
      dados: ["name", "description", "price", "billing_cycle", "sort_order"].some((k) => k in form.errors),
      features: Object.keys(form.errors).some((k) => k.startsWith("features"))
    }));
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 540,
        loading: loading.value,
        "loading-label": __props.t.loading,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h5 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-box me-2 text-info"${_scopeId}></i>${ssrInterpolate(title.value)}</h5>`);
          } else {
            return [
              createVNode("h5", { class: "mb-0 fw-semibold" }, [
                createVNode("i", { class: "ti ti-box me-2 text-info" }),
                createTextVNode(toDisplayString(title.value), 1)
              ])
            ];
          }
        }),
        tabs: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<ul class="nav nav-tabs border-0"${_scopeId}><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "dados", "text-danger": tabErrors.value.dados }, "nav-link"])}"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.tab_data)} `);
            if (tabErrors.value.dados) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1 fs-12"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li><li class="nav-item"${_scopeId}><button class="${ssrRenderClass([{ active: activeTab.value === "features", "text-danger": tabErrors.value.features }, "nav-link"])}"${_scopeId}><i class="ti ti-adjustments me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.tab_features)} `);
            if (tabErrors.value.features) {
              _push2(`<i class="ti ti-alert-circle text-danger ms-1 fs-12"${_scopeId}></i>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</button></li></ul>`);
          } else {
            return [
              createVNode("ul", { class: "nav nav-tabs border-0" }, [
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "dados", "text-danger": tabErrors.value.dados }],
                    onClick: ($event) => activeTab.value = "dados"
                  }, [
                    createVNode("i", { class: "ti ti-info-circle me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.tab_data) + " ", 1),
                    tabErrors.value.dados ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1 fs-12"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ]),
                createVNode("li", { class: "nav-item" }, [
                  createVNode("button", {
                    class: ["nav-link", { active: activeTab.value === "features", "text-danger": tabErrors.value.features }],
                    onClick: ($event) => activeTab.value = "features"
                  }, [
                    createVNode("i", { class: "ti ti-adjustments me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.tab_features) + " ", 1),
                    tabErrors.value.features ? (openBlock(), createBlock("i", {
                      key: 0,
                      class: "ti ti-alert-circle text-danger ms-1 fs-12"
                    })) : createCommentVNode("", true)
                  ], 10, ["onClick"])
                ])
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-light"${_scopeId}>${ssrInterpolate(__props.t.btn_cancel)}</button><button type="button" class="btn btn-primary"${ssrIncludeBooleanAttr(unref(form).processing) ? " disabled" : ""}${_scopeId}>`);
            if (unref(form).processing) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(isEdit.value ? __props.t.btn_save_changes : __props.t.btn_create_plan)}</button>`);
          } else {
            return [
              createVNode("button", {
                type: "button",
                class: "btn btn-light",
                onClick: ($event) => _ctx.$emit("close")
              }, toDisplayString(__props.t.btn_cancel), 9, ["onClick"]),
              createVNode("button", {
                type: "button",
                class: "btn btn-primary",
                disabled: unref(form).processing,
                onClick: submit
              }, [
                unref(form).processing ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "spinner-border spinner-border-sm me-1"
                })) : createCommentVNode("", true),
                createTextVNode(" " + toDisplayString(isEdit.value ? __props.t.btn_save_changes : __props.t.btn_create_plan), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<form${_scopeId}><div style="${ssrRenderStyle(activeTab.value === "dados" ? null : { display: "none" })}"${_scopeId}><div class="row g-3"${_scopeId}><div class="col-9"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_name_required)}</label><input${ssrRenderAttr("value", unref(form).name)} type="text" maxlength="100" autocomplete="off" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.name }, "form-control"])}"${_scopeId}>`);
            if (unref(form).errors.name) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.name)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-3"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_sort_order)}</label><input${ssrRenderAttr("value", unref(form).sort_order)} type="number" min="0" class="form-control"${_scopeId}></div><div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_description)}</label><textarea class="form-control" rows="2" maxlength="500"${ssrRenderAttr("placeholder", __props.t.field_description_placeholder)}${_scopeId}>${ssrInterpolate(unref(form).description)}</textarea></div><div class="col-5"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_price)}</label><div class="input-group"${_scopeId}><span class="input-group-text"${_scopeId}>${ssrInterpolate(__props.t.currency_prefix)}</span><input${ssrRenderAttr("value", unref(form).price)} type="number" step="0.01" min="0" class="${ssrRenderClass([{ "is-invalid": unref(form).errors.price }, "form-control"])}"${_scopeId}></div>`);
            if (unref(form).errors.price) {
              _push2(`<div class="invalid-feedback d-block"${_scopeId}>${ssrInterpolate(unref(form).errors.price)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-7"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_billing_cycle_required)}</label><select class="${ssrRenderClass([{ "is-invalid": unref(form).errors.billing_cycle }, "form-select"])}"${_scopeId}><!--[-->`);
            ssrRenderList(__props.billingCycles, (c) => {
              _push2(`<option${ssrRenderAttr("value", c.value)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).billing_cycle) ? ssrLooseContain(unref(form).billing_cycle, c.value) : ssrLooseEqual(unref(form).billing_cycle, c.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(c.label)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (unref(form).errors.billing_cycle) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(unref(form).errors.billing_cycle)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (isEdit.value) {
              _push2(`<div class="col-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_status)}</label><select class="form-select"${_scopeId}><option${ssrRenderAttr("value", true)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, true) : ssrLooseEqual(unref(form).active, true)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.status_option_active)}</option><option${ssrRenderAttr("value", false)}${ssrIncludeBooleanAttr(Array.isArray(unref(form).active) ? ssrLooseContain(unref(form).active, false) : ssrLooseEqual(unref(form).active, false)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.status_option_inactive)}</option></select></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div style="${ssrRenderStyle(activeTab.value === "features" ? null : { display: "none" })}"${_scopeId}><div class="alert alert-info small py-2 mb-3"${_scopeId}><i class="ti ti-info-circle me-1"${_scopeId}></i> ${ssrInterpolate(__props.t.features_info)}</div>`);
            if (numericFeatures.value.length) {
              _push2(`<div class="mb-4"${_scopeId}><h6 class="text-muted fw-semibold mb-2" style="${ssrRenderStyle({ "font-size": ".75rem", "letter-spacing": ".05em", "text-transform": "uppercase" })}"${_scopeId}>${ssrInterpolate(__props.t.features_numeric_section)}</h6><div class="row g-3"${_scopeId}><!--[-->`);
              ssrRenderList(numericFeatures.value, (f) => {
                _push2(`<div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(f.label)}</label><input${ssrRenderAttr("value", unref(form).features[f.key])} type="number" min="0" class="form-control form-control-sm"${ssrRenderAttr("placeholder", __props.t.features_numeric_placeholder)}${_scopeId}><div class="form-text" style="${ssrRenderStyle({ "font-size": ".7rem" })}"${_scopeId}>${ssrInterpolate(__props.t.features_numeric_hint)}</div></div>`);
              });
              _push2(`<!--]--></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            if (booleanFeatures.value.length) {
              _push2(`<div${_scopeId}><h6 class="text-muted fw-semibold mb-2" style="${ssrRenderStyle({ "font-size": ".75rem", "letter-spacing": ".05em", "text-transform": "uppercase" })}"${_scopeId}>${ssrInterpolate(__props.t.features_boolean_section)}</h6><div class="row g-3"${_scopeId}><!--[-->`);
              ssrRenderList(booleanFeatures.value, (f) => {
                _push2(`<div class="col-6"${_scopeId}><label class="form-label small"${_scopeId}>${ssrInterpolate(f.label)}</label><select class="form-select form-select-sm"${_scopeId}><option value="0"${ssrIncludeBooleanAttr(Array.isArray(unref(form).features[f.key]) ? ssrLooseContain(unref(form).features[f.key], "0") : ssrLooseEqual(unref(form).features[f.key], "0")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.features_boolean_not_included)}</option><option value="1"${ssrIncludeBooleanAttr(Array.isArray(unref(form).features[f.key]) ? ssrLooseContain(unref(form).features[f.key], "1") : ssrLooseEqual(unref(form).features[f.key], "1")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.features_boolean_included)}</option></select></div>`);
              });
              _push2(`<!--]--></div></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></form>`);
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"])
              }, [
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "row g-3" }, [
                    createVNode("div", { class: "col-9" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_name_required), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).name = $event,
                        type: "text",
                        maxlength: "100",
                        class: ["form-control", { "is-invalid": unref(form).errors.name }],
                        autocomplete: "off"
                      }, null, 10, ["onUpdate:modelValue"]), [
                        [vModelText, unref(form).name]
                      ]),
                      unref(form).errors.name ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.name), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-3" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_sort_order), 1),
                      withDirectives(createVNode("input", {
                        "onUpdate:modelValue": ($event) => unref(form).sort_order = $event,
                        type: "number",
                        min: "0",
                        class: "form-control"
                      }, null, 8, ["onUpdate:modelValue"]), [
                        [
                          vModelText,
                          unref(form).sort_order,
                          void 0,
                          { number: true }
                        ]
                      ])
                    ]),
                    createVNode("div", { class: "col-12" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_description), 1),
                      withDirectives(createVNode("textarea", {
                        "onUpdate:modelValue": ($event) => unref(form).description = $event,
                        class: "form-control",
                        rows: "2",
                        maxlength: "500",
                        placeholder: __props.t.field_description_placeholder
                      }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                        [vModelText, unref(form).description]
                      ])
                    ]),
                    createVNode("div", { class: "col-5" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_price), 1),
                      createVNode("div", { class: "input-group" }, [
                        createVNode("span", { class: "input-group-text" }, toDisplayString(__props.t.currency_prefix), 1),
                        withDirectives(createVNode("input", {
                          "onUpdate:modelValue": ($event) => unref(form).price = $event,
                          type: "number",
                          step: "0.01",
                          min: "0",
                          class: ["form-control", { "is-invalid": unref(form).errors.price }]
                        }, null, 10, ["onUpdate:modelValue"]), [
                          [vModelText, unref(form).price]
                        ])
                      ]),
                      unref(form).errors.price ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback d-block"
                      }, toDisplayString(unref(form).errors.price), 1)) : createCommentVNode("", true)
                    ]),
                    createVNode("div", { class: "col-7" }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_billing_cycle_required), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).billing_cycle = $event,
                        class: ["form-select", { "is-invalid": unref(form).errors.billing_cycle }]
                      }, [
                        (openBlock(true), createBlock(Fragment, null, renderList(__props.billingCycles, (c) => {
                          return openBlock(), createBlock("option", {
                            key: c.value,
                            value: c.value
                          }, toDisplayString(c.label), 9, ["value"]);
                        }), 128))
                      ], 10, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).billing_cycle]
                      ]),
                      unref(form).errors.billing_cycle ? (openBlock(), createBlock("div", {
                        key: 0,
                        class: "invalid-feedback"
                      }, toDisplayString(unref(form).errors.billing_cycle), 1)) : createCommentVNode("", true)
                    ]),
                    isEdit.value ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "col-6"
                    }, [
                      createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_status), 1),
                      withDirectives(createVNode("select", {
                        "onUpdate:modelValue": ($event) => unref(form).active = $event,
                        class: "form-select"
                      }, [
                        createVNode("option", { value: true }, toDisplayString(__props.t.status_option_active), 1),
                        createVNode("option", { value: false }, toDisplayString(__props.t.status_option_inactive), 1)
                      ], 8, ["onUpdate:modelValue"]), [
                        [vModelSelect, unref(form).active]
                      ])
                    ])) : createCommentVNode("", true)
                  ])
                ], 512), [
                  [vShow, activeTab.value === "dados"]
                ]),
                withDirectives(createVNode("div", null, [
                  createVNode("div", { class: "alert alert-info small py-2 mb-3" }, [
                    createVNode("i", { class: "ti ti-info-circle me-1" }),
                    createTextVNode(" " + toDisplayString(__props.t.features_info), 1)
                  ]),
                  numericFeatures.value.length ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "mb-4"
                  }, [
                    createVNode("h6", {
                      class: "text-muted fw-semibold mb-2",
                      style: { "font-size": ".75rem", "letter-spacing": ".05em", "text-transform": "uppercase" }
                    }, toDisplayString(__props.t.features_numeric_section), 1),
                    createVNode("div", { class: "row g-3" }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(numericFeatures.value, (f) => {
                        return openBlock(), createBlock("div", {
                          key: f.key,
                          class: "col-6"
                        }, [
                          createVNode("label", { class: "form-label small" }, toDisplayString(f.label), 1),
                          withDirectives(createVNode("input", {
                            "onUpdate:modelValue": ($event) => unref(form).features[f.key] = $event,
                            type: "number",
                            min: "0",
                            class: "form-control form-control-sm",
                            placeholder: __props.t.features_numeric_placeholder
                          }, null, 8, ["onUpdate:modelValue", "placeholder"]), [
                            [
                              vModelText,
                              unref(form).features[f.key],
                              void 0,
                              { number: true }
                            ]
                          ]),
                          createVNode("div", {
                            class: "form-text",
                            style: { "font-size": ".7rem" }
                          }, toDisplayString(__props.t.features_numeric_hint), 1)
                        ]);
                      }), 128))
                    ])
                  ])) : createCommentVNode("", true),
                  booleanFeatures.value.length ? (openBlock(), createBlock("div", { key: 1 }, [
                    createVNode("h6", {
                      class: "text-muted fw-semibold mb-2",
                      style: { "font-size": ".75rem", "letter-spacing": ".05em", "text-transform": "uppercase" }
                    }, toDisplayString(__props.t.features_boolean_section), 1),
                    createVNode("div", { class: "row g-3" }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(booleanFeatures.value, (f) => {
                        return openBlock(), createBlock("div", {
                          key: f.key,
                          class: "col-6"
                        }, [
                          createVNode("label", { class: "form-label small" }, toDisplayString(f.label), 1),
                          withDirectives(createVNode("select", {
                            "onUpdate:modelValue": ($event) => unref(form).features[f.key] = $event,
                            class: "form-select form-select-sm"
                          }, [
                            createVNode("option", { value: "0" }, toDisplayString(__props.t.features_boolean_not_included), 1),
                            createVNode("option", { value: "1" }, toDisplayString(__props.t.features_boolean_included), 1)
                          ], 8, ["onUpdate:modelValue"]), [
                            [vModelSelect, unref(form).features[f.key]]
                          ])
                        ]);
                      }), 128))
                    ])
                  ])) : createCommentVNode("", true)
                ], 512), [
                  [vShow, activeTab.value === "features"]
                ])
              ], 32)
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Plans/PlanFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
