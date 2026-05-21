import { ref, watch, mergeProps, withCtx, createVNode, withModifiers, toDisplayString, withDirectives, openBlock, createBlock, Fragment, renderList, vModelSelect, createCommentVNode, vModelText, createTextVNode, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList, ssrRenderAttr } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "SubscriptionFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    subscriptionId: { type: String, default: null },
    plans: { type: Array, default: () => [] },
    statuses: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const loading = ref(false);
    const saving = ref(false);
    const errors = ref({});
    const form = ref({ plan_id: "", status: "", starts_at: "", ends_at: "", trial_ends_at: "" });
    function resetForm() {
      form.value = { plan_id: "", status: "", starts_at: "", ends_at: "", trial_ends_at: "" };
      errors.value = {};
    }
    async function loadData(id) {
      loading.value = true;
      try {
        const res = await fetch(route("manager.subscriptions.show", id));
        const json = await res.json();
        const d = json.data;
        form.value = {
          plan_id: d.plan_id ?? "",
          status: d.status ?? "",
          starts_at: d.starts_at_raw ?? "",
          ends_at: d.ends_at_raw ?? "",
          trial_ends_at: d.trial_ends_at_raw ?? ""
        };
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val) {
        resetForm();
        if (props.subscriptionId) loadData(props.subscriptionId);
      }
    });
    async function submit() {
      var _a;
      saving.value = true;
      errors.value = {};
      try {
        const res = await fetch(route("manager.subscriptions.update", props.subscriptionId), {
          method: "PUT",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? "",
            "Accept": "application/json"
          },
          body: JSON.stringify(form.value)
        });
        const json = await res.json();
        if (!res.ok) {
          errors.value = json.errors ?? {};
          return;
        }
        emit("saved");
        emit("close");
        router.reload({ only: ["subscriptions", "total"] });
      } finally {
        saving.value = false;
      }
    }
    function firstError(field) {
      const e = errors.value[field];
      return Array.isArray(e) ? e[0] : e ?? "";
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        width: 480,
        loading: loading.value,
        "loading-label": __props.t.loading,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<h5 class="mb-0 fw-semibold"${_scopeId}><i class="fas fa-file-contract me-2 text-primary"${_scopeId}></i> ${ssrInterpolate(__props.t.form_edit_title)}</h5>`);
          } else {
            return [
              createVNode("h5", { class: "mb-0 fw-semibold" }, [
                createVNode("i", { class: "fas fa-file-contract me-2 text-primary" }),
                createTextVNode(" " + toDisplayString(__props.t.form_edit_title), 1)
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-light"${_scopeId}>${ssrInterpolate(__props.t.btn_cancel)}</button><button type="button" class="btn btn-primary"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}${_scopeId}>`);
            if (saving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(__props.t.btn_save_changes)}</button>`);
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
                disabled: saving.value,
                onClick: submit
              }, [
                saving.value ? (openBlock(), createBlock("span", {
                  key: 0,
                  class: "spinner-border spinner-border-sm me-1"
                })) : createCommentVNode("", true),
                createTextVNode(" " + toDisplayString(__props.t.btn_save_changes), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<form${_scopeId}><div class="row g-3"${_scopeId}><div class="col-md-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.form_field_plan)}</label><select class="${ssrRenderClass([{ "is-invalid": firstError("plan_id") }, "form-select"])}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.plan_id) ? ssrLooseContain(form.value.plan_id, "") : ssrLooseEqual(form.value.plan_id, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.form_select_plan)}</option><!--[-->`);
            ssrRenderList(__props.plans, (p) => {
              _push2(`<option${ssrRenderAttr("value", p.id)}${ssrIncludeBooleanAttr(Array.isArray(form.value.plan_id) ? ssrLooseContain(form.value.plan_id, p.id) : ssrLooseEqual(form.value.plan_id, p.id)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(p.name)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (firstError("plan_id")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(firstError("plan_id"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-md-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.form_field_status)}</label><select class="${ssrRenderClass([{ "is-invalid": firstError("status") }, "form-select"])}"${_scopeId}><!--[-->`);
            ssrRenderList(__props.statuses, (s) => {
              _push2(`<option${ssrRenderAttr("value", s.value)}${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, s.value) : ssrLooseEqual(form.value.status, s.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(s.label)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (firstError("status")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(firstError("status"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-md-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.form_field_starts)}</label><input${ssrRenderAttr("value", form.value.starts_at)} type="date" class="${ssrRenderClass([{ "is-invalid": firstError("starts_at") }, "form-control"])}"${_scopeId}>`);
            if (firstError("starts_at")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(firstError("starts_at"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-md-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.form_field_ends)}</label><input${ssrRenderAttr("value", form.value.ends_at)} type="date" class="form-control"${_scopeId}></div><div class="col-md-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.form_field_trial)}</label><input${ssrRenderAttr("value", form.value.trial_ends_at)} type="date" class="form-control"${_scopeId}></div></div></form>`);
          } else {
            return [
              createVNode("form", {
                onSubmit: withModifiers(submit, ["prevent"])
              }, [
                createVNode("div", { class: "row g-3" }, [
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("label", { class: "form-label" }, toDisplayString(__props.t.form_field_plan), 1),
                    withDirectives(createVNode("select", {
                      "onUpdate:modelValue": ($event) => form.value.plan_id = $event,
                      class: ["form-select", { "is-invalid": firstError("plan_id") }]
                    }, [
                      createVNode("option", { value: "" }, toDisplayString(__props.t.form_select_plan), 1),
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.plans, (p) => {
                        return openBlock(), createBlock("option", {
                          key: p.id,
                          value: p.id
                        }, toDisplayString(p.name), 9, ["value"]);
                      }), 128))
                    ], 10, ["onUpdate:modelValue"]), [
                      [vModelSelect, form.value.plan_id]
                    ]),
                    firstError("plan_id") ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(firstError("plan_id")), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "col-md-6" }, [
                    createVNode("label", { class: "form-label" }, toDisplayString(__props.t.form_field_status), 1),
                    withDirectives(createVNode("select", {
                      "onUpdate:modelValue": ($event) => form.value.status = $event,
                      class: ["form-select", { "is-invalid": firstError("status") }]
                    }, [
                      (openBlock(true), createBlock(Fragment, null, renderList(__props.statuses, (s) => {
                        return openBlock(), createBlock("option", {
                          key: s.value,
                          value: s.value
                        }, toDisplayString(s.label), 9, ["value"]);
                      }), 128))
                    ], 10, ["onUpdate:modelValue"]), [
                      [vModelSelect, form.value.status]
                    ]),
                    firstError("status") ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(firstError("status")), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "col-md-4" }, [
                    createVNode("label", { class: "form-label" }, toDisplayString(__props.t.form_field_starts), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.starts_at = $event,
                      type: "date",
                      class: ["form-control", { "is-invalid": firstError("starts_at") }]
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.starts_at]
                    ]),
                    firstError("starts_at") ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(firstError("starts_at")), 1)) : createCommentVNode("", true)
                  ]),
                  createVNode("div", { class: "col-md-4" }, [
                    createVNode("label", { class: "form-label" }, toDisplayString(__props.t.form_field_ends), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.ends_at = $event,
                      type: "date",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.ends_at]
                    ])
                  ]),
                  createVNode("div", { class: "col-md-4" }, [
                    createVNode("label", { class: "form-label" }, toDisplayString(__props.t.form_field_trial), 1),
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.trial_ends_at = $event,
                      type: "date",
                      class: "form-control"
                    }, null, 8, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.trial_ends_at]
                    ])
                  ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Subscriptions/SubscriptionFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
