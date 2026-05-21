import { ref, computed, watch, mergeProps, withCtx, createVNode, createTextVNode, toDisplayString, withDirectives, vModelText, openBlock, createBlock, createCommentVNode, Fragment, renderList, vModelSelect, useSSRContext } from "vue";
import { ssrRenderComponent, ssrInterpolate, ssrRenderAttr, ssrRenderClass, ssrIncludeBooleanAttr, ssrLooseContain, ssrLooseEqual, ssrRenderList } from "vue/server-renderer";
import { router } from "@inertiajs/vue3";
import { O as OffcanvasPanel } from "./OffcanvasPanel-VfE7yaou.js";
import "./_plugin-vue_export-helper-1tPrXgE0.js";
const _sfc_main = {
  __name: "PartnerFormModal",
  __ssrInlineRender: true,
  props: {
    open: { type: Boolean, required: true },
    partnerId: { type: String, default: null },
    editDataUrl: { type: String, default: "" },
    updateUrl: { type: String, default: "" },
    partnerTypes: { type: Array, default: () => [] },
    t: { type: Object, default: () => ({}) }
  },
  emits: ["close", "saved"],
  setup(__props, { emit: __emit }) {
    const props = __props;
    const emit = __emit;
    const loading = ref(false);
    const saving = ref(false);
    const errors = ref({});
    const defaultForm = () => ({
      name: "",
      email: "",
      type: "",
      document: "",
      commission_rate: "",
      notes: "",
      status: "active"
    });
    const form = ref(defaultForm());
    const isEdit = computed(() => !!props.partnerId);
    const defaultRates = Object.fromEntries(
      props.partnerTypes.map((t) => [t.value, t.default_rate])
    );
    function resetForm() {
      form.value = defaultForm();
      errors.value = {};
    }
    async function loadData() {
      loading.value = true;
      try {
        const res = await fetch(props.editDataUrl);
        const json = await res.json();
        const d = json.data;
        form.value = {
          name: d.name ?? "",
          email: d.email ?? "",
          type: d.type ?? "",
          document: d.document ?? "",
          commission_rate: d.commission_rate ?? "",
          notes: d.notes ?? "",
          status: d.status ?? "active"
        };
      } finally {
        loading.value = false;
      }
    }
    watch(() => props.open, (val) => {
      if (val) {
        resetForm();
        if (props.partnerId) loadData();
      }
    });
    function onTypeChange() {
      if (!isEdit.value && form.value.type && defaultRates[form.value.type]) {
        form.value.commission_rate = defaultRates[form.value.type];
      }
    }
    async function submit() {
      var _a;
      saving.value = true;
      errors.value = {};
      try {
        const url = isEdit.value ? props.updateUrl : route("manager.partners.store");
        const method = isEdit.value ? "PUT" : "POST";
        const res = await fetch(url, {
          method,
          headers: {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "X-CSRF-TOKEN": ((_a = document.querySelector('meta[name="csrf-token"]')) == null ? void 0 : _a.content) ?? ""
          },
          body: JSON.stringify(form.value)
        });
        const json = await res.json();
        if (!res.ok) {
          errors.value = json.errors ?? {};
          return;
        }
        showToast(json.message, "success");
        emit("saved");
        emit("close");
        router.reload({ only: ["partners", "kpis", "recentCommissions"] });
      } finally {
        saving.value = false;
      }
    }
    function err(field) {
      const e = errors.value[field];
      return Array.isArray(e) ? e[0] : e ?? "";
    }
    function showToast(msg, type = "success") {
      if (type === "success" && window.showSuccessToast) return window.showSuccessToast(msg);
      if (type === "error" && window.showErrorToast) return window.showErrorToast(msg);
    }
    return (_ctx, _push, _parent, _attrs) => {
      _push(ssrRenderComponent(OffcanvasPanel, mergeProps({
        open: __props.open,
        loading: loading.value,
        "loading-label": __props.t.saving,
        onClose: ($event) => _ctx.$emit("close")
      }, _attrs), {
        header: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div${_scopeId}><h5 class="mb-0 fw-semibold"${_scopeId}><i class="ti ti-affiliate me-2 text-primary"${_scopeId}></i> ${ssrInterpolate(isEdit.value ? __props.t.edit : __props.t.new)}</h5></div>`);
          } else {
            return [
              createVNode("div", null, [
                createVNode("h5", { class: "mb-0 fw-semibold" }, [
                  createVNode("i", { class: "ti ti-affiliate me-2 text-primary" }),
                  createTextVNode(" " + toDisplayString(isEdit.value ? __props.t.edit : __props.t.new), 1)
                ])
              ])
            ];
          }
        }),
        footer: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<button type="button" class="btn btn-secondary"${_scopeId}>${ssrInterpolate(__props.t.cancel)}</button><button type="button" class="btn btn-primary"${ssrIncludeBooleanAttr(saving.value) ? " disabled" : ""}${_scopeId}>`);
            if (saving.value) {
              _push2(`<span class="spinner-border spinner-border-sm me-1"${_scopeId}></span>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(` ${ssrInterpolate(saving.value ? __props.t.saving : isEdit.value ? __props.t.save : __props.t.register)}</button>`);
          } else {
            return [
              createVNode("button", {
                type: "button",
                class: "btn btn-secondary",
                onClick: ($event) => _ctx.$emit("close")
              }, toDisplayString(__props.t.cancel), 9, ["onClick"]),
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
                createTextVNode(" " + toDisplayString(saving.value ? __props.t.saving : isEdit.value ? __props.t.save : __props.t.register), 1)
              ], 8, ["disabled"])
            ];
          }
        }),
        default: withCtx((_, _push2, _parent2, _scopeId) => {
          if (_push2) {
            _push2(`<div class="row g-3"${_scopeId}><div class="col-12 col-sm-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_name)} <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", form.value.name)} type="text" class="${ssrRenderClass([{ "is-invalid": err("name") }, "form-control"])}"${ssrRenderAttr("placeholder", __props.t.field_name_ph)} maxlength="255"${_scopeId}>`);
            if (err("name")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(err("name"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-12 col-sm-6"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_email)} <span class="text-danger"${_scopeId}>*</span></label><input${ssrRenderAttr("value", form.value.email)} type="email" class="${ssrRenderClass([{ "is-invalid": err("email") }, "form-control"])}"${ssrRenderAttr("placeholder", __props.t.field_email_ph)}${ssrIncludeBooleanAttr(isEdit.value) ? " disabled" : ""} maxlength="255"${_scopeId}>`);
            if (err("email")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(err("email"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            if (!isEdit.value) {
              _push2(`<div class="form-text small"${_scopeId}>${ssrInterpolate(__props.t.email_hint)}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-12 col-sm-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_type)} <span class="text-danger"${_scopeId}>*</span></label><select class="${ssrRenderClass([{ "is-invalid": err("type") }, "form-select"])}"${_scopeId}><option value=""${ssrIncludeBooleanAttr(Array.isArray(form.value.type) ? ssrLooseContain(form.value.type, "") : ssrLooseEqual(form.value.type, "")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.field_select)}</option><!--[-->`);
            ssrRenderList(__props.partnerTypes, (tp) => {
              _push2(`<option${ssrRenderAttr("value", tp.value)}${ssrIncludeBooleanAttr(Array.isArray(form.value.type) ? ssrLooseContain(form.value.type, tp.value) : ssrLooseEqual(form.value.type, tp.value)) ? " selected" : ""}${_scopeId}>${ssrInterpolate(tp.label)}</option>`);
            });
            _push2(`<!--]--></select>`);
            if (err("type")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(err("type"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div><div class="col-12 col-sm-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_commission_rate)}</label><div class="input-group"${_scopeId}><input${ssrRenderAttr("value", form.value.commission_rate)} type="number" step="0.01" min="0" max="100" class="${ssrRenderClass([{ "is-invalid": err("commission_rate") }, "form-control"])}" placeholder="0.00"${_scopeId}><span class="input-group-text"${_scopeId}>%</span>`);
            if (err("commission_rate")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(err("commission_rate"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div><div class="col-12 col-sm-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_document)}</label><input${ssrRenderAttr("value", form.value.document)} type="text" class="${ssrRenderClass([{ "is-invalid": err("document") }, "form-control"])}"${ssrRenderAttr("placeholder", __props.t.field_document_ph)} maxlength="18"${_scopeId}>`);
            if (err("document")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(err("document"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div>`);
            if (isEdit.value) {
              _push2(`<div class="col-12 col-sm-4"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_status)}</label><select class="form-select"${_scopeId}><option value="active"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "active") : ssrLooseEqual(form.value.status, "active")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.status_active)}</option><option value="inactive"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "inactive") : ssrLooseEqual(form.value.status, "inactive")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.status_inactive)}</option><option value="suspended"${ssrIncludeBooleanAttr(Array.isArray(form.value.status) ? ssrLooseContain(form.value.status, "suspended") : ssrLooseEqual(form.value.status, "suspended")) ? " selected" : ""}${_scopeId}>${ssrInterpolate(__props.t.status_suspended)}</option></select></div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`<div class="col-12"${_scopeId}><label class="form-label"${_scopeId}>${ssrInterpolate(__props.t.field_notes)}</label><textarea class="${ssrRenderClass([{ "is-invalid": err("notes") }, "form-control"])}"${ssrRenderAttr("placeholder", __props.t.field_notes_ph)} rows="3" maxlength="1000"${_scopeId}>${ssrInterpolate(form.value.notes)}</textarea>`);
            if (err("notes")) {
              _push2(`<div class="invalid-feedback"${_scopeId}>${ssrInterpolate(err("notes"))}</div>`);
            } else {
              _push2(`<!---->`);
            }
            _push2(`</div></div>`);
          } else {
            return [
              createVNode("div", { class: "row g-3" }, [
                createVNode("div", { class: "col-12 col-sm-6" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString(__props.t.field_name) + " ", 1),
                    createVNode("span", { class: "text-danger" }, "*")
                  ]),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => form.value.name = $event,
                    type: "text",
                    class: ["form-control", { "is-invalid": err("name") }],
                    placeholder: __props.t.field_name_ph,
                    maxlength: "255"
                  }, null, 10, ["onUpdate:modelValue", "placeholder"]), [
                    [vModelText, form.value.name]
                  ]),
                  err("name") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("name")), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "col-12 col-sm-6" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString(__props.t.field_email) + " ", 1),
                    createVNode("span", { class: "text-danger" }, "*")
                  ]),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => form.value.email = $event,
                    type: "email",
                    class: ["form-control", { "is-invalid": err("email") }],
                    placeholder: __props.t.field_email_ph,
                    disabled: isEdit.value,
                    maxlength: "255"
                  }, null, 10, ["onUpdate:modelValue", "placeholder", "disabled"]), [
                    [vModelText, form.value.email]
                  ]),
                  err("email") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("email")), 1)) : createCommentVNode("", true),
                  !isEdit.value ? (openBlock(), createBlock("div", {
                    key: 1,
                    class: "form-text small"
                  }, toDisplayString(__props.t.email_hint), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "col-12 col-sm-4" }, [
                  createVNode("label", { class: "form-label" }, [
                    createTextVNode(toDisplayString(__props.t.field_type) + " ", 1),
                    createVNode("span", { class: "text-danger" }, "*")
                  ]),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => form.value.type = $event,
                    class: ["form-select", { "is-invalid": err("type") }],
                    onChange: onTypeChange
                  }, [
                    createVNode("option", { value: "" }, toDisplayString(__props.t.field_select), 1),
                    (openBlock(true), createBlock(Fragment, null, renderList(__props.partnerTypes, (tp) => {
                      return openBlock(), createBlock("option", {
                        key: tp.value,
                        value: tp.value
                      }, toDisplayString(tp.label), 9, ["value"]);
                    }), 128))
                  ], 42, ["onUpdate:modelValue"]), [
                    [vModelSelect, form.value.type]
                  ]),
                  err("type") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("type")), 1)) : createCommentVNode("", true)
                ]),
                createVNode("div", { class: "col-12 col-sm-4" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_commission_rate), 1),
                  createVNode("div", { class: "input-group" }, [
                    withDirectives(createVNode("input", {
                      "onUpdate:modelValue": ($event) => form.value.commission_rate = $event,
                      type: "number",
                      step: "0.01",
                      min: "0",
                      max: "100",
                      class: ["form-control", { "is-invalid": err("commission_rate") }],
                      placeholder: "0.00"
                    }, null, 10, ["onUpdate:modelValue"]), [
                      [vModelText, form.value.commission_rate]
                    ]),
                    createVNode("span", { class: "input-group-text" }, "%"),
                    err("commission_rate") ? (openBlock(), createBlock("div", {
                      key: 0,
                      class: "invalid-feedback"
                    }, toDisplayString(err("commission_rate")), 1)) : createCommentVNode("", true)
                  ])
                ]),
                createVNode("div", { class: "col-12 col-sm-4" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_document), 1),
                  withDirectives(createVNode("input", {
                    "onUpdate:modelValue": ($event) => form.value.document = $event,
                    type: "text",
                    class: ["form-control", { "is-invalid": err("document") }],
                    placeholder: __props.t.field_document_ph,
                    maxlength: "18"
                  }, null, 10, ["onUpdate:modelValue", "placeholder"]), [
                    [vModelText, form.value.document]
                  ]),
                  err("document") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("document")), 1)) : createCommentVNode("", true)
                ]),
                isEdit.value ? (openBlock(), createBlock("div", {
                  key: 0,
                  class: "col-12 col-sm-4"
                }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_status), 1),
                  withDirectives(createVNode("select", {
                    "onUpdate:modelValue": ($event) => form.value.status = $event,
                    class: "form-select"
                  }, [
                    createVNode("option", { value: "active" }, toDisplayString(__props.t.status_active), 1),
                    createVNode("option", { value: "inactive" }, toDisplayString(__props.t.status_inactive), 1),
                    createVNode("option", { value: "suspended" }, toDisplayString(__props.t.status_suspended), 1)
                  ], 8, ["onUpdate:modelValue"]), [
                    [vModelSelect, form.value.status]
                  ])
                ])) : createCommentVNode("", true),
                createVNode("div", { class: "col-12" }, [
                  createVNode("label", { class: "form-label" }, toDisplayString(__props.t.field_notes), 1),
                  withDirectives(createVNode("textarea", {
                    "onUpdate:modelValue": ($event) => form.value.notes = $event,
                    class: ["form-control", { "is-invalid": err("notes") }],
                    placeholder: __props.t.field_notes_ph,
                    rows: "3",
                    maxlength: "1000"
                  }, null, 10, ["onUpdate:modelValue", "placeholder"]), [
                    [vModelText, form.value.notes]
                  ]),
                  err("notes") ? (openBlock(), createBlock("div", {
                    key: 0,
                    class: "invalid-feedback"
                  }, toDisplayString(err("notes")), 1)) : createCommentVNode("", true)
                ])
              ])
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
  (ssrContext.modules || (ssrContext.modules = /* @__PURE__ */ new Set())).add("resources/js/Pages/Panel/Manager/Partners/PartnerFormModal.vue");
  return _sfc_setup ? _sfc_setup(props, ctx) : void 0;
};
export {
  _sfc_main as default
};
